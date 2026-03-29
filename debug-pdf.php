<?php
/**
 * TEMPORARY DEBUG SCRIPT
 * Place in your project root (same folder as self-assessment.php)
 * Access via: http://localhost/pta/debug-pdf.php
 * DELETE THIS FILE after debugging is done!
 */

// ── Point this to your uploaded PDF ──────────────────────────
// After uploading a PDF, check uploads/self_assessment_pdfs/ and paste the filename below.
// Or just hardcode the path to your test PDF.
$pdfPath = isset($_GET['pdf'])
    ? 'uploads/self_assessment_pdfs/' . basename($_GET['pdf'])
    : null;

if (!$pdfPath || !file_exists($pdfPath)) {
    // List available PDFs so you can pick one
    $files = glob('uploads/self_assessment_pdfs/*.pdf') ?: [];
    echo '<h2>Available PDFs</h2><ul>';
    foreach ($files as $f) {
        $name = basename($f);
        echo '<li><a href="?pdf=' . urlencode($name) . '">' . htmlspecialchars($name) . '</a></li>';
    }
    echo '</ul>';
    echo '<p>Or pass ?pdf=filename.pdf in the URL.</p>';
    exit;
}

echo '<h2>Debugging: ' . htmlspecialchars($pdfPath) . '</h2>';

// ── Copy the extractPdfText function from self-assessment.php ──
function extractPdfText(string $path): string
{
    $which = trim((string)shell_exec('which pdftotext 2>/dev/null'));
    if ($which !== '') {
        $esc = escapeshellarg($path);
        $out = (string)shell_exec("pdftotext -layout $esc - 2>/dev/null");
        if (trim($out) !== '') { echo '<p style="color:green">✅ pdftotext (layout mode) succeeded.</p>'; return $out; }
        $out = (string)shell_exec("pdftotext $esc - 2>/dev/null");
        if (trim($out) !== '') { echo '<p style="color:green">✅ pdftotext (plain mode) succeeded.</p>'; return $out; }
        echo '<p style="color:orange">⚠️ pdftotext found but returned empty output.</p>';
    } else {
        echo '<p style="color:orange">⚠️ pdftotext not found — using pure-PHP fallback.</p>';
    }

    $raw = file_get_contents($path);
    $decompressed = [];
    preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $sm);
    foreach ($sm[1] as $stream) {
        $dec = @gzuncompress($stream);
        if ($dec === false) $dec = @gzinflate($stream);
        $decompressed[] = ($dec !== false) ? $dec : $stream;
    }
    echo '<p>Streams found: ' . count($decompressed) . '</p>';

    $cmap = [];
    foreach ($decompressed as $src) {
        if (strpos($src, 'beginbfchar') === false && strpos($src, 'beginbfrange') === false) continue;
        preg_match_all('/beginbfchar(.*?)endbfchar/s', $src, $bfcharSecs);
        foreach ($bfcharSecs[1] as $sec) {
            preg_match_all('/<([0-9A-Fa-f]+)>\s+<([0-9A-Fa-f]+)>/', $sec, $bfc);
            foreach ($bfc[1] as $k => $cidHex) {
                $cid = hexdec($cidHex); $uni = hexdec($bfc[2][$k]);
                if ($uni >= 32) $cmap[$cid] = mb_chr($uni, 'UTF-8');
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
    echo '<p>CMap entries: ' . count($cmap) . '</p>';

    $decodeCid = function(string $hexStr) use ($cmap): string {
        $out = ''; $step = (strlen($hexStr) % 4 === 0 && strlen($hexStr) >= 4) ? 4 : 2;
        for ($i = 0; $i < strlen($hexStr); $i += $step) {
            $cid = hexdec(substr($hexStr, $i, $step));
            if (isset($cmap[$cid])) { $out .= $cmap[$cid]; }
            else { $uni = $cid + 29; $out .= ($uni >= 32 && $uni <= 126) ? chr($uni) : ''; }
        }
        return $out;
    };

    $text = '';
    foreach ($decompressed as $src) {
        if (strpos($src, 'BT') === false) continue;
        preg_match_all('/BT(.*?)ET/s', $src, $bt);
        foreach ($bt[1] as $block) {
            $lineText = '';
            preg_match_all('/\((?:[^)(\\\\]|\\\\.)*\)\s*Tj/s', $block, $tj);
            foreach ($tj[0] as $t) {
                $inner = preg_replace(['/\)\s*Tj$/', '/^\(/'], ['', ''], $t);
                $inner = str_replace(['\\)', '\\('], [')', '('], $inner);
                $inner = preg_replace('/\\\\[0-9]{1,3}/', '', $inner);
                $inner = preg_replace('/\\\\[nrtf\\\\]/', ' ', $inner);
                $lineText .= trim($inner) . ' ';
            }
            preg_match_all('/<([0-9A-Fa-f]+)>\s*Tj/', $block, $hexTj);
            foreach ($hexTj[1] as $h) $lineText .= $decodeCid($h);
            preg_match_all('/\[(.*?)\]\s*TJ/s', $block, $tJ);
            foreach ($tJ[1] as $t) {
                preg_match_all('/\((?:[^)(\\\\]|\\\\.)*\)/', $t, $parts);
                foreach ($parts[0] as $p) {
                    $inner = preg_replace(['/^\(/', '/\)$/'], '', $p);
                    $inner = str_replace(['\\)', '\\('], [')', '('], $inner);
                    $inner = preg_replace('/\\\\[0-9]{1,3}/', '', $inner);
                    $inner = preg_replace('/\\\\[nrtf\\\\]/', ' ', $inner);
                    $lineText .= $inner;
                }
                preg_match_all('/<([0-9A-Fa-f]+)>/', $t, $hexParts);
                foreach ($hexParts[1] as $h) $lineText .= $decodeCid($h);
            }
            $lineText = trim($lineText);
            if ($lineText !== '') $text .= $lineText . "\n";
        }
    }

    $lines = explode("\n", $text); $merged = []; $i = 0;
    while ($i < count($lines)) {
        $l = trim($lines[$i]);
        if (strlen($l) === 1 && ctype_alpha($l)) {
            $word = $l; $j = $i + 1;
            while ($j < count($lines) && strlen(trim($lines[$j])) === 1 && ctype_alpha(trim($lines[$j]))) { $word .= trim($lines[$j]); $j++; }
            $merged[] = strlen($word) >= 2 ? $word : $l; $i = $j;
        } else { $merged[] = $l; $i++; }
    }
    $text = implode("\n", $merged);

    if (strlen(trim($text)) < 20) {
        echo '<p style="color:red">⚠️ BT/ET extraction failed, falling back to raw paren grab.</p>';
        preg_match_all('/\(([^\)]{3,})\)/', $raw, $m);
        $text = implode("\n", $m[1]);
    }

    return $text;
}

$text = extractPdfText($pdfPath);

echo '<h3>Extracted Text (' . strlen($text) . ' chars)</h3>';
echo '<pre style="background:#f5f5f5;padding:16px;border:1px solid #ccc;max-height:500px;overflow:auto;font-size:12px;">';
echo htmlspecialchars(substr($text, 0, 3000));
if (strlen($text) > 3000) echo "\n... (truncated, showing first 3000 chars)";
echo '</pre>';

echo '<h3>First 30 lines</h3><ol style="font-family:monospace;font-size:13px;">';
$lines = explode("\n", $text);
foreach (array_slice($lines, 0, 30) as $l) {
    echo '<li>' . htmlspecialchars($l) . '</li>';
}
echo '</ol>';
