<?php
/** @var ?string $error */
/** @var bool $installed */
$brand = branding();
$pageTitle = 'Log in · ' . $brand['site_title'];
$noindex = true;
require ROOT . '/views/partials/head.php';
?>
<body>
  <?php require ROOT . '/views/partials/masthead.php'; ?>
  <main class="wrap">
    <form method="post" class="card login-card">
      <?= csrf_field() ?>
      <div class="card-head"><h2>Admin log in</h2><span class="pill-note">Organiser only</span></div>
      <?php if ($installed): ?><div class="banner good">Installed successfully. Log in with the account you just created.</div><?php endif; ?>
      <?php if ($error): ?><div class="banner warn"><?= e($error) ?></div><?php endif; ?>
      <?php if ($f = flash()): ?><div class="banner <?= $f[0] === 'ok' ? 'good' : 'warn' ?>"><?= e($f[1]) ?></div><?php endif; ?>
      <div class="fields" style="grid-template-columns:1fr">
        <label class="field"><span>Username</span><input name="username" autocomplete="username" required autofocus></label>
        <label class="field"><span>Password</span><input type="password" name="password" autocomplete="current-password" required></label>
      </div>
      <p style="margin:20px 0 0"><button class="btn accent">Log in</button></p>
    </form>
  </main>
</body>
</html>
