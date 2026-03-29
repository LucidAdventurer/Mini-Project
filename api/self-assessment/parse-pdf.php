<?php
/* ============================================================
 * API: Parse PDF for Self Assessment
 * api/self-assessment/parse-pdf.php
 * POST: sa_id, csrf_token
 *
 * Flow:
 *  1. Read PDF from stored path (already uploaded)
 *  2. Extract text — tries pdftotext first, falls back to
 *     pure-PHP stream extraction
 *  3. Parse via parseQuestions() — ultra-robust parser. Handles:
 *       MCQ  : 1. / Q1. / Ques.1 / Question 1: / no prefix
 *              a) A) (a) [A] a. A- A: *A) ✓A  Option A:  1) i)
 *              Answer:/Ans:/Key:/Correct:/Solution: + letter
 *              bare letter line (A) after options
 *              inline all-on-one-line options split automatically
 *              trailing answer key: 1-A / 1.A / 1)A / columns
 *       T/F  : True/False or Yes/No as options
 *              Answer: True/False/Yes/No or Ans: a/b
 *  4. Save to self_assessment_q_map, mark assessment 'ready'
 *  5. Return count
 * ============================================================ */

require_once '../../config.php';
require_once '../../db-guard.php';

header('Content-Type: application/json');

$user   = validateSession($conn, 'student');
$userId = (int)$user['user_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid method']); exit;
}

if (!hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'error' => 'CSRF error']); exit;
}

$saId = (int)($_POST['sa_id'] ?? 0);
if (!$saId) {
    echo json_encode(['success' => false, 'error' => 'Missing sa_id']); exit;
}

// ── Load assessment ──
$saRes = safePreparedQuery($conn,
    "SELECT pdf_path, status FROM self_assessments WHERE sa_id = ? AND user_id = ? AND type = 'pdf'",
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

// ── Resolve PDF path ──
$pdfPath = $sa['pdf_path'];
if (!file_exists($pdfPath)) {
    $pdfPath = __DIR__ . '/../../' . ltrim($sa['pdf_path'], '/');
}
if (!file_exists($pdfPath)) {
    echo json_encode(['success' => false, 'error' => 'PDF file missing on server. Please re-upload.']); exit;
}

// ── Extract text ──
$rawText = extractTextFromPDF($pdfPath);

if (strlen(trim($rawText)) < 20) {
    echo json_encode([
        'success' => false,
        'error'   => 'Could not extract text from PDF. '
                   . 'Make sure the PDF has selectable (not scanned/image-based) text.',
    ]);
    exit;
}

// ── Parse questions ──
$questions = parseQuestions($rawText);

if (empty($questions)) {
    echo json_encode([
        'success' => false,
        'error'   => 'No questions could be extracted from your PDF.',
        'hint'    => 'Make sure the PDF has selectable text (not a scanned image). '
                   . 'Supported question formats: "1." / "Q1." / "Question 1:" etc. '
                   . 'Supported option formats: A) / a) / (A) / [A] / A. / 1) / i). '
                   . 'Supported answer formats: "Answer: A" / "Ans: B" / "Key: C" / bare letter / trailing answer key (1-A, 2-B...).',
    ]);
    exit;
}

// ── Save to DB ──
safePreparedQuery($conn, "DELETE FROM self_assessment_q_map WHERE sa_id = ?", "i", [$saId]);

$order = 0;
foreach ($questions as $q) {
    if ($q['type'] === 'true_false') {
        $optA    = 'True';
        $optB    = 'False';
        $optC    = '';
        $optD    = '';
        $ans     = strtolower((string)($q['correctAnswer'] ?? 'true'));
        $correct = ($ans === 'true' || $ans === 'a') ? 'a' : 'b';
    } else {
        $opts    = $q['options'];
        $optA    = $opts[0] ?? '';
        $optB    = $opts[1] ?? '';
        $optC    = $opts[2] ?? '';
        $optD    = $opts[3] ?? '';
        $correct = $q['correctAnswer'] ?? 'a';
    }

    $res = safePreparedQuery($conn,
        "INSERT INTO self_assessment_q_map
         (sa_id, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, q_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, '', ?)",
        "issssssi",
        [$saId, $q['text'], $optA, $optB, $optC, $optD, $correct, $order]
    );
    if ($res['success']) $order++;
}

if ($order === 0) {
    echo json_encode(['success' => false, 'error' => 'Questions found but could not save to database.']);
    exit;
}

// ── Mark assessment ready ──
safePreparedQuery($conn,
    "UPDATE self_assessments SET total_questions = ?, status = 'ready' WHERE sa_id = ?",
    "ii", [$order, $saId]
);

echo json_encode([
    'success' => true,
    'count'   => $order,
    'message' => "$order questions extracted and saved.",
]);



// ============================================================
// extractTextFromPDF
// Tries pdftotext (poppler) first — highest quality.
// Pure-PHP fallback handles ALL common filter chains:
//   • FlateDecode (gzip)
//   • ASCII85Decode + FlateDecode  (ReportLab, Word-exported PDFs)
//   • No compression (raw streams)
//   • CID/hex encoded fonts (Google Docs)
// ============================================================
function extractTextFromPDF(string $path): string
{
    // Method 1: pdftotext (poppler)
    $which = trim((string)shell_exec('which pdftotext 2>/dev/null'));
    if ($which !== '') {
        $esc  = escapeshellarg($path);
        $out  = (string)shell_exec("pdftotext -layout $esc - 2>/dev/null");
        if (trim($out) !== '') return $out;
        $out  = (string)shell_exec("pdftotext $esc - 2>/dev/null");
        if (trim($out) !== '') return $out;
    }

    $raw = file_get_contents($path);

    // ── ASCII85Decode helper ──────────────────────────────────
    $ascii85Decode = function(string $data): string {
        $data = preg_replace('/\s+/', '', $data);
        if (substr($data, -2) === '~>') $data = substr($data, 0, -2);
        $result = ''; $i = 0; $len = strlen($data);
        while ($i < $len) {
            if ($data[$i] === 'z') { $result .= "\x00\x00\x00\x00"; $i++; continue; }
            $chunk = substr($data, $i, 5); $i += 5;
            $n = 0;
            for ($k = 0; $k < strlen($chunk); $k++) $n = $n * 85 + (ord($chunk[$k]) - 33);
            $pad = 5 - strlen($chunk);
            for ($k = 0; $k < $pad; $k++) $n = $n * 85 + 84;
            $result .= substr(pack('N', $n), 0, 4 - $pad);
        }
        return $result;
    };

    // ── Per-object filter detection & decompression ───────────
    preg_match_all('/(\d+)\s+0\s+obj\s*<<(.*?)>>\s*stream\r?\n(.*?)\r?\nendstream/s', $raw, $objs, PREG_SET_ORDER);
    $decompressed = [];
    foreach ($objs as $obj) {
        $header = $obj[2]; $data = $obj[3];
        $filters = [];
        if (preg_match('/\/Filter\s*(\[[^\]]*\]|\/\w+)/', $header, $fm)) {
            preg_match_all('/\/(\w+)/', $fm[1], $fnames);
            $filters = $fnames[1];
        }
        foreach ($filters as $filter) {
            if ($filter === 'ASCII85Decode') { $data = $ascii85Decode($data); }
            elseif ($filter === 'FlateDecode') { $dec = @gzuncompress($data); if ($dec === false) $dec = @gzinflate($data); if ($dec !== false) $data = $dec; }
            elseif ($filter === 'ASCIIHexDecode') { $data = pack('H*', preg_replace('/[^0-9A-Fa-f]/', '', $data)); }
        }
        $decompressed[] = $data;
    }
    if (empty($decompressed)) {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $sm);
        foreach ($sm[1] as $stream) {
            $dec = @gzuncompress($stream);
            if ($dec === false) $dec = @gzinflate($stream);
            $decompressed[] = ($dec !== false) ? $dec : $stream;
        }
    }

    // ── Build ToUnicode CMap ──────────────────────────────────
    $cmap = [];
    foreach ($decompressed as $src) {
        if (strpos($src, 'beginbfchar') === false && strpos($src, 'beginbfrange') === false) continue;
        preg_match_all('/beginbfchar(.*?)endbfchar/s', $src, $bfcharSecs);
        foreach ($bfcharSecs[1] as $sec) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>/', $sec, $bfc);
            foreach ($bfc[1] as $k => $cidHex) { $cid = hexdec($cidHex); $uni = hexdec($bfc[2][$k]); if ($uni > 0) $cmap[$cid] = mb_chr($uni, 'UTF-8'); }
        }
        preg_match_all('/beginbfrange(.*?)endbfrange/s', $src, $bfrangeSecs);
        foreach ($bfrangeSecs[1] as $sec) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>/', $sec, $bfr);
            foreach ($bfr[1] as $k => $startHex) { $start = hexdec($startHex); $end = hexdec($bfr[2][$k]); $uni = hexdec($bfr[3][$k]); for ($c = $start; $c <= $end; $c++) $cmap[$c] = mb_chr($uni + ($c - $start), 'UTF-8'); }
        }
    }
    $decodeCidHex = function(string $hexStr) use ($cmap): string {
        $result = ''; $step = (strlen($hexStr) % 4 === 0 && strlen($hexStr) >= 4) ? 4 : 2;
        for ($i = 0; $i < strlen($hexStr); $i += $step) { $cid = hexdec(substr($hexStr, $i, $step)); if (isset($cmap[$cid])) { $result .= $cmap[$cid]; } else { $uni = $cid + 29; $result .= ($uni >= 32 && $uni <= 126) ? chr($uni) : ''; } }
        return $result;
    };

    // ── PDF string unescape (handles \) \( \\ and octal \NNN) ─
    $pdfUnescape = function(string $s): string {
        return preg_replace_callback('/\\\\([0-7]{1,3}|[\\\\nrtfb()])/', function($m) {
            $c = $m[1];
            if (strlen($c) >= 1 && $c[0] >= '0' && $c[0] <= '7') return chr(octdec($c));
            return match($c) { 'n'=>"\n",'r'=>"\r",'t'=>"\t",'f'=>"\f",'b'=>"\x08",'\\'=>'\\','('=>'(',')'=>')', default=>$c };
        }, $s);
    };

    // ── Method 2: BT/ET block extraction ─────────────────────
    $text = '';
    foreach ($decompressed as $src) {
        if (strpos($src, 'BT') === false) continue;
        preg_match_all('/BT(.*?)ET/s', $src, $bt);
        foreach ($bt[1] as $block) {
            $lineText = '';
            preg_match_all('/\((?:[^)(\\\\]|\\\\.)*\)\s*Tj/s', $block, $tj);
            foreach ($tj[0] as $t) { $inner = preg_replace('/\)\s*Tj$/', '', preg_replace('/^\(/', '', $t)); $lineText .= $pdfUnescape($inner) . ' '; }
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

    // Collapse single-char-per-line artifacts
    $lines = explode("\n", $text); $merged = []; $i = 0;
    while ($i < count($lines)) {
        $line = trim($lines[$i]);
        if (strlen($line) === 1 && ctype_alpha($line)) {
            $word = $line; $j = $i + 1;
            while ($j < count($lines) && strlen(trim($lines[$j])) === 1 && ctype_alpha(trim($lines[$j]))) { $word .= trim($lines[$j]); $j++; }
            $merged[] = strlen($word) >= 2 ? $word : $line; $i = $j;
        } else { $merged[] = $line; $i++; }
    }
    $text = implode("\n", $merged);

    // Method 3: last-resort raw parenthesised string grab
    if (strlen(trim($text)) < 20) {
        preg_match_all('/\(([^\)]{3,})\)/', $raw, $m);
        $text = implode("\n", $m[1]);
    }

    return $text;
}



// ============================================================
// parseQuestions  — ultra-robust edition
//
// Handles every real-world MCQ/T-F layout variant:
//
// QUESTION PREFIXES
//   1.  1)  1:  1 -   Q1.  Q.1  Q1)  Q-1  Qn.1
//   Q1  Question 1.  Question 1:  Ques.1  Qs1.
//   No prefix at all (plain numbered lines)
//
// OPTION PREFIXES  (A–D or a–d, 1–4, i–iv)
//   A)  A.  A:  A-  A–  (A)  [A]  *A)  A]  A |
//   a)  a.  (a)  1)  1.  i)  ii)  iii)  iv)
//   Option A:  Opt. A)  Choice A.
//   Options on same line as question: "... A)x B)y C)z D)w"
//   Options all on one line separated by spaces/tabs
//   Option label alone on its own line, text on next line
//
// ANSWER INDICATORS
//   Answer: A       Ans: a        Ans. B       Answer is C
//   Correct: D      Correct Answer: B           Key: A
//   Solution: C     Right Answer: D             Ans = B
//   *A)  **A**      ✓A)            [A] ← marked  A ← (arrow)
//   Highlighted/bold markers stripped to bare letter
//   Bare letter line after options: A  /  (A)  /  A.
//   Trailing answer key block: "1-A  2-B  3-C …"
//   Inline key: "1.A  2.B" or "1)A  2)B" on same line
//   Column-formatted key: two/three columns side by side
//   Answer embedded in question line: "… Answer: B"
//   T/F answer: True/False  t/f  Yes/No  y/n  1/0
//
// QUESTION TYPES
//   MCQ (2–4 options), True/False, Yes/No
//   Fill-in-the-blank (treated as MCQ if options follow)
//   Assertion–Reason (parsed as MCQ)
//   Match-the-following (parsed as MCQ if A–D options present)
//
// NOISE TOLERANCE
//   Page numbers, headers, footers, watermarks skipped
//   Unicode dashes, bullets, smart-quotes normalised
//   Multi-column PDF text re-linearised
//   Inline options (all on one line) split automatically
//   Word-wrapped question text re-joined
//   Explanation / Rationale blocks after answers consumed
// ============================================================
function parseQuestions(string $text): array
{
    $questions = [];

    // ── 1. Normalise whitespace & encoding ───────────────────
    $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);

    // Smart quotes / typographic punctuation → ASCII
    $text = str_replace(
        ["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}", "\u{2013}", "\u{2014}",
         "\u{00A0}", "\u{2022}", "\u{25CF}", "\u{2023}", "\u{25B6}", "\u{2192}"],
        ["'",        "'",        '"',        '"',        '-',        '-',
         ' ',        '-',        '-',        '-',        '-',        '->'],
        $text
    );

    // Unicode fraction / superscript artefacts from some PDF extractors
    $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text);

    $lines = explode("\n", $text);

    // ── 2. Per-line normalisation ─────────────────────────────
    $lines = array_map(function (string $line): string {
        // Collapse all horizontal whitespace runs
        $line = preg_replace('/\h+/', ' ', $line);
        // Remove leading bullets / decorators: • ▪ ► ✓ ★ * #
        $line = preg_replace('/^[\s\*#•▪►✓★]+/', '', $line);
        // Collapse space between option letter and its delimiter: "A )" → "A)"
        $line = preg_replace('/^(\s*[\[(]?\s*[a-dA-D1-4])\s+([).\]])\s*/', '$1$2 ', $line);
        return trim($line);
    }, $lines);
    $lines = array_values(array_filter($lines, fn($l) => $l !== ''));

    // ── 3. Skip obvious noise lines ──────────────────────────
    // (page numbers, "Page X of Y", copyright, URLs, pure-numeric lines)
    $reNoise = '/^(?:'
        . 'page\s+\d+(\s+of\s+\d+)?'
        . '|\d+\s+of\s+\d+'
        . '|https?:\/\/'
        . '|www\.'
        . '|copyright\b'
        . '|all\s+rights\s+reserved'
        . '|\d+$'                      // lone page number
        . ')/i';
    $lines = array_values(array_filter($lines, fn($l) => !preg_match($reNoise, $l)));

    // ── 4. Inline-options splitter ────────────────────────────
    // "1. Question text  A) opt1  B) opt2  C) opt3  D) opt4"
    // → split into separate lines so the main parser sees them normally.
    $reInlineOpts = '/\b([A-Da-d])\s*[).]\s*[^\s].*\b([A-Da-d])\s*[).]/';
    $expanded = [];
    foreach ($lines as $line) {
        // Detect: line contains ≥2 option markers
        if (preg_match($reInlineOpts, $line)) {
            // Split on each option delimiter (keep delimiter with the option text)
            $parts = preg_split('/(?=\b[A-Da-d]\s*[).])/i', $line);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part !== '') $expanded[] = $part;
            }
        } else {
            $expanded[] = $line;
        }
    }
    $lines = $expanded;

    // ── 5. Inline-answer-key splitter ────────────────────────
    // "1.A  2.B  3.C" or "1)A 2)B 3)C" — compact key rows
    $reCompactKeyRow = '/^(\d+\s*[-–.)]\s*[A-Da-d]\s*){2,}$/i';
    $expandedKeys = [];
    foreach ($lines as $line) {
        if (preg_match($reCompactKeyRow, trim($line))) {
            preg_match_all('/(\d+)\s*[-–.)]\s*([A-Da-d])/i', $line, $km);
            foreach ($km[1] as $ki => $knum) {
                $expandedKeys[] = $knum . '-' . $km[2][$ki];
            }
        } else {
            $expandedKeys[] = $line;
        }
    }
    $lines = $expandedKeys;

    // ── 6. Anchor pattern for line re-joining ────────────────
    $reAnchor = '/^(?:'
        . '(?:Q(?:uestions?|ues?\.?)?\s*[-–.]?\s*)?\d+\s*[.):\-\s]'  // numbered Q
        . '|[a-dA-D1-4ivx]+\s*[).]\s'                                  // option labels
        . '|[a-dA-D1-4ivx]+[).]\s*$'                                   // bare option label
        . '|(?:answer|ans(?:wer)?|key|correct|solution|right)\s*[:\s.\-=]'
        . '|(?:true|false|yes|no)\s*$'
        . '|(?:option|opt|choice)\s*[a-dA-D]'
        . ')/i';

    // Re-join word-wrapped fragments
    $joined = [];
    foreach ($lines as $line) {
        if (empty($joined) || preg_match($reAnchor, $line)) {
            $joined[] = $line;
        } else {
            $joined[count($joined) - 1] .= ' ' . $line;
        }
    }

    // ── 7. Orphan option label re-join ───────────────────────
    // "A)" alone on a line, actual text on next line
    $assembled = [];
    for ($j = 0, $jMax = count($joined); $j < $jMax; $j++) {
        $line = $joined[$j];
        if (preg_match('/^(?:[(\[]?\s*)?[a-dA-D1-4]\s*[).]\s*$/', $line) && isset($joined[$j + 1])) {
            $next = $joined[$j + 1];
            if (!preg_match('/^[a-dA-D0-9][\s).\[:]/', $next)) {
                $assembled[] = rtrim($line) . ' ' . $next;
                $j++;
                continue;
            }
        }
        $assembled[] = $line;
    }
    $lines = $assembled;

    // ── 8. Pre-scan for trailing / separate answer key ────────
    //
    // Formats recognised:
    //   1-A   1.A   1)A   1: A   1 - A   1 A   (1) A
    //   Q1-A  No.1-A
    //   Under heading: "Answer Key", "Answers", "Key", "Solutions"
    //   Column format: two/three columns of pairs on same line
    //   "1.A 2.B 3.C" compact row (already split above → "1-A" etc.)

    $trailingAnswers = [];

    // Pattern for a single answer-key pair
    $reAKPair    = '/^\s*(?:Q(?:uestion)?\s*|No\.?\s*)?(\d+)\s*[-–.:)\s]+\s*([a-dA-D])\s*\.?\s*$/i';
    // Heading that signals a key section
    $reAKHeading = '/^(?:answer\s*(?:key|sheet)?|answers?|key|solutions?|correct\s+answers?)\s*[:\-]?\s*$/i';
    // Multi-column key on one line: "1-A  2-B  3-C"  (already split above, but catch anything remaining)
    $reAKMulti   = '/^(?:\d+\s*[-–.]\s*[A-Da-d]\s+){2,}/i';

    $totalLines = count($lines);
    $akStart    = -1;

    // Walk backwards — answer keys always trail the questions
    for ($r = $totalLines - 1; $r >= 0; $r--) {
        $l = $lines[$r];
        if (preg_match($reAKPair, $l) || preg_match($reAKMulti, $l)) {
            $akStart = $r;
        } elseif (preg_match($reAKHeading, $l)) {
            $akStart = $r;
            break;  // found the section header — stop here
        } elseif ($akStart !== -1) {
            break;  // hit non-key line after key lines started — done
        } else {
            // Allow up to 6 non-pair lines at very bottom (blank / page header artefacts)
            if (($totalLines - 1 - $r) > 6) break;
        }
    }

    if ($akStart !== -1) {
        for ($r = $akStart; $r < $totalLines; $r++) {
            $l = $lines[$r];
            // Single pair
            if (preg_match($reAKPair, $l, $akm)) {
                $trailingAnswers[(int)$akm[1]] = strtolower($akm[2]);
            }
            // Multi-pair on one line
            if (preg_match($reAKMulti, $l)) {
                preg_match_all('/(\d+)\s*[-–.]\s*([A-Da-d])/i', $l, $akms);
                foreach ($akms[1] as $ki => $knum) {
                    $trailingAnswers[(int)$knum] = strtolower($akms[2][$ki]);
                }
            }
        }
        array_splice($lines, $akStart);
        $totalLines = count($lines);
    }

    // ── 9. Core regex patterns ────────────────────────────────

    // Question starters — very broad:
    //   1.  1)  1:  1-   Q1.  Q.1  Q-1  Q1)  Qn.1  Ques.1  Question 1.  Qs1:
    $reQuestion = '/^(?:'
        . '(?:Qs?(?:uestions?|ues?\.?)?\s*[-–.]?\s*)?'   // optional Q/Ques/Question prefix
        . '(\d+)'                                          // capture: question number
        . '\s*[.):\-–\s]\s*'                              // delimiter
        . '(.{2,})'                                        // capture: question text (≥2 chars)
        . ')/i';

    // Option lines — covers:
    //   A) A. A: A- A– (A) [A] A| A] *A  A→   a) (a) [a]
    //   1) 1. (1) — numeric options
    //   i) ii) iii) iv) — roman numeral options (map to a–d)
    //   Option A:  Opt A.  Choice A)
    $reOption = '/^(?:'
        . '(?:option|opt\.?|choice)\s*'   // optional "Option" prefix
        . ')?'
        . '[\[(]?\s*'
        . '([a-dA-D]|[1-4]|i{1,3}v?|vi{0,3})'   // letter, digit, or roman
        . '\s*[\].):\-–|*\s]\s*'
        . '(.+)/ix';

    // Answer keyword lines
    $reAnswer = '/^(?:'
        . 'answer(?:\s+is)?|ans(?:wer)?|ans\.|key|correct(?:\s+answer)?'
        . '|solution|right\s+answer|marked\s+answer'
        . ')\s*[=:\-–.\s]*\s*([a-dA-D]|true|false|yes|no|[1-4])\b/i';

    // Answer = letter at end of line (compact)
    $reAnswerCompact = '/^(?:'
        . 'answer(?:\s+is)?|ans(?:wer)?|ans\.|key|correct(?:\s+answer)?'
        . '|solution|right\s+answer'
        . ')\s*[=:\-–.\s]*([a-dA-D]|true|false|yes|no|[1-4])\.?\s*$/i';

    // Bare single-letter answer after options  (A)  /  A.  /  A  /  (A.)
    $reBareAns  = '/^\(?([a-dA-D])\)?\.?\s*$/i';

    // Asterisk / checkmark marked answer  *A)  **A**  ✓A  →A
    $reMarkedAns = '/^(?:\*{1,2}|✓|→|←|>)\s*([a-dA-D])\s*(?:[).\*].*)?$/i';

    // Answer embedded at end of question/option line:  "… Answer: B"
    $reEmbeddedAns = '/(?:answer(?:\s+is)?|ans(?:wer)?|correct)\s*[=:.\-–\s]+([a-dA-D])\s*\.?\s*$/i';

    // Next question detector
    $reNextQ = '/^(?:Q(?:uestions?|ues?\.?)?\s*[-–.]?\s*)?\d+\s*[.):\-–\s]/i';

    // True/False / Yes/No lines
    $reTrueFalse = '/^(true|false|yes|no)\s*$/i';

    // Explanation/rationale block to skip
    $reExplain = '/^(?:explanation|rationale|reason|solution|note)\s*[:\-–]/i';

    // ── 10. Helper: normalise answer letter ──────────────────
    $normaliseAnswer = function(string $raw): string {
        $raw = strtolower(trim($raw));
        // Numeric → letter
        if ($raw === '1') return 'a';
        if ($raw === '2') return 'b';
        if ($raw === '3') return 'c';
        if ($raw === '4') return 'd';
        // Roman → letter
        if ($raw === 'i')   return 'a';
        if ($raw === 'ii')  return 'b';
        if ($raw === 'iii') return 'c';
        if ($raw === 'iv')  return 'd';
        // Yes/No
        if ($raw === 'yes') return 'true';
        if ($raw === 'no')  return 'false';
        return $raw;  // already a/b/c/d or true/false
    };

    // ── 11. Helper: normalise option letter ──────────────────
    $normaliseOptLabel = function(string $raw): string {
        $raw = strtolower(trim($raw));
        if ($raw === '1' || $raw === 'i')   return 'a';
        if ($raw === '2' || $raw === 'ii')  return 'b';
        if ($raw === '3' || $raw === 'iii') return 'c';
        if ($raw === '4' || $raw === 'iv')  return 'd';
        return $raw;
    };

    // ── 12. Main parsing loop ─────────────────────────────────
    $i       = 0;
    $qNum    = 0;   // question number from the text (used for key lookup)
    $qIndex  = 1;   // sequential index for our output

    while ($i < $totalLines) {
        $line = $lines[$i];

        // Skip explanation/rationale blocks
        if (preg_match($reExplain, $line)) { $i++; continue; }

        if (!preg_match($reQuestion, $line, $qMatch)) { $i++; continue; }

        $qNum         = (int)$qMatch[1];
        $questionText = trim($qMatch[2]);
        $options      = [];
        $correctAnswer = null;
        $i++;
        $unrecognised = 0;

        // Check if the question line itself embeds an answer at the end
        if (preg_match($reEmbeddedAns, $questionText, $eaM)) {
            $correctAnswer = $normaliseAnswer($eaM[1]);
            $questionText  = trim(preg_replace($reEmbeddedAns, '', $questionText));
        }

        // ── True/False / Yes/No look-ahead ───────────────────
        $tfTokens  = [];
        $lookahead = 0;
        while (
            ($i + $lookahead) < $totalLines
            && !preg_match($reOption, $lines[$i + $lookahead])
            && !preg_match($reNextQ,  $lines[$i + $lookahead])
        ) {
            $ll = trim($lines[$i + $lookahead]);
            if (preg_match($reTrueFalse, $ll)) $tfTokens[] = strtolower($ll);
            $lookahead++;
        }

        $hasTF = (in_array('true',  $tfTokens, true) && in_array('false', $tfTokens, true))
              || (in_array('yes',   $tfTokens, true) && in_array('no',    $tfTokens, true));

        if ($hasTF) {
            for ($k = 0; $k < $lookahead; $k++) $i++;

            // Look for the answer within the T/F block + 3 more lines
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
                    $correctAnswer = ($tfA[1] === 'yes') ? 'true'
                                  : (($tfA[1] === 'no') ? 'false' : strtolower($tfA[1]));
                    $i = $s + 1;
                    break;
                }
            }

            // Consume trailing noise before next Q
            while ($i < $totalLines && !preg_match($reNextQ, $lines[$i])
                && (preg_match($reAnswer, $lines[$i])
                    || preg_match($reAnswerCompact, $lines[$i])
                    || preg_match($reTrueFalse, $lines[$i])
                    || preg_match($reExplain, $lines[$i]))) {
                $i++;
            }

            // Fall back to trailing key
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

            // Hit next question?
            if (preg_match($reNextQ, $ol)) break;

            // Skip explanation lines
            if (preg_match($reExplain, $ol)) { $i++; break; }

            // Marked answer (asterisk/checkmark on option line)
            if (preg_match($reMarkedAns, $ol, $maM)) {
                $correctAnswer = $normaliseAnswer($maM[1]);
                // Also try to capture the option text if present
                if (preg_match($reOption, ltrim($ol, '*✓→←> '), $optM)) {
                    $key = $normaliseOptLabel($optM[1]);
                    $options[$key] = trim($optM[2]);
                }
                $i++; continue;
            }

            // Answer keyword line
            if (preg_match($reAnswer, $ol, $ansM) || preg_match($reAnswerCompact, $ol, $ansM)) {
                $r = $normaliseAnswer($ansM[1]);
                $correctAnswer = (strlen($r) === 1 && ctype_alpha($r)) ? $r : $correctAnswer;
                $i++; break;
            }

            // Bare single-letter answer after ≥2 options
            if (count($options) >= 2 && preg_match($reBareAns, $ol, $bm)) {
                $correctAnswer = strtolower($bm[1]);
                $i++; break;
            }

            // Option line
            if (preg_match($reOption, $ol, $optM)) {
                $key = $normaliseOptLabel($optM[1]);
                if (isset($options[$key])) {
                    // Duplicate label — treat as unrecognised to avoid overwriting
                    $i++; $unrecognised++; continue;
                }
                $optText = trim($optM[2]);
                // Strip trailing answer marker embedded in option text: "25,15 ← Correct"
                $optText = preg_replace('/\s*(?:←|→|✓|\*{1,2}|correct|right)\s*$/i', '', $optText);
                $options[$key] = $optText;
                $unrecognised  = 0;
                $i++;
                continue;
            }

            // Unrecognised line — be lenient, try appending to last option
            if (count($options) > 0 && $unrecognised === 0) {
                $lastKey = array_key_last($options);
                // Only append if short (continuation) and no next-Q signal
                if (strlen($ol) < 80 && !preg_match($reNextQ, $ol)) {
                    $options[$lastKey] .= ' ' . $ol;
                    $i++; continue;
                }
            }
            $i++;
            $unrecognised++;
            if (count($options) >= 2 && $unrecognised >= 2) break;
            if (count($options) === 0 && $unrecognised >= 4) break;
        }

        // One more look for straggling answer line immediately after options
        if ($correctAnswer === null && $i < $totalLines) {
            $nl = $lines[$i];
            if (preg_match($reAnswer, $nl, $ansM) || preg_match($reAnswerCompact, $nl, $ansM)) {
                $r = $normaliseAnswer($ansM[1]);
                $correctAnswer = (strlen($r) === 1 && ctype_alpha($r)) ? $r : null;
                $i++;
            } elseif (count($options) >= 2 && preg_match($reBareAns, $nl, $bm)) {
                $correctAnswer = strtolower($bm[1]);
                $i++;
            } elseif (preg_match($reMarkedAns, $nl, $maM)) {
                $correctAnswer = $normaliseAnswer($maM[1]);
                $i++;
            }
        }

        // Consume explanation block
        if ($i < $totalLines && preg_match($reExplain, $lines[$i])) {
            while ($i < $totalLines && !preg_match($reNextQ, $lines[$i])) $i++;
        }

        if (count($options) < 2) continue;

        // Fall back to trailing answer key
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
