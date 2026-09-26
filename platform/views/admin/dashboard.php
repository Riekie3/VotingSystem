<?php
/** @var array $polls */
/** @var string $filter */
/** @var array $counts */
$pageTitle = 'Dashboard';
$nav = 'dash';
require ROOT . '/views/admin/_top.php';
$stateLabel = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'open' => 'Open', 'closed' => 'Closed'];
?>
<div class="admin-top">
  <h1>Voting pages</h1>
  <div class="actions"><a class="btn small accent" href="<?= e(url('admin/polls/new')) ?>">＋ New voting page</a></div>
</div>
<div class="tabs">
  <a href="<?= e(url('admin')) ?>" class="<?= $filter === 'active' ? 'on' : '' ?>">Active · <?= $counts['active'] ?></a>
  <a href="<?= e(url('admin?show=archived')) ?>" class="<?= $filter === 'archived' ? 'on' : '' ?>">Archived · <?= $counts['archived'] ?></a>
</div>

<?php if (!$polls): ?>
  <div class="card" style="text-align:center;padding:40px">
    <h2 style="margin:0 0 8px"><?= $filter === 'archived' ? 'Nothing archived yet' : 'No voting pages yet' ?></h2>
    <p class="hint" style="margin:0 0 18px">Create your first voting page. You can change everything later.</p>
    <?php if ($filter !== 'archived'): ?><a class="btn small accent" href="<?= e(url('admin/polls/new')) ?>">＋ New voting page</a><?php endif; ?>
  </div>
<?php endif; ?>

<div class="poll-cards">
  <?php foreach ($polls as $p): $state = poll_state($p); $voteUrl = abs_url($p['slug']); ?>
    <article class="card poll-card">
      <div class="meta">
        <span class="badge <?= e($state) ?>"><?= e($stateLabel[$state]) ?></span>
        <span><?= e(POLL_TYPES[$p['type']] ? explode(' — ', POLL_TYPES[$p['type']])[0] : '') ?></span>
        <?php if ($p['access'] === 'code'): ?><span>· 🔑 Code needed</span><?php endif; ?>
        <?php if ($p['reveal'] === 'hidden'): ?><span>· 🙈 Results hidden</span><?php endif; ?>
      </div>
      <h3><a href="<?= e(url('admin/polls/' . $p['id'])) ?>"><?= e($p['title']) ?></a></h3>
      <div class="meta"><span class="big"><?= (int) $p['responses'] ?></span><span>responses<br><span class="mono">/<?= e($p['slug']) ?></span></span></div>
      <div class="row-actions">
        <a class="btn tiny ghost" href="<?= e(url('admin/polls/' . $p['id'])) ?>">✎ Edit</a>
        <a class="btn tiny ghost" href="<?= e(url('admin/polls/' . $p['id'] . '/results')) ?>">📊 Results</a>
        <a class="btn tiny ghost" href="<?= e(url($p['slug'])) ?>" target="_blank" rel="noopener">📱 Vote page</a>
        <a class="btn tiny ghost" href="<?= e(url($p['slug'] . '/results')) ?>" target="_blank" rel="noopener">🖥 Projector</a>
      </div>
      <div class="row-actions">
        <?php if (!$p['archived']): ?>
        <form method="post" action="<?= e(url('admin/polls/' . $p['id'] . '/action')) ?>">
          <?= csrf_field() ?><input type="hidden" name="back" value="admin">
          <?php if ($state === 'open' || $state === 'scheduled'): ?>
            <button class="btn tiny" name="action" value="close">■ Close voting</button>
          <?php else: ?>
            <button class="btn tiny good" name="action" value="open">▶ Open voting</button>
          <?php endif; ?>
        </form>
        <?php endif; ?>
        <button class="btn tiny ghost" data-copy="<?= e($voteUrl) ?>">🔗 Copy link</button>
        <button class="btn tiny ghost" data-whatsapp data-title="<?= e($p['title']) ?>" data-url="<?= e($voteUrl) ?>">💬 WhatsApp text</button>
        <button class="btn tiny ghost" data-qr-download="<?= e($voteUrl) ?>" data-name="<?= e($p['slug']) ?>">▦ QR</button>
      </div>
    </article>
  <?php endforeach; ?>
</div>
<?php require ROOT . '/views/admin/_bottom.php'; ?>
