<?php
/** @var array $polls */
/** @var int $every */
/** @var array $brand */
$pageTitle = 'Presentation · ' . $brand['site_title'];
require ROOT . '/views/partials/head.php';
?>
<body class="screen" style="overflow:hidden">
  <?php foreach ($polls as $i => $p): ?>
    <iframe class="present-frame<?= $i === 0 ? ' on' : '' ?>" src="<?= e(url($p['slug'] . '/results')) ?>" title="<?= e($p['title']) ?>"></iframe>
  <?php endforeach; ?>
  <?php if (count($polls) > 1): ?>
    <div class="present-dots"><?php foreach ($polls as $i => $p): ?><i class="<?= $i === 0 ? 'on' : '' ?>"></i><?php endforeach; ?></div>
  <?php endif; ?>
  <script>
    // Rotate between the selected projector pages. Press → / ← to skip, F for fullscreen.
    (() => {
      const frames = [...document.querySelectorAll('.present-frame')];
      const dots = [...document.querySelectorAll('.present-dots i')];
      if (frames.length < 2) return;
      let i = 0, timer;
      const show = n => {
        i = (n + frames.length) % frames.length;
        frames.forEach((f, k) => f.classList.toggle('on', k === i));
        dots.forEach((d, k) => d.classList.toggle('on', k === i));
        clearTimeout(timer);
        timer = setTimeout(() => show(i + 1), <?= (int) $every ?> * 1000);
      };
      document.addEventListener('keydown', e => {
        if (e.key === 'ArrowRight') show(i + 1);
        if (e.key === 'ArrowLeft') show(i - 1);
        if (e.key === 'f') document.documentElement.requestFullscreen?.();
      });
      show(0);
    })();
  </script>
</body>
</html>
