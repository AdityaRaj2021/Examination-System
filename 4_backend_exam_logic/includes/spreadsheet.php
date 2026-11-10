<?php
declare(strict_types=1);
// Minimal, dependency-free spreadsheet support (no Composer needed):
//   * read .xlsx (via ZipArchive + XMLReader) and .csv
//   * write .xlsx (multi-sheet) and .csv
// Legacy binary .xls files are NOT supported - ask the examiner to "Save as .xlsx / .csv".

function sheet_norm(string $s): string
{
    return preg_replace('/[^a-z0-9]/', '', strtolower($s)) ?? '';
}

function col_index(string $ref): int
{
    $letters = preg_replace('/[^A-Z]/', '', strtoupper($ref));
    $n = 0;
    for ($i = 0, $l = strlen($letters); $i < $l; $i++) $n = $n * 26 + (ord($letters[$i]) - 64);
    return max(0, $n - 1);
}

/** @return array<int,array{row:int,cells:array<int,string>}> */
function read_spreadsheet(string $path, string $ext): array
{
    $ext = strtolower($ext);
    if ($ext === 'csv') return read_csv_rows($path);
    if ($ext === 'xlsx') return read_xlsx_rows($path);
    if ($ext === 'xls') throw new ApiError('Legacy .xls files are not supported. In Excel choose File > Save As > "Excel Workbook (.xlsx)" or "CSV", then upload that file.', 400);
    throw new ApiError('Choose an .xlsx or .csv file.', 400);
}

function read_csv_rows(string $path): array
{
    $text = (string) file_get_contents($path);
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text) ?? $text;
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    $first = strtok($text, "\n") ?: '';
    $best = ','; $bestN = -1;
    foreach ([',', ';', "\t"] as $d) {      // Excel in some regions exports ; or tab separated files
        $c = substr_count($first, $d);
        if ($c > $bestN) { $bestN = $c; $best = $d; }
    }
    $h = fopen('php://temp', 'r+');
    fwrite($h, $text);
    rewind($h);
    $rows = []; $n = 0;
    while (($cells = fgetcsv($h, 0, $best, '"', '')) !== false) {
        $n++;
        if ($cells === [null]) continue;
        $rows[] = ['row' => $n, 'cells' => array_map(fn($c) => (string) $c, $cells)];
        if ($n > 20000) throw new ApiError('File has too many rows.', 400);
    }
    fclose($h);
    return $rows;
}

function read_xlsx_rows(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new ApiError('The PHP "zip" extension is disabled, so .xlsx cannot be read. Enable extension=zip in php.ini (XAMPP Control Panel > Apache > Config > PHP) or upload a .csv file.', 500);
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new ApiError('This is not a valid .xlsx file.', 400);
    $get = function (string $name) use ($zip): ?string {
        $i = $zip->locateName($name);
        if ($i === false) return null;
        $st = $zip->statIndex($i);
        if ($st && $st['size'] > 40 * 1024 * 1024) throw new ApiError('Spreadsheet is too large when unpacked.', 400);
        $d = $zip->getFromIndex($i);
        return $d === false ? null : $d;
    };
    $dom = function (string $xml): DOMDocument {
        $d = new DOMDocument();
        if (!@$d->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) throw new ApiError('Could not read this spreadsheet.', 400);
        return $d;
    };
    $nsRel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    $shared = [];
    if (($ss = $get('xl/sharedStrings.xml')) !== null) {
        foreach ($dom($ss)->getElementsByTagName('si') as $si) {
            $t = '';
            foreach ($si->getElementsByTagName('t') as $tn) {
                // ignore phonetic runs (rPh) - only take text nodes that are not inside <rPh>
                $skip = false;
                for ($p = $tn->parentNode; $p && $p !== $si; $p = $p->parentNode) if ($p->localName === 'rPh') { $skip = true; break; }
                if (!$skip) $t .= $tn->textContent;
            }
            $shared[] = $t;
        }
    }

    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $get('xl/workbook.xml');
    $rels = $get('xl/_rels/workbook.xml.rels');
    if ($wb !== null && $rels !== null) {
        $sheets = $dom($wb)->getElementsByTagName('sheet');
        if ($sheets->length) {
            $rid = $sheets->item(0)->getAttributeNS($nsRel, 'id');
            foreach ($dom($rels)->getElementsByTagName('Relationship') as $rel) {
                if ($rel->getAttribute('Id') === $rid) {
                    $t = ltrim($rel->getAttribute('Target'), '/');
                    $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                    break;
                }
            }
        }
    }
    $xml = $get($sheetPath);
    $zip->close();
    if ($xml === null) throw new ApiError('The first worksheet could not be found in this file.', 400);

    $r = new XMLReader();
    $r->XML($xml, null, LIBXML_NONET);
    $rows = []; $auto = 0;
    while ($r->read()) {
        if ($r->nodeType !== XMLReader::ELEMENT || $r->localName !== 'row') continue;
        $auto++;
        $rowNum = (int) ($r->getAttribute('r') ?: $auto);
        $auto = $rowNum;
        $node = $r->expand();
        if (!$node) continue;
        $cells = []; $maxCol = -1; $seq = 0;
        foreach ($node->childNodes as $c) {
            if ($c->localName !== 'c') continue;
            $ref = $c->getAttribute('r');
            $ci = $ref !== '' ? col_index($ref) : $seq;
            $seq = $ci + 1;
            $type = $c->getAttribute('t');
            $val = '';
            if ($type === 'inlineStr') {
                foreach ($c->getElementsByTagName('t') as $tn) $val .= $tn->textContent;
            } else {
                $v = null;
                foreach ($c->childNodes as $ch) if ($ch->localName === 'v') { $v = $ch->textContent; break; }
                if ($v !== null) {
                    if ($type === 's') $val = $shared[(int) $v] ?? '';
                    elseif ($type === 'b') $val = $v === '1' ? 'TRUE' : 'FALSE';
                    elseif ($type === '' || $type === 'n') {
                        if (is_numeric($v)) {
                            $f = (float) $v;
                            $val = ($f == floor($f) && abs($f) < 1e15) ? (string) (int) $f : rtrim(rtrim(number_format($f, 10, '.', ''), '0'), '.');
                        } else $val = $v;
                    } else $val = $v;
                }
            }
            $cells[$ci] = $val;
            $maxCol = max($maxCol, $ci);
        }
        $flat = [];
        for ($i = 0; $i <= $maxCol; $i++) $flat[$i] = $cells[$i] ?? '';
        $rows[] = ['row' => $rowNum, 'cells' => $flat];
        if (count($rows) > 20000) throw new ApiError('File has too many rows.', 400);
    }
    $r->close();
    return $rows;
}

/**
 * Turns spreadsheet rows (first non-empty row = headers) into validated questions.
 * Header matching mirrors the original prototype (Option A / Choice A / Option 1 ... etc.).
 * @return array{questions:array,errors:array}
 */
function questions_from_rows(array $rows): array
{
    $rows = array_values(array_filter($rows, fn($r) => trim(implode('', $r['cells'])) !== ''));
    if (count($rows) < 2) throw new ApiError('The first sheet is empty (it needs a header row and at least one question).', 400);
    $headers = array_map(fn($h) => sheet_norm((string) $h), array_shift($rows)['cells']);
    $max = (int) app_cfg('max_questions_per_exam', 1000);
    if (count($rows) > $max) throw new ApiError("A test can contain at most $max questions.", 400);

    $questions = []; $errors = [];
    foreach ($rows as $row) {
        $vals = [];
        foreach ($headers as $i => $h) if ($h !== '' && !isset($vals[$h])) $vals[$h] = trim((string) ($row['cells'][$i] ?? ''));
        $get = function (string ...$keys) use ($vals): string {
            foreach ($keys as $k) { $k = sheet_norm($k); if (($vals[$k] ?? '') !== '') return $vals[$k]; }
            return '';
        };
        $question = $get('question', 'questions', 'questiontext', 'mcq');
        $opts = [];
        foreach (['A' => 1, 'B' => 2, 'C' => 3, 'D' => 4] as $L => $N) {
            $l = strtolower($L);
            $v = $get("option$l", $l, "choice$l", "{$l}option", "option$N", "choice$N", "answeroption$l", "option{$l}text", "choice{$l}text");
            if ($v === '') {
                foreach ($vals as $k => $val) {
                    if ($val !== '' && (preg_match("/^(option|choice)$l(text|value|required)?$/", (string) $k)
                        || preg_match("/^(option|choice)$N(text|value|required)?$/", (string) $k)
                        || preg_match("/^$l(option|choice)$/", (string) $k))) { $v = $val; break; }
                }
            }
            $opts[] = $v;
        }
        $ans = strtoupper($get('correctanswer', 'correctoption', 'answer', 'answerkey', 'rightanswer'));
        $ans = preg_replace('/^OPTION\s*/', '', $ans) ?? $ans;
        $ans = rtrim($ans, '.) ');
        $marksRaw = $get('marks', 'mark', 'points');
        $n = $row['row'];

        if ($question === '') { $errors[] = ['row' => $n, 'error' => 'Question is required.']; continue; }
        if (in_array('', $opts, true)) { $errors[] = ['row' => $n, 'error' => 'Options A-D are missing. Use headers Option A, Option B, Option C, Option D (or Choice A-D / Option 1-4).']; continue; }
        if (!in_array($ans, ['A', 'B', 'C', 'D'], true)) { $errors[] = ['row' => $n, 'error' => 'Correct Answer must be A, B, C, or D.']; continue; }
        $marks = $marksRaw === '' ? 1.0 : (is_numeric($marksRaw) ? (float) $marksRaw : NAN);
        if (is_nan($marks) || $marks <= 0 || $marks > 1000) { $errors[] = ['row' => $n, 'error' => 'Marks must be a positive number.']; continue; }
        if (mb_strlen($question) > 2000 || max(array_map('mb_strlen', $opts)) > 500) { $errors[] = ['row' => $n, 'error' => 'Question or option text is too long.']; continue; }
        $questions[] = ['question' => $question, 'options' => $opts, 'answer' => $ans, 'marks' => num($marks)];
    }
    return ['questions' => $questions, 'errors' => $errors];
}

// ------------------------------------------------------------------ writers
function xml_text(string $s): string
{
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function col_letter(int $i): string
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
    return $s;
}

/** Sends a multi-sheet .xlsx download. $sheets = ['Sheet name' => [ [header...], [row...], ... ]] */
function send_xlsx(array $sheets, string $filename): void
{
    if (!class_exists('ZipArchive')) throw new ApiError('The PHP "zip" extension is disabled; use the CSV export instead.', 500);
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new ApiError('Could not build the Excel file.', 500);

    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    $wbSheets = ''; $wbRels = '';
    $i = 0;
    foreach ($sheets as $name => $rows) {
        $i++;
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $safe = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', (string) $name) ?? 'Sheet', 0, 31);
        $wbSheets .= '<sheet name="' . xml_text($safe) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $wbRels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';

        $ncols = 0;
        foreach ($rows as $r) $ncols = max($ncols, count($r));
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        if ($ncols) $x .= '<cols><col min="1" max="' . $ncols . '" width="22" customWidth="1"/></cols>';
        $x .= '<sheetData>';
        foreach (array_values($rows) as $ri => $r) {
            $x .= '<row r="' . ($ri + 1) . '">';
            foreach (array_values($r) as $ci => $v) {
                $ref = col_letter($ci) . ($ri + 1);
                $style = $ri === 0 ? ' s="1"' : '';
                if ($v === null || $v === '') { continue; }
                if (is_int($v) || is_float($v)) $x .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
                else $x .= '<c r="' . $ref . '"' . $style . ' t="inlineStr"><is><t xml:space="preserve">' . xml_text((string) $v) . '</t></is></c>';
            }
            $x .= '</row>';
        }
        $x .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', $x);
    }
    $ct .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbSheets . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $wbRels . '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs></styleSheet>');
    $zip->close();

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    readfile($tmp);
    @unlink($tmp);
    exit;
}

/** CSV download (UTF-8 with BOM so Excel opens accents correctly). Guards against spreadsheet formula injection. */
function send_csv(array $rows, string $filename): void
{
    header_remove('Content-Type');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    foreach ($rows as $r) {
        $r = array_map(function ($v) {
            if (is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) return "'" . $v;
            return $v;
        }, $r);
        fputcsv($out, $r, ',', '"', '');
    }
    fclose($out);
    exit;
}
