<?php
// Exports: CSV, Excel (.xlsx), printable report, full backup (SQL + uploads).

declare(strict_types=1);

/** Summary rows + one row per response (responses shuffled so order says nothing about who voted when). */
function export_tables(array $p): array
{
    $res = poll_results($p);
    $isRanked = $p['type'] === 'ranked';
    $summary = [$isRanked
        ? array_merge(['Rank', 'Option', 'Points'], array_map(fn($i) => '# ranked ' . ($i + 1), array_keys(poll_points($p))))
        : ['Rank', 'Option', 'Votes', '% of responses']];
    foreach ($res['rows'] as $r) {
        $summary[] = $isRanked ? array_merge([$r['rank'], $r['label'], $r['score']], $r['ranks']) : [$r['rank'], $r['label'], $r['votes'], $r['pct'] . '%'];
    }
    $labels = array_column(poll_options((int) $p['id'], true), 'label', 'id');
    $byBallot = [];
    foreach (all('SELECT ballot_id, option_id, rank_pos, comment FROM ballot_choices WHERE poll_id = ? ORDER BY ballot_id, rank_pos', [$p['id']]) as $c) {
        $byBallot[$c['ballot_id']][] = $c;
    }
    $max = max(1, ...array_map('count', $byBallot ?: [[]]));
    $withComments = $p['comment_mode'] !== 'off';
    $head = [];
    for ($i = 1; $i <= $max; $i++) {
        $head[] = $isRanked ? "Rank $i" : ($max > 1 ? "Choice $i" : 'Choice');
        if ($withComments) $head[] = ($isRanked ? "Rank $i" : ($max > 1 ? "Choice $i" : 'Choice')) . ' — ' . ($p['comment_label'] ?: 'comment');
    }
    $responses = [$head];
    $ballots = array_values($byBallot);
    shuffle($ballots);
    foreach ($ballots as $choices) {
        $row = [];
        foreach ($choices as $c) {
            $row[] = $labels[$c['option_id']] ?? '?';
            if ($withComments) $row[] = (string) $c['comment'];
        }
        $responses[] = $row;
    }
    return ['summary' => $summary, 'responses' => $responses, 'total' => $res['responses']];
}

function export_filename(array $p, string $ext): string
{
    return preg_replace('/[^a-z0-9-]+/', '-', strtolower($p['slug'])) . '-results-' . date('Ymd-Hi') . '.' . $ext;
}

function export_csv(array $p): void
{
    $t = export_tables($p);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . export_filename($p, 'csv') . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, [$p['title']]);
    fputcsv($out, ['Total responses', $t['total']]);
    fputcsv($out, []);
    foreach ($t['summary'] as $r) fputcsv($out, $r);
    fputcsv($out, []);
    fputcsv($out, ['Responses (anonymous, shuffled)']);
    foreach ($t['responses'] as $r) fputcsv($out, $r);
    fclose($out);
    audit('export_csv', "#{$p['id']}");
    exit;
}

/** Minimal .xlsx writer (inline strings) — no libraries needed, only ZipArchive. */
function export_xlsx(array $p): void
{
    if (!class_exists('ZipArchive')) export_csv($p);
    $t = export_tables($p);
    $sheet = function (array $rows, array $widths, array $bold = [0]): string {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cols>';
        foreach ($widths as $i => $w) $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        $x .= '</cols><sheetData>';
        foreach ($rows as $ri => $row) {
            $x .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($row) as $ci => $v) {
                $ref = xlsx_col($ci) . ($ri + 1);
                $style = in_array($ri, $bold, true) ? ' s="1"' : '';
                if (is_int($v) || (is_string($v) && preg_match('/^-?\d{1,15}$/', $v))) $x .= "<c r=\"$ref\"$style><v>$v</v></c>";
                else $x .= "<c r=\"$ref\" t=\"inlineStr\"$style><is><t xml:space=\"preserve\">" . htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
            $x .= '</row>';
        }
        return $x . '</sheetData></worksheet>';
    };
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Summary" sheetId="1" r:id="rId1"/><sheet name="Responses" sheetId="2" r:id="rId2"/></sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
    $summary = array_merge([[$p['title']], ['Total responses', $t['total']], []], $t['summary']);
    $z->addFromString('xl/worksheets/sheet1.xml', $sheet($summary, [8, 48, 12, 12, 12, 12], [0, 3]));
    $z->addFromString('xl/worksheets/sheet2.xml', $sheet($t['responses'], array_fill(0, max(1, count($t['responses'][0])), 36)));
    $z->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . export_filename($p, 'xlsx') . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    audit('export_xlsx', "#{$p['id']}");
    exit;
}

function xlsx_col(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

function export_report(array $p): void
{
    view('admin/report', ['p' => $p, 'res' => poll_results($p), 'comments' => poll_comments($p), 'brand' => branding($p)]);
}

/** Full backup: SQL dump of every table + the uploads folder, as one zip. */
function export_backup(): void
{
    if (!class_exists('ZipArchive')) { http_response_code(500); exit('ZipArchive is not available on this server.'); }
    $tables = ['settings', 'admins', 'media', 'polls', 'options', 'ballots', 'ballot_choices', 'access_codes', 'voters', 'audit_log'];
    $sql = "-- WKC Voting System backup " . date('c') . "\nSET FOREIGN_KEY_CHECKS=0;\n";
    $pdo = db();
    foreach ($tables as $t) {
        $create = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM)[1];
        $sql .= "\nDROP TABLE IF EXISTS `$t`;\n$create;\n";
        $st = $pdo->query("SELECT * FROM `$t`");
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $vals = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row);
            $sql .= "INSERT INTO `$t` (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', $vals) . ");\n";
        }
    }
    $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
    $tmp = tempnam(sys_get_temp_dir(), 'bak');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('database.sql', $sql);
    foreach (glob(UPLOAD_DIR . '/*') ?: [] as $f) {
        if (is_file($f) && basename($f) !== '.htaccess') $z->addFile($f, 'uploads/' . basename($f));
    }
    $z->addFromString('README.txt', "Restore: import database.sql with phpMyAdmin into an empty database,\ncopy the uploads/ files into the app's uploads/ folder, and point config.php at that database.\n");
    $z->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="voting-backup-' . date('Ymd-Hi') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    audit('backup');
    exit;
}
