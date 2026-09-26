<?php
/** @var array $p */
/** @var array $res */
/** @var array $comments */
$pageTitle = 'Results · ' . $p['title'];
$nav = 'dash';
require ROOT . '/views/admin/_top.php';
$isRanked = $p['type'] === 'ranked';
$voters = (int) val('SELECT COUNT(*) FROM voters WHERE poll_id = ? AND used > 0', [$p['id']]);
$commentCount = array_sum(array_map(fn($g) => count($g['items']), $comments));
$base = 'admin/polls/' . $p['id'];
?>
<div class="crumb"><a href="<?= e(url('admin')) ?>">Dashboard</a> / <a href="<?= e(url($base)) ?>">Edit</a> / Results</div>
<div class="admin-top">
  <h1><?= e($p['title']) ?></h1>
  <div class="actions">
    <a class="btn small ghost" href="<?= e(url($base . '/export.xlsx')) ?>">⬇ Excel</a>
    <a class="btn small ghost" href="<?= e(url($base . '/export.csv')) ?>">⬇ CSV</a>
    <a class="btn small ghost" href="<?= e(url($base . '/report')) ?>" target="_blank" rel="noopener">🖨 Report / PDF</a>
    <a class="btn small ghost" href="<?= e(url($p['slug'] . '/results')) ?>" target="_blank" rel="noopener">🖥 Projector</a>
  </div>
</div>

<div class="stats" data-live-results="<?= (int) $p['id'] ?>">
  <div class="stat"><b data-stat="responses"><?= $res['responses'] ?></b><span>responses</span></div>
  <div class="stat"><b><?= $voters ?></b><span>devices voted</span></div>
  <div class="stat"><b data-stat="comments"><?= $commentCount ?></b><span>comments</span></div>
  <div class="stat"><b><?= e(ucfirst(poll_state($p))) ?></b><span>status · updates live</span></div>
</div>

<section class="card">
  <div class="card-head"><h2>All options</h2><span class="status"><?= $isRanked ? 'Points: ' . e(implode(' / ', poll_points($p))) . ' for 1st / 2nd / 3rd…' : 'Votes and % of responses' ?></span></div>
  <div style="overflow-x:auto">
    <table class="table" id="results-table">
      <thead><tr><th class="num">#</th><th>Option</th><th class="num"><?= $isRanked ? 'Points' : 'Votes' ?></th>
        <?php if ($isRanked): foreach (poll_points($p) as $i => $_): ?><th class="num"># <?= $i + 1 ?></th><?php endforeach; else: ?><th class="num">%</th><?php endif; ?></tr></thead>
      <tbody>
        <?php foreach ($res['rows'] as $r): ?>
          <tr><td class="num"><?= $r['rank'] ?></td><td><?= e($r['label']) ?></td><td class="num"><b><?= $r['score'] ?></b></td>
            <?php if ($isRanked): foreach ($r['ranks'] as $n): ?><td class="num"><?= $n ?></td><?php endforeach; else: ?><td class="num"><?= $r['pct'] ?>%</td><?php endif; ?></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<?php if ($p['comment_mode'] !== 'off' || $comments): ?>
<section class="card">
  <div class="card-head"><h2>Comments</h2><span class="status">Tap “Hide” to remove a comment from the projector (it stays in exports).</span></div>
  <?php if (!$comments): ?><p class="hint">No comments yet.</p><?php endif; ?>
  <?php foreach ($comments as $g): ?>
    <details class="section" style="border-top:1px solid var(--line);padding-top:12px" open>
      <summary><?= e($g['label']) ?> <small><?= count($g['items']) ?> comment<?= count($g['items']) === 1 ? '' : 's' ?></small></summary>
      <?php foreach ($g['items'] as $c): ?>
        <div class="comment-item<?= $c['hidden'] ? ' is-hidden' : '' ?>">
          <?php if ($isRanked): ?><span class="rk <?= $c['rank'] <= 3 ? 'm' . $c['rank'] : 'badge' ?>"><?= e(['1st', '2nd', '3rd', '4th', '5th'][$c['rank'] - 1] ?? $c['rank'] . 'th') ?></span><?php endif; ?>
          <span class="txt"><?= e($c['text']) ?></span>
          <button type="button" class="btn tiny ghost" data-moderate="<?= $c['id'] ?>" data-hidden="<?= $c['hidden'] ?>"><?= $c['hidden'] ? 'Show' : 'Hide' ?></button>
        </div>
      <?php endforeach; ?>
    </details>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php require ROOT . '/views/admin/_bottom.php'; ?>
