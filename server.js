const express = require('express');
const crypto = require('crypto');
const path = require('path');
const db = require('./db');

const app = express();
const PORT = process.env.PORT || 3000;
const PASSCODE = process.env.APP_PASSCODE || '';
// optional: comma-separated IPs that skip the passcode entirely (e.g. office/VPN)
const ALLOWED_IPS = (process.env.ALLOWED_IPS || '')
  .split(',').map(s => s.trim()).filter(Boolean);

app.set('trust proxy', true); // behind Traefik, req.ip = real client IP
app.use(express.json());

// ---- passcode gate: one shared passcode, remembered per device for a year ----
const authToken = PASSCODE
  ? crypto.createHash('sha256').update(PASSCODE).digest('hex')
  : null;

function readCookie(req, name) {
  const raw = req.headers.cookie || '';
  for (const part of raw.split(';')) {
    const [k, ...v] = part.trim().split('=');
    if (k === name) return v.join('=');
  }
  return null;
}

const clientIp = (req) => (req.ip || '').replace(/^::ffff:/, '');
const ipAllowed = (req) => ALLOWED_IPS.includes(clientIp(req));

// gentle brute-force damper: after 5 bad tries from an IP, each try waits longer
const loginFails = new Map();
app.post('/login', (req, res) => {
  if (!authToken) return res.json({ ok: true });
  const ip = clientIp(req);
  const fails = loginFails.get(ip) || 0;
  const finish = () => {
    if ((req.body.passcode || '') === PASSCODE) {
      loginFails.delete(ip);
      const secure = req.secure ? ' Secure;' : '';
      res.setHeader('Set-Cookie',
        `cp_auth=${authToken}; Path=/; HttpOnly; SameSite=Lax;${secure} Max-Age=31536000`);
      return res.json({ ok: true });
    }
    loginFails.set(ip, fails + 1);
    res.status(401).json({ ok: false });
  };
  setTimeout(finish, Math.min(5000, Math.max(0, fails - 4) * 1000));
});

app.use((req, res, next) => {
  if (!authToken) return next();
  if (ipAllowed(req)) return next();
  if (readCookie(req, 'cp_auth') === authToken) return next();
  if (req.path === '/login.html' || req.path === '/style.css') return next();
  if (req.path.startsWith('/api/')) return res.status(401).json({ error: 'unauthorized' });
  if (req.path === '/' || req.path === '/index.html') return res.redirect('/login.html');
  next();
});

app.use(express.static(path.join(__dirname, 'public')));

// ---- helpers ----
const ok = (res, data) => res.json(data === undefined ? { ok: true } : data);

function fullState() {
  return {
    projects: db.prepare('SELECT * FROM projects ORDER BY archived, created_at').all(),
    people: db.prepare('SELECT * FROM people ORDER BY name').all(),
    tasks: db.prepare('SELECT * FROM tasks ORDER BY project_id, sort_order, start, id').all(),
    deps: db.prepare('SELECT * FROM deps').all(),
    snapshots: db.prepare(
      'SELECT id, project_id, name, created_at FROM snapshots ORDER BY id DESC').all(),
  };
}

// ---- API ----
app.get('/api/state', (req, res) => ok(res, fullState()));

app.post('/api/projects', (req, res) => {
  const { name, color, due_date, notes } = req.body;
  const r = db.prepare('INSERT INTO projects (name, color, due_date, notes) VALUES (?,?,?,?)')
    .run(name, color || '#24bbb6', due_date || null, notes || '');
  ok(res, { id: r.lastInsertRowid });
});
app.put('/api/projects/:id', (req, res) => {
  const p = db.prepare('SELECT * FROM projects WHERE id=?').get(req.params.id);
  if (!p) return res.status(404).json({ error: 'not found' });
  const m = { ...p, ...req.body };
  db.prepare('UPDATE projects SET name=?, color=?, due_date=?, notes=?, archived=? WHERE id=?')
    .run(m.name, m.color, m.due_date, m.notes, m.archived ? 1 : 0, p.id);
  ok(res);
});
app.delete('/api/projects/:id', (req, res) => {
  db.prepare('DELETE FROM projects WHERE id=?').run(req.params.id);
  ok(res);
});

app.post('/api/people', (req, res) => {
  const r = db.prepare('INSERT INTO people (name, color) VALUES (?,?)')
    .run(req.body.name, req.body.color || '#8a94a6');
  ok(res, { id: r.lastInsertRowid });
});
app.put('/api/people/:id', (req, res) => {
  const p = db.prepare('SELECT * FROM people WHERE id=?').get(req.params.id);
  if (!p) return res.status(404).json({ error: 'not found' });
  const m = { ...p, ...req.body };
  db.prepare('UPDATE people SET name=?, color=? WHERE id=?').run(m.name, m.color, p.id);
  ok(res);
});
app.delete('/api/people/:id', (req, res) => {
  db.prepare('DELETE FROM people WHERE id=?').run(req.params.id);
  ok(res);
});

app.post('/api/tasks', (req, res) => {
  const t = req.body;
  const progress = t.done ? 100 : Math.max(0, Math.min(100, Number(t.progress) || 0));
  const r = db.prepare(
    `INSERT INTO tasks (project_id, name, start, end, done, milestone, person_id, notes, progress, parent_id, sort_order)
     VALUES (?,?,?,?,?,?,?,?,?,?,
       COALESCE((SELECT MAX(sort_order)+1 FROM tasks WHERE project_id=?), 0))`
  ).run(t.project_id, t.name, t.start, t.end, progress >= 100 ? 1 : (t.done ? 1 : 0),
        t.milestone ? 1 : 0, t.person_id || null, t.notes || '', progress,
        t.parent_id || null, t.project_id);
  ok(res, { id: r.lastInsertRowid });
});
app.put('/api/tasks/:id', (req, res) => {
  const t = db.prepare('SELECT * FROM tasks WHERE id=?').get(req.params.id);
  if (!t) return res.status(404).json({ error: 'not found' });
  const m = { ...t, ...req.body };
  // progress and done stay coupled: 100% = done, explicit progress wins
  if (req.body.progress !== undefined) {
    m.progress = Math.max(0, Math.min(100, Number(req.body.progress) || 0));
    m.done = m.progress >= 100 ? 1 : 0;
  } else if (req.body.done !== undefined) {
    m.progress = req.body.done ? 100 : (t.progress >= 100 ? 0 : t.progress);
  }
  db.prepare(
    'UPDATE tasks SET name=?, start=?, end=?, done=?, milestone=?, person_id=?, notes=?, progress=?, parent_id=?, sort_order=? WHERE id=?'
  ).run(m.name, m.start, m.end, m.done ? 1 : 0, m.milestone ? 1 : 0,
        m.person_id || null, m.notes, m.progress || 0,
        m.parent_id === t.id ? null : (m.parent_id || null), m.sort_order, t.id);
  ok(res);
});
app.post('/api/tasks/reorder', (req, res) => {
  const ids = req.body.ids || [];
  const stmt = db.prepare('UPDATE tasks SET sort_order=? WHERE id=?');
  ids.forEach((id, i) => stmt.run(i, id));
  ok(res);
});
app.delete('/api/tasks/:id', (req, res) => {
  // deleting a phase promotes its subtasks rather than orphaning them
  db.prepare('UPDATE tasks SET parent_id=NULL WHERE parent_id=?').run(req.params.id);
  db.prepare('DELETE FROM tasks WHERE id=?').run(req.params.id);
  ok(res);
});

// ---- settings + AI plan generation ----
function getSetting(key) {
  const r = db.prepare('SELECT value FROM settings WHERE key=?').get(key);
  return r ? r.value : null;
}
app.get('/api/settings', (req, res) =>
  ok(res, { openai: !!(getSetting('openai_key') || process.env.OPENAI_API_KEY) }));
app.post('/api/settings', (req, res) => {
  if ('openai_key' in req.body) {
    const v = (req.body.openai_key || '').trim();
    if (v) db.prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?,?)')
      .run('openai_key', v);
    else db.prepare('DELETE FROM settings WHERE key=?').run('openai_key');
  }
  ok(res);
});

app.post('/api/ai-plan', async (req, res) => {
  const key = getSetting('openai_key') || process.env.OPENAI_API_KEY;
  if (!key) return res.status(400).json({ error: 'no_key' });
  const { prompt, plan, amendment } = req.body;
  const today = new Date().toISOString().slice(0, 10);
  const sys = `You are a pragmatic project planner inside a Gantt tool. Reply with ONLY a JSON object, no prose, shaped exactly like:
{"name": "Project name", "due_date": "YYYY-MM-DD or null", "tasks": [
  {"name": "...", "start": "YYYY-MM-DD", "end": "YYYY-MM-DD", "milestone": false,
   "person": "Name or null", "depends_on": ["exact task names that must finish first"],
   "notes": "short note or null", "parent": "phase task name or null"}]}
Rules:
- Today is ${today}. Start the plan on or after today unless told otherwise.
- 5–15 tasks. Realistic durations. Milestones are single-day (start = end) gates like "Sign-off" or "Launch".
- Chain dependencies wherever work genuinely cannot start before another finishes — this is what drives the critical path. Parallel tracks should stay parallel.
- A task's start must be at least the day after every task it depends on ends.
- Only assign a person if the user names people; otherwise use null.
- For larger plans, group work into phases: add the phase itself as a task (dates spanning its subtasks, no depends_on, parent null) and set each subtask's "parent" to that phase's exact name. One level only. Milestones may sit inside phases.
- Task names must be unique.`;
  const messages = [{ role: 'system', content: sys }];
  if (plan && amendment) {
    messages.push({ role: 'user', content:
      `Current plan JSON:\n${JSON.stringify(plan)}\n\nAmend it as follows: ${amendment}\n\nReturn the complete amended plan JSON.` });
  } else {
    messages.push({ role: 'user', content: String(prompt || '') });
  }
  try {
    const r = await fetch('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: { Authorization: `Bearer ${key}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ model: 'gpt-4o-mini',
        response_format: { type: 'json_object' }, messages }),
    });
    if (!r.ok) {
      const detail = (await r.text()).slice(0, 400);
      return res.status(502).json({ error: 'openai_error', detail });
    }
    const j = await r.json();
    const parsed = JSON.parse(j.choices[0].message.content);
    if (!parsed.name || !Array.isArray(parsed.tasks)) throw new Error('bad shape');
    ok(res, { plan: parsed });
  } catch (e) {
    res.status(502).json({ error: 'bad_response', detail: String(e.message) });
  }
});

app.post('/api/snapshots', (req, res) => {
  const { project_id, name } = req.body;
  const tasks = db.prepare(
    'SELECT id, name, start, end, milestone, done FROM tasks WHERE project_id=?').all(project_id);
  const r = db.prepare('INSERT INTO snapshots (project_id, name, data) VALUES (?,?,?)')
    .run(project_id, name || 'Snapshot', JSON.stringify(tasks));
  ok(res, { id: r.lastInsertRowid });
});
app.get('/api/snapshots/:id', (req, res) => {
  const s = db.prepare('SELECT * FROM snapshots WHERE id=?').get(req.params.id);
  if (!s) return res.status(404).json({ error: 'not found' });
  ok(res, { ...s, data: JSON.parse(s.data) });
});
app.delete('/api/snapshots/:id', (req, res) => {
  db.prepare('DELETE FROM snapshots WHERE id=?').run(req.params.id);
  ok(res);
});

app.post('/api/deps', (req, res) => {
  const { project_id, pred_id, succ_id } = req.body;
  if (pred_id === succ_id) return res.status(400).json({ error: 'self-dependency' });
  try {
    const r = db.prepare('INSERT INTO deps (project_id, pred_id, succ_id) VALUES (?,?,?)')
      .run(project_id, pred_id, succ_id);
    ok(res, { id: r.lastInsertRowid });
  } catch (e) {
    res.status(400).json({ error: 'duplicate' });
  }
});
app.delete('/api/deps/:id', (req, res) => {
  db.prepare('DELETE FROM deps WHERE id=?').run(req.params.id);
  ok(res);
});

app.listen(PORT, () => console.log(`EmotioGantt running on http://localhost:${PORT}`));
