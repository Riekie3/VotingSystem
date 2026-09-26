<?php
// Admin panel controllers.

declare(strict_types=1);

function admin_login(): void
{
    if (admin_user()) redirect('admin');
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $error = login_attempt(trim((string) ($_POST['username'] ?? '')), (string) ($_POST['password'] ?? ''));
        if (!$error) redirect('admin');
    }
    view('admin/login', ['error' => $error, 'installed' => isset($_GET['installed'])]);
}

function admin_logout(): void
{
    csrf_check();
    logout();
    redirect('admin/login');
}

function admin_dashboard(): void
{
    require_admin();
    $filter = in_array($_GET['show'] ?? '', ['archived'], true) ? 'archived' : 'active';
    $polls = all('SELECT p.*, (SELECT COUNT(*) FROM ballots b WHERE b.poll_id = p.id) AS responses
        FROM polls p WHERE p.deleted_at IS NULL AND p.archived = ? ORDER BY p.updated_at DESC', [$filter === 'archived' ? 1 : 0]);
    $counts = [
        'active' => (int) val('SELECT COUNT(*) FROM polls WHERE deleted_at IS NULL AND archived = 0'),
        'archived' => (int) val('SELECT COUNT(*) FROM polls WHERE deleted_at IS NULL AND archived = 1'),
        'trash' => (int) val('SELECT COUNT(*) FROM polls WHERE deleted_at IS NOT NULL'),
    ];
    view('admin/dashboard', ['polls' => $polls, 'filter' => $filter, 'counts' => $counts]);
}

function admin_poll_new(): void
{
    require_admin();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { view('admin/poll_new', []); return; }
    csrf_check();
    $type = array_key_exists($_POST['type'] ?? '', POLL_TYPES) ? $_POST['type'] : 'single';
    $title = clean_text((string) ($_POST['title'] ?? '')) ?: 'Untitled poll';
    $lines = array_values(array_filter(array_map('clean_list_item', preg_split('/\R/', clean_text((string) ($_POST['options'] ?? ''), false)))));
    $t = now();
    db()->beginTransaction();
    q('INSERT INTO polls (slug, type, title, eyebrow, picks, points, comment_label, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)', [
        unique_slug($title), $type, mb_substr($title, 0, 400), setting('org_name', ''),
        $type === 'single' ? 1 : 3, '[3,2,1]', $type === 'ranked' ? 'How will you apply it in your work?' : 'Why?', $t, $t,
    ]);
    $id = (int) db()->lastInsertId();
    foreach ($lines as $i => $label) {
        q('INSERT INTO options (poll_id, label, sort_order) VALUES (?, ?, ?)', [$id, mb_substr($label, 0, 255), $i]);
    }
    db()->commit();
    audit('poll_create', "#$id $title");
    flash('Poll created. Adjust anything below, then publish it.');
    redirect("admin/polls/$id");
}

function admin_poll_edit(int $id): void
{
    require_admin();
    $p = poll_by_id($id) ?? not_found('Poll not found.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $errors = admin_poll_save($p);
        if (!$errors) redirect("admin/polls/$id");
        flash(implode(' ', $errors), 'error');
        redirect("admin/polls/$id");
    }
    $responses = (int) val('SELECT COUNT(*) FROM ballots WHERE poll_id = ?', [$id]);
    $options = poll_options($id, true);
    $votesByOption = [];
    foreach (all('SELECT option_id, COUNT(*) c FROM ballot_choices WHERE poll_id = ? GROUP BY option_id', [$id]) as $r) $votesByOption[(int) $r['option_id']] = (int) $r['c'];
    view('admin/poll_edit', ['p' => $p, 'options' => $options, 'votesByOption' => $votesByOption, 'responses' => $responses]);
}

/** Save the poll editor form. Returns a list of errors (empty on success). */
function admin_poll_save(array $p): array
{
    $id = (int) $p['id'];
    $in = fn(string $k, $d = '') => is_string($_POST[$k] ?? null) ? clean_text($_POST[$k]) : $d;
    $errors = [];

    $title = $in('title');
    if ($title === '') $errors[] = 'The question/title cannot be empty.';
    $slug = slugify($in('slug') ?: $title);
    if (in_array($slug, RESERVED_SLUGS, true)) $errors[] = "The link name “{$slug}” is reserved; pick another.";
    elseif (val('SELECT id FROM polls WHERE slug = ? AND id <> ?', [$slug, $id])) $errors[] = "The link name “{$slug}” is already used by another poll.";

    $type = array_key_exists($in('type'), POLL_TYPES) ? $in('type') : 'single';
    $picks = max(1, min(20, (int) $in('picks', '1')));
    if ($type === 'single') $picks = 1;
    $points = array_values(array_filter(array_map('intval', preg_split('/[\s,]+/', $in('points', '3,2,1'))), fn($n) => $n > 0));
    $dt = function (string $v): ?string {
        if ($v === '') return null;
        $ts = strtotime($v);
        return $ts ? date('Y-m-d H:i:s', $ts) : null;
    };
    $opensAt = $dt($in('opens_at'));
    $closesAt = $dt($in('closes_at'));
    if ($opensAt && $closesAt && strtotime($closesAt) <= strtotime($opensAt)) $errors[] = 'Closing time must be after opening time.';

    // Options: at least 2 visible options with a label.
    $ids = (array) ($_POST['opt_id'] ?? []);
    $labels = (array) ($_POST['opt_label'] ?? []);
    $rows = [];
    foreach ($labels as $i => $label) {
        $label = clean_text((string) $label);
        if ($label === '') continue;
        $rows[] = [
            'id' => (int) ($ids[$i] ?? 0),
            'label' => mb_substr($label, 0, 255),
            'description' => mb_substr(clean_text((string) ($_POST['opt_desc'][$i] ?? '')), 0, 500),
            'icon_type' => in_array($_POST['opt_icon_type'][$i] ?? '', ['initials', 'emoji', 'image', 'none'], true) ? $_POST['opt_icon_type'][$i] : 'initials',
            'icon_value' => mb_substr(trim((string) ($_POST['opt_icon_value'][$i] ?? '')), 0, 64),
            'hidden' => ($_POST['opt_hidden'][$i] ?? '0') === '1' ? 1 : 0,
        ];
    }
    $visible = count(array_filter($rows, fn($r) => !$r['hidden']));
    if ($visible < 2) $errors[] = 'Add at least 2 visible options.';
    if ($type !== 'single' && $picks > $visible) $errors[] = "You asked for $picks picks but there are only $visible visible options.";
    if ($errors) return $errors;

    $texts = [];
    foreach (TEXT_DEFAULTS as $k => $_) {
        $v = trim((string) ($_POST['text'][$k] ?? ''));
        if ($v !== '') $texts[$k] = mb_substr($v, 0, 300);
    }
    $accent = $in('accent_use') === '1' && preg_match('/^#[0-9a-f]{6}$/i', $in('accent')) ? $in('accent') : '';
    $status = in_array($in('status'), ['draft', 'open', 'closed'], true) ? $in('status') : $p['status'];

    q('UPDATE polls SET slug=?, type=?, eyebrow=?, title=?, subtitle=?, picks=?, points=?, comment_mode=?, comment_label=?, comment_max=?,
        votes_per_device=?, access=?, results_visibility=?, top_n=?, chart=?, answers_wall=?, status=?, opens_at=?, closes_at=?, logo_media_id=?,
        accent=?, texts=?, updated_at=?, version = version + 1 WHERE id=?', [
        $slug, $type, mb_substr($in('eyebrow'), 0, 120), mb_substr($title, 0, 400), mb_substr($in('subtitle'), 0, 600),
        $picks, json_encode($points ?: [3, 2, 1]),
        in_array($in('comment_mode'), ['off', 'optional', 'required'], true) ? $in('comment_mode') : 'off',
        mb_substr($in('comment_label'), 0, 255), max(20, min(2000, (int) $in('comment_max', '500'))),
        max(1, min(50, (int) $in('votes_per_device', '1'))),
        $in('access') === 'code' ? 'code' : 'open',
        in_array($in('results_visibility'), ['always', 'after_vote', 'after_close', 'hidden'], true) ? $in('results_visibility') : 'always',
        max(1, min(20, (int) $in('top_n', '5'))), in_array($in('chart'), ['bars', 'pie', 'both'], true) ? $in('chart') : 'bars',
        $in('answers_wall') === '1' ? 1 : 0,
        $status, $opensAt, $closesAt, ((int) $in('logo_media_id')) ?: null, $accent, json_encode($texts, JSON_UNESCAPED_UNICODE), now(), $id,
    ]);

    // Sync options: update kept ones, insert new ones, delete removed ones
    // (options that already have votes are hidden instead, so results stay intact).
    $existing = array_column(poll_options($id, true), null, 'id');
    $kept = [];
    foreach ($rows as $order => $r) {
        if ($r['id'] && isset($existing[$r['id']])) {
            q('UPDATE options SET label=?, description=?, icon_type=?, icon_value=?, hidden=?, sort_order=? WHERE id=? AND poll_id=?',
                [$r['label'], $r['description'], $r['icon_type'], $r['icon_value'], $r['hidden'], $order, $r['id'], $id]);
            $kept[$r['id']] = true;
        } else {
            q('INSERT INTO options (poll_id, label, description, icon_type, icon_value, hidden, sort_order) VALUES (?,?,?,?,?,?,?)',
                [$id, $r['label'], $r['description'], $r['icon_type'], $r['icon_value'], $r['hidden'], $order]);
        }
    }
    $keptHidden = 0;
    foreach ($existing as $oid => $o) {
        if (isset($kept[$oid])) continue;
        if (val('SELECT 1 FROM ballot_choices WHERE option_id = ? LIMIT 1', [$oid])) {
            q('UPDATE options SET hidden = 1, sort_order = 999 WHERE id = ?', [$oid]);
            $keptHidden++;
        } else {
            q('DELETE FROM options WHERE id = ?', [$oid]);
        }
    }
    audit('poll_save', "#$id $title");
    flash('Saved.' . ($keptHidden ? " $keptHidden removed option(s) already had votes, so they were hidden instead of deleted." : ''));
    return [];
}

function admin_poll_action(int $id): void
{
    require_admin();
    csrf_check();
    $p = poll_by_id($id, true) ?? not_found('Poll not found.');
    $action = (string) ($_POST['action'] ?? '');
    $back = (string) ($_POST['back'] ?? "admin/polls/$id");
    $set = function (string $sql, array $params = []) use ($id) {
        q("UPDATE polls SET $sql, updated_at = ?, version = version + 1 WHERE id = ?", array_merge($params, [now(), $id]));
    };
    switch ($action) {
        case 'open':
            $set('status = "open", opens_at = IF(opens_at > NOW(), NULL, opens_at), closes_at = IF(closes_at <= NOW(), NULL, closes_at)');
            flash('Voting is open.');
            break;
        case 'close':   $set('status = "closed"'); flash('Voting is closed.'); break;
        case 'draft':   $set('status = "draft"'); flash('Moved back to draft (hidden from the public).'); break;
        case 'reset':   poll_reset($id); flash('All votes were cleared.'); break;
        case 'duplicate':
            $new = poll_duplicate($id);
            flash('Copy created. It is a draft; edit it and publish when ready.');
            redirect("admin/polls/$new");
        case 'archive':   $set('archived = 1'); flash('Archived. Find it under “Archived” on the dashboard.'); $back = 'admin'; break;
        case 'unarchive': $set('archived = 0'); flash('Restored from the archive.'); break;
        case 'trash':     $set('deleted_at = ?', [now()]); flash('Moved to Trash. You can restore it from the Trash page.'); $back = 'admin'; break;
        case 'restore':   $set('deleted_at = NULL'); flash('Restored from Trash.'); $back = "admin/polls/$id"; break;
        case 'destroy':
            if (!$p['deleted_at']) not_found();
            q('DELETE FROM polls WHERE id = ?', [$id]);
            audit('poll_destroy', "#$id {$p['title']}");
            flash('Deleted permanently.');
            redirect('admin/trash');
        case 'reveal_hide': $set('reveal = "hidden"'); flash('Results are hidden on the projector until you reveal them.'); break;
        case 'reveal_show': $set('reveal = "revealed"'); flash('Revealing results on the projector now.'); break;
        case 'reveal_live': $set('reveal = "live"'); flash('Results are live again.'); break;
        default: not_found('Unknown action.');
    }
    audit('poll_' . $action, "#$id");
    redirect($back);
}

function admin_poll_results(int $id): void
{
    require_admin();
    $p = poll_by_id($id, true) ?? not_found('Poll not found.');
    view('admin/poll_results', ['p' => $p, 'res' => poll_results($p), 'comments' => poll_comments($p, true)]);
}

function admin_api_live(int $id): void
{
    require_admin();
    $p = poll_by_id($id, true) ?? json_out(['error' => 'Not found'], 404);
    json_out(['results' => poll_results($p), 'comments' => poll_comments($p, true), 'live' => poll_live($p)]);
}

function admin_api_comment(): void
{
    require_admin();
    csrf_check();
    $b = request_json();
    $c = one('SELECT poll_id FROM ballot_choices WHERE id = ?', [(int) ($b['id'] ?? 0)]) ?? json_out(['error' => 'Not found'], 404);
    q('UPDATE ballot_choices SET comment_hidden = ? WHERE id = ?', [!empty($b['hidden']) ? 1 : 0, (int) $b['id']]);
    poll_bump((int) $c['poll_id']);
    json_out(['ok' => true]);
}

function admin_codes(int $id): void
{
    require_admin();
    $p = poll_by_id($id) ?? not_found('Poll not found.');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = $_POST['action'] ?? '';
        if ($action === 'generate') {
            $n = max(1, min(500, (int) ($_POST['count'] ?? 1)));
            $votes = max(1, min(50, (int) ($_POST['votes'] ?? 1)));
            $label = mb_substr(trim((string) ($_POST['label'] ?? '')), 0, 120);
            for ($i = 0; $i < $n; $i++) {
                do { $code = new_code(); } while (val('SELECT 1 FROM access_codes WHERE code = ?', [$code]));
                q('INSERT INTO access_codes (poll_id, code, label, votes_allowed, created_at) VALUES (?,?,?,?,?)', [$id, $code, $label, $votes, now()]);
            }
            flash("Created $n code(s) with $votes vote(s) each.");
        } elseif ($action === 'release') {
            q('UPDATE access_codes SET claimed_by = NULL WHERE id = ? AND poll_id = ?', [(int) $_POST['code_id'], $id]);
            flash('Code released. It can be opened on a new device.');
        } elseif ($action === 'delete') {
            q('DELETE FROM access_codes WHERE id = ? AND poll_id = ?', [(int) $_POST['code_id'], $id]);
            flash('Code deleted.');
        } elseif ($action === 'delete_unused') {
            q('DELETE FROM access_codes WHERE poll_id = ? AND claimed_by IS NULL', [$id]);
            flash('All unused codes deleted.');
        }
        poll_bump($id);
        audit('codes_' . $action, "#$id");
        redirect("admin/polls/$id/codes");
    }
    $codes = all('SELECT c.*, (SELECT used FROM voters v WHERE v.poll_id = c.poll_id AND v.device_hash = c.claimed_by) AS used
        FROM access_codes c WHERE c.poll_id = ? ORDER BY c.id', [$id]);
    view('admin/codes', ['p' => $p, 'codes' => $codes, 'print' => false]);
}

function admin_codes_print(int $id): void
{
    require_admin();
    $p = poll_by_id($id) ?? not_found('Poll not found.');
    $codes = all('SELECT * FROM access_codes WHERE poll_id = ? AND claimed_by IS NULL ORDER BY id', [$id]);
    view('admin/codes_print', ['p' => $p, 'codes' => $codes, 'brand' => branding($p)]);
}

function admin_trash(): void
{
    require_admin();
    $polls = all('SELECT p.*, (SELECT COUNT(*) FROM ballots b WHERE b.poll_id = p.id) AS responses FROM polls p WHERE p.deleted_at IS NOT NULL ORDER BY p.deleted_at DESC');
    view('admin/trash', ['polls' => $polls]);
}

function admin_present(): void
{
    require_admin();
    $polls = all('SELECT * FROM polls WHERE deleted_at IS NULL AND archived = 0 AND status <> "draft" ORDER BY updated_at DESC');
    view('admin/present', ['polls' => $polls]);
}

function admin_settings(): void
{
    $u = require_admin();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $action = $_POST['action'] ?? 'settings';
        if ($action === 'password') {
            $cur = (string) ($_POST['current'] ?? '');
            $new = (string) ($_POST['new'] ?? '');
            if (!password_verify($cur, $u['password_hash'])) flash('Current password is wrong.', 'error');
            elseif (strlen($new) < 10) flash('New password must be at least 10 characters.', 'error');
            elseif ($new !== ($_POST['new2'] ?? '')) flash('The new passwords do not match.', 'error');
            else {
                q('UPDATE admins SET password_hash = ? WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $u['id']]);
                audit('password_change');
                flash('Password changed.');
            }
        } elseif ($action === 'logout_all') {
            q('UPDATE admins SET session_epoch = session_epoch + 1 WHERE id = ?', [$u['id']]);
            audit('logout_all');
            logout();
            redirect('admin/login');
        } else {
            $s = fn($k) => trim((string) ($_POST[$k] ?? ''));
            setting_set('site_title', mb_substr($s('site_title') ?: 'Voting', 0, 120));
            setting_set('org_name', mb_substr($s('org_name'), 0, 120));
            setting_set('accent', preg_match('/^#[0-9a-f]{6}$/i', $s('accent')) ? $s('accent') : '#f7941d');
            setting_set('font_pair', array_key_exists($s('font_pair'), FONT_PAIRS) ? $s('font_pair') : 'classic');
            setting_set('phone_theme', in_array($s('phone_theme'), ['auto', 'light', 'dark'], true) ? $s('phone_theme') : 'auto');
            setting_set('screen_theme', in_array($s('screen_theme'), ['dark', 'light'], true) ? $s('screen_theme') : 'dark');
            setting_set('home_mode', in_array($s('home_mode'), ['list', 'redirect', 'blank'], true) ? $s('home_mode') : 'list');
            setting_set('home_slug', slugify($s('home_slug') ?: 'x') === 'x' ? '' : slugify($s('home_slug')));
            setting_set('logo_media_id', (string) (int) $s('logo_media_id'));
            setting_set('favicon_media_id', (string) (int) $s('favicon_media_id'));
            $texts = [];
            foreach (TEXT_DEFAULTS as $k => $_) {
                $v = trim((string) ($_POST['text'][$k] ?? ''));
                if ($v !== '') $texts[$k] = mb_substr($v, 0, 300);
            }
            setting_set('texts', json_encode($texts, JSON_UNESCAPED_UNICODE));
            q('UPDATE polls SET version = version + 1');
            audit('settings_save');
            flash('Settings saved.');
        }
        redirect('admin/settings');
    }
    $polls = all('SELECT slug, title FROM polls WHERE deleted_at IS NULL ORDER BY title');
    view('admin/settings', ['s' => settings_all(), 'polls' => $polls]);
}

function admin_api_upload(): void
{
    require_admin();
    csrf_check();
    $f = $_FILES['file'] ?? null;
    if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK) json_out(['error' => 'Upload failed. Is the file under 5 MB?'], 400);
    if ($f['size'] > 5 * 1024 * 1024) json_out(['error' => 'Images must be under 5 MB.'], 400);
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/x-icon' => 'ico', 'image/vnd.microsoft.icon' => 'ico'][$mime] ?? null;
    if (!$ext) json_out(['error' => 'Please upload a PNG, JPG, WEBP, GIF or ICO image.'], 400);
    if ($ext !== 'ico' && !@getimagesize($f['tmp_name'])) json_out(['error' => 'That file is not a valid image.'], 400);
    $name = bin2hex(random_bytes(8)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name)) json_out(['error' => 'Could not save the file. Check that /uploads is writable.'], 500);
    q('INSERT INTO media (filename, original_name, mime, size, created_at) VALUES (?,?,?,?,?)', [$name, mb_substr((string) $f['name'], 0, 255), $mime, (int) $f['size'], now()]);
    $id = (int) db()->lastInsertId();
    audit('upload', (string) $f['name']);
    json_out(['id' => $id, 'url' => url('uploads/' . $name)]);
}
