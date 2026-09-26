<?php
/** @var array $o */
/** @var int $v */
$pub = option_public($o + ['id' => 0]);
?>
<div class="opt-row<?= $o['hidden'] ? ' hidden-opt' : '' ?>" draggable="true">
  <span class="handle" title="Drag to reorder" aria-hidden="true">⠿</span>
  <button type="button" class="icon-pick" title="Change icon" data-icon-pick>
    <?php if ($pub['icon']['type'] === 'image'): ?><span class="avatar image"><img src="<?= e($pub['icon']['url']) ?>" alt=""></span>
    <?php elseif ($pub['icon']['type'] === 'emoji'): ?><span class="avatar emoji"><?= e($pub['icon']['value']) ?></span>
    <?php elseif ($pub['icon']['type'] === 'none'): ?><span class="avatar" style="background:var(--surface);color:var(--muted)">—</span>
    <?php else: ?><span class="avatar"><?= e($pub['icon']['value'] ?: '?') ?></span><?php endif; ?>
  </button>
  <div class="inputs">
    <input type="hidden" name="opt_id[]" value="<?= e($o['id']) ?>">
    <input type="hidden" name="opt_icon_type[]" value="<?= e($o['icon_type']) ?>">
    <input type="hidden" name="opt_icon_value[]" value="<?= e($o['icon_value']) ?>">
    <input type="hidden" name="opt_hidden[]" value="<?= $o['hidden'] ? '1' : '0' ?>">
    <input name="opt_label[]" value="<?= e($o['label']) ?>" placeholder="Option name" maxlength="255" aria-label="Option name">
    <input class="desc" name="opt_desc[]" value="<?= e($o['description']) ?>" placeholder="Short description (optional)" maxlength="500" aria-label="Description">
  </div>
  <div class="tools">
    <?php if ($v): ?><span class="votes"><?= $v ?> vote<?= $v === 1 ? '' : 's' ?></span><?php endif; ?>
    <button type="button" class="btn tiny ghost" data-hide-toggle title="Hide from the ballot (keeps its votes)"><?= $o['hidden'] ? 'Show' : 'Hide' ?></button>
    <button type="button" class="btn tiny ghost" data-remove title="<?= $v ? 'Has votes — it will be hidden instead of deleted' : 'Remove' ?>">✕</button>
  </div>
</div>
