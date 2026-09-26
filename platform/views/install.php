<?php
/** @var array $in */
/** @var array $errors */
$brand = ['phone_theme' => 'auto', 'screen_theme' => 'dark', 'accent' => '#f7941d', 'on_accent' => '#1d1204', 'favicon' => url('assets/img/favicon.svg'),
    'fonts' => FONT_PAIRS['classic'], 'logo' => url('assets/img/default-logo.png'), 'site_title' => 'Voting', 'org_name' => ''];
$pageTitle = 'Install — Voting System';
$noindex = true;
require ROOT . '/views/partials/head.php';
$field = fn($name, $label, $type = 'text', $hint = '') => '<label class="field"><span>' . e($label) . '</span><input type="' . $type . '" name="' . $name . '" value="'
    . ($type === 'password' ? '' : e($in[$name])) . '" autocomplete="off">' . ($hint ? '<small>' . e($hint) . '</small>' : '') . '</label>';
?>
<body>
  <?php require ROOT . '/views/partials/masthead.php'; ?>
  <main class="wrap" style="max-width:640px">
    <section class="hero">
      <span class="eyebrow">One-time setup</span>
      <h1>Install the voting system</h1>
      <p class="lock">Create an empty MySQL database in cPanel (MySQL® Databases), add a user to it with ALL PRIVILEGES, then fill in the details below.</p>
    </section>
    <?php foreach ($errors as $err): ?><div class="banner warn"><?= e($err) ?></div><?php endforeach; ?>
    <form method="post" class="card">
      <div class="card-head"><h2>1. Database</h2></div>
      <div class="fields">
        <?= $field('db_host', 'Host', 'text', 'Usually “localhost” on cPanel') ?>
        <?= $field('db_port', 'Port') ?>
        <?= $field('db_name', 'Database name', 'text', 'e.g. cpaneluser_voting') ?>
        <?= $field('db_user', 'Database user', 'text', 'e.g. cpaneluser_voteuser') ?>
        <label class="field full"><span>Database password</span><input type="password" name="db_pass" autocomplete="off"></label>
      </div>
      <div class="card-head" style="margin-top:26px"><h2>2. Your admin login</h2></div>
      <div class="fields">
        <?= $field('site_title', 'Site name') ?>
        <?= $field('username', 'Admin username') ?>
        <?= $field('password', 'Password (10+ characters)', 'password') ?>
        <?= $field('password2', 'Repeat password', 'password') ?>
      </div>
      <p style="margin:22px 0 0"><button class="btn accent">Install</button></p>
    </form>
  </main>
</body>
</html>
