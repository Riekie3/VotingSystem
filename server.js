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
  return { rows, total, voters: Object.keys(state.voters).length, open: state.open };
}

// ---------- live stream (Server-Sent Events) ----------

const streams = new Set();

function broadcast() {
  const payload = `data: ${JSON.stringify(results())}\n\n`;
  for (const res of streams) res.write(payload);
}

setInterval(() => {
  for (const res of streams) res.write(': ping\n\n');
}, 20000);

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

function serveStatic(res, file, type) {
  fs.readFile(path.join(PUBLIC_DIR, file), (err, buf) => {
    if (err) return send(res, 404, { error: 'Not found' });
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

  if (req.method === 'GET' && p === '/api/stream') {
    res.writeHead(200, {
      'Content-Type': 'text/event-stream',
      'Cache-Control': 'no-cache, no-transform',
      Connection: 'keep-alive',
      'X-Accel-Buffering': 'no',
    });
    res.write('retry: 3000\n\n');
    res.write(`data: ${JSON.stringify(results())}\n\n`);
    streams.add(res);
    req.on('close', () => streams.delete(res));
    return;
  }

  if (req.method === 'GET' && p === '/api/results') return send(res, 200, results());

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
    const name = body.candidate;
    if (typeof name !== 'string' || !config.candidates.includes(name)) {
      return send(res, 400, { error: 'Unknown candidate.', vid, ...me(dev) });
    }
    if (me(dev).remaining <= 0) {
      return send(res, 409, { error: 'You have already used all your votes on this device.', vid, ...me(dev) });
    }
    state.tally[name] += 1;
    state.voters[dev] = (state.voters[dev] || 0) + 1;
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
