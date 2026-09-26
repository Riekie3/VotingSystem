<?php
// One-time web installer. Runs only while config.php does not exist.

declare(strict_types=1);

function page_install(): void
{
    if (installed()) redirect('admin');
    $errors = [];
    $in = [
        'db_host' => 'localhost', 'db_port' => '3306', 'db_name' => '', 'db_user' => '', 'db_pass' => '',
        'username' => 'admin', 'password' => '', 'password2' => '', 'site_title' => 'WKC Voting',
    ];
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        foreach ($in as $k => $v) $in[$k] = trim((string) ($_POST[$k] ?? ''));
        if (!is_writable(ROOT)) $errors[] = 'The app folder is not writable, so config.php cannot be created. Check folder permissions (755).';
        if ($in['db_name'] === '' || $in['db_user'] === '') $errors[] = 'Enter the database name and user.';
        if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $in['username'])) $errors[] = 'Username: 3–64 letters, numbers, dot, dash or underscore.';
        if (strlen($in['password']) < 10) $errors[] = 'Password must be at least 10 characters.';
        if ($in['password'] !== $in['password2']) $errors[] = 'The two passwords do not match.';
        $pdo = null;
        if (!$errors) {
            try {
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $in['db_host'], (int) $in['db_port'], $in['db_name']);
                $pdo = new PDO($dsn, $in['db_user'], $in['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            } catch (Throwable $t) {
                $errors[] = 'Could not connect to the database: ' . $t->getMessage();
            }
        }
        if (!$errors && $pdo) {
            try {
                if ($pdo->query("SHOW TABLES LIKE 'polls'")->fetch()) {
                    throw new RuntimeException('This database already contains a voting system. Use an empty database, or restore config.php.');
                }
                foreach (array_filter(array_map('trim', explode(';', file_get_contents(ROOT . '/sql/schema.sql')))) as $stmt) {
                    if (preg_match('/^\s*(--.*\n\s*)*$/', $stmt)) continue;
                    $pdo->exec($stmt);
                }
                $t = date('Y-m-d H:i:s');
                $pdo->prepare('INSERT INTO admins (username, password_hash, created_at) VALUES (?, ?, ?)')
                    ->execute([$in['username'], password_hash($in['password'], PASSWORD_DEFAULT), $t]);
                $set = $pdo->prepare('INSERT INTO settings (k, v) VALUES (?, ?)');
                $set->execute(['site_title', $in['site_title'] ?: 'WKC Voting']);
                // First one-time recovery code, shown once on the login page.
                $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
                $raw = '';
                for ($i = 0; $i < 16; $i++) $raw .= $alphabet[random_int(0, 31)];
                $set->execute(['recovery_hash', password_hash($raw, PASSWORD_DEFAULT)]);
                $set->execute(['recovery_created', $t]);
                require_once ROOT . '/src/migrate.php';
                $set->execute(['db_version', (string) DB_VERSION]);
                // Default logo (PetWorld) so the first poll already looks right.
                $src = ROOT . '/assets/img/default-logo.png';
                if (is_file($src) && is_writable(UPLOAD_DIR)) {
                    $name = 'logo-' . bin2hex(random_bytes(6)) . '.png';
                    copy($src, UPLOAD_DIR . '/' . $name);
                    $pdo->prepare('INSERT INTO media (filename, original_name, mime, size, created_at) VALUES (?, ?, ?, ?, ?)')
                        ->execute([$name, 'default-logo.png', 'image/png', filesize($src), $t]);
                    $set->execute(['logo_media_id', (string) $pdo->lastInsertId()]);
                }
                $config = [
                    'db' => ['host' => $in['db_host'], 'port' => (int) $in['db_port'], 'name' => $in['db_name'], 'user' => $in['db_user'], 'pass' => $in['db_pass']],
                    'secret' => bin2hex(random_bytes(32)),
                    'timezone' => 'Asia/Kuala_Lumpur',
                ];
                $php = "<?php\n// Created by the installer. Keep this file private.\nreturn " . var_export($config, true) . ";\n";
                if (file_put_contents(CONFIG_FILE, $php) === false) throw new RuntimeException('Could not write config.php.');
                @chmod(CONFIG_FILE, 0640);
                start_session();
                $_SESSION['recovery_show'] = implode('-', str_split($raw, 4));
                redirect('admin/login?installed=1');
            } catch (Throwable $t) {
                $errors[] = $t->getMessage();
            }
        }
    }
    view('install', ['in' => $in, 'errors' => $errors]);
}
