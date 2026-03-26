<?php
/* ========================================
 * API: Parse Questions from PDF / DOCX
 * File: api-parse-questions.php
 *
 * PDF  → extracted via pdftotext (Poppler)
 * DOCX → extracted via PHP ZipArchive (built-in, no extra libs)
 *
 * Accepts:  multipart POST, field "file" (.pdf or .docx, max 10 MB)
 * Returns:  JSON { success, questions[], count }
 *
 * Requires: admin or teacher session + CSRF
 *
 * ── Windows setup ──────────────────────────────────────────
 *  Option A (Poppler in PATH):
 *    Leave POPPLER_PATH as 'pdftotext'
 *    Add  C:\poppler\bin  to System PATH
 *
 *  Option B (full path):
 *    define('POPPLER_PATH', 'C:\\poppler\\bin\\pdftotext.exe');
 *    (add the line above in config.php or uncomment below)
 *
 * ── Linux / Mac setup ──────────────────────────────────────
 *    apt install poppler-utils   OR   brew install poppler
 *    Leave POPPLER_PATH as 'pdftotext'
 * ======================================== */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

header('Content-Type: application/json');

/* ── Poppler binary path ──────────────────────────────────── */
if (!defined('POPPLER_PATH')) {
    // Override in config.php if needed:
    // define('POPPLER_PATH', 'C:\\poppler\\bin\\pdftotext.exe');
    define('POPPLER_PATH', 'pdftotext');
}

/* ── Auth ─────────────────────────────────────────────────── */
$user = null;
foreach (['admin', 'teacher'] as $role) {
    try { $user = validateSession($conn, $role); break; } catch (Exception $e) {}
}
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

/* ── CSRF ─────────────────────────────────────────────────── */
verifyCsrf();

/* ── File validation ──────────────────────────────────────── */
if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $uploadErrors = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder on server.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by a server extension.',
    ];
    $code = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;
    echo json_encode(['success' => false, 'error' => $uploadErrors[$code] ?? 'Upload error.']);
    exit;
}

$file     = $_FILES['file'];
$maxBytes = 10 * 1024 * 1024; // 10 MB

if ($file['size'] > $maxBytes) {
    echo json_encode(['success' => false, 'error' => 'File too large. Maximum is 10 MB.']);
    exit;
}

$origName = basename($file['name']);
$ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

if (!in_array($ext, ['pdf', 'docx'], true)) {
    echo json_encode(['success' => false, 'error' => 'Only PDF and DOCX files are accepted.']);
    exit;
}

/* Magic-byte validation */
$fh    = fopen($file['tmp_name'], 'rb');
$magic = fread($fh, 8);
fclose($fh);

if ($ext === 'pdf' && substr($magic, 0, 4) !== '%PDF') {
    echo json_encode(['success' => false, 'error' => 'File does not appear to be a valid PDF.']);
    exit;
}
if ($ext === 'docx' && substr($magic, 0, 2) !== 'PK') {
    echo json_encode(['success' => false, 'error' => 'File does not appear to be a valid DOCX.']);
    exit;
}

/* Move to safe temp location */
$tmpDir  = sys_get_temp_dir();
$tmpFile = $tmpDir . DIRECTORY_SEPARATOR
         . 'prepaura_' . bin2hex(random_bytes(8)) . '.' . $ext;

if (!move_uploaded_file($file['tmp_name'], $tmpFile)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save uploaded file on server.']);
    exit;
}

/* ── Extract raw text ─────────────────────────────────────── */
$rawText = '';
try {
    $rawText = ($ext === 'pdf') ? extractTextPdf($tmpFile) : extractTextDocx($tmpFile);
} catch (Exception $e) {
    @unlink($tmpFile);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    exit;
}
@unlink($tmpFile);

if (!trim($rawText)) {
    $hint = $ext === 'pdf'
        ? 'Make sure Poppler (pdftotext) is installed on the server.'
        : 'The DOCX file may be empty or corrupted.';
    echo json_encode(['success' => false, 'error' => 'Could not extract any text from the file. ' . $hint]);
    exit;
}

/* ── Parse questions ──────────────────────────────────────── */
$questions = parseQuestions($rawText);

if (empty($questions)) {
    echo json_encode(['success' => false,
        'error' => 'No questions found in the file. '
                 . 'Format: numbered questions (1. or 1)) followed by lettered options (a) b) c) d)). '
                 . 'Mark the correct option with * at the end of the line, e.g. "b) Paris *"'
    ]);
    exit;
}

echo json_encode([
    'success'   => true,
    'questions' => $questions,
    'count'     => count($questions),
]);
exit;


/* ════════════════════════════════════════════════════════════
 * extractTextPdf()
 *
 * Calls pdftotext (Poppler) via shell_exec / exec.
 * Works on Windows, Linux, and Mac.
 *
 * Flags used:
 *   -nopgbrk  — no form-feed page separators
 *   -layout   — preserve spatial column layout (better for MCQs)
 *   output -  — write to stdout
 * ════════════════════════════════════════════════════════════ */
function extractTextPdf(string $filePath): string
{
    $binary = POPPLER_PATH;
    $isWin  = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

    /* Auto-detect binary on Windows if still default */
    if ($isWin && $binary === 'pdftotext') {
        $candidates = [
            'C:\\poppler\\bin\\pdftotext.exe',
            'C:\\Program Files\\poppler\\bin\\pdftotext.exe',
            'C:\\Program Files (x86)\\poppler\\bin\\pdftotext.exe',
        ];
        foreach ($candidates as $c) {
            if (file_exists($c)) { $binary = $c; break; }
        }
        /* Try PATH via 'where' command */
        if ($binary === 'pdftotext') {
            exec('where pdftotext 2>NUL', $wo, $wr);
            if ($wr === 0 && !empty($wo[0])) $binary = trim($wo[0]);
        }
    }

    /* Verify binary exists on Linux / Mac */
    if (!$isWin && $binary === 'pdftotext') {
        exec('which pdftotext 2>/dev/null', $wo, $wr);
        if ($wr !== 0) {
            throw new Exception(
                'pdftotext not found on this server. '
                . 'Install Poppler: "apt install poppler-utils" (Linux) or "brew install poppler" (Mac). '
                . 'On Windows: download from https://github.com/oschwartz10612/poppler-windows/releases'
            );
        }
    }

    $redirect = $isWin ? ' 2>NUL' : ' 2>/dev/null';
    $cmd = escapeshellarg($binary)
         . ' -nopgbrk -layout '
         . escapeshellarg($filePath)
         . ' - '
         . $redirect;

    $output = [];
    $ret    = 0;
    exec($cmd, $output, $ret);

    if ($ret !== 0) {
        throw new Exception(
            'pdftotext exited with error code ' . $ret . '. '
            . 'Ensure Poppler is correctly installed. '
            . 'Output: ' . implode(' ', array_slice($output, 0, 3))
        );
    }

    return implode("\n", $output);
}


/* ════════════════════════════════════════════════════════════
 * extractTextDocx()
 *
 * Pure PHP — uses built-in ZipArchive to read the DOCX
 * (which is a ZIP containing word/document.xml).
 * No external tools or libraries needed.
 * ════════════════════════════════════════════════════════════ */
function extractTextDocx(string $filePath): string
{
    if (!class_exists('ZipArchive')) {
        throw new Exception(
            'PHP ZipArchive extension is not enabled. '
            . 'Enable "extension=zip" in php.ini.'
        );
    }

    $zip = new ZipArchive();
    $res = $zip->open($filePath);

    if ($res !== true) {
        $zipErrors = [
            ZipArchive::ER_NOZIP  => 'Not a ZIP/DOCX file.',
            ZipArchive::ER_INCONS => 'Inconsistent ZIP archive.',
            ZipArchive::ER_MEMORY => 'Memory allocation failure.',
            ZipArchive::ER_NOENT  => 'File not found.',
            ZipArchive::ER_OPEN   => 'Cannot open file.',
            ZipArchive::ER_READ   => 'Read error.',
            ZipArchive::ER_SEEK   => 'Seek error.',
        ];
        throw new Exception('Could not open DOCX: ' . ($zipErrors[$res] ?? 'Unknown error ' . $res));
    }

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();

    if ($xml === false) {
        throw new Exception('Invalid DOCX file: word/document.xml not found inside the archive.');
    }

    /*
     * Convert XML to readable plain text:
     * 1. Replace paragraph/row closing tags with newlines
     * 2. Add space where runs (<w:r>) end so words don't merge
     * 3. Strip all remaining XML tags
     * 4. Decode XML entities
     */
    $xml = str_replace('</w:p>',  "\n",  $xml);  // paragraph break
    $xml = str_replace('</w:tr>', "\n",  $xml);  // table row break
    $xml = str_replace('</w:r>',  ' ',   $xml);  // run break (prevents word merging)
    $text = strip_tags($xml);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');

    // Split inline options onto their own lines.
    // Handles DOCX where all options are in one paragraph:
    // "A. PHP B) C C) Assembly D) COBOL" -> each option on its own line
    $text = preg_replace('/(?<!\n)\s+([B-Db-d][.)]\s)/u', "\n$1", $text);
    // Split inline Answer:/Ans:/Key: lines
    $text = preg_replace('/(?<!\n)\s+((?:Answer|Ans|Key)\s*[:\-]\s*[a-dA-D])/iu', "\n$1", $text);

    // Collapse 3+ consecutive blank lines to 2
    $text = preg_replace("/\n{3,}/", "\n\n", $text);

    return $text;
}


/* ════════════════════════════════════════════════════════════
 * parseQuestions()
 *
 * Parses numbered MCQ questions from plain text.
 *
 * Supported question formats:
 *   1. Question text        ← dot or closing paren after number
 *   1) Question text
 *
 * Supported option formats:
 *   a) Option text          ← letter a-d, dot or closing paren
 *   a. Option text
 *   A) Option text          ← uppercase also accepted
 *
 * Correct-answer markers (any of these work):
 *   b) Paris *              ← asterisk at end of line
 *   b) Paris [correct]      ← [correct] tag
 *   b) Paris CORRECT        ← word CORRECT at end
 *   Answer: b               ← standalone answer line after options
 *   Ans: b
 *   Key: b
 *
 * Additional supported lines:
 *   Explanation: ...        ← stored in explanation field
 *   Marks: 2                ← stored in marks field
 * ════════════════════════════════════════════════════════════ */
function parseQuestions(string $text): array
{
    $questions = [];

    // Normalise line endings
    $text  = str_replace(["\r\n", "\r"], "\n", $text);
    $lines = array_map('rtrim', explode("\n", $text));

    $current    = null;
    $ansHint    = null;

    $qPat      = '/^(\d{1,3})[.)]\s+(.+)$/u';
    $optPat    = '/^([a-dA-D])[.)]\s+(.+)$/u';
    $ansPat    = '/^(?:answer|ans|key)\s*[:\-]\s*([a-dA-D])/iu';
    $exPat     = '/^(?:explanation|note|hint)\s*[:\-]\s*(.+)/iu';
    $marksPat  = '/^(?:marks?|score)\s*[:\-]\s*(\d+(?:\.\d+)?)/iu';
    $correctRx = '/\s*(\*|\[correct\]|correct)\s*$/iu';

    $flush = function () use (&$questions, &$current, &$ansHint) {
        if (!$current || empty($current['options'])) return;

        // Apply answer hint if no option is marked correct yet
        $hasCorrect = !empty(array_filter($current['options'], fn($o) => $o['is_correct']));
        if (!$hasCorrect && $ansHint !== null) {
            $idx = ord(strtolower($ansHint)) - ord('a');
            if (isset($current['options'][$idx])) {
                $current['options'][$idx]['is_correct'] = true;
                $hasCorrect = true;
            }
        }
        // Graceful fallback: mark first option correct
        if (!$hasCorrect) {
            $current['options'][0]['is_correct'] = true;
        }

        $questions[] = $current;
        $current = null;
        $ansHint = null;
    };

    foreach ($lines as $line) {
        $s = trim($line);
        if ($s === '') continue;

        /* Answer hint line */
        if (preg_match($ansPat, $s, $m)) {
            $letter = strtolower($m[1]);
            if ($current !== null) {
                $idx = ord($letter) - ord('a');
                if (isset($current['options'][$idx])) {
                    // Clear any existing correct flags first
                    foreach ($current['options'] as &$o) $o['is_correct'] = false;
                    unset($o);
                    $current['options'][$idx]['is_correct'] = true;
                    $ansHint = null;
                } else {
                    $ansHint = $letter;
                }
            } else {
                $ansHint = $letter;
            }
            continue;
        }

        /* Explanation line */
        if (preg_match($exPat, $s, $m) && $current !== null) {
            $current['explanation'] = trim($m[1]);
            continue;
        }

        /* Marks line */
        if (preg_match($marksPat, $s, $m) && $current !== null) {
            $current['marks'] = (float) $m[1];
            continue;
        }

        /* New question line — flush previous */
        if (preg_match($qPat, $s, $m)) {
            $flush();
            $current = [
                'question_text'  => trim($m[2]),
                'question_type'  => 'mcq',
                'marks'          => 1,
                'negative_marks' => 0,
                'explanation'    => '',
                'options'        => [],
            ];
            continue;
        }

        /* Option line */
        if (preg_match($optPat, $s, $m) && $current !== null) {
            $optText   = trim($m[2]);
            $isCorrect = false;

            // Check if this "option" line actually contains multiple inline options
            // e.g. "PHP B) C C) Assembly D) COBOL Answer: A) PHP"
            // Split it on B) C) D) boundaries (letter followed by ) or .)
            $inlineSplit = preg_split('/\s+(?=[B-Db-d][.)]\s)/u', $optText);
            if (count($inlineSplit) > 1) {
                // First part belongs to option A (current match)
                $firstText  = trim($inlineSplit[0]);
                $isCorrect0 = false;
                if (preg_match($correctRx, $firstText, $mc)) {
                    $isCorrect0 = true;
                    $firstText  = trim(preg_replace($correctRx, '', $firstText));
                }
                // Strip trailing Answer:/Ans: from first part
                $firstText = preg_replace('/\s+(?:Answer|Ans|Key)\s*[:\-]\s*[a-dA-D]\)?\s*$/iu', '', $firstText);
                $current['options'][] = ['option_text' => trim($firstText), 'is_correct' => $isCorrect0];

                // Remaining parts are options B, C, D...
                for ($si = 1; $si < count($inlineSplit); $si++) {
                    // Strip leading letter+delimiter
                    $part = preg_replace('/^[a-dA-D][.)]\s*/u', '', trim($inlineSplit[$si]));
                    // Strip trailing Answer:/Ans: annotation
                    $part = preg_replace('/\s+(?:Answer|Ans|Key)\s*[:\-]\s*[a-dA-D]\)?\s*$/iu', '', $part);
                    $isCorrectN = false;
                    if (preg_match($correctRx, $part, $mc)) {
                        $isCorrectN = true;
                        $part = trim(preg_replace($correctRx, '', $part));
                    }
                    $current['options'][] = ['option_text' => trim($part), 'is_correct' => $isCorrectN];
                }

                // Now look for Answer: marker anywhere in original line
                if (preg_match('/(?:Answer|Ans|Key)\s*[:\-]\s*([a-dA-D])/iu', $optText, $am)) {
                    $ansIdx = ord(strtolower($am[1])) - ord('a');
                    foreach ($current['options'] as &$op) $op['is_correct'] = false;
                    unset($op);
                    if (isset($current['options'][$ansIdx])) {
                        $current['options'][$ansIdx]['is_correct'] = true;
                    }
                }
                continue;
            }

            if (preg_match($correctRx, $optText, $mc)) {
                $isCorrect = true;
                $optText   = trim(preg_replace($correctRx, '', $optText));
            }

            $current['options'][] = [
                'option_text' => $optText,
                'is_correct'  => $isCorrect,
            ];
            continue;
        }

        /* Multi-line question text (continuation before first option) */
        if ($current !== null && empty($current['options'])) {
            $current['question_text'] .= ' ' . $s;
        }
    }

    $flush(); // final flush

    /* ── Post-processing ── */
    foreach ($questions as &$q) {
        $q['question_text'] = trim($q['question_text']);
        foreach ($q['options'] as &$o) {
            $o['option_text'] = trim($o['option_text']);
        }
        unset($o);

        // Auto-detect true/false questions
        if (count($q['options']) === 2) {
            $texts = array_map(fn($o) => strtolower(trim($o['option_text'])), $q['options']);
            if (in_array('true', $texts) && in_array('false', $texts)) {
                $q['question_type'] = 'true_false';
            }
        }
    }
    unset($q);

    return $questions;
}