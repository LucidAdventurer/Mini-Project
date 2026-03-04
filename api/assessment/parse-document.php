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

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

validateSession($conn, 'teacher');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

header('Content-Type: application/json');

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
    echo json_encode(['success' => false, 'error' => 'Your PDF is too large to process. Try converting your questions to a DOCX file instead — it handles large documents much better.']);
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
// FUNCTION: extractTextFromDOCX
// Reads word/document.xml from the ZIP — preserves paragraph
// structure by replacing </w:p> with newlines before stripping tags.
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

    $xml = preg_replace('/<\/w:p>/',     "\n", $xml);
    $xml = preg_replace('/<w:br[^>]*\/>/', "\n", $xml);
    $xml = strip_tags($xml);
    $xml = html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $xml = preg_replace('/[ \t]+/',  ' ',    $xml);
    $xml = preg_replace('/\n{3,}/', "\n\n", $xml);
    return trim($xml);
}


// ============================================================
// FUNCTION: extractTextFromPDF
//
// Three-strategy extraction designed to handle Google Docs PDFs:
//
// Strategy 1 — Structured stream walk
//   Decompresses each stream (Google Docs always uses FlateDecode/zlib).
//   Walks PDF operators in order. Uses Td/TD/Tm/T* positioning
//   operators to detect line breaks — this correctly reconstructs
//   lines that Google Docs fragments across many small Tj calls.
//
// Strategy 2 — Simple string concatenation
//   If strategy 1 yields no questions after parsing, falls back to
//   naively joining all Tj/TJ strings from decompressed streams.
//   Less structured but catches edge cases.
//
// Strategy 3 — Raw byte scan
//   Scans raw PDF bytes for parenthesised strings as a last resort.
// ============================================================
function extractTextFromPDF(string $path): string {
    $content = @file_get_contents($path);
    if ($content === false) return '';

    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $content, $m);
    $rawStreams = $m[1] ?? [];

    // ── Pass 1: build ToUnicode CMap from any CMap streams ──
    // Google Docs PDFs use CIDFonts where text operators contain hex glyph IDs
    // like <0044> instead of (string) literals. The ToUnicode CMap maps
    // glyph ID → Unicode codepoint. Without this, all text is invisible to us.
    $cmap = buildToUnicodeCMap($rawStreams);

    // ── Pass 2: extract lines from content streams ──
    // Strategy A: CID hex strings decoded via CMap (Google Docs PDF)
    // Strategy B: parenthesis string literals decoded directly (other PDFs)
    // Both strategies walk Td/Tm/T*/ET operators to reconstruct line breaks.
    $lines = [];
    foreach ($rawStreams as $raw) {
        $stream = tryDecompress($raw);
        if ($stream === null) continue;

        // Skip font/image binary streams (CMap streams start with /CIDInit)
        if (strpos($stream, '/CIDInit') !== false) continue;
        if (strpos($stream, 'begincmap') !== false) continue;

        $streamLines = extractLinesFromStream($stream, $cmap);
        foreach ($streamLines as $line) {
            $line = trim($line);
            if ($line !== '') $lines[] = $line;
        }
    }

    $text = implode("\n", $lines);

    // ── Fallback: raw parenthesis scan if nothing found ──
    if (trim($text) === '') {
        $text = pdfRawFallback($content);
    }

    $text = preg_replace('/[ \t]+/',  ' ',    $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}


// ============================================================
// FUNCTION: buildToUnicodeCMap
//
// Parses ToUnicode CMap streams to build a glyph-ID → character map.
// This is required for Google Docs PDFs (and most modern PDFs) which
// use CIDFont encoding. Text operators contain hex glyph IDs like
// <0044><0048><0051> instead of readable (string) literals.
//
// CMap format:
//   beginbfchar
//   <GLYPH_ID> <UNICODE_CODEPOINT>
//   endbfchar
//
//   beginbfrange
//   <START_GID> <END_GID> <BASE_UNICODE>
//   endbfrange
// ============================================================
function buildToUnicodeCMap(array $rawStreams): array {
    $cmap = [];

    foreach ($rawStreams as $raw) {
        $stream = tryDecompress($raw);
        if ($stream === null) continue;
        if (strpos($stream, 'begincmap') === false) continue;

        // Parse bfchar entries: <XXXX> <YYYY>
        if (preg_match('/beginbfchar(.*?)endbfchar/s', $stream, $bfcharMatch)) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/', $bfcharMatch[1], $pairs);
            foreach ($pairs[1] as $idx => $gidHex) {
                $gid       = hexdec($gidHex);
                $uniHex    = $pairs[2][$idx];
                // Unicode value — convert to UTF-8 character
                $codepoint = hexdec($uniHex);
                if ($codepoint >= 0x20 && $codepoint <= 0x7E) {
                    $cmap[$gid] = chr($codepoint);
                } elseif ($codepoint > 0x7E) {
                    // Multi-byte Unicode — encode as UTF-8
                    $cmap[$gid] = mb_chr($codepoint, 'UTF-8');
                }
            }
        }

        // Parse bfrange entries: <START> <END> <BASE>
        if (preg_match('/beginbfrange(.*?)endbfrange/s', $stream, $bfrangeMatch)) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>/',
                           $bfrangeMatch[1], $ranges);
            foreach ($ranges[1] as $idx => $startHex) {
                $start    = hexdec($startHex);
                $end      = hexdec($ranges[2][$idx]);
                $base     = hexdec($ranges[3][$idx]);
                for ($gid = $start; $gid <= $end; $gid++) {
                    $codepoint = $base + ($gid - $start);
                    if ($codepoint >= 0x20 && $codepoint <= 0x7E) {
                        $cmap[$gid] = chr($codepoint);
                    } elseif ($codepoint > 0x7E) {
                        $cmap[$gid] = mb_chr($codepoint, 'UTF-8');
                    }
                }
            }
        }

        // Do NOT break — continue merging all CMap streams.
        // Multi-font documents (e.g. bold headings use a second font)
        // embed one CMap per font. We must merge them all or characters
        // from secondary fonts will be silently dropped.
    }

    return $cmap;
}


// ============================================================
// FUNCTION: tryDecompress
//
// Handles zlib (FlateDecode) compressed PDF streams.
// Google Docs always compresses with zlib — magic bytes 0x78 0x9C.
// Returns decompressed string, plain-text stream, or null for binary.
//
// CHANGE: Aborts if decompressed output exceeds 5MB.
// This prevents zip-bomb style PDFs from spiking CPU/memory.
// The 10MB raw upload limit is enforced before this is ever called.
// ============================================================
function tryDecompress(string $stream): ?string {
    // 5MB decompressed cap — generous for any text-based MCQ PDF
    define('MAX_UPLOAD_BYTES',       2 * 1024 * 1024);  // 2MB raw file
    define('MAX_DECOMPRESSED_BYTES', 5 * 1024 * 1024);  // 5MB per stream
    if (strlen($stream) === 0) return null;

    $b0 = ord($stream[0]);
    $b1 = strlen($stream) > 1 ? ord($stream[1]) : 0;

    if ($b0 === 0x78 && in_array($b1, [0x01, 0x5E, 0x9C, 0xDA], true)) {
        $d = @gzuncompress($stream);
        if ($d !== false) {
            if (strlen($d) > MAX_DECOMPRESSED_BYTES) {
                error_log("parse-document: stream decompressed to " . strlen($d) . " bytes — aborted (zip-bomb guard)");
                return null;
            }
            return $d;
        }
        $d = @gzinflate($stream);
        if ($d !== false) {
            if (strlen($d) > MAX_DECOMPRESSED_BYTES) {
                error_log("parse-document: stream decompressed to " . strlen($d) . " bytes — aborted (zip-bomb guard)");
                return null;
            }
            return $d;
        }
        $d = @gzinflate(substr($stream, 2));
        if ($d !== false) {
            if (strlen($d) > MAX_DECOMPRESSED_BYTES) {
                error_log("parse-document: stream decompressed to " . strlen($d) . " bytes — aborted (zip-bomb guard)");
                return null;
            }
            return $d;
        }
        return null;
    }

    $sample    = substr($stream, 0, 64);
    $printable = preg_match_all('/[\x09\x0A\x0D\x20-\x7E]/', $sample);
    $ratio     = strlen($sample) > 0 ? $printable / strlen($sample) : 0;
    return $ratio >= 0.7 ? $stream : null;
}


// ============================================================
// FUNCTION: extractLinesFromStream
//
// Extracts text lines from a PDF content stream.
// Handles both encoding types:
//   A) CID hex strings:     <0044> Tj  — decoded via ToUnicode CMap
//   B) Parenthesis strings: (Hello) Tj — decoded directly
//
// Line grouping — two strategies, applied together:
//
//   Primary (Google Docs): the cm operator sets an absolute page Y
//   coordinate before each visual line's BT blocks. We use that Y
//   string as the grouping key.
//
//   Fallback (other PDFs): if no cm is seen, we track Y via Td/TD
//   (relative move) and Tm (absolute matrix). A non-zero Y delta
//   flushes the current line and starts a new group.
// ============================================================
function extractLinesFromStream(string $stream, array $cmap): array {

    // Group text by cm Y coordinate (Google Docs layout engine)
    // and by Td/Tm Y tracking (other PDFs)
    $lineGroups = [];   // string_y_key => accumulated_text
    $lineOrder  = [];   // preserves insertion order (= reading order)

    $contextY    = '0'; // current Y as string key (avoids float key issues)
    $hasCm       = false; // did this stream use cm for layout?
    $tdY         = 0.0;   // accumulated Y from Td for fallback tracking
    $blockChars  = '';
    $inText      = false;

    $lines = explode("\n", $stream);

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') continue;

        // ── cm operator: sets absolute page Y for the coming BT blocks ──
        // Format: "a b c d tx ty cm"  — ty is the 6th number.
        // Google Docs emits one cm per visual line of text.
        // Regex is intentionally unanchored (\b not ^$) so leading
        // whitespace or preceding tokens on the same line don't break it.
        if (preg_match('/(-?[\d.]+\s+){5}(-?[\d.]+)\s+cm\b/', $line, $cmm)) {
            $contextY = $cmm[2]; // ty component — unique per visual line
            $hasCm    = true;
            continue;
        }

        if ($line === 'BT') { $inText = true;  $blockChars = ''; continue; }
        if ($line === 'ET') {
            if ($blockChars !== '') {
                if (!isset($lineGroups[$contextY])) {
                    $lineGroups[$contextY] = '';
                    $lineOrder[]           = $contextY;
                }
                $lineGroups[$contextY] .= $blockChars;
                $blockChars = '';
            }
            $inText = false;
            continue;
        }

        if (!$inText) continue;

        // ── Fallback: Td/TD Y-tracking for PDFs that don't use cm per line ──
        // Td format (on one line): "tx ty Td" or "tx ty TD"
        // A non-zero ty means the cursor moved to a new visual line.
        // We only apply this when cm hasn't been seen (hasCm=false) to
        // avoid double-counting on Google Docs streams.
        if (!$hasCm && preg_match('/(-?[\d.]+)\s+(-?[\d.]+)\s+TD?\s*$/', $line, $tdm)) {
            $ty = (float)$tdm[2];
            if (abs($ty) > 0.5) {
                // Flush any pending chars under the old Y
                if ($blockChars !== '') {
                    if (!isset($lineGroups[$contextY])) {
                        $lineGroups[$contextY] = '';
                        $lineOrder[]           = $contextY;
                    }
                    $lineGroups[$contextY] .= $blockChars;
                    $blockChars = '';
                }
                $tdY     += $ty;
                $contextY = (string)round($tdY, 3);
            }
            // Fall through — the same line may also contain a Tj
        }

        // ── Fallback: Tm absolute matrix for PDFs that don't use cm ──
        // Format: "a b c d tx ty Tm" — ty is the absolute Y position.
        if (!$hasCm && preg_match('/(-?[\d.]+\s+){5}(-?[\d.]+)\s+Tm\b/', $line, $tmm)) {
            $newY = (float)$tmm[2];
            if ($contextY !== (string)round($newY, 3)) {
                if ($blockChars !== '') {
                    if (!isset($lineGroups[$contextY])) {
                        $lineGroups[$contextY] = '';
                        $lineOrder[]           = $contextY;
                    }
                    $lineGroups[$contextY] .= $blockChars;
                    $blockChars = '';
                }
                $tdY      = $newY;
                $contextY = (string)round($newY, 3);
            }
            // Fall through — same line may also contain a Tj
        }

        // ── CID hex string Tj: "tx ty Td <XXXX> Tj" or just "<XXXX> Tj" ──
        if (!empty($cmap) && preg_match('/<([0-9A-Fa-f]+)>\s*Tj\s*$/', $line, $hexm)) {
            $hex  = $hexm[1];
            $text = '';
            for ($j = 0; $j < strlen($hex); $j += 4) {
                $chunk = substr($hex, $j, 4);
                if (strlen($chunk) === 4) {
                    $gid = hexdec($chunk);
                    $text .= $cmap[$gid] ?? ''; // includes space (0x20)
                }
            }
            $blockChars .= $text;
            continue;
        }

        // ── Parenthesis string Tj: "(string) Tj" ──
        if (preg_match('/^\((.*)\)\s*Tj\s*$/', $line, $parm)) {
            $blockChars .= decodePdfStringFull($parm[1]);
            continue;
        }

        // ── TJ array: "[...] TJ" — mix of hex/paren strings and kerning numbers ──
        if (preg_match('/^\[(.+)\]\s*TJ\s*$/', $line, $tjm)) {
            $inner = $tjm[1];
            if (!empty($cmap)) {
                preg_match_all('/<([0-9A-Fa-f]+)>/', $inner, $hexParts);
                foreach ($hexParts[1] as $hex) {
                    for ($j = 0; $j < strlen($hex); $j += 4) {
                        $chunk = substr($hex, $j, 4);
                        if (strlen($chunk) === 4) $blockChars .= $cmap[hexdec($chunk)] ?? '';
                    }
                }
            }
            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $inner, $parParts);
            foreach ($parParts[1] as $str) $blockChars .= decodePdfStringFull($str);
            continue;
        }

        // ── T* or ' operator: explicit next line ──
        if ($line === "T*" || $line === "'") {
            if ($blockChars !== '') {
                if (!isset($lineGroups[$contextY])) {
                    $lineGroups[$contextY] = '';
                    $lineOrder[]           = $contextY;
                }
                $lineGroups[$contextY] .= $blockChars;
                $blockChars = '';
            }
            // Increment pseudo-Y so next text gets a new group
            $contextY = (string)((float)$contextY + 0.001);
            continue;
        }
    }

    // Build output in insertion order (= reading order from stream)
    $result = [];
    foreach ($lineOrder as $yKey) {
        $text = trim($lineGroups[$yKey]);
        if ($text !== '') $result[] = $text;
    }
    return $result;
}


// ============================================================
// FUNCTION: pdfRawFallback
// Last-resort: scan raw PDF bytes for parenthesised strings.
// ============================================================
function pdfRawFallback(string $content): string {
    $text = '';
    preg_match_all('/\(([^)]{2,})\)/', $content, $matches);
    foreach ($matches[1] as $raw) {
        $decoded = decodePdfStringFull($raw);
        if (preg_match('/^D:\d{8}/', $decoded)) continue;
        if (strlen($decoded) < 3) continue;
        $printable = preg_match_all('/[\x20-\x7E]/', $decoded);
        if (strlen($decoded) > 0 && $printable / strlen($decoded) >= 0.6) {
            $text .= $decoded . "\n";
        }
    }
    return $text;
}


// ============================================================
// FUNCTION: decodePdfStringFull
// Full PDF string decoder with proper octal escape support.
// ============================================================
function decodePdfStringFull(string $str): string {
    $result = '';
    $len    = strlen($str);
    $i      = 0;
    while ($i < $len) {
        if ($str[$i] === '\\' && $i + 1 < $len) {
            $next = $str[$i + 1];
            if ($next >= '0' && $next <= '7') {
                $oct = '';
                $j   = $i + 1;
                while ($j < $len && $j < $i + 4 && $str[$j] >= '0' && $str[$j] <= '7') $oct .= $str[$j++];
                $c = chr(octdec($oct));
                if (ord($c) >= 0x20 && ord($c) <= 0x7E) $result .= $c;
                $i = $j;
                continue;
            }
            $map = ['n'=>"\n",'r'=>"\r",'t'=>"\t",'b'=>"\x08",'f'=>"\x0C",'('=>'(',')'=>')','\\'=>'\\'];
            $result .= $map[$next] ?? $next;
            $i += 2;
            continue;
        }
        $result .= $str[$i++];
    }
    return $result;
}


// ============================================================
// FUNCTION: parseQuestions
//
// Parses raw text into MCQ question objects.
// Tolerates spacing anomalies introduced by Google Docs PDF export.
//
// Supported question formats:
//   1. Question    1) Question    Q1. Question
//
// Supported option formats — all tolerated:
//   a) Option   a. Option   A) Option
//   a ) Option  (a) Option  [a] Option  a  )  Option
//
// Answer line (optional):
//   Answer: b   Ans: b   Key: b   Correct: b
// ============================================================
function parseQuestions(string $text): array {
    $questions = [];

    // Normalise line endings
    $text = str_replace(["\r\n", "\r"], "\n", $text);

    $lines = explode("\n", $text);

    // Per-line normalisation
    $lines = array_map(function(string $line): string {
        $line = preg_replace('/\h+/', ' ', $line);
        $line = preg_replace('/^(\s*[\[(]?\s*[a-dA-D])\s+([).\]])\s*/', '$1$2 ', $line);
        return trim($line);
    }, $lines);
    $lines = array_values(array_filter($lines, fn($l) => $l !== ''));

    // ── Reassemble word-per-line fragments (Google Docs PDF output) ──
    // Google Docs PDFs produce one word per line from the CID stream extractor.
    // Join consecutive lines into the previous line unless the line starts with
    // a recognised anchor: question number, option letter, or answer marker.
    $reAnchor = '/^(?:'
        . '(?:Q(?:uestion)?\s*)?\d+\s*[.):\s]'   // question number
        . '|[a-dA-D]\s*[).]\s'                    // option letter with text after
        . '|[a-dA-D][).]\s*$'                     // bare option label "a)"
        . '|(?:answer|ans|key|correct)\s*[:\s.]'  // answer line
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

    // Reassemble orphan option labels: ["a)", "Berlin"] → ["a) Berlin"]
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

    $reQuestion = '/^(?:Q(?:uestion)?\s*)?(\d+)\s*[.):\s]\s*(.{5,})/i';
    $reOption   = '/^\s*[\[(]?\s*([a-dA-D])\s*[\].):\s]\s*(.+)/';
    $reAnswer   = '/^(?:answer|ans|key|correct)\s*[:\s.]+\s*([a-dA-D])/i';
    $reNextQ    = '/^(?:Q(?:uestion)?\s*)?\d+\s*[.):\s]/i';

    while ($i < $totalLines) {
        $line = $lines[$i];

        if (!preg_match($reQuestion, $line, $qMatch)) { $i++; continue; }

        $questionText  = trim($qMatch[2]);
        $options       = [];
        $correctAnswer = null;
        $i++;
        $unrecognised  = 0;

        while ($i < $totalLines && count($options) < 4) {
            $optLine = $lines[$i];

            if (preg_match($reOption, $optLine, $optMatch)) {
                $options[strtolower($optMatch[1])] = trim($optMatch[2]);
                $unrecognised = 0;
                $i++;
            } elseif (preg_match($reAnswer, $optLine, $ansMatch)) {
                $correctAnswer = strtolower($ansMatch[1]);
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
            if (preg_match($reAnswer, $lines[$i], $ansMatch)) {
                $correctAnswer = strtolower($ansMatch[1]);
                $i++;
            }
        }

        if (count($options) < 2) continue;

        $questions[] = [
            'id'            => $qIndex++,
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