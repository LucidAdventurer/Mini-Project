<?php
// ============================================================
// api/assessment/parse-document.php
//
// Accepts a multipart file upload (PDF or DOCX).
// PDF  → extracted via pdftotext (poppler)
// DOCX → extracted via ZipArchive + word/document.xml
// Parses MCQ / True-False questions using regex.
// Returns JSON array of questions.
//
// Called by: create-assessment.php frontend JS
// Method:    POST (multipart/form-data)
// Field:     'document' (the uploaded file)
// Returns:   { success: true,  count: int, questions: [...] }
//         or { success: false, error: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

// Send JSON header early so all error responses are JSON
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

$conn = createDatabaseConnection();
if (!$conn) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Database unavailable.']);
    exit;
}

// ── Auth: allow admin or teacher only ──
// We must NOT call validateSession() for both roles in a loop —
// it calls exit() internally on role mismatch, which cannot be caught.
// Instead, read the session role first and validate against it directly.
$sessionRole = getSessionRole();

if (!in_array($sessionRole, ['admin', 'teacher'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. Insufficient permissions.']);
    exit;
}

// Safe to call now — role is confirmed to match
$user = validateSession($conn, $sessionRole);

// ── Validate upload ──
if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Server failed to write file.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension blocked the upload.',
    ];
    $code = $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE;
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $uploadErrors[$code] ?? 'Unknown upload error.']);
    exit;
}

$file     = $_FILES['document'];
$origName = strtolower($file['name']);
$tmpPath  = $file['tmp_name'];

if ($file['size'] > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum size is 10 MB.']);
    exit;
}

$ext      = pathinfo($origName, PATHINFO_EXTENSION);
$mimeType = mime_content_type($tmpPath);

$isPDF  = ($ext === 'pdf'  || $mimeType === 'application/pdf');
$isDOCX = ($ext === 'docx' || $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
$isDOC  = ($ext === 'doc'  || $mimeType === 'application/msword');

if ($isDOC) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Legacy .doc format is not supported. Save as .docx or export as PDF.']);
    exit;
}
if (!$isPDF && !$isDOCX) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported file type. Upload a PDF or DOCX.']);
    exit;
}

$rawText = $isDOCX ? extractTextFromDOCX($tmpPath) : extractTextFromPDF($tmpPath);

if (empty(trim($rawText))) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => $isPDF
            ? 'No text found in PDF. The file may be scanned or image-based. Try a text-based PDF or use DOCX.'
            : 'No text found in the Word document.',
    ]);
    exit;
}

$questions = parseQuestions($rawText);

if (empty($questions)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => 'No questions found. Check your document follows the expected format.',
        'hint'    => 'Expected: numbered questions (1. or 1)) followed by options a) b) c) d). Optionally add "Answer: b".',
    ]);
    exit;
}

echo json_encode(['success' => true, 'count' => count($questions), 'questions' => $questions]);
exit;


// ============================================================
// FUNCTION: extractTextFromPDF
// Uses pdftotext (poppler) — handles all text-based PDFs.
// ============================================================
function extractTextFromPDF(string $path): string {
    $which = trim(shell_exec('which pdftotext 2>/dev/null') ?? '');
    if ($which === '') {
        error_log('parse-document.php: pdftotext not found. Install poppler.');
        return '';
    }
    $escaped = escapeshellarg($path);
    $text    = shell_exec("pdftotext -layout $escaped - 2>/dev/null");
    if ($text === null || trim($text) === '') {
        $text = shell_exec("pdftotext $escaped - 2>/dev/null");
    }
    return $text ?? '';
}


// ============================================================
// FUNCTION: extractTextFromDOCX
// Reads word/document.xml from the ZIP.
// ============================================================
function extractTextFromDOCX(string $path): string {
    if (!class_exists('ZipArchive')) {
        error_log('parse-document.php: ZipArchive not available');
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) return '';

    $xml = preg_replace('/<\/w:p>/',       "\n", $xml);
    $xml = preg_replace('/<w:br[^>]*\/>/', "\n", $xml);
    $xml = strip_tags($xml);
    $xml = html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $xml = preg_replace('/[ \t]+/',   ' ',    $xml);
    $xml = preg_replace('/\n{3,}/', "\n\n", $xml);
    return trim($xml);
}


// ============================================================
// FUNCTION: parseQuestions
//
// Parses raw text into question objects. Handles two types:
// 1. MCQ  — numbered question + lettered options a/b/c/d
// 2. True/False — numbered question + bare True/False lines
//
// Supported question formats: 1.  1)  Q1.  Question 1.
// Supported option formats:   a) a. A) (a) [a]
// Answer formats: Ans: a  Answer: b  Key: c  Correct: d
// ============================================================
function parseQuestions(string $text): array {
    $questions = [];

    $text  = str_replace(["\r\n", "\r", "\f"], "\n", $text);
    $lines = explode("\n", $text);

    // Per-line normalisation
    $lines = array_map(function(string $line): string {
        $line = preg_replace('/\h+/', ' ', $line);
        $line = preg_replace('/^(\s*[\[(]?\s*[a-dA-D])\s+([).\]])\s*/', '$1$2 ', $line);
        return trim($line);
    }, $lines);
    $lines = array_values(array_filter($lines, fn($l) => $l !== ''));

    // Reassemble word-per-line fragments
    $reAnchor = '/^(?:'
        . '(?:Q(?:uestion)?\s*)?\d+\s*[.):\s]'
        . '|[a-dA-D]\s*[).]\s'
        . '|[a-dA-D][).]\s*$'
        . '|(?:answer|ans|key|correct)\s*[:\s.]'
        . '|true\s*$'
        . '|false\s*$'
        . ')/i';

    $joined = [];
    foreach ($lines as $line) {
        if (empty($joined) || preg_match($reAnchor, $line)) {
            $joined[] = $line;
        } else {
            $joined[count($joined) - 1] .= ' ' . $line;
        }
    }
    $lines = $joined;

    // Reassemble orphan option labels: ["a)", "Berlin"] -> ["a) Berlin"]
    $assembled = [];
    for ($j = 0; $j < count($lines); $j++) {
        $line = $lines[$j];
        if (preg_match('/^[a-dA-D][).]$/', $line) && isset($lines[$j + 1])) {
            $next = $lines[$j + 1];
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

    $reQuestion      = '/^(?:Q(?:uestion)?\s*)?(\d+)\s*[.):\s]\s*(.{5,})/i';
    $reOption        = '/^\s*[\[(]?\s*([a-dA-D])\s*[\].):\s]\s*(.+)/';
    $reAnswer        = '/^(?:answer|ans|key|correct)\s*[:\s.]+\s*([a-dA-D]|true|false)\b/i';
    $reAnswerCompact = '/^(?:answer|ans|key|correct)\s*[:\s.]*\s*([a-dA-D]|true|false)\.?\s*$/i';
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

        // Look-ahead: detect True/False question
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
            for ($s = $scanFrom; $s < $scanFrom + $lookahead + 2 && $s < $totalLines; $s++) {
                $sl = trim($lines[$s]);
                if (preg_match($reAnswer, $sl, $ansMatch) || preg_match($reAnswerCompact, $sl, $ansMatch)) {
                    $raw = strtolower($ansMatch[1]);
                    if ($raw === 'a' || $raw === 'true')      $correctAnswer = 'true';
                    elseif ($raw === 'b' || $raw === 'false') $correctAnswer = 'false';
                    if ($s >= $i) $i = $s + 1;
                    break;
                }
            }
            while ($i < $totalLines
                && !preg_match($reNextQ, $lines[$i])
                && (preg_match($reAnswer, $lines[$i]) || preg_match($reAnswerCompact, $lines[$i]) || preg_match($reTrueFalse, $lines[$i]))
            ) { $i++; }

            $questions[] = [
                'id'            => $qIndex++,
                'type'          => 'true_false',
                'text'          => $questionText,
                'options'       => ['True', 'False'],
                'correctAnswer' => $correctAnswer,
            ];
            continue;
        }

        // MCQ branch
        while ($i < $totalLines && count($options) < 4) {
            $optLine = $lines[$i];
            if (preg_match($reOption, $optLine, $optMatch)) {
                $options[strtolower($optMatch[1])] = trim($optMatch[2]);
                $unrecognised = 0;
                $i++;
            } elseif (preg_match($reAnswer, $optLine, $ansMatch) || preg_match($reAnswerCompact, $optLine, $ansMatch)) {
                $raw           = strtolower($ansMatch[1]);
                $correctAnswer = (strlen($raw) === 1 && ctype_alpha($raw)) ? $raw : null;
                $i++;
                break;
            } elseif (preg_match($reNextQ, $optLine)) {
                break;
            } else {
                $i++;
                $unrecognised++;
                if (count($options) >= 2 && $unrecognised >= 1) break;
                if (count($options) === 0 && $unrecognised >= 3) break;
            }
        }

        if ($correctAnswer === null && $i < $totalLines) {
            if (preg_match($reAnswer, $lines[$i], $ansMatch) || preg_match($reAnswerCompact, $lines[$i], $ansMatch)) {
                $raw           = strtolower($ansMatch[1]);
                $correctAnswer = (strlen($raw) === 1 && ctype_alpha($raw)) ? $raw : null;
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