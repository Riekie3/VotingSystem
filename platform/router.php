<?php
// Local development only: `php -S localhost:8995 router.php`
// Mirrors .htaccess — serves real files from assets/ and uploads/, sends everything else to index.php.

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/(assets|uploads)/~', $path) && is_file(__DIR__ . $path) && !preg_match('/\.php$/i', $path)) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
