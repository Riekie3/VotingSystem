<?php
/** @var array $polls */
$pageTitle = 'Presentation mode';
$nav = 'present';
require ROOT . '/views/admin/_top.php';
?>
<div class="admin-top"><h1>Presentation mode</h1></div>
<p class="hint" style="max-width:720px">One projector tab that rotates through several voting pages. Tick the pages, choose how long each one shows, then open it on the projector laptop and press <b>F</b> for full screen. Use the ← → keys to skip.</p>
<form class="card" id="present-form" style="max-width:760px" data-present-base="<?= e(abs_url('present')) ?>">
  <?php if (!$polls): ?><p class="hint">Publish at least one voting page first.</p><?php endif; ?>
  <div style="display:grid;gap:10px">
    <?php foreach ($polls as $p): ?>
      <label class="check"><input type="checkbox" value="<?= e($p['slug']) ?>" checked> <span><b><?= e($p['title']) ?></b> <small class="mono">/<?= e($p['slug']) ?></small></span></label>
    <?php endforeach; ?>
  </div>
  <div class="fields" style="margin-top:18px">
    <label class="field"><span>Seconds per page</span><input type="number" id="present-every" min="5" max="600" value="20"></label>
  </div>
  <p class="mono" id="present-url" style="word-break:break-all;margin:16px 0"></p>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <a class="btn small accent" id="present-open" target="_blank" rel="noopener">▶ Open presentation</a>
    <button type="button" class="btn small ghost" id="present-copy">🔗 Copy link</button>
  </div>
</form>
<?php require ROOT . '/views/admin/_bottom.php'; ?>
