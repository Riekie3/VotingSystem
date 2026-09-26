<?php
// Public pages and the voter/projector JSON API.

declare(strict_types=1);

function public_poll_or_404(string $slug, bool $allowDraftForAdmin = true): array
{
    $p = poll_by_slug($slug);
    if (!$p) not_found('This voting page does not exist.');
    if (($p['status'] === 'draft' || $p['archived']) && !($allowDraftForAdmin && admin_user())) {
        not_found($p['archived'] ? 'This vote has ended.' : 'This voting page is not published yet.');
    }
    return $p;
}

function page_home(): void
{
    $mode = setting('home_mode', 'list');
    if ($mode === 'redirect' && ($slug = setting('home_slug')) && poll_by_slug((string) $slug)) redirect((string) $slug);
    $polls = $mode === 'blank' ? [] : all('SELECT * FROM polls WHERE deleted_at IS NULL AND archived = 0 AND status <> "draft" ORDER BY updated_at DESC');
    $polls = array_values(array_filter($polls, fn($p) => in_array(poll_state($p), ['open', 'scheduled'], true)));
    view('home', ['polls' => $polls, 'brand' => branding()]);
}

function page_vote(string $slug): void
{
    $p = public_poll_or_404($slug);
    view('vote', ['p' => $p, 'brand' => branding($p), 'screen' => false]);
}

function page_screen(string $slug): void
{
    $p = public_poll_or_404($slug);
    view('vote', ['p' => $p, 'brand' => branding($p), 'screen' => true]);
}

function page_present(): void
{
    $slugs = array_values(array_filter(array_map('trim', explode(',', (string) ($_GET['p'] ?? '')))));
    $every = max(5, min(600, (int) ($_GET['t'] ?? 20)));
    $polls = [];
    foreach ($slugs as $s) if ($p = poll_by_slug($s)) $polls[] = $p;
    if (!$polls) not_found('No polls selected for this presentation.');
    view('present', ['polls' => $polls, 'every' => $every, 'brand' => branding()]);
}

// ---------- API ----------

const NOTICES = [
    'code_ok'        => 'Access code accepted.',
    'code_invalid'   => 'That code is not valid for this vote.',
    'code_claimed'   => 'That code has already been used on another device.',
    'code_has_other' => 'This device already has an access code for this vote.',
];

function results_allowed(array $p, ?array $me, bool $screen): bool
{
    if ($p['reveal'] === 'hidden') return false;
    if ($screen) return true;
    $closed = poll_state($p) === 'closed';
    return match ($p['results_visibility']) {
        'always' => true,
        'after_vote' => $closed || ($me && $me['used'] > 0),
        'after_close' => $closed,
        default => false,
    };
}

function api_poll(string $slug): void
{
    $p = public_poll_or_404($slug);
    $dev = device_hash();
    $notice = null;
    if (!empty($_GET['k'])) $notice = claim_code($p, $dev, (string) $_GET['k']);
    $me = voter_state($p, $dev, $notice ? (NOTICES[$notice] ?? null) : null);
    $me['notice_ok'] = $notice === 'code_ok';
    json_out(['poll' => poll_public($p), 'me' => $me, 'live' => poll_live($p)]);
}

function api_code(string $slug): void
{
    $p = public_poll_or_404($slug);
    $dev = device_hash();
    $notice = claim_code($p, $dev, (string) (request_json()['code'] ?? ''));
    $me = voter_state($p, $dev, NOTICES[$notice] ?? null);
    $me['notice_ok'] = $notice === 'code_ok';
    json_out(['ok' => $notice === 'code_ok', 'me' => $me], $notice === 'code_ok' ? 200 : 400);
}

function api_results(string $slug): void
{
    $p = public_poll_or_404($slug);
    $screen = ($_GET['view'] ?? '') === 'screen';
    $me = $screen ? null : voter_state($p, device_hash());
    $out = ['live' => poll_live($p), 'me' => $me];
    // Clients send the version they already have; unchanged polls get a tiny reply.
    if ((int) ($_GET['v'] ?? 0) === (int) $p['version'] && !isset($_GET['full'])) json_out($out + ['same' => true]);
    if (results_allowed($p, $me, $screen)) {
        $res = poll_results($p);
        $out += ['results' => $res];
    } else {
        $out += ['results' => null, 'responses' => (int) val('SELECT COUNT(*) FROM ballots WHERE poll_id = ?', [$p['id']])];
    }
    json_out($out);
}

function api_vote(string $slug): void
{
    $p = public_poll_or_404($slug, false);
    $dev = device_hash();
    $body = request_json();
    $picks = is_array($body['picks'] ?? null) ? array_slice($body['picks'], 0, 50) : [];
    $err = cast_vote($p, $dev, $picks);
    $p = poll_by_id((int) $p['id']);
    $me = voter_state($p, $dev);
    if ($err) json_out(['error' => $err, 'me' => $me], 409);
    json_out(['ok' => true, 'me' => $me]);
}

function api_answers(string $slug): void
{
    $p = public_poll_or_404($slug);
    if (!$p['answers_wall'] || $p['reveal'] === 'hidden') json_out(['groups' => []]);
    json_out(['groups' => poll_comments($p)]);
}
