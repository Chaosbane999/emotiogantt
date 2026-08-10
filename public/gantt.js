// SVG Gantt renderer with drag-to-reschedule and critical path highlighting
(function () {
  const { D } = window.CPM;
  const NS = 'http://www.w3.org/2000/svg';
  const ROW_H = 40, HEAD_H = 56, BAR_H = 24, BAR_PAD = (ROW_H - BAR_H) / 2;

  function el(tag, attrs, parent) {
    const e = document.createElementNS(NS, tag);
    for (const k in attrs) e.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(e);
    return e;
  }
  function css(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  }
  function initials(name) {
    return name.split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase();
  }

  function render(container, opts) {
    const { tasks, deps, cpm, project, people, zoom, onTaskClick, onTaskChange, onAddTask } = opts;
    const dayW = zoom === 'day' ? 36 : 13;
    const today = D.today();

    // date range
    let min = today, max = today;
    for (const t of tasks) { if (t.start < min) min = t.start; if (t.end > max) max = t.end; }
    if (project.due_date && project.due_date > max) max = project.due_date;
    min = D.add(min, -3); max = D.add(max, 10);
    const totalDays = D.diff(min, max) + 1;
    const width = totalDays * dayW;
    const height = HEAD_H + tasks.length * ROW_H;
    const x = (date) => D.diff(min, date) * dayW;

    const peopleById = new Map(people.map(p => [p.id, p]));

    container.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'gantt-wrap';

    // ---- left names column ----
    const names = document.createElement('div');
    names.className = 'gantt-names';
    names.innerHTML = '<div class="gn-head">Tasks</div>';
    for (const t of tasks) {
      const row = document.createElement('div');
      row.className = 'gn-row' + (t.done ? ' done' : '');
      const c = cpm.get(t.id);
      const person = t.person_id ? peopleById.get(t.person_id) : null;
      row.innerHTML =
        (c && c.critical ? '<span class="dot" style="background:var(--critical)" title="On the critical path"></span>' : '<span class="dot" style="background:transparent"></span>') +
        `<span class="nm">${escapeHtml(t.name)}</span>` +
        (person ? `<span class="who" style="background:${person.color}" title="${escapeHtml(person.name)}">${initials(person.name)}</span>` : '');
      row.addEventListener('click', () => onTaskClick(t));
      names.appendChild(row);
    }
    const addWrap = document.createElement('div');
    addWrap.className = 'gantt-add';
    const addBtn = document.createElement('button');
    addBtn.className = 'btn small';
    addBtn.textContent = '+ Add task';
    addBtn.addEventListener('click', onAddTask);
    addWrap.appendChild(addBtn);
    names.appendChild(addWrap);
    wrap.appendChild(names);

    // ---- scrollable chart ----
    const scroll = document.createElement('div');
    scroll.className = 'gantt-scroll';
    const svg = el('svg', { class: 'gantt', width, height: height + 10 });
    scroll.appendChild(svg);
    wrap.appendChild(scroll);

    // weekend + day grid
    for (let i = 0; i < totalDays; i++) {
      const date = D.add(min, i);
      if (D.isWeekend(date)) {
        el('rect', { x: i * dayW, y: HEAD_H, width: dayW, height: height - HEAD_H,
          fill: css('--surface2'), opacity: .55 }, svg);
      }
    }
    // row separators
    for (let r = 0; r <= tasks.length; r++) {
      el('line', { x1: 0, y1: HEAD_H + r * ROW_H, x2: width, y2: HEAD_H + r * ROW_H,
        stroke: css('--line'), 'stroke-width': 1 }, svg);
    }

    // ---- header: months + days/weeks ----
    let cursor = min;
    while (cursor <= max) {
      const dt = new Date(cursor + 'T00:00:00Z');
      const monthEnd = D.fmt(Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth() + 1, 0));
      const segEnd = monthEnd < max ? monthEnd : max;
      const mx = x(cursor), mw = (D.diff(cursor, segEnd) + 1) * dayW;
      if (mw > 40) {
        el('text', { x: mx + 8, y: 20, 'font-size': 12, 'font-weight': 600,
          fill: css('--ink-soft') }, svg).textContent =
          dt.toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });
      }
      el('line', { x1: mx, y1: 0, x2: mx, y2: height, stroke: css('--line') }, svg);
      cursor = D.add(segEnd, 1);
    }
    for (let i = 0; i < totalDays; i++) {
      const date = D.add(min, i);
      const dt = new Date(date + 'T00:00:00Z');
      if (zoom === 'day') {
        el('text', { x: i * dayW + dayW / 2, y: 44, 'font-size': 11,
          'text-anchor': 'middle', fill: css('--ink-soft') }, svg).textContent = dt.getUTCDate();
      } else if (dt.getUTCDay() === 1) {
        el('text', { x: i * dayW + 3, y: 44, 'font-size': 10,
          fill: css('--ink-soft') }, svg).textContent = dt.getUTCDate();
        el('line', { x1: i * dayW, y1: HEAD_H - 8, x2: i * dayW, y2: HEAD_H,
          stroke: css('--line') }, svg);
      }
    }

    // ---- dependency lines ----
    const byId = new Map(tasks.map(t => [t.id, t]));
    const rowOf = new Map(tasks.map((t, i) => [t.id, i]));
    for (const d of deps) {
      const p = byId.get(d.pred_id), s = byId.get(d.succ_id);
      if (!p || !s) continue;
      const isCrit = cpm.get(p.id)?.critical && cpm.get(s.id)?.critical;
      const x1 = x(p.end) + dayW, y1 = HEAD_H + rowOf.get(p.id) * ROW_H + ROW_H / 2;
      const x2 = x(s.start), y2 = HEAD_H + rowOf.get(s.id) * ROW_H + ROW_H / 2;
      const midx = Math.max(x1 + 8, x2 - 8);
      const path = x2 >= x1 + 16
        ? `M ${x1} ${y1} L ${x2 - 8} ${y1} L ${x2 - 8} ${y2} L ${x2} ${y2}`
        : `M ${x1} ${y1} L ${x1 + 8} ${y1} L ${x1 + 8} ${(y1 + y2) / 2} L ${x2 - 8} ${(y1 + y2) / 2} L ${x2 - 8} ${y2} L ${x2} ${y2}`;
      el('path', { d: path, fill: 'none',
        stroke: isCrit ? css('--critical') : css('--ink-soft'),
        'stroke-width': isCrit ? 1.8 : 1.1, opacity: isCrit ? .9 : .45 }, svg);
      el('path', { d: `M ${x2} ${y2} l -6 -4 l 0 8 z`,
        fill: isCrit ? css('--critical') : css('--ink-soft'),
        opacity: isCrit ? .9 : .45 }, svg);
    }

    // ---- today line ----
    if (today >= min && today <= max) {
      const tx = x(today) + dayW / 2;
      el('line', { x1: tx, y1: HEAD_H - 6, x2: tx, y2: height,
        stroke: css('--accent'), 'stroke-width': 1.6, 'stroke-dasharray': '4 3' }, svg);
      const badge = el('g', {}, svg);
      el('rect', { x: tx - 22, y: HEAD_H - 26, width: 44, height: 17, rx: 8,
        fill: css('--accent') }, badge);
      el('text', { x: tx, y: HEAD_H - 14, 'font-size': 10, 'font-weight': 700,
        'text-anchor': 'middle', fill: '#fff' }, badge).textContent = 'Today';
    }

    // ---- project due marker ----
    if (project.due_date && project.due_date >= min && project.due_date <= max) {
      const dx = x(project.due_date) + dayW;
      el('line', { x1: dx, y1: HEAD_H, x2: dx, y2: height,
        stroke: css('--watch'), 'stroke-width': 1.4 }, svg);
      el('text', { x: dx - 4, y: height + 8, 'font-size': 10, 'text-anchor': 'end',
        fill: css('--watch'), 'font-weight': 600 }, svg).textContent = 'Due';
    }

    // ---- task bars ----
    tasks.forEach((t, i) => {
      const c = cpm.get(t.id) || {};
      const y = HEAD_H + i * ROW_H + BAR_PAD;
      const g = el('g', { style: 'cursor:pointer' }, svg);

      if (t.milestone) {
        const cx = x(t.start) + dayW / 2, cy = y + BAR_H / 2, r = 9;
        el('path', {
          d: `M ${cx} ${cy - r} L ${cx + r} ${cy} L ${cx} ${cy + r} L ${cx - r} ${cy} Z`,
          fill: t.done ? css('--ink-soft') : (c.critical ? css('--critical') : project.color),
          opacity: t.done ? .5 : 1,
        }, g);
        el('text', { x: cx + r + 6, y: cy + 4, 'font-size': 12, fill: css('--ink-soft') }, g)
          .textContent = t.name;
        attachDrag(g, t, 'move');
      } else {
        const bx = x(t.start), bw = (D.diff(t.start, t.end) + 1) * dayW;
        const fill = t.done ? css('--ink-soft') : (c.critical ? css('--critical') : project.color);
        el('rect', { x: bx, y, width: bw, height: BAR_H, rx: 6, fill,
          opacity: t.done ? .38 : .92 }, g);
        if (c.critical && !t.done) {
          el('rect', { x: bx, y, width: bw, height: BAR_H, rx: 6, fill: 'none',
            stroke: css('--critical'), 'stroke-width': 2 }, g);
        }
        const label = el('text', { 'font-size': 12, 'font-weight': 550 }, g);
        label.textContent = t.name + (t.done ? ' ✓' : '');
        if (bw > t.name.length * 7 + 16) {
          label.setAttribute('x', bx + 8); label.setAttribute('y', y + 16);
          label.setAttribute('fill', '#fff');
        } else {
          label.setAttribute('x', bx + bw + 8); label.setAttribute('y', y + 16);
          label.setAttribute('fill', css('--ink-soft'));
        }
        attachDrag(g, t, 'move');
        // resize handles
        for (const side of ['left', 'right']) {
          const hx = side === 'left' ? bx - 3 : bx + bw - 5;
          const h = el('rect', { x: hx, y, width: 8, height: BAR_H, fill: 'transparent',
            style: 'cursor:ew-resize' }, g);
          attachDrag(h, t, side);
        }
      }
    });

    // ---- drag logic ----
    let drag = null;
    function attachDrag(target, task, mode) {
      target.addEventListener('pointerdown', (e) => {
        e.preventDefault(); e.stopPropagation();
        drag = { task, mode, startX: e.clientX, moved: false };
        target.setPointerCapture(e.pointerId);
      });
      target.addEventListener('pointermove', (e) => {
        if (!drag || drag.task !== task) return;
        const days = Math.round((e.clientX - drag.startX) / dayW);
        if (days !== 0) drag.moved = true;
        drag.days = days;
        // live preview: shift the group
        if (drag.mode === 'move') target.parentNode.style.transform = `translateX(${days * dayW}px)`;
      });
      target.addEventListener('pointerup', () => {
        if (!drag || drag.task !== task) return;
        const { mode, days = 0, moved } = drag;
        drag = null;
        if (!moved || days === 0) {
          if (!moved) onTaskClick(task);
          if (target.parentNode.style) target.parentNode.style.transform = '';
          return;
        }
        let { start, end } = task;
        if (mode === 'move') { start = D.add(start, days); end = D.add(end, days); }
        if (mode === 'left') { start = D.add(start, days); if (start > end) start = end; }
        if (mode === 'right') { end = D.add(end, days); if (end < start) end = start; }
        onTaskChange(task, { start, end });
      });
    }

    container.appendChild(wrap);
    // scroll so today is visible near the left
    const tx = x(today) - dayW * 4;
    if (tx > 0) scroll.scrollLeft = tx;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  window.Gantt = { render };
})();
