<?php
/** @var string $title */
/** @var string $message */
$brand = installed() ? (function () { try { return branding(); } catch (Throwable $t) { return null; } })() : null;
$brand = $brand ?: ['phone_theme' => 'auto', 'screen_theme' => 'dark', 'accent' => '#f7941d', 'on_accent' => '#1d1204', 'favicon' => url('assets/img/favicon.svg'),
    'fonts' => FONT_PAIRS['classic'], 'logo' => '', 'site_title' => 'Voting', 'org_name' => ''];
$pageTitle = $title;
require ROOT . '/views/partials/head.php';
?>
<body>
  <?php require ROOT . '/views/partials/masthead.php'; ?>
  <main class="wrap">
    <section class="hero">
      <h1><?= e($title) ?></h1>
      <p class="lock"><?= e($message) ?></p>
    </section>
    <p style="text-align:center"><a class="btn small ghost" href="<?= e(url('')) ?>">Go to the home page</a></p>
  </main>
</body>
</html>
