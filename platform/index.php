<?php
// Front controller: every request comes through here (see .htaccess).

declare(strict_types=1);

require __DIR__ . '/src/core.php';
require __DIR__ . '/src/polls.php';
require __DIR__ . '/src/voting.php';
require __DIR__ . '/src/public.php';

date_default_timezone_set((string) config('timezone', 'Asia/Kuala_Lumpur'));
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Frame-Options: SAMEORIGIN');

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$path = rawurldecode(substr($uri, strlen(base_path())));
$path = trim($path, '/');
$seg = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!installed()) {
    require __DIR__ . '/src/install.php';
    page_install();
    exit;
}

try {
    require __DIR__ . '/src/migrate.php';
    migrate();

    // ----- admin -----
    if (($seg[0] ?? '') === 'admin') {
        require __DIR__ . '/src/admin.php';
        require __DIR__ . '/src/export.php';
        $a = $seg[1] ?? '';
        $id = isset($seg[2]) && ctype_digit($seg[2]) ? (int) $seg[2] : 0;
        $sub = $seg[3] ?? '';
        switch (true) {
            case $a === '':                                   admin_dashboard(); break;
            case $a === 'login':                              admin_login(); break;
            case $a === 'forgot':                             admin_forgot(); break;
            case $a === 'logout' && $method === 'POST':       admin_logout(); break;
            case $a === 'trash':                              admin_trash(); break;
            case $a === 'settings':                           admin_settings(); break;
            case $a === 'present':                            admin_present(); break;
            case $a === 'backup':                             require_admin(); export_backup(); break;
            case $a === 'api' && ($seg[2] ?? '') === 'upload' && $method === 'POST':  admin_api_upload(); break;
            case $a === 'api' && ($seg[2] ?? '') === 'comment' && $method === 'POST': admin_api_comment(); break;
            case $a === 'api' && ($seg[2] ?? '') === 'live' && isset($seg[3]) && ctype_digit($seg[3]): admin_api_live((int) $seg[3]); break;
            case $a === 'polls' && ($seg[2] ?? '') === 'new': admin_poll_new(); break;
            case $a === 'polls' && $id && $sub === '':        admin_poll_edit($id); break;
            case $a === 'polls' && $id && $sub === 'action' && $method === 'POST': admin_poll_action($id); break;
            case $a === 'polls' && $id && $sub === 'results': admin_poll_results($id); break;
            case $a === 'polls' && $id && $sub === 'codes' && ($seg[4] ?? '') === 'print': admin_codes_print($id); break;
            case $a === 'polls' && $id && $sub === 'codes':   admin_codes($id); break;
            case $a === 'polls' && $id && in_array($sub, ['export.csv', 'export.xlsx', 'report'], true):
                require_admin();
                $p = poll_by_id($id, true) ?? not_found('Poll not found.');
                $sub === 'export.csv' ? export_csv($p) : ($sub === 'export.xlsx' ? export_xlsx($p) : export_report($p));
                break;
            default: not_found();
        }
        exit;
    }

    // ----- public API -----
    if (($seg[0] ?? '') === 'api' && ($seg[1] ?? '') === 'p' && isset($seg[2])) {
        $slug = $seg[2];
        $sub = $seg[3] ?? '';
        switch (true) {
            case $sub === '':                              api_poll($slug); break;
            case $sub === 'results':                       api_results($slug); break;
            case $sub === 'answers':                       api_answers($slug); break;
            case $sub === 'vote' && $method === 'POST':    api_vote($slug); break;
            case $sub === 'code' && $method === 'POST':    api_code($slug); break;
            default: json_out(['error' => 'Not found'], 404);
        }
        exit;
    }

    // ----- public pages -----
    if (!$seg) { page_home(); exit; }
    if ($seg[0] === 'present') { page_present(); exit; }
    if (count($seg) === 1) { page_vote($seg[0]); exit; }
    if (count($seg) === 2 && $seg[1] === 'results') { page_screen($seg[0]); exit; }
    not_found();
} catch (Throwable $t) {
    error_log('[voting] ' . $t->getMessage() . ' @ ' . $t->getFile() . ':' . $t->getLine());
    if (str_starts_with($path, 'api/') || str_starts_with($path, 'admin/api/')) json_out(['error' => 'Server error. Please try again.'], 500);
    http_response_code(500);
    view('message', ['title' => 'Something went wrong', 'message' => 'Please try again in a moment. If it keeps happening, check the server error log.']);
}
