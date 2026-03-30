<?php
/* ============================================================
 * API: Parse PDF → Questions JSON
 * api/self-assessment/parse-pdf.php
 *
 * POST params:
 *   pdf         — file upload  (multipart/form-data)
 *   sa_id       — (optional) save to DB after parsing
 *   csrf_token  — (required only when sa_id is given)
 *
 * Modes:
 *  A) pdf upload only  → returns { success, count, questions[] }
 *  B) pdf + sa_id      → parses, saves to self_assessment_q_map,
 *                         returns { success, count, message }
 *  C) sa_id only       → re-parses the already-stored pdf_path
 *
 * PDF format compatibility (all handled):
 *   Questions : "1."  "1)"  "Q1."  "Question 1:"  etc.
 *   Options   : "A)"  "a)"  "(A)"  "[A]"  "A."  indented  etc.
 *   Answers   : "Answer: B"  "Ans: C"  bare letter  trailing key
 *   T/F       : True/False or Yes/No as options
 * ============================================================ */

require_once '../../config.php';
require_once '../../db-guard.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']); exit;
}

// ── Mode detection ───────────────────────────────────────────
$saId      = (int)($_POST['sa_id'] ?? 0);
$saveToDb  = $saId > 0;
$hasUpload = !empty($_FILES['pdf']['tmp_name']) && is_uploaded_file($_FILES['pdf']['tmp_name']);

// ── Auth guard (only for DB operations) ─────────────────────
if ($saveToDb) {
    $user   = validateSession($conn, 'student');
    $userId = (int)$user['user_id'];

    if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
        echo json_encode(['success' => false, 'error' => 'CSRF error']); exit;
    }
}

// ── Resolve PDF path ─────────────────────────────────────────
$pdfPath = null;

if ($hasUpload) {
    // Mode A / B: fresh file upload
    $pdfPath = $_FILES['pdf']['tmp_name'];
} elseif ($saveToDb) {
    // Mode C: re-parse a previously stored PDF
    $saRes = safePreparedQuery($conn,
        "SELECT pdf_path FROM self_assessments WHERE sa_id = ? AND user_id = ? AND type = 'pdf'",
        "ii", [$saId, $userId]
    );
    $sa = null;
    if ($saRes['success'] && $saRes['result']) {
        $sa = $saRes['result']->fetch_assoc();
        $saRes['result']->free();
    }
    if (!$sa || !$sa['pdf_path']) {
        echo json_encode(['success' => false, 'error' => 'Assessment or PDF not found']); exit;
    }
    $pdfPath = $sa['pdf_path'];
    if (!file_exists($pdfPath)) {
        $pdfPath = __DIR__ . '/../../' . ltrim($sa['pdf_path'], '/');
    }
}

if (!$pdfPath || !file_exists($pdfPath)) {
    echo json_encode(['success' => false, 'error' => 'No PDF provided or file not found on server.']); exit;
}

// ── Extract text from PDF ────────────────────────────────────
$rawText = extractTextFromPDF($pdfPath);

$debugMode = !empty($_POST['debug']) || !empty($_GET['debug']);

if ($debugMode) {
    echo json_encode([
        'debug'        => true,
        'text_length'  => strlen(trim($rawText)),
        'text_preview' => substr(trim($rawText), 0, 1000),
        'pdftotext'    => trim((string)shell_exec('which pdftotext 2>/dev/null')),
    ]); exit;
}

if (strlen(trim($rawText)) < 20) {
    echo json_encode([
        'success' => false,
        'error'   => 'Could not extract text from this PDF.',
        'hint'    => 'Make sure the PDF contains selectable text (not a scanned image).',
        'text_length' => strlen(trim($rawText)),
    ]); exit;
}

// ── Parse questions ──────────────────────────────────────────
$questions = parseQuestions($rawText);

if (empty($questions)) {
    echo json_encode([
        'success' => false,
        'error'   => 'No questions could be extracted from this PDF.',
        'hint'    => 'Supported formats — questions: "1." / "Q1." / "Question 1:" '
                   . '| options: A) a) (A) [A] A. 1) i) '
                   . '| answers: "Answer: A" / "Ans: B" / bare letter / trailing key.',
        'text_preview' => substr(trim($rawText), 0, 500),
    ]); exit;
}

// ── DB save (Mode B / C only) ────────────────────────────────
if ($saveToDb) {
    safePreparedQuery($conn, "DELETE FROM self_assessment_q_map WHERE sa_id = ?", "i", [$saId]);

    $saved = 0;
    foreach ($questions as $q) {
        if ($q['type'] === 'true_false') {
            $opts    = ['True', 'False', '', ''];
            $ans     = strtolower((string)($q['correctAnswer'] ?? 'true'));
            $correct = ($ans === 'true' || $ans === 'a') ? 'a' : 'b';
        } else {
            $opts    = array_pad(array_values($q['options']), 4, '');
            $correct = $q['correctAnswer'] ?? 'a';
        }

        $res = safePreparedQuery($conn,
            "INSERT INTO self_assessment_q_map
             (sa_id, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, q_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, '', ?)",
            "issssssi",
            [$saId, $q['text'], $opts[0], $opts[1], $opts[2], $opts[3], $correct, $saved]
        );
        if ($res['success']) $saved++;
    }

    if ($saved === 0) {
        echo json_encode(['success' => false, 'error' => 'Questions parsed but failed to save to database.']); exit;
    }

    safePreparedQuery($conn,
        "UPDATE self_assessments SET total_questions = ?, status = 'ready' WHERE sa_id = ?",
        "ii", [$saved, $saId]
    );

    echo json_encode(['success' => true, 'count' => $saved, 'message' => "$saved questions extracted and saved."]);
    exit;
}

// ── Mode A: return questions as JSON (no DB) ─────────────────
echo json_encode(['success' => true, 'count' => count($questions), 'questions' => $questions]);


// ============================================================
// extractTextFromPDF
//
// 1. pdftotext (poppler) — best quality.
//    -layout mode preserves indented option lines like
//    "   A) London" which trim() later normalises to "A) London".
// 2. Pure-PHP fallback — FlateDecode, ASCII85+Flat, raw, CID fonts.
// ============================================================
function extractTextFromPDF(string $path): string
{
    // ── Method 1: pdftotext ───────────────────────────────────
    $which = trim((string)shell_exec('which pdftotext 2>/dev/null'));
    if ($which !== '') {
        $esc = escapeshellarg($path);
        $out = (string)shell_exec("pdftotext -layout $esc - 2>/dev/null");
        if (trim($out) !== '') return $out;
        $out = (string)shell_exec("pdftotext $esc - 2>/dev/null");
        if (trim($out) !== '') return $out;
    }

    // ── Method 2: pure-PHP stream extraction ─────────────────
    $raw = file_get_contents($path);

    $ascii85Decode = function(string $data): string {
        $data = preg_replace('/\s+/', '', $data);
        if (substr($data, -2) === '~>') $data = substr($data, 0, -2);
        $result = ''; $i = 0; $len = strlen($data);
        while ($i < $len) {
            if ($data[$i] === 'z') { $result .= "\x00\x00\x00\x00"; $i++; continue; }
            $chunk = substr($data, $i, 5); $i += 5; $n = 0;
            for ($k = 0; $k < strlen($chunk); $k++) $n = $n * 85 + (ord($chunk[$k]) - 33);
            $pad = 5 - strlen($chunk);
            for ($k = 0; $k < $pad; $k++) $n = $n * 85 + 84;
            $result .= substr(pack('N', $n), 0, 4 - $pad);
        }
        return $result;
    };

    // ── Find all streams with their filter info ─────────────────────────
    // Replaces the old <<(.*?)>> approach which broke on PDFs with nested
    // dictionaries (e.g. ReportLab PDFs with /Resources << /Font ... >>).
    // Instead we scan for every stream...endstream block, then look back
    // up to 1500 bytes to find the /Filter declaration for that stream.
    $decompressed = [];
    preg_match_all('/stream\r?\n(.*?)(?:\r?\n)?endstream/s', $raw, $streamMatches, PREG_OFFSET_CAPTURE);
    foreach ($streamMatches[1] as $sm) {
        $data    = $sm[0];
        $offset  = $sm[1];
        // Look backwards for /Filter within the preceding obj header
        $lookback = substr($raw, max(0, $offset - 1500), min(1500, $offset));
        $filters  = [];
        if (preg_match('/\/Filter\s*(\[[^\]]*\]|\/\w+)/', $lookback, $fm)) {
            preg_match_all('/\/([A-Za-z0-9]+)/', $fm[1], $fnames);
            $filters = $fnames[1];
        }
        foreach ($filters as $filter) {
            if ($filter === 'ASCII85Decode') {
                $data = $ascii85Decode($data);
            } elseif ($filter === 'FlateDecode') {
                $dec = @gzuncompress($data);
                if ($dec === false) $dec = @gzinflate($data);
                if ($dec !== false) $data = $dec;
            } elseif ($filter === 'ASCIIHexDecode') {
                $data = pack('H*', preg_replace('/[^0-9A-Fa-f]/', '', $data));
            }
        }
        // If no filter found, attempt decompression anyway (handles omitted /Filter)
        if (empty($filters)) {
            $dec = @gzuncompress($data);
            if ($dec === false) $dec = @gzinflate($data);
            if ($dec !== false) $data = $dec;
        }
        $decompressed[] = $data;
    }

    $cmap = [];
    foreach ($decompressed as $src) {
        if (strpos($src, 'beginbfchar') === false && strpos($src, 'beginbfrange') === false) continue;
        preg_match_all('/beginbfchar(.*?)endbfchar/s', $src, $bfcharSecs);
        foreach ($bfcharSecs[1] as $sec) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>/', $sec, $bfc);
            foreach ($bfc[1] as $k => $cidHex) {
                $cid = hexdec($cidHex); $uni = hexdec($bfc[2][$k]);
                if ($uni > 0) $cmap[$cid] = mb_chr($uni, 'UTF-8');
            }
        }
        preg_match_all('/beginbfrange(.*?)endbfrange/s', $src, $bfrangeSecs);
        foreach ($bfrangeSecs[1] as $sec) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>/', $sec, $bfr);
            foreach ($bfr[1] as $k => $startHex) {
                $start = hexdec($startHex); $end = hexdec($bfr[2][$k]); $uni = hexdec($bfr[3][$k]);
                for ($c = $start; $c <= $end; $c++) $cmap[$c] = mb_chr($uni + ($c - $start), 'UTF-8');
            }
        }
    }
    $decodeCidHex = function(string $hexStr) use ($cmap): string {
        $result = ''; $step = (strlen($hexStr) % 4 === 0 && strlen($hexStr) >= 4) ? 4 : 2;
        for ($i = 0; $i < strlen($hexStr); $i += $step) {
            $cid = hexdec(substr($hexStr, $i, $step));
            if (isset($cmap[$cid])) { $result .= $cmap[$cid]; }
            else { $uni = $cid + 29; $result .= ($uni >= 32 && $uni <= 126) ? chr($uni) : ''; }
        }
        return $result;
    };

    $pdfUnescape = function(string $s): string {
        return preg_replace_callback('/\\\\([0-7]{1,3}|[\\\\nrtfb()])/', function($m) {
            $c = $m[1];
            if ($c[0] >= '0' && $c[0] <= '7') return chr(octdec($c));
            return match($c) {
                'n' => "\n", 'r' => "\r", 't' => "\t", 'f' => "\f",
                'b' => "\x08", '\\' => '\\', '(' => '(', ')' => ')',
                default => $c
            };
        }, $s);
    };

    $text = '';
    foreach ($decompressed as $src) {
        if (strpos($src, 'BT') === false) continue;
        preg_match_all('/BT(.*?)ET/s', $src, $bt);
        foreach ($bt[1] as $block) {
            $lineText = '';
            preg_match_all('/\((?:[^)(\\\\]|\\\\.)*\)\s*Tj/s', $block, $tj);
            foreach ($tj[0] as $t) {
                $inner = preg_replace('/\)\s*Tj$/', '', preg_replace('/^\(/', '', $t));
                $lineText .= $pdfUnescape($inner) . ' ';
            }
            preg_match_all('/<([0-9A-Fa-f]+)>\s*Tj/', $block, $hexTj);
            foreach ($hexTj[1] as $h) { $lineText .= $decodeCidHex($h); }
            preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tJ);
            foreach ($tJ[1] as $t) {
                preg_match_all('/\((?:[^)(\\\\]|\\\\.)*\)/', $t, $parts);
                foreach ($parts[0] as $p) { $lineText .= $pdfUnescape(preg_replace('/^\(|\)$/', '', $p)); }
                preg_match_all('/<([0-9A-Fa-f]+)>/', $t, $hexParts);
                foreach ($hexParts[1] as $h) { $lineText .= $decodeCidHex($h); }
            }
            $lineText = trim($lineText);
            if ($lineText !== '') $text .= $lineText . "\n";
        }
    }

    // Collapse single-char-per-line artefacts
    $lines = explode("\n", $text); $merged = []; $i = 0;
    while ($i < count($lines)) {
        $line = trim($lines[$i]);
        if (strlen($line) === 1 && ctype_alpha($line)) {
            $word = $line; $j = $i + 1;
            while ($j < count($lines) && strlen(trim($lines[$j])) === 1 && ctype_alpha(trim($lines[$j]))) {
                $word .= trim($lines[$j]); $j++;
            }
            $merged[] = strlen($word) >= 2 ? $word : $line; $i = $j;
        } else { $merged[] = $line; $i++; }
    }
    $text = implode("\n", $merged);

    // Last-resort: raw parenthesised string grab
    if (strlen(trim($text)) < 20) {
        preg_match_all('/\(([^\)]{3,})\)/', $raw, $m);
        $text = implode("\n", $m[1]);
    }

    return $text;
}


// ============================================================
// parseQuestions — ultra-robust MCQ / True-False parser
//
// Compatible with your sample PDF format:
//   "1. What is the capital of France?"
//   "   A) London"   ← leading spaces stripped by trim() in step 2
//   "   Answer: B"   ← matched by $reAnswer after trim()
// ============================================================
function parseQuestions(string $text): array
{
    $questions = [];

    // ── 1. Normalise line endings & encoding ─────────────────
    $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
    $text = str_replace(
        ["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}",
         "\u{00A0}", "\u{2022}", "\u{25CF}", "\u{2023}", "\u{25B6}", "\u{2192}"],
        ["'",        "'",        '"',        '"',        '-',        '-',
         ' ',        '-',        '-',        '-',        '-',        '->'],
        $text
    );
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);

    $lines = explode("\n", $text);

    // ── 2. Per-line normalisation ─────────────────────────────
    // trim() is what makes indented "   A) London" → "A) London"
    $lines = array_map(function(string $line): string {
        $line = preg_replace('/\h+/', ' ', $line);
        $line = preg_replace('/^[\s\*#•▪►✓★]+/', '', $line);
        $line = preg_replace('/^(\s*[\[(]?\s*[a-dA-D1-4])\s+([).\]])\s*/', '$1$2 ', $line);
        return trim($line);
    }, $lines);
    $lines = array_values(array_filter($lines, fn($l) => $l !== ''));

    // ── 3. Skip noise lines ───────────────────────────────────
    $reNoise = '/^(?:page\s+\d+(\s+of\s+\d+)?|\d+\s+of\s+\d+|https?:\/\/|www\.|copyright\b|all\s+rights\s+reserved|\d+$)/i';
    $lines = array_values(array_filter($lines, fn($l) => !preg_match($reNoise, $l)));

    // ── 4. Inline-options splitter ────────────────────────────
    $reInlineOpts = '/\b([A-Da-d])\s*[).]\s*[^\s].*\b([A-Da-d])\s*[).]/';
    $expanded = [];
    foreach ($lines as $line) {
        if (preg_match($reInlineOpts, $line)) {
            $parts = preg_split('/(?=\b[A-Da-d]\s*[).])/i', $line);
            foreach ($parts as $part) { $part = trim($part); if ($part !== '') $expanded[] = $part; }
        } else { $expanded[] = $line; }
    }
    $lines = $expanded;

    // ── 5. Compact key-row splitter ───────────────────────────
    $reCompactKeyRow = '/^(\d+\s*[-–.)]\s*[A-Da-d]\s*){2,}$/i';
    $expandedKeys = [];
    foreach ($lines as $line) {
        if (preg_match($reCompactKeyRow, trim($line))) {
            preg_match_all('/(\d+)\s*[-–.)]\s*([A-Da-d])/i', $line, $km);
            foreach ($km[1] as $ki => $knum) { $expandedKeys[] = $knum . '-' . $km[2][$ki]; }
        } else { $expandedKeys[] = $line; }
    }
    $lines = $expandedKeys;

    // ── 6. Word-wrap rejoining ────────────────────────────────
    $reAnchor = '/^(?:'
        . '(?:Q(?:uestions?|ues?\.?)?\s*[-–.]?\s*)?\d+\s*[.):\-\s]'
        . '|[a-dA-D1-4ivx]+\s*[).]\s'
        . '|[a-dA-D1-4ivx]+[).]\s*$'
        . '|(?:answer(?:\s+is)?|ans(?:wer)?\.?|ans\.|key|correct(?:\s+answer)?|solution|right\s+answer)\s*[=:\-–.\s]'
        . '|(?:true|false|yes|no)\s*$'
        . '|(?:option|opt|choice)\s*[a-dA-D]'
        . ')/i';
    $joined = [];
    foreach ($lines as $line) {
        if (empty($joined) || preg_match($reAnchor, $line)) { $joined[] = $line; }
        else { $joined[count($joined) - 1] .= ' ' . $line; }
    }

    // ── 7. Orphan option label rejoining ─────────────────────
    $assembled = [];
    for ($j = 0, $jMax = count($joined); $j < $jMax; $j++) {
        $line = $joined[$j];
        if (preg_match('/^(?:[(\[]?\s*)?[a-dA-D1-4]\s*[).]\s*$/', $line) && isset($joined[$j + 1])) {
            $next = $joined[$j + 1];
            if (!preg_match('/^[a-dA-D0-9][\s).\[:]/', $next)) {
                $assembled[] = rtrim($line) . ' ' . $next; $j++; continue;
            }
        }
        $assembled[] = $line;
    }
    $lines = $assembled;

    // ── 8. Trailing answer key pre-scan ──────────────────────
    $trailingAnswers = [];
    $reAKPair    = '/^\s*(?:Q(?:uestion)?\s*|No\.?\s*)?(\d+)\s*[-–.:)\s]+\s*([a-dA-D])\s*\.?\s*$/i';
    $reAKHeading = '/^(?:answer\s*(?:key|sheet)?|answers?|key|solutions?|correct\s+answers?)\s*[:\-]?\s*$/i';
    $reAKMulti   = '/^(?:\d+\s*[-–.]\s*[A-Da-d]\s+){2,}/i';
    $totalLines  = count($lines);
    $akStart     = -1;

    for ($r = $totalLines - 1; $r >= 0; $r--) {
        $l = $lines[$r];
        if (preg_match($reAKPair, $l) || preg_match($reAKMulti, $l)) { $akStart = $r; }
        elseif (preg_match($reAKHeading, $l)) { $akStart = $r; break; }
        elseif ($akStart !== -1) { break; }
        elseif (($totalLines - 1 - $r) > 6) { break; }
    }
    if ($akStart !== -1) {
        for ($r = $akStart; $r < $totalLines; $r++) {
            $l = $lines[$r];
            if (preg_match($reAKPair, $l, $akm)) { $trailingAnswers[(int)$akm[1]] = strtolower($akm[2]); }
            if (preg_match($reAKMulti, $l)) {
                preg_match_all('/(\d+)\s*[-–.]\s*([A-Da-d])/i', $l, $akms);
                foreach ($akms[1] as $ki => $knum) { $trailingAnswers[(int)$knum] = strtolower($akms[2][$ki]); }
            }
        }
        array_splice($lines, $akStart);
        $totalLines = count($lines);
    }

    // ── 9. Core regex patterns ────────────────────────────────
    $reQuestion      = '/^(?:(?:Qs?(?:uestions?|ues?\.?)?\s*[-–.]?\s*)?(\d+)\s*[.):\-–\s]\s*(.{2,}))/i';
    $reOption        = '/^(?:(?:option|opt\.?|choice)\s*)?[\[(]?\s*([a-dA-D]|[1-4]|i{1,3}v?|vi{0,3})\s*[\].):\-–|*\s]\s*(.+)/ix';
    $reAnswer        = '/^(?:answer(?:\s+is)?|ans(?:wer)?|ans\.|key|correct(?:\s+answer)?|solution|right\s+answer|marked\s+answer)\s*[=:\-–.\s]*\s*([a-dA-D]|true|false|yes|no|[1-4])\b/i';
    $reAnswerCompact = '/^(?:answer(?:\s+is)?|ans(?:wer)?|ans\.|key|correct(?:\s+answer)?|solution|right\s+answer)\s*[=:\-–.\s]*([a-dA-D]|true|false|yes|no|[1-4])\.?\s*$/i';
    $reBareAns       = '/^\(?([a-dA-D])\)?\.?\s*$/i';
    $reMarkedAns     = '/^(?:\*{1,2}|✓|→|←|>)\s*([a-dA-D])\s*(?:[).\*].*)?$/i';
    $reEmbeddedAns   = '/(?:answer(?:\s+is)?|ans(?:wer)?|correct)\s*[=:.\-–\s]+([a-dA-D])\s*\.?\s*$/i';
    $reNextQ         = '/^(?:Q(?:uestions?|ues?\.?)?\s*[-–.]?\s*)?\d+\s*[.):\-–\s]/i';
    $reTrueFalse     = '/^(true|false|yes|no)\s*$/i';
    $reExplain       = '/^(?:explanation|rationale|reason|solution|note)\s*[:\-–]/i';

    // ── 10. Normalisation helpers ─────────────────────────────
    $normaliseAnswer = function(string $raw): string {
        $raw = strtolower(trim($raw));
        if ($raw === '1') return 'a'; if ($raw === '2') return 'b';
        if ($raw === '3') return 'c'; if ($raw === '4') return 'd';
        if ($raw === 'i')   return 'a'; if ($raw === 'ii')  return 'b';
        if ($raw === 'iii') return 'c'; if ($raw === 'iv')  return 'd';
        if ($raw === 'yes') return 'true'; if ($raw === 'no') return 'false';
        return $raw;
    };

    $normaliseOptLabel = function(string $raw): string {
        $raw = strtolower(trim($raw));
        if ($raw === '1' || $raw === 'i')   return 'a';
        if ($raw === '2' || $raw === 'ii')  return 'b';
        if ($raw === '3' || $raw === 'iii') return 'c';
        if ($raw === '4' || $raw === 'iv')  return 'd';
        return $raw;
    };

    // ── 11. Main parsing loop ─────────────────────────────────
    $i = 0; $qNum = 0; $qIndex = 1;

    while ($i < $totalLines) {
        $line = $lines[$i];
        if (preg_match($reExplain, $line)) { $i++; continue; }
        if (!preg_match($reQuestion, $line, $qMatch)) { $i++; continue; }

        $qNum          = (int)$qMatch[1];
        $questionText  = trim($qMatch[2]);
        $options       = [];
        $correctAnswer = null;
        $i++;
        $unrecognised  = 0;

        if (preg_match($reEmbeddedAns, $questionText, $eaM)) {
            $correctAnswer = $normaliseAnswer($eaM[1]);
            $questionText  = trim(preg_replace($reEmbeddedAns, '', $questionText));
        }

        // ── True/False look-ahead ────────────────────────────
        $tfTokens = []; $lookahead = 0;
        while (($i + $lookahead) < $totalLines
            && !preg_match($reOption, $lines[$i + $lookahead])
            && !preg_match($reNextQ, $lines[$i + $lookahead])) {
            $ll = trim($lines[$i + $lookahead]);
            if (preg_match($reTrueFalse, $ll)) $tfTokens[] = strtolower($ll);
            $lookahead++;
        }
        $hasTF = (in_array('true',  $tfTokens, true) && in_array('false', $tfTokens, true))
              || (in_array('yes',   $tfTokens, true) && in_array('no',    $tfTokens, true));

        if ($hasTF) {
            for ($k = 0; $k < $lookahead; $k++) $i++;
            $scanFrom = $i - $lookahead;
            for ($s = $scanFrom; $s < min($scanFrom + $lookahead + 3, $totalLines); $s++) {
                $sl = trim($lines[$s]);
                if (preg_match($reAnswer, $sl, $ansM) || preg_match($reAnswerCompact, $sl, $ansM)) {
                    $r = $normaliseAnswer($ansM[1]);
                    $correctAnswer = ($r === 'a' || $r === 'true' || $r === 'yes') ? 'true' : 'false';
                    if ($s >= $i) $i = $s + 1;
                    break;
                }
                if ($s >= $i && preg_match($reTrueFalse, $sl, $tfA)) {
                    $correctAnswer = ($tfA[1] === 'yes') ? 'true' : (($tfA[1] === 'no') ? 'false' : strtolower($tfA[1]));
                    $i = $s + 1; break;
                }
            }
            while ($i < $totalLines && !preg_match($reNextQ, $lines[$i])
                && (preg_match($reAnswer, $lines[$i]) || preg_match($reAnswerCompact, $lines[$i])
                    || preg_match($reTrueFalse, $lines[$i]) || preg_match($reExplain, $lines[$i]))) { $i++; }
            if ($correctAnswer === null && isset($trailingAnswers[$qNum])) {
                $r = $trailingAnswers[$qNum];
                $correctAnswer = ($r === 'a') ? 'true' : 'false';
            }
            $questions[] = [
                'id'            => $qIndex++,
                'type'          => 'true_false',
                'text'          => $questionText,
                'options'       => ['True', 'False'],
                'correctAnswer' => $correctAnswer ?? 'true',
            ];
            continue;
        }

        // ── MCQ branch ───────────────────────────────────────
        while ($i < $totalLines && count($options) < 4) {
            $ol = $lines[$i];
            if (preg_match($reNextQ, $ol)) break;
            if (preg_match($reExplain, $ol)) { $i++; break; }

            if (preg_match($reMarkedAns, $ol, $maM)) {
                $correctAnswer = $normaliseAnswer($maM[1]);
                if (preg_match($reOption, ltrim($ol, '*✓→←> '), $optM)) {
                    $key = $normaliseOptLabel($optM[1]);
                    $options[$key] = trim($optM[2]);
                }
                $i++; continue;
            }

            if (preg_match($reAnswer, $ol, $ansM) || preg_match($reAnswerCompact, $ol, $ansM)) {
                $r = $normaliseAnswer($ansM[1]);
                $correctAnswer = (strlen($r) === 1 && ctype_alpha($r)) ? $r : $correctAnswer;
                $i++; break;
            }

            if (count($options) >= 2 && preg_match($reBareAns, $ol, $bm)) {
                $correctAnswer = strtolower($bm[1]); $i++; break;
            }

            if (preg_match($reOption, $ol, $optM)) {
                $key = $normaliseOptLabel($optM[1]);
                if (isset($options[$key])) { $i++; $unrecognised++; continue; }
                $optText = preg_replace('/\s*(?:←|→|✓|\*{1,2}|correct|right)\s*$/i', '', trim($optM[2]));
                $options[$key] = $optText; $unrecognised = 0; $i++; continue;
            }

            if (count($options) > 0 && $unrecognised === 0) {
                $lastKey = array_key_last($options);
                if (strlen($ol) < 80 && !preg_match($reNextQ, $ol)) {
                    $options[$lastKey] .= ' ' . $ol; $i++; continue;
                }
            }
            $i++; $unrecognised++;
            if (count($options) >= 2 && $unrecognised >= 2) break;
            if (count($options) === 0 && $unrecognised >= 4) break;
        }

        // Straggling answer line right after options
        if ($correctAnswer === null && $i < $totalLines) {
            $nl = $lines[$i];
            if (preg_match($reAnswer, $nl, $ansM) || preg_match($reAnswerCompact, $nl, $ansM)) {
                $r = $normaliseAnswer($ansM[1]);
                $correctAnswer = (strlen($r) === 1 && ctype_alpha($r)) ? $r : null;
                $i++;
            } elseif (count($options) >= 2 && preg_match($reBareAns, $nl, $bm)) {
                $correctAnswer = strtolower($bm[1]); $i++;
            } elseif (preg_match($reMarkedAns, $nl, $maM)) {
                $correctAnswer = $normaliseAnswer($maM[1]); $i++;
            }
        }

        // Consume explanation block
        if ($i < $totalLines && preg_match($reExplain, $lines[$i])) {
            while ($i < $totalLines && !preg_match($reNextQ, $lines[$i])) $i++;
        }

        if (count($options) < 2) continue;

        if ($correctAnswer === null && isset($trailingAnswers[$qNum])) {
            $correctAnswer = $trailingAnswers[$qNum];
        }

        $questions[] = [
            'id'            => $qIndex++,
            'type'          => 'mcq',
            'text'          => $questionText,
            'options'       => [
                $options['a'] ?? '',
                $options['b'] ?? '',
                $options['c'] ?? '',
                $options['d'] ?? '',
            ],
            'correctAnswer' => $correctAnswer,
        ];
    }

    return $questions;
}
