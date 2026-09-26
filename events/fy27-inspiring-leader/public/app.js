(() => {
  const $ = id => document.getElementById(id);
  const params = new URLSearchParams(location.search);
  const screenMode = location.pathname.replace(/\/+$/, '') === '/results';
  const specialCode = params.get('k');

  let cfg = null;
  let me = null;
  let selected = [];
  let busy = false;
  let lastResults = null;

  // ---------- device id (mirrors the HttpOnly cookie) ----------
  const store = {
    get() { try { return localStorage.getItem('wkc_vid'); } catch { return null; } },
    set(v) { try { localStorage.setItem('wkc_vid', v); } catch { /* private mode */ } },
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

  function initials(name) {
    const parts = name.replace(/^Dr\.?\s+/i, '').split(/\s+/);
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[1][0]).toUpperCase();
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

  // Short burst of brand-coloured confetti after a vote.
  function confetti() {
    const c = document.createElement("canvas");
    c.className = "confetti";
    document.body.append(c);
    const dpr = window.devicePixelRatio || 1;
    const w = innerWidth, h = innerHeight;
    c.width = w * dpr;
    c.height = h * dpr;
    const ctx = c.getContext("2d");
    ctx.scale(dpr, dpr);
    const colors = ["#f7941d", "#ffb547", "#d8700a", "#ffffff", "#939598", "#ffd66b"];
    const parts = Array.from({ length: 150 }, () => ({
      x: w / 2 + (Math.random() - .5) * 80, y: h * .42,
      vx: (Math.random() - .5) * 15, vy: -Math.random() * 13 - 5,
      s: 5 + Math.random() * 7, r: Math.random() * 6.3, vr: (Math.random() - .5) * .35,
      c: colors[Math.floor(Math.random() * colors.length)],
    }));
    const t0 = performance.now();
    const tick = now => {
      const t = now - t0;
      ctx.clearRect(0, 0, w, h);
      for (const p of parts) {
        p.vy += .32; p.vx *= .99; p.x += p.vx; p.y += p.vy; p.r += p.vr;
        ctx.save();
        ctx.globalAlpha = Math.max(0, 1 - t / 2600);
        ctx.translate(p.x, p.y);
        ctx.rotate(p.r);
        ctx.fillStyle = p.c;
        ctx.fillRect(-p.s / 2, -p.s / 4, p.s, p.s / 2);
        ctx.restore();
      }
      if (t < 2600) requestAnimationFrame(tick); else c.remove();
    };
    requestAnimationFrame(tick);
  }

  function toast(msg) {
    const t = $('toast');
    t.textContent = msg;
    t.classList.add('show');
    clearTimeout(toast._t);
    toast._t = setTimeout(() => t.classList.remove('show'), 2600);
  }

  // ---------- ballot ----------
  function renderGrid() {
    const grid = $('grid');
    grid.textContent = '';
    for (const name of cfg.candidates) {
      const b = document.createElement('button');
      b.type = 'button';
      b.className = 'cand';
      b.setAttribute('aria-pressed', 'false');
      b.dataset.name = name;
      const av = document.createElement('span');
      av.className = 'avatar';
      av.textContent = initials(name);
      av.setAttribute('aria-hidden', 'true');
      const label = document.createElement('span');
      label.textContent = name;
      b.append(av, label);
      b.addEventListener('click', () => select(name));
      b.style.setProperty("--i", grid.children.length);
      grid.append(b);
    }
  }

  // Normal voters pick one name; special ballots tick up to their remaining votes
  // (different people) and submit them together.
  function select(name) {
    if (busy || !me || me.remaining <= 0 || (lastResults && !lastResults.open)) return;
    if (me.remaining === 1) {
      selected = selected[0] === name ? [] : [name];
    } else if (selected.includes(name)) {
      selected = selected.filter(n => n !== name);
    } else if (selected.length >= me.remaining) {
      return toast(`You can pick up to ${me.remaining}. Tap a ticked name to remove it.`);
    } else {
      selected = [...selected, name];
    }
    syncSelection();
  }

  function syncSelection() {
    for (const b of $('grid').children) b.setAttribute('aria-pressed', String(selected.includes(b.dataset.name)));
    updateVoteButton();
  }

  function updateVoteButton() {
    const btn = $('vote-btn');
    const n = selected.length;
    const max = me ? me.remaining : 1;
    btn.disabled = !n || busy;
    if (busy) btn.textContent = 'Submitting…';
    else if (max === 1) btn.textContent = n ? `Vote for ${selected[0]}` : 'Select a name to vote';
    else if (!n) btn.textContent = `Pick up to ${max} people`;
    else if (n < max) btn.textContent = `Submit ${n} vote${n > 1 ? 's' : ''} · ${n} of ${max} picked`;
    else btn.textContent = `Submit my ${n} votes`;
  }

  function banner(text, warn) {
    const el = $('banner');
    el.hidden = !text;
    el.textContent = text || '';
    el.classList.toggle('warn', !!warn);
  }

  function renderBallot() {
    const closed = lastResults && !lastResults.open;
    const done = me.remaining <= 0;

    $('ballot').hidden = false;
    $('grid').hidden = done;
    $('thanks').hidden = !done;
    $('votebar').hidden = done || closed;
    for (const b of $('grid').children) b.disabled = closed || done;

    if (me.special) {
      $('ballot-title').textContent = 'Your special ballot';
      $('status').innerHTML = done
        ? 'All <strong>' + me.allowed + '</strong> votes used'
        : '<strong>' + me.remaining + '</strong> of ' + me.allowed + ' votes left';
    } else {
      $('ballot-title').textContent = 'Cast your vote';
      $('status').innerHTML = done ? 'Vote counted' : 'You have <strong>1</strong> vote';
    }
    if (done) {
      $('thanks-sub').textContent = me.special
        ? 'All your votes have been counted anonymously. Watch the results below.'
        : 'Your vote has been counted anonymously. Watch the results below.';
    }

    if (closed) banner('Voting is closed. Thank you, everyone!', true);
    else if (me.special && !done) banner(`Special ballot: tick up to ${me.remaining} different people, then submit them all at once.`);
    else if (me.notice === 'claimed') banner('This special link has already been used on another device. You can still cast a regular vote.', true);
    else if (me.notice === 'invalid') banner('That link isn’t valid. You can still cast a regular vote.', true);
    else banner('');

    const hideResults = !cfg.showResultsBeforeVoting && !done && !closed;
    $('results').hidden = hideResults;
  }

  async function castVote() {
    if (!selected.length || busy) return;
    const names = selected;
    const left = me.remaining - names.length;
    if (left > 0 && me.remaining > 1 &&
        !confirm(`You picked ${names.length} of ${me.remaining}. Submit now? You can use the other ${left} later.`)) return;
    busy = true;
    updateVoteButton();
    const { ok, data } = await api('/api/vote', { method: 'POST', body: JSON.stringify({ candidates: names }) })
      .catch(() => ({ ok: false, data: { error: 'Network problem. Please try again.' } }));
    busy = false;
    if (data.allowed) me = { ...me, ...data, notice: me.notice };
    if (ok) {
      confetti();
      selected = [];
      const what = names.length === 1 ? `Vote for ${names[0]}` : `Votes for ${names.length} people`;
      toast(me.remaining > 0 ? `${what} counted. ${me.remaining} left.` : `${what} counted. Thank you!`);
      if (me.remaining <= 0) window.scrollTo({ top: $('ballot').offsetTop - 12, behavior: 'smooth' });
    } else {
      toast(data.error || 'Something went wrong.');
    }
    syncSelection();
    renderBallot();
  }

  // ---------- results ----------
  const TOP = 5;
  const rowEls = new Map();

  function rowEl(name) {
    let el = rowEls.get(name);
    if (!el) {
      el = document.createElement('li');
      el.className = 'row';
      el.dataset.name = name;
      el.innerHTML = '<span class="rank"></span><span class="name"></span><span class="track"><span class="fill"></span></span><span class="count"><span class="num">0</span><small></small></span>';
      el.querySelector('.name').textContent = name;
      // Clear the vote flash once it ends, or moving the row would replay it.
      el.addEventListener('animationend', e => { if (e.animationName === 'flash') el.classList.remove('bump'); });
      rowEls.set(name, el);
    }
    return el;
  }

  // Shows the top 5 (people with at least one vote). Rows slide to their new
  // place when the order changes, and newcomers slide in.
  function renderResults(r) {
    const prev = lastResults;
    lastResults = r;
    const board = $('board');
    const prevVotes = new Map((prev?.rows || []).map(x => [x.name, x.votes]));
    const sorted = r.rows
      .filter(x => x.votes > 0)
      .sort((a, b) => b.votes - a.votes || cfg.candidates.indexOf(a.name) - cfg.candidates.indexOf(b.name));
    const top = sorted.slice(0, TOP);
    const max = Math.max(1, ...top.map(x => x.votes));

    // FLIP: remember positions before reordering.
    const before = new Map();
    for (const el of board.children) before.set(el.dataset.name, el.getBoundingClientRect().top);
    const keep = new Set(top.map(x => x.name));
    for (const el of [...board.children]) if (!keep.has(el.dataset.name)) el.remove();

    let rank = 0;
    let lastVotes = null;
    top.forEach((row, i) => {
      const el = rowEl(row.name);
      if (row.votes !== lastVotes) { rank = i + 1; lastVotes = row.votes; }
      el.querySelector('.rank').textContent = rank;
      el.classList.toggle('lead', rank === 1);
      for (const k of [1, 2, 3]) el.classList.toggle('r' + k, rank === k);
      const pct = r.total ? Math.round((row.votes / r.total) * 100) : 0;
      tween(el.querySelector('.num'), row.votes);
      el.querySelector('.count small').textContent = pct + '%';
      if (prev && (prevVotes.get(row.name) || 0) < row.votes) {
        el.classList.remove('bump');
        void el.offsetWidth;
        el.classList.add('bump');
      }
      if (board.children[i] !== el) board.insertBefore(el, board.children[i] || null);
    });

    const ease = 'cubic-bezier(.2,.8,.2,1)';
    for (const el of board.children) {
      const old = before.get(el.dataset.name);
      if (old === undefined) {
        el.animate([{ opacity: 0, transform: 'translateX(-32px)' }, { opacity: 1, transform: 'none' }], { duration: 600, easing: ease });
        continue;
      }
      const delta = old - el.getBoundingClientRect().top;
      if (delta) el.animate([{ transform: `translateY(${delta}px)` }, { transform: 'none' }], { duration: 700, easing: ease });
    }
    // Set bar widths after layout so new rows grow from zero.
    requestAnimationFrame(() => {
      for (const row of top) rowEl(row.name).querySelector('.fill').style.width = (row.votes / max) * 100 + '%';
    });

    const cutoff = top.length === TOP ? top[TOP - 1].votes : null;
    const tiedOut = cutoff ? sorted.slice(TOP).filter(x => x.votes === cutoff).length : 0;
    $('more').textContent = tiedOut ? `+${tiedOut} more tied on ${cutoff} vote${cutoff > 1 ? 's' : ''}` : '';
    $('empty').hidden = top.length > 0;
    tween($('total'), r.total);
    $('live').classList.toggle('off', !r.open);
    $('live-text').textContent = r.open ? 'Live' : 'Voting closed';

    if (me && (!prev || prev.open !== r.open)) renderBallot();
  }

  // Live updates via long-polling: the server answers as soon as anyone votes.
  let version = 0;
  let inflight = null;

  // Reload when the page files change (the server reports a build fingerprint),
  // so open tabs such as the projector pick up design updates on their own.
  // Never reloads while someone is part-way through picking or typing.
  let build = null;
  let reloadTimer = null;
  function checkBuild(b) {
    if (!b) return;
    if (!build) build = b;
    if (b === build || reloadTimer) return;
    if (screenMode || (!busy && selected.length === 0)) reloadTimer = setTimeout(() => location.reload(), screenMode ? 0 : 3000);
  }

  async function connectLive() {
    for (;;) {
      inflight = new AbortController();
      const timeout = setTimeout(() => inflight.abort(), 35000);
      try {
        const res = await fetch('/api/results?v=' + version, { signal: inflight.signal, cache: 'no-store' });
        if (!res.ok) throw new Error(res.status);
        const data = await res.json();
        checkBuild(data.build);
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
    $('event').textContent = cfg.event;
    document.title = screenMode ? 'Live Results' : 'Most Inspiring Leader';

    if (screenMode) {
      document.body.classList.add('screen');
      $('results').hidden = false;
      document.querySelector('.foot').hidden = true;
      renderQr();
      connectLive();
      return;
    }

    renderGrid();
    $('vote-btn').addEventListener('click', castVote);
    const q = specialCode ? '?k=' + encodeURIComponent(specialCode) : '';
    me = (await api('/api/me' + q)).data;
    if (specialCode) history.replaceState(null, '', location.pathname);
    $('results').hidden = false;
    renderBallot();
    connectLive();
  }

  boot().catch(err => {
    console.error(err);
    toast('Could not reach the voting server. Please refresh.');
  });
})();
