<?php
/** @var array $p */
/** @var array $brand */
/** @var bool $screen */
$t = texts($p);
$pageTitle = ($screen ? 'Live results · ' : '') . $p['title'];
require ROOT . '/views/partials/head.php';
$boot = [
    'slug' => $p['slug'],
    'api' => url('api/p/' . rawurlencode($p['slug'])),
    'screen' => $screen,
    'preview' => isset($_GET['preview']),
];
?>
<body class="<?= $screen ? 'screen' : 'voter' ?>">
  <div class="fold">
    <?php require ROOT . '/views/partials/masthead.php'; ?>
    <main class="wrap">
      <section class="hero">
        <?php if ($p['eyebrow'] !== ''): ?><span class="eyebrow"><?= e($p['eyebrow']) ?></span><?php endif; ?>
        <h1><?= e($p['title']) ?></h1>
        <?php if ($p['subtitle'] !== ''): ?><p class="subtitle"><?= e($p['subtitle']) ?></p><?php endif; ?>
        <p class="lock">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
          <?= e($t['anonymous_note']) ?>
        </p>
      </section>

      <div class="screen-layout">
        <div>
          <?php if (!$screen): ?>
          <section class="card" id="ballot" hidden>
            <div class="card-head">
              <h2 id="ballot-title"></h2>
              <span class="status" id="status"></span>
            </div>
            <div class="banner" id="banner" hidden></div>
            <div id="code-box" hidden>
              <p style="margin:0 0 12px;font-weight:700"><?= e($t['code_prompt']) ?></p>
              <form class="code-form" id="code-form">
                <input class="input" id="code-input" maxlength="16" autocomplete="one-time-code" aria-label="Access code" placeholder="ABCD2345">
                <button class="btn accent" type="submit">Continue</button>
              </form>
            </div>
            <div class="grid" id="options" role="group" aria-label="Choices"></div>
          </section>

          <section class="card" id="picks-card" hidden>
            <div class="card-head">
              <h2 id="picks-title"></h2>
              <span class="pill-note" id="why-note"></span>
            </div>
            <ol class="picks" id="picks"></ol>
          </section>

          <section class="card" id="thanks-card" hidden>
            <div class="thanks">
              <div class="tick"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg></div>
              <h3><?= e($t['thanks_title']) ?></h3>
              <p><?= e($t['thanks_body']) ?></p>
            </div>
          </section>
          <?php endif; ?>

          <section class="card" id="results" hidden>
            <div class="card-head">
              <h2><?= e($t['results_title']) ?> <span class="h2-sub" id="top-n"></span></h2>
              <div class="head-right">
                <div class="seg" id="chart-toggle" hidden role="group" aria-label="Chart style">
                  <button type="button" data-chart="bars" title="Ranking bars">▤ Bars</button>
                  <button type="button" data-chart="pie" title="Pie chart">◔ Pie</button>
                  <button type="button" data-chart="both" title="Both">◫ Both</button>
                </div>
                <span class="live" id="live"><i></i><span id="live-text">Live</span></span>
              </div>
            </div>
            <p class="empty" id="empty"><?= e($t['waiting_first']) ?></p>
            <div class="reveal-wait" id="reveal-wait" hidden>
              <div>
                <div class="drum" aria-hidden="true">🥁</div>
                <p class="big"><?= e($t['reveal_waiting']) ?></p>
                <span class="chip"><b id="reveal-count">0</b>&nbsp;responses</span>
              </div>
            </div>
            <p class="empty" id="results-hidden" hidden><?= e($t['results_hidden']) ?></p>
            <div class="viz" id="viz" data-chart="<?= e($p['chart'] ?? 'bars') ?>">
              <div class="pie-wrap" id="pie"></div>
              <ol class="board" id="board"></ol>
            </div>
            <div class="totals" id="totals">
              <span><b id="total">0</b> <span id="total-label">responses</span></span>
              <span id="more"></span>
            </div>
          </section>
        </div>

        <?php if ($screen): ?>
        <aside class="card qr-card" id="qr-card">
          <strong><?= e($t['scan_to_vote']) ?></strong>
          <div class="qr" id="qr"></div>
          <p id="qr-url"></p>
          <span class="chip" id="screen-countdown" hidden></span>
          <p class="scroll-hint" id="scroll-hint" hidden>↓ <?= e($t['answers_title']) ?></p>
        </aside>
        <?php endif; ?>
      </div>

      <?php if (!$screen): ?>
      <div class="votebar" id="votebar" hidden>
        <button class="btn accent" id="vote-btn" disabled></button>
      </div>
      <p class="foot"><?= e($t['footer_note']) ?></p>
      <?php endif; ?>
    </main>
  </div>

  <?php if ($screen): ?>
  <section class="wrap applies" id="applies" hidden>
    <div class="applies-head">
      <h2><?= e($t['answers_title']) ?></h2>
      <span class="live"><i></i><span id="applies-count">0</span></span>
    </div>
    <div class="groups" id="groups"></div>
  </section>
  <?php endif; ?>

  <div class="toast" id="toast" role="status" aria-live="polite"></div>
  <script>window.VS = <?= json_encode($boot, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
  <?php if ($screen): ?><script id="qrlib" async src="https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js"></script><?php endif; ?>
  <script src="<?= e(asset('assets/js/common.js')) ?>"></script>
  <script src="<?= e(asset($screen ? 'assets/js/screen.js' : 'assets/js/vote.js')) ?>"></script>
</body>
</html>
