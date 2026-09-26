<?php
/** @var array $p */
/** @var array $res */
/** @var array $comments */
/** @var array $brand */
$brand['phone_theme'] = 'light';
$pageTitle = 'Report · ' . $p['title'];
$noindex = true;
require ROOT . '/views/partials/head.php';
$isRanked = $p['type'] === 'ranked';
$max = max(1, ...array_map(fn($r) => $r['score'], $res['rows'] ?: [['score' => 1]]));
$ord = ['1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th'];
?>
<body class="report">
  <div class="wrap no-print" style="padding-top:18px"><div class="banner">Print this page or choose <b>Save as PDF</b> in the print dialog. <button class="btn tiny" onclick="print()" style="margin-left:8px">🖨 Print / Save PDF</button></div></div>
  <?php require ROOT . '/views/partials/masthead.php'; ?>
  <main class="wrap">
    <section class="hero" style="padding-bottom:0">
      <?php if ($p['eyebrow']): ?><span class="eyebrow"><?= e($p['eyebrow']) ?></span><?php endif; ?>
      <h1><?= e($p['title']) ?></h1>
      <p class="lock">Results report · <?= e(date('j F Y, g:i A')) ?> · <?= $res['responses'] ?> responses<?= $isRanked ? ' · scoring ' . e(implode('/', poll_points($p))) . ' points' : '' ?></p>
    </section>

    <section class="card">
      <div class="card-head"><h2>Results</h2></div>
      <table class="table">
        <thead><tr><th class="num">#</th><th>Option</th><th style="width:34%"></th><th class="num"><?= $isRanked ? 'Points' : 'Votes' ?></th><?php if (!$isRanked): ?><th class="num">%</th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($res['rows'] as $r): ?>
            <tr><td class="num"><b><?= $r['rank'] ?></b></td><td><?= e($r['label']) ?></td>
              <td><div class="bar"><i style="width:<?= round($r['score'] / $max * 100) ?>%"></i></div></td>
              <td class="num"><b><?= $r['score'] ?></b></td><?php if (!$isRanked): ?><td class="num"><?= $r['pct'] ?>%</td><?php endif; ?></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </section>

    <?php if ($comments): ?>
    <section class="card">
      <div class="card-head"><h2><?= e($p['comment_label'] ?: 'Comments') ?></h2></div>
      <?php foreach ($comments as $g): ?>
        <h3 style="margin:18px 0 8px;font-size:16px"><?= e($g['label']) ?> <small style="color:#777;font-weight:500">· <?= $g['score'] ?> <?= $isRanked ? 'pts' : 'votes' ?></small></h3>
        <ul style="margin:0;padding-left:18px;display:grid;gap:6px">
          <?php foreach ($g['items'] as $c): ?><li><?= $isRanked ? '<b>' . e($ord[$c['rank'] - 1] ?? '') . ':</b> ' : '' ?><?= e($c['text']) ?></li><?php endforeach; ?>
        </ul>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <p class="foot">Anonymous responses — no names or devices are recorded.</p>
  </main>
</body>
</html>
