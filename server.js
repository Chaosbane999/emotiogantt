const express = require('express');
const crypto = require('crypto');
const path = require('path');
const db = require('./db');

const app = express();
const PORT = process.env.PORT || 3000;
const PASSCODE = process.env.APP_PASSCODE || '';

app.use(express.json());

// ---- optional passcode gate (set APP_PASSCODE in production) ----
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

app.post('/login', (req, res) => {
  if (!authToken) return res.json({ ok: true });
  if ((req.body.passcode || '') === PASSCODE) {
    res.setHeader('Set-Cookie',
      `cp_auth=${authToken}; Path=/; HttpOnly; SameSite=Lax; Max-Age=31536000`);
    return res.json({ ok: true });
  }
  res.status(401).json({ ok: false });
});

app.use((req, res, next) => {
  if (!authToken) return next();
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
  const r = db.prepare(
    `INSERT INTO tasks (project_id, name, start, end, done, milestone, person_id, notes, sort_order)
     VALUES (?,?,?,?,?,?,?,?,
       COALESCE((SELECT MAX(sort_order)+1 FROM tasks WHERE project_id=?), 0))`
  ).run(t.project_id, t.name, t.start, t.end, t.done ? 1 : 0, t.milestone ? 1 : 0,
        t.person_id || null, t.notes || '', t.project_id);
  ok(res, { id: r.lastInsertRowid });
});
app.put('/api/tasks/:id', (req, res) => {
  const t = db.prepare('SELECT * FROM tasks WHERE id=?').get(req.params.id);
  if (!t) return res.status(404).json({ error: 'not found' });
  const m = { ...t, ...req.body };
  db.prepare(
    'UPDATE tasks SET name=?, start=?, end=?, done=?, milestone=?, person_id=?, notes=?, sort_order=? WHERE id=?'
  ).run(m.name, m.start, m.end, m.done ? 1 : 0, m.milestone ? 1 : 0,
        m.person_id || null, m.notes, m.sort_order, t.id);
  ok(res);
});
app.post('/api/tasks/reorder', (req, res) => {
  const ids = req.body.ids || [];
  const stmt = db.prepare('UPDATE tasks SET sort_order=? WHERE id=?');
  ids.forEach((id, i) => stmt.run(i, id));
  ok(res);
});
app.delete('/api/tasks/:id', (req, res) => {
  db.prepare('DELETE FROM tasks WHERE id=?').run(req.params.id);
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
