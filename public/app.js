// ClearPath app — state, routing, views
(function () {
  const { D, computeCPM, projectHealth } = window.CPM;
  const view = document.getElementById('view');
  const panel = document.getElementById('panel');
  const scrim = document.getElementById('scrim');

  let S = { projects: [], people: [], tasks: [], deps: [] };
  let ganttZoom = localStorage.getItem('cp_zoom') || 'day';

  const PROJECT_COLORS = ['#24bbb6', '#6b7fd7', '#6faa8d', '#d9a648', '#c77fb3', '#a08b6f'];
  const PEOPLE_COLORS = ['#24bbb6', '#8a94a6', '#6b7fd7', '#6faa8d', '#c77fb3', '#d9a648'];

  // ---------- data ----------
  async function api(method, url, body) {
    const r = await fetch(url, {
      method,
      headers: body ? { 'Content-Type': 'application/json' } : undefined,
      body: body ? JSON.stringify(body) : undefined,
    });
    if (r.status === 401) { location.href = '/login.html'; throw new Error('auth'); }
    if (!r.ok) throw new Error(await r.text());
    return r.json();
  }
  async function reload() { S = await api('GET', '/api/state'); }
  async function mutate(method, url, body) { await api(method, url, body); await reload(); route(); }

  const projTasks = (pid) => S.tasks.filter(t => t.project_id === pid);
  const projDeps = (pid) => S.deps.filter(d => d.project_id === pid);
  const cpmFor = (pid) => computeCPM(projTasks(pid), projDeps(pid));
  const personName = (id) => (S.people.find(p => p.id === id) || {}).name || null;
  const projById = (id) => S.projects.find(p => p.id === id);

  function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  function countdownChip(end, done) {
    if (done) return '';
    const days = D.diff(D.today(), end);
    if (days < 0) return `<span class="countdown overdue">${-days}d past due</span>`;
    if (days === 0) return `<span class="countdown today">due today</span>`;
    if (days === 1) return `<span class="countdown">due tomorrow</span>`;
    return `<span class="countdown">${days} days left</span>`;
  }

  const HEALTH_LABEL = { good: 'On track', watch: 'Needs a look', late: 'Slipping' };

  // ---------- panel (side editor) ----------
  function openPanel(html) {
    panel.innerHTML = html;
    panel.classList.remove('hidden');
    scrim.classList.remove('hidden');
  }
  function closePanel() {
    panel.classList.add('hidden');
    scrim.classList.add('hidden');
    panel.innerHTML = '';
  }
  scrim.addEventListener('click', closePanel);
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closePanel(); });

  // ---------- views ----------
  function setNav(name) {
    document.querySelectorAll('[data-nav]').forEach(a =>
      a.classList.toggle('active', a.dataset.nav === name));
  }

  // ----- Today -----
  function renderToday() {
    setNav('today');
    const today = D.today();
    const cpms = new Map(S.projects.map(p => [p.id, cpmFor(p.id)]));
    const open = S.tasks.filter(t => {
      const p = projById(t.project_id);
      return !t.done && p && !p.archived;
    });

    const focus = open
      .filter(t => t.start <= today && (t.end >= today || t.end < today))
      .filter(t => t.start <= today)
      .sort((a, b) => {
        const ac = cpms.get(a.project_id).get(a.id)?.critical ? 0 : 1;
        const bc = cpms.get(b.project_id).get(b.id)?.critical ? 0 : 1;
        return ac - bc || a.end.localeCompare(b.end);
      });

    const upcoming = open
      .filter(t => t.start > today && D.diff(today, t.start) <= 7)
      .sort((a, b) => a.start.localeCompare(b.start));

    const item = (t) => {
      const crit = cpms.get(t.project_id).get(t.id)?.critical;
      const proj = projById(t.project_id);
      const who = personName(t.person_id);
      return `<div class="focus-item ${crit ? 'critical' : ''}">
        <input type="checkbox" data-done="${t.id}" title="Mark done">
        <div class="grow">
          <div class="t-name">${esc(t.name)} ${crit ? '<span class="pill critical">critical path</span>' : ''}</div>
          <div class="t-sub"><span class="dot" style="background:${proj.color};display:inline-block;margin-right:5px"></span>${esc(proj.name)}${who ? ' · ' + esc(who) : ''}</div>
        </div>
        ${countdownChip(t.end, t.done)}
      </div>`;
    };

    view.innerHTML = `
      <h1>Today</h1>
      <p class="subtitle">${new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })}</p>
      <h2>Focus now</h2>
      ${focus.length
        ? `<div class="focus-list">${focus.map(item).join('')}</div>`
        : `<div class="empty-state"><span class="big-emoji">🌤️</span>Nothing needs your attention right now.<br>Enjoy the calm.</div>`}
      ${upcoming.length ? `<h2>Coming up this week</h2>
        <div class="focus-list">${upcoming.map(t => {
          const proj = projById(t.project_id);
          return `<div class="focus-item">
            <div class="grow">
              <div class="t-name">${esc(t.name)}</div>
              <div class="t-sub"><span class="dot" style="background:${proj.color};display:inline-block;margin-right:5px"></span>${esc(proj.name)} · starts ${D.humanFull(t.start)}</div>
            </div>
            ${countdownChip(t.end, false)}
          </div>`;
        }).join('')}</div>` : ''}
    `;
    view.querySelectorAll('[data-done]').forEach(cb =>
      cb.addEventListener('change', () =>
        mutate('PUT', `/api/tasks/${cb.dataset.done}`, { done: 1 })));
  }

  // ----- Projects dashboard -----
  function renderProjects() {
    setNav('projects');
    const active = S.projects.filter(p => !p.archived);
    const cards = active.map(p => {
      const tasks = projTasks(p.id);
      const cpm = cpmFor(p.id);
      const health = projectHealth(p, tasks, cpm);
      const doneCount = tasks.filter(t => t.done).length;
      const pct = tasks.length ? Math.round(doneCount / tasks.length * 100) : 0;
      const dueBit = p.due_date
        ? `Due ${D.human(p.due_date)} · ${Math.max(0, D.diff(D.today(), p.due_date))}d`
        : 'No due date';
      return `<div class="card project-card" data-open="${p.id}">
        <div class="head">
          <span class="dot" style="background:${p.color}"></span>
          <span class="name">${esc(p.name)}</span>
          <span class="pill ${health}">${HEALTH_LABEL[health]}</span>
        </div>
        <div class="progress-track"><div class="progress-fill" style="width:${pct}%;background:${p.color}"></div></div>
        <div class="card-meta"><span>${doneCount}/${tasks.length} tasks · ${pct}%</span><span>${dueBit}</span></div>
      </div>`;
    }).join('');

    view.innerHTML = `
      <h1>Projects in hand</h1>
      <p class="subtitle">Everything you're juggling, at a glance.</p>
      <div class="cards-grid">
        ${cards}
        <div class="card new-card" id="newProject">+ New project</div>
      </div>`;
    view.querySelectorAll('[data-open]').forEach(c =>
      c.addEventListener('click', () => { location.hash = `#/project/${c.dataset.open}`; }));
    document.getElementById('newProject').addEventListener('click', () => editProject(null));
  }

  function editProject(p) {
    const isNew = !p;
    p = p || { name: '', color: PROJECT_COLORS[S.projects.length % PROJECT_COLORS.length], due_date: '', notes: '' };
    openPanel(`
      <h3>${isNew ? 'New project' : 'Edit project'}</h3>
      <label>Name</label><input id="f_name" value="${esc(p.name)}" placeholder="e.g. Kitchen renovation">
      <label>Colour</label>
      <div class="color-swatches">${PROJECT_COLORS.map(c =>
        `<div class="swatch ${c === p.color ? 'sel' : ''}" data-c="${c}" style="background:${c}"></div>`).join('')}</div>
      <label>Due date (optional)</label><input id="f_due" type="date" value="${p.due_date || ''}">
      <label>Notes</label><textarea id="f_notes" rows="3">${esc(p.notes)}</textarea>
      <div class="panel-actions">
        <button class="btn primary grow" id="f_save">${isNew ? 'Create' : 'Save'}</button>
        ${!isNew ? '<button class="btn danger" id="f_del">Delete</button>' : ''}
        <button class="btn" id="f_cancel">Cancel</button>
      </div>`);
    let color = p.color;
    panel.querySelectorAll('.swatch').forEach(s => s.addEventListener('click', () => {
      color = s.dataset.c;
      panel.querySelectorAll('.swatch').forEach(x => x.classList.toggle('sel', x === s));
    }));
    panel.querySelector('#f_cancel').addEventListener('click', closePanel);
    panel.querySelector('#f_save').addEventListener('click', async () => {
      const name = panel.querySelector('#f_name').value.trim();
      if (!name) return;
      const body = { name, color, due_date: panel.querySelector('#f_due').value || null,
        notes: panel.querySelector('#f_notes').value };
      closePanel();
      if (isNew) await mutate('POST', '/api/projects', body);
      else await mutate('PUT', `/api/projects/${p.id}`, body);
    });
    if (!isNew) panel.querySelector('#f_del').addEventListener('click', async () => {
      if (!confirm(`Delete "${p.name}" and all its tasks?`)) return;
      closePanel();
      location.hash = '#/projects';
      await mutate('DELETE', `/api/projects/${p.id}`);
    });
  }

  // ----- Project detail (Gantt) -----
  function renderProject(pid) {
    setNav('projects');
    const p = projById(pid);
    if (!p) { location.hash = '#/projects'; return; }
    const tasks = projTasks(pid);
    const deps = projDeps(pid);
    const cpm = cpmFor(pid);
    const health = projectHealth(p, tasks, cpm);

    view.innerHTML = `
      <div class="gantt-toolbar">
        <a href="#/projects" class="btn small">← All projects</a>
        <h1 style="margin:0;display:flex;align-items:center;gap:10px">
          <span class="dot" style="background:${p.color}"></span>${esc(p.name)}
        </h1>
        <span class="pill ${health}">${HEALTH_LABEL[health]}</span>
        <div class="spacer"></div>
        <div class="zoom-toggle">
          <button data-z="day" class="${ganttZoom === 'day' ? 'active' : ''}">Day</button>
          <button data-z="week" class="${ganttZoom === 'week' ? 'active' : ''}">Week</button>
        </div>
        <button class="btn small" id="editProj">Settings</button>
      </div>
      <div id="ganttHost"></div>
      <p class="muted" style="font-size:13px;margin-top:14px">
        <span style="color:var(--critical)">■</span> Critical path — a delay here delays the whole project.
        Drag bars to reschedule; drag edges to change length; click a task to edit.
      </p>`;

    view.querySelectorAll('[data-z]').forEach(b => b.addEventListener('click', () => {
      ganttZoom = b.dataset.z; localStorage.setItem('cp_zoom', ganttZoom); route();
    }));
    view.querySelector('#editProj').addEventListener('click', () => editProject(p));

    if (!tasks.length) {
      document.getElementById('ganttHost').innerHTML =
        `<div class="empty-state"><span class="big-emoji">🌱</span>No tasks yet. Add the first one to see your timeline.<br><br>
         <button class="btn primary" id="firstTask">+ Add first task</button></div>`;
      document.getElementById('firstTask').addEventListener('click', () => editTask(null, p));
      return;
    }

    Gantt.render(document.getElementById('ganttHost'), {
      tasks, deps, cpm, project: p, people: S.people, zoom: ganttZoom,
      onTaskClick: (t) => editTask(t, p),
      onTaskChange: (t, dates) => mutate('PUT', `/api/tasks/${t.id}`, dates),
      onAddTask: () => editTask(null, p),
      onReorder: (ids) => mutate('POST', '/api/tasks/reorder', { ids }),
    });
  }

  function editTask(t, project) {
    const isNew = !t;
    const today = D.today();
    t = t || { name: '', start: today, end: D.add(today, 2), done: 0, milestone: 0,
      person_id: null, notes: '', project_id: project.id };
    const others = projTasks(project.id).filter(x => x.id !== t.id);
    const myDeps = S.deps.filter(d => d.succ_id === t.id);
    const cpm = isNew ? null : cpmFor(project.id).get(t.id);

    openPanel(`
      <h3>${isNew ? 'New task' : 'Edit task'}</h3>
      ${cpm && cpm.critical ? '<span class="pill critical">on the critical path</span>' :
        (cpm ? `<span class="pill neutral">${cpm.slack}d slack</span>` : '')}
      <label>Name</label><input id="t_name" value="${esc(t.name)}" placeholder="What needs doing?">
      <div class="field-row">
        <div><label>Start</label><input id="t_start" type="date" value="${t.start}"></div>
        <div><label>End</label><input id="t_end" type="date" value="${t.end}"></div>
      </div>
      <label>Assigned to</label>
      <select id="t_person">
        <option value="">Nobody yet</option>
        ${S.people.map(pp => `<option value="${pp.id}" ${pp.id === t.person_id ? 'selected' : ''}>${esc(pp.name)}</option>`).join('')}
      </select>
      <label style="display:flex;align-items:center;gap:8px;margin-top:16px">
        <input type="checkbox" id="t_milestone" style="width:auto" ${t.milestone ? 'checked' : ''}> Milestone (single date)
      </label>
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" id="t_done" style="width:auto" ${t.done ? 'checked' : ''}> Done
      </label>
      ${!isNew ? `
        <label>Depends on (must finish first)</label>
        <div id="depList">${myDeps.map(d => {
          const pred = S.tasks.find(x => x.id === d.pred_id);
          return pred ? `<div class="dep-row"><span class="grow">${esc(pred.name)}</span><button data-deldep="${d.id}" title="Remove">✕</button></div>` : '';
        }).join('')}</div>
        <select id="t_adddep">
          <option value="">+ Add a dependency…</option>
          ${others.filter(o => !myDeps.some(d => d.pred_id === o.id))
            .map(o => `<option value="${o.id}">${esc(o.name)}</option>`).join('')}
        </select>` : ''}
      <label>Notes</label><textarea id="t_notes" rows="3">${esc(t.notes)}</textarea>
      <div class="panel-actions">
        <button class="btn primary grow" id="t_save">${isNew ? 'Add task' : 'Save'}</button>
        ${!isNew ? '<button class="btn danger" id="t_del">Delete</button>' : ''}
        <button class="btn" id="t_cancel">Cancel</button>
      </div>`);

    panel.querySelector('#t_cancel').addEventListener('click', closePanel);
    // milestone keeps end = start
    const startI = panel.querySelector('#t_start'), endI = panel.querySelector('#t_end');
    const msI = panel.querySelector('#t_milestone');
    function syncMilestone() { if (msI.checked) endI.value = startI.value; endI.disabled = msI.checked; }
    msI.addEventListener('change', syncMilestone);
    startI.addEventListener('change', () => { if (msI.checked) endI.value = startI.value; });
    syncMilestone();

    panel.querySelector('#t_save').addEventListener('click', async () => {
      const name = panel.querySelector('#t_name').value.trim();
      if (!name) return;
      let start = startI.value, end = msI.checked ? startI.value : endI.value;
      if (end < start) end = start;
      const body = { project_id: project.id, name, start, end,
        done: panel.querySelector('#t_done').checked ? 1 : 0,
        milestone: msI.checked ? 1 : 0,
        person_id: Number(panel.querySelector('#t_person').value) || null,
        notes: panel.querySelector('#t_notes').value };
      closePanel();
      if (isNew) await mutate('POST', '/api/tasks', body);
      else await mutate('PUT', `/api/tasks/${t.id}`, body);
    });
    if (!isNew) {
      panel.querySelector('#t_del').addEventListener('click', async () => {
        if (!confirm(`Delete "${t.name}"?`)) return;
        closePanel();
        await mutate('DELETE', `/api/tasks/${t.id}`);
      });
      panel.querySelectorAll('[data-deldep]').forEach(b => b.addEventListener('click', async () => {
        closePanel();
        await mutate('DELETE', `/api/deps/${b.dataset.deldep}`);
        editTask(S.tasks.find(x => x.id === t.id), project);
      }));
      panel.querySelector('#t_adddep').addEventListener('change', async (e) => {
        const predId = Number(e.target.value);
        if (!predId) return;
        closePanel();
        await mutate('POST', '/api/deps', { project_id: project.id, pred_id: predId, succ_id: t.id });
        editTask(S.tasks.find(x => x.id === t.id), project);
      });
    }
  }

  // ----- People / workload -----
  function renderPeople() {
    setNav('people');
    const today = D.today();
    const DAYS = 14;
    const dates = Array.from({ length: DAYS }, (_, i) => D.add(today, i));
    const openTasks = S.tasks.filter(t => {
      const p = projById(t.project_id);
      return !t.done && p && !p.archived;
    });

    const rows = S.people.map(p => {
      const mine = openTasks.filter(t => t.person_id === p.id);
      const cells = dates.map(d => {
        const n = mine.filter(t => t.start <= d && t.end >= d && !t.milestone).length;
        const lvl = n === 0 ? '' : n === 1 ? 'l1' : n === 2 ? 'l2' : 'l3';
        return `<div class="heat-cell ${lvl}" title="${D.humanFull(d)}: ${n} task${n === 1 ? '' : 's'}">${n > 1 ? n : ''}</div>`;
      }).join('');
      const busiest = Math.max(0, ...dates.map(d => mine.filter(t => t.start <= d && t.end >= d && !t.milestone).length));
      return `<div class="person-row" data-person="${p.id}" style="cursor:pointer">
        <div class="avatar" style="background:${p.color}">${esc(p.name.split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase())}</div>
        <div class="p-name">${esc(p.name)}<div class="muted" style="font-size:12px;font-weight:400">${mine.length} open task${mine.length === 1 ? '' : 's'}${busiest > 2 ? ' · <span style="color:var(--critical)">quite full</span>' : ''}</div></div>
        <div class="heat-strip">${cells}</div>
      </div>`;
    }).join('');

    view.innerHTML = `
      <h1>People &amp; workload</h1>
      <p class="subtitle">Who's carrying what over the next two weeks. Deeper colour = more on that day.</p>
      <div style="display:flex;gap:14px;margin-bottom:6px">
        <div style="width:48px"></div><div style="width:140px"></div>
        <div class="heat-labels">${dates.map(d => `<span>${D.human(d).split(' ')[0]}</span>`).join('')}</div>
      </div>
      ${rows || '<div class="empty-state"><span class="big-emoji">👋</span>No people yet — add yourself first.</div>'}
      <div style="margin-top:18px"><button class="btn primary" id="addPerson">+ Add person</button></div>`;

    document.getElementById('addPerson').addEventListener('click', () => editPerson(null));
    view.querySelectorAll('[data-person]').forEach(r =>
      r.addEventListener('click', () => editPerson(S.people.find(p => p.id === Number(r.dataset.person)))));
  }

  function editPerson(p) {
    const isNew = !p;
    p = p || { name: '', color: PEOPLE_COLORS[S.people.length % PEOPLE_COLORS.length] };
    openPanel(`
      <h3>${isNew ? 'Add person' : 'Edit person'}</h3>
      <label>Name</label><input id="p_name" value="${esc(p.name)}" placeholder="e.g. Damon">
      <label>Colour</label>
      <div class="color-swatches">${PEOPLE_COLORS.map(c =>
        `<div class="swatch ${c === p.color ? 'sel' : ''}" data-c="${c}" style="background:${c}"></div>`).join('')}</div>
      <div class="panel-actions">
        <button class="btn primary grow" id="p_save">${isNew ? 'Add' : 'Save'}</button>
        ${!isNew ? '<button class="btn danger" id="p_del">Remove</button>' : ''}
        <button class="btn" id="p_cancel">Cancel</button>
      </div>`);
    let color = p.color;
    panel.querySelectorAll('.swatch').forEach(s => s.addEventListener('click', () => {
      color = s.dataset.c;
      panel.querySelectorAll('.swatch').forEach(x => x.classList.toggle('sel', x === s));
    }));
    panel.querySelector('#p_cancel').addEventListener('click', closePanel);
    panel.querySelector('#p_save').addEventListener('click', async () => {
      const name = panel.querySelector('#p_name').value.trim();
      if (!name) return;
      closePanel();
      if (isNew) await mutate('POST', '/api/people', { name, color });
      else await mutate('PUT', `/api/people/${p.id}`, { name, color });
    });
    if (!isNew) panel.querySelector('#p_del').addEventListener('click', async () => {
      if (!confirm(`Remove ${p.name}? Their tasks stay, just unassigned.`)) return;
      closePanel();
      await mutate('DELETE', `/api/people/${p.id}`);
    });
  }

  // ---------- router ----------
  function route() {
    const h = location.hash || '#/today';
    const m = h.match(/^#\/project\/(\d+)/);
    if (m) return renderProject(Number(m[1]));
    if (h.startsWith('#/projects')) return renderProjects();
    if (h.startsWith('#/people')) return renderPeople();
    return renderToday();
  }
  window.addEventListener('hashchange', route);

  // ---------- theme ----------
  const savedTheme = localStorage.getItem('cp_theme');
  if (savedTheme) document.documentElement.dataset.theme = savedTheme;
  else if (matchMedia('(prefers-color-scheme: dark)').matches)
    document.documentElement.dataset.theme = 'dark';
  document.getElementById('themeToggle').addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    document.documentElement.dataset.theme = next;
    localStorage.setItem('cp_theme', next);
    route();
  });

  // ---------- boot ----------
  reload().then(route).catch(err => {
    view.innerHTML = `<div class="empty-state">Couldn't load your data — is the server running?<br><span class="muted">${esc(err.message)}</span></div>`;
  });
})();
