<?php
/** @var array $p */
/** @var array $codes */
/** @var array $brand */
$brand['phone_theme'] = 'light';
$pageTitle = 'QR cards · ' . $p['title'];
$noindex = true;
require ROOT . '/views/partials/head.php';
?>
<body class="report">
  <div class="wrap no-print" style="padding-top:20px">
    <div class="banner"><?= count($codes) ?> unused code(s). Print this page (Ctrl + P), cut along the dashed lines and hand one card to each person.
      <button class="btn tiny" onclick="print()" style="margin-left:8px">🖨 Print</button></div>
  </div>
  <div class="wrap">
    <div class="cards-sheet">
      <?php foreach ($codes as $c): $link = abs_url($p['slug']) . '?k=' . $c['code']; ?>
        <div class="code-card">
          <?php if ($brand['logo']): ?><img class="logo" src="<?= e($brand['logo']) ?>" alt=""><?php endif; ?>
          <div class="t"><?= e($p['title']) ?></div>
          <div class="qrbox" data-qr="<?= e($link) ?>"></div>
          <div class="c"><?= e($c['code']) ?></div>
          <div class="v">Scan to vote · <?= (int) $c['votes_allowed'] ?> vote<?= (int) $c['votes_allowed'] === 1 ? '' : 's' ?> · one phone only</div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
  <script>
    document.querySelectorAll('[data-qr]').forEach(n => {
      const q = qrcode(0, 'M'); q.addData(n.dataset.qr); q.make();
      n.innerHTML = q.createSvgTag({ cellSize: 4, margin: 0, scalable: true });
    });
  </script>
</body>
</html>
