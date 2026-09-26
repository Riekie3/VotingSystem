<?php
/** @var array $polls */
$pageTitle = 'Trash';
$nav = 'trash';
require ROOT . '/views/admin/_top.php';
?>
<div class="admin-top"><h1>Trash</h1></div>
<p class="hint">Deleted voting pages wait here. Restore them any time, or delete them permanently (this also deletes their votes).</p>
<section class="card">
  <?php if (!$polls): ?><p class="hint" style="margin:0">The Trash is empty.</p><?php else: ?>
  <table class="table">
    <thead><tr><th>Voting page</th><th class="num">Responses</th><th>Deleted</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($polls as $p): ?>
        <tr>
          <td><b><?= e($p['title']) ?></b><br><small class="mono">/<?= e($p['slug']) ?></small></td>
          <td class="num"><?= (int) $p['responses'] ?></td>
          <td><?= e(date('j M Y, H:i', strtotime($p['deleted_at']))) ?></td>
          <td style="white-space:nowrap">
            <form method="post" action="<?= e(url('admin/polls/' . $p['id'] . '/action')) ?>" style="display:inline"><?= csrf_field() ?>
              <button class="btn tiny good" name="action" value="restore">↺ Restore</button>
              <button class="btn tiny danger" name="action" value="destroy" data-confirm="Permanently delete “<?= e($p['title']) ?>” and all <?= (int) $p['responses'] ?> responses? This cannot be undone." data-confirm-type="DELETE">Delete forever</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</section>
<?php require ROOT . '/views/admin/_bottom.php'; ?>
