/* Voter page: ballot for single / multi / ranked polls, comments, access codes, live results. */
(() => {
  const { $, el, ORD, api, toast, confetti, iconEl, fmtCountdown, createBoard, createPie, chartSwitch, poller } = window.VSC;
  const VS = window.VS;

  let poll = null, me = null, live = null, texts = {};
  let picks = [];                 // option ids in pick / rank order
  const comments = new Map();     // option id -> text (kept if the pick moves)
  let busy = false, board = null, pie = null, results = null, clockTimer = null;

  const optById = id => poll.options.find(o => o.id === id);
  const ranked = () => poll.type === 'ranked';
  // Single-choice with several votes left (special ballots) lets people pick several at once.
  const maxPicks = () => ranked() || poll.type === 'multi' ? poll.picks_max : Math.max(1, me.remaining);
  const minPicks = () => ranked() ? poll.picks_max : 1;
  const wantComments = () => poll.comment_mode !== 'off';
  const isOpen = () => live && live.state === 'open';

  // ---------- ballot ----------
  function renderOptions() {
    const box = $('options');
    box.textContent = '';
    const long = poll.options.some(o => o.label.length > 22 || o.description) || ranked();
    box.classList.toggle('list', long);
    poll.options.forEach((o, i) => {
      const b = el('button', 'cand' + (ranked() ? ' ranked' : ''));
      b.type = 'button';
      b.dataset.id = o.id;
      b.style.setProperty('--i', i);
      b.setAttribute('aria-pressed', 'false');
      if (ranked()) b.append(el('span', 'rank-dot'));
      const ic = iconEl(o.icon);
      if (ic && !(ranked() && o.icon.type === 'initials')) b.append(ic);
      const label = el('span', 'label', o.label);
      if (o.description) label.append(el('span', 'desc', o.description));
      b.append(label);
      b.addEventListener('click', () => toggle(o.id));
      box.append(b);
    });
  }

  function toggle(id) {
    if (busy || !me || me.remaining <= 0 || !isOpen()) return;
    if (picks.includes(id)) {
      picks = picks.filter(x => x !== id);
    } else if (maxPicks() === 1) {
      picks = [id];
    } else if (picks.length >= maxPicks()) {
      return toast(`You can pick up to ${maxPicks()}. Tap a picked option to remove it.`);
    } else {
      picks = [...picks, id];
      if (picks.length === maxPicks() && (ranked() || wantComments())) {
        setTimeout(() => $('picks-card').scrollIntoView({ behavior: 'smooth', block: 'start' }), 150);
      }
    }
    sync();
  }

  function move(i, dir) {
    const j = i + dir;
    if (j < 0 || j >= picks.length) return;
    [picks[i], picks[j]] = [picks[j], picks[i]];
    sync();
  }

  function sync() {
    for (const b of $('options').children) {
      const i = picks.indexOf(Number(b.dataset.id));
      b.setAttribute('aria-pressed', String(i >= 0));
      const dot = b.querySelector('.rank-dot');
      if (dot) dot.textContent = i >= 0 ? i + 1 : '';
    }
    renderPicks();
    updateButton();
    updateStatus();
  }

  function renderPicks() {
    const card = $('picks-card');
    const show = (ranked() || wantComments()) && me.remaining > 0 && !me.needs_code;
    card.hidden = !show;
    if (!show) return;
    $('picks-title').textContent = wantComments() ? (poll.comment_label || 'Tell us why') : 'Your ranking';
    $('why-note').textContent = wantComments() ? (poll.comment_mode === 'required' ? 'Required' : 'Optional') : '';
    $('why-note').hidden = !wantComments();
    const list = $('picks');
    list.textContent = '';
    const slots = ranked() ? poll.picks_max : Math.max(picks.length, 1);
    for (let i = 0; i < slots; i++) {
      const id = picks[i];
      const o = id ? optById(id) : null;
      const li = el('li', 'pick' + (o ? '' : ' empty-slot'));
      const head = el('div', 'pick-head');
      head.append(el('span', 'pick-badge' + (o && ranked() && i < 3 ? ' m' + (i + 1) : ''), ranked() ? ORD[i] : (o ? '✓' : '—')));
      if (!o) {
        head.append(el('span', 'pick-placeholder', ranked() ? 'Tap an option above' : 'Pick an option above'));
        li.append(head);
        list.append(li);
        continue;
      }
      head.append(el('span', 'pick-name', o.label));
      const tools = el('span', 'pick-tools');
      if (ranked()) {
        const up = el('button', 'icon-btn', '↑'); up.type = 'button'; up.disabled = i === 0; up.onclick = () => move(i, -1); up.setAttribute('aria-label', 'Move up');
        const down = el('button', 'icon-btn', '↓'); down.type = 'button'; down.disabled = i === picks.length - 1; down.onclick = () => move(i, 1); down.setAttribute('aria-label', 'Move down');
        tools.append(up, down);
      }
      const rm = el('button', 'icon-btn', '✕'); rm.type = 'button'; rm.onclick = () => toggle(id); rm.setAttribute('aria-label', 'Remove');
      tools.append(rm);
      head.append(tools);
      li.append(head);
      if (wantComments()) {
        const ta = el('textarea', 'why');
        ta.rows = 2;
        ta.maxLength = poll.comment_max;
        ta.required = poll.comment_mode === 'required';
        ta.placeholder = (poll.comment_label || 'Your answer') + (ta.required ? '' : ' (optional)');
        ta.value = comments.get(id) || '';
        ta.addEventListener('input', () => { comments.set(id, ta.value); updateButton(); });
        li.append(ta);
      }
      list.append(li);
    }
  }

  function missingComments() {
    return poll.comment_mode === 'required' ? picks.filter(id => !(comments.get(id) || '').trim()).length : 0;
  }

  function updateButton() {
    const btn = $('vote-btn');
    const n = picks.length, need = minPicks(), max = maxPicks(), miss = missingComments();
    btn.disabled = busy || n < need || n === 0 || miss > 0;
    if (busy) btn.textContent = 'Submitting…';
    else if (n === 0) btn.textContent = ranked() ? `Pick your top ${need}` : (max > 1 ? `Pick up to ${max}` : texts.pick_prompt);
    else if (n < need) btn.textContent = `Pick ${need - n} more · ${n} of ${need}`;
    else if (miss) btn.textContent = `Fill in ${miss} more answer${miss > 1 ? 's' : ''} to submit`;
    else if (n === 1 && !ranked()) btn.textContent = texts.submit_one.replace('{choice}', optById(picks[0]).label);
    else btn.textContent = texts.submit_many + (max > n && !ranked() ? ` · ${n} of ${max}` : '');
  }

  function updateStatus() {
    const s = $('status');
    if (me.needs_code) { s.textContent = ''; return; }
    if (me.remaining <= 0) { s.textContent = 'Done'; return; }
    if (ranked() || poll.type === 'multi') s.innerHTML = `<strong>${picks.length}</strong> of ${maxPicks()} picked`;
    else if (me.allowed > 1) s.innerHTML = `<strong>${me.remaining}</strong> of ${me.allowed} votes left`;
    else s.innerHTML = 'You have <strong>1</strong> vote';
  }

  function banner(text, kind) {
    const b = $('banner');
    b.hidden = !text;
    b.textContent = text || '';
    b.className = 'banner' + (kind ? ' ' + kind : '');
  }

  function renderState() {
    const done = me.remaining <= 0 && !me.needs_code;
    const state = live.state;
    $('ballot').hidden = done;
    $('thanks-card').hidden = !done;
    $('ballot-title').textContent = ranked() ? texts.ranked_title : texts.ballot_title;
    $('code-box').hidden = !me.needs_code;
    $('options').hidden = me.needs_code;
    $('votebar').hidden = done || me.needs_code || state !== 'open' || VS.preview;
    for (const b of $('options').children) b.disabled = state !== 'open' || done;
    renderPicks();
    updateStatus();
    updateButton();
    clearInterval(clockTimer);
    const skew = Date.now() - live.server_now;
    const tickBanner = () => {
      const now = Date.now() - skew;
      if (VS.preview && state !== 'draft') banner('Preview — voting is switched off here so no real votes are recorded.', 'warn');
      else if (state === 'draft') banner('Preview — this poll is not published yet, so votes are not accepted.', 'warn');
      else if (state === 'scheduled') banner(live.opens_at ? `${texts.opens_in} ${fmtCountdown(live.opens_at - now)}` : texts.not_open_yet);
      else if (state === 'closed') banner(texts.closed_message, 'warn');
      else if (me.notice) banner(me.notice, me.notice_ok ? 'good' : 'warn');
      else if (live.closes_at && live.closes_at - now < 86400000) banner(`${texts.closes_in} ${fmtCountdown(live.closes_at - now)}`);
      else if (me.allowed > 1 && !done && poll.type === 'single') banner(`You have ${me.remaining} votes. Pick up to ${me.remaining} different options and submit them together.`);
      else banner('');
      if ((state === 'scheduled' && live.opens_at && live.opens_at <= now) || (state === 'open' && live.closes_at && live.closes_at <= now)) pollNow();
    };
    tickBanner();
    if (state === 'scheduled' || (state === 'open' && live.closes_at)) clockTimer = setInterval(tickBanner, 1000);
  }

  async function submit() {
    if (busy) return;
    busy = true;
    updateButton();
    const body = { picks: picks.map(id => ({ option_id: id, comment: (comments.get(id) || '').trim() })) };
    const { ok, data } = await api(VS.api + '/vote', { method: 'POST', body: JSON.stringify(body) })
      .catch(() => ({ ok: false, data: { error: 'Network problem. Please try again.' } }));
    busy = false;
    if (data.me) me = data.me;
    if (ok) {
      confetti();
      picks = [];
      comments.clear();
      toast(me.remaining > 0 ? `Counted! ${me.remaining} vote${me.remaining > 1 ? 's' : ''} left.` : texts.thanks_title);
      window.scrollTo({ top: 0, behavior: 'smooth' });
      pollNow();
    } else {
      toast(data.error || 'Something went wrong.');
    }
    sync();
    renderState();
  }

  // ---------- results ----------
  function renderResults(data) {
    const card = $('results');
    const res = data.results;
    const hiddenReason = !res;
    card.hidden = false;
    $('results-hidden').hidden = !hiddenReason;
    $('viz').hidden = hiddenReason;
    $('totals').hidden = hiddenReason;
    $('reveal-wait').hidden = true;
    if (hiddenReason) { $('empty').hidden = true; return; }
    if (!board) {
      pie = createPie({ host: $('pie'), topN: poll.top_n });
      board = createBoard({ board: $('board'), empty: $('empty'), total: $('total'), more: $('more'), topN: poll.top_n, colorOf: pie.colorOf });
      chartSwitch({ toggle: $('chart-toggle'), viz: $('viz'), slug: poll.slug, initial: poll.chart });
      $('top-n').textContent = `Top ${poll.top_n}`;
      $('total-label').textContent = 'responses';
    }
    results = res;
    board.render(res);
    pie.render(res);
    $('viz').classList.toggle('no-data', !res.rows.some(r => r.score > 0));
  }

  let pollNow = () => {};

  function applyLive(data) {
    const changed = !live || live.state !== data.live.state || live.reveal !== data.live.reveal;
    live = data.live;
    if (data.me) me = data.me;
    $('live').classList.toggle('off', live.state !== 'open');
    $('live-text').textContent = live.state === 'open' ? 'Live' : live.state === 'closed' ? 'Closed' : 'Soon';
    if (changed) renderState();
  }

  // ---------- boot ----------
  async function boot() {
    const params = new URLSearchParams(location.search);
    const code = params.get('k');
    const { ok, data } = await api(VS.api + (code ? '?k=' + encodeURIComponent(code) : ''));
    if (!ok) { toast(data.error || 'Could not load this vote.'); return; }
    if (code) { params.delete('k'); history.replaceState(null, '', location.pathname + (params.toString() ? '?' + params : '')); }
    poll = data.poll; me = data.me; live = data.live; texts = poll.texts;
    renderOptions();
    sync();
    renderState();
    $('vote-btn').addEventListener('click', submit);
    $('code-form').addEventListener('submit', async e => {
      e.preventDefault();
      const r = await api(VS.api + '/code', { method: 'POST', body: JSON.stringify({ code: $('code-input').value }) });
      if (r.data.me) me = r.data.me;
      toast(r.data.me?.notice || (r.ok ? 'Code accepted.' : 'That code did not work.'));
      sync();
      renderState();
    });
    const p = poller(VS.api + '/results', (d, fresh) => {
      applyLive(d);
      if (fresh) renderResults(d);
    }, { every: 3000, safeToReload: () => !busy && picks.length === 0 && ![...comments.values()].some(v => v.trim()) });
    pollNow = p.now;
  }

  boot().catch(err => { console.error(err); toast('Could not reach the voting server. Please refresh.'); });
})();
