<?php
/** @var ?string $error */
/** @var bool $hasCode */
$brand = branding();
$pageTitle = 'Reset password · ' . $brand['site_title'];
$noindex = true;
require ROOT . '/views/partials/head.php';
?>
<body>
  <?php require ROOT . '/views/partials/masthead.php'; ?>
  <main class="wrap">
    <form method="post" class="card login-card" autocomplete="off">
      <?= csrf_field() ?>
      <div class="card-head"><h2>Reset your password</h2><span class="pill-note">Organiser only</span></div>
      <?php if ($error): ?><div class="banner warn"><?= e($error) ?></div><?php endif; ?>
      <?php if ($hasCode): ?>
        <p class="hint" style="margin-top:0">Enter your username, the <b>recovery code</b> you saved when the system was installed (or created in Branding &amp; settings), and a new password.</p>
        <div class="fields" style="grid-template-columns:1fr">
          <label class="field"><span>Username</span><input name="username" autocomplete="username" required></label>
          <label class="field"><span>Recovery code</span><input name="code" class="mono" placeholder="ABCD-EFGH-JKLM-NPQR" required style="text-transform:uppercase;letter-spacing:.08em"></label>
          <label class="field"><span>New password (10+ characters)</span><input type="password" name="new" autocomplete="new-password" minlength="10" required></label>
          <label class="field"><span>Repeat new password</span><input type="password" name="new2" autocomplete="new-password" minlength="10" required></label>
        </div>
        <p style="margin:20px 0 0"><button class="btn accent">Reset password</button></p>
      <?php else: ?>
        <div class="banner">No recovery code is set up (or it has already been used).</div>
      <?php endif; ?>
      <details style="margin-top:18px">
        <summary style="cursor:pointer;font-weight:700">Lost the recovery code too?</summary>
        <p class="hint">In cPanel → <b>File Manager</b>, open the voting system's folder and create a new file named <b class="mono">RESET-PASSWORD.txt</b>. Type your new password (10+ characters) on its first line and save. Then open the <a href="<?= e(url('admin/login')) ?>">login page</a>. The password is changed and the file deletes itself.</p>
      </details>
      <p style="margin:16px 0 0;text-align:center"><a href="<?= e(url('admin/login')) ?>">← Back to log in</a></p>
    </form>
  </main>
</body>
</html>
