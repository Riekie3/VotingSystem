<?php
$pageTitle = 'New voting page';
$nav = 'new';
require ROOT . '/views/admin/_top.php';
?>
<div class="crumb"><a href="<?= e(url('admin')) ?>">Dashboard</a> / New</div>
<div class="admin-top"><h1>New voting page</h1></div>
<form method="post" class="card" style="max-width:820px">
  <?= csrf_field() ?>
  <div class="fields">
    <label class="field full"><span>Question / title</span>
      <input name="title" required maxlength="400" placeholder="e.g. Who is your Most Inspiring Leader of FY28?"></label>
    <div class="field full"><span>Type of vote</span>
      <?php foreach (POLL_TYPES as $k => $label): ?>
        <label class="check"><input type="radio" name="type" value="<?= e($k) ?>" <?= $k === 'single' ? 'checked' : '' ?>> <span><?= e($label) ?></span></label>
      <?php endforeach; ?>
    </div>
    <label class="field full"><span>Options (one per line)</span>
      <textarea name="options" rows="8" placeholder="Justin&#10;Jade&#10;Chris"></textarea>
      <small>You can paste a list straight from Excel or WhatsApp. Icons, descriptions and order can be changed on the next screen.</small></label>
  </div>
  <p style="margin:18px 0 0"><button class="btn accent small">Create and continue →</button></p>
</form>
<?php require ROOT . '/views/admin/_bottom.php'; ?>
