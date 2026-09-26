// WKC FY27 Retreat — anonymous live voting server.
// Zero dependencies: run with `node server.js` (Node 18+).
//
// Anonymity: votes are stored only as per-candidate tallies. The server separately
// remembers how many votes each *device* has used (a hashed random cookie id), but
// never which candidate a device voted for, so ballots cannot be traced back.

const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const PORT = Number(process.env.PORT) || 3080;
const ROOT = __dirname;
const PUBLIC_DIR = path.join(ROOT, 'public');
const DATA_DIR = path.join(ROOT, 'data');
const STATE_FILE = path.join(DATA_DIR, 'state.json');
const COOKIE = 'wkc_vid';
const ID_RE = /^[a-f0-9]{32}$/;

const config = JSON.parse(fs.readFileSync(path.join(ROOT, 'config.json'), 'utf8'));

// ---------- state ----------

function newCode() {
  return crypto.randomBytes(6).toString('hex');
}

function freshState(prev) {
  const tally = {};
  for (const name of config.candidates) tally[name] = 0;
  return {
    adminKey: prev?.adminKey || crypto.randomBytes(12).toString('hex'),
    open: prev ? prev.open : true,
    tally,
    voters: {}, // deviceHash -> votes used
    special: prev?.special?.map(s => ({ code: s.code, device: null }))
      || Array.from({ length: config.specialBallots }, () => ({ code: newCode(), device: null })),
  };
}

function loadState() {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  if (!fs.existsSync(STATE_FILE)) return freshState();
  const s = JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
  // Keep tallies in sync with config.json if names were added later.
  for (const name of config.candidates) if (!(name in s.tally)) s.tally[name] = 0;
  while (s.special.length < config.specialBallots) s.special.push({ code: newCode(), device: null });
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
  return crypto.createHash('sha256').update('wkc-fy27:' + vid).digest('hex').slice(0, 32);
}

function allowanceFor(dev) {
  return state.special.some(s => s.device === dev) ? config.votesPerSpecialBallot : config.votesPerPerson;
}

function me(dev, notice) {
  const allowed = allowanceFor(dev);
  const used = state.voters[dev] || 0;
  return {
    allowed,
    used,
    remaining: Math.max(0, allowed - used),
    special: allowed === config.votesPerSpecialBallot && allowed !== config.votesPerPerson,
    notice: notice || null,
  };
}

function results() {
  const rows = config.candidates.map(name => ({ name, votes: state.tally[name] || 0 }));
  const total = rows.reduce((n, r) => n + r.votes, 0);
  return { rows, total, voters: Object.keys(state.voters).length, open: state.open, build: assetVersion() };
}

// ---------- live updates (long-polling) ----------
// Clients ask for results newer than the version they have; the request is held
// open until the next vote (or 25s). Unlike SSE, this passes straight through
// Cloudflare Quick Tunnels, which buffer streamed responses.

let version = Date.now(); // differs across restarts, so stale clients refresh at once
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

// The device id lives in a cookie, mirrored in localStorage by the page (sent as a
// header) so clearing just one of them doesn't hand out a fresh vote.
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

function readJson(req) {
  return new Promise((resolve, reject) => {
    let body = '';
    req.on('data', chunk => {
      body += chunk;
      if (body.length > 2048) { reject(new Error('too large')); req.destroy(); }
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

function isAdmin(req) {
  const key = req.headers['x-admin-key'] || '';
  const a = Buffer.from(key);
  const b = Buffer.from(state.adminKey);
  return a.length === b.length && crypto.timingSafeEqual(a, b);
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
      event: config.event,
      candidates: config.candidates,
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

  if (req.method === 'GET' && p === '/api/me') {
    const { vid, dev } = deviceOf(req, res);
    let notice = null;
    const code = url.searchParams.get('k');
    if (code) {
      const ballot = state.special.find(s => s.code === code);
      if (!ballot) notice = 'invalid';
      else if (ballot.device === dev) notice = 'special';
      else if (ballot.device) notice = 'claimed';
      else if (state.special.some(s => s.device === dev)) notice = 'special';
      else {
        ballot.device = dev;
        saveState();
        notice = 'special';
      }
    }
    return send(res, 200, { vid, ...me(dev, notice) });
  }

  if (req.method === 'POST' && p === '/api/vote') {
    const { vid, dev } = deviceOf(req, res);
    let body;
    try { body = await readJson(req); } catch { return send(res, 400, { error: 'Bad request' }); }
    if (!state.open) return send(res, 409, { error: 'Voting is closed.', vid, ...me(dev) });
    // Special ballots submit several different names at once; all-or-nothing.
    const names = Array.isArray(body.candidates) ? body.candidates : [body.candidate];
    if (!names.length || !names.every(n => typeof n === 'string' && config.candidates.includes(n))) {
      return send(res, 400, { error: 'Unknown candidate.', vid, ...me(dev) });
    }
    if (new Set(names).size !== names.length) {
      return send(res, 400, { error: 'Pick different people for each vote.', vid, ...me(dev) });
    }
    const { remaining } = me(dev);
    if (remaining <= 0) {
      return send(res, 409, { error: 'You have already used all your votes on this device.', vid, ...me(dev) });
    }
    if (names.length > remaining) {
      return send(res, 409, { error: `You only have ${remaining} vote${remaining > 1 ? 's' : ''} left.`, vid, ...me(dev) });
    }
    for (const n of names) state.tally[n] += 1;
    state.voters[dev] = (state.voters[dev] || 0) + names.length;
    saveState();
    broadcast();
    return send(res, 200, { ok: true, vid, ...me(dev) });
  }

  // ----- admin -----
  if (p.startsWith('/api/admin/')) {
    if (!isAdmin(req)) return send(res, 403, { error: 'Wrong admin key.' });
    const adminView = () => ({
      ...results(),
      special: state.special.map(s => ({
        code: s.code,
        claimed: !!s.device,
        used: s.device ? state.voters[s.device] || 0 : 0,
      })),
      votesPerSpecialBallot: config.votesPerSpecialBallot,
    });

    if (req.method === 'GET' && p === '/api/admin/info') return send(res, 200, adminView());

    if (req.method === 'POST' && p === '/api/admin/toggle') {
      state.open = !state.open;
      saveState();
      broadcast();
      return send(res, 200, adminView());
    }
    if (req.method === 'POST' && p === '/api/admin/release') {
      let body;
      try { body = await readJson(req); } catch { return send(res, 400, { error: 'Bad request' }); }
      const ballot = state.special.find(s => s.code === body.code);
      if (!ballot) return send(res, 404, { error: 'No such ballot.' });
      ballot.device = null;
      saveState();
      return send(res, 200, adminView());
    }
    if (req.method === 'POST' && p === '/api/admin/reset') {
      state = freshState(state);
      saveState();
      broadcast();
      return send(res, 200, adminView());
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
