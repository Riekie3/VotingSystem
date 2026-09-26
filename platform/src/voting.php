<?php
// Voting: anonymous device identity, allowances, access codes, casting ballots.
//
// Anonymity: `voters` remembers only HOW MANY submissions a hashed device made;
// `ballots` hold the choices with no device, code or exact time attached.

declare(strict_types=1);

const VOTER_COOKIE = 'vs_vid';

/** Returns the device hash, (re)issuing the random cookie id if needed. */
function device_hash(): string
{
    $vid = $_COOKIE[VOTER_COOKIE] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $vid)) $vid = $_SERVER['HTTP_X_VOTER_ID'] ?? '';
    if (!preg_match('/^[a-f0-9]{32}$/', $vid)) $vid = bin2hex(random_bytes(16));
    setcookie(VOTER_COOKIE, $vid, [
        'expires' => time() + 365 * 86400,
        'path' => base_path() . '/',
        'secure' => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    header('X-Voter-Id: ' . $vid);
    return hash_hmac('sha256', $vid, (string) config('secret', 'vs'));
}

function voter_code(array $p, string $dev): ?array
{
    return one('SELECT * FROM access_codes WHERE poll_id = ? AND claimed_by = ?', [$p['id'], $dev]);
}

/** What this device may still do on this poll. */
function voter_state(array $p, string $dev, ?string $notice = null): array
{
    $code = voter_code($p, $dev);
    if ($code) $allowed = (int) $code['votes_allowed'];
    else $allowed = $p['access'] === 'code' ? 0 : max(1, (int) $p['votes_per_device']);
    $used = (int) (val('SELECT used FROM voters WHERE poll_id = ? AND device_hash = ?', [$p['id'], $dev]) ?? 0);
    return [
        'allowed' => $allowed,
        'used' => $used,
        'remaining' => max(0, $allowed - $used),
        'has_code' => (bool) $code,
        'code_label' => $code['label'] ?? '',
        'needs_code' => !$code && $p['access'] === 'code',
        'notice' => $notice,
    ];
}

/** Claim an access code for this device. Returns a notice key. */
function claim_code(array $p, string $dev, string $code): string
{
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    if ($code === '') return 'code_invalid';
    $row = one('SELECT * FROM access_codes WHERE poll_id = ? AND code = ?', [$p['id'], $code]);
    if (!$row) return 'code_invalid';
    if ($row['claimed_by'] === $dev) return 'code_ok';
    if ($row['claimed_by']) return 'code_claimed';
    if (voter_code($p, $dev)) return 'code_has_other';
    q('UPDATE access_codes SET claimed_by = ? WHERE id = ? AND claimed_by IS NULL', [$dev, $row['id']]);
    return 'code_ok';
}

function new_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I confusion
    $s = '';
    for ($i = 0; $i < 8; $i++) $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $s;
}

/**
 * Validate and store a submission. $picks = [['option_id' => int, 'comment' => string], ...]
 * in rank order. A single-choice poll with several remaining votes may submit several
 * different picks at once; each becomes its own ballot (FY27 "special ballot" behaviour).
 * Returns null on success or an error message.
 */
function cast_vote(array $p, string $dev, array $picks): ?string
{
    if (poll_state($p) !== 'open') return 'Voting is not open right now.';
    $state = voter_state($p, $dev);
    if ($state['needs_code']) return 'Please enter your access code first.';
    if ($state['remaining'] <= 0) return 'You have already used all your votes on this device.';

    $valid = array_column(poll_options((int) $p['id']), null, 'id');
    $clean = [];
    foreach ($picks as $pick) {
        $oid = (int) ($pick['option_id'] ?? 0);
        if (!isset($valid[$oid])) return 'One of your choices is no longer available. Please refresh.';
        if (isset($clean[$oid])) return 'Each pick must be a different option.';
        $comment = clean_text((string) ($pick['comment'] ?? ''), false);
        if ($p['comment_mode'] === 'off') $comment = '';
        $comment = mb_substr($comment, 0, max(1, (int) $p['comment_max']));
        if ($p['comment_mode'] === 'required' && $comment === '') return 'Please fill in every explanation.';
        $clean[$oid] = $comment;
    }

    [$min, $max] = poll_pick_range($p);
    $n = count($clean);
    $ballots = [];
    if ($p['type'] === 'single') {
        if ($n < 1 || $n > $state['remaining']) return $n < 1 ? 'Please pick an option.' : "You only have {$state['remaining']} vote(s) left.";
        foreach ($clean as $oid => $c) $ballots[] = [[$oid, 1, $c]];
    } else {
        if ($n < $min || $n > $max) {
            return $p['type'] === 'ranked' ? "Please rank exactly $min options." : "Please pick between $min and $max options.";
        }
        $rank = 0;
        $ballot = [];
        foreach ($clean as $oid => $c) $ballot[] = [$oid, ++$rank, $c];
        $ballots[] = $ballot;
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Lock the device row so two fast taps cannot both pass the allowance check.
        q('INSERT INTO voters (poll_id, device_hash, used) VALUES (?, ?, 0) ON DUPLICATE KEY UPDATE used = used', [$p['id'], $dev]);
        $used = (int) val('SELECT used FROM voters WHERE poll_id = ? AND device_hash = ? FOR UPDATE', [$p['id'], $dev]);
        if ($used + count($ballots) > $state['allowed']) { $pdo->rollBack(); return 'You have already used all your votes on this device.'; }
        $hour = date('Y-m-d H:00:00');
        foreach ($ballots as $ballot) {
            q('INSERT INTO ballots (poll_id, created_hour) VALUES (?, ?)', [$p['id'], $hour]);
            $bid = (int) $pdo->lastInsertId();
            foreach ($ballot as [$oid, $rank, $c]) {
                q('INSERT INTO ballot_choices (ballot_id, poll_id, option_id, rank_pos, comment) VALUES (?, ?, ?, ?, ?)', [$bid, $p['id'], $oid, $rank, $c === '' ? null : $c]);
            }
        }
        q('UPDATE voters SET used = used + ? WHERE poll_id = ? AND device_hash = ?', [count($ballots), $p['id'], $dev]);
        poll_bump((int) $p['id']);
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        throw $t;
    }
    return null;
}
