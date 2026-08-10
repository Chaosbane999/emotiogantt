// ClearPath app — state, routing, views
(function () {
  const { D, computeCPM, projectHealth } = window.CPM;
  const view = document.getElementById('view');
  const panel = document.getElementById('panel');
  const scrim = document.getElementById('scrim');

  let S = { projects: [], people: [], tasks: [], deps: [], snapshots: [] };
  let ganttZoom = localStorage.getItem('cp_zoom') || 'day';
  const snapCache = new Map(); // snapshot id -> task list

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
  // "leaf" tasks are real work; a task with children is a phase (summary row)
  const parentIds = () => new Set(S.tasks.filter(t => t.parent_id).map(t => t.parent_id));
  const isLeaf = (t) => !parentIds().has(t.id);
  const projLeafTasks = (pid) => { const ps = parentIds(); return projTasks(pid).filter(t => !ps.has(t.id)); };
  const cpmFor = (pid) => computeCPM(projLeafTasks(pid), projDeps(pid));
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

  // small confirmation toast (popups like prompt/confirm are blocked in some browsers)
  function toast(msg) {
    let t = document.getElementById('toast');
    if (!t) {
      t = document.createElement('div');
      t.id = 'toast'; t.className = 'toast';
      t.setAttribute('role', 'status');
      document.body.appendChild(t);
    }
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => t.classList.remove('show'), 3500);
  }

  // gentle two-step delete: first click arms the button, second click confirms
  function armDelete(btn, fn) {
    btn.addEventListener('click', () => {
      if (btn.dataset.armed) { fn(); return; }
      btn.dataset.armed = '1';
      const orig = btn.textContent;
      btn.textContent = 'Click again to confirm';
      setTimeout(() => { delete btn.dataset.armed; btn.textContent = orig; }, 3000);
    });
  }

  // shared person filter: tidy dropdown with checkboxes inside (pick one or many).
  // Stores EXCLUDED ids so newly added people default to shown.
  let pdropOpenKey = null; // menu stays open across re-renders while ticking
  document.addEventListener('click', (e) => {
    if (!e.target.isConnected) return;
    if (![...document.querySelectorAll('.pdrop')].some(r => r.contains(e.target))) {
      document.querySelectorAll('.pdrop-menu').forEach(m => m.classList.add('hidden'));
      pdropOpenKey = null;
    }
  });
  function chipFilter(key, { includeUnassigned = false } = {}) {
    const excluded = new Set(JSON.parse(localStorage.getItem(key) || '[]'));
    const shown = S.people.filter(pp => !excluded.has(pp.id));
    let label = 'Everyone';
    if (excluded.size) {
      const names = shown.map(p => p.name);
      if (includeUnassigned && !excluded.has('u')) names.push('Unassigned');
      label = !names.length ? 'Nobody' : names.length <= 2 ? names.join(' + ') : `${names.length} selected`;
    }
    const item = (val, dotColor, text, bold) => `
      <label class="pdrop-item">
        <input type="checkbox" data-pd="${val}" ${
          val === 'all' ? (excluded.size === 0 ? 'checked' : '') : (excluded.has(val) ? '' : 'checked')}>
        ${dotColor ? `<span class="dot" style="background:${dotColor}"></span>` : ''}
        ${bold ? `<strong>${text}</strong>` : text}
      </label>`;
    const html = S.people.length ? `
      <div class="pdrop" data-pdrop="${key}">
        <button class="btn small pdrop-btn" title="Choose whose work to show">👥 ${esc(label)} ▾</button>
        <div class="pdrop-menu ${pdropOpenKey === key ? '' : 'hidden'}">
          ${item('all', '', 'Everyone', true)}
          ${S.people.map(pp => item(pp.id, pp.color, esc(pp.name))).join('')}
          ${includeUnassigned ? item('u', 'var(--ink-soft)', 'Unassigned') : ''}
        </div>
      </div>` : '';
    const wire = () => {
      const root = view.querySelector(`[data-pdrop="${CSS.escape(key)}"]`);
      if (!root) return;
      const menu = root.querySelector('.pdrop-menu');
      root.querySelector('.pdrop-btn').addEventListener('click', (e) => {
        e.stopPropagation();
        const opening = menu.classList.contains('hidden');
        menu.classList.toggle('hidden', !opening);
        pdropOpenKey = opening ? key : null;
      });
      root.querySelectorAll('[data-pd]').forEach(cb => cb.addEventListener('change', () => {
        if (cb.dataset.pd === 'all') {
          localStorage.removeItem(key);
        } else {
          const id = cb.dataset.pd === 'u' ? 'u' : Number(cb.dataset.pd);
          if (excluded.has(id)) excluded.delete(id); else excluded.add(id);
          localStorage.setItem(key, JSON.stringify([...excluded]));
        }
        pdropOpenKey = key;
        route();
      }));
    };
    const showsTask = (t) =>
      t.person_id ? !excluded.has(t.person_id) : !excluded.has('u');
    return { excluded, html, wire, showsTask, shown };
  }

  // ---------- views ----------
  function setNav(name) {
    document.querySelectorAll('[data-nav]').forEach(a =>
      a.classList.toggle('active', a.dataset.nav === name));
  }

  // ----- Today -----
  function renderToday() {
    setNav('today');
    const today = D.today();
    const filter = chipFilter('cp_td_excl', { includeUnassigned: true });
    const cpms = new Map(S.projects.map(p => [p.id, cpmFor(p.id)]));
    const ps = parentIds();
    const open = S.tasks.filter(t => {
      const p = projById(t.project_id);
      return !t.done && !ps.has(t.id) && p && !p.archived && filter.showsTask(t);
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
      const dur = D.diff(t.start, t.end) + 1;
      const inc = Math.ceil(100 / dur);
      const prog = t.progress || 0;
      return `<div class="focus-item ${crit ? 'critical' : ''}">
        <input type="checkbox" data-done="${t.id}" title="Tick off today's slice (+${inc}%)">
        <div class="grow">
          <div class="t-name">${esc(t.name)} ${crit ? '<span class="pill critical">critical path</span>' : ''}</div>
          <div class="t-sub"><span class="dot" style="background:${proj.color};display:inline-block;margin-right:5px"></span>${esc(proj.name)}${who ? ' · ' + esc(who) : ''}${prog > 0 ? ` · ${prog}% done` : ''}</div>
        </div>
        ${countdownChip(t.end, t.done)}
      </div>`;
    };

    view.innerHTML = `
      <h1>Today</h1>
      <p class="subtitle">${new Date().toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' })}</p>
      ${filter.html}
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
    filter.wire();
    view.querySelectorAll('[data-done]').forEach(cb =>
      cb.addEventListener('change', () => {
        const t = S.tasks.find(x => x.id === Number(cb.dataset.done));
        const dur = D.diff(t.start, t.end) + 1;
        const progress = Math.min(100, (t.progress || 0) + Math.ceil(100 / dur));
        mutate('PUT', `/api/tasks/${t.id}`, { progress });
      }));
  }

  // ----- Projects dashboard -----
  function renderProjects() {
    setNav('projects');
    const active = S.projects.filter(p => !p.archived);
    const cards = active.map(p => {
      const tasks = projLeafTasks(p.id);
      const cpm = cpmFor(p.id);
      const health = projectHealth(p, tasks, cpm);
      const doneCount = tasks.filter(t => t.done).length;
      // progress-weighted by task length, so a half-done long task counts fairly
      const totalDays = tasks.reduce((a, t) => a + D.diff(t.start, t.end) + 1, 0);
      const doneDays = tasks.reduce((a, t) =>
        a + (D.diff(t.start, t.end) + 1) * (t.done ? 100 : (t.progress || 0)) / 100, 0);
      const pct = totalDays ? Math.round(doneDays / totalDays * 100) : 0;
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
      <div class="chip-row">
        <button class="btn small" id="importCsv">⬆ Import CSV</button>
        <button class="btn small" id="aiSetup">✨ Set up with AI</button>
      </div>
      <div class="cards-grid">
        ${cards}
        <div class="card new-card" id="newProject">+ New project</div>
      </div>`;
    view.querySelectorAll('[data-open]').forEach(c =>
      c.addEventListener('click', () => { location.hash = `#/project/${c.dataset.open}`; }));
    document.getElementById('newProject').addEventListener('click', () => editProject(null));
    document.getElementById('importCsv').addEventListener('click', importDialog);
    document.getElementById('aiSetup').addEventListener('click', aiDialog);
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
    if (!isNew) armDelete(panel.querySelector('#f_del'), async () => {
      closePanel();
      location.hash = '#/projects';
      await mutate('DELETE', `/api/projects/${p.id}`);
      toast(`Project "${p.name}" deleted.`);
    });
  }

  // ----- Project detail (Gantt) -----
  async function renderProject(pid) {
    setNav('projects');
    const p = projById(pid);
    if (!p) { location.hash = '#/projects'; return; }
    const allTasks = projTasks(pid);
    const deps = projDeps(pid);
    const cpm = cpmFor(pid);
    const health = projectHealth(p, projLeafTasks(pid), cpm);
    const mySnaps = S.snapshots.filter(s => s.project_id === pid);

    // person filter (dropdown with checkboxes — show one or many)
    const filter = chipFilter(`cp_pfchips_${pid}`, { includeUnassigned: true });

    // build the display list: roots in order, children under their phase,
    // collapsed phases hide their children but keep the summary bar
    const collKey = `cp_coll_${pid}`;
    const collapsed = new Set(JSON.parse(localStorage.getItem(collKey) || '[]'));
    const kidsBy = new Map();
    for (const t of allTasks) {
      if (!t.parent_id) continue;
      if (!kidsBy.has(t.parent_id)) kidsBy.set(t.parent_id, []);
      kidsBy.get(t.parent_id).push(t);
    }
    const tasks = [];
    for (const t of allTasks.filter(t => !t.parent_id)) {
      const kids = kidsBy.get(t.id) || [];
      if (kids.length) {
        const visKids = kids.filter(filter.showsTask);
        if (!visKids.length) continue;
        tasks.push({ ...t, _kind: 'parent', _collapsed: collapsed.has(t.id),
          _span: {
            start: kids.reduce((a, k) => k.start < a ? k.start : a, kids[0].start),
            end: kids.reduce((a, k) => k.end > a ? k.end : a, kids[0].end),
          },
          _crit: visKids.some(k => cpm.get(k.id)?.critical) });
        if (!collapsed.has(t.id)) tasks.push(...visKids.map(k => ({ ...k, _kind: 'child' })));
      } else if (filter.showsTask(t)) {
        tasks.push({ ...t, _kind: 'leaf' });
      }
    }

    // baseline snapshot to compare against
    const snapKey = `cp_snap_${pid}`;
    let snapId = Number(localStorage.getItem(snapKey)) || 0;
    if (snapId && !mySnaps.some(s => s.id === snapId)) {
      snapId = 0; localStorage.removeItem(snapKey);
    }
    let baseline = null, snapName = '';
    if (snapId) {
      if (!snapCache.has(snapId)) {
        const snap = await api('GET', `/api/snapshots/${snapId}`);
        snapCache.set(snapId, snap.data);
      }
      baseline = new Map(snapCache.get(snapId).map(t => [t.id, t]));
      snapName = (mySnaps.find(s => s.id === snapId) || {}).name || 'snapshot';
    }

    view.innerHTML = `
      <div class="gantt-toolbar">
        <a href="#/projects" class="btn small">← All projects</a>
        <h1 style="margin:0;display:flex;align-items:center;gap:10px">
          <span class="dot" style="background:${p.color}"></span>${esc(p.name)}
        </h1>
        <span class="pill ${health}">${HEALTH_LABEL[health]}</span>
        <div class="spacer"></div>
        ${filter.html}
        <select id="snapSel" class="toolbar-select" title="Compare against a saved plan">
          <option value="0">No comparison</option>
          ${mySnaps.map(s =>
            `<option value="${s.id}" ${s.id === snapId ? 'selected' : ''}>vs ${esc(s.name)}</option>`).join('')}
        </select>
        <button class="btn small" id="saveSnap" title="Save today's plan so you can compare later">📸 Snapshot</button>
        <div class="zoom-toggle">
          <button data-z="day" class="${ganttZoom === 'day' ? 'active' : ''}">Day</button>
          <button data-z="week" class="${ganttZoom === 'week' ? 'active' : ''}">Week</button>
        </div>
        <button class="btn small" id="editProj">Settings</button>
      </div>
      <div id="ganttHost"></div>
      <p class="muted" style="font-size:13px;margin-top:14px">
        <span style="color:var(--critical)">■</span> Critical path — a delay here delays the whole project.
        ${baseline ? `<span style="color:var(--ink-soft)">▬</span> Grey line under a bar = where it sat in "${esc(snapName)}".` : ''}
        ${filter.excluded.size ? 'Showing only the ticked people’s tasks. ' : ''}
        Drag bars to reschedule; drag edges to change length; drag the list to reorder; click a task to edit.
      </p>`;
    filter.wire();

    view.querySelectorAll('[data-z]').forEach(b => b.addEventListener('click', () => {
      ganttZoom = b.dataset.z; localStorage.setItem('cp_zoom', ganttZoom); route();
    }));
    view.querySelector('#editProj').addEventListener('click', () => editProject(p));
    view.querySelector('#snapSel').addEventListener('change', (e) => {
      const v = Number(e.target.value);
      if (v) localStorage.setItem(snapKey, v); else localStorage.removeItem(snapKey);
      route();
    });
    view.querySelector('#saveSnap').addEventListener('click', () => snapshotDialog(p));

    if (!allTasks.length) {
      document.getElementById('ganttHost').innerHTML =
        `<div class="empty-state"><span class="big-emoji">🌱</span>No tasks yet. Add the first one to see your timeline.<br><br>
         <button class="btn primary" id="firstTask">+ Add first task</button></div>`;
      document.getElementById('firstTask').addEventListener('click', () => editTask(null, p));
      return;
    }
    if (!tasks.length) {
      document.getElementById('ganttHost').innerHTML =
        `<div class="empty-state"><span class="big-emoji">🔍</span>Nobody ticked has tasks in this project.</div>`;
      return;
    }

    Gantt.render(document.getElementById('ganttHost'), {
      tasks, deps, cpm, project: p, people: S.people, zoom: ganttZoom, baseline,
      onTaskClick: (t) => editTask(allTasks.find(x => x.id === t.id) || t, p),
      onTaskChange: (t, dates) => mutate('PUT', `/api/tasks/${t.id}`, dates),
      onAddTask: () => editTask(null, p),
      onToggleCollapse: (t) => {
        const c = new Set(JSON.parse(localStorage.getItem(collKey) || '[]'));
        if (c.has(t.id)) c.delete(t.id); else c.add(t.id);
        localStorage.setItem(collKey, JSON.stringify([...c]));
        route();
      },
      onReorder: (ids) => {
        // translate the dragged display order into a sane hierarchy order:
        // roots keep block structure, children stay within their own phase
        const byId = new Map(allTasks.map(t => [t.id, t]));
        const rootOrder = ids.filter(id => byId.get(id) && !byId.get(id).parent_id);
        for (const t of allTasks.filter(t => !t.parent_id))
          if (!rootOrder.includes(t.id)) rootOrder.push(t.id);
        const childOrder = new Map();
        for (const id of ids) {
          const t = byId.get(id);
          if (t && t.parent_id) {
            if (!childOrder.has(t.parent_id)) childOrder.set(t.parent_id, []);
            childOrder.get(t.parent_id).push(id);
          }
        }
        const flat = [];
        for (const rid of rootOrder) {
          flat.push(rid);
          const kidIds = allTasks.filter(t => t.parent_id === rid).map(t => t.id);
          const ordered = (childOrder.get(rid) || []).filter(id => kidIds.includes(id));
          for (const k of kidIds) if (!ordered.includes(k)) ordered.push(k);
          flat.push(...ordered);
        }
        mutate('POST', '/api/tasks/reorder', { ids: flat });
      },
    });
  }

  function snapshotDialog(p) {
    const mySnaps = S.snapshots.filter(s => s.project_id === p.id);
    openPanel(`
      <h3>Save a snapshot</h3>
      <p class="muted" style="font-size:13px;margin:6px 0 0">
        A snapshot freezes today's plan. Later, pick it in the "vs" menu to see
        how far things have drifted from it.</p>
      <label>Name</label><input id="s_name" value="Plan ${D.human(D.today())}">
      <div class="panel-actions">
        <button class="btn primary grow" id="s_save">Save snapshot</button>
        <button class="btn" id="s_cancel">Cancel</button>
      </div>
      ${mySnaps.length ? `
        <h3 style="margin-top:30px;font-size:15px">Saved snapshots</h3>
        ${mySnaps.map(s => `<div class="dep-row">
          <span class="grow">${esc(s.name)} · ${D.human(s.created_at)}</span>
          <button data-delsnap="${s.id}" title="Delete snapshot">✕</button>
        </div>`).join('')}` : ''}`);
    panel.querySelector('#s_cancel').addEventListener('click', closePanel);
    panel.querySelector('#s_save').addEventListener('click', async () => {
      const name = panel.querySelector('#s_name').value.trim() || 'Snapshot';
      closePanel();
      await mutate('POST', '/api/snapshots', { project_id: p.id, name });
      toast(`Snapshot "${name}" saved — pick it in the "vs" menu to compare later.`);
    });
    panel.querySelectorAll('[data-delsnap]').forEach(b =>
      b.addEventListener('click', async () => {
        closePanel();
        await mutate('DELETE', `/api/snapshots/${b.dataset.delsnap}`);
        toast('Snapshot deleted.');
      }));
  }

  // ----- Timeline (all projects overlapping) -----
  function renderTimeline() {
    setNav('timeline');
    const active = S.projects.filter(p => !p.archived);
    const filter = chipFilter('cp_tl_excl');
    const shown = filter.shown;

    view.innerHTML = `
      <h1>Timeline</h1>
      <p class="subtitle">All projects side by side, with a lane for each person's work.
        <span style="color:var(--critical);font-weight:600">Coral</span> = they're booked on two projects at once.
        Amber line = due date.</p>
      ${filter.html}
      <div id="portfolioHost"></div>`;
    filter.wire();

    if (!active.length) {
      document.getElementById('portfolioHost').innerHTML =
        '<div class="empty-state"><span class="big-emoji">🗺️</span>No projects yet.</div>';
      return;
    }
    const tasksByProject = new Map(active.map(p => [p.id, projLeafTasks(p.id)]));
    const healthByProject = new Map(active.map(p =>
      [p.id, projectHealth(p, projLeafTasks(p.id), cpmFor(p.id))]));
    Gantt.renderPortfolio(document.getElementById('portfolioHost'), {
      projects: active, tasksByProject, healthByProject, people: shown,
      onOpen: (p) => { location.hash = `#/project/${p.id}`; },
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
      ${S.tasks.some(x => x.parent_id === t.id) ? '' : `
      <label>Part of (phase)</label>
      <select id="t_parent">
        <option value="">Not part of a phase</option>
        ${projTasks(project.id).filter(x => !x.parent_id && x.id !== t.id && !x.milestone)
          .map(x => `<option value="${x.id}" ${x.id === t.parent_id ? 'selected' : ''}>${esc(x.name)}</option>`).join('')}
      </select>`}
      <label style="display:flex;align-items:center;gap:8px;margin-top:16px">
        <input type="checkbox" id="t_milestone" style="width:auto" ${t.milestone ? 'checked' : ''}> Milestone (single date)
      </label>
      <label style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" id="t_done" style="width:auto" ${t.done ? 'checked' : ''}> Done
      </label>
      <div id="progRow">
        <label>Progress <span id="t_progpct" style="font-weight:400">${t.done ? 100 : (t.progress || 0)}%</span></label>
        <input type="range" id="t_progress" min="0" max="100" step="5"
          value="${t.done ? 100 : (t.progress || 0)}" style="accent-color:var(--accent);padding:0">
      </div>
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
    const progI = panel.querySelector('#t_progress');
    const progPct = panel.querySelector('#t_progpct');
    const doneI = panel.querySelector('#t_done');
    function syncMilestone() {
      if (msI.checked) endI.value = startI.value;
      endI.disabled = msI.checked;
      panel.querySelector('#progRow').style.display = msI.checked ? 'none' : '';
    }
    msI.addEventListener('change', syncMilestone);
    startI.addEventListener('change', () => { if (msI.checked) endI.value = startI.value; });
    syncMilestone();
    progI.addEventListener('input', () => {
      progPct.textContent = progI.value + '%';
      doneI.checked = Number(progI.value) >= 100;
    });
    doneI.addEventListener('change', () => {
      progI.value = doneI.checked ? 100 : (Number(progI.value) >= 100 ? 0 : progI.value);
      progPct.textContent = progI.value + '%';
    });

    panel.querySelector('#t_save').addEventListener('click', async () => {
      const name = panel.querySelector('#t_name').value.trim();
      if (!name) return;
      let start = startI.value, end = msI.checked ? startI.value : endI.value;
      if (end < start) end = start;
      const parentSel = panel.querySelector('#t_parent');
      const body = { project_id: project.id, name, start, end,
        parent_id: parentSel ? (Number(parentSel.value) || null) : (t.parent_id || null),
        progress: msI.checked ? (doneI.checked ? 100 : 0) : Number(progI.value),
        done: doneI.checked ? 1 : 0,
        milestone: msI.checked ? 1 : 0,
        person_id: Number(panel.querySelector('#t_person').value) || null,
        notes: panel.querySelector('#t_notes').value };
      closePanel();
      if (isNew) await mutate('POST', '/api/tasks', body);
      else await mutate('PUT', `/api/tasks/${t.id}`, body);
    });
    if (!isNew) {
      armDelete(panel.querySelector('#t_del'), async () => {
        closePanel();
        await mutate('DELETE', `/api/tasks/${t.id}`);
        toast(`Task "${t.name}" deleted.`);
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
    const ps = parentIds();
    const openTasks = S.tasks.filter(t => {
      const p = projById(t.project_id);
      return !t.done && !ps.has(t.id) && p && !p.archived;
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
    if (!isNew) armDelete(panel.querySelector('#p_del'), async () => {
      closePanel();
      await mutate('DELETE', `/api/people/${p.id}`);
      toast(`${p.name} removed — their tasks are now unassigned.`);
    });
  }

  // ---------- project import (CSV + AI) ----------
  const CSV_HEADER = 'Task Name,Start,End,Person,Milestone,Depends On,Notes,Phase';
  const CSV_TEMPLATE = `${CSV_HEADER}
Discovery,2026-08-17,2026-08-21,,no,,Phase row — subtasks reference it in the Phase column,
Kickoff meeting,2026-08-17,2026-08-17,Damon,yes,,Align on scope,Discovery
Research,2026-08-18,2026-08-21,Damon,no,Kickoff meeting,,Discovery
Design,2026-08-24,2026-08-28,,no,Research,Two concepts to review,
Build,2026-08-31,2026-09-11,,no,Design,,
Review & sign-off,2026-09-14,2026-09-15,Damon,no,Build,,
Launch,2026-09-16,2026-09-16,,yes,Review & sign-off,,`;
  const GPT_PROMPT = `Create a CSV project plan I can import into my Gantt tool. Output ONLY CSV (no prose, no code fences) with this exact header row:
${CSV_HEADER}
Rules:
- Dates are YYYY-MM-DD. Milestone is yes/no; milestones are single-day (Start = End) gates like "Sign-off" or "Launch".
- "Depends On" lists the exact Task Names (semicolon-separated) that must finish before this task can start — chain these properly for sequential work so the critical path is meaningful; leave parallel work unchained.
- A task's Start must be at least the day after everything it depends on ends.
- Optional phases: add a row for the phase itself (dates spanning its subtasks, no dependencies), then put that phase's exact name in the Phase column of its subtasks. Milestones can live inside phases too. One level only.
- Unique task names. Person can be blank.
The project is: [describe your project here]`;

  function parseCSV(text) {
    const rows = []; let row = [], field = '', inQ = false;
    for (let i = 0; i < text.length; i++) {
      const ch = text[i];
      if (inQ) {
        if (ch === '"') { if (text[i + 1] === '"') { field += '"'; i++; } else inQ = false; }
        else field += ch;
      } else if (ch === '"') inQ = true;
      else if (ch === ',') { row.push(field); field = ''; }
      else if (ch === '\n' || ch === '\r') {
        if (ch === '\r' && text[i + 1] === '\n') i++;
        row.push(field); field = '';
        if (row.some(f => f.trim() !== '')) rows.push(row);
        row = [];
      } else field += ch;
    }
    row.push(field);
    if (row.some(f => f.trim() !== '')) rows.push(row);
    return rows;
  }

  function csvToTasks(rows) {
    const head = rows[0].map(h => h.trim().toLowerCase());
    const col = (...names) => head.findIndex(h => names.some(n => h.startsWith(n)));
    const ci = { name: col('task', 'name'), start: col('start'), end: col('end', 'finish'),
      person: col('person', 'who', 'assigned'), milestone: col('milestone'),
      deps: col('depends', 'after'), notes: col('note'), parent: col('phase', 'parent') };
    const cell = (r, i) => (i >= 0 && r[i] !== undefined ? r[i].trim() : '');
    return rows.slice(1).map(r => ({
      name: cell(r, ci.name),
      start: cell(r, ci.start),
      end: cell(r, ci.end) || cell(r, ci.start),
      person: cell(r, ci.person) || null,
      milestone: /^(y|yes|true|1)$/i.test(cell(r, ci.milestone)),
      depends_on: cell(r, ci.deps).split(/[;|]/).map(s => s.trim()).filter(Boolean),
      notes: cell(r, ci.notes) || null,
      parent: cell(r, ci.parent) || null,
    })).filter(t => t.name);
  }

  function planIssues(plan) {
    const issues = [];
    const names = new Set(plan.tasks.map(t => t.name.toLowerCase()));
    const dateRe = /^\d{4}-\d{2}-\d{2}$/;
    for (const t of plan.tasks) {
      if (!dateRe.test(t.start) || !dateRe.test(t.end))
        issues.push(`"${t.name}": bad or missing date (needs YYYY-MM-DD) — will be skipped`);
      for (const d of t.depends_on || [])
        if (!names.has(d.toLowerCase()))
          issues.push(`"${t.name}" depends on unknown task "${d}" — that link will be skipped`);
      if (t.parent && !names.has(t.parent.toLowerCase()))
        issues.push(`"${t.name}" is in unknown phase "${t.parent}" — it will sit at the top level`);
    }
    return issues;
  }

  function planPreviewHTML(plan) {
    const issues = planIssues(plan);
    return `
      <h3 style="margin-top:22px;font-size:15px">${esc(plan.name)} — ${plan.tasks.length} tasks${plan.due_date ? ` · due ${D.human(plan.due_date)}` : ''}</h3>
      ${issues.length ? `<div class="dep-row" style="color:var(--watch);display:block">${issues.map(esc).join('<br>')}</div>` : ''}
      <div style="max-height:300px;overflow-y:auto">
      ${plan.tasks.map(t => `<div class="dep-row" style="display:block">
        <strong>${esc(t.name)}</strong>${t.milestone ? ' <span class="pill neutral">milestone</span>' : ''}
        ${t.notes ? ' ✎' : ''}<br>
        <span class="muted" style="font-size:12px">${esc(t.start)}${t.end !== t.start ? ' → ' + esc(t.end) : ''}${t.person ? ' · ' + esc(t.person) : ''}${t.parent ? ' · in ' + esc(t.parent) : ''}${(t.depends_on || []).length ? ' · after: ' + t.depends_on.map(esc).join(', ') : ''}</span>
      </div>`).join('')}</div>`;
  }

  async function createProjectFromPlan(plan, color) {
    const dateRe = /^\d{4}-\d{2}-\d{2}$/;
    const tasks = plan.tasks.filter(t => dateRe.test(t.start) && dateRe.test(t.end));
    // people: match existing by name (case-insensitive), create the rest
    const peopleMap = new Map(S.people.map(pp => [pp.name.toLowerCase(), pp.id]));
    for (const nm of [...new Set(tasks.map(t => (t.person || '').trim()).filter(Boolean))]) {
      if (!peopleMap.has(nm.toLowerCase())) {
        const r = await api('POST', '/api/people',
          { name: nm, color: PEOPLE_COLORS[peopleMap.size % PEOPLE_COLORS.length] });
        peopleMap.set(nm.toLowerCase(), r.id);
      }
    }
    const pr = await api('POST', '/api/projects',
      { name: plan.name, color, due_date: plan.due_date || null, notes: '' });
    const idByName = new Map();
    for (const t of tasks) {
      let end = t.milestone ? t.start : t.end;
      if (end < t.start) end = t.start;
      const r = await api('POST', '/api/tasks', {
        project_id: pr.id, name: t.name, start: t.start, end,
        milestone: t.milestone ? 1 : 0,
        person_id: t.person ? peopleMap.get(t.person.trim().toLowerCase()) || null : null,
        notes: t.notes || '' });
      idByName.set(t.name.toLowerCase(), r.id);
    }
    for (const t of tasks) {
      for (const dep of (t.depends_on || [])) {
        const predId = idByName.get(String(dep).toLowerCase());
        if (predId) await api('POST', '/api/deps',
          { project_id: pr.id, pred_id: predId, succ_id: idByName.get(t.name.toLowerCase()) });
      }
      if (t.parent) {
        const parId = idByName.get(t.parent.toLowerCase());
        const myId = idByName.get(t.name.toLowerCase());
        if (parId && myId && parId !== myId)
          await api('PUT', `/api/tasks/${myId}`, { parent_id: parId });
      }
    }
    await reload();
    location.hash = `#/project/${pr.id}`;
    route();
    toast(`Project "${plan.name}" created with ${tasks.length} tasks.`);
  }

  function importDialog() {
    const color = PROJECT_COLORS[S.projects.length % PROJECT_COLORS.length];
    let plan = null;
    openPanel(`
      <h3>Import a project from CSV</h3>
      <p class="muted" style="font-size:13px;margin:6px 0 0">Download the template (or copy the
        instructions into ChatGPT/Claude and let it write the CSV), then choose the file here.
        You'll see a preview before anything is created.</p>
      <div class="panel-actions" style="margin-top:14px">
        <button class="btn small" id="dlTpl">⬇ Download template</button>
        <button class="btn small" id="cpPrompt">Copy AI instructions</button>
      </div>
      <label>Project name</label><input id="i_name" placeholder="e.g. Autumn campaign">
      <label>Due date (optional)</label><input id="i_due" type="date">
      <label>CSV file</label><input id="i_file" type="file" accept=".csv,text/csv,text/plain">
      <div id="i_preview"></div>
      <div class="panel-actions">
        <button class="btn primary grow" id="i_create" disabled>Create project</button>
        <button class="btn" id="i_cancel">Cancel</button>
      </div>`);
    panel.querySelector('#i_cancel').addEventListener('click', closePanel);
    panel.querySelector('#dlTpl').addEventListener('click', () => {
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([CSV_TEMPLATE], { type: 'text/csv' }));
      a.download = 'emotiogantt-template.csv';
      a.click();
      URL.revokeObjectURL(a.href);
    });
    panel.querySelector('#cpPrompt').addEventListener('click', async () => {
      await navigator.clipboard.writeText(GPT_PROMPT);
      toast('Instructions copied — paste them into your AI of choice.');
    });
    panel.querySelector('#i_file').addEventListener('change', async (e) => {
      const f = e.target.files[0];
      if (!f) return;
      const rows = parseCSV(await f.text());
      if (rows.length < 2) {
        panel.querySelector('#i_preview').innerHTML =
          '<p class="err">That file looks empty — is it the right CSV?</p>';
        return;
      }
      const nameInput = panel.querySelector('#i_name');
      if (!nameInput.value.trim()) nameInput.value = f.name.replace(/\.[^.]+$/, '');
      plan = { name: '', due_date: null, tasks: csvToTasks(rows) };
      panel.querySelector('#i_preview').innerHTML =
        planPreviewHTML({ ...plan, name: nameInput.value.trim() || 'Preview' });
      panel.querySelector('#i_create').disabled = !plan.tasks.length;
    });
    panel.querySelector('#i_create').addEventListener('click', async () => {
      const name = panel.querySelector('#i_name').value.trim();
      if (!plan || !name) { toast('Give the project a name first.'); return; }
      plan.name = name;
      plan.due_date = panel.querySelector('#i_due').value || null;
      closePanel();
      await createProjectFromPlan(plan, color);
    });
  }

  function aiDialog() {
    const color = PROJECT_COLORS[S.projects.length % PROJECT_COLORS.length];
    api('GET', '/api/settings').then(({ openai }) => openai ? promptForm() : keyForm());

    function keyForm() {
      openPanel(`
        <h3>Set up with AI</h3>
        <p class="muted" style="font-size:13px;margin:6px 0 0">Paste an OpenAI API key once and
          it's stored on your server (never shown again here). Then describe any project and
          get a draft plan to review.</p>
        <label>OpenAI API key</label><input id="k_key" type="password" placeholder="sk-…">
        <div class="panel-actions">
          <button class="btn primary grow" id="k_save">Save key</button>
          <button class="btn" id="k_cancel">Cancel</button>
        </div>`);
      panel.querySelector('#k_cancel').addEventListener('click', closePanel);
      panel.querySelector('#k_save').addEventListener('click', async () => {
        const v = panel.querySelector('#k_key').value.trim();
        if (!v) return;
        await api('POST', '/api/settings', { openai_key: v });
        promptForm();
      });
    }

    function promptForm() {
      openPanel(`
        <h3>Set up with AI</h3>
        <p class="muted" style="font-size:13px;margin:6px 0 0">Describe the project — what it is,
          rough timescales, who's involved. You'll get an outline to approve or amend before
          anything is created.</p>
        <label>Describe your project</label>
        <textarea id="a_prompt" rows="6" placeholder="e.g. Launch a small e-commerce site for my candle business by mid-October. Me and Lou. Needs branding, product photos, Shopify build, payment setup and a soft launch."></textarea>
        <div class="panel-actions">
          <button class="btn primary grow" id="a_go">Draft the plan</button>
          <button class="btn" id="a_cancel">Cancel</button>
        </div>
        <p style="margin-top:14px"><button class="btn small" id="a_key">Change API key</button></p>
        <p id="a_err" class="err hidden"></p>`);
      panel.querySelector('#a_cancel').addEventListener('click', closePanel);
      panel.querySelector('#a_key').addEventListener('click', keyForm);
      panel.querySelector('#a_go').addEventListener('click', () => {
        const prompt = panel.querySelector('#a_prompt').value.trim();
        if (prompt) draft({ prompt });
      });
    }

    async function draft(body) {
      const go = panel.querySelector('#a_go') || panel.querySelector('#a_amend_go');
      if (go) { go.disabled = true; go.textContent = 'Thinking…'; }
      try {
        const { plan } = await api('POST', '/api/ai-plan', body);
        preview(plan);
      } catch (e) {
        const err = panel.querySelector('#a_err');
        if (err) {
          err.textContent = 'The AI call failed — check the key and try again. ' + e.message.slice(0, 160);
          err.classList.remove('hidden');
        }
        if (go) { go.disabled = false; go.textContent = 'Draft the plan'; }
      }
    }

    function preview(plan) {
      openPanel(`
        <h3>Here's the proposed plan</h3>
        <p class="muted" style="font-size:13px;margin:6px 0 0">Nothing is created yet. Amend it
          below as many times as you like, then commit it to a Gantt.</p>
        ${planPreviewHTML(plan)}
        <label>Amendments (optional)</label>
        <textarea id="a_amend" rows="3" placeholder="e.g. add a week of contingency before launch, and give the photo tasks to Lou"></textarea>
        <div class="panel-actions">
          <button class="btn" id="a_amend_go">Apply amendment</button>
          <button class="btn primary grow" id="a_create">Create project</button>
          <button class="btn" id="a_cancel2">Cancel</button>
        </div>
        <p id="a_err" class="err hidden"></p>`);
      panel.querySelector('#a_cancel2').addEventListener('click', closePanel);
      panel.querySelector('#a_amend_go').addEventListener('click', () => {
        const amendment = panel.querySelector('#a_amend').value.trim();
        if (amendment) draft({ plan, amendment });
      });
      panel.querySelector('#a_create').addEventListener('click', async () => {
        closePanel();
        await createProjectFromPlan(plan, color);
      });
    }
  }

  // ---------- router ----------
  function route() {
    const h = location.hash || '#/today';
    const m = h.match(/^#\/project\/(\d+)/);
    if (m) return renderProject(Number(m[1]));
    if (h.startsWith('#/projects')) return renderProjects();
    if (h.startsWith('#/timeline')) return renderTimeline();
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
