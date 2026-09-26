<?php
/** @var array $p */
/** @var array $codes */
$pageTitle = 'Access codes · ' . $p['title'];
$nav = 'dash';
require ROOT . '/views/admin/_top.php';
$base = 'admin/polls/' . $p['id'];
$unused = count(array_filter($codes, fn($c) => !$c['claimed_by']));
?>
<div class="crumb"><a href="<?= e(url('admin')) ?>">Dashboard</a> / <a href="<?= e(url($base)) ?>">Edit</a> / Access codes</div>
<div class="admin-top">
  <h1>Access codes</h1>
  <div class="actions">
    <?php if ($unused): ?><a class="btn small accent" href="<?= e(url($base . '/codes/print')) ?>" target="_blank" rel="noopener">🖨 Print <?= $unused ?> QR card<?= $unused === 1 ? '' : 's' ?></a><?php endif; ?>
  </div>
</div>
<p class="hint" style="max-width:760px">
  Each code works on the first phone that opens it. Use them for <b>special ballots</b> (e.g. 2 codes × 3 votes for the boss) or print
  <b>QR cards</b> to stop double voting. <?= $p['access'] === 'code'
      ? '<b>This poll only accepts voters with a code.</b>'
      : 'Right now anyone with the link can vote too — switch “Who can vote” to codes-only in the editor if you want.' ?>
</p>

<form method="post" class="card" style="max-width:760px">
  <?= csrf_field() ?><input type="hidden" name="action" value="generate">
  <div class="card-head"><h2>Create codes</h2></div>
  <div class="fields">
    <label class="field"><span>How many codes</span><input type="number" name="count" min="1" max="500" value="2"></label>
    <label class="field"><span>Votes per code</span><input type="number" name="votes" min="1" max="50" value="3"></label>
    <label class="field"><span>Label <small>(optional)</small></span><input name="label" maxlength="120" placeholder="e.g. Special ballot"></label>
  </div>
  <p style="margin:16px 0 0"><button class="btn small accent">Create</button></p>
</form>

<section class="card">
  <div class="card-head"><h2><?= count($codes) ?> code<?= count($codes) === 1 ? '' : 's' ?></h2>
    <?php if ($unused): ?><form method="post"><?= csrf_field() ?><button class="btn tiny ghost" name="action" value="delete_unused" data-confirm="Delete all <?= $unused ?> unused codes?">Delete unused</button></form><?php endif; ?></div>
  <?php if (!$codes): ?><p class="hint">No codes yet.</p><?php else: ?>
  <div style="overflow-x:auto">
  <table class="table">
    <thead><tr><th>QR</th><th>Code &amp; link</th><th>Label</th><th class="num">Votes</th><th>Status</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($codes as $c): $link = abs_url($p['slug']) . '?k=' . $c['code']; ?>
        <tr>
          <td><span class="qr-mini" data-qr="<?= e($link) ?>" style="display:inline-block;width:64px;height:64px;background:#fff;border-radius:8px;padding:4px"></span></td>
          <td><b class="mono"><?= e($c['code']) ?></b><br><small class="mono" style="word-break:break-all"><?= e($link) ?></small><br>
            <button type="button" class="btn tiny ghost" data-copy="<?= e($link) ?>" style="margin-top:6px">🔗 Copy link</button>
            <button type="button" class="btn tiny ghost" data-whatsapp-code data-title="<?= e($p['title']) ?>" data-url="<?= e($link) ?>" data-votes="<?= (int) $c['votes_allowed'] ?>" style="margin-top:6px">💬 WhatsApp</button></td>
          <td><?= e($c['label']) ?></td>
          <td class="num"><?= (int) $c['votes_allowed'] ?></td>
          <td><?= $c['claimed_by'] ? '<span class="badge open">Claimed · ' . (int) $c['used'] . '/' . (int) $c['votes_allowed'] . ' used</span>' : '<span class="badge">Not used yet</span>' ?></td>
          <td style="white-space:nowrap">
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="code_id" value="<?= (int) $c['id'] ?>">
              <?php if ($c['claimed_by']): ?><button class="btn tiny ghost" name="action" value="release" data-confirm="Let this code be opened on a different phone?">Release</button><?php endif; ?>
              <button class="btn tiny ghost" name="action" value="delete" data-confirm="Delete this code?">✕</button></form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>
<?php require ROOT . '/views/admin/_bottom.php'; ?>
