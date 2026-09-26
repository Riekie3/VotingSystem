<?php
/** @var array $polls */
/** @var array $brand */
$pageTitle = $brand['site_title'];
require ROOT . '/views/partials/head.php';
?>
<body>
  <?php require ROOT . '/views/partials/masthead.php'; ?>
  <main class="wrap">
    <section class="hero">
      <?php if ($brand['org_name']): ?><span class="eyebrow"><?= e($brand['org_name']) ?></span><?php endif; ?>
      <h1><?= e($brand['site_title']) ?></h1>
      <p class="lock"><?= $polls ? 'Choose a vote below.' : 'There are no open votes right now. Please check back soon.' ?></p>
    </section>
    <div class="poll-list">
      <?php foreach ($polls as $p): ?>
        <a class="card poll-link" href="<?= e(url($p['slug'])) ?>">
          <div>
            <h3><?= e($p['title']) ?></h3>
            <p><?= e(poll_state($p) === 'scheduled' ? 'Opens soon' : ($p['subtitle'] ?: 'Tap to vote')) ?></p>
          </div>
          <span class="go">→</span>
        </a>
      <?php endforeach; ?>
    </div>
  </main>
</body>
</html>
