(() => {
  const $ = id => document.getElementById(id);
  const screenMode = location.pathname.replace(/\/+$/, '') === '/results';
  const ORD = ['1st', '2nd', '3rd', '4th', '5th'];

  let cfg = null;
  let me = null;
  let picks = [];              // strategy names in rank order
  const whyByName = new Map(); // explanation text, kept if a pick is moved
  let busy = false;
  let lastResults = null;

  // ---------- device id (mirrors the HttpOnly cookie) ----------
  const store = {
    get() { try { return localStorage.getItem('wkc_rank_vid'); } catch { return null; } },
    set(v) { try { localStorage.setItem('wkc_rank_vid', v); } catch { /* private mode */ } },
  };

  async function api(path, opts = {}) {
    const headers = { ...(opts.headers || {}) };
    const vid = store.get();
    if (vid) headers['X-Voter-Id'] = vid;
    if (opts.body) headers['Content-Type'] = 'application/json';
    const res = await fetch(path, { ...opts, headers, credentials: 'same-origin' });
    const data = await res.json().catch(() => ({}));
    if (data.vid) store.set(data.vid);
    return { ok: res.ok, data };
  }

  function toast(msg) {
    const t = $('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => t.classList.remove('show'), 2600);
  }

  function el(tag, cls, text) {
    const e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text != null) e.textContent = text;
    return e;
  }

  // ---------- ballot ----------
  function renderOptions() {
    const box = $('options');
    box.textContent = '';
    for (const name of cfg.options) {
      const b = el('button', 'opt');
      b.type = 'button';
      b.dataset.name = name;
      b.append(el('span', 'opt-rank'), el('span', 'opt-name', name));
      b.addEventListener('click', () => toggle(name));
      box.append(b);
    }
  }

  function toggle(name) {
    if (busy || !me || me.voted || (lastResults && !lastResults.open)) return;
    if (picks.includes(name)) {
      picks = picks.filter(n => n !== name);
    } else if (picks.length >= cfg.picks) {
      return toast(`You've picked ${cfg.picks}. Tap a picked strategy to remove it.`);
    } else {
      picks = [...picks, name];
      if (picks.length === cfg.picks) {
        setTimeout(() => $('picks-card').scrollIntoView({ behavior: 'smooth', block: 'start' }), 150);
      }
    }
    syncPicks();
  }

  function move(i, dir) {
    const j = i + dir;
    if (j < 0 || j >= picks.length) return;
    const next = [...picks];
    [next[i], next[j]] = [next[j], next[i]];
    picks = next;
    syncPicks();
  }

  function syncPicks() {
    for (const b of $('options').children) {
      const i = picks.indexOf(b.dataset.name);
      b.setAttribute('aria-pressed', String(i >= 0));
      b.querySelector('.opt-rank').textContent = i >= 0 ? i + 1 : '';
    }
    $('status').innerHTML = `<strong>${picks.length}</strong> of ${cfg.picks} picked`;
    renderPickSlots();
    updateVoteButton();
  }

  function renderPickSlots() {
    const list = $('picks');
    list.textContent = '';
    for (let i = 0; i < cfg.picks; i++) {
      const name = picks[i];
      const li = el('li', 'pick' + (name ? '' : ' empty-slot'));
      const head = el('div', 'pick-head');
      head.append(el('span', 'pick-badge', ORD[i]));
      if (!name) {
        head.append(el('span', 'pick-placeholder', 'Tap a strategy above'));
        li.append(head);
        list.append(li);
        continue;
      }
      head.append(el('span', 'pick-name', name));
      const tools = el('span', 'pick-tools');
      const up = el('button', 'icon-btn', '↑');
      up.type = 'button';
      up.disabled = i === 0;
      up.setAttribute('aria-label', `Move ${name} up`);
      up.onclick = () => move(i, -1);
      const down = el('button', 'icon-btn', '↓');
      down.type = 'button';
      down.disabled = i === picks.length - 1;
      down.setAttribute('aria-label', `Move ${name} down`);
      down.onclick = () => move(i, 1);
      const rm = el('button', 'icon-btn', '✕');
      rm.type = 'button';
      rm.setAttribute('aria-label', `Remove ${name}`);
      rm.onclick = () => toggle(name);
      tools.append(up, down, rm);
      head.append(tools);

      const ta = el('textarea', 'why');
      ta.rows = 2;
      ta.maxLength = cfg.maxExplanation;
      ta.placeholder = 'How will you apply this in your work?' + (cfg.requireExplanation ? '' : ' (optional)');
      ta.value = whyByName.get(name) || '';
      ta.setAttribute('aria-label', `How you will apply ${name}`);
      ta.required = !!cfg.requireExplanation;
      ta.addEventListener('input', () => { whyByName.set(name, ta.value); updateVoteButton(); });
      li.append(head, ta);
      list.append(li);
    }
  }

  function missingCount() {
    return cfg.requireExplanation ? picks.filter(n => !(whyByName.get(n) || '').trim()).length : 0;
  }

  function missingWhy() {
    return missingCount() > 0;
  }

  function updateVoteButton() {
    const btn = $('vote-btn');
    const n = picks.length;
    const left = cfg.picks - n;
    btn.disabled = busy || left > 0 || missingWhy();
    if (busy) btn.textContent = 'Submitting…';
    else if (left > 0) btn.textContent = n ? `Pick ${left} more · ${n} of ${cfg.picks}` : `Pick your top ${cfg.picks} strategies`;
    else if (missingWhy()) {
      const m = missingCount();
      btn.textContent = m === cfg.picks ? 'Explain how you will apply each one' : `Explain ${m} more to submit`;
    }
    else btn.textContent = 'Submit my ranking';
  }

  function banner(text, warn) {
    const b = $('banner');
    b.hidden = !text;
    b.textContent = text || '';
    b.classList.toggle('warn', !!warn);
  }

  function renderBallot() {
    const closed = lastResults && !lastResults.open;
    const done = me.voted;
    $('ballot').hidden = done;
    $('picks-card').hidden = done;
    $('thanks-card').hidden = !done;
    $('votebar').hidden = done || closed;
    for (const b of $('options').children) b.disabled = closed || done;
    for (const t of $('picks').querySelectorAll('textarea, button')) t.disabled = closed || done;
    banner(closed && !done ? 'Voting is closed. Thank you, everyone!' : '', true);
    $('results').hidden = !cfg.showResultsBeforeVoting && !done && !closed;
  }

  async function submit() {
    if (busy || picks.length !== cfg.picks || missingWhy()) return;
    busy = true;
    updateVoteButton();
    const ranking = picks.map(name => ({ name, why: (whyByName.get(name) || '').trim() }));
    const { ok, data } = await api('/api/vote', { method: 'POST', body: JSON.stringify({ ranking }) })
      .catch(() => ({ ok: false, data: { error: 'Network problem. Please try again.' } }));
    busy = false;
    if ('voted' in data) me = { ...me, voted: data.voted };
    if (ok) {
      toast('Your ranking has been counted. Thank you!');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    } else {
      toast(data.error || 'Something went wrong.');
    }
    updateVoteButton();
    renderBallot();
  }

  // ---------- results ----------
  const TOP = 5;
  const rowEls = new Map();

  function rowEl(name) {
    let r = rowEls.get(name);
    if (!r) {
      r = el('li', 'row rank-row');
      r.dataset.name = name;
      r.innerHTML = '<span class="rank"></span><span class="main"><span class="name"></span><span class="track"><span class="fill"></span></span></span><span class="count"></span>';
      r.querySelector('.name').textContent = name;
      // Clear the vote flash once it ends, or moving the row would replay it.
      r.addEventListener('animationend', e => { if (e.animationName === 'flash') r.classList.remove('bump'); });
      rowEls.set(name, r);
    }
    return r;
  }

  function byScore(a, b) {
    if (b.points !== a.points) return b.points - a.points;
    for (let i = 0; i < a.ranks.length; i++) if (b.ranks[i] !== a.ranks[i]) return b.ranks[i] - a.ranks[i];
    return cfg.options.indexOf(a.name) - cfg.options.indexOf(b.name);
  }

  // Top 5 by points. Rows slide to their new place when the order changes.
  function renderResults(r) {
    const prev = lastResults;
    lastResults = r;
    const board = $('board');
    const prevPoints = new Map((prev?.rows || []).map(x => [x.name, x.points]));
    const sorted = r.rows.filter(x => x.points > 0).sort(byScore);
    const top = sorted.slice(0, TOP);
    const max = Math.max(1, ...top.map(x => x.points));

    const before = new Map();
    for (const e of board.children) before.set(e.dataset.name, e.getBoundingClientRect().top);
    const keep = new Set(top.map(x => x.name));
    for (const e of [...board.children]) if (!keep.has(e.dataset.name)) e.remove();

    let rank = 0;
    let lastPoints = null;
    top.forEach((row, i) => {
      const e = rowEl(row.name);
      if (row.points !== lastPoints) { rank = i + 1; lastPoints = row.points; }
      e.querySelector('.rank').textContent = rank;
      e.classList.toggle('lead', rank === 1);
      const count = e.querySelector('.count');
      count.textContent = row.points;
      count.append(el('small', null, row.points === 1 ? 'pt' : 'pts'));
      if (prev && (prevPoints.get(row.name) || 0) < row.points) {
        e.classList.remove('bump');
        void e.offsetWidth;
        e.classList.add('bump');
      }
      if (board.children[i] !== e) board.insertBefore(e, board.children[i] || null);
    });

    const ease = 'cubic-bezier(.2,.8,.2,1)';
    for (const e of board.children) {
      const old = before.get(e.dataset.name);
      if (old === undefined) {
        e.animate([{ opacity: 0, transform: 'translateX(-32px)' }, { opacity: 1, transform: 'none' }], { duration: 600, easing: ease });
        continue;
      }
      const delta = old - e.getBoundingClientRect().top;
      if (delta) e.animate([{ transform: `translateY(${delta}px)` }, { transform: 'none' }], { duration: 700, easing: ease });
    }
    requestAnimationFrame(() => {
      for (const row of top) rowEl(row.name).querySelector('.fill').style.width = (row.points / max) * 100 + '%';
    });

    $('empty').hidden = top.length > 0;
    $('total').textContent = r.responses;
    $('live').classList.toggle('off', !r.open);
    $('live-text').textContent = r.open ? 'Live' : 'Voting closed';

    if (me && (!prev || prev.open !== r.open)) renderBallot();
  }

  // Live updates via long-polling: the server answers as soon as anyone votes.
  let version = 0;
  let inflight = null;

  async function connectLive() {
    for (;;) {
      inflight = new AbortController();
      const timeout = setTimeout(() => inflight.abort(), 35000);
      try {
        const res = await fetch('/api/results?v=' + version, { signal: inflight.signal, cache: 'no-store' });
        if (!res.ok) throw new Error(res.status);
        const data = await res.json();
        if (data.version !== version) {
          version = data.version;
          renderResults(data);
        }
      } catch {
        await new Promise(r => setTimeout(r, 2000));
      } finally {
        clearTimeout(timeout);
      }
    }
  }

  // Phones freeze background tabs; resync as soon as the page is visible again.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && inflight) {
      version = 0;
      inflight.abort();
    }
  });

  function renderQr() {
    const url = location.origin + '/';
    $('qr-url').textContent = url.replace(/^https?:\/\//, '').replace(/\/$/, '');
    if (window.qrcode) {
      const qr = qrcode(0, 'M');
      qr.addData(url);
      qr.make();
      $('qr').innerHTML = qr.createSvgTag({ cellSize: 6, margin: 0, scalable: true });
    }
    $('qr-card').hidden = false;
  }

  // ---------- boot ----------
  async function boot() {
    cfg = (await api('/api/config')).data;
    $('title').textContent = cfg.title;
    $('subtitle').textContent = cfg.subtitle || '';
    $('event').textContent = cfg.event;
    $('scoring').textContent = cfg.points.map((p, i) => `${ORD[i]} = ${p} pts`).join(' · ');
    document.title = screenMode ? 'Live Results · Top Strategies' : 'Top 3 Strategies';

    if (screenMode) {
      document.body.classList.add('screen');
      $('results').hidden = false;
      document.querySelector('.foot').hidden = true;
      renderQr();
      connectLive();
      return;
    }

    renderOptions();
    $('why-note').textContent = cfg.requireExplanation ? 'Required' : 'Optional';
    $('vote-btn').addEventListener('click', submit);
    me = (await api('/api/me')).data;
    syncPicks();
    renderBallot();
    connectLive();
  }

  boot().catch(err => {
    console.error(err);
    toast('Could not reach the voting server. Please refresh.');
  });
})();
