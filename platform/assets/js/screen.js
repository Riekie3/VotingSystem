/* Projector page: one static screen with live top-N, QR, countdown, reveal mode and the answers wall. */
(() => {
  const { $, el, ORD, api, confetti, fmtCountdown, createBoard, createPie, chartSwitch, poller } = window.VSC;
  const VS = window.VS;

  let poll = null, texts = {}, live = null, board = null, pie = null, chart = null, clockTimer = null;
  let revealing = false, lastReveal = null, answersVersion = -1;
  const seen = new Set();

  function renderQr() {
    const url = poll.vote_url;
    $('qr-url').textContent = url.replace(/^https?:\/\//, '').replace(/\/$/, '');
    // The QR library loads in the background; draw the code as soon as it arrives.
    if (!window.qrcode) { $('qrlib')?.addEventListener('load', renderQr, { once: true }); return; }
    {
      const qr = qrcode(0, 'M');
      qr.addData(url);
      qr.make();
      $('qr').innerHTML = qr.createSvgTag({ cellSize: 6, margin: 0, scalable: true });
    }
  }

  function renderClock() {
    clearInterval(clockTimer);
    const chip = $('screen-countdown');
    const skew = Date.now() - live.server_now;
    const tick = () => {
      const now = Date.now() - skew;
      if (live.state === 'scheduled' && live.opens_at) { chip.hidden = false; chip.textContent = `${texts.opens_in} ${fmtCountdown(live.opens_at - now)}`; }
      else if (live.state === 'open' && live.closes_at) { chip.hidden = false; chip.textContent = `${texts.closes_in} ${fmtCountdown(live.closes_at - now)}`; }
      else chip.hidden = true;
    };
    tick();
    clockTimer = setInterval(tick, 1000);
  }

  function showWaiting(responses) {
    $('reveal-wait').hidden = false;
    $('viz').hidden = true;
    $('empty').hidden = true;
    $('reveal-count').textContent = responses ?? 0;
  }

  /** Reveal: show rows from last place up to 1st, with a pause before the winner. */
  async function playReveal(res) {
    revealing = true;
    const sleep = ms => new Promise(r => setTimeout(r, ms));
    $('reveal-wait').hidden = true;
    $('viz').hidden = false;
    if (chart.get() === 'pie') {
      // Pie: a short pause, then the slices grow in and the winner gets confetti.
      $('pie').style.visibility = 'hidden';
      await sleep(900);
      $('pie').style.visibility = '';
      pie.render(res);
      board.render(res);
      await sleep(1200);
      confetti(true);
      revealing = false;
      return;
    }
    pie.render(res);
    const rows = board.render(res, { animateIn: true });
    rows.forEach(r => { r.style.visibility = 'hidden'; });
    await sleep(600);
    for (let i = rows.length - 1; i >= 0; i--) {
      await sleep(i === 0 ? 2200 : 1300);
      rows[i].style.visibility = '';
      rows[i].classList.add('reveal-in');
      if (i === 0) confetti(true);
    }
    revealing = false;
  }

  function renderResults(data) {
    const res = data.results;
    $('results').hidden = false;
    if (!board) {
      pie = createPie({ host: $('pie'), topN: poll.top_n });
      board = createBoard({ board: $('board'), empty: $('empty'), total: $('total'), more: $('more'), topN: poll.top_n, colorOf: pie.colorOf });
      $('top-n').textContent = `Top ${poll.top_n}`;
    }
    if (live.reveal === 'hidden' || !res) { showWaiting(data.responses); lastReveal = live.reveal; return; }
    $('reveal-wait').hidden = true;
    $('viz').hidden = false;
    $('viz').classList.toggle('no-data', !res.rows.some(r => r.score > 0));
    $('total-label').textContent = 'responses';
    if (lastReveal === 'hidden' && live.reveal === 'revealed' && !revealing) { lastReveal = live.reveal; playReveal(res); return; }
    lastReveal = live.reveal;
    if (!revealing) { board.render(res); pie.render(res); }
  }

  async function loadAnswers() {
    if (!poll.answers_wall || poll.comment_mode === 'off') return;
    const { ok, data } = await api(VS.api + '/answers');
    if (!ok) return;
    const groups = data.groups || [];
    $('applies').hidden = groups.length === 0;
    $('scroll-hint').hidden = groups.length === 0;
    const total = groups.reduce((n, g) => n + g.items.length, 0);
    $('applies-count').textContent = `${total} answer${total === 1 ? '' : 's'}`;
    const box = $('groups');
    const first = seen.size === 0;
    box.textContent = '';
    for (const g of groups) {
      const card = el('article', 'group');
      const head = el('div', 'group-head');
      head.append(el('span', 'group-name', g.label), el('span', 'group-meta', `${g.score} ${poll.type === 'ranked' ? 'pts' : 'votes'} · ${g.items.length}`));
      const ul = el('ul');
      for (const it of g.items) {
        const li = el('li', !first && !seen.has(it.id) ? 'new' : null);
        seen.add(it.id);
        if (poll.type === 'ranked') li.append(el('b', it.rank <= 3 ? 'm' + it.rank : null, ORD[it.rank - 1]));
        li.append(document.createTextNode(it.text));
        ul.append(li);
      }
      card.append(head, ul);
      box.append(card);
    }
  }

  async function boot() {
    const { ok, data } = await api(VS.api);
    if (!ok) return;
    poll = data.poll; texts = poll.texts; live = data.live;
    document.title = 'Live results · ' + poll.title;
    renderQr();
    renderClock();
    chart = chartSwitch({ toggle: $('chart-toggle'), viz: $('viz'), slug: poll.slug, initial: poll.chart });
    lastReveal = live.reveal;
    poller(VS.api + '/results?view=screen', (d, fresh) => {
      const clockChanged = d.live.state !== live.state || d.live.closes_at !== live.closes_at || d.live.opens_at !== live.opens_at;
      live = d.live;
      $('live').classList.toggle('off', live.state !== 'open');
      $('live-text').textContent = live.state === 'open' ? 'Live' : live.state === 'closed' ? 'Voting closed' : live.state === 'scheduled' ? 'Opening soon' : 'Draft';
      if (clockChanged) renderClock();
      if (fresh) {
        renderResults(d);
        if (live.version !== answersVersion) { answersVersion = live.version; loadAnswers(); }
      }
    }, { every: 1500, safeToReload: () => !revealing });
  }

  boot().catch(err => console.error(err));
})();
