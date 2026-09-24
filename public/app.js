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
  const rowEls = new Map();

  function renderResults(r) {
    const prev = lastResults;
    lastResults = r;
    const board = $('board');
    const max = Math.max(1, ...r.rows.map(x => x.votes));
    const prevVotes = new Map((prev?.rows || []).map(x => [x.name, x.votes]));
    const sorted = [...r.rows].sort((a, b) => b.votes - a.votes || cfg.candidates.indexOf(a.name) - cfg.candidates.indexOf(b.name));

    // FLIP: remember positions before reordering.
    const before = new Map();
    for (const [name, el] of rowEls) before.set(name, el.getBoundingClientRect().top);

    let rank = 0;
    let lastVotes = null;
    sorted.forEach((row, i) => {
      let el = rowEls.get(row.name);
      if (!el) {
        el = document.createElement('li');
        el.className = 'row';
        el.innerHTML = '<span class="rank"></span><span class="name"></span><span class="track"><span class="fill"></span></span><span class="count"></span>';
        el.querySelector('.name').textContent = row.name;
        rowEls.set(row.name, el);
      }
      if (row.votes !== lastVotes) { rank = i + 1; lastVotes = row.votes; }
      el.querySelector('.rank').textContent = rank;
      el.querySelector('.fill').style.width = (row.votes / max) * 100 + '%';
      const pct = r.total ? Math.round((row.votes / r.total) * 100) : 0;
      const count = el.querySelector('.count');
      count.textContent = row.votes;
      const small = document.createElement('small');
      small.textContent = pct + '%';
      count.append(small);
      el.classList.toggle('lead', row.votes > 0 && row.votes === sorted[0].votes);
      if (prev && prevVotes.get(row.name) !== undefined && prevVotes.get(row.name) < row.votes) {
        el.classList.remove('bump');
        void el.offsetWidth;
        el.classList.add('bump');
      }
      board.append(el);
    });

    for (const [name, el] of rowEls) {
      const old = before.get(name);
      if (old === undefined) continue;
      const delta = old - el.getBoundingClientRect().top;
      if (!delta) continue;
      el.animate([{ transform: `translateY(${delta}px)` }, { transform: 'none' }], { duration: 500, easing: 'cubic-bezier(.2,.8,.2,1)' });
    }

    $('total').textContent = r.total;
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
