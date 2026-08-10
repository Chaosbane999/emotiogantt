// Date helpers (all dates are 'YYYY-MM-DD' strings, math done in UTC)
const DAY = 86400000;
const D = {
  parse: (s) => new Date(s + 'T00:00:00Z').getTime(),
  fmt: (ms) => new Date(ms).toISOString().slice(0, 10),
  add: (s, days) => D.fmt(D.parse(s) + days * DAY),
  diff: (a, b) => Math.round((D.parse(b) - D.parse(a)) / DAY), // b - a in days
  today: () => new Date().toISOString().slice(0, 10),
  human: (s) => new Date(s + 'T00:00:00Z').toLocaleDateString(undefined,
    { day: 'numeric', month: 'short', timeZone: 'UTC' }),
  humanFull: (s) => new Date(s + 'T00:00:00Z').toLocaleDateString(undefined,
    { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' }),
  isWeekend: (s) => { const d = new Date(s + 'T00:00:00Z').getUTCDay(); return d === 0 || d === 6; },
};

// Critical path (CPM backward pass over scheduled dates, finish-to-start deps).
// Returns Map taskId -> { slack, critical, lf } for one project.
// slack = latest finish (without delaying the project) minus scheduled end.
// critical = slack <= 0 on the chain that drives the project finish.
function computeCPM(tasks, deps) {
  const result = new Map();
  if (!tasks.length) return result;

  const byId = new Map(tasks.map(t => [t.id, t]));
  const succs = new Map(tasks.map(t => [t.id, []]));
  for (const d of deps) {
    if (byId.has(d.pred_id) && byId.has(d.succ_id)) succs.get(d.pred_id).push(d.succ_id);
  }

  const projectFinish = Math.max(...tasks.map(t => D.parse(t.end)));

  // Memoized latest finish: min over successors of (their latest start - 1 day)
  const lfCache = new Map();
  const visiting = new Set();
  function latestFinish(id) {
    if (lfCache.has(id)) return lfCache.get(id);
    if (visiting.has(id)) return projectFinish; // cycle guard
    visiting.add(id);
    const t = byId.get(id);
    const dur = D.parse(t.end) - D.parse(t.start); // ms span
    let lf = projectFinish;
    for (const sid of succs.get(id)) {
      const s = byId.get(sid);
      const sDur = D.parse(s.end) - D.parse(s.start);
      const sLatestStart = latestFinish(sid) - sDur;
      lf = Math.min(lf, sLatestStart - DAY);
    }
    visiting.delete(id);
    lfCache.set(id, lf);
    return lf;
  }

  for (const t of tasks) {
    const lf = latestFinish(t.id);
    const slack = Math.round((lf - D.parse(t.end)) / DAY);
    result.set(t.id, { slack, lf: D.fmt(lf), critical: slack <= 0 && !t.done });
  }
  return result;
}

// Project health: 'good' | 'watch' | 'late'
function projectHealth(project, tasks, cpm) {
  const today = D.today();
  const open = tasks.filter(t => !t.done);
  if (!open.length) return tasks.length ? 'good' : 'good';
  const overdue = open.some(t => t.end < today);
  const projected = D.fmt(Math.max(...open.map(t => D.parse(t.end))));
  if (overdue || (project.due_date && projected > project.due_date)) return 'late';
  const soonCritical = open.some(t => {
    const c = cpm.get(t.id);
    return c && c.critical && D.diff(today, t.end) <= 3;
  });
  const dueSoon = open.some(t => D.diff(today, t.end) <= 1 && t.start <= today);
  if (soonCritical || dueSoon) return 'watch';
  return 'good';
}

window.CPM = { D, computeCPM, projectHealth };
