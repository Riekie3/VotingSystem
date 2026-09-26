<?php /** @var array $brand */ ?>
<header class="masthead">
  <?php if ($brand['logo']): ?>
    <img src="<?= e($brand['logo']) ?>" alt="<?= e($brand['org_name'] ?: $brand['site_title']) ?>">
  <?php else: ?>
    <span class="site-name"><?= e($brand['site_title']) ?></span>
  <?php endif; ?>
</header>
