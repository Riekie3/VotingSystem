<?php
// Import results from the original FY27 Node.js votes (config.json + data/state.json)
// as a closed, archived poll. Only anonymous totals / answers exist in those files,
// so nothing identifying is created.

declare(strict_types=1);

/** Returns [pollId, message] or throws with a readable message. */
function import_legacy(array $config, array $state, string $eyebrow = ''): array
{
    $isRanked = isset($config['options'], $config['points']);
    $isLeader = isset($config['candidates']);
    if (!$isRanked && !$isLeader) throw new RuntimeException('Unrecognised config.json: expected "candidates" (single choice) or "options" + "points" (ranked).');
    if (!isset($state['tally']) || !is_array($state['tally'])) throw new RuntimeException('Unrecognised state.json: no "tally" found.');

    $names = $isRanked ? $config['options'] : $config['candidates'];
    $title = trim((string) ($config['title'] ?? 'Imported vote'));
    $slug = unique_slug('fy27-' . ($isRanked ? 'top-strategies' : 'inspiring-leader'));
    $t = now();

    db()->beginTransaction();
    try {
        q('INSERT INTO polls (slug, type, eyebrow, title, subtitle, picks, points, comment_mode, comment_label, votes_per_device, results_visibility,
            top_n, chart, answers_wall, status, archived, reveal, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $slug, $isRanked ? 'ranked' : 'single', $eyebrow ?: (string) ($config['event'] ?? ''), mb_substr($title, 0, 400),
            mb_substr((string) ($config['subtitle'] ?? ''), 0, 600),
            $isRanked ? (int) $config['picks'] : 1, json_encode($isRanked ? array_values($config['points']) : [3, 2, 1]),
            $isRanked ? (!empty($config['requireExplanation']) ? 'required' : 'optional') : 'off',
            $isRanked ? 'How will you apply it in your work?' : '', (int) ($config['votesPerPerson'] ?? 1), 'always',
            5, 'bars', 1, 'closed', 1, 'live', $t, $t,
        ]);
        $pid = (int) db()->lastInsertId();
        $optId = [];
        foreach (array_values($names) as $i => $name) {
            q('INSERT INTO options (poll_id, label, sort_order) VALUES (?, ?, ?)', [$pid, mb_substr((string) $name, 0, 255), $i]);
            $optId[(string) $name] = (int) db()->lastInsertId();
        }
        $hour = date('Y-m-d H:00:00');
        $ballots = 0;
        $addBallot = function (array $choices) use ($pid, $hour, &$ballots) {
            q('INSERT INTO ballots (poll_id, created_hour) VALUES (?, ?)', [$pid, $hour]);
            $bid = (int) db()->lastInsertId();
            foreach ($choices as [$oid, $rank, $comment]) {
                q('INSERT INTO ballot_choices (ballot_id, poll_id, option_id, rank_pos, comment) VALUES (?,?,?,?,?)', [$bid, $pid, $oid, $rank, $comment ?: null]);
            }
            $ballots++;
        };
        if ($isRanked) {
            // Each response keeps its ranking and the explanations people wrote.
            foreach ($state['responses'] ?? [] as $resp) {
                $choices = [];
                foreach (array_values($resp['ranking'] ?? []) as $i => $pick) {
                    $name = (string) ($pick['name'] ?? '');
                    if (!isset($optId[$name])) throw new RuntimeException("Response mentions “{$name}”, which is not in config.json.");
                    $choices[] = [$optId[$name], $i + 1, clean_text((string) ($pick['why'] ?? ''), false)];
                }
                if ($choices) $addBallot($choices);
            }
        } else {
            // Only per-candidate totals were stored, so recreate one ballot per vote.
            foreach ($state['tally'] as $name => $count) {
                if (!isset($optId[(string) $name])) continue;
                for ($i = 0; $i < (int) $count; $i++) $addBallot([[$optId[(string) $name], 1, '']]);
            }
        }
        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }
    audit('import_legacy', "#$pid $title ($ballots responses)");
    return [$pid, "Imported “{$title}” with {$ballots} responses as an archived poll."];
}
