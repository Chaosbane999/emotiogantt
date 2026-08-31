const express = require('express');
const crypto = require('crypto');
const path = require('path');
const db = require('./db');
const mcp = require('./mcp');
const oauth = require('./oauth');

const app = express();
const PORT = process.env.PORT || 3000;
const PASSCODE = process.env.APP_PASSCODE || '';
// optional: comma-separated IPs that skip login entirely (e.g. office/VPN) — treated as admin
const ALLOWED_IPS = (process.env.ALLOWED_IPS || '')
  .split(',').map(s => s.trim()).filter(Boolean);

app.set('trust proxy', true); // behind Traefik, req.ip = real client IP
app.use(express.json());

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

// ---- passwords + sessions ----
const scryptHex = (pw, salt) => crypto.scryptSync(String(pw), salt, 32).toString('hex');
const hashPassword = (pw) => {
  const salt = crypto.randomBytes(8).toString('hex');
  return `${salt}:${scryptHex(pw, salt)}`;
};
const checkPassword = (pw, stored) => {
  const [salt, h] = String(stored || '').split(':');
  if (!salt || !h) return false;
  try {
    return crypto.timingSafeEqual(Buffer.from(scryptHex(pw, salt), 'hex'), Buffer.from(h, 'hex'));
  } catch (e) { return false; }
};

// legacy passcode cookie value (pre-user-login sessions map to the bootstrap admin)
const legacyAuthToken = PASSCODE
  ? crypto.createHash('sha256').update(PASSCODE).digest('hex')
  : null;

// bootstrap: ensure an admin login exists so nobody is locked out
if (PASSCODE && !db.prepare("SELECT 1 FROM people WHERE role='admin' AND username IS NOT NULL").get()) {
  let damon = db.prepare("SELECT * FROM people WHERE lower(name)='damon'").get();
  if (!damon) {
    const r = db.prepare("INSERT INTO people (name, color) VALUES ('Damon', '#24bbb6')").run();
    damon = { id: r.lastInsertRowid };
  }
  db.prepare("UPDATE people SET username='damon', password_hash=?, role='admin' WHERE id=?")
    .run(hashPassword(PASSCODE), damon.id);
  console.log('Bootstrapped admin login "damon" (password = APP_PASSCODE)');
}

const authEnabled = () =>
  !!PASSCODE || !!db.prepare('SELECT 1 FROM people WHERE username IS NOT NULL').get();

const bootstrapAdmin = () =>
  db.prepare("SELECT * FROM people WHERE role='admin' AND username IS NOT NULL ORDER BY id").get();

function currentUser(req) {
  const tok = readCookie(req, 'cp_sess');
  if (tok) {
    const s = db.prepare('SELECT * FROM sessions WHERE token=?').get(tok);
    if (s) return db.prepare('SELECT * FROM people WHERE id=?').get(s.person_id) || null;
  }
  // continuity: old shared-passcode cookies act as the bootstrap admin
  if (legacyAuthToken && readCookie(req, 'cp_auth') === legacyAuthToken) return bootstrapAdmin();
  return null;
}

const setSessionCookie = (req, res, token, maxAge) => {
  const secure = req.secure ? ' Secure;' : '';
  res.setHeader('Set-Cookie',
    `cp_sess=${token}; Path=/; HttpOnly; SameSite=Lax;${secure} Max-Age=${maxAge}`);
};

// gentle brute-force damper: after 5 bad tries from an IP, each try waits longer
const loginFails = new Map();
app.post('/login', (req, res) => {
  if (!authEnabled()) return res.json({ ok: true });
  const ip = clientIp(req);
  const fails = loginFails.get(ip) || 0;
  const finish = () => {
    const b = req.body || {};
    let person = null;
    if (b.username) {
      const u = db.prepare('SELECT * FROM people WHERE lower(username)=lower(?)').get(b.username);
      if (u && checkPassword(b.password || '', u.password_hash)) person = u;
    } else if (PASSCODE && (b.passcode || '') === PASSCODE) {
      person = bootstrapAdmin(); // legacy passcode-only login
    }
    if (!person) {
      loginFails.set(ip, fails + 1);
      return res.status(401).json({ ok: false });
    }
    loginFails.delete(ip);
    const token = crypto.randomBytes(32).toString('hex');
    db.prepare('INSERT INTO sessions (token, person_id) VALUES (?,?)').run(token, person.id);
    setSessionCookie(req, res, token, 31536000);
    audit({ user: person, via: 'web' }, 'login', 'session', person.id, null, {});
    res.json({ ok: true, name: person.name });
  };
  setTimeout(finish, Math.min(5000, Math.max(0, fails - 4) * 1000));
});

app.post('/logout', (req, res) => {
  const tok = readCookie(req, 'cp_sess');
  if (tok) db.prepare('DELETE FROM sessions WHERE token=?').run(tok);
  setSessionCookie(req, res, 'gone', 0);
  res.json({ ok: true });
});

// MCP endpoint (token-authenticated, independent of the login gate)
app.post('/mcp', mcp.handle);
app.get('/mcp', (req, res) => res.status(405).end());

// OAuth 2.1 for MCP clients that require it (ChatGPT etc.)
app.use(express.urlencoded({ extended: false }));
app.get('/.well-known/oauth-authorization-server', oauth.asMetadata);
app.get('/.well-known/oauth-authorization-server/mcp', oauth.asMetadata);
app.get('/.well-known/openid-configuration', oauth.asMetadata);
app.get('/.well-known/oauth-protected-resource', oauth.prMetadata);
app.get('/.well-known/oauth-protected-resource/mcp', oauth.prMetadata);
app.post('/oauth/register', oauth.register);
app.get('/oauth/authorize', oauth.authorizeForm);
app.post('/oauth/authorize', oauth.authorizeSubmit);
app.post('/oauth/token', oauth.token);

app.use((req, res, next) => {
  if (!authEnabled()) { req.user = null; return next(); }
  const u = currentUser(req);
  if (u) { req.user = u; return next(); }
  if (ipAllowed(req)) { req.user = bootstrapAdmin(); return next(); }
  if (req.path === '/login.html' || req.path === '/style.css') return next();
  if (req.path.startsWith('/api/')) return res.status(401).json({ error: 'unauthorized' });
  if (req.path === '/' || req.path === '/index.html') return res.redirect('/login.html');
  next();
});

app.use(express.static(path.join(__dirname, 'public')));

// ---- helpers ----
const ok = (res, data) => res.json(data === undefined ? { ok: true } : data);
const forbidden = (res) => res.status(403).json({ error: 'forbidden' });
const isAdmin = (req) => !req.user || req.user.role === 'admin';

// which projects can this user see? null = all
function visibleProjectIds(req) {
  if (isAdmin(req)) return null;
  const ids = new Set();
  for (const r of db.prepare('SELECT project_id FROM project_members WHERE person_id=?').all(req.user.id))
    ids.add(r.project_id);
  for (const r of db.prepare('SELECT DISTINCT project_id FROM tasks WHERE person_id=?').all(req.user.id))
    ids.add(r.project_id);
  return ids;
}
const canTouchProject = (req, pid) => {
  const v = visibleProjectIds(req);
  return v === null || v.has(Number(pid));
};
const taskProject = (taskId) => {
  const t = db.prepare('SELECT * FROM tasks WHERE id=?').get(taskId);
  return t ? t : null;
};

// ---- audit trail ----
function audit(reqOrCtx, action, entity, entityId, projectId, detail) {
  const user = reqOrCtx.user;
  db.prepare(
    'INSERT INTO audit (person_id, via, action, entity, entity_id, project_id, detail) VALUES (?,?,?,?,?,?,?)')
    .run(user ? user.id : null, reqOrCtx.via || 'web', action, entity,
      entityId || null, projectId || null,
      JSON.stringify(detail || {}).slice(0, 2000));
}
const diff = (before, after, fields) => {
  const d = {};
  for (const f of fields) {
    if (after[f] !== undefined && String(after[f] ?? '') !== String(before[f] ?? ''))
      d[f] = { from: before[f] ?? null, to: after[f] };
  }
  return d;
};

// ---- me / users ----
app.get('/api/me', (req, res) => ok(res, req.user
  ? { id: req.user.id, name: req.user.name, role: req.user.role, username: req.user.username }
  : { id: null, name: 'Open access', role: 'admin', username: null }));

app.post('/api/me/password', (req, res) => {
  if (!req.user) return forbidden(res);
  const pw = String(req.body.password || '');
  if (pw.length < 8) return res.status(400).json({ error: 'password must be at least 8 characters' });
  db.prepare('UPDATE people SET password_hash=? WHERE id=?').run(hashPassword(pw), req.user.id);
  audit(req, 'change_password', 'person', req.user.id, null, {});
  ok(res);
});

// admin: give a person a login / change role / remove login
app.post('/api/people/:id/login', (req, res) => {
  if (!isAdmin(req)) return forbidden(res);
  const p = db.prepare('SELECT * FROM people WHERE id=?').get(req.params.id);
  if (!p) return res.status(404).json({ error: 'not found' });
  const { username, password, role } = req.body;
  if (!username || !/^[a-z0-9._-]{2,40}$/i.test(username))
    return res.status(400).json({ error: 'username must be 2-40 letters/numbers/._-' });
  const clash = db.prepare('SELECT id FROM people WHERE lower(username)=lower(?) AND id<>?')
    .get(username, p.id);
  if (clash) return res.status(400).json({ error: 'username already taken' });
  if (password !== undefined && password !== '' && String(password).length < 8)
    return res.status(400).json({ error: 'password must be at least 8 characters' });
  const hash = password ? hashPassword(password) : p.password_hash;
  if (!hash) return res.status(400).json({ error: 'password required for a new login' });
  db.prepare('UPDATE people SET username=?, password_hash=?, role=? WHERE id=?')
    .run(username, hash, role === 'admin' ? 'admin' : 'member', p.id);
  audit(req, 'set_login', 'person', p.id, null, { username, role: role === 'admin' ? 'admin' : 'member', password_changed: !!password });
  ok(res);
});
app.delete('/api/people/:id/login', (req, res) => {
  if (!isAdmin(req)) return forbidden(res);
  if (req.user && Number(req.params.id) === req.user.id)
    return res.status(400).json({ error: "you can't remove your own login" });
  db.prepare("UPDATE people SET username=NULL, password_hash=NULL, role='member' WHERE id=?")
    .run(req.params.id);
  db.prepare('DELETE FROM sessions WHERE person_id=?').run(req.params.id);
  audit(req, 'remove_login', 'person', Number(req.params.id), null, {});
  ok(res);
});

// admin: who is allocated to a project (assignees are always implicitly included)
app.put('/api/projects/:id/members', (req, res) => {
  if (!isAdmin(req)) return forbidden(res);
  const pid = Number(req.params.id);
  if (!db.prepare('SELECT 1 FROM projects WHERE id=?').get(pid))
    return res.status(404).json({ error: 'not found' });
  const ids = (req.body.person_ids || []).map(Number).filter(Boolean);
  db.prepare('DELETE FROM project_members WHERE project_id=?').run(pid);
  const ins = db.prepare('INSERT OR IGNORE INTO project_members (project_id, person_id) VALUES (?,?)');
  for (const id of ids) ins.run(pid, id);
  audit(req, 'set_members', 'project', pid, pid, { person_ids: ids });
  ok(res);
});

// audit viewer: admins see everything, members see their visible projects
app.get('/api/audit', (req, res) => {
  const limit = Math.min(500, Number(req.query.limit) || 200);
  const pid = req.query.project_id ? Number(req.query.project_id) : null;
  const v = visibleProjectIds(req);
  let rows = db.prepare(`
    SELECT a.*, p.name AS who FROM audit a
    LEFT JOIN people p ON p.id = a.person_id
    ORDER BY a.id DESC LIMIT ?`).all(limit * 2);
  if (pid !== null) rows = rows.filter(r => r.project_id === pid);
  if (v !== null) rows = rows.filter(r =>
    r.project_id === null ? r.person_id === req.user.id : v.has(r.project_id));
  ok(res, rows.slice(0, limit));
});

function fullState(req) {
  const v = visibleProjectIds(req);
  const vis = (pid) => v === null || v.has(pid);
  return {
    projects: db.prepare('SELECT * FROM projects ORDER BY archived, created_at').all()
      .filter(p => vis(p.id)),
    people: db.prepare('SELECT id, name, color, username, role FROM people ORDER BY name').all(),
    tasks: db.prepare('SELECT * FROM tasks ORDER BY project_id, sort_order, start, id').all()
      .filter(t => vis(t.project_id)),
    deps: db.prepare('SELECT * FROM deps').all().filter(d => vis(d.project_id)),
    snapshots: db.prepare(
      'SELECT id, project_id, name, created_at FROM snapshots ORDER BY id DESC').all()
      .filter(s => vis(s.project_id)),
    members: db.prepare('SELECT * FROM project_members').all().filter(m => vis(m.project_id)),
  };
}

// ---- API ----
app.get('/api/state', (req, res) => ok(res, fullState(req)));

app.post('/api/projects', (req, res) => {
  const { name, color, due_date, notes } = req.body;
  const r = db.prepare('INSERT INTO projects (name, color, due_date, notes) VALUES (?,?,?,?)')
    .run(name, color || '#24bbb6', due_date || null, notes || '');
  const pid = r.lastInsertRowid;
  if (req.user && req.user.role !== 'admin')
    db.prepare('INSERT OR IGNORE INTO project_members (project_id, person_id) VALUES (?,?)')
      .run(pid, req.user.id);
  audit(req, 'create', 'project', pid, pid, { name });
  ok(res, { id: pid });
});
app.put('/api/projects/:id', (req, res) => {
  const p = db.prepare('SELECT * FROM projects WHERE id=?').get(req.params.id);
  if (!p) return res.status(404).json({ error: 'not found' });
  if (!canTouchProject(req, p.id)) return forbidden(res);
  const m = { ...p, ...req.body };
  db.prepare('UPDATE projects SET name=?, color=?, due_date=?, notes=?, archived=? WHERE id=?')
    .run(m.name, m.color, m.due_date, m.notes, m.archived ? 1 : 0, p.id);
  audit(req, 'update', 'project', p.id, p.id,
    diff(p, req.body, ['name', 'due_date', 'notes', 'archived', 'color']));
  ok(res);
});
app.delete('/api/projects/:id', (req, res) => {
  if (!isAdmin(req)) return forbidden(res);
  const p = db.prepare('SELECT * FROM projects WHERE id=?').get(req.params.id);
  if (p) {
    db.prepare('DELETE FROM projects WHERE id=?').run(p.id);
    db.prepare('DELETE FROM project_members WHERE project_id=?').run(p.id);
    audit(req, 'delete', 'project', p.id, p.id, { name: p.name });
  }
  ok(res);
});

app.post('/api/people', (req, res) => {
  const r = db.prepare('INSERT INTO people (name, color) VALUES (?,?)')
    .run(req.body.name, req.body.color || '#8a94a6');
  audit(req, 'create', 'person', r.lastInsertRowid, null, { name: req.body.name });
  ok(res, { id: r.lastInsertRowid });
});
app.put('/api/people/:id', (req, res) => {
  const p = db.prepare('SELECT * FROM people WHERE id=?').get(req.params.id);
  if (!p) return res.status(404).json({ error: 'not found' });
  const m = { ...p, ...req.body };
  db.prepare('UPDATE people SET name=?, color=? WHERE id=?').run(m.name, m.color, p.id);
  audit(req, 'update', 'person', p.id, null, diff(p, req.body, ['name', 'color']));
  ok(res);
});
app.delete('/api/people/:id', (req, res) => {
  if (!isAdmin(req)) return forbidden(res);
  const p = db.prepare('SELECT * FROM people WHERE id=?').get(req.params.id);
  if (p) {
    db.prepare('DELETE FROM people WHERE id=?').run(p.id);
    db.prepare('DELETE FROM sessions WHERE person_id=?').run(p.id);
    audit(req, 'delete', 'person', p.id, null, { name: p.name });
  }
  ok(res);
});

const TASK_FIELDS = ['name', 'start', 'end', 'done', 'milestone', 'person_id', 'notes', 'progress', 'parent_id'];
app.post('/api/tasks', (req, res) => {
  const t = req.body;
  if (!canTouchProject(req, t.project_id)) return forbidden(res);
  const progress = t.done ? 100 : Math.max(0, Math.min(100, Number(t.progress) || 0));
  const r = db.prepare(
    `INSERT INTO tasks (project_id, name, start, end, done, milestone, person_id, notes, progress, parent_id, sort_order)
     VALUES (?,?,?,?,?,?,?,?,?,?,
       COALESCE((SELECT MAX(sort_order)+1 FROM tasks WHERE project_id=?), 0))`
  ).run(t.project_id, t.name, t.start, t.end, progress >= 100 ? 1 : (t.done ? 1 : 0),
        t.milestone ? 1 : 0, t.person_id || null, t.notes || '', progress,
        t.parent_id || null, t.project_id);
  audit(req, 'create', 'task', r.lastInsertRowid, t.project_id,
    { name: t.name, start: t.start, end: t.end });
  ok(res, { id: r.lastInsertRowid });
});
app.put('/api/tasks/:id', (req, res) => {
  const t = db.prepare('SELECT * FROM tasks WHERE id=?').get(req.params.id);
  if (!t) return res.status(404).json({ error: 'not found' });
  if (!canTouchProject(req, t.project_id)) return forbidden(res);
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
  const d = diff(t, { ...req.body, progress: m.progress, done: m.done }, TASK_FIELDS);
  if (Object.keys(d).length)
    audit(req, 'update', 'task', t.id, t.project_id, { name: t.name, changes: d });
  ok(res);
});
app.post('/api/tasks/reorder', (req, res) => {
  const ids = req.body.ids || [];
  const first = ids.length ? taskProject(ids[0]) : null;
  if (first && !canTouchProject(req, first.project_id)) return forbidden(res);
  const stmt = db.prepare('UPDATE tasks SET sort_order=? WHERE id=?');
  ids.forEach((id, i) => stmt.run(i, id));
  if (first) audit(req, 'reorder', 'task', null, first.project_id, { count: ids.length });
  ok(res);
});
app.delete('/api/tasks/:id', (req, res) => {
  const t = db.prepare('SELECT * FROM tasks WHERE id=?').get(req.params.id);
  if (!t) return ok(res);
  if (!canTouchProject(req, t.project_id)) return forbidden(res);
  // deleting a phase promotes its subtasks rather than orphaning them
  db.prepare('UPDATE tasks SET parent_id=NULL WHERE parent_id=?').run(t.id);
  db.prepare('DELETE FROM tasks WHERE id=?').run(t.id);
  audit(req, 'delete', 'task', t.id, t.project_id, { name: t.name });
  ok(res);
});

// ---- settings + AI plan generation ----
function getSetting(key) {
  const r = db.prepare('SELECT value FROM settings WHERE key=?').get(key);
  return r ? r.value : null;
}
app.get('/api/settings', (req, res) =>
  ok(res, { openai: !!(getSetting('openai_key') || process.env.OPENAI_API_KEY) }));
app.post('/api/settings', async (req, res) => {
  if (!isAdmin(req)) return forbidden(res);
  if ('openai_key' in req.body) {
    const v = (req.body.openai_key || '').trim();
    if (v) {
      // verify with OpenAI right away so a bad paste fails at save time
      try {
        const r = await fetch('https://api.openai.com/v1/models', {
          headers: { Authorization: `Bearer ${v}` },
        });
        if (!r.ok) {
          return res.status(400).json({ error: 'invalid_key',
            detail: `OpenAI rejected the key (HTTP ${r.status}). Paste the full key exactly as shown when it was created — keys in the dashboard list are masked and can't be copied from there.` });
        }
      } catch (e) {
        return res.status(502).json({ error: 'openai_unreachable', detail: String(e.message) });
      }
      db.prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?,?)')
        .run('openai_key', v);
    } else {
      db.prepare('DELETE FROM settings WHERE key=?').run('openai_key');
    }
    audit(req, 'update', 'settings', null, null, { openai_key: req.body.openai_key ? 'set' : 'cleared' });
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
- If the user supplies their own task list, table, or schedule, reproduce it FAITHFULLY and COMPLETELY: every single task and phase, exact dates, no matter how many rows. Never summarise, merge, or drop tasks. Dates without a year belong to the schedule's stated timeframe.
- Only when the user gives a loose description (no task list) should you invent the plan yourself: 5–15 tasks with realistic durations.
- Milestones are single-day (start = end) gates like "Sign-off" or "Launch".
- Chain dependencies wherever work genuinely cannot start before another finishes — this is what drives the critical path. Parallel tracks should stay parallel.
- Owners like "Emotio / Campions" mean shared work — assign the first-named person.
- Group work into phases: add the phase itself as a task (dates spanning its subtasks, no depends_on, parent null) and set each subtask's "parent" to that phase's exact name. One level only. Milestones may sit inside phases.
- Deliverable columns become the task's "notes".
- Task names must be unique (prefix with the phase name if needed to disambiguate).`;
  const messages = [{ role: 'system', content: sys }];
  if (plan && amendment) {
    messages.push({ role: 'user', content:
      `Current plan JSON:\n${JSON.stringify(plan)}\n\nAmend it as follows: ${amendment}\n\nReturn the complete amended plan JSON.` });
  } else {
    messages.push({ role: 'user', content: String(prompt || '') });
  }
  // big briefs (detailed schedules) get the stronger model and full output headroom
  const big = String(prompt || '').length > 1200 ||
    (plan && Array.isArray(plan.tasks) && plan.tasks.length > 20);
  try {
    const r = await fetch('https://api.openai.com/v1/chat/completions', {
      method: 'POST',
      headers: { Authorization: `Bearer ${key}`, 'Content-Type': 'application/json' },
      body: JSON.stringify({ model: big ? 'gpt-4o' : 'gpt-4o-mini',
        max_tokens: 16000,
        response_format: { type: 'json_object' }, messages }),
    });
    if (!r.ok) {
      const detail = (await r.text()).slice(0, 400);
      return res.status(502).json({ error: 'openai_error', detail });
    }
    const j = await r.json();
    if (j.choices[0].finish_reason === 'length') {
      return res.status(502).json({ error: 'plan_too_long',
        detail: 'The plan was too large to generate in one go — try splitting the brief into two projects, or import it as CSV instead.' });
    }
    const parsed = JSON.parse(j.choices[0].message.content);
    if (!parsed.name || !Array.isArray(parsed.tasks)) throw new Error('bad shape');
    ok(res, { plan: parsed });
  } catch (e) {
    res.status(502).json({ error: 'bad_response', detail: String(e.message) });
  }
});

app.post('/api/snapshots', (req, res) => {
  const { project_id, name } = req.body;
  if (!canTouchProject(req, project_id)) return forbidden(res);
  const tasks = db.prepare(
    'SELECT id, name, start, end, milestone, done FROM tasks WHERE project_id=?').all(project_id);
  const r = db.prepare('INSERT INTO snapshots (project_id, name, data) VALUES (?,?,?)')
    .run(project_id, name || 'Snapshot', JSON.stringify(tasks));
  audit(req, 'create', 'snapshot', r.lastInsertRowid, project_id, { name });
  ok(res, { id: r.lastInsertRowid });
});
app.get('/api/snapshots/:id', (req, res) => {
  const s = db.prepare('SELECT * FROM snapshots WHERE id=?').get(req.params.id);
  if (!s) return res.status(404).json({ error: 'not found' });
  if (!canTouchProject(req, s.project_id)) return forbidden(res);
  ok(res, { ...s, data: JSON.parse(s.data) });
});
app.delete('/api/snapshots/:id', (req, res) => {
  const s = db.prepare('SELECT * FROM snapshots WHERE id=?').get(req.params.id);
  if (!s) return ok(res);
  if (!canTouchProject(req, s.project_id)) return forbidden(res);
  db.prepare('DELETE FROM snapshots WHERE id=?').run(s.id);
  audit(req, 'delete', 'snapshot', s.id, s.project_id, { name: s.name });
  ok(res);
});

app.post('/api/deps', (req, res) => {
  const { project_id, pred_id, succ_id } = req.body;
  if (pred_id === succ_id) return res.status(400).json({ error: 'self-dependency' });
  if (!canTouchProject(req, project_id)) return forbidden(res);
  try {
    const r = db.prepare('INSERT INTO deps (project_id, pred_id, succ_id) VALUES (?,?,?)')
      .run(project_id, pred_id, succ_id);
    audit(req, 'create', 'dependency', r.lastInsertRowid, project_id,
      { pred_id, succ_id });
    ok(res, { id: r.lastInsertRowid });
  } catch (e) {
    res.status(400).json({ error: 'duplicate' });
  }
});
app.delete('/api/deps/:id', (req, res) => {
  const d = db.prepare('SELECT * FROM deps WHERE id=?').get(req.params.id);
  if (!d) return ok(res);
  if (!canTouchProject(req, d.project_id)) return forbidden(res);
  db.prepare('DELETE FROM deps WHERE id=?').run(d.id);
  audit(req, 'delete', 'dependency', d.id, d.project_id, { pred_id: d.pred_id, succ_id: d.succ_id });
  ok(res);
});

app.listen(PORT, () => console.log(`EmotioGantt running on http://localhost:${PORT}`));
