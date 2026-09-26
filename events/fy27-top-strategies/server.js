// WKC FY27 Retreat — anonymous live "rank your top 3 strategies" vote.
// Zero dependencies: run with `node server.js` (Node 18+).
//
// Anonymity: the server keeps per-strategy point totals and the written
// explanations, but never links a response to a device. Devices are only
// remembered (as a hashed random cookie id) so each can respond once.

const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const PORT = Number(process.env.PORT) || 3081;
const ROOT = __dirname;
const PUBLIC_DIR = path.join(ROOT, 'public');
const DATA_DIR = path.join(ROOT, 'data');
const STATE_FILE = path.join(DATA_DIR, 'state.json');
const COOKIE = 'wkc_rank_vid';
const ID_RE = /^[a-f0-9]{32}$/;

const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'config.json'), 'utf8'));

// ---------- state ----------

function emptyTally() {
  const tally = {};
  for (const name of config.options) tally[name] = { points: 0, ranks: config.points.map(() => 0) };
  return tally;
}

function freshState(prev) {
  return {
    adminKey: prev?.adminKey || crypto.randomBytes(12).toString('hex'),
    open: prev ? prev.open : true,
    tally: emptyTally(),
    voters: {}, // deviceHash -> 1
    responses: [], // [{ ranking: [{ name, why }] }] in random order, no device link
  };
}

function loadState() {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  if (!fs.existsSync(STATE_FILE)) return freshState();
  const s = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
  const blank = emptyTally();
  for (const name of config.options) if (!(name in s.tally)) s.tally[name] = blank[name];
  return s;
}

let state = loadState();

function saveState() {
  const tmp = STATE_FILE + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(state, null, 2));
  fs.renameSync(tmp, STATE_FILE);
}
saveState();

function hashDevice(vid) {
  return crypto.createHash('sha256').update('wkc-fy27-rank:' + vid).digest('hex').slice(0, 32);
}

function me(dev) {
  return { voted: !!state.voters[dev] };
}

function results() {
  const rows = config.options.map(name => ({
    name,
    points: state.tally[name]?.points || 0,
    ranks: state.tally[name]?.ranks || config.points.map(() => 0),
  }));
  return { rows, responses: Object.keys(state.voters).length, open: state.open, build: assetVersion() };
}

// ---------- live updates (long-polling) ----------
// Clients ask for results newer than the version they have; the request is held
// open until the next response (or 25s). Cloudflare Quick Tunnels buffer SSE,
// but pass these through immediately.

let version = Date.now();
const waiters = new Set();

function broadcast() {
  version += 1;
  const body = JSON.stringify({ version, ...results() });
  for (const w of waiters) w(body);
  waiters.clear();
}

// ---------- http helpers ----------

function parseCookies(req) {
  const out = {};
  for (const part of (req.headers.cookie || '').split(';')) {
    const i = part.indexOf('=');
    if (i > 0) out[part.slice(0, i).trim()] = decodeURIComponent(part.slice(i + 1).trim());
  }
  return out;
}

// Device id lives in a cookie, mirrored in localStorage by the page (sent as a
// header) so clearing just one of them doesn't allow a second response.
function deviceOf(req, res) {
  let vid = parseCookies(req)[COOKIE];
  if (!ID_RE.test(vid || '')) vid = req.headers['x-voter-id'];
  if (!ID_RE.test(vid || '')) vid = crypto.randomBytes(16).toString('hex');
  const secure = req.headers['x-forwarded-proto'] === 'https' ? '; Secure' : '';
  res.setHeader('Set-Cookie', `${COOKIE}=${vid}; Path=/; Max-Age=31536000; HttpOnly; SameSite=Lax${secure}`);
  return { vid, dev: hashDevice(vid) };
}

function send(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
  res.end(JSON.stringify(body));
}

function readJson(req, limit = 8192) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', chunk => {
      body += chunk;
      if (body.length > limit) { reject(new Error('too large')); req.destroy(); }
    });
    req.on('end', () => {
      try { resolve(body ? JSON.parse(body) : {}); } catch (e) { reject(e); }
    });
  });
}

const STATIC = {
  '/': ['index.html', 'text/html; charset=utf-8'],
  '/results': ['index.html', 'text/html; charset=utf-8'],
  '/admin': ['admin.html', 'text/html; charset=utf-8'],
  '/style.css': ['style.css', 'text/css; charset=utf-8'],
  '/rank.css': ['rank.css', 'text/css; charset=utf-8'],
  '/app.js': ['app.js', 'text/javascript; charset=utf-8'],
  '/admin.js': ['admin.js', 'text/javascript; charset=utf-8'],
  '/logo.png': ['logo.png', 'image/png'],
};

// Fingerprint of the page files. Pages compare it on every live update and
// reload themselves when it changes, so open tabs (e.g. the projector) always
// show the latest design; HTML also gets ?v= on its assets to defeat caching.
let assetCache = { at: 0, v: '' };
function assetVersion() {
  if (Date.now() - assetCache.at < 2000) return assetCache.v;
  const h = crypto.createHash('sha1');
  for (const f of fs.readdirSync(PUBLIC_DIR).sort()) {
    const st = fs.statSync(path.join(PUBLIC_DIR, f));
    h.update(`${f}:${st.size}:${st.mtimeMs}`);
  }
  assetCache = { at: Date.now(), v: h.digest('hex').slice(0, 10) };
  return assetCache.v;
}

function serveStatic(res, file, type) {
  fs.readFile(path.join(PUBLIC_DIR, file), (err, buf) => {
    if (err) return send(res, 404, { error: 'Not found' });
    if (type.startsWith('text/html')) {
      const v = assetVersion();
      const html = buf.toString('utf8').replace(/(href|src)="\/([\w-]+\.(?:css|js))"/g,`$1="/$2?v=${v}"`);
      res.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-store' });
      return res.end(html);
    }
    res.writeHead(200, { 'Content-Type': type, 'Cache-Control': 'no-cache' });
    res.end(buf);
  });
}

function isAdmin(req, url) {
  const key = req.headers['x-admin-key'] || url.searchParams.get('key') || '';
  const a = Buffer.from(key);
  const b = Buffer.from(state.adminKey);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
}

function csvCell(v) {
  const s = String(v ?? '');
  return /[",\n\r]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
}

// ---------- routes ----------

async function handle(req, res) {
  const url = new URL(req.url, 'http://localhost');
  const p = url.pathname;
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('Referrer-Policy', 'no-referrer');

  if (req.method === 'GET' && STATIC[p]) return serveStatic(res, ...STATIC[p]);

  if (req.method === 'GET' && p === '/api/config') {
    return send(res, 200, {
      title: config.title,
      subtitle: config.subtitle,
      event: config.event,
      options: config.options,
      picks: config.picks,
      points: config.points,
      requireExplanation: config.requireExplanation,
      maxExplanation: config.maxExplanation,
      showResultsBeforeVoting: config.showResultsBeforeVoting,
    });
  }

  if (req.method === 'GET' && p === '/api/results') {
    const since = Number(url.searchParams.get('v'));
    if (since !== version) return send(res, 200, { version, ...results() });
    const reply = body => {
      clearTimeout(timer);
      if (res.writableEnded) return;
      res.writeHead(200, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store' });
      res.end(body);
    };
    const timer = setTimeout(() => {
      waiters.delete(reply);
      reply(JSON.stringify({ version, ...results() }));
    }, 25000);
    waiters.add(reply);
    req.on('close', () => { clearTimeout(timer); waiters.delete(reply); });
    return;
  }

  // Explanations grouped by strategy, for the section below the projector view.
  if (req.method === 'GET' && p === '/api/responses') {
    const rows = results().rows;
    const groups = new Map(rows.map(r => [r.name, { name: r.name, points: r.points, ranks: r.ranks, items: [] }]));
    for (const resp of state.responses) {
      resp.ranking.forEach((x, i) => { if (x.why) groups.get(x.name)?.items.push({ rank: i, why: x.why }); });
    }
    const list = [...groups.values()]
      .filter(g => g.items.length)
      .sort((a, b) => b.points - a.points || b.ranks[0] - a.ranks[0] || config.options.indexOf(a.name) - config.options.indexOf(b.name));
    for (const g of list) g.items.sort((a, b) => a.rank - b.rank);
    return send(res, 200, { groups: list.map(({ ranks, ...g }) => g) });
  }

  if (req.method === 'GET' && p === '/api/me') {
    const { vid, dev } = deviceOf(req, res);
    return send(res, 200, { vid, ...me(dev) });
  }

  if (req.method === 'POST' && p === '/api/vote') {
    const { vid, dev } = deviceOf(req, res);
    let body;
    try { body = await readJson(req); } catch { return send(res, 400, { error: 'Bad request' }); }
    if (!state.open) return send(res, 409, { error: 'Voting is closed.', vid, ...me(dev) });
    if (state.voters[dev]) return send(res, 409, { error: 'You have already submitted your ranking on this device.', vid, ...me(dev) });

    const ranking = Array.isArray(body.ranking) ? body.ranking : [];
    const names = ranking.map(r => r && r.name);
    if (ranking.length !== config.picks || !names.every(n => typeof n === 'string' && config.options.includes(n))) {
      return send(res, 400, { error: `Please rank exactly ${config.picks} strategies.`, vid, ...me(dev) });
    }
    if (new Set(names).size !== names.length) {
      return send(res, 400, { error: 'Each rank needs a different strategy.', vid, ...me(dev) });
    }
    const whys = ranking.map(r => (typeof r.why === 'string' ? r.why.trim().slice(0, config.maxExplanation) : ''));
    if (config.requireExplanation && whys.some(w => !w)) {
      return send(res, 400, { error: 'Please explain how you will apply each strategy.', vid, ...me(dev) });
    }

    names.forEach((n, i) => {
      state.tally[n].points += config.points[i];
      state.tally[n].ranks[i] += 1;
    });
    // Insert at a random position so response order can't be matched to vote time.
    const entry = { ranking: names.map((name, i) => ({ name, why: whys[i] })) };
    state.responses.splice(Math.floor(Math.random() * (state.responses.length + 1)), 0, entry);
    state.voters[dev] = 1;
    saveState();
    broadcast();
    return send(res, 200, { ok: true, vid, ...me(dev) });
  }

  // ----- admin -----
  if (p.startsWith('/api/admin/')) {
    if (!isAdmin(req, url)) return send(res, 403, { error: 'Wrong admin key.' });

    if (req.method === 'GET' && p === '/api/admin/info') {
      return send(res, 200, { ...results(), responsesList: state.responses });
    }
    if (req.method === 'GET' && p === '/api/admin/export.csv') {
      const head = [];
      for (let i = 1; i <= config.picks; i++) head.push(`Rank ${i}`, `Rank ${i} - how I will apply it`);
      const lines = [head.map(csvCell).join(',')];
      for (const r of state.responses) lines.push(r.ranking.flatMap(x => [x.name, x.why]).map(csvCell).join(','));
      lines.push('', ['Strategy', 'Points', ...config.points.map((_, i) => `# ranked ${i + 1}`)].map(csvCell).join(','));
      for (const row of results().rows.sort((a, b) => b.points - a.points)) {
        lines.push([row.name, row.points, ...row.ranks].map(csvCell).join(','));
      }
      res.writeHead(200, {
        'Content-Type': 'text/csv; charset=utf-8',
        'Content-Disposition': 'attachment; filename="strategy-ranking-responses.csv"',
        'Cache-Control': 'no-store',
      });
      return res.end('﻿' + lines.join('\r\n'));
    }
    if (req.method === 'POST' && p === '/api/admin/toggle') {
      state.open = !state.open;
      saveState();
      broadcast();
      return send(res, 200, { ...results(), responsesList: state.responses });
    }
    if (req.method === 'POST' && p === '/api/admin/reset') {
      state = freshState(state);
      saveState();
      broadcast();
      return send(res, 200, { ...results(), responsesList: state.responses });
    }
  }

  send(res, 404, { error: 'Not found' });
}

http.createServer((req, res) => {
  handle(req, res).catch(err => {
    console.error(err);
    if (!res.headersSent) send(res, 500, { error: 'Server error' });
  });
}).listen(PORT, () => {
  console.log(`\n  Voting page : http://localhost:${PORT}/`);
  console.log(`  Big screen  : http://localhost:${PORT}/results`);
  console.log(`  Admin       : http://localhost:${PORT}/admin?key=${state.adminKey}\n`);
});
