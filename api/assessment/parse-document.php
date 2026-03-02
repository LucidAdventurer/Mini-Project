<?php
// ============================================================
// api/assessment/parse-document.php
//
// Accepts a multipart file upload (PDF or DOCX).
// Extracts raw text using pure PHP — no Composer, no libraries.
// Parses MCQ questions using regex.
// Returns JSON array of questions.
//
// Called by: create-assessment.php frontend JS
// Method:    POST (multipart/form-data)
// Field:     'document' (the uploaded file)
// Returns:   { success: true, questions: [...] }
//         or { success: false, error: '...' }
// ============================================================

// ── Dependencies ──
// Go up two levels from api/assessment/ to reach project root
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

// ── Only teachers may call this endpoint ──
validateSession($conn, 'teacher');

// ── Only accept POST ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

header('Content-Type: application/json');

// ── Validate uploaded file ──
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
    $code    = $_FILES['document']['error'] ?? UPLOAD_ERR_NO_FILE;
    $message = $uploadErrors[$code] ?? 'Unknown upload error.';
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

$file     = $_FILES['document'];
$origName = strtolower($file['name']);
$tmpPath  = $file['tmp_name'];
$fileSize = $file['size'];

// ── Size limit: 10MB ──
if ($fileSize > 10 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum is 10MB.']);
    exit;
}

// ── Detect type by extension and MIME ──
$ext      = pathinfo($origName, PATHINFO_EXTENSION);
$mimeType = mime_content_type($tmpPath);

$isPDF  = ($ext === 'pdf' || $mimeType === 'application/pdf');
$isDOCX = ($ext === 'docx' || $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
$isDOC  = ($ext === 'doc'  || $mimeType === 'application/msword');

if ($isDOC) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Legacy .doc format is not supported. Please save as .docx or export as PDF.']);
    exit;
}

if (!$isPDF && !$isDOCX) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported file type. Please upload a PDF or DOCX file.']);
    exit;
}

// ============================================================
// TEXT EXTRACTION
// ============================================================

$rawText = '';

if ($isDOCX) {
    $rawText = extractTextFromDOCX($tmpPath);
} elseif ($isPDF) {
    $rawText = extractTextFromPDF($tmpPath);
}

if (empty(trim($rawText))) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => $isPDF
            ? 'No text found in PDF. The file may be scanned or image-based. Try a text-based PDF or use DOCX instead.'
            : 'No text found in the Word document. Make sure the file contains typed text.'
    ]);
    exit;
}

// ============================================================
// QUESTION PARSING
// ============================================================

$questions = parseQuestions($rawText);

if (empty($questions)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'error'   => 'No questions found. Make sure your document follows the expected format.',
        'hint'    => 'Expected format: numbered questions (1. or 1)) followed by options labeled a) b) c) d) on separate lines. Optionally add "Answer: b" after each question.',
    ]);
    exit;
}

echo json_encode([
    'success'   => true,
    'count'     => count($questions),
    'questions' => $questions,
]);
exit;


// ============================================================
// FUNCTION: extractTextFromDOCX
//
// A .docx file is a ZIP archive.
// The text lives inside word/document.xml as XML.
// We unzip it in memory and strip the XML tags.
// No external libraries needed — just PHP's ZipArchive.
// ============================================================
function extractTextFromDOCX(string $path): string {
    if (!class_exists('ZipArchive')) {
        error_log('parse-document.php: ZipArchive extension not available');
        return '';
    }

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        error_log('parse-document.php: Failed to open DOCX as ZIP');
        return '';
    }

    // The main document content is always at this path inside the ZIP
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false) {
        error_log('parse-document.php: word/document.xml not found in DOCX');
        return '';
    }

    // Insert a space before every XML tag so words don't merge when we strip tags
    $xml = str_replace('<', ' <', $xml);

    // Strip all XML tags
    $text = strip_tags($xml);

    // Collapse multiple whitespace/newlines into single spaces
    $text = preg_replace('/\s+/', ' ', $text);

    // Restore paragraph-like line breaks by treating common paragraph markers
    // The XML uses </w:p> for paragraph end — we already stripped tags, so
    // we use a two-pass approach: first mark paragraphs, then clean up
    // Re-open to do a smarter pass
    $zip2 = new ZipArchive();
    if ($zip2->open($path) === true) {
        $xml2 = $zip2->getFromName('word/document.xml');
        $zip2->close();

        if ($xml2 !== false) {
            // Replace paragraph and line break tags with newlines before stripping
            $xml2 = preg_replace('/<\/w:p>/', "\n", $xml2);
            $xml2 = preg_replace('/<w:br[^>]*\/>/', "\n", $xml2);
            $xml2 = strip_tags($xml2);
            // Decode XML entities
            $xml2 = html_entity_decode($xml2, ENT_QUOTES | ENT_XML1, 'UTF-8');
            // Collapse blank lines but keep single newlines
            $xml2 = preg_replace('/\n{3,}/', "\n\n", $xml2);
            $xml2 = preg_replace('/[ \t]+/', ' ', $xml2);
            return trim($xml2);
        }
    }

    return trim($text);
}


// ============================================================
// FUNCTION: extractTextFromPDF
//
// Pure PHP PDF text extraction without external libraries.
// Works on text-based PDFs (not scanned/image PDFs).
// Extracts text stream content from PDF structure.
// ============================================================
function extractTextFromPDF(string $path): string {
    $content = @file_get_contents($path);
    if ($content === false) {
        error_log('parse-document.php: Could not read PDF file');
        return '';
    }

    $text = '';

    // ── Strategy 1: Extract text from PDF stream objects ──
    // PDF streams contain the actual page content between "stream" and "endstream"
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams);

    foreach ($streams[1] as $stream) {
        // Skip binary/compressed streams (they start with non-printable chars)
        // Compressed streams begin with 0x78 (zlib header)
        if (strlen($stream) > 2 && ord($stream[0]) === 0x78) {
            // Try to decompress
            $decompressed = @gzuncompress($stream);
            if ($decompressed !== false) {
                $stream = $decompressed;
            } else {
                continue; // Skip streams we can't decompress
            }
        }

        // Extract text from PDF content stream operators
        // Tj and TJ are the PDF text-showing operators
        // BT...ET marks a text block

        // Extract (string) Tj — simple text show
        preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s', $stream, $tjMatches);
        foreach ($tjMatches[1] as $match) {
            $text .= decodePdfString($match) . ' ';
        }

        // Extract [(string) num (string)] TJ — text show with kerning
        preg_match_all('/\[((?:[^\[\]]|\((?:[^()\\\\]|\\\\.)*\))*)\]\s*TJ/s', $stream, $tjArrayMatches);
        foreach ($tjArrayMatches[1] as $match) {
            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $match, $stringMatches);
            foreach ($stringMatches[1] as $str) {
                $text .= decodePdfString($str);
            }
            $text .= ' ';
        }

        // Add newline after each stream to help with line separation
        $text .= "\n";
    }

    // ── Strategy 2: Fallback — extract raw printable strings ──
    // If strategy 1 found nothing, pull all printable strings from the PDF
    if (trim($text) === '') {
        preg_match_all('/\(([^\)]{4,})\)/', $content, $rawStrings);
        foreach ($rawStrings[1] as $str) {
            $decoded = decodePdfString($str);
            // Only keep strings that look like readable text (mostly printable ASCII)
            if (preg_match('/^[\x20-\x7E\s]{4,}$/', $decoded)) {
                $text .= $decoded . "\n";
            }
        }
    }

    // Clean up
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);

    return trim($text);
}


// ============================================================
// FUNCTION: decodePdfString
// Handles PDF escape sequences inside string literals
// ============================================================
function decodePdfString(string $str): string {
    // Replace PDF escape sequences
    $str = str_replace(
        ['\\n', '\\r', '\\t', '\\b', '\\f', '\\(', '\\)', '\\\\'],
        ["\n",  "\r",  "\t",  "\x08", "\x0C", '(',   ')',   '\\'],
        $str
    );
    // Remove remaining backslashes before other chars (octal not handled here)
    $str = preg_replace('/\\\\(\d{3})/', '', $str); // skip octal for simplicity
    return $str;
}


// ============================================================
// FUNCTION: parseQuestions
//
// Parses raw text into structured question objects using regex.
//
// Supported formats:
//
// Format A (numbered with dot):
//   1. Question text?
//   a) Option A
//   b) Option B
//   c) Option C
//   d) Option D
//   Answer: a
//
// Format B (numbered with bracket):
//   1) Question text?
//   a. Option A
//   ...
//
// Format C (Q prefix):
//   Q1. Question text?
//   A) Option A
//   ...
//
// Answer line is optional. If present, it is auto-detected.
// ============================================================
function parseQuestions(string $text): array {

    $questions = [];

    // ── Normalise line endings ──
    $text = str_replace("\r\n", "\n", $text);
    $text = str_replace("\r", "\n", $text);

    // ── Split into lines and clean up ──
    $lines = explode("\n", $text);
    $lines = array_map('trim', $lines);
    $lines = array_values(array_filter($lines, fn($l) => $l !== ''));

    $i          = 0;
    $totalLines = count($lines);
    $qIndex     = 1; // sequential ID — never trust client IDs

    while ($i < $totalLines) {
        $line = $lines[$i];

        // ── Detect question line ──
        // Matches: "1." "1)" "Q1." "Q1)" "Question 1." "Question 1:"
        // Followed by at least 5 characters of question text
        if (!preg_match('/^(?:Q(?:uestion)?\s*)?(\d+)[.):\s]\s*(.{5,})/i', $line, $qMatch)) {
            $i++;
            continue;
        }

        $questionText = trim($qMatch[2]);
        $options      = [];
        $correctAnswer = null;
        $i++;

        // ── Collect option lines ──
        // Options are labeled: a) a. A) A. (a) [a]
        // We collect up to 4 options (a–d or A–D)
        while ($i < $totalLines && count($options) < 4) {
            $optLine = $lines[$i];

            // Check if it's an option line
            if (preg_match('/^[\[(]?([a-dA-D])[\].):\s]\s*(.+)/', $optLine, $optMatch)) {
                $options[strtolower($optMatch[1])] = trim($optMatch[2]);
                $i++;
            }
            // Check if it's an answer line — stop collecting options
            elseif (preg_match('/^(?:answer|ans|key|correct)[:\s.]+([a-dA-D])/i', $optLine, $ansMatch)) {
                $correctAnswer = strtolower($ansMatch[1]);
                $i++;
                break;
            }
            // Check if it looks like the next question — stop
            elseif (preg_match('/^(?:Q(?:uestion)?\s*)?\d+[.):\s]/i', $optLine)) {
                break;
            }
            // Blank-ish or unrecognised line — skip but keep looking
            else {
                $i++;
                // If we already have some options and hit 2 unrecognised lines, stop
                if (count($options) > 0) {
                    break;
                }
            }
        }

        // ── Check for answer line immediately after options ──
        if ($correctAnswer === null && $i < $totalLines) {
            if (preg_match('/^(?:answer|ans|key|correct)[:\s.]+([a-dA-D])/i', $lines[$i], $ansMatch)) {
                $correctAnswer = strtolower($ansMatch[1]);
                $i++;
            }
        }

        // ── Only keep questions with at least 2 options ──
        if (count($options) < 2) {
            continue;
        }

        // ── Build ordered options array [a, b, c, d] ──
        $orderedOptions = [
            $options['a'] ?? '',
            $options['b'] ?? '',
            $options['c'] ?? '',
            $options['d'] ?? '',
        ];

        $questions[] = [
            'id'            => $qIndex++,  // server-generated sequential ID
            'text'          => $questionText,
            'options'       => $orderedOptions,
            'correctAnswer' => $correctAnswer, // null if not marked in doc
        ];
    }

    return $questions;
}