// Regenerates the README screenshots and collages from a FRESH, EMPTY install.
// Uses only demo data (made-up names), never real results.
//
//   php -S 127.0.0.1:18996 -t <fresh copy of platform> <copy>/router.php   (empty database)
//   node docs/screenshots/capture.mjs http://127.0.0.1:18996
// Use an unusual port on 127.0.0.1 so no other local dev server can answer instead.
//
// Needs Chrome or Edge. No npm packages: talks to the browser over the
// DevTools protocol with Node's built-in WebSocket.

import { spawn } from 'node:child_process';
import { mkdtempSync, writeFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { randomBytes, randomInt } from 'node:crypto';

const BASE = (process.argv[2] || 'http://127.0.0.1:18996').replace(/\/$/, '');
const OUT = dirname(fileURLToPath(import.meta.url));
const sleep = ms => new Promise(r => setTimeout(r, ms));
const BROWSERS = [
  'C:/Program Files/Google/Chrome/Application/chrome.exe',
  'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
  '/usr/bin/google-chrome', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
];

// ---------- minimal DevTools client ----------
async function launch() {
  const exe = BROWSERS.find(existsSync);
  if (!exe) throw new Error('Chrome/Edge not found');
  const profile = mkdtempSync(join(tmpdir(), 'vs-shots-'));
  const port = 9333;
  const proc = spawn(exe, [`--remote-debugging-port=${port}`, '--headless=new', `--user-data-dir=${profile}`, '--no-first-run',
    '--no-default-browser-check', '--hide-scrollbars', '--force-color-profile=srgb', '--disable-extensions', 'about:blank'], { stdio: 'ignore' });
  let ws;
  for (let i = 0; i < 50 && !ws; i++) {
    await sleep(200);
    try {
      const list = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
      const page = list.find(t => t.type === 'page');
      if (page) ws = page.webSocketDebuggerUrl;
    } catch { /* not up yet */ }
  }
  if (!ws) throw new Error('Browser did not start');
  const sock = new WebSocket(ws);
  await new Promise((res, rej) => { sock.onopen = res; sock.onerror = rej; });
  let id = 0;
  const pending = new Map();
  const waiters = [];
  sock.onmessage = ev => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) {
      const { res, rej } = pending.get(m.id);
      pending.delete(m.id);
      m.error ? rej(new Error(m.error.message)) : res(m.result);
    } else if (m.method) {
      for (const w of [...waiters]) if (w.method === m.method) { waiters.splice(waiters.indexOf(w), 1); w.res(m.params); }
    }
  };
  const send = (method, params = {}) => new Promise((res, rej) => { const i = ++id; pending.set(i, { res, rej }); sock.send(JSON.stringify({ id: i, method, params })); });
  const once = method => new Promise(res => waiters.push({ method, res }));
  await send('Page.enable');
  await send('Runtime.enable');
  const close = () => { try { sock.close(); } catch {} proc.kill(); setTimeout(() => rmSync(profile, { recursive: true, force: true }), 1500); };
  return { send, once, close };
}

let B;
async function viewport(width, height, dpr = 1, mobile = false, scheme = 'dark') {
  await B.send('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: dpr, mobile });
  await B.send('Emulation.setEmulatedMedia', { features: [{ name: 'prefers-color-scheme', value: scheme }] });
}
async function go(path, wait = 2600) {
  const loaded = B.once('Page.loadEventFired');
  await B.send('Page.navigate', { url: /^[a-z]+:/i.test(path) ? path : BASE + path });
  await loaded;
  await sleep(wait);
}
async function js(expr) {
  const r = await B.send('Runtime.evaluate', { expression: `(async () => { ${expr} })()`, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
  return r.result.value;
}
async function shot(name, { full = false, maxHeight = 2600, fromY = 0 } = {}) {
  let clip;
  if (full || fromY) {
    const h = await js('return Math.max(document.documentElement.scrollHeight, document.body.scrollHeight)');
    const w = await js('return document.documentElement.clientWidth');
    clip = { x: 0, y: fromY, width: w, height: Math.min(h - fromY, maxHeight), scale: 1 };
  }
  const r = await B.send('Page.captureScreenshot', { format: 'jpeg', quality: 86, captureBeyondViewport: !!clip, ...(clip ? { clip } : {}) });
  writeFileSync(join(OUT, name + '.jpg'), Buffer.from(r.data, 'base64'));
  console.log('  📸', name);
}

// ---------- demo data (all made up) ----------
const vid = () => randomBytes(16).toString('hex');
async function vote(slug, picks) {
  const r = await fetch(`${BASE}/api/p/${slug}/vote`, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Voter-Id': vid() }, body: JSON.stringify({ picks }) });
  return r.ok;
}
async function optionIds(slug) {
  return (await (await fetch(`${BASE}/api/p/${slug}`, { headers: { 'X-Voter-Id': vid() } })).json()).poll.options.map(o => o.id);
}

// Runs inside the logged-in browser: create / save polls, run admin actions.
const ADMIN_HELPERS = `
  const token = async p => ((await (await fetch(p)).text()).match(/name="_csrf" value="([a-f0-9]+)"/) || [])[1];
  window.A = {
    async create(type, title, options) {
      const fd = new FormData(); fd.set('_csrf', await token('/admin/polls/new')); fd.set('type', type); fd.set('title', title); fd.set('options', options.join('\\n'));
      const r = await fetch('/admin/polls/new', { method: 'POST', body: fd }); return Number(r.url.match(/(\\d+)$/)[1]);
    },
    async save(id, fields, icons) {
      const doc = new DOMParser().parseFromString(await (await fetch('/admin/polls/' + id)).text(), 'text/html');
      const fd = new FormData(doc.getElementById('poll-form'));
      for (const [k, v] of Object.entries(fields)) v === null ? fd.delete(k) : fd.set(k, v);
      if (icons) { fd.delete('opt_icon_type[]'); fd.delete('opt_icon_value[]'); icons.forEach(([t, v]) => { fd.append('opt_icon_type[]', t); fd.append('opt_icon_value[]', v); }); }
      await fetch('/admin/polls/' + id, { method: 'POST', body: fd });
    },
    async action(id, action) {
      const fd = new FormData(); fd.set('_csrf', await token('/admin/polls/' + id)); fd.set('action', action);
      await fetch('/admin/polls/' + id + '/action', { method: 'POST', body: fd });
    },
    async codes(id, count, votes, label) {
      const fd = new FormData(); fd.set('_csrf', await token('/admin/polls/' + id + '/codes'));
      fd.set('action', 'generate'); fd.set('count', count); fd.set('votes', votes); fd.set('label', label);
      await fetch('/admin/polls/' + id + '/codes', { method: 'POST', body: fd });
    },
  };`;

const PEOPLE = ['Alex Tan', 'Brenda Lim', 'Chandra Kumar', 'Daniel Wong', 'Emily Ng', 'Farah Aziz', 'Gavin Lee', 'Hana Yusof'];
const STRATEGIES = ['Customer First', 'Data-Driven Decisions', 'Move Fast, Learn Faster', 'Own the Outcome', 'Grow Our People', 'Keep It Simple', 'Innovate Every Day', 'One Team'];
const WHY = {
  'Customer First': ['Call three customers every Friday to hear what we can improve.', 'Add a customer quote to every weekly report.'],
  'Data-Driven Decisions': ['Track weekly sell-out per store and share it every Monday.', 'Set up one simple dashboard for my team this quarter.'],
  'Move Fast, Learn Faster': ['Run small two-week pilots before any big rollout.', 'Hold a 15-minute retro after every campaign.'],
  'Own the Outcome': ['Give every project one clear owner and a deadline.', 'Follow up on open issues the same day.'],
  'Grow Our People': ['Monthly 1-to-1 coaching for each team member.', 'Let juniors lead one meeting every week.'],
  'Keep It Simple': ['Cut our approval steps from five to two.', 'One-page proposals only.'],
  'Innovate Every Day': ['Reserve Friday afternoons for testing new ideas.', 'Share one new idea in every team huddle.'],
  'One Team': ['Monthly lunch with the warehouse team.', 'Shared targets between sales and marketing.'],
};

async function main() {
  B = await launch();
  try {
    const pass = 'Demo-' + randomBytes(9).toString('base64url');
    console.log('Installer');
    await viewport(1440, 900, 1, false, 'dark');
    await go('/', 1800);
    await shot('admin-01-installer');
    await js(`
      const f = document.querySelector('form');
      const set = (n, v) => { f.querySelector('[name="' + n + '"]').value = v; };
      set('db_name', 'vs_demo'); set('db_user', 'root'); set('site_title', 'Company Voting');
      set('username', 'admin'); set('password', ${JSON.stringify(pass)}); set('password2', ${JSON.stringify(pass)});
      f.submit();`);
    await sleep(3500);

    console.log('Login + forgot');
    await go('/admin/login', 1200);
    await go('/admin/login', 1200); // second visit: recovery-code banner already shown once
    await shot('admin-02-login');
    await go('/admin/forgot', 1200);
    await shot('admin-03-forgot');
    await go('/admin/login', 800);
    await js(`document.querySelector('[name=username]').value = 'admin'; document.querySelector('[name=password]').value = ${JSON.stringify(pass)}; document.querySelector('form').submit();`);
    await sleep(2500);

    console.log('Seeding demo polls');
    await js(ADMIN_HELPERS + 'return true;');
    const ids = await js(`
      const mvp = await A.create('single', 'Who is our MVP of the Year?', ${JSON.stringify(PEOPLE)});
      await A.save(mvp, { slug: 'mvp', eyebrow: 'Annual Awards 2026', status: 'open', top_n: '5', chart: 'bars' });
      const strat = await A.create('ranked', 'Rank the 3 most valuable strategies from this retreat', ${JSON.stringify(STRATEGIES)});
      await A.save(strat, { slug: 'strategies', eyebrow: 'Leadership Retreat', subtitle: '(For each one, share how you will apply it in your work)',
        status: 'open', picks: '3', points: '3, 2, 1', comment_mode: 'required', comment_label: 'How will you apply it?', answers_wall: '1' });
      const fun = await A.create('multi', 'Which team outing should we do next?', ['Beach day', 'Bowling night', 'Cooking class', 'Rock climbing', 'Karaoke', 'Jungle trekking']);
      await A.save(fun, { slug: 'team-outing', eyebrow: 'Staff Club', subtitle: 'Pick up to 2', status: 'open', picks: '2', chart: 'pie' },
        [['emoji', '🏖️'], ['emoji', '🎳'], ['emoji', '🍳'], ['emoji', '🧗'], ['emoji', '🎤'], ['emoji', '🌿']]);
      const award = await A.create('single', 'Best Department Award', ['Sales', 'Marketing', 'Operations', 'Finance', 'Customer Service']);
      await A.save(award, { slug: 'best-department', eyebrow: 'Annual Awards 2026', status: 'open' },
        [['emoji', '💼'], ['emoji', '📣'], ['emoji', '⚙️'], ['emoji', '📊'], ['emoji', '🎧']]);
      await A.action(award, 'reveal_hide');
      const board = await A.create('single', 'Board members: approve the new office location?', ['Option A — Bangsar South', 'Option B — Petaling Jaya', 'Option C — Stay where we are']);
      await A.save(board, { slug: 'board-vote', eyebrow: 'Board of Directors', status: 'open', access: 'code', results_visibility: 'after_close' });
      await A.codes(mvp, 2, 3, 'Special ballot');
      await A.codes(board, 9, 1, 'Board member');
      const old = await A.create('single', 'Old test poll', ['Yes', 'No']);
      await A.action(old, 'trash');
      return { mvp, strat, fun, award, board };`);

    const mvpIds = await optionIds('mvp');
    const weights = [9, 14, 6, 4, 11, 3, 2, 5];
    for (const [i, n] of weights.entries()) for (let k = 0; k < n; k++) await vote('mvp', [{ option_id: mvpIds[i] }]);
    const sIds = await optionIds('strategies');
    const picksPool = [[0, 1, 3], [1, 0, 2], [0, 3, 4], [2, 1, 0], [4, 0, 1], [1, 3, 0], [0, 2, 7], [3, 0, 1], [1, 4, 6], [0, 1, 5], [7, 0, 2], [1, 0, 3], [5, 1, 0], [0, 6, 1], [2, 0, 4], [1, 7, 0]];
    for (const [n, p] of picksPool.entries()) {
      await vote('strategies', p.map(i => ({ option_id: sIds[i], comment: WHY[STRATEGIES[i]][(n + i) % 2] })));
    }
    const fIds = await optionIds('team-outing');
    for (const p of [[0, 4], [0, 2], [4], [0, 4], [2, 5], [0], [4, 1], [0, 3], [2, 4], [0, 4], [5, 0], [1, 4], [0, 2], [4]]) await vote('team-outing', p.map(i => ({ option_id: fIds[i] })));
    const aIds = await optionIds('best-department');
    for (const [i, n] of [7, 5, 9, 3, 6].entries()) for (let k = 0; k < n; k++) await vote('best-department', [{ option_id: aIds[i] }]);

    console.log('Admin screens');
    await viewport(1440, 900, 1, false, 'dark');
    await go('/admin', 2200); await shot('admin-04-dashboard');
    await go('/admin/polls/new', 1200);
    await js(`document.querySelector('[name=title]').value = 'Who should win Rookie of the Year?';
      document.querySelector('[name=options]').value = ['Iris Chong', 'Jason Ho', 'Kavitha Raj', 'Leon Teo'].join('\\n');`);
    await shot('admin-05-new-poll');
    await go('/admin/polls/' + ids.strat, 3500); await shot('admin-06-editor');
    await js(`document.querySelectorAll('details.section').forEach(d => d.open = true); window.scrollTo(0, document.querySelectorAll('details.section')[1].offsetTop - 20);`);
    await sleep(600); await shot('admin-07-editor-options');
    await go('/admin/polls/' + ids.strat + '/results', 2200); await shot('admin-08-results');
    await go('/admin/polls/' + ids.mvp + '/codes', 1800); await shot('admin-09-access-codes');
    await viewport(1100, 900, 1, false, 'light');
    await go('/admin/polls/' + ids.board + '/codes/print', 2000); await shot('admin-10-qr-cards', { full: true, maxHeight: 1400 });
    await go('/admin/polls/' + ids.strat + '/report', 2000); await shot('admin-11-report', { full: true, maxHeight: 1700 });
    await viewport(1440, 900, 1, false, 'dark');
    await go('/admin/present', 1500); await shot('admin-12-presentation');
    await go('/admin/settings', 1500); await shot('admin-13-settings');
    await go('/admin/trash', 1200); await shot('admin-14-trash');

    console.log('Projector screens');
    await viewport(1920, 1080, 1, false, 'dark');
    for (const mode of ['bars', 'pie', 'both']) {
      await js(`localStorage.setItem('vs_chart_mvp', '${mode}');`);
      await go('/mvp/results', 3200);
      await shot('screen-0' + ({ bars: 1, pie: 2, both: 3 })[mode] + '-' + mode);
    }
    await go('/best-department/results', 2500); await shot('screen-04-reveal-waiting');
    await go('/strategies/results', 3200); await shot('screen-05-ranked');
    await shot('screen-06-answers-wall', { fromY: 1080, maxHeight: 1080 });
    await js(`localStorage.setItem('vs_chart_team-outing', 'pie');`);
    await go('/team-outing/results', 3200); await shot('screen-07-emoji-pie');

    console.log('Phone screens');
    const phone = (scheme) => viewport(390, 844, 2, true, scheme);
    await phone('dark');  await go('/', 1500); await shot('phone-01-home');
    await phone('light'); await go('/mvp', 2500);
    await js(`[...document.querySelectorAll('.cand')][4].click(); window.scrollTo(0, 250);`); await sleep(700); await shot('phone-02-vote');
    await go('/team-outing', 2500);
    await js(`const c = [...document.querySelectorAll('.cand')]; c[0].click(); c[4].click(); window.scrollTo(0, 250);`); await sleep(700); await shot('phone-03-multi-emoji');
    await phone('dark'); await go('/strategies', 2500);
    await js(`const c = [...document.querySelectorAll('.cand')]; c[1].click(); c[0].click(); c[3].click(); await new Promise(r => setTimeout(r, 400));
      const t = [...document.querySelectorAll('.why')]; t[0].value = 'Share one simple dashboard with my team every Monday.'; t[1].value = 'Call three customers each Friday.';
      t.forEach(x => x.dispatchEvent(new Event('input')));
      window.scrollTo(0, document.getElementById('picks-card').offsetTop - 12);`);
    await sleep(900); await shot('phone-04-ranked-comments');
    await phone('light'); await go('/board-vote', 2500); await js(`window.scrollTo(0, 180);`); await sleep(400); await shot('phone-05-access-code');
    await phone('dark'); await js(`localStorage.setItem('vs_chart_mvp', 'pie');`); await go('/mvp', 2500);
    await js(`[...document.querySelectorAll('.cand')][1].click(); document.getElementById('vote-btn').click();`);
    await sleep(4200);
    await js(`window.scrollTo(0, document.getElementById('thanks-card').offsetTop - 12);`); await sleep(1200); await shot('phone-06-thanks-pie');

    console.log('Collages');
    await collages();
  } finally {
    B.close();
  }
}

// ---------- collages ----------
const SETS = {
  phones: {
    title: 'Voting on phones', sub: 'No login, anonymous, light or dark to match each phone',
    cols: 6, width: 2340, items: [
      ['phone-01-home', 'Home: open votes'], ['phone-02-vote', 'Pick one'], ['phone-03-multi-emoji', 'Pick up to N, emoji icons'],
      ['phone-04-ranked-comments', 'Rank top 3 + comments'], ['phone-05-access-code', 'Codes-only vote'], ['phone-06-thanks-pie', 'Thank-you + live pie'],
    ],
  },
  projector: {
    title: 'Projector (big screen)', sub: 'One static screen, updates live, switch Bars / Pie / Both anytime',
    cols: 2, width: 2400, items: [
      ['screen-01-bars', 'Bars: top 5 with medals'], ['screen-02-pie', 'Pie chart'], ['screen-03-both', 'Both side by side'],
      ['screen-04-reveal-waiting', 'Reveal mode: hidden until you press Reveal'], ['screen-05-ranked', 'Ranked poll (points)'],
      ['screen-06-answers-wall', 'Answers wall (scroll down)'], ['screen-07-emoji-pie', 'Emoji options on a pie'],
    ],
  },
  admin: {
    title: 'Admin panel', sub: 'Create, edit, run and export every vote. No code needed',
    cols: 3, width: 2400, items: [
      ['admin-01-installer', 'One-time installer'], ['admin-02-login', 'Admin login'], ['admin-03-forgot', 'Forgot password (recovery code)'],
      ['admin-04-dashboard', 'Dashboard'], ['admin-05-new-poll', 'New voting page'], ['admin-06-editor', 'Poll editor + live preview'],
      ['admin-07-editor-options', 'Options: drag, icons, hide'], ['admin-08-results', 'Results + comment moderation'], ['admin-09-access-codes', 'Access codes / special ballots'],
      ['admin-10-qr-cards', 'Printable QR voting cards'], ['admin-11-report', 'Printable report / PDF'], ['admin-12-presentation', 'Presentation mode'],
      ['admin-13-settings', 'Branding & settings'], ['admin-14-trash', 'Trash with restore'],
    ],
  },
};

async function collages() {
  for (const [key, set] of Object.entries(SETS)) {
    const cells = set.items.map(([f, cap]) => `<figure><div class="frame ${key}"><img src="${f}.jpg"></div><figcaption>${cap}</figcaption></figure>`).join('');
    const html = `<!doctype html><html><head><meta charset="utf-8"><style>
      body { margin: 0; background: radial-gradient(1200px 700px at 10% -10%, rgba(247,148,29,.25), transparent 60%), #0b0d12; color: #f3f1ec;
        font-family: 'Segoe UI', system-ui, sans-serif; padding: 56px 60px 64px; width: ${set.width}px; box-sizing: border-box; }
      h1 { font: 700 54px Georgia, serif; margin: 0 0 6px; } p.sub { margin: 0 0 40px; color: #a3a8b2; font-size: 24px; }
      .grid { display: grid; grid-template-columns: repeat(${set.cols}, 1fr); gap: 34px 30px; align-items: start; }
      figure { margin: 0; } .frame { border-radius: 18px; overflow: hidden; background: #000; box-shadow: 0 20px 50px -20px rgba(0,0,0,.9), 0 0 0 1px rgba(255,255,255,.08); }
      .frame.phones { border-radius: 38px; border: 10px solid #1c1e24; } .frame.admin { max-height: 560px; }
      .frame img { display: block; width: 100%; } .frame.admin img { object-fit: cover; object-position: top; }
      figcaption { margin-top: 14px; font-size: 21px; font-weight: 600; color: #e7e3dc; } figcaption::before { content: '● '; color: #f7941d; }
    </style></head><body><h1>${set.title}</h1><p class="sub">${set.sub}</p><div class="grid">${cells}</div></body></html>`;
    const file = join(OUT, `_collage-${key}.html`);
    writeFileSync(file, html);
    await viewport(set.width, 900, 1, false, 'dark');
    await go(pathToFileURL(file).href, 1500);
    await shot(`collage-${key}`, { full: true, maxHeight: 8000 });
    rmSync(file);
  }
}

main().catch(e => { console.error(e); process.exit(1); });
