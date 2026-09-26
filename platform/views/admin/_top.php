<?php
/** @var string $pageTitle */
/** @var string $nav */
$brand = branding();
$noindex = true;
$pageTitle = ($pageTitle ?? 'Admin') . ' · ' . $brand['site_title'] . ' admin';
require ROOT . '/views/partials/head.php';
$trashCount = (int) val('SELECT COUNT(*) FROM polls WHERE deleted_at IS NOT NULL');
$navLink = fn($href, $label, $key, $extra = '') => '<a href="' . e(url($href)) . '"' . (($nav ?? '') === $key ? ' class="on"' : '') . '>' . $label . $extra . '</a>';
?>
<body class="admin">
<div class="admin-shell">
  <nav class="side" aria-label="Admin">
    <div class="brand">
      <?php if ($brand['logo']): ?><img src="<?= e($brand['logo']) ?>" alt=""><?php endif; ?>
      <span><?= e($brand['site_title']) ?></span>
    </div>
    <?= $navLink('admin', '▦ Dashboard', 'dash') ?>
    <?= $navLink('admin/polls/new', '＋ New voting page', 'new') ?>
    <?= $navLink('admin/present', '▶ Presentation mode', 'present') ?>
    <?= $navLink('admin/settings', '⚙ Branding & settings', 'settings') ?>
    <?= $navLink('admin/trash', '🗑 Trash', 'trash', $trashCount ? '<span class="count">' . $trashCount . '</span>' : '') ?>
    <div class="spacer"></div>
    <a href="<?= e(url('')) ?>" target="_blank" rel="noopener">↗ View public site</a>
    <form method="post" action="<?= e(url('admin/logout')) ?>"><?= csrf_field() ?><button class="link">⎋ Log out</button></form>
  </nav>
  <main class="admin-main">
    <?php if ($f = flash()): ?><div class="flash <?= e($f[0]) ?>" role="status"><?= e($f[1]) ?></div><?php endif; ?>
