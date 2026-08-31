// MCP server (Streamable HTTP, stateless) exposing EmotioGantt projects
// as tools. Enabled only when MCP_TOKEN is set; auth via Bearer header or
// ?token= query for clients that cannot set headers.
const db = require('./db');

const TOKEN = process.env.MCP_TOKEN || '';
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

const date = (s, field) => {
  if (!DATE_RE.test(s || '')) throw new Error(`${field} must be YYYY-MM-DD`);
  return s;
};
const getTask = (id) => {
  const t = db.prepare('SELECT * FROM tasks WHERE id=?').get(id);
  if (!t) throw new Error(`no task with id ${id}`);
  return t;
};
const getProject = (id) => {
  const p = db.prepare('SELECT * FROM projects WHERE id=?').get(id);
  if (!p) throw new Error(`no project with id ${id}`);
  return p;
};
const personId = (name) => {
  if (!name) return null;
  const p = db.prepare('SELECT id FROM people WHERE lower(name)=lower(?)').get(name.trim());
  if (p) return p.id;
  return db.prepare('INSERT INTO people (name, color) VALUES (?, ?)')
    .run(name.trim(), '#8a94a6').lastInsertRowid;
};
const coupleProgress = (m, body) => {
  if (body.progress !== undefined) {
    m.progress = Math.max(0, Math.min(100, Number(body.progress) || 0));
    m.done = m.progress >= 100 ? 1 : 0;
  } else if (body.done !== undefined) {
    m.done = body.done ? 1 : 0;
    m.progress = m.done ? 100 : (m.progress >= 100 ? 0 : m.progress);
  }
};

const tools = [
  { name: 'list_projects',
    description: 'List all projects with task counts and overall progress.',
    inputSchema: { type: 'object', properties: {} } },
  { name: 'get_project',
    description: 'Full detail for one project: every task (with dates, person, phase parent_id, milestone/done/progress, notes), dependencies, and snapshots. Tasks whose id appears as another task\'s parent_id are phases.',
    inputSchema: { type: 'object', properties: {
      project_id: { type: 'number' } }, required: ['project_id'] } },
  { name: 'create_project',
    description: 'Create a project. Dates are YYYY-MM-DD.',
    inputSchema: { type: 'object', properties: {
      name: { type: 'string' }, due_date: { type: 'string' },
      color: { type: 'string' }, notes: { type: 'string' } }, required: ['name'] } },
  { name: 'update_project',
    description: 'Update project fields (name, due_date, notes, color, archived).',
    inputSchema: { type: 'object', properties: {
      project_id: { type: 'number' }, name: { type: 'string' },
      due_date: { type: 'string' }, notes: { type: 'string' },
      color: { type: 'string' }, archived: { type: 'boolean' } },
      required: ['project_id'] } },
  { name: 'delete_project',
    description: 'Delete a project and all its tasks, dependencies, and snapshots. Irreversible.',
    inputSchema: { type: 'object', properties: {
      project_id: { type: 'number' } }, required: ['project_id'] } },
  { name: 'create_task',
    description: 'Create a task. person is a name (created if new). parent_task_id makes it a subtask of that phase. milestone tasks are single-day. depends_on_task_ids adds finish-to-start dependencies.',
    inputSchema: { type: 'object', properties: {
      project_id: { type: 'number' }, name: { type: 'string' },
      start: { type: 'string' }, end: { type: 'string' },
      person: { type: 'string' }, milestone: { type: 'boolean' },
      parent_task_id: { type: 'number' }, notes: { type: 'string' },
      progress: { type: 'number' },
      depends_on_task_ids: { type: 'array', items: { type: 'number' } } },
      required: ['project_id', 'name', 'start'] } },
  { name: 'update_task',
    description: 'Update task fields. person is a name; set parent_task_id to 0 to move a subtask to the top level. progress 100 marks done; done=false resets a finished task.',
    inputSchema: { type: 'object', properties: {
      task_id: { type: 'number' }, name: { type: 'string' },
      start: { type: 'string' }, end: { type: 'string' },
      person: { type: 'string' }, milestone: { type: 'boolean' },
      parent_task_id: { type: 'number' }, notes: { type: 'string' },
      progress: { type: 'number' }, done: { type: 'boolean' } },
      required: ['task_id'] } },
  { name: 'delete_task',
    description: 'Delete a task. If it is a phase, its subtasks are promoted to the top level.',
    inputSchema: { type: 'object', properties: {
      task_id: { type: 'number' } }, required: ['task_id'] } },
  { name: 'add_dependency',
    description: 'Add a finish-to-start dependency: the successor cannot start until the predecessor finishes. Drives the critical path.',
    inputSchema: { type: 'object', properties: {
      predecessor_task_id: { type: 'number' },
      successor_task_id: { type: 'number' } },
      required: ['predecessor_task_id', 'successor_task_id'] } },
  { name: 'remove_dependency',
    description: 'Remove the dependency between two tasks.',
    inputSchema: { type: 'object', properties: {
      predecessor_task_id: { type: 'number' },
      successor_task_id: { type: 'number' } },
      required: ['predecessor_task_id', 'successor_task_id'] } },
  { name: 'list_people',
    description: 'List people and how many open tasks each carries.',
    inputSchema: { type: 'object', properties: {} } },
  { name: 'create_snapshot',
    description: 'Freeze the current plan of a project as a named snapshot for later baseline comparison.',
    inputSchema: { type: 'object', properties: {
      project_id: { type: 'number' }, name: { type: 'string' } },
      required: ['project_id', 'name'] } },
];

const impl = {
  list_projects() {
    return db.prepare('SELECT * FROM projects ORDER BY archived, created_at').all()
      .map(p => {
        const tasks = db.prepare('SELECT * FROM tasks WHERE project_id=?').all(p.id);
        const parents = new Set(tasks.filter(t => t.parent_id).map(t => t.parent_id));
        const leafs = tasks.filter(t => !parents.has(t.id));
        const totalDays = leafs.reduce((a, t) =>
          a + (Date.parse(t.end) - Date.parse(t.start)) / 86400000 + 1, 0);
        const doneDays = leafs.reduce((a, t) =>
          a + ((Date.parse(t.end) - Date.parse(t.start)) / 86400000 + 1) *
              (t.done ? 100 : t.progress) / 100, 0);
        return { id: p.id, name: p.name, due_date: p.due_date, color: p.color,
          archived: !!p.archived, task_count: leafs.length, phase_count: parents.size,
          progress_pct: totalDays ? Math.round(doneDays / totalDays * 100) : 0 };
      });
  },
  get_project({ project_id }) {
    const p = getProject(project_id);
    const people = new Map(db.prepare('SELECT * FROM people').all().map(x => [x.id, x.name]));
    const tasks = db.prepare(
      'SELECT * FROM tasks WHERE project_id=? ORDER BY sort_order, start, id').all(p.id)
      .map(t => ({ id: t.id, name: t.name, start: t.start, end: t.end,
        person: people.get(t.person_id) || null, parent_id: t.parent_id,
        milestone: !!t.milestone, done: !!t.done, progress: t.progress,
        notes: t.notes || null }));
    return { project: { id: p.id, name: p.name, due_date: p.due_date,
        notes: p.notes || null, archived: !!p.archived },
      tasks,
      dependencies: db.prepare('SELECT pred_id, succ_id FROM deps WHERE project_id=?').all(p.id),
      snapshots: db.prepare(
        'SELECT id, name, created_at FROM snapshots WHERE project_id=?').all(p.id) };
  },
  create_project({ name, due_date, color, notes }) {
    if (!name) throw new Error('name is required');
    const r = db.prepare('INSERT INTO projects (name, color, due_date, notes) VALUES (?,?,?,?)')
      .run(name, color || '#24bbb6', due_date ? date(due_date, 'due_date') : null, notes || '');
    return { project_id: r.lastInsertRowid };
  },
  update_project(a) {
    const p = getProject(a.project_id);
    const m = { ...p, ...a };
    if (a.due_date !== undefined && a.due_date !== null && a.due_date !== '')
      date(a.due_date, 'due_date');
    db.prepare('UPDATE projects SET name=?, color=?, due_date=?, notes=?, archived=? WHERE id=?')
      .run(m.name, m.color, m.due_date || null, m.notes, m.archived ? 1 : 0, p.id);
    return { ok: true };
  },
  delete_project({ project_id }) {
    getProject(project_id);
    db.prepare('DELETE FROM projects WHERE id=?').run(project_id);
    return { ok: true };
  },
  create_task(a) {
    getProject(a.project_id);
    const start = date(a.start, 'start');
    let end = a.milestone ? start : (a.end ? date(a.end, 'end') : start);
    if (end < start) end = start;
    if (a.parent_task_id) {
      const par = getTask(a.parent_task_id);
      if (par.parent_id) throw new Error('phases are one level deep — that parent is itself a subtask');
    }
    const progress = Math.max(0, Math.min(100, Number(a.progress) || 0));
    const r = db.prepare(
      `INSERT INTO tasks (project_id, name, start, end, done, milestone, person_id, notes, progress, parent_id, sort_order)
       VALUES (?,?,?,?,?,?,?,?,?,?,
         COALESCE((SELECT MAX(sort_order)+1 FROM tasks WHERE project_id=?), 0))`)
      .run(a.project_id, a.name, start, end, progress >= 100 ? 1 : 0,
        a.milestone ? 1 : 0, personId(a.person), a.notes || '', progress,
        a.parent_task_id || null, a.project_id);
    const id = r.lastInsertRowid;
    for (const pid of (a.depends_on_task_ids || [])) {
      const pred = getTask(pid);
      if (pred.project_id !== a.project_id) throw new Error(`task ${pid} is in a different project`);
      db.prepare('INSERT OR IGNORE INTO deps (project_id, pred_id, succ_id) VALUES (?,?,?)')
        .run(a.project_id, pid, id);
    }
    return { task_id: id };
  },
  update_task(a) {
    const t = getTask(a.task_id);
    const m = { ...t };
    if (a.name !== undefined) m.name = a.name;
    if (a.start !== undefined) m.start = date(a.start, 'start');
    if (a.end !== undefined) m.end = date(a.end, 'end');
    if (a.milestone !== undefined) m.milestone = a.milestone ? 1 : 0;
    if (m.milestone) m.end = m.start;
    if (m.end < m.start) m.end = m.start;
    if (a.notes !== undefined) m.notes = a.notes;
    if (a.person !== undefined) m.person_id = a.person ? personId(a.person) : null;
    if (a.parent_task_id !== undefined) {
      if (a.parent_task_id) {
        const par = getTask(a.parent_task_id);
        if (par.id === t.id) throw new Error('a task cannot be its own phase');
        if (par.parent_id) throw new Error('phases are one level deep');
        if (db.prepare('SELECT 1 FROM tasks WHERE parent_id=?').get(t.id))
          throw new Error('that task is a phase itself — move its subtasks out first');
        m.parent_id = a.parent_task_id;
      } else m.parent_id = null;
    }
    coupleProgress(m, a);
    db.prepare(
      'UPDATE tasks SET name=?, start=?, end=?, done=?, milestone=?, person_id=?, notes=?, progress=?, parent_id=?, sort_order=? WHERE id=?')
      .run(m.name, m.start, m.end, m.done ? 1 : 0, m.milestone ? 1 : 0,
        m.person_id || null, m.notes, m.progress || 0, m.parent_id || null,
        m.sort_order, t.id);
    return { ok: true };
  },
  delete_task({ task_id }) {
    getTask(task_id);
    db.prepare('UPDATE tasks SET parent_id=NULL WHERE parent_id=?').run(task_id);
    db.prepare('DELETE FROM tasks WHERE id=?').run(task_id);
    return { ok: true };
  },
  add_dependency({ predecessor_task_id, successor_task_id }) {
    const pred = getTask(predecessor_task_id), succ = getTask(successor_task_id);
    if (pred.id === succ.id) throw new Error('a task cannot depend on itself');
    if (pred.project_id !== succ.project_id) throw new Error('tasks are in different projects');
    try {
      db.prepare('INSERT INTO deps (project_id, pred_id, succ_id) VALUES (?,?,?)')
        .run(pred.project_id, pred.id, succ.id);
    } catch (e) { throw new Error('those tasks are already linked'); }
    return { ok: true };
  },
  remove_dependency({ predecessor_task_id, successor_task_id }) {
    const r = db.prepare('DELETE FROM deps WHERE pred_id=? AND succ_id=?')
      .run(predecessor_task_id, successor_task_id);
    if (!r.changes) throw new Error('no such dependency');
    return { ok: true };
  },
  list_people() {
    return db.prepare('SELECT * FROM people ORDER BY name').all().map(p => ({
      id: p.id, name: p.name,
      open_tasks: db.prepare(
        'SELECT COUNT(*) c FROM tasks WHERE person_id=? AND done=0').get(p.id).c }));
  },
  create_snapshot({ project_id, name }) {
    getProject(project_id);
    const tasks = db.prepare(
      'SELECT id, name, start, end, milestone, done FROM tasks WHERE project_id=?').all(project_id);
    const r = db.prepare('INSERT INTO snapshots (project_id, name, data) VALUES (?,?,?)')
      .run(project_id, name || 'Snapshot', JSON.stringify(tasks));
    return { snapshot_id: r.lastInsertRowid };
  },
};

function handle(req, res) {
  if (!TOKEN) return res.status(404).end();
  const oauth = require('./oauth'); // late require avoids a load cycle
  const auth = req.headers.authorization || '';
  const bearer = auth.startsWith('Bearer ') ? auth.slice(7) : '';
  const authed = (bearer && (bearer === TOKEN || oauth.isValidToken(bearer))) ||
    (req.query.token || '') === TOKEN;
  if (!authed) {
    res.set('WWW-Authenticate',
      `Bearer resource_metadata="${oauth.PUBLIC_URL}/.well-known/oauth-protected-resource"`);
    return res.status(401).json({ error: 'unauthorized' });
  }
  const m = req.body || {};
  if (m.id === undefined || m.id === null) return res.status(202).end(); // notification
  const reply = (result) => res.json({ jsonrpc: '2.0', id: m.id, result });
  const fail = (code, message) => res.json({ jsonrpc: '2.0', id: m.id, error: { code, message } });
  switch (m.method) {
    case 'initialize':
      return reply({
        protocolVersion: (m.params && m.params.protocolVersion) || '2024-11-05',
        capabilities: { tools: {} },
        serverInfo: { name: 'emotiogantt', version: '1.0.0' } });
    case 'ping':
      return reply({});
    case 'tools/list':
      return reply({ tools });
    case 'tools/call': {
      const name = m.params && m.params.name;
      const fn = impl[name];
      if (!fn) return fail(-32602, `unknown tool: ${name}`);
      const args = (m.params && m.params.arguments) || {};
      try {
        const out = fn(args);
        if (!/^(list_|get_)/.test(name)) {
          const pid = args.project_id ||
            (args.task_id && (db.prepare('SELECT project_id FROM tasks WHERE id=?').get(args.task_id) || {}).project_id) ||
            (args.predecessor_task_id && (db.prepare('SELECT project_id FROM tasks WHERE id=?').get(args.predecessor_task_id) || {}).project_id) ||
            out.project_id || null;
          db.prepare('INSERT INTO audit (person_id, via, action, entity, entity_id, project_id, detail) VALUES (?,?,?,?,?,?,?)')
            .run(null, 'mcp', name, 'mcp', out.task_id || out.snapshot_id || null,
              pid, JSON.stringify(args).slice(0, 2000));
        }
        return reply({ content: [{ type: 'text', text: JSON.stringify(out, null, 1) }] });
      } catch (e) {
        return reply({ content: [{ type: 'text', text: 'Error: ' + e.message }], isError: true });
      }
    }
    default:
      return fail(-32601, `method not supported: ${m.method}`);
  }
}

module.exports = { handle };
