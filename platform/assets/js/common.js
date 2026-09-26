/* Shared helpers for the voting page and the projector. */
(() => {
  const $ = id => document.getElementById(id);
  const ORD = ['1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th'];

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  // Device id mirrors the HttpOnly cookie, so clearing just one doesn't reset a vote.
  const store = {
    get() { try { return localStorage.getItem('vs_vid'); } catch { return null; } },
    set(v) { try { localStorage.setItem('vs_vid', v); } catch { /* private mode */ } },
  };

  async function api(path, opts = {}) {
    const headers = { ...(opts.headers || {}) };
    const vid = store.get();
    if (vid) headers['X-Voter-Id'] = vid;
    if (opts.body) headers['Content-Type'] = 'application/json';
    const res = await fetch(path, { ...opts, headers, credentials: 'same-origin', cache: 'no-store' });
    const issued = res.headers.get('X-Voter-Id');
    if (issued) store.set(issued);
    const data = await res.json().catch(() => ({}));
    return { ok: res.ok, status: res.status, data };
  }

  function toast(msg, ms = 2800) {
    const t = $('toast');
    if (!t) return;
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => t.classList.remove('show'), ms);
  }

  // Counts a number up (or down) to its new value.
  function tween(node, to) {
    const from = Number(node.dataset.v ?? node.textContent) || 0;
    node.dataset.v = to;
    if (from === to) { node.textContent = to; return; }
    const t0 = performance.now();
    const step = now => {
      const p = Math.min(1, (now - t0) / 700);
      node.textContent = Math.round(from + (to - from) * (1 - Math.pow(1 - p, 3)));
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  }

  function confetti(big = false) {
    const c = el('canvas', 'confetti');
    document.body.append(c);
    const dpr = window.devicePixelRatio || 1;
    const w = innerWidth, h = innerHeight;
    c.width = w * dpr;
    c.height = h * dpr;
    const ctx = c.getContext('2d');
    ctx.scale(dpr, dpr);
    const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#f7941d';
    const colors = [accent, '#ffd66b', '#ffffff', '#939598', accent, '#ffb547'];
    const n = big ? 260 : 150;
    const parts = Array.from({ length: n }, () => ({
      x: w / 2 + (Math.random() - .5) * (big ? w * .6 : 80), y: big ? h * .3 : h * .42,
      vx: (Math.random() - .5) * (big ? 22 : 15), vy: -Math.random() * (big ? 16 : 13) - 5,
      s: 5 + Math.random() * (big ? 10 : 7), r: Math.random() * 6.3, vr: (Math.random() - .5) * .35,
      c: colors[Math.floor(Math.random() * colors.length)],
    }));
    const life = big ? 3600 : 2600;
    const t0 = performance.now();
    const tick = now => {
      const t = now - t0;
      ctx.clearRect(0, 0, w, h);
      for (const p of parts) {
        p.vy += .32; p.vx *= .99; p.x += p.vx; p.y += p.vy; p.r += p.vr;
        ctx.save();
        ctx.globalAlpha = Math.max(0, 1 - t / life);
        ctx.translate(p.x, p.y);
        ctx.rotate(p.r);
        ctx.fillStyle = p.c;
        ctx.fillRect(-p.s / 2, -p.s / 4, p.s, p.s / 2);
        ctx.restore();
      }
      if (t < life) requestAnimationFrame(tick); else c.remove();
    };
    requestAnimationFrame(tick);
  }

  /** Option icon: initials, emoji or image. */
  function iconEl(icon, extra = '') {
    const a = el('span', 'avatar ' + extra);
    a.setAttribute('aria-hidden', 'true');
    if (!icon || icon.type === 'none') return null;
    if (icon.type === 'image' && icon.url) {
      a.classList.add('image');
      const img = el('img');
      img.src = icon.url;
      img.alt = '';
      img.loading = 'lazy';
      a.append(img);
    } else if (icon.type === 'emoji') {
      a.classList.add('emoji');
      a.textContent = icon.value;
    } else {
      a.textContent = icon.value || '?';
    }
    return a;
  }

  function fmtCountdown(ms) {
    const s = Math.max(0, Math.floor(ms / 1000));
    const d = Math.floor(s / 86400), h = Math.floor(s % 86400 / 3600), m = Math.floor(s % 3600 / 60), sec = s % 60;
    const pad = n => String(n).padStart(2, '0');
    if (d) return `${d}d ${h}h ${pad(m)}m`;
    if (h) return `${h}:${pad(m)}:${pad(sec)}`;
    return `${pad(m)}:${pad(sec)}`;
  }

  /**
   * Live results board. Rows slide to their new place when the order changes,
   * newcomers slide in, and a row flashes when it gains votes.
   */
  function createBoard({ board, empty, total, more, unitLabel, topN, showIcons = true, colorOf = null }) {
    const rowEls = new Map();
    let prev = null;
    board.style.setProperty('--rows', topN);

    function rowEl(r) {
      let e = rowEls.get(r.id);
      if (!e) {
        e = el('li', 'row');
        e.dataset.id = r.id;
        e.innerHTML = '<span class="rank"></span><span class="main"><span class="name"></span><span class="track"><span class="fill"></span></span></span><span class="count"><span class="num">0</span><small></small></span>';
        const name = e.querySelector('.name');
        const ic = showIcons && r.icon && r.icon.type !== 'initials' ? iconEl(r.icon, 'mini') : null;
        if (ic) name.append(ic);
        name.append(document.createTextNode(r.label));
        e.addEventListener('animationend', ev => { if (ev.animationName === 'flash') e.classList.remove('bump'); });
        rowEls.set(r.id, e);
      }
      return e;
    }

    function render(res, { animateIn = false } = {}) {
      const unit = res.unit === 'pts' ? 'pts' : 'votes';
      const prevScore = new Map((prev?.rows || []).map(r => [r.id, r.score]));
      const shown = res.rows.filter(r => r.score > 0);
      const top = shown.slice(0, topN);
      const max = Math.max(1, ...top.map(r => r.score));

      const before = new Map();
      for (const e of board.children) before.set(Number(e.dataset.id), e.getBoundingClientRect().top);
      const keep = new Set(top.map(r => r.id));
      for (const e of [...board.children]) if (!keep.has(Number(e.dataset.id))) e.remove();

      top.forEach((r, i) => {
        const e = rowEl(r);
        e.querySelector('.rank').textContent = r.rank;
        e.classList.toggle('lead', r.rank === 1);
        for (const k of [1, 2, 3]) e.classList.toggle('r' + k, r.rank === k);
        if (colorOf) e.style.setProperty('--c', colorOf(r.id));
        tween(e.querySelector('.num'), r.score);
        e.querySelector('.count small').textContent = unit === 'pts' ? (r.score === 1 ? 'pt' : 'pts') : r.pct + '%';
        if (prev && (prevScore.get(r.id) || 0) < r.score) {
          e.classList.remove('bump');
          void e.offsetWidth;
          e.classList.add('bump');
        }
        if (board.children[i] !== e) board.insertBefore(e, board.children[i] || null);
      });

      if (!animateIn) {
        const ease = 'cubic-bezier(.2,.8,.2,1)';
        for (const e of board.children) {
          const old = before.get(Number(e.dataset.id));
          if (old === undefined) {
            e.animate([{ opacity: 0, transform: 'translateX(-32px)' }, { opacity: 1, transform: 'none' }], { duration: 600, easing: ease });
            continue;
          }
          const delta = old - e.getBoundingClientRect().top;
          if (delta) e.animate([{ transform: `translateY(${delta}px)` }, { transform: 'none' }], { duration: 700, easing: ease });
        }
      }
      requestAnimationFrame(() => {
        for (const r of top) rowEl(r).querySelector('.fill').style.width = (r.score / max) * 100 + '%';
      });

      if (empty) empty.hidden = top.length > 0;
      if (total) tween(total, res.responses);
      if (more) {
        const cutoff = top.length === topN ? top[topN - 1].score : null;
        const tied = cutoff ? shown.slice(topN).filter(r => r.score === cutoff).length : 0;
        more.textContent = tied ? `+${tied} more tied on ${cutoff} ${unit === 'pts' ? 'pts' : (cutoff === 1 ? 'vote' : 'votes')}` : (unitLabel || '');
      }
      prev = res;
      return [...board.children];
    }

    return { render, rows: () => [...board.children] };
  }

  /**
   * Live donut chart with legend. Slices keep their colour per option, grow/shrink
   * smoothly, and everything past the top N is grouped into "Others".
   */
  const PALETTE = ['var(--accent)', '#4c8dff', '#2fbf8f', '#b07cff', '#ff6b8a', '#f5c542', '#3cc6d6', '#ff9f5a', '#e56bff', '#7bd151'];
  function createPie({ host, topN }) {
    host.innerHTML = '<div class="donut"><svg viewBox="0 0 42 42" aria-hidden="true"><circle class="ring" cx="21" cy="21" r="15.9155"></circle></svg>'
      + '<div class="center"><b class="num">0</b><span>responses</span></div></div><ul class="legend"></ul>';
    const svg = host.querySelector('svg');
    const legend = host.querySelector('.legend');
    const slices = new Map();
    const colors = new Map();
    const colorOf = id => { if (!colors.has(id)) colors.set(id, PALETTE[colors.size % PALETTE.length]); return colors.get(id); };

    function render(res) {
      const unit = res.unit === 'pts' ? 'pts' : 'votes';
      const shown = res.rows.filter(r => r.score > 0);
      const items = shown.slice(0, topN).map(r => ({ key: String(r.id), label: r.label, score: r.score, rank: r.rank, color: colorOf(r.id) }));
      const rest = shown.slice(topN);
      if (rest.length) items.push({ key: 'others', label: `Others (${rest.length})`, score: rest.reduce((s, r) => s + r.score, 0), color: 'var(--grey)' });
      const sum = items.reduce((s, i) => s + i.score, 0) || 1;
      const gap = items.length > 1 ? .6 : 0;
      let offset = 0;
      const keep = new Set(items.map(i => i.key));
      for (const [k, c] of slices) if (!keep.has(k)) { c.style.strokeDasharray = '0 100'; setTimeout(() => c.remove(), 900); slices.delete(k); }
      for (const it of items) {
        let c = slices.get(it.key);
        if (!c) {
          c = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
          c.setAttribute('class', 'slice');
          c.setAttribute('cx', '21'); c.setAttribute('cy', '21'); c.setAttribute('r', '15.9155');
          c.style.strokeDasharray = '0 100';
          c.style.strokeDashoffset = String(25 - offset);
          svg.append(c);
          slices.set(it.key, c);
        }
        const pct = it.score / sum * 100;
        const len = Math.max(pct - gap, 0);
        const start = offset;
        c.style.stroke = it.color;
        // Next frame, so new slices animate from zero; offset 25 starts the ring at 12 o'clock.
        requestAnimationFrame(() => {
          c.style.strokeDasharray = `${len} ${100 - len}`;
          c.style.strokeDashoffset = String(25 - start);
        });
        offset += pct;
      }
      tween(host.querySelector('.center .num'), res.responses);
      legend.textContent = '';
      for (const it of items) {
        const li = el('li', it.rank === 1 ? 'lead' : null);
        const sw = el('span', 'sw'); sw.style.background = it.color;
        const name = el('span', 'nm', it.label);
        const val = el('span', 'val', String(it.score));
        val.append(el('small', null, ` ${unit === 'pts' ? 'pts' : ''} ${Math.round(it.score / sum * 100)}%`));
        li.append(sw, name, val);
        legend.append(li);
      }
      host.classList.toggle('is-empty', items.length === 0);
    }
    return { render, colorOf };
  }

  /** Bars / Pie / Both switch. Remembers the viewer's choice per poll on this device. */
  function chartSwitch({ toggle, viz, slug, initial }) {
    const key = 'vs_chart_' + slug;
    let saved = null;
    try { saved = localStorage.getItem(key); } catch { /* private mode */ }
    const set = mode => {
      viz.dataset.chart = mode;
      for (const b of toggle.querySelectorAll('button')) b.classList.toggle('on', b.dataset.chart === mode);
      try { localStorage.setItem(key, mode); } catch { /* ignore */ }
    };
    toggle.hidden = false;
    toggle.addEventListener('click', e => { const b = e.target.closest('button[data-chart]'); if (b) set(b.dataset.chart); });
    set(['bars', 'pie', 'both'].includes(saved) ? saved : initial || 'bars');
    return { get: () => viz.dataset.chart };
  }

  /**
   * Polls a results URL. Sends the version we already have; the server answers
   * with a tiny "same" reply when nothing changed. Pauses while the tab is hidden.
   */
  function poller(url, onData, { every = 2000, safeToReload = () => true } = {}) {
    let version = 0, timer = null, build = null, pendingReload = false, busy = false;
    async function tick() {
      clearTimeout(timer);
      if (document.hidden) { timer = setTimeout(tick, every); return; }
      if (busy) return;
      busy = true;
      try {
        const sep = url.includes('?') ? '&' : '?';
        const { ok, data } = await api(url + sep + 'v=' + version);
        if (ok && data.live) {
          if (!build) build = data.live.build;
          if (data.live.build !== build) pendingReload = true;
          if (pendingReload && safeToReload()) { location.reload(); return; }
          onData(data, !data.same);
          if (!data.same) version = data.live.version;
        }
      } catch { /* offline — try again shortly */ }
      busy = false;
      timer = setTimeout(tick, every);
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) tick(); });
    tick();
    return { now: () => { version = 0; tick(); }, refresh: tick };
  }

  window.VSC = { $, el, ORD, api, toast, tween, confetti, iconEl, fmtCountdown, createBoard, createPie, chartSwitch, poller };
})();
