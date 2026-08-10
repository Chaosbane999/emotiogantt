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
    const { tasks, deps, cpm, project, people, zoom, baseline,
      onTaskClick, onTaskChange, onAddTask, onReorder } = opts;
    let dayW = zoom === 'day' ? 36 : 13;
    const today = D.today();

    // date range
    let min = today, max = today;
    for (const t of tasks) { if (t.start < min) min = t.start; if (t.end > max) max = t.end; }
    if (baseline) for (const b of baseline.values()) {
      if (b.start < min) min = b.start;
      if (b.end > max) max = b.end;
    }
    if (project.due_date && project.due_date > max) max = project.due_date;
    min = D.add(min, -3); max = D.add(max, 10);
    const totalDays = D.diff(min, max) + 1;
    // stretch to fill the available width rather than leaving dead space
    const avail = container.clientWidth - 231 - 2;
    if (avail > 0 && totalDays * dayW < avail) dayW = avail / totalDays;
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
    const rowEls = [];
    let rowDrag = null;
    tasks.forEach((t, idx) => {
      const row = document.createElement('div');
      row.className = 'gn-row' + (t.done ? ' done' : '');
      const c = cpm.get(t.id);
      const person = t.person_id ? peopleById.get(t.person_id) : null;
      row.innerHTML =
        '<span class="grab" title="Drag to reorder">⋮⋮</span>' +
        (c && c.critical ? '<span class="dot" style="background:var(--critical)" title="On the critical path"></span>' : '<span class="dot" style="background:transparent"></span>') +
        `<span class="nm">${escapeHtml(t.name)}</span>` +
        (person ? `<span class="who" style="background:${person.color}" title="${escapeHtml(person.name)}">${initials(person.name)}</span>` : '');
      rowEls.push(row);

      row.addEventListener('pointerdown', (e) => {
        e.preventDefault();
        rowDrag = { row, idx, startY: e.clientY, active: false, target: idx };
        row.setPointerCapture(e.pointerId);
      });
      row.addEventListener('pointermove', (e) => {
        if (!rowDrag || rowDrag.row !== row) return;
        const dy = e.clientY - rowDrag.startY;
        if (!rowDrag.active) {
          if (Math.abs(dy) < 6) return;
          rowDrag.active = true;
          row.classList.add('dragging');
        }
        row.style.transform = `translateY(${dy}px)`;
        const target = Math.max(0, Math.min(tasks.length - 1, idx + Math.round(dy / ROW_H)));
        rowDrag.target = target;
        rowEls.forEach((r, i) => {
          if (r === row) return;
          let shift = 0;
          if (idx < target && i > idx && i <= target) shift = -ROW_H;
          if (idx > target && i < idx && i >= target) shift = ROW_H;
          r.style.transform = shift ? `translateY(${shift}px)` : '';
        });
      });
      const finishRow = () => {
        if (!rowDrag || rowDrag.row !== row) return;
        const { active, target } = rowDrag;
        rowDrag = null;
        row.classList.remove('dragging');
        if (!active) { onTaskClick(t); return; }
        if (target !== idx && onReorder) {
          const order = tasks.map(x => x.id);
          order.splice(idx, 1);
          order.splice(target, 0, t.id);
          onReorder(order);
        } else {
          rowEls.forEach(r => { r.style.transform = ''; });
        }
      };
      row.addEventListener('pointerup', finishRow);
      row.addEventListener('pointercancel', finishRow);
      names.appendChild(row);
    });
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
    const svg = el('svg', { class: 'gantt', width, height: height + 10,
      style: 'touch-action:none;user-select:none;-webkit-user-select:none;display:block' });
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

      // baseline ghost: where this task sat when the snapshot was taken
      const b = baseline && baseline.get(t.id);
      if (b) {
        const gy = y + BAR_H + 1.5;
        if (b.milestone) {
          const cx = x(b.start) + dayW / 2, cy = y + BAR_H / 2, r = 9;
          el('path', {
            d: `M ${cx} ${cy - r} L ${cx + r} ${cy} L ${cx} ${cy + r} L ${cx - r} ${cy} Z`,
            fill: 'none', stroke: css('--ink-soft'), 'stroke-width': 1.4,
            'stroke-dasharray': '3 2', opacity: .8 }, svg);
        } else {
          el('rect', { x: x(b.start), y: gy,
            width: (D.diff(b.start, b.end) + 1) * dayW, height: 5, rx: 2.5,
            fill: css('--ink-soft'), opacity: .45 }, svg);
        }
      }

      const g = el('g', { style: 'cursor:pointer' }, svg);
      const hasNote = !!(t.notes && t.notes.trim());
      const prog = t.done ? 100 : Math.max(0, Math.min(100, t.progress || 0));
      const titleLines = [];
      if (!t.done && prog > 0) titleLines.push(`${prog}% done`);
      if (hasNote) titleLines.push(
        '📝 ' + (t.notes.length > 220 ? t.notes.slice(0, 220) + '…' : t.notes));
      if (titleLines.length) el('title', {}, g).textContent = titleLines.join('\n');

      if (t.milestone) {
        const cx = x(t.start) + dayW / 2, cy = y + BAR_H / 2, r = 9;
        el('path', {
          d: `M ${cx} ${cy - r} L ${cx + r} ${cy} L ${cx} ${cy + r} L ${cx - r} ${cy} Z`,
          fill: t.done ? css('--ink-soft') : (c.critical ? css('--critical') : project.color),
          opacity: t.done ? .5 : 1,
        }, g);
        el('text', { x: cx + r + 6, y: cy + 4, 'font-size': 12, fill: css('--ink-soft') }, g)
          .textContent = t.name + (hasNote ? ' ✎' : '');
        attachDrag(g, t, 'move', { g });
      } else {
        const bx = x(t.start), bw = (D.diff(t.start, t.end) + 1) * dayW;
        const durDays = D.diff(t.start, t.end);
        const fill = t.done ? css('--ink-soft') : (c.critical ? css('--critical') : project.color);
        const barRect = el('rect', { x: bx, y, width: bw, height: BAR_H, rx: 6, fill,
          opacity: t.done ? .38 : .92, class: 'bar' }, g);
        // darker fill showing how much of the task is complete
        if (!t.done && prog > 0) {
          el('rect', { x: bx, y, width: Math.max(6, bw * prog / 100), height: BAR_H,
            rx: 6, fill: '#000', opacity: .28, style: 'pointer-events:none' }, g);
        }
        let critRect = null;
        if (c.critical && !t.done) {
          critRect = el('rect', { x: bx, y, width: bw, height: BAR_H, rx: 6, fill: 'none',
            stroke: css('--critical'), 'stroke-width': 2 }, g);
        }
        const label = el('text', { 'font-size': 12, 'font-weight': 550,
          style: 'pointer-events:none' }, g);
        label.textContent = t.name + (t.done ? ' ✓' : '') + (hasNote ? '  ✎' : '');
        if (bw > t.name.length * 7 + 16) {
          label.setAttribute('x', bx + 8); label.setAttribute('y', y + 16);
          label.setAttribute('fill', '#fff');
        } else {
          label.setAttribute('x', bx + bw + 8); label.setAttribute('y', y + 16);
          label.setAttribute('fill', css('--ink-soft'));
        }
        const ctx = { g, barRect, critRect, bx, bw, durDays };
        attachDrag(barRect, t, 'move', ctx);
        // resize handles: generous hit area + visible grip on hover
        for (const side of ['left', 'right']) {
          const hx = side === 'left' ? bx - 6 : bx + bw - 8;
          el('rect', { class: 'grip', 'pointer-events': 'none', rx: 1.5,
            x: side === 'left' ? bx + 3 : bx + bw - 6, y: y + 5,
            width: 3, height: BAR_H - 10, fill: '#fff', opacity: 0 }, g);
          const h = el('rect', { x: hx, y: y - 4, width: 14, height: BAR_H + 8,
            fill: 'transparent', style: 'cursor:ew-resize' }, g);
          attachDrag(h, t, side, ctx);
        }
        g.addEventListener('pointerenter', () =>
          g.querySelectorAll('.grip').forEach(r => r.setAttribute('opacity', .85)));
        g.addEventListener('pointerleave', () =>
          g.querySelectorAll('.grip').forEach(r => r.setAttribute('opacity', 0)));
      }
    });

    // ---- drag logic ----
    let drag = null;
    function attachDrag(target, task, mode, ctx) {
      const clampDays = (days) => {
        // never let a bar invert: keep at least 1 day
        if (mode === 'left') return Math.min(days, ctx.durDays ?? 0);
        if (mode === 'right') return Math.max(days, -(ctx.durDays ?? 0));
        return days;
      };
      const preview = (days) => {
        if (mode === 'move') {
          ctx.g.style.transform = `translateX(${days * dayW}px)`;
        } else if (ctx.barRect) {
          const nx = mode === 'left' ? ctx.bx + days * dayW : ctx.bx;
          const nw = mode === 'left' ? ctx.bw - days * dayW : ctx.bw + days * dayW;
          for (const r of [ctx.barRect, ctx.critRect]) {
            if (!r) continue;
            r.setAttribute('x', nx); r.setAttribute('width', Math.max(nw, dayW));
          }
        }
      };
      target.addEventListener('pointerdown', (e) => {
        e.preventDefault(); e.stopPropagation();
        drag = { task, mode, startX: e.clientX, moved: false, days: 0 };
        target.setPointerCapture(e.pointerId);
      });
      target.addEventListener('pointermove', (e) => {
        if (!drag || drag.task !== task || drag.mode !== mode) return;
        const days = clampDays(Math.round((e.clientX - drag.startX) / dayW));
        if (days !== 0) drag.moved = true;
        drag.days = days;
        preview(days);
      });
      const finish = () => {
        if (!drag || drag.task !== task || drag.mode !== mode) return;
        const { days = 0, moved } = drag;
        drag = null;
        if (!moved || days === 0) {
          ctx.g.style.transform = '';
          preview(0);
          if (!moved && mode === 'move') onTaskClick(task);
          return;
        }
        let { start, end } = task;
        if (mode === 'move') { start = D.add(start, days); end = D.add(end, days); }
        if (mode === 'left') { start = D.add(start, days); if (start > end) start = end; }
        if (mode === 'right') { end = D.add(end, days); if (end < start) end = start; }
        onTaskChange(task, { start, end });
      };
      target.addEventListener('pointerup', finish);
      target.addEventListener('pointercancel', finish);
    }

    container.appendChild(wrap);
    // scroll so today is visible near the left
    const tx = x(today) - dayW * 4;
    if (tx > 0) scroll.scrollLeft = tx;
  }

  // All projects on one timeline — see how they overlap, with a lane per person
  // so double-bookings across projects show up in coral.
  function renderPortfolio(container, opts) {
    const { projects, tasksByProject, healthByProject, people, onOpen } = opts;
    const dayW = 14;
    const PB_H = 22;          // project span bar height
    const NAME_BAND = 40;     // vertical space for the project bar band
    const LANE_H = 18;        // vertical space per person lane
    const today = D.today();
    const peopleById = new Map(people.map(p => [p.id, p]));
    const allTasks = [...tasksByProject.values()].flat();

    // people involved per project, and per-row layout
    const rows = projects.map(p => {
      const tasks = tasksByProject.get(p.id) || [];
      const ids = [...new Set(tasks.filter(t => t.person_id).map(t => t.person_id))];
      const involved = ids.map(id => peopleById.get(id)).filter(Boolean)
        .sort((a, b) => a.name.localeCompare(b.name));
      return { p, tasks, involved, rowH: NAME_BAND + involved.length * LANE_H + 12 };
    });

    let min = today, max = today;
    for (const r of rows) {
      for (const t of r.tasks) {
        if (t.start < min) min = t.start;
        if (t.end > max) max = t.end;
      }
      if (r.p.due_date && r.p.due_date > max) max = r.p.due_date;
    }
    min = D.add(min, -4); max = D.add(max, 14);
    const totalDays = D.diff(min, max) + 1;
    const width = totalDays * dayW;
    const height = HEAD_H + rows.reduce((a, r) => a + r.rowH, 0);
    const x = (date) => D.diff(min, date) * dayW;

    container.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'gantt-wrap';

    const names = document.createElement('div');
    names.className = 'gantt-names';
    names.innerHTML = '<div class="gn-head">Projects</div>';
    for (const r of rows) {
      const health = healthByProject.get(r.p.id);
      const hColor = health === 'late' ? 'var(--late)' : health === 'watch' ? 'var(--watch)' : 'var(--good)';
      const row = document.createElement('div');
      row.className = 'pf-row';
      row.style.height = r.rowH + 'px';
      row.innerHTML =
        `<div class="pf-name" style="height:${NAME_BAND}px">
           <span class="dot" style="background:${r.p.color}"></span>
           <span class="nm">${escapeHtml(r.p.name)}</span>
           <span class="dot" style="background:${hColor}" title="${health}"></span>
         </div>` +
        r.involved.map(pp =>
          `<div class="pf-person" style="height:${LANE_H}px">
             <span class="who" style="background:${pp.color};width:16px;height:16px;font-size:9px">${initials(pp.name)}</span>
             <span class="pf-pname">${escapeHtml(pp.name)}</span>
           </div>`).join('');
      row.addEventListener('click', () => onOpen(r.p));
      names.appendChild(row);
    }
    wrap.appendChild(names);

    const scroll = document.createElement('div');
    scroll.className = 'gantt-scroll';
    const svg = el('svg', { class: 'gantt', width, height: height + 10,
      style: 'display:block' });
    scroll.appendChild(svg);
    wrap.appendChild(scroll);

    let yCursor = HEAD_H;
    el('line', { x1: 0, y1: yCursor, x2: width, y2: yCursor,
      stroke: css('--line'), 'stroke-width': 1 }, svg);
    for (const r of rows) {
      r.top = yCursor;
      yCursor += r.rowH;
      el('line', { x1: 0, y1: yCursor, x2: width, y2: yCursor,
        stroke: css('--line'), 'stroke-width': 1 }, svg);
    }

    // month header + week ticks
    let cursor = min;
    while (cursor <= max) {
      const dt = new Date(cursor + 'T00:00:00Z');
      const monthEnd = D.fmt(Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth() + 1, 0));
      const segEnd = monthEnd < max ? monthEnd : max;
      const mx = x(cursor), mw = (D.diff(cursor, segEnd) + 1) * dayW;
      if (mw > 46) {
        el('text', { x: mx + 8, y: 22, 'font-size': 12, 'font-weight': 600,
          fill: css('--ink-soft') }, svg).textContent =
          dt.toLocaleDateString(undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' });
      }
      el('line', { x1: mx, y1: 0, x2: mx, y2: height, stroke: css('--line') }, svg);
      cursor = D.add(segEnd, 1);
    }
    for (let i = 0; i < totalDays; i++) {
      const dt = new Date(D.add(min, i) + 'T00:00:00Z');
      if (dt.getUTCDay() === 1) {
        el('text', { x: i * dayW + 3, y: 46, 'font-size': 10,
          fill: css('--ink-soft') }, svg).textContent = dt.getUTCDate();
      }
    }

    // today line
    if (today >= min && today <= max) {
      const tx = x(today) + dayW / 2;
      el('line', { x1: tx, y1: HEAD_H - 6, x2: tx, y2: height,
        stroke: css('--accent'), 'stroke-width': 1.6, 'stroke-dasharray': '4 3' }, svg);
      el('rect', { x: tx - 22, y: HEAD_H - 26, width: 44, height: 17, rx: 8,
        fill: css('--accent') }, svg);
      el('text', { x: tx, y: HEAD_H - 14, 'font-size': 10, 'font-weight': 700,
        'text-anchor': 'middle', fill: '#fff' }, svg).textContent = 'Today';
    }

    const overlap = (a, b) => a.start <= b.end && b.start <= a.end;

    for (const r of rows) {
      const { p, tasks, involved } = r;
      const y = r.top + (NAME_BAND - PB_H) / 2 + 4;
      if (tasks.length) {
        const s = tasks.reduce((a, t) => t.start < a ? t.start : a, tasks[0].start);
        const e = tasks.reduce((a, t) => t.end > a ? t.end : a, tasks[0].end);
        const bx = x(s), bw = (D.diff(s, e) + 1) * dayW;
        const g = el('g', { style: 'cursor:pointer' }, svg);
        el('rect', { x: bx, y, width: bw, height: PB_H, rx: 6,
          fill: p.color, opacity: .3 }, g);
        const totalTaskDays = tasks.reduce((a, t) => a + D.diff(t.start, t.end) + 1, 0);
        const doneTaskDays = tasks.reduce((a, t) =>
          a + (D.diff(t.start, t.end) + 1) * (t.done ? 100 : (t.progress || 0)) / 100, 0);
        const frac = totalTaskDays ? doneTaskDays / totalTaskDays : 0;
        if (frac > 0) {
          el('rect', { x: bx, y, width: Math.max(bw * frac, 6), height: PB_H,
            rx: 6, fill: p.color, opacity: .9 }, g);
        }
        for (const t of tasks.filter(t => t.milestone)) {
          const cx = x(t.start) + dayW / 2, cy = y + PB_H / 2, mr = 6;
          el('path', {
            d: `M ${cx} ${cy - mr} L ${cx + mr} ${cy} L ${cx} ${cy + mr} L ${cx - mr} ${cy} Z`,
            fill: t.done ? css('--ink-soft') : p.color, stroke: css('--surface'),
            'stroke-width': 1.5 }, g);
        }
        g.addEventListener('click', () => onOpen(p));
      }

      // one lane per person: their work on THIS project; coral where they're
      // also booked on another project at the same time
      involved.forEach((pp, li) => {
        const ly = r.top + NAME_BAND + li * LANE_H + (LANE_H - 10) / 2;
        const mine = tasks.filter(t => t.person_id === pp.id && !t.milestone);
        const elsewhere = allTasks.filter(t =>
          t.person_id === pp.id && t.project_id !== p.id && !t.done && !t.milestone);
        for (const t of mine) {
          const seg = el('rect', {
            x: x(t.start), y: ly, width: (D.diff(t.start, t.end) + 1) * dayW,
            height: 10, rx: 5, fill: pp.color, opacity: t.done ? .25 : .65 }, svg);
          el('title', {}, seg).textContent = `${pp.name}: ${t.name}`;
          if (t.done) continue;
          for (const o of elsewhere.filter(o => overlap(t, o))) {
            const os = t.start > o.start ? t.start : o.start;
            const oe = t.end < o.end ? t.end : o.end;
            const conflictSeg = el('rect', {
              x: x(os), y: ly, width: (D.diff(os, oe) + 1) * dayW,
              height: 10, rx: 5, fill: css('--critical'), opacity: .95 }, svg);
            const op = projects.find(pr => pr.id === o.project_id);
            el('title', {}, conflictSeg).textContent =
              `${pp.name} is double-booked: also on "${op ? op.name : 'another project'}" (${o.name}) ${D.human(os)}–${D.human(oe)}`;
          }
        }
      });

      // due marker across the whole row band
      if (p.due_date) {
        const dx = x(p.due_date) + dayW;
        el('line', { x1: dx, y1: r.top + 4, x2: dx, y2: r.top + r.rowH - 4,
          stroke: css('--watch'), 'stroke-width': 2 }, svg);
      }
    }

    container.appendChild(wrap);
    const tx = x(today) - dayW * 10;
    if (tx > 0) scroll.scrollLeft = tx;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c =>
      ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  }

  window.Gantt = { render, renderPortfolio };
})();
