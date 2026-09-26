<?php
// Polls: loading, status, options and results.

declare(strict_types=1);

const POLL_TYPES = [
    'single' => 'Single choice — pick one',
    'multi'  => 'Multiple choice — pick up to N',
    'ranked' => 'Ranked — rank the top N (points)',
];

function poll_by_slug(string $slug): ?array
{
    return one('SELECT * FROM polls WHERE slug = ? AND deleted_at IS NULL', [$slug]);
}

function poll_by_id(int $id, bool $withDeleted = false): ?array
{
    return one('SELECT * FROM polls WHERE id = ?' . ($withDeleted ? '' : ' AND deleted_at IS NULL'), [$id]);
}

/** draft | scheduled | open | closed — taking the open/close schedule into account. */
function poll_state(array $p): string
{
    if ($p['status'] === 'draft') return 'draft';
    if ($p['status'] === 'closed') return 'closed';
    $t = time();
    if ($p['opens_at'] && strtotime($p['opens_at']) > $t) return 'scheduled';
    if ($p['closes_at'] && strtotime($p['closes_at']) <= $t) return 'closed';
    return 'open';
}

function poll_points(array $p): array
{
    $pts = json_decode((string) $p['points'], true);
    $pts = is_array($pts) ? array_values(array_map('intval', $pts)) : [];
    $n = max(1, (int) $p['picks']);
    for ($i = count($pts); $i < $n; $i++) $pts[] = max(1, $n - $i);
    return array_slice($pts, 0, $n);
}

/** How many choices one submission contains (min, max). */
function poll_pick_range(array $p): array
{
    return match ($p['type']) {
        'ranked' => [max(1, (int) $p['picks']), max(1, (int) $p['picks'])],
        'multi'  => [1, max(1, (int) $p['picks'])],
        default  => [1, 1],
    };
}

function poll_bump(int $pollId): void
{
    q('UPDATE polls SET version = version + 1 WHERE id = ?', [$pollId]);
}

function poll_options(int $pollId, bool $includeHidden = false): array
{
    return all('SELECT * FROM options WHERE poll_id = ?' . ($includeHidden ? '' : ' AND hidden = 0') . ' ORDER BY sort_order, id', [$pollId]);
}

function initials(string $label): string
{
    $parts = preg_split('/\s+/', trim(preg_replace('/^(dr|mr|mrs|ms|miss|prof|dato|datuk|tan sri)\.?\s+/i', '', $label)));
    $parts = array_values(array_filter($parts, fn($s) => $s !== '' && preg_match('/\w/u', $s)));
    if (!$parts) return '?';
    if (count($parts) === 1) return mb_strtoupper(mb_substr($parts[0], 0, 2));
    return mb_strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
}

function option_public(array $o): array
{
    $icon = ['type' => $o['icon_type'], 'value' => '', 'url' => ''];
    if ($o['icon_type'] === 'emoji') $icon['value'] = $o['icon_value'];
    elseif ($o['icon_type'] === 'image') $icon['url'] = media_url($o['icon_value']);
    elseif ($o['icon_type'] === 'initials') $icon['value'] = initials($o['label']);
    if ($o['icon_type'] === 'image' && !$icon['url']) $icon = ['type' => 'initials', 'value' => initials($o['label']), 'url' => ''];
    return ['id' => (int) $o['id'], 'label' => $o['label'], 'description' => $o['description'], 'icon' => $icon];
}

/**
 * Results for a poll. Single/multi score = number of votes; ranked score = points.
 * Rows are sorted best first (ties broken by more 1st places, then 2nd, …, then option order).
 */
function poll_results(array $p): array
{
    $isRanked = $p['type'] === 'ranked';
    $points = poll_points($p);
    $nRanks = $isRanked ? count($points) : 1;
    $opts = poll_options((int) $p['id'], true);
    $rows = [];
    foreach ($opts as $i => $o) {
        $rows[(int) $o['id']] = option_public($o) + ['votes' => 0, 'score' => 0, 'ranks' => array_fill(0, $nRanks, 0), 'order' => $i, 'hidden' => (int) $o['hidden']];
    }
    foreach (all('SELECT option_id, rank_pos, COUNT(*) c FROM ballot_choices WHERE poll_id = ? GROUP BY option_id, rank_pos', [$p['id']]) as $r) {
        $id = (int) $r['option_id'];
        if (!isset($rows[$id])) continue;
        $c = (int) $r['c'];
        $rows[$id]['votes'] += $c;
        $pos = max(1, (int) $r['rank_pos']) - 1;
        if ($isRanked && $pos < $nRanks) {
            $rows[$id]['ranks'][$pos] += $c;
            $rows[$id]['score'] += $c * $points[$pos];
        }
    }
    $responses = (int) val('SELECT COUNT(*) FROM ballots WHERE poll_id = ?', [$p['id']]);
    foreach ($rows as &$r) {
        if (!$isRanked) $r['score'] = $r['votes'];
        $r['pct'] = $responses ? (int) round($r['votes'] / $responses * 100) : 0;
    }
    unset($r);
    $rows = array_values(array_filter($rows, fn($r) => !$r['hidden'] || $r['votes'] > 0));
    usort($rows, function ($a, $b) {
        if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
        foreach ($a['ranks'] as $i => $n) if ($n !== $b['ranks'][$i]) return $b['ranks'][$i] <=> $n;
        return $a['order'] <=> $b['order'];
    });
    $rank = 0; $last = null;
    foreach ($rows as $i => &$r) {
        if ($r['score'] !== $last) { $rank = $i + 1; $last = $r['score']; }
        $r['rank'] = $rank;
        unset($r['order'], $r['hidden']);
    }
    unset($r);
    return ['rows' => $rows, 'responses' => $responses, 'unit' => $isRanked ? 'pts' : 'votes'];
}

/** Comments grouped by option, best-scoring option first (answers wall / admin). */
function poll_comments(array $p, bool $includeHidden = false): array
{
    $res = poll_results($p);
    $groups = [];
    foreach ($res['rows'] as $r) $groups[$r['id']] = ['id' => $r['id'], 'label' => $r['label'], 'score' => $r['score'], 'items' => []];
    $sql = 'SELECT id, option_id, rank_pos, comment, comment_hidden FROM ballot_choices WHERE poll_id = ? AND comment IS NOT NULL AND comment <> ""'
        . ($includeHidden ? '' : ' AND comment_hidden = 0') . ' ORDER BY rank_pos, id';
    foreach (all($sql, [$p['id']]) as $c) {
        $oid = (int) $c['option_id'];
        if (!isset($groups[$oid])) continue;
        $groups[$oid]['items'][] = ['id' => (int) $c['id'], 'rank' => (int) $c['rank_pos'], 'text' => $c['comment'], 'hidden' => (int) $c['comment_hidden']];
    }
    return array_values(array_filter($groups, fn($g) => $g['items']));
}

function slugify(string $s): string
{
    $s = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $s), '-'));
    return substr($s, 0, 60) ?: 'poll';
}

function unique_slug(string $base, int $exceptId = 0): string
{
    $base = slugify($base);
    if (in_array($base, RESERVED_SLUGS, true)) $base .= '-poll';
    $slug = $base;
    for ($i = 2; val('SELECT id FROM polls WHERE slug = ? AND id <> ?', [$slug, $exceptId]); $i++) $slug = $base . '-' . $i;
    return $slug;
}

/** Copy a poll with its options (no votes, codes or voters). Returns the new id. */
function poll_duplicate(int $id): int
{
    $p = poll_by_id($id, true);
    $cols = ['type', 'eyebrow', 'subtitle', 'picks', 'points', 'comment_mode', 'comment_label', 'comment_max', 'votes_per_device', 'access',
        'results_visibility', 'top_n', 'chart', 'answers_wall', 'logo_media_id', 'accent', 'texts'];
    $data = array_intersect_key($p, array_flip($cols));
    $data['title'] = $p['title'] . ' (copy)';
    $data['slug'] = unique_slug($p['slug'] . '-copy');
    $data['status'] = 'draft';
    $data['created_at'] = $data['updated_at'] = now();
    q('INSERT INTO polls (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')', array_values($data));
    $newId = (int) db()->lastInsertId();
    foreach (poll_options($id, true) as $o) {
        q('INSERT INTO options (poll_id, label, description, icon_type, icon_value, sort_order, hidden) VALUES (?,?,?,?,?,?,?)',
            [$newId, $o['label'], $o['description'], $o['icon_type'], $o['icon_value'], $o['sort_order'], $o['hidden']]);
    }
    audit('poll_duplicate', "#$id → #$newId");
    return $newId;
}

function poll_reset(int $id): void
{
    q('DELETE FROM ballots WHERE poll_id = ?', [$id]);
    q('DELETE FROM voters WHERE poll_id = ?', [$id]);
    q('UPDATE access_codes SET claimed_by = NULL WHERE poll_id = ?', [$id]);
    q('UPDATE polls SET reveal = IF(reveal = "revealed", "hidden", reveal) WHERE id = ?', [$id]);
    poll_bump($id);
    audit('poll_reset', "#$id");
}

/** Everything the public pages need about a poll (no secrets). */
function poll_public(array $p): array
{
    [$min, $max] = poll_pick_range($p);
    return [
        'slug' => $p['slug'],
        'type' => $p['type'],
        'eyebrow' => $p['eyebrow'],
        'title' => $p['title'],
        'subtitle' => $p['subtitle'],
        'picks_min' => $min,
        'picks_max' => $max,
        'points' => $p['type'] === 'ranked' ? poll_points($p) : [],
        'comment_mode' => $p['comment_mode'],
        'comment_label' => $p['comment_label'],
        'comment_max' => (int) $p['comment_max'],
        'access' => $p['access'],
        'results_visibility' => $p['results_visibility'],
        'top_n' => max(1, min(20, (int) $p['top_n'])),
        'chart' => $p['chart'] ?? 'bars',
        'answers_wall' => (bool) $p['answers_wall'],
        'options' => array_map('option_public', poll_options((int) $p['id'])),
        'texts' => texts($p),
        'vote_url' => abs_url($p['slug']),
    ];
}

/** Live state that changes while a poll runs (sent with every results update). */
function poll_live(array $p): array
{
    return [
        'state' => poll_state($p),
        'reveal' => $p['reveal'],
        'opens_at' => $p['opens_at'] ? strtotime($p['opens_at']) * 1000 : null,
        'closes_at' => $p['closes_at'] ? strtotime($p['closes_at']) * 1000 : null,
        'server_now' => (int) (microtime(true) * 1000),
        'version' => (int) $p['version'],
        'build' => asset_version(),
    ];
}
