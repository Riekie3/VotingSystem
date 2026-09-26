<?php
// Builds dist/voting-system-vX.Y.Z.zip — everything needed for cPanel, nothing private.
// Usage: php tools/build_release.php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/core.php';

$exclude = [
    '~^config\.php$~',                 // local database credentials
    '~^uploads/(?!\.htaccess$).+~',    // local uploaded images
    '~^storage/(?!\.htaccess$).+~',    // local backups / logs
    '~(^|/)\.DS_Store$~',
];
$dist = dirname($root) . '/dist';
if (!is_dir($dist)) mkdir($dist, 0777, true);
$zipPath = $dist . '/voting-system-v' . APP_VERSION . '.zip';
@unlink($zipPath);

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) exit("Cannot create $zipPath\n");
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$n = 0;
foreach ($it as $file) {
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    foreach ($exclude as $re) if (preg_match($re, $rel)) continue 2;
    $zip->addFile($file->getPathname(), $rel);
    $n++;
}
$guide = dirname($root) . '/DEPLOY-CPANEL.md';
if (is_file($guide)) { $zip->addFile($guide, 'DEPLOY-CPANEL.md'); $n++; }
$zip->close();
printf("Built %s (%d files, %.0f KB)\n", $zipPath, $n, filesize($zipPath) / 1024);
