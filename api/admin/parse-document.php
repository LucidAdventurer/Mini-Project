<?php
// ============================================================
// api/admin/parse-document.php
//
// Parses questions from an uploaded PDF or DOCX file.
// PDF extraction uses pure PHP (no pdftotext/poppler needed)
// so it works on Windows/AMPPS without any extra tools.
//
// POST (multipart/form-data)  field: 'document'
// Returns { success: true, count: int, questions: [...] }
//      or { success: false, error: string }
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

header('Content-Type: application/json');

if (!isset($conn) || $conn === null) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Database unavailable.']);
    exit;
}

validateSession($conn, 'admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// ── Validate upload ──────────────────────────────────────────
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
            ? 'No text could be extracted from this PDF. Make sure it is a text-based PDF (not a scanned image). If it is scanned, convert it to DOCX using Word or Google Docs first.'
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
//
// Pure PHP extraction — no pdftotext / poppler needed.
// Works on Windows/AMPPS without any external tools.
//
// Strategy:
//   1. Try pdftotext if available (best quality)
//   2. Fall back to pure PHP stream parsing (always works)
// ============================================================
function extractTextFromPDF(string $path): string {

    // ── Attempt 1: pdftotext (Linux/Mac with poppler installed) ──
    if (function_exists('shell_exec') && !str_contains(strtolower(PHP_OS), 'win')) {
        $which = trim(shell_exec('which pdftotext 2>/dev/null') ?? '');
        if ($which !== '') {
            $escaped = escapeshellarg($path);
            $text    = shell_exec("pdftotext -layout $escaped - 2>/dev/null");
            if ($text === null || trim($text) === '') {
                $text = shell_exec("pdftotext $escaped - 2>/dev/null");
            }
            if (!empty(trim($text ?? ''))) {
                return $text;
            }
        }
    }

    // ── Attempt 2: Pure PHP PDF stream parser ──
    return extractTextFromPDFPure($path);
}


// ============================================================
// FUNCTION: extractTextFromPDFPure
//
// Reads raw PDF bytes and extracts text from content streams.
// Handles compressed (FlateDecode/zlib) and uncompressed streams.
// Covers the vast majority of text-based PDFs generated by
// Word, Google Docs, LibreOffice, question paper generators.
// ============================================================
function extractTextFromPDFPure(string $path): string {
    $content = @file_get_contents($path);
    if ($content === false || strlen($content) < 10) return '';

    // Verify it's actually a PDF
    if (substr($content, 0, 4) !== '%PDF') return '';

    $text = '';

    // ── Extract all content streams ──
    // Match both compressed and uncompressed streams
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $streams)) {
        foreach ($streams[1] as $stream) {
            // Try zlib decompression first (FlateDecode — most common)
            $decompressed = @gzuncompress($stream);
            if ($decompressed === false) {
                // Some PDFs use raw deflate without zlib header
                $decompressed = @gzinflate($stream);
            }
            $streamText = $decompressed !== false ? $decompressed : $stream;

            $text .= extractTextFromPDFStream($streamText) . "\n";
        }
    }

    // ── Also extract text from object streams (PDF 1.5+ compressed objects) ──
    if (preg_match_all('/(\d+)\s+\d+\s+obj.*?endobj/s', $content, $objects)) {
        foreach ($objects[0] as $obj) {
            // Look for /Type /ObjStm (object streams)
            if (str_contains($obj, '/ObjStm')) {
                if (preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $obj, $m)) {
                    $decompressed = @gzuncompress($m[1]);
                    if ($decompressed === false) $decompressed = @gzinflate($m[1]);
                    if ($decompressed !== false) {
                        $text .= extractTextFromPDFStream($decompressed) . "\n";
                    }
                }
            }
        }
    }

    return cleanExtractedText($text);
}


// ============================================================
// FUNCTION: extractTextFromPDFStream
//
// Parses PDF content stream operators to extract text.
// Handles: Tj, TJ, ', ", TD, Td, BT/ET blocks.
// ============================================================
function extractTextFromPDFStream(string $stream): string {
    $text = '';

    // Remove binary chunks that aren't text operators
    // Extract BT...ET blocks (text blocks in PDF)
    if (preg_match_all('/BT(.*?)ET/s', $stream, $btBlocks)) {
        foreach ($btBlocks[1] as $block) {
            $text .= parsePDFTextBlock($block) . "\n";
        }
    } else {
        // No BT/ET markers — try parsing the whole stream
        $text = parsePDFTextBlock($stream);
    }

    return $text;
}


// ============================================================
// FUNCTION: parsePDFTextBlock
//
// Extracts strings from PDF text operators within a block.
// ============================================================
function parsePDFTextBlock(string $block): string {
    $text = '';

    // Match Tj operator: (string) Tj
    // Match ' operator:  (string) '
    // Match " operator:  n n (string) "
    if (preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)?\)\s*[Tj\'"]/s', $block, $matches)) {
        foreach ($matches[1] as $raw) {
            $text .= decodePDFString($raw) . ' ';
        }
    }

    // Match TJ operator: [(string) n (string) n ...] TJ
    if (preg_match_all('/\[((?:[^\[\]]|\((?:[^()\\\\]|\\.)*\))*)\]\s*TJ/s', $block, $tjMatches)) {
        foreach ($tjMatches[1] as $tjContent) {
            if (preg_match_all('/\(((?:[^()\\\\]|\\.)*)\)/s', $tjContent, $strMatches)) {
                foreach ($strMatches[1] as $raw) {
                    $decoded = decodePDFString($raw);
                    // Negative kerning values > 100 indicate a word space
                    $text .= $decoded;
                }
                $text .= ' ';
            }
        }
    }

    // Handle Td/TD operators — they indicate line breaks
    $text = preg_replace('/(-?\d+\.?\d*)\s+(-?\d+\.?\d*)\s+T[dD]/', "\n", $text);

    return $text;
}


// ============================================================
// FUNCTION: decodePDFString
//
// Decodes PDF string escapes to readable text.
// Handles: \n \r \t \\ \( \) \ooo (octal)
// Also handles UTF-16BE BOM (common in PDFs with Unicode text)
// ============================================================
function decodePDFString(string $raw): string {
    // Decode PDF escape sequences
    $decoded = '';
    $len     = strlen($raw);
    $i       = 0;

    while ($i < $len) {
        if ($raw[$i] === '\\' && $i + 1 < $len) {
            $next = $raw[$i + 1];
            switch ($next) {
                case 'n':  $decoded .= "\n"; $i += 2; break;
                case 'r':  $decoded .= "\r"; $i += 2; break;
                case 't':  $decoded .= "\t"; $i += 2; break;
                case '\\': $decoded .= '\\'; $i += 2; break;
                case '(':  $decoded .= '(';  $i += 2; break;
                case ')':  $decoded .= ')';  $i += 2; break;
                default:
                    // Octal escape \ddd
                    if ($i + 3 < $len && ctype_digit($raw[$i+1]) && ctype_digit($raw[$i+2] ?? '') && ctype_digit($raw[$i+3] ?? '')) {
                        $octal   = substr($raw, $i + 1, 3);
                        $decoded .= chr(octdec($octal));
                        $i      += 4;
                    } elseif ($i + 2 < $len && ctype_digit($raw[$i+1]) && ctype_digit($raw[$i+2] ?? '')) {
                        $octal   = substr($raw, $i + 1, 2);
                        $decoded .= chr(octdec($octal));
                        $i      += 3;
                    } else {
                        $decoded .= $next;
                        $i      += 2;
                    }
            }
        } else {
            $decoded .= $raw[$i];
            $i++;
        }
    }

    // Detect UTF-16BE (BOM: \xFE\xFF) — common in modern PDFs
    if (strlen($decoded) >= 2 && $decoded[0] === "\xFE" && $decoded[1] === "\xFF") {
        $converted = @mb_convert_encoding(substr($decoded, 2), 'UTF-8', 'UTF-16BE');
        if ($converted !== false && $converted !== '') {
            return $converted;
        }
    }

    // Filter out non-printable bytes (binary garbage in streams)
    $decoded = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $decoded);

    return $decoded;
}


// ============================================================
// FUNCTION: cleanExtractedText
//
// Normalises extracted PDF text for the question parser.
// ============================================================
function cleanExtractedText(string $text): string {
    // Normalise line endings
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    // Collapse runs of spaces/tabs on a single line
    $text = preg_replace('/[ \t]+/', ' ', $text);

    // Remove lines that are just numbers or single characters (page numbers, artifacts)
    $lines = explode("\n", $text);
    $lines = array_filter($lines, function(string $line): bool {
        $line = trim($line);
        if ($line === '') return false;
        // Skip lines that are just a page number or single punctuation
        if (preg_match('/^\d{1,3}$/', $line)) return false;
        return true;
    });

    // Collapse 3+ blank lines into 2
    $text = preg_replace('/\n{3,}/', "\n\n", implode("\n", $lines));

    return trim($text);
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
// Parses raw text into question objects.
// Handles MCQ and True/False formats.
// Supported: 1.  1)  Q1.  Question 1.
// Options:   a) a. A) (a) [a]
// Answers:   Ans: a  Answer: b  Key: c  Correct: d
// ============================================================
function parseQuestions(string $text): array {
    $questions = [];

    $text  = str_replace(["\r\n", "\r", "\f"], "\n", $text);
    $lines = explode("\n", $text);

    $lines = array_map(function(string $line): string {
        $line = preg_replace('/\h+/', ' ', $line);
        $line = preg_replace('/^(\s*[\[(]?\s*[a-dA-D])\s+([).\]])\s*/', '$1$2 ', $line);
        return trim($line);
    }, $lines);
    $lines = array_values(array_filter($lines, fn($l) => $l !== ''));

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