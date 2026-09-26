<?php
/** @var array $s */
/** @var array $polls */
$pageTitle = 'Branding & settings';
$nav = 'settings';
require ROOT . '/views/admin/_top.php';
$sel = fn($a, $b) => (string) $a === (string) $b ? ' selected' : '';
$savedTexts = json_decode((string) $s['texts'], true) ?: [];
$logo = media_url($s['logo_media_id']);
$fav = media_url($s['favicon_media_id']);
?>
<div class="admin-top"><h1>Branding &amp; settings</h1></div>

<form method="post" autocomplete="off">
  <?= csrf_field() ?><input type="hidden" name="action" value="settings">

  <details class="card section" open>
    <summary>Brand <small>— name, logo, colour, fonts</small></summary>
    <div class="body fields">
      <label class="field"><span>Site name</span><input name="site_title" value="<?= e($s['site_title']) ?>" maxlength="120"></label>
      <label class="field"><span>Organisation / default small label</span><input name="org_name" value="<?= e($s['org_name']) ?>" maxlength="120" placeholder="e.g. WKC"></label>
      <div class="field full"><span>Logo</span>
        <div class="media-pick" data-media>
          <div class="thumb"><?= $logo ? '<img src="' . e($logo) . '" alt="">' : '<small>No logo</small>' ?></div>
          <input type="hidden" name="logo_media_id" value="<?= e($s['logo_media_id']) ?>">
          <label class="btn tiny ghost">Upload…<input type="file" accept="image/*" hidden></label>
          <button type="button" class="btn tiny ghost" data-media-clear>Remove</button>
        </div><small>PNG with a white or transparent background works best. It shows on a white card at the top of every page.</small></div>
      <div class="field full"><span>Browser tab icon (favicon)</span>
        <div class="media-pick" data-media>
          <div class="thumb" style="width:64px"><?= $fav ? '<img src="' . e($fav) . '" alt="">' : '<small>Logo</small>' ?></div>
          <input type="hidden" name="favicon_media_id" value="<?= e($s['favicon_media_id']) ?>">
          <label class="btn tiny ghost">Upload…<input type="file" accept="image/*" hidden></label>
          <button type="button" class="btn tiny ghost" data-media-clear>Use logo</button>
        </div></div>
      <label class="field"><span>Accent colour</span><div class="color-row"><input type="color" name="accent" value="<?= e($s['accent']) ?>" data-accent-live><span class="hint">Buttons, highlights, the leader bar</span></div></label>
      <label class="field"><span>Fonts</span><select name="font_pair">
        <?php foreach (FONT_PAIRS as $k => $f): ?><option value="<?= e($k) ?>"<?= $sel($s['font_pair'], $k) ?>><?= e($f['label']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span>Phone pages theme</span><select name="phone_theme">
        <option value="auto"<?= $sel($s['phone_theme'], 'auto') ?>>Automatic (follows each phone)</option>
        <option value="light"<?= $sel($s['phone_theme'], 'light') ?>>Always light</option>
        <option value="dark"<?= $sel($s['phone_theme'], 'dark') ?>>Always dark</option></select></label>
      <label class="field"><span>Projector theme</span><select name="screen_theme">
        <option value="dark"<?= $sel($s['screen_theme'], 'dark') ?>>Dark stage (recommended)</option>
        <option value="light"<?= $sel($s['screen_theme'], 'light') ?>>Light</option></select></label>
    </div>
  </details>

  <details class="card section">
    <summary>Home page <small>— what people see at the main address</small></summary>
    <div class="body fields">
      <label class="field"><span>Main address shows</span><select name="home_mode">
        <option value="list"<?= $sel($s['home_mode'], 'list') ?>>A list of open voting pages</option>
        <option value="redirect"<?= $sel($s['home_mode'], 'redirect') ?>>Go straight to one voting page</option>
        <option value="blank"<?= $sel($s['home_mode'], 'blank') ?>>Just the logo and site name</option></select></label>
      <label class="field"><span>Voting page to go to</span><select name="home_slug">
        <option value="">—</option>
        <?php foreach ($polls as $p): ?><option value="<?= e($p['slug']) ?>"<?= $sel($s['home_slug'], $p['slug']) ?>><?= e($p['title']) ?></option><?php endforeach; ?></select></label>
    </div>
  </details>

  <details class="card section">
    <summary>Wording <small>— default texts for every voting page (each page can override)</small></summary>
    <div class="body fields">
      <?php foreach (TEXT_DEFAULTS as $k => [$label, $def]): ?>
        <label class="field"><span><?= e($label) ?></span><input name="text[<?= e($k) ?>]" value="<?= e($savedTexts[$k] ?? '') ?>" placeholder="<?= e($def) ?>" maxlength="300"></label>
      <?php endforeach; ?>
      <p class="hint full" style="grid-column:1/-1;margin:0">Leave a box empty to keep the default shown in grey. <code>{choice}</code> is replaced by the option name.</p>
    </div>
  </details>

  <div class="savebar"><span class="note">Saved changes appear on every open page within a few seconds.</span><button class="btn small accent">Save settings</button></div>
</form>

<div class="fields" style="grid-template-columns:repeat(auto-fill,minmax(320px,1fr));align-items:start">
  <form method="post" class="card" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="password">
    <div class="card-head"><h2>Change password</h2></div>
    <div class="fields" style="grid-template-columns:1fr">
      <label class="field"><span>Current password</span><input type="password" name="current" autocomplete="current-password" required></label>
      <label class="field"><span>New password (10+ characters)</span><input type="password" name="new" autocomplete="new-password" minlength="10" required></label>
      <label class="field"><span>Repeat new password</span><input type="password" name="new2" autocomplete="new-password" minlength="10" required></label>
    </div>
    <p style="margin:16px 0 0"><button class="btn small">Change password</button></p>
  </form>
  <form method="post" class="card" autocomplete="off">
    <?= csrf_field() ?><input type="hidden" name="action" value="recovery">
    <div class="card-head"><h2>Recovery code</h2>
      <span class="pill-note"><?= (string) $s['recovery_hash'] !== '' ? 'Active' : 'Not set' ?></span></div>
    <?php start_session(); if (!empty($_SESSION['recovery_show'])): ?>
      <div class="banner good"><b>Your new recovery code:</b><br><span class="mono" style="font-size:19px;font-weight:800"><?= e($_SESSION['recovery_show']) ?></span><br>
        <small>Shown only this once. Write it down or save it in your phone. Any older code no longer works.</small></div>
      <?php unset($_SESSION['recovery_show']); endif; ?>
    <p class="hint" style="margin-top:0">Lets you reset your password from the login page (“Forgot password?”) without email. Each code works once.
      <?php if (!empty($s['recovery_created']) && (string) $s['recovery_hash'] !== ''): ?>Current code created <?= e(date('j M Y', strtotime($s['recovery_created']))) ?>.<?php endif; ?></p>
    <div class="fields" style="grid-template-columns:1fr">
      <label class="field"><span>Your current password</span><input type="password" name="current" autocomplete="current-password" required></label>
    </div>
    <p style="margin:16px 0 0"><button class="btn small"><?= (string) $s['recovery_hash'] !== '' ? 'Replace recovery code' : 'Create recovery code' ?></button></p>
  </form>
  <div class="card">
    <div class="card-head"><h2>Backup &amp; security</h2></div>
    <p class="hint" style="margin-top:0">Download everything (database + uploaded images) as one zip. Keep it somewhere safe — it's also how you move the system to another server.</p>
    <a class="btn small ghost" href="<?= e(url('admin/backup')) ?>">⬇ Download full backup</a>
    <form method="post" style="margin-top:16px"><?= csrf_field() ?><input type="hidden" name="action" value="logout_all">
      <button class="btn small ghost" data-confirm="Log out of the admin panel on every device (including this one)?">⎋ Log out everywhere</button></form>
    <p class="hint">Version <?= e(APP_VERSION) ?></p>
  </div>
  <form method="post" class="card" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="import">
    <div class="card-head"><h2>Import old results</h2></div>
    <p class="hint" style="margin-top:0">Bring in a vote from the original FY27 system: choose its <code>config.json</code> and its <code>data/state.json</code>. It becomes a closed, archived poll with the same results and comments.</p>
    <div class="fields" style="grid-template-columns:1fr">
      <label class="field"><span>config.json</span><input type="file" name="config" accept=".json,application/json" required></label>
      <label class="field"><span>state.json</span><input type="file" name="state" accept=".json,application/json" required></label>
    </div>
    <p style="margin:16px 0 0"><button class="btn small">Import</button></p>
  </form>
</div>
<?php require ROOT . '/views/admin/_bottom.php'; ?>
