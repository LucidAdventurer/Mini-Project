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
 *  3. Parse via parseQuestions() — same robust parser as
 *     parse-document.php. Handles:
 *       MCQ  : 1. / Q1. / Question 1:
 *              a) A) (a) [A] a. A- A: *A)
 *              Answer:/Ans:/Key:/Correct: + letter
 *              bare letter line (A) after options
 *       T/F  : bare True/False lines as options
 *              Answer: True/False  or  Ans: a/b
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
        'hint'    => 'Supported: numbered questions (1. / Q1. / Question 1:), '
                   . 'options (A) / a) / A. / (A) / A-), '
                   . 'answers (Answer: A / Ans: a / Correct: B / Key: C / bare letter A), '
                   . 'True/False questions.',
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
// Falls back to pure-PHP extraction supporting:
//   • Plain text strings: (text) Tj / [(text)] TJ
//   • CID/hex strings: <XXXX> Tj — used by Google Docs, Word, etc.
//     Decoded via ToUnicode CMap embedded in the PDF.
// Falls back further to raw parenthesised string grab.
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

    // ── Pure-PHP fallback ──
    $raw = file_get_contents($path);

    // Decompress all streams
    $decompressed = [];
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $sm);
    foreach ($sm[1] as $stream) {
        $dec = @gzuncompress($stream);
        if ($dec === false) $dec = @gzinflate($stream);
        $decompressed[] = ($dec !== false) ? $dec : $stream;
    }

    // Build ToUnicode CMap: CID (int) → UTF-8 char
    // CMap streams contain "beginbfchar/endbfchar" and "beginbfrange/endbfrange"
    $cmap = [];
    foreach ($decompressed as $src) {
        if (strpos($src, 'beginbfchar') === false && strpos($src, 'beginbfrange') === false) continue;

        // bfchar: <CID> <Unicode>
        preg_match_all('/<([0-9A-Fa-f]{2,8})>\s+<([0-9A-Fa-f]{2,8})>/', $src, $bfc);
        foreach ($bfc[1] as $k => $cidHex) {
            $cid = hexdec($cidHex);
            $uni = hexdec($bfc[2][$k]);
            if ($uni > 0) $cmap[$cid] = mb_chr($uni, 'UTF-8');
        }

        // bfrange: <start> <end> <unicodeStart>
        preg_match_all('/<([0-9A-Fa-f]{2,8})>\s+<([0-9A-Fa-f]{2,8})>\s+<([0-9A-Fa-f]{2,8})>/', $src, $bfr);
        foreach ($bfr[1] as $k => $startHex) {
            $start = hexdec($startHex);
            $end   = hexdec($bfr[2][$k]);
            $uni   = hexdec($bfr[3][$k]);
            for ($c = $start; $c <= $end; $c++) {
                $cmap[$c] = mb_chr($uni + ($c - $start), 'UTF-8');
            }
        }
    }

    // Helper: decode a CID hex string using CMap, with offset heuristic fallback
    $decodeCidHex = function(string $hexStr) use ($cmap): string {
        $result = '';
        // CIDs are 2 bytes (4 hex chars) for Identity-H/V fonts
        $step = (strlen($hexStr) % 4 === 0 && strlen($hexStr) >= 4) ? 4 : 2;
        for ($i = 0; $i < strlen($hexStr); $i += $step) {
            $cid = hexdec(substr($hexStr, $i, $step));
            if (isset($cmap[$cid])) {
                $result .= $cmap[$cid];
            } else {
                // Heuristic: Google Docs embeds fonts where GID = Unicode - 29
                $uni = $cid + 29;
                $result .= ($uni >= 32 && $uni <= 126) ? chr($uni) : '';
            }
        }
        return $result;
    };

    // Method 2: BT/ET block extraction (handles both string and hex operators)
    $text = '';
    foreach ($decompressed as $src) {
        if (strpos($src, 'BT') === false) continue;
        preg_match_all('/BT(.*?)ET/s', $src, $bt);
        foreach ($bt[1] as $block) {
            $lineText = '';

            // Plain string Tj: (text) Tj
            preg_match_all('/\((?:[^)(\\\\]|\\\\.)*\)\s*Tj/s', $block, $tj);
            foreach ($tj[0] as $t) {
                $inner = preg_replace('/\)\s*Tj$/', '', preg_replace('/^\(/', '', $t));
                $inner = str_replace(['\\)', '\\('], [')', '('], $inner);
                $inner = preg_replace('/\\\\[0-9]{1,3}/', '', $inner);
                $inner = preg_replace('/\\\\[nrtf\\\\]/', ' ', $inner);
                $lineText .= trim($inner) . ' ';
            }

            // Hex string Tj: <XXXX> Tj  (CID-encoded, Google Docs style)
            preg_match_all('/<([0-9A-Fa-f]+)>\s*Tj/', $block, $hexTj);
            foreach ($hexTj[1] as $h) {
                $lineText .= $decodeCidHex($h);
            }

            // Plain TJ array: [(text)(text2)] TJ
            preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tJ);
            foreach ($tJ[1] as $t) {
                // Plain strings in TJ
                preg_match_all('/\((?:[^)(\\\\]|\\\\.)*\)/', $t, $parts);
                foreach ($parts[0] as $p) {
                    $inner = preg_replace('/^\(|\)$/', '', $p);
                    $inner = str_replace(['\\)', '\\('], [')', '('], $inner);
                    $inner = preg_replace('/\\\\[0-9]{1,3}/', '', $inner);
                    $inner = preg_replace('/\\\\[nrtf\\\\]/', ' ', $inner);
                    $lineText .= $inner;
                }
                // Hex strings in TJ
                preg_match_all('/<([0-9A-Fa-f]+)>/', $t, $hexParts);
                foreach ($hexParts[1] as $h) {
                    $lineText .= $decodeCidHex($h);
                }
            }

            $lineText = trim($lineText);
            if ($lineText !== '') $text .= $lineText . "\n";
        }
    }

    // Post-process: collapse single-char-per-line artifacts from CID decoding
    // e.g. "W\nh\na\nt" → "What"
    $lines = explode("\n", $text);
    $merged = [];
    $i = 0;
    while ($i < count($lines)) {
        $line = trim($lines[$i]);
        if (strlen($line) === 1 && ctype_alpha($line)) {
            // Collect a run of single chars
            $word = $line;
            $j = $i + 1;
            while ($j < count($lines) && strlen(trim($lines[$j])) === 1 && ctype_alpha(trim($lines[$j]))) {
                $word .= trim($lines[$j]);
                $j++;
            }
            // Only collapse if we got 2+ chars
            $merged[] = strlen($word) >= 2 ? $word : $line;
            $i = $j;
        } else {
            $merged[] = $line;
            $i++;
        }
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
// parseQuestions
//
// Unified parser — same logic as parse-document.php so both
// endpoints behave identically. Handles MCQ + True/False with
// all common formatting variants.
// ============================================================
function parseQuestions(string $text): array
{
    $questions = [];

    $text  = str_replace(["\r\n", "\r", "\f"], "\n", $text);
    $lines = explode("\n", $text);

    // Normalise each line
    $lines = array_map(function (string $line): string {
        $line = preg_replace('/\h+/', ' ', $line);
        $line = preg_replace('/^(\s*[\[(]?\s*[a-dA-D])\s+([).\]])\s*/', '$1$2 ', $line);
        return trim($line);
    }, $lines);
    $lines = array_values(array_filter($lines, fn($l) => $l !== ''));

    // Anchor pattern — lines that start a new logical unit
    $reAnchor = '/^(?:'
        . '(?:Q(?:uestion)?\s*)?\d+\s*[.):\s]'
        . '|[a-dA-D]\s*[).]\s'
        . '|[a-dA-D][).]\s*$'
        . '|(?:answer|ans(?:wer)?|key|correct)\s*[:\s.]'
        . '|true\s*$'
        . '|false\s*$'
        . ')/i';

    // Re-join word-per-line fragments
    $joined = [];
    foreach ($lines as $line) {
        if (empty($joined) || preg_match($reAnchor, $line)) {
            $joined[] = $line;
        } else {
            $joined[count($joined) - 1] .= ' ' . $line;
        }
    }

    // Reassemble orphan option labels ("a)" on one line, text on next)
    $assembled = [];
    for ($j = 0, $jMax = count($joined); $j < $jMax; $j++) {
        $line = $joined[$j];
        if (preg_match('/^[a-dA-D][).]$/', $line) && isset($joined[$j + 1])) {
            $next = $joined[$j + 1];
            if (!preg_match('/^[a-dA-D0-9][\s).\[:]/', $next)) {
                $assembled[] = $line . ' ' . $next;
                $j++;
                continue;
            }
        }
        $assembled[] = $line;
    }

    $lines      = $assembled;
    $totalLines = count($lines);
    $i          = 0;
    $qIndex     = 1;

    // Core patterns
    $reQuestion      = '/^(?:Q(?:uestion)?\s*)?(\d+)\s*[.):\s]\s*(.{3,})/i';
    $reOption        = '/^\s*[\[(]?\s*([a-dA-D])\s*[\].):\-–\s]\s*(.+)/';
    $reAnswer        = '/^(?:answer|ans(?:wer)?|key|correct(?:\s+answer)?)\s*(?:is\s*)?[\s:.\-–]*\s*([a-dA-D]|true|false)\b/i';
    $reAnswerCompact = '/^(?:answer|ans(?:wer)?|key|correct(?:\s+answer)?)\s*[:\s.\-–]*([a-dA-D]|true|false)\.?\s*$/i';
    $reBareAns       = '/^\(?([a-dA-D])\)?\.?\s*$/i';
    $reNextQ         = '/^(?:Q(?:uestion)?\s*)?\d+\s*[.):\s]/i';
    $reTrueFalse     = '/^(true|false)\s*$/i';

    while ($i < $totalLines) {
        $line = $lines[$i];

        if (!preg_match($reQuestion, $line, $qMatch)) { $i++; continue; }

        $questionText  = trim($qMatch[2]);
        $options       = [];
        $correctAnswer = null;
        $i++;
        $unrecognised  = 0;

        // ── True/False look-ahead ──
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

        $hasBothTF = in_array('true', $tfTokens, true) && in_array('false', $tfTokens, true);

        if ($hasBothTF) {
            for ($k = 0; $k < $lookahead; $k++) $i++;

            $scanFrom = $i - $lookahead;
            for ($s = $scanFrom; $s < min($scanFrom + $lookahead + 3, $totalLines); $s++) {
                $sl = trim($lines[$s]);
                if (preg_match($reAnswer, $sl, $ansMatch) || preg_match($reAnswerCompact, $sl, $ansMatch)) {
                    $raw = strtolower($ansMatch[1]);
                    $correctAnswer = ($raw === 'a' || $raw === 'true') ? 'true' : 'false';
                    if ($s >= $i) $i = $s + 1;
                    break;
                }
                // Bare True/False answer after options window
                if ($s >= $i && preg_match($reTrueFalse, $sl, $tfAns)) {
                    $correctAnswer = strtolower($tfAns[1]);
                    $i = $s + 1;
                    break;
                }
            }

            // Consume any remaining answer/T-F lines before next question
            while (
                $i < $totalLines
                && !preg_match($reNextQ, $lines[$i])
                && (
                    preg_match($reAnswer,         $lines[$i])
                    || preg_match($reAnswerCompact, $lines[$i])
                    || preg_match($reTrueFalse,     $lines[$i])
                )
            ) { $i++; }

            $questions[] = [
                'id'            => $qIndex++,
                'type'          => 'true_false',
                'text'          => $questionText,
                'options'       => ['True', 'False'],
                'correctAnswer' => $correctAnswer ?? 'true',
            ];
            continue;
        }

        // ── MCQ branch ──
        while ($i < $totalLines && count($options) < 4) {
            $ol = $lines[$i];

            if (preg_match($reNextQ, $ol)) break;

            // Answer keyword
            if (preg_match($reAnswer, $ol, $ansMatch) || preg_match($reAnswerCompact, $ol, $ansMatch)) {
                $raw           = strtolower($ansMatch[1]);
                $correctAnswer = (strlen($raw) === 1 && ctype_alpha($raw)) ? $raw : null;
                $i++; break;
            }

            // Bare single-letter answer (after ≥2 options)
            if (count($options) >= 2 && preg_match($reBareAns, $ol, $bm)) {
                $correctAnswer = strtolower($bm[1]);
                $i++; break;
            }

            // Option line
            if (preg_match($reOption, $ol, $optMatch)) {
                $options[strtolower($optMatch[1])] = trim($optMatch[2]);
                $unrecognised = 0;
                $i++;
                continue;
            }

            // Unrecognised line
            $i++;
            $unrecognised++;
            if (count($options) >= 2 && $unrecognised >= 1) break;
            if (count($options) === 0 && $unrecognised >= 3) break;
        }

        // One more look for a straggling answer line
        if ($correctAnswer === null && $i < $totalLines) {
            $nl = $lines[$i];
            if (preg_match($reAnswer, $nl, $ansMatch) || preg_match($reAnswerCompact, $nl, $ansMatch)) {
                $raw           = strtolower($ansMatch[1]);
                $correctAnswer = (strlen($raw) === 1 && ctype_alpha($raw)) ? $raw : null;
                $i++;
            } elseif (count($options) >= 2 && preg_match($reBareAns, $nl, $bm)) {
                $correctAnswer = strtolower($bm[1]);
                $i++;
            }
        }

        if (count($options) < 2) continue;

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
