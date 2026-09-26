<?php
// Core: config, database, helpers, settings, editable texts, CSRF, admin auth.

declare(strict_types=1);

const APP_VERSION = '1.0.0';
const RESERVED_SLUGS = ['admin', 'api', 'install', 'present', 'assets', 'uploads', 'storage', 'src', 'sql', 'views', 'tools'];

define('ROOT', dirname(__DIR__));
define('CONFIG_FILE', ROOT . '/config.php');
define('UPLOAD_DIR', ROOT . '/uploads');
define('STORAGE_DIR', ROOT . '/storage');

function config(?string $key = null, $default = null)
{
    static $cfg = null;
    if ($cfg === null) $cfg = is_file(CONFIG_FILE) ? (require CONFIG_FILE) : [];
    if ($key === null) return $cfg;
    return $cfg[$key] ?? $default;
}

function installed(): bool
{
    return is_file(CONFIG_FILE);
}

// ---------- request / response ----------

function base_path(): string
{
    static $base = null;
    if ($base === null) {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        $base = rtrim($dir === '.' ? '' : $dir, '/');
    }
    return $base;
}

function url(string $path = ''): string
{
    return base_path() . '/' . ltrim($path, '/');
}

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
}

function abs_url(string $path = ''): string
{
    $host = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    return (is_https() ? 'https' : 'http') . '://' . $host . url($path);
}

function e($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Make pasted text safe: valid UTF-8, no control characters, trimmed. */
function clean_text(string $s, bool $singleLine = true): string
{
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'Windows-1252'); // e.g. text pasted from older Excel/Windows apps
    }
    $s = preg_replace($singleLine ? '/[\x00-\x1F\x7F]+/u' : '/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]+/u', $singleLine ? ' ' : '', $s) ?? '';
    return trim($s);
}

/** One pasted list line without its bullet or number: "-JUSTIN", "• Jade", "3 EnE via AI", "4) Be Agile". */
function clean_list_item(string $line): string
{
    $line = clean_text($line);
    return trim(preg_replace('/^(?:[-*•·]+\s*|\d+\s*[.)\-:]\s*|\d+\s+)/u', '', $line) ?? $line);
}

function json_out($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('~^https?://~', $path) ? $path : url($path)));
    exit;
}

function request_json(): array
{
    $raw = file_get_contents('php://input', false, null, 0, 65536) ?: '';
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function client_ip(): string
{
    return substr($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 64);
}

function view(string $name, array $vars = []): void
{
    extract($vars, EXTR_SKIP);
    require ROOT . '/views/' . $name . '.php';
}

function not_found(string $message = 'Page not found'): void
{
    http_response_code(404);
    view('message', ['title' => 'Not found', 'message' => $message]);
    exit;
}

// ---------- database ----------

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) ($c['port'] ?? 3306), $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function val(string $sql, array $params = [])
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function audit(string $action, string $detail = ''): void
{
    q('INSERT INTO audit_log (action, detail, created_at) VALUES (?, ?, ?)', [$action, mb_substr($detail, 0, 500), now()]);
}

// ---------- settings & branding ----------

const SETTING_DEFAULTS = [
    'site_title'    => 'WKC Voting',
    'org_name'      => 'WKC',
    'logo_media_id' => '',
    'favicon_media_id' => '',
    'accent'        => '#f7941d',
    'font_pair'     => 'classic',
    'phone_theme'   => 'auto',   // auto | light | dark
    'screen_theme'  => 'dark',   // dark | light (projector)
    'home_mode'     => 'list',   // list | redirect | blank
    'home_slug'     => '',
    'texts'         => '{}',
];

const FONT_PAIRS = [
    'classic'   => ['label' => 'Classic — Playfair Display + Plus Jakarta Sans', 'display' => 'Playfair Display', 'ui' => 'Plus Jakarta Sans', 'css' => 'family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Playfair+Display:wght@600;700'],
    'modern'    => ['label' => 'Modern — Plus Jakarta Sans only', 'display' => 'Plus Jakarta Sans', 'ui' => 'Plus Jakarta Sans', 'css' => 'family=Plus+Jakarta+Sans:wght@400;500;600;700;800'],
    'editorial' => ['label' => 'Editorial — Fraunces + Inter', 'display' => 'Fraunces', 'ui' => 'Inter', 'css' => 'family=Inter:wght@400;500;600;700;800&family=Fraunces:opsz,wght@9..144,600;9..144,700'],
    'rounded'   => ['label' => 'Friendly — Nunito', 'display' => 'Nunito', 'ui' => 'Nunito', 'css' => 'family=Nunito:wght@400;600;700;800;900'],
];

// Every fixed piece of wording on the public pages. Editable globally in
// Settings, and per poll in the poll editor.
const TEXT_DEFAULTS = [
    'anonymous_note'  => ['Anonymity line under the title', 'Completely anonymous · no login · results update live'],
    'ballot_title'    => ['Ballot heading (pick one / pick many)', 'Cast your vote'],
    'ranked_title'    => ['Ballot heading (ranked polls)', 'Tap in order of value'],
    'submit_one'      => ['Submit button (one pick)', 'Vote for {choice}'],
    'submit_many'     => ['Submit button (several picks)', 'Submit my votes'],
    'pick_prompt'     => ['Button before anything is picked', 'Select to vote'],
    'thanks_title'    => ['Thank-you heading', 'Thank you for voting!'],
    'thanks_body'     => ['Thank-you message', 'Your vote has been counted anonymously.'],
    'results_title'   => ['Results heading', 'Live results'],
    'waiting_first'   => ['Results before the first vote', 'Waiting for the first vote…'],
    'scan_to_vote'    => ['Projector QR heading', 'Scan to vote'],
    'closed_message'  => ['Shown when voting is closed', 'Voting is closed. Thank you, everyone!'],
    'not_open_yet'    => ['Shown before voting opens', 'Voting opens soon.'],
    'opens_in'        => ['Countdown before opening', 'Voting opens in'],
    'closes_in'       => ['Countdown before closing', 'Voting closes in'],
    'answers_title'   => ['Answers wall heading (projector)', 'What people said'],
    'reveal_waiting'  => ['Projector while results are hidden', 'Results will be revealed soon'],
    'results_hidden'  => ['Phones when results are hidden', 'Results will be shown on the big screen.'],
    'code_prompt'     => ['Asking for an access code', 'Enter the code from your card to vote'],
    'footer_note'     => ['Footer note', 'Your choice is never linked to you.'],
];

function settings_all(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = SETTING_DEFAULTS;
        if (installed()) {
            foreach (all('SELECT k, v FROM settings') as $r) $cache[$r['k']] = $r['v'];
        }
    }
    return $cache;
}

function setting(string $k, $default = null)
{
    return settings_all()[$k] ?? $default;
}

function setting_set(string $k, string $v): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$k, $v]);
}

/** Resolved texts: defaults, then global overrides, then per-poll overrides. */
function texts(?array $poll = null): array
{
    $out = [];
    foreach (TEXT_DEFAULTS as $k => [, $def]) $out[$k] = $def;
    foreach ([json_decode((string) setting('texts', '{}'), true) ?: [], $poll ? (json_decode((string) ($poll['texts'] ?? ''), true) ?: []) : []] as $layer) {
        foreach ($layer as $k => $v) if (isset($out[$k]) && trim((string) $v) !== '') $out[$k] = (string) $v;
    }
    return $out;
}

function media_url($id): string
{
    if (!$id) return '';
    $m = one('SELECT filename FROM media WHERE id = ?', [(int) $id]);
    return $m ? url('uploads/' . $m['filename']) : '';
}

/** Branding for a page: global settings with optional per-poll logo/accent overrides. */
function branding(?array $poll = null): array
{
    $s = settings_all();
    $fonts = FONT_PAIRS[$s['font_pair']] ?? FONT_PAIRS['classic'];
    $logo = ($poll && $poll['logo_media_id']) ? media_url($poll['logo_media_id']) : media_url($s['logo_media_id']);
    $accent = ($poll && preg_match('/^#[0-9a-f]{6}$/i', (string) $poll['accent'])) ? $poll['accent'] : $s['accent'];
    if (!preg_match('/^#[0-9a-f]{6}$/i', (string) $accent)) $accent = '#f7941d';
    // Readable text on top of the accent colour: dark on light accents, white on dark ones.
    [$r, $g, $b] = array_map(fn($h) => hexdec($h) / 255, str_split(substr($accent, 1), 2));
    $lin = fn($c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    $lum = 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    return [
        'on_accent' => $lum > 0.36 ? '#1d1204' : '#ffffff',
        'site_title' => $s['site_title'],
        'org_name' => $s['org_name'],
        'logo' => $logo,
        'favicon' => media_url($s['favicon_media_id']) ?: ($logo ?: url('assets/img/favicon.svg')),
        'accent' => $accent,
        'fonts' => $fonts,
        'phone_theme' => $s['phone_theme'],
        'screen_theme' => $s['screen_theme'],
    ];
}

/** Fingerprint of the front-end files so open tabs reload after an update. */
function asset_version(): string
{
    static $v = null;
    if ($v === null) {
        $h = hash_init('sha1');
        foreach (['assets/css/app.css', 'assets/js/vote.js', 'assets/js/screen.js', 'assets/js/common.js'] as $f) {
            $p = ROOT . '/' . $f;
            if (is_file($p)) hash_update($h, $f . filemtime($p) . filesize($p));
        }
        $v = substr(hash_final($h), 0, 10);
    }
    return $v;
}

function asset(string $path): string
{
    return url($path) . '?v=' . asset_version();
}

// ---------- sessions, CSRF, admin auth ----------

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('vs_admin');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => base_path() . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    start_session();
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? '');
    if (!is_string($sent) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(419);
        view('message', ['title' => 'Session expired', 'message' => 'Your session expired. Go back, refresh the page and try again.']);
        exit;
    }
}

function admin_user(): ?array
{
    static $user = false;
    if ($user !== false) return $user;
    start_session();
    $user = null;
    if (!empty($_SESSION['admin_id'])) {
        $u = one('SELECT * FROM admins WHERE id = ?', [(int) $_SESSION['admin_id']]);
        if ($u && (int) $u['session_epoch'] === (int) ($_SESSION['epoch'] ?? -1)) $user = $u;
    }
    return $user;
}

function require_admin(): array
{
    $u = admin_user();
    if (!$u) {
        if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', url('admin/api'))) json_out(['error' => 'Please log in again.'], 401);
        redirect('admin/login');
    }
    return $u;
}

function login_attempt(string $username, string $password): ?string
{
    $ip = client_ip();
    q('DELETE FROM login_attempts WHERE attempted_at < ?', [date('Y-m-d H:i:s', time() - 900)]);
    $recent = (int) val('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [$ip]);
    if ($recent >= 8) return 'Too many attempts. Please wait 15 minutes and try again.';
    $u = one('SELECT * FROM admins WHERE username = ?', [$username]);
    if (!$u || !password_verify($password, $u['password_hash'])) {
        q('INSERT INTO login_attempts (ip, attempted_at) VALUES (?, ?)', [$ip, now()]);
        return 'Wrong username or password.';
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION['admin_id'] = (int) $u['id'];
    $_SESSION['epoch'] = (int) $u['session_epoch'];
    q('UPDATE admins SET last_login_at = ? WHERE id = ?', [now(), $u['id']]);
    q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
    audit('login', $username);
    return null;
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}

function flash(?string $msg = null, string $kind = 'ok'): ?array
{
    start_session();
    if ($msg !== null) { $_SESSION['flash'] = [$kind, $msg]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
