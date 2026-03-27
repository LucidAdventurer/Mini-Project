<?php
/* ============================================================
 * API: Parse PDF for Self Assessment
 * api/self-assessment/parse-pdf.php
 * POST: sa_id, csrf_token
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

// Load assessment — must belong to this student
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
    echo json_encode(['success' => false, 'error' => 'PDF not found']); exit;
}

$pdfPath = $sa['pdf_path'];
// Resolve path relative to project root
if (!file_exists($pdfPath)) {
    $pdfPath = __DIR__ . '/../../' . $sa['pdf_path'];
}
if (!file_exists($pdfPath)) {
    echo json_encode(['success' => false, 'error' => 'PDF file missing on server: ' . $sa['pdf_path']]); exit;
}

// ── Extract text from PDF (pure PHP, no external tools needed) ──
$text = '';
$raw = file_get_contents($pdfPath);

// Method 1: Extract from stream objects (handles most modern PDFs)
preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams);
foreach ($streams[1] as $stream) {
    // Try zlib decompress
    $dec = @gzuncompress($stream);
    if ($dec === false) $dec = @gzinflate($stream);
    $src = ($dec !== false) ? $dec : $stream;
    // Extract text from BT...ET blocks
    preg_match_all('/BT(.*?)ET/s', $src, $bt);
    foreach ($bt[1] as $block) {
        // Tj and TJ operators
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

// Method 2: fallback — grab all parenthesised strings
if (strlen(trim($text)) < 20) {
    preg_match_all('/\(([^\)]{3,})\)/', $raw, $m);
    $text = implode("\n", $m[1]);
}

if (strlen(trim($text)) < 20) {
    echo json_encode(['success' => false, 'error' => 'Could not extract text from PDF. Make sure the PDF has selectable (not scanned) text.']); exit;
}

// ── Parse questions from extracted text ──
$questions = [];
$lines = preg_split('/\r?\n/', $text);
$lines = array_map('trim', $lines);
$lines = array_filter($lines, fn($l) => $l !== '');
$lines = array_values($lines);

$i = 0;
$total = count($lines);

while ($i < $total) {
    $line = $lines[$i];

    // Match question line: "1." or "1)" or "Q1." or "Q.1"
    if (preg_match('/^(?:Q\.?\s*)?\d+[\.\)]\s+(.+)/i', $line, $qm)) {
        $qText   = trim($qm[1]);
        $options = [];
        $correct = 'a';
        $i++;

        // Collect option lines
        while ($i < $total) {
            $ol = $lines[$i];
            if (preg_match('/^([A-D])[\.\)]\s*(.+)/i', $ol, $om)) {
                $options[strtolower($om[1])] = trim($om[2]);
                $i++;
            } elseif (preg_match('/^Answer\s*:\s*([A-D])/i', $ol, $am)) {
                $correct = strtolower($am[1]);
                $i++;
                break;
            } elseif (preg_match('/^(?:Q\.?\s*)?\d+[\.\)]\s+/i', $ol)) {
                // Next question starts — don't advance
                break;
            } else {
                $i++;
            }
        }

        if ($qText && count($options) >= 2) {
            $questions[] = [
                'text'          => $qText,
                'options'       => [
                    $options['a'] ?? '',
                    $options['b'] ?? '',
                    $options['c'] ?? '',
                    $options['d'] ?? '',
                ],
                'correctAnswer' => $correct,
            ];
        }
    } else {
        $i++;
    }
}

if (empty($questions)) {
    echo json_encode(['success' => false, 'error' => 'No questions found. Make sure PDF follows the format: "1. Question", "A) Option", "Answer: A"']);
    exit;
}

echo json_encode(['success' => true, 'questions' => $questions, 'count' => count($questions)]);
