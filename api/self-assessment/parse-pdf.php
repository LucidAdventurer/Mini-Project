<?php
/* ============================================================
 * API: Parse PDF for Self Assessment
 * api/self-assessment/parse-pdf.php
 * POST: sa_id, csrf_token
 *
 * Flow:
 *  1. Read PDF bytes from the stored path (already uploaded)
 *  2. Extract question text in-memory (no external tools)
 *  3. Parse questions from extracted text
 *  4. Save directly into self_assessment_q_map (DB)
 *  5. Mark assessment as 'ready'
 *  6. Return count — no question data sent back to browser
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

// ── Load assessment — must belong to this student ──
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

// ── Allow re-parsing even if already ready (clears old questions) ──
// Resolve PDF path
$pdfPath = $sa['pdf_path'];
if (!file_exists($pdfPath)) {
    $pdfPath = __DIR__ . '/../../' . ltrim($sa['pdf_path'], '/');
}
if (!file_exists($pdfPath)) {
    echo json_encode(['success' => false, 'error' => 'PDF file missing on server. Please re-upload.']); exit;
}

// ── Extract text from PDF (pure PHP, no external tools) ──
$text = '';
$raw  = file_get_contents($pdfPath);

// Method 1: Extract from compressed stream objects (handles most modern PDFs)
preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams);
foreach ($streams[1] as $stream) {
    $dec = @gzuncompress($stream);
    if ($dec === false) $dec = @gzinflate($stream);
    $src = ($dec !== false) ? $dec : $stream;

    preg_match_all('/BT(.*?)ET/s', $src, $bt);
    foreach ($bt[1] as $block) {
        preg_match_all('/\(([^)]*)\)\s*Tj/s', $block, $tj);
        foreach ($tj[1] as $t) $text .= stripcslashes($t) . ' ';

        preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tJ);
        foreach ($tJ[1] as $t) {
            preg_match_all('/\(([^)]*)\)/', $t, $parts);
            foreach ($parts[1] as $p) $text .= stripcslashes($p);
        }
        $text .= "\n";
    }
}

// Method 2: Fallback — grab all parenthesised strings if Method 1 got nothing
if (strlen(trim($text)) < 20) {
    preg_match_all('/\(([^\)]{3,})\)/', $raw, $m);
    $text = implode("\n", $m[1]);
}

if (strlen(trim($text)) < 20) {
    echo json_encode([
        'success' => false,
        'error'   => 'Could not extract text from PDF. Make sure the PDF has selectable (not scanned) text.'
    ]);
    exit;
}

// ── Parse questions from extracted text ──
$questions = [];
$lines = preg_split('/\r?\n/', $text);
$lines = array_map('trim', $lines);
$lines = array_filter($lines, fn($l) => $l !== '');
$lines = array_values($lines);

$i     = 0;
$total = count($lines);

while ($i < $total) {
    $line = $lines[$i];

    // Match: "1." "1)" "Q1." "Q.1" "Q 1."
    if (preg_match('/^(?:Q\.?\s*)?\d+[\.\)]\s+(.+)/i', $line, $qm)) {
        $qText   = trim($qm[1]);
        $options = [];
        $correct = 'a';
        $i++;

        while ($i < $total) {
            $ol = $lines[$i];

            if (preg_match('/^([A-D])[\.\)]\s*(.+)/i', $ol, $om)) {
                $options[strtolower($om[1])] = trim($om[2]);
                $i++;
            } elseif (preg_match('/^(?:Answer|Ans)[\s\.:]*([A-D])/i', $ol, $am)) {
                $correct = strtolower($am[1]);
                $i++;
                break;
            } elseif (preg_match('/^(?:Q\.?\s*)?\d+[\.\)]\s+/i', $ol)) {
                // Next question — don't consume this line
                break;
            } else {
                $i++;
            }
        }

        if ($qText && count($options) >= 2) {
            $questions[] = [
                'text'    => $qText,
                'a'       => $options['a'] ?? '',
                'b'       => $options['b'] ?? '',
                'c'       => $options['c'] ?? '',
                'd'       => $options['d'] ?? '',
                'correct' => $correct,
            ];
        }
    } else {
        $i++;
    }
}

if (empty($questions)) {
    echo json_encode([
        'success' => false,
        'error'   => 'No questions found. PDF must follow the format: "1. Question", "A) Option", "Answer: A"'
    ]);
    exit;
}

// ── Save questions to DB (clear old ones first to allow re-parse) ──
safePreparedQuery($conn,
    "DELETE FROM self_assessment_q_map WHERE sa_id = ?",
    "i", [$saId]
);

$order = 0;
foreach ($questions as $q) {
    $res = safePreparedQuery($conn,
        "INSERT INTO self_assessment_q_map
         (sa_id, question_text, option_a, option_b, option_c, option_d, correct_option, explanation, q_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, '', ?)",
        "issssssi",
        [$saId, $q['text'], $q['a'], $q['b'], $q['c'], $q['d'], $q['correct'], $order]
    );
    if ($res['success']) $order++;
}

if ($order === 0) {
    echo json_encode(['success' => false, 'error' => 'Questions found but could not save to database.']);
    exit;
}

// ── Mark assessment as ready ──
safePreparedQuery($conn,
    "UPDATE self_assessments SET total_questions = ?, status = 'ready' WHERE sa_id = ?",
    "ii", [$order, $saId]
);

echo json_encode([
    'success' => true,
    'count'   => $order,
    'message' => "$order questions extracted and saved."
]);
