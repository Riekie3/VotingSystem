  </main>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>
<script>window.VSA = <?= json_encode(['csrf' => csrf_token(), 'base' => url(''), 'upload' => url('admin/api/upload')], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;</script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script>
<script src="<?= e(asset('assets/js/common.js')) ?>"></script>
<script src="<?= e(url('assets/js/admin.js')) ?>?v=<?= e(asset_version() . (is_file(ROOT . '/assets/js/admin.js') ? filemtime(ROOT . '/assets/js/admin.js') : '')) ?>"></script>
</body>
</html>
