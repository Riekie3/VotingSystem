<?php
/** @var array $p */
/** @var array $options */
/** @var array $votesByOption */
/** @var int $responses */
$pageTitle = 'Edit · ' . $p['title'];
$nav = 'dash';
require ROOT . '/views/admin/_top.php';
$state = poll_state($p);
$dtl = fn($v) => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$globalTexts = texts(null);
$pollTexts = json_decode((string) $p['texts'], true) ?: [];
$sel = fn($a, $b) => $a === $b ? ' selected' : '';
$stateLabel = ['draft' => 'Draft', 'scheduled' => 'Scheduled', 'open' => 'Open', 'closed' => 'Closed'];
$logoUrl = media_url($p['logo_media_id']);
$act = url('admin/polls/' . $p['id'] . '/action');
?>
<div class="crumb"><a href="<?= e(url('admin')) ?>">Dashboard</a> / Edit</div>
<div class="admin-top">
  <h1><?= e($p['title']) ?></h1>
  <div class="actions">
    <span class="badge <?= e($state) ?>" style="align-self:center"><?= e($stateLabel[$state]) ?></span>
    <?php if ($state === 'open' || $state === 'scheduled'): ?>
      <button class="btn small" form="act" name="action" value="close">■ Close voting</button>
    <?php else: ?>
      <button class="btn small good" form="act" name="action" value="open">▶ Open voting now</button>
    <?php endif; ?>
    <a class="btn small ghost" href="<?= e(url('admin/polls/' . $p['id'] . '/results')) ?>">📊 Results (<?= $responses ?>)</a>
    <a class="btn small ghost" href="<?= e(url('admin/polls/' . $p['id'] . '/codes')) ?>">🔑 Access codes</a>
  </div>
</div>
<form id="act" method="post" action="<?= e($act) ?>"><?= csrf_field() ?></form>

<div class="editor">
  <form method="post" id="poll-form" autocomplete="off">
    <?= csrf_field() ?>

    <details class="card section" open>
      <summary>The question <small>— title, link and type</small></summary>
      <div class="body fields">
        <label class="field full"><span>Question / title</span><input name="title" value="<?= e($p['title']) ?>" maxlength="400" required></label>
        <label class="field full"><span>Subtitle <small>(optional)</small></span><input name="subtitle" value="<?= e($p['subtitle']) ?>" maxlength="600" placeholder="e.g. (For each strategy, briefly explain how you will apply it)"></label>
        <label class="field"><span>Small label above the title</span><input name="eyebrow" value="<?= e($p['eyebrow']) ?>" maxlength="120" placeholder="e.g. WKC FY28 Retreat"></label>
        <label class="field"><span>Link name</span><input name="slug" value="<?= e($p['slug']) ?>" maxlength="60" pattern="[A-Za-z0-9-]+">
          <small class="mono"><?= e(abs_url('')) ?><b data-slug-preview><?= e($p['slug']) ?></b></small></label>
        <label class="field"><span>Type of vote</span>
          <select name="type" data-type-select>
            <?php foreach (POLL_TYPES as $k => $label): ?><option value="<?= e($k) ?>"<?= $sel($p['type'], $k) ?>><?= e($label) ?></option><?php endforeach; ?>
          </select>
          <?php if ($responses): ?><small>⚠ This poll already has <?= $responses ?> responses. Changing the type or picks affects how existing results are counted.</small><?php endif; ?>
        </label>
        <label class="field" data-show-for="multi ranked"><span data-picks-label>How many picks</span><input type="number" name="picks" min="1" max="20" value="<?= (int) $p['picks'] ?>"></label>
        <label class="field" data-show-for="ranked"><span>Points for 1st, 2nd, 3rd…</span><input name="points" value="<?= e(implode(', ', poll_points($p))) ?>" placeholder="3, 2, 1"><small>Ties are broken by more 1st places, then 2nd…</small></label>
      </div>
    </details>

    <details class="card section" open>
      <summary>Options <small>— <?= count($options) ?> · drag ⠿ to reorder · tap the icon to change it</small></summary>
      <div class="body">
        <div class="opt-rows" id="opt-rows">
          <?php foreach ($options as $o): $v = $votesByOption[(int) $o['id']] ?? 0; ?>
            <?php require ROOT . '/views/admin/_option_row.php'; ?>
          <?php endforeach; ?>
        </div>
        <template id="opt-template"><?php $o = ['id' => '', 'label' => '', 'description' => '', 'icon_type' => 'initials', 'icon_value' => '', 'hidden' => 0]; $v = 0; require ROOT . '/views/admin/_option_row.php'; ?></template>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
          <button type="button" class="btn small ghost" id="add-option">＋ Add option</button>
          <button type="button" class="btn small ghost" id="bulk-add">☰ Paste a list</button>
        </div>
        <div id="bulk-box" hidden style="margin-top:12px">
          <label class="field"><span>One option per line</span><textarea id="bulk-text" rows="6"></textarea></label>
          <button type="button" class="btn small" id="bulk-apply" style="margin-top:8px">Add these</button>
        </div>
      </div>
    </details>

    <details class="card section">
      <summary>Voting rules <small>— votes per device, access codes, comments</small></summary>
      <div class="body fields">
        <label class="field"><span>Votes per device</span><input type="number" name="votes_per_device" min="1" max="50" value="<?= (int) $p['votes_per_device'] ?>">
          <small>How many times one phone can submit. Access codes can give specific people more.</small></label>
        <label class="field"><span>Who can vote</span>
          <select name="access">
            <option value="open"<?= $sel($p['access'], 'open') ?>>Anyone with the link</option>
            <option value="code"<?= $sel($p['access'], 'code') ?>>Only people with an access code (QR cards)</option>
          </select><small><a href="<?= e(url('admin/polls/' . $p['id'] . '/codes')) ?>">Manage access codes →</a></small></label>
        <label class="field"><span>Comment with each pick</span>
          <select name="comment_mode">
            <option value="off"<?= $sel($p['comment_mode'], 'off') ?>>No comments</option>
            <option value="optional"<?= $sel($p['comment_mode'], 'optional') ?>>Optional</option>
            <option value="required"<?= $sel($p['comment_mode'], 'required') ?>>Required</option>
          </select></label>
        <label class="field"><span>Comment question</span><input name="comment_label" value="<?= e($p['comment_label']) ?>" maxlength="255" placeholder="How will you apply it in your work?"></label>
        <label class="field"><span>Max comment length</span><input type="number" name="comment_max" min="20" max="2000" value="<?= (int) $p['comment_max'] ?>"></label>
      </div>
    </details>

    <details class="card section">
      <summary>Results &amp; projector <small>— who sees results, top N, answers wall</small></summary>
      <div class="body fields">
        <label class="field"><span>Show results on phones</span>
          <select name="results_visibility">
            <option value="always"<?= $sel($p['results_visibility'], 'always') ?>>Always (live)</option>
            <option value="after_vote"<?= $sel($p['results_visibility'], 'after_vote') ?>>Only after they vote</option>
            <option value="after_close"<?= $sel($p['results_visibility'], 'after_close') ?>>Only after voting closes</option>
            <option value="hidden"<?= $sel($p['results_visibility'], 'hidden') ?>>Never (projector only)</option>
          </select></label>
        <label class="field"><span>Rows on the projector</span><input type="number" name="top_n" min="1" max="20" value="<?= (int) $p['top_n'] ?>"><small>Top 5 fits a projector best.</small></label>
        <label class="field"><span>Chart style</span>
          <select name="chart">
            <option value="bars"<?= $sel($p['chart'], 'bars') ?>>Bars (ranking list)</option>
            <option value="pie"<?= $sel($p['chart'], 'pie') ?>>Pie chart</option>
            <option value="both"<?= $sel($p['chart'], 'both') ?>>Both side by side</option>
          </select><small>Default view. The projector and phones also get a small switch to change it live.</small></label>
        <label class="field full check"><input type="checkbox" name="answers_wall" value="1" <?= $p['answers_wall'] ? 'checked' : '' ?>>
          <span>Show comments below the projector view (scroll down). You can hide any single comment on the Results page.</span></label>
        <div class="field full">
          <span>Reveal mode — for award moments</span>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn tiny ghost" form="act" name="action" value="reveal_hide" <?= $p['reveal'] === 'hidden' ? 'disabled' : '' ?>>🙈 Hide results</button>
            <button class="btn tiny accent" form="act" name="action" value="reveal_show" <?= $p['reveal'] !== 'hidden' ? 'disabled' : '' ?>>🥁 Reveal now (5th → 1st)</button>
            <button class="btn tiny ghost" form="act" name="action" value="reveal_live" <?= $p['reveal'] === 'live' ? 'disabled' : '' ?>>Back to normal live results</button>
          </div>
          <small>Now: <b><?= e(['live' => 'Live results', 'hidden' => 'Hidden — waiting for your reveal', 'revealed' => 'Revealed'][$p['reveal']]) ?></b>. Hide the results before voting starts, then press Reveal when you're ready on stage.</small>
        </div>
      </div>
    </details>

    <details class="card section" open>
      <summary>Status &amp; schedule</summary>
      <div class="body fields">
        <label class="field"><span>Status</span>
          <select name="status">
            <option value="draft"<?= $sel($p['status'], 'draft') ?>>Draft — hidden from the public</option>
            <option value="open"<?= $sel($p['status'], 'open') ?>>Published — open (within the times below)</option>
            <option value="closed"<?= $sel($p['status'], 'closed') ?>>Closed — results only</option>
          </select></label>
        <label class="field"><span>Opens at <small>(optional)</small></span><input type="datetime-local" name="opens_at" value="<?= e($dtl($p['opens_at'])) ?>"></label>
        <label class="field"><span>Closes at <small>(optional)</small></span><input type="datetime-local" name="closes_at" value="<?= e($dtl($p['closes_at'])) ?>"><small>Shows a countdown on phones and the projector.</small></label>
      </div>
    </details>

    <details class="card section">
      <summary>Look <small>— logo and colour for this page</small></summary>
      <div class="body fields">
        <div class="field full"><span>Logo for this page <small>(leave empty to use the site logo)</small></span>
          <div class="media-pick" data-media>
            <div class="thumb"><?= $logoUrl ? '<img src="' . e($logoUrl) . '" alt="">' : '<small>Site logo</small>' ?></div>
            <input type="hidden" name="logo_media_id" value="<?= (int) $p['logo_media_id'] ?: '' ?>">
            <label class="btn tiny ghost">Upload…<input type="file" accept="image/*" hidden></label>
            <button type="button" class="btn tiny ghost" data-media-clear>Use site logo</button>
          </div></div>
        <div class="field"><span>Accent colour</span>
          <div class="color-row">
            <label class="check"><input type="checkbox" name="accent_use" value="1" <?= $p['accent'] ? 'checked' : '' ?>> <span>Own colour</span></label>
            <input type="color" name="accent" value="<?= e($p['accent'] ?: setting('accent')) ?>">
          </div></div>
      </div>
    </details>

    <details class="card section">
      <summary>Wording <small>— change any text on this page (empty = use the site default)</small></summary>
      <div class="body fields">
        <?php foreach (TEXT_DEFAULTS as $k => [$label]): ?>
          <label class="field"><span><?= e($label) ?></span><input name="text[<?= e($k) ?>]" value="<?= e($pollTexts[$k] ?? '') ?>" placeholder="<?= e($globalTexts[$k]) ?>" maxlength="300"></label>
        <?php endforeach; ?>
      </div>
    </details>

    <div class="savebar">
      <span class="note">Changes go live for everyone the moment you save.</span>
      <button class="btn small accent">Save changes</button>
    </div>
  </form>

  <aside class="preview">
    <div class="card" style="margin:0">
      <div class="card-head"><h2>Preview</h2><button class="btn tiny ghost" type="button" id="reload-preview">↻ Refresh</button></div>
      <div class="preview-frames">
        <div class="screen-frame" id="screen-frame"><iframe src="<?= e(url($p['slug'] . '/results')) ?>" title="Projector preview" loading="lazy"></iframe></div>
        <div class="phone-frame"><iframe src="<?= e(url($p['slug'] . '?preview=1')) ?>" title="Phone preview" loading="lazy"></iframe></div>
      </div>
      <div style="display:grid;gap:8px;margin-top:14px">
        <button class="btn tiny ghost" form="act" name="action" value="duplicate">⧉ Duplicate this poll</button>
        <button class="btn tiny ghost" form="act" name="action" value="<?= $p['archived'] ? 'unarchive' : 'archive' ?>"><?= $p['archived'] ? '↺ Restore from archive' : '🗄 Archive' ?></button>
        <button class="btn tiny ghost" form="act" name="action" value="reset" data-confirm="Delete ALL <?= $responses ?> votes for this poll? This cannot be undone." data-confirm-type="RESET">⟲ Reset votes</button>
        <button class="btn tiny danger" form="act" name="action" value="trash" data-confirm="Move this poll to the Trash? You can restore it from there.">🗑 Move to Trash</button>
      </div>
    </div>
  </aside>
</div>
<?php require ROOT . '/views/admin/_bottom.php'; ?>
