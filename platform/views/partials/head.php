<?php
/** @var array $brand */
/** @var string $pageTitle */
$theme = $brand['phone_theme'] ?? 'auto';
$screenTheme = $brand['screen_theme'] ?? 'dark';
?><!doctype html>
<html lang="en"<?= $theme !== 'auto' ? ' data-theme="' . e($theme) . '"' : '' ?> data-screen="<?= e($screenTheme) ?>"
  style="--accent: <?= e($brand['accent']) ?>; --on-accent: <?= e($brand['on_accent']) ?>; --font-display: '<?= e($brand['fonts']['display']) ?>', Georgia, serif; --font-ui: '<?= e($brand['fonts']['ui']) ?>', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($pageTitle) ?></title>
  <?php if (!empty($noindex)): ?><meta name="robots" content="noindex"><?php endif; ?>
  <meta name="theme-color" content="#f5f3ef" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#0a0c10" media="(prefers-color-scheme: dark)">
  <link rel="icon" href="<?= e($brand['favicon']) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?<?= e($brand['fonts']['css']) ?>&display=swap">
  <link rel="stylesheet" href="<?= e(asset('assets/css/app.css')) ?>">
</head>
