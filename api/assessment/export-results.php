<?php
ob_start(); // Capture any stray output from config/db-guard
// ============================================================
// api/assessment/export-results.php
// Exports assessment results in multiple formats.
//
// GET params:
//   assessment_id  int     (required)
//   format         string  csv | excel | pdf | print
//   filter         string  all | pass | fail  (optional)
//
// SCHEMA: Live DB columns only. Status: 'submitted','timeout'.
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

$currentUser  = validateSession($conn, 'teacher');
$teacherId    = (int) $currentUser['user_id'];
$assessmentId = (int)($_GET['assessment_id'] ?? 0);
$format       = strtolower(trim($_GET['format'] ?? 'csv'));
$filter       = strtolower(trim($_GET['filter'] ?? 'all'));

if ($assessmentId <= 0)                              { http_response_code(400); echo 'Invalid ID.'; exit; }
if (!in_array($format, ['csv','excel','pdf','print'],true)) { http_response_code(400); echo 'Bad format.'; exit; }
if (!in_array($filter, ['all','pass','fail'],true))  { $filter = 'all'; }

// ── Ownership check ──
$asmRes = safePreparedQuery($conn,
    "SELECT assessment_id,title,total_marks,passing_marks,max_attempts,category,difficulty
     FROM assessments WHERE assessment_id=? AND created_by=?",
    "ii", [$assessmentId, $teacherId]);

if (!$asmRes['success'] || !$asmRes['result'] || $asmRes['result']->num_rows === 0) {
    http_response_code(403); echo 'Access denied.'; exit;
}
$asm = $asmRes['result']->fetch_assoc();
$asmRes['result']->free();

$title        = $asm['title'];
$totalMarks   = (int)$asm['total_marks'];
$passingMarks = (int)$asm['passing_marks'];
$maxAttempts  = (int)($asm['max_attempts'] ?? 1);
$passPct      = $totalMarks > 0 ? round($passingMarks / $totalMarks * 100, 2) : 0;
$isMulti      = $maxAttempts > 1;

// ── Fetch attempts ──
$raw = $conn->query(
    "SELECT aa.attempt_id,aa.user_id,aa.attempt_number,aa.score,aa.percentage,
            aa.submitted_at,aa.status,
            u.full_name AS student_name,u.email,u.department,u.registration_number
     FROM assessment_attempts aa
     LEFT JOIN users u ON u.user_id=aa.user_id
     WHERE aa.assessment_id=$assessmentId AND aa.status IN ('submitted','timeout')
     ORDER BY aa.user_id ASC,aa.attempt_number ASC");
if (!$raw) { http_response_code(500); echo 'Query failed.'; exit; }

$studentMap = [];
while ($row = $raw->fetch_assoc()) {
    $uid = $row['user_id'] !== null ? (int)$row['user_id'] : ('guest_'.$row['attempt_id']);
    if (!isset($studentMap[$uid])) {
        $studentMap[$uid] = [
            'student_name' => $row['student_name'] ?? 'Unknown',
            'email'        => $row['email'] ?? '',
            'department'   => $row['department'] ?? '',
            'reg_no'       => $row['registration_number'] ?? '',
            'attempts'     => [], 'best_pct' => -1, 'best_idx' => 0,
        ];
    }
    $pct = (float)($row['percentage'] ?? 0);
    $studentMap[$uid]['attempts'][] = [
        'attempt_number' => (int)($row['attempt_number'] ?? 1),
        'score'          => round((float)($row['score'] ?? 0), 2),
        'percentage'     => round($pct, 2),
        'submitted_at'   => $row['submitted_at'],
        'status'         => $row['status'],
    ];
    if ($pct > $studentMap[$uid]['best_pct']) {
        $studentMap[$uid]['best_pct'] = $pct;
        $studentMap[$uid]['best_idx'] = count($studentMap[$uid]['attempts']) - 1;
    }
}
$raw->free();

uasort($studentMap, fn($a,$b) => $b['best_pct'] <=> $a['best_pct']);

$rows = []; $totalPct = 0; $passCount = 0; $rank = 0;
foreach ($studentMap as $s) {
    $best = $s['attempts'][$s['best_idx']];
    $pct  = $best['percentage'];
    $isPassed = $pct >= $passPct;
    if ($filter === 'pass' && !$isPassed) continue;
    if ($filter === 'fail' && $isPassed)  continue;
    $rank++; $totalPct += $pct;
    if ($isPassed) $passCount++;
    $rows[] = [
        'rank'           => $rank,
        'student_name'   => $s['student_name'],
        'email'          => $s['email'],
        'department'     => $s['department'],
        'reg_no'         => $s['reg_no'],
        'best_score'     => $best['score'],
        'best_pct'       => $pct,
        'total_attempts' => count($s['attempts']),
        'submitted_at'   => $best['submitted_at'],
        'passed'         => $isPassed,
        'attempts'       => $s['attempts'],
    ];
}
$count    = count($rows);
$avgScore = $count > 0 ? round($totalPct / $count, 1) : 0;
$passRate = $count > 0 ? round($passCount / $count * 100) : 0;

function fmtDate(?string $dt): string  { return $dt ? date('d M Y, H:i', strtotime($dt)) : '—'; }
function fmtShort(?string $dt): string { return $dt ? date('d M Y',      strtotime($dt)) : '—'; }
function xe(string $s): string { return htmlspecialchars($s, ENT_XML1, 'UTF-8'); }

$exportDate = date('d M Y');
$safeTitle  = preg_replace('/[^a-zA-Z0-9_\- ]/', '', $title);
$fileName   = 'Results_' . str_replace(' ', '_', $safeTitle) . '_' . date('Y-m-d');

// ═══════════════════════════════════════════════════════
// FORMAT: CSV
// ═══════════════════════════════════════════════════════
if ($format === 'csv') {
    ob_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$fileName.'.csv"');
    header('Cache-Control: no-cache');
    $out = fopen('php://output','w');
    fputs($out,"\xEF\xBB\xBF");
    fputcsv($out,['Assessment',$title]);
    fputcsv($out,['Total Marks',$totalMarks,'Passing Marks',$passingMarks,'Pass %',$passPct.'%']);
    fputcsv($out,['Exported',$exportDate,'Filter',ucfirst($filter)]);
    fputcsv($out,['Students',$count,'Avg Score',$avgScore.'%','Pass Rate',$passRate.'%']);
    fputcsv($out,[]);
    if ($isMulti) {
        fputcsv($out,['#','Student Name','Email','Department','Reg No','Attempt #','Score %','Marks','Result','Submitted']);
        foreach ($rows as $r) {
            foreach ($r['attempts'] as $i => $att) {
                $ap = $att['percentage'] >= $passPct;
                fputcsv($out,[$i===0?$r['rank']:'',$i===0?$r['student_name']:'',$i===0?$r['email']:'',$i===0?$r['department']:'',$i===0?$r['reg_no']:'','Attempt '.$att['attempt_number'],$att['percentage'].'%',$att['score'].' / '.$totalMarks,$ap?'Pass':'Fail',fmtDate($att['submitted_at'])]);
            }
        }
    } else {
        fputcsv($out,['#','Student Name','Email','Department','Reg No','Score %','Marks','Result','Submitted']);
        foreach ($rows as $r) {
            fputcsv($out,[$r['rank'],$r['student_name'],$r['email'],$r['department'],$r['reg_no'],$r['best_pct'].'%',$r['best_score'].' / '.$totalMarks,$r['passed']?'Pass':'Fail',fmtDate($r['submitted_at'])]);
        }
    }
    fclose($out); exit;
}

// ═══════════════════════════════════════════════════════
// FORMAT: EXCEL — real .xlsx via SpreadsheetML + ZIP
// ═══════════════════════════════════════════════════════
if ($format === 'excel') {

    ob_clean(); // Discard any stray output before binary xlsx stream

    // ── Helper: escape for XML ──
    function xlEsc(mixed $v): string {
        return htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    // ── Shared strings table ──
    $sst    = [];   // string => index
    $sstArr = [];
    function ssIdx(string $s): int {
        global $sst, $sstArr;
        if (!isset($sst[$s])) { $sst[$s] = count($sstArr); $sstArr[] = $s; }
        return $sst[$s];
    }

    // ── Column letter helper ──
    function colLetter(int $col): string {
        $l = '';
        while ($col >= 0) { $l = chr($col % 26 + 65) . $l; $col = intdiv($col, 26) - 1; }
        return $l;
    }

    // ── Cell builders ──
    // s=shared-string  n=number  b=boolean-like number
    function cellS(int $row, int $col, string $val, int $styleIdx = 0): string {
        $ref = colLetter($col) . $row;
        $si  = ssIdx($val);
        return "<c r=\"$ref\" t=\"s\" s=\"$styleIdx\"><v>$si</v></c>";
    }
    function cellN(int $row, int $col, float $val, int $styleIdx = 0): string {
        $ref = colLetter($col) . $row;
        return "<c r=\"$ref\" s=\"$styleIdx\"><v>$val</v></c>";
    }

    // ──────────────────────────────────────────
    // STYLES  (xl/styles.xml)
    // Index map:
    //   0  = default (no special style)
    //   1  = header row  (bold, white text, purple bg, center)
    //   2  = info label  (bold, light purple bg)
    //   3  = info value  (light purple bg)
    //   4  = pass row    (bold, green bg)
    //   5  = fail row    (normal, pink bg)
    //   6  = number cell pass (bold, green bg, 1 decimal)
    //   7  = number cell fail (normal, pink bg, 1 decimal)
    //   8  = rank cell   (bold, centered)
    //   9  = attempt badge (bold, violet bg, white text)
    //  10  = best attempt (bold, amber bg)
    //  11  = date cell (small, grey)
    // ──────────────────────────────────────────
    $stylesXml = <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="2">
    <numFmt numFmtId="164" formatCode="0.0&quot;%&quot;"/>
    <numFmt numFmtId="165" formatCode="d\ mmm\ yyyy"/>
  </numFmts>
  <fonts count="6">
    <font><sz val="11"/><name val="Times New Roman"/></font>
    <font><sz val="11"/><b/><name val="Times New Roman"/><color rgb="FFFFFFFF"/></font>
    <font><sz val="11"/><b/><name val="Times New Roman"/></font>
    <font><sz val="10"/><name val="Times New Roman"/><color rgb="FF6B7280"/></font>
    <font><sz val="11"/><b/><name val="Times New Roman"/><color rgb="FFFFFFFF"/></font>
    <font><sz val="13"/><b/><name val="Times New Roman"/></font>
  </fonts>
  <fills count="10">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF4C1D95"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEDE9F6"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFD1FAE5"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFF0F0"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF7C3AED"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFEF9C3"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF0FDF4"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFFF1F2"/></patternFill></fill>
  </fills>
  <borders count="3">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFD8B4FE"/></left>
      <right style="thin"><color rgb="FFD8B4FE"/></right>
      <top style="thin"><color rgb="FFD8B4FE"/></top>
      <bottom style="thin"><color rgb="FFD8B4FE"/></bottom>
    </border>
    <border>
      <left style="medium"><color rgb="FF4C1D95"/></left>
      <right style="medium"><color rgb="FF4C1D95"/></right>
      <top style="medium"><color rgb="FF4C1D95"/></top>
      <bottom style="medium"><color rgb="FF4C1D95"/></bottom>
    </border>
  </borders>
  <cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>
  <cellXfs count="12">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0"><alignment wrapText="1" vertical="center"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="2" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0"><alignment vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="3" borderId="1" xfId="0"><alignment vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="4" borderId="1" xfId="0"><alignment vertical="center"/></xf>
    <xf numFmtId="0" fontId="0" fillId="5" borderId="1" xfId="0"><alignment vertical="center"/></xf>
    <xf numFmtId="164" fontId="2" fillId="4" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="164" fontId="0" fillId="5" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="5" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="4" fillId="6" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
    <xf numFmtId="0" fontId="2" fillId="7" borderId="1" xfId="0"><alignment vertical="center"/></xf>
    <xf numFmtId="165" fontId="3" fillId="0" borderId="1" xfId="0"><alignment horizontal="center" vertical="center"/></xf>
  </cellXfs>
</styleSheet>
XML;

    // ── Build worksheet rows ──
    // Style indices:
    // 0=default 1=header 2=info-bold 3=info 4=pass 5=fail 6=pct-pass 7=pct-fail 8=rank 9=att-badge 10=best-att 11=date
    $sheetRows  = '';
    $merges     = [];
    $rowNum     = 1;

    // Title row (merged A1:I1)
    $titleCols = $isMulti ? 10 : 9;
    $sheetRows .= "<row r=\"$rowNum\" ht=\"24\" customHeight=\"1\">";
    $sheetRows .= cellS($rowNum, 0, $title . ' — Student Results', 1);
    for ($c = 1; $c < $titleCols; $c++) $sheetRows .= "<c r=\"".colLetter($c).$rowNum."\" s=\"1\"/>";
    $sheetRows .= "</row>";
    $merges[]   = "A$rowNum:".colLetter($titleCols-1).$rowNum;
    $rowNum++;

    // Info rows
    $infoPairs = [
        ['Total Marks', $totalMarks,   'Passing Marks', $passingMarks, 'Pass %', $passPct.'%'],
        ['Exported',    $exportDate,   'Filter',        ucfirst($filter), '', ''],
        ['Students',    $count,        'Avg Score',     $avgScore.'%', 'Pass Rate', $passRate.'%'],
    ];
    foreach ($infoPairs as $ip) {
        $sheetRows .= "<row r=\"$rowNum\" ht=\"18\" customHeight=\"1\">";
        $c = 0;
        for ($i = 0; $i < 6; $i += 2) {
            $sheetRows .= cellS($rowNum, $c,   (string)$ip[$i],   2); $c++;
            $sheetRows .= cellS($rowNum, $c,   (string)$ip[$i+1], 3); $c++;
        }
        for (; $c < $titleCols; $c++) $sheetRows .= "<c r=\"".colLetter($c).$rowNum."\" s=\"3\"/>";
        $sheetRows .= "</row>";
        $rowNum++;
    }

    // Spacer
    $sheetRows .= "<row r=\"$rowNum\"/>";
    $rowNum++;

    $dataStartRow = $rowNum;

    // Header row
    $sheetRows .= "<row r=\"$rowNum\" ht=\"20\" customHeight=\"1\">";
    if ($isMulti) {
        $hdrs = ['#','Student Name','Email','Department','Reg No','Attempt','Score %','Marks','Result','Submitted'];
    } else {
        $hdrs = ['#','Student Name','Email','Department','Reg No','Score %','Marks','Result','Submitted'];
    }
    foreach ($hdrs as $ci => $h) $sheetRows .= cellS($rowNum, $ci, $h, 1);
    $sheetRows .= "</row>";
    $rowNum++;

    // Data rows
    foreach ($rows as $r) {
        $isPassed = $r['passed'];
        $baseStyle = $isPassed ? 4 : 5;
        $pctStyle  = $isPassed ? 6 : 7;

        if ($isMulti) {
            $bestPct    = $r['best_pct'];
            $attCount   = count($r['attempts']);
            $firstRowOfStudent = $rowNum;

            foreach ($r['attempts'] as $ai => $att) {
                $attPass   = $att['percentage'] >= $passPct;
                $attBase   = $attPass ? 4 : 5;
                $attPct    = $attPass ? 6 : 7;
                $isBest    = ($att['percentage'] === $bestPct);
                $attStyle  = $isBest ? 10 : 9; // amber=best, violet=normal

                $sheetRows .= "<row r=\"$rowNum\" ht=\"18\" customHeight=\"1\">";
                if ($ai === 0) {
                    $sheetRows .= cellS($rowNum, 0, (string)$r['rank'], 8);
                    $sheetRows .= cellS($rowNum, 1, $r['student_name'], $attBase);
                    $sheetRows .= cellS($rowNum, 2, $r['email'],        $attBase);
                    $sheetRows .= cellS($rowNum, 3, $r['department'],   $attBase);
                    $sheetRows .= cellS($rowNum, 4, $r['reg_no'],       $attBase);
                } else {
                    for ($c = 0; $c <= 4; $c++) $sheetRows .= "<c r=\"".colLetter($c).$rowNum."\" s=\"$attBase\"/>";
                }
                $attLabel = 'Attempt ' . $att['attempt_number'] . ($isBest ? ' ★ Best' : '');
                $sheetRows .= cellS($rowNum, 5, $attLabel,             $attStyle);
                $sheetRows .= cellN($rowNum, 6, $att['percentage'],    $attPct);
                $sheetRows .= cellS($rowNum, 7, $att['score'].' / '.$totalMarks, $attBase);
                $sheetRows .= cellS($rowNum, 8, $attPass ? 'Pass' : 'Fail', $attBase);
                $sheetRows .= cellS($rowNum, 9, fmtShort($att['submitted_at']), 11);
                $sheetRows .= "</row>";
                $rowNum++;
            }
        } else {
            $sheetRows .= "<row r=\"$rowNum\" ht=\"18\" customHeight=\"1\">";
            $sheetRows .= cellS($rowNum, 0, (string)$r['rank'],         8);
            $sheetRows .= cellS($rowNum, 1, $r['student_name'],         $baseStyle);
            $sheetRows .= cellS($rowNum, 2, $r['email'],                $baseStyle);
            $sheetRows .= cellS($rowNum, 3, $r['department'],           $baseStyle);
            $sheetRows .= cellS($rowNum, 4, $r['reg_no'],               $baseStyle);
            $sheetRows .= cellN($rowNum, 5, $r['best_pct'],             $pctStyle);
            $sheetRows .= cellS($rowNum, 6, $r['best_score'].' / '.$totalMarks, $baseStyle);
            $sheetRows .= cellS($rowNum, 7, $r['passed'] ? 'Pass' : 'Fail', $baseStyle);
            $sheetRows .= cellS($rowNum, 8, fmtShort($r['submitted_at']), 11);
            $sheetRows .= "</row>";
            $rowNum++;
        }
    }

    $dataEndRow    = $rowNum - 1;
    $chartStartRow = $rowNum + 1;   // 1 blank row gap

    // ── Column widths ──
    $colsXml = $isMulti
        ? '<col min="1" max="1" width="5"  customWidth="1"/>
           <col min="2" max="2" width="22" customWidth="1"/>
           <col min="3" max="3" width="26" customWidth="1"/>
           <col min="4" max="4" width="16" customWidth="1"/>
           <col min="5" max="5" width="14" customWidth="1"/>
           <col min="6" max="6" width="18" customWidth="1"/>
           <col min="7" max="7" width="10" customWidth="1"/>
           <col min="8" max="8" width="12" customWidth="1"/>
           <col min="9" max="9" width="10" customWidth="1"/>
           <col min="10" max="10" width="14" customWidth="1"/>'
        : '<col min="1" max="1" width="5"  customWidth="1"/>
           <col min="2" max="2" width="22" customWidth="1"/>
           <col min="3" max="3" width="26" customWidth="1"/>
           <col min="4" max="4" width="16" customWidth="1"/>
           <col min="5" max="5" width="14" customWidth="1"/>
           <col min="6" max="6" width="10" customWidth="1"/>
           <col min="7" max="7" width="12" customWidth="1"/>
           <col min="8" max="8" width="10" customWidth="1"/>
           <col min="9" max="9" width="14" customWidth="1"/>';

    // ── Shared strings XML ──
    $sstXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($sstArr).'" uniqueCount="'.count($sstArr).'">';
    foreach ($sstArr as $sv) {
        $sstXml .= '<si><t xml:space="preserve">'.xlEsc($sv).'</t></si>';
    }
    $sstXml .= '</sst>';

    // ── Merge cells XML ──
    $mergeXml = '';
    if (!empty($merges)) {
        $mergeXml = '<mergeCells count="'.count($merges).'">';
        foreach ($merges as $m) $mergeXml .= "<mergeCell ref=\"$m\"/>";
        $mergeXml .= '</mergeCells>';
    }

    // ── Build bar chart (DrawingML embedded chart XML) ──
    // Chart plots student name (col B) vs score % (col F for single, col G for multi)
    // We reference the sheet data directly.
    $scoreColLetter = $isMulti ? 'G' : 'F';
    $nameColLetter  = 'B';

    $chartXml = <<<CHARTXML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"
              xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
              xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <c:chart>
    <c:title>
      <c:tx><c:rich><a:bodyPr/><a:lstStyle/>
        <a:p><a:r><a:t>Student Score Analysis</a:t></a:r></a:p>
      </c:rich></c:tx><c:overlay val="0"/>
    </c:title>
    <c:autoTitleDeleted val="0"/>
    <c:plotArea>
      <c:layout/>
      <c:barChart>
        <c:barDir val="col"/>
        <c:grouping val="clustered"/>
        <c:varyColors val="0"/>
        <c:ser>
          <c:idx val="0"/>
          <c:order val="0"/>
          <c:tx>
            <c:strRef>
              <c:f>Sheet1!\${scoreColLetter}\${dataStartRow}:\${scoreColLetter}\${dataStartRow}</c:f>
              <c:strCache><c:ptCount val="1"/><c:pt idx="0"><c:v>Score %</c:v></c:pt></c:strCache>
            </c:strRef>
          </c:tx>
          <c:spPr>
            <a:solidFill><a:srgbClr val="7C3AED"/></a:solidFill>
            <a:ln><a:solidFill><a:srgbClr val="4C1D95"/></a:solidFill></a:ln>
          </c:spPr>
          <c:cat>
            <c:strRef>
              <c:f>Sheet1!\${nameColLetter}\${dataStartRow}:\${nameColLetter}\${dataEndRow}</c:f>
              <c:strCache><c:ptCount val="{$count}"/>
CHARTXML;

    // Inline student name cache
    foreach ($rows as $ri => $r) {
        $chartXml .= '<c:pt idx="'.$ri.'"><c:v>'.xlEsc($r['student_name']).'</c:v></c:pt>';
    }

    $chartXml .= <<<CHARTXML2
              </c:strCache>
            </c:strRef>
          </c:cat>
          <c:val>
            <c:numRef>
              <c:f>Sheet1!\${scoreColLetter}\${dataStartRow}:\${scoreColLetter}\${dataEndRow}</c:f>
              <c:numCache><c:formatCode>0.0</c:formatCode><c:ptCount val="{$count}"/>
CHARTXML2;

    foreach ($rows as $ri => $r) {
        $chartXml .= '<c:pt idx="'.$ri.'"><c:v>'.$r['best_pct'].'</c:v></c:pt>';
    }

    // Pass mark reference line via scatter overlay
    $chartXml .= <<<CHARTXML3
              </c:numCache>
            </c:numRef>
          </c:val>
          <c:smooth val="0"/>
        </c:ser>
        <c:axId val="1"/>
        <c:axId val="2"/>
      </c:barChart>
      <c:lineChart>
        <c:barDir val="col"/>
        <c:grouping val="standard"/>
        <c:varyColors val="0"/>
        <c:ser>
          <c:idx val="1"/>
          <c:order val="1"/>
          <c:tx><c:strRef><c:f>Sheet1!A1</c:f><c:strCache><c:ptCount val="1"/><c:pt idx="0"><c:v>Pass Mark</c:v></c:pt></c:strCache></c:strRef></c:tx>
          <c:spPr>
            <a:ln w="25400"><a:solidFill><a:srgbClr val="E11D48"/></a:solidFill><a:prstDash val="dash"/></a:ln>
          </c:spPr>
          <c:marker><c:symbol val="none"/></c:marker>
          <c:val>
            <c:numRef>
              <c:f>Sheet1!A1</c:f>
              <c:numCache><c:formatCode>0.0</c:formatCode><c:ptCount val="{$count}"/>
CHARTXML3;

    for ($ri = 0; $ri < $count; $ri++) {
        $chartXml .= '<c:pt idx="'.$ri.'"><c:v>'.$passPct.'</c:v></c:pt>';
    }

    $chartXml .= <<<CHARTXML4
              </c:numCache>
            </c:numRef>
          </c:val>
          <c:smooth val="0"/>
        </c:ser>
        <c:axId val="1"/>
        <c:axId val="2"/>
      </c:lineChart>
      <c:catAx>
        <c:axId val="1"/>
        <c:scaling><c:orientation val="minMax"/></c:scaling>
        <c:delete val="0"/>
        <c:axPos val="b"/>
        <c:crossAx val="2"/>
        <c:tickLblPos val="nextTo"/>
        <c:spPr><a:ln><a:solidFill><a:srgbClr val="E2D9F3"/></a:solidFill></a:ln></c:spPr>
        <c:txPr><a:bodyPr rot="-2700000"/><a:lstStyle/>
          <a:p><a:pPr><a:defRPr b="0" sz="800" latin="Times New Roman"/></a:pPr></a:p>
        </c:txPr>
      </c:catAx>
      <c:valAx>
        <c:axId val="2"/>
        <c:scaling><c:orientation val="minMax"/><c:max val="100"/></c:scaling>
        <c:delete val="0"/>
        <c:axPos val="l"/>
        <c:title>
          <c:tx><c:rich><a:bodyPr/><a:lstStyle/>
            <a:p><a:r><a:rPr lang="en-US" b="0"/><a:t>Score (%)</a:t></a:r></a:p>
          </c:rich></c:tx><c:overlay val="0"/>
        </c:title>
        <c:crossAx val="1"/>
        <c:spPr><a:ln><a:solidFill><a:srgbClr val="E2D9F3"/></a:solidFill></a:ln></c:spPr>
        <c:txPr><a:bodyPr/><a:lstStyle/>
          <a:p><a:pPr><a:defRPr b="0" sz="800" latin="Times New Roman"/></a:pPr></a:p>
        </c:txPr>
      </c:valAx>
    </c:plotArea>
    <c:legend>
      <c:legendPos val="b"/>
      <c:spPr><a:solidFill><a:srgbClr val="F7F5FB"/></a:solidFill></c:spPr>
    </c:legend>
    <c:plotVisOnly val="1"/>
    <c:dispBlanksAs val="gap"/>
  </c:chart>
  <c:spPr>
    <a:solidFill><a:srgbClr val="FFFFFF"/></a:solidFill>
    <a:ln><a:solidFill><a:srgbClr val="E2D9F3"/></a:solidFill></a:ln>
  </c:spPr>
</c:chartSpace>
CHARTXML4;

    // ── Drawing XML (positions chart on the sheet) ──
    $drawingXml = <<<XML
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing"
          xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"
          xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart">
  <xdr:twoCellAnchor moveWithCells="1">
    <xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>{$chartStartRow}</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from>
    <xdr:to><xdr:col>8</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>{$rowNum}</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to>
    <xdr:graphicFrame macro="">
      <xdr:nvGraphicFramePr>
        <xdr:cNvPr id="2" name="Chart 1"/>
        <xdr:cNvGraphicFramePr/>
      </xdr:nvGraphicFramePr>
      <xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm>
      <a:graphic>
        <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart">
          <c:chart r:id="rId1"/>
        </a:graphicData>
      </a:graphic>
    </xdr:graphicFrame>
    <xdr:clientData/>
  </xdr:twoCellAnchor>
</xdr:wsDr>
XML;

    // ── worksheet XML ──
    $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
    . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheetViews><sheetView workbookViewId="0" showGridLines="1">'
    . '<selection activeCell="A1" sqref="A1"/>'
    . '</sheetView></sheetViews>'
    . '<sheetFormatPr defaultRowHeight="15" customHeight="1"/>'
    . '<cols>' . $colsXml . '</cols>'
    . '<sheetData>' . $sheetRows . '</sheetData>'
    . $mergeXml
    . '<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>'
    . '<drawing r:id="rId1"/>'
    . '</worksheet>';

    // ── ZIP it all into .xlsx ──
    function zipEntry(string $filename, string $data): string {
        $crc    = crc32($data);
        $uLen   = strlen($data);
        $comp   = gzdeflate($data, 6);
        $cLen   = strlen($comp);
        $fnLen  = strlen($filename);
        $lh = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, 0, 0, $crc, $cLen, $uLen, $fnLen, 0)
            . $filename . $comp;
        $cd = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, 0, 0, $crc, $cLen, $uLen, $fnLen, 0, 0, 0, 0, 0, 0)
            . $filename;
        return $lh . '|' . $cd;
    }

    $files = [
        '_rels/.rels'                       => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml'                   => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels'        => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/><Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/worksheets/sheet1.xml'          => $sheetXml,
        'xl/worksheets/_rels/sheet1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>',
        'xl/drawings/drawing1.xml'          => $drawingXml,
        'xl/drawings/_rels/drawing1.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart1.xml"/></Relationships>',
        'xl/charts/chart1.xml'              => $chartXml,
        'xl/sharedStrings.xml'              => $sstXml,
        'xl/styles.xml'                     => $stylesXml,
        '[Content_Types].xml'               => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/><Override PartName="/xl/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/></Types>',
    ];

    // Build ZIP binary
    $localHeaders = '';
    $centralDir   = '';
    $offset       = 0;

    foreach ($files as $fname => $fdata) {
        $crc   = crc32($fdata);
        $uLen  = strlen($fdata);
        $comp  = gzdeflate($fdata, 6);
        $cLen  = strlen($comp);
        $fnLen = strlen($fname);
        $time  = 0; $date = 0;

        $lh = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 8, $time, $date, $crc, $cLen, $uLen, $fnLen, 0) . $fname . $comp;
        $cd = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 8, $time, $date, $crc, $cLen, $uLen, $fnLen, 0, 0, 0, 0, 0, $offset) . $fname;
        $localHeaders .= $lh;
        $centralDir   .= $cd;
        $offset        += strlen($lh);
    }

    $cdLen  = strlen($centralDir);
    $eocdr  = pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), $cdLen, $offset, 0);
    $xlsx   = $localHeaders . $centralDir . $eocdr;

    ob_clean(); // Critical: no stray bytes before binary xlsx stream
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$fileName.'.xlsx"');
    header('Content-Length: '.strlen($xlsx));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    echo $xlsx;
    exit;
}

// ═══════════════════════════════════════════════════════
// FORMAT: PDF / PRINT  (Times New Roman + Chart.js bar)
// ═══════════════════════════════════════════════════════
ob_clean(); // Discard stray output, emit clean HTML
$autoPrint   = ($format === 'print');
$filterLabel = match($filter) {
    'pass'  => ' — Passed Students Only',
    'fail'  => ' — Failed Students Only',
    default => '',
};

// Prepare chart data for JS
$chartLabels = json_encode(array_column($rows, 'student_name'));
$chartScores = json_encode(array_column($rows, 'best_pct'));
$chartColors = json_encode(array_map(fn($r) => $r['passed'] ? '#059669' : '#e11d48', $rows));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — Results<?= htmlspecialchars($filterLabel) ?></title>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<style>
*, *::before, *::after { margin:0; padding:0; box-sizing:border-box; }
:root {
  --violet:#7c3aed; --violet2:#4c1d95; --orchid:#c084fc;
  --emerald:#059669; --rose:#e11d48;
  --ink:#1a1425; --ink2:#4b4565; --ink3:#8b7fa8;
  --surface:#f7f5fb; --border:#e2d9f3;
}
body {
  font-family: 'Times New Roman', Times, serif;
  font-size: 12px; color: var(--ink); background: #fff;
}

/* ── Print controls (screen only) ── */
.print-controls {
  position:fixed; top:0; left:0; right:0; z-index:1000;
  background:#1a1425; color:white;
  padding:10px 24px; display:flex;
  align-items:center; justify-content:space-between; gap:16px;
  box-shadow:0 2px 12px rgba(0,0,0,.3);
}
.ctrl-title { font-weight:700; font-size:13px; }
.ctrl-hint  { font-size:11px; opacity:.6; }
.btn-print-now {
  display:flex; align-items:center; gap:8px;
  background:var(--violet); color:white; border:none;
  border-radius:8px; padding:9px 18px; font-size:13px;
  font-weight:700; cursor:pointer;
  font-family:'Times New Roman',Times,serif;
}
.btn-close-preview {
  background:rgba(255,255,255,.1); color:white;
  border:1px solid rgba(255,255,255,.15); border-radius:8px;
  padding:9px 16px; font-size:13px; cursor:pointer;
  font-family:'Times New Roman',Times,serif;
}

/* ── Page header ── */
.page-header {
  background:linear-gradient(135deg,var(--violet2) 0%,var(--violet) 60%,var(--orchid) 100%);
  color:white; padding:28px 32px 24px;
}
.page-header .brand {
  font-size:11px; font-weight:700; letter-spacing:.12em;
  text-transform:uppercase; opacity:.7; margin-bottom:8px;
}
.page-header h1 {
  font-size:24px; font-weight:700; line-height:1.2; margin-bottom:4px;
}
.page-header .subtitle { font-size:12px; opacity:.75; margin-bottom:16px; }

.stat-row { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-top:16px; }
.stat-card {
  background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2);
  border-radius:10px; padding:12px 14px; text-align:center;
}
.stat-value { font-size:22px; font-weight:700; line-height:1; margin-bottom:4px; }
.stat-label { font-size:10px; opacity:.7; text-transform:uppercase; letter-spacing:.06em; }

.filter-badge {
  display:inline-flex; align-items:center; gap:5px; margin-top:12px;
  padding:4px 12px; background:rgba(255,255,255,.15);
  border:1px solid rgba(255,255,255,.25); border-radius:20px;
  font-size:10.5px; font-weight:700; letter-spacing:.04em;
}

/* ── Chart section ── */
.chart-section {
  margin:24px 32px 0;
  background:var(--surface); border:1px solid var(--border);
  border-radius:14px; padding:20px 24px;
}
.chart-title {
  font-size:14px; font-weight:700; color:var(--ink);
  margin-bottom:4px; border-bottom:2px solid var(--border);
  padding-bottom:8px; margin-bottom:14px;
}
.chart-legend {
  display:flex; gap:20px; margin-top:10px;
  font-size:11px; color:var(--ink2);
}
.chart-legend span { display:flex; align-items:center; gap:6px; }
.legend-dot { width:12px; height:12px; border-radius:3px; flex-shrink:0; }

/* ── Table ── */
.table-wrap { padding:20px 32px 24px; }
table.results { width:100%; border-collapse:collapse; font-size:11.5px; }
table.results thead tr {
  background:linear-gradient(90deg,var(--violet2),var(--violet)); color:white;
}
table.results thead th {
  padding:9px 10px; text-align:left; font-weight:700;
  font-size:11px; text-transform:uppercase; letter-spacing:.04em;
  font-family:'Times New Roman',Times,serif;
}
table.results tbody tr { border-bottom:1px solid var(--border); }
table.results tbody tr:nth-child(even) { background:var(--surface); }
table.results tbody tr.pass-row { background:#f0fdf4; }
table.results tbody tr.fail-row { background:#fff1f2; }
table.results tbody td { padding:8px 10px; vertical-align:middle; }

.student-name { font-weight:700; font-size:12px; }
.student-email { font-size:10.5px; color:var(--ink3); margin-top:1px; }
.student-reg   { font-size:10px; color:var(--ink3); }

.score-pct { font-weight:700; font-size:14px; }
.score-bar-wrap { margin-top:3px; height:4px; background:#e2d9f3; border-radius:4px; overflow:hidden; }
.score-bar-fill { height:100%; border-radius:4px; }
.score-bar-fill.pass { background:var(--emerald); }
.score-bar-fill.fail { background:var(--rose); }

.badge {
  display:inline-flex; align-items:center; gap:4px; padding:3px 10px;
  border-radius:20px; font-size:10.5px; font-weight:700;
}
.badge.pass { background:#d1fae5; color:#065f46; }
.badge.fail { background:#ffe4e6; color:#9f1239; }

.rank-cell { font-size:13px; font-weight:700; text-align:center; }
.rank-cell.gold   { color:#d97706; }
.rank-cell.silver { color:#6b7280; }
.rank-cell.bronze { color:#92400e; }

.att-cell { font-size:11px; color:var(--violet); font-weight:700; }
.att-cell.best { color:#b45309; }

/* ── Footer ── */
.page-footer {
  padding:16px 32px; border-top:2px solid var(--border);
  display:flex; justify-content:space-between; align-items:center;
  font-size:10.5px; color:var(--ink3);
}
.page-footer strong { color:var(--ink); }

.empty-state { text-align:center; padding:48px; color:var(--ink3); }

@media print {
  .print-controls { display:none !important; }
  body { padding-top:0 !important; }
  .page-header,table.results thead tr,.badge,.score-bar-fill,.chart-section {
    -webkit-print-color-adjust:exact; print-color-adjust:exact;
  }
  tr { page-break-inside:avoid; }
  .chart-section { page-break-inside:avoid; }
}
@media screen { body { padding-top:52px; } }
</style>
</head>
<body>

<!-- ── Screen controls ── -->
<div class="print-controls">
  <div>
    <div class="ctrl-title">📄 <?= htmlspecialchars($title) ?> — Export Preview</div>
    <div class="ctrl-hint">Click "Print / Save as PDF" to download via browser print dialog.</div>
  </div>
  <div style="display:flex;gap:10px;">
    <button class="btn-close-preview" onclick="window.close()">✕ Close</button>
    <button class="btn-print-now" onclick="window.print()">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <polyline points="6 9 6 2 18 2 18 9"/>
        <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
        <rect x="6" y="14" width="12" height="8"/>
      </svg>
      Print / Save as PDF
    </button>
  </div>
</div>

<!-- ── Header ── -->
<div class="page-header">
  <div class="brand">PREPAURA · Placement Training Platform</div>
  <h1><?= htmlspecialchars($title) ?></h1>
  <div class="subtitle">
    <?= htmlspecialchars($asm['category'] ?? 'General') ?> ·
    <?= ucfirst($asm['difficulty'] ?? 'medium') ?> Difficulty ·
    Exported on <?= $exportDate ?>
  </div>
  <div class="stat-row">
    <div class="stat-card"><div class="stat-value"><?= $count ?></div><div class="stat-label">Students</div></div>
    <div class="stat-card"><div class="stat-value"><?= $avgScore ?>%</div><div class="stat-label">Avg Score</div></div>
    <div class="stat-card"><div class="stat-value"><?= $passRate ?>%</div><div class="stat-label">Pass Rate</div></div>
    <div class="stat-card"><div class="stat-value"><?= $totalMarks ?></div><div class="stat-label">Total Marks</div></div>
    <div class="stat-card"><div class="stat-value"><?= $passingMarks ?></div><div class="stat-label">Pass Marks</div></div>
  </div>
  <?php if ($filter !== 'all'): ?>
  <div class="filter-badge">
    <?= $filter === 'pass' ? '✅ Passing Students Only' : '❌ Failed Students Only' ?>
  </div>
  <?php endif; ?>
</div>

<!-- ── Bar Chart ── -->
<?php if ($count > 0): ?>
<div class="chart-section">
  <div class="chart-title">📊 Student Score Analysis — <?= htmlspecialchars($title) ?></div>
  <canvas id="scoreChart" height="<?= min(120, max(60, $count * 4)) ?>"></canvas>
  <div class="chart-legend">
    <span><span class="legend-dot" style="background:#059669;"></span> Passed (≥<?= $passPct ?>%)</span>
    <span><span class="legend-dot" style="background:#e11d48;"></span> Failed (&lt;<?= $passPct ?>%)</span>
    <span><span class="legend-dot" style="background:#f59e0b;border:2px dashed #d97706;"></span> Pass Mark (<?= $passPct ?>%)</span>
  </div>
</div>
<script>
(function() {
  const labels = <?= $chartLabels ?>;
  const scores = <?= $chartScores ?>;
  const colors = <?= $chartColors ?>;
  const passMark = <?= $passPct ?>;

  const ctx = document.getElementById('scoreChart').getContext('2d');
  new Chart(ctx, {
    data: {
      labels: labels,
      datasets: [
        {
          type: 'bar',
          label: 'Score %',
          data: scores,
          backgroundColor: colors.map(c => c + 'CC'),
          borderColor: colors,
          borderWidth: 1.5,
          borderRadius: 4,
          order: 2,
        },
        {
          type: 'line',
          label: 'Pass Mark (' + passMark + '%)',
          data: Array(labels.length).fill(passMark),
          borderColor: '#f59e0b',
          borderWidth: 2,
          borderDash: [6, 4],
          pointRadius: 0,
          fill: false,
          order: 1,
          tension: 0,
        }
      ]
    },
    options: {
      responsive: true,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          display: true,
          position: 'bottom',
          labels: { font: { family: "'Times New Roman', Times, serif", size: 11 } }
        },
        tooltip: {
          callbacks: {
            label: ctx => ctx.dataset.label + ': ' + ctx.parsed.y + '%'
          }
        }
      },
      scales: {
        x: {
          ticks: {
            font: { family: "'Times New Roman', Times, serif", size: 10 },
            maxRotation: 45, minRotation: 30,
          },
          grid: { color: '#e2d9f3' }
        },
        y: {
          min: 0, max: 100,
          title: {
            display: true, text: 'Score (%)',
            font: { family: "'Times New Roman', Times, serif", size: 11 }
          },
          ticks: {
            font: { family: "'Times New Roman', Times, serif", size: 10 },
            callback: v => v + '%'
          },
          grid: { color: '#e2d9f3' }
        }
      }
    }
  });
})();
</script>
<?php endif; ?>

<!-- ── Table ── -->
<div class="table-wrap">
<?php if (empty($rows)): ?>
  <div class="empty-state">
    <div style="font-size:40px;margin-bottom:12px;">📭</div>
    <strong>No results match the current filter.</strong>
  </div>

<?php elseif ($isMulti): ?>
  <table class="results">
    <thead><tr>
      <th style="width:36px;">#</th><th>Student</th><th>Department</th>
      <th>Attempt</th><th>Score %</th><th>Marks</th><th>Result</th><th>Submitted</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $bestPct  = $r['best_pct'];
        $attCount = count($r['attempts']);
        $rowCls   = $r['passed'] ? 'pass-row' : 'fail-row';
        $rankCls  = match($r['rank']) {1=>'gold',2=>'silver',3=>'bronze',default=>''};
    ?>
      <?php foreach ($r['attempts'] as $idx => $att):
          $attPass = $att['percentage'] >= $passPct;
          $isBest  = ($att['percentage'] === $bestPct);
      ?>
      <tr class="<?= $rowCls ?>">
        <?php if ($idx === 0): ?>
        <td class="rank-cell <?= $rankCls ?>" rowspan="<?= $attCount ?>"><?= $r['rank'] ?></td>
        <td rowspan="<?= $attCount ?>">
          <div class="student-name"><?= htmlspecialchars($r['student_name']) ?></div>
          <div class="student-email"><?= htmlspecialchars($r['email']) ?></div>
          <?php if ($r['reg_no']): ?><div class="student-reg"><?= htmlspecialchars($r['reg_no']) ?></div><?php endif; ?>
        </td>
        <td rowspan="<?= $attCount ?>"><?= htmlspecialchars($r['department'] ?: '—') ?></td>
        <?php endif; ?>
        <td class="att-cell<?= $isBest ? ' best' : '' ?>">Attempt <?= $att['attempt_number'] ?><?= $isBest ? ' ★' : '' ?></td>
        <td>
          <div class="score-pct" style="color:<?= $attPass?'var(--emerald)':'var(--rose)' ?>;"><?= $att['percentage'] ?>%</div>
          <div class="score-bar-wrap"><div class="score-bar-fill <?= $attPass?'pass':'fail' ?>" style="width:<?= min(100,$att['percentage']) ?>%"></div></div>
        </td>
        <td><?= $att['score'] ?> / <?= $totalMarks ?></td>
        <td><span class="badge <?= $attPass?'pass':'fail' ?>"><?= $attPass?'✅ Pass':'❌ Fail' ?></span></td>
        <td style="color:var(--ink3);font-size:10.5px;"><?= fmtShort($att['submitted_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>

<?php else: ?>
  <table class="results">
    <thead><tr>
      <th style="width:36px;">#</th><th>Student</th><th>Department</th>
      <th>Score %</th><th>Marks</th><th>Result</th><th>Submitted</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $pass    = $r['passed'];
        $rankCls = match($r['rank']) {1=>'gold',2=>'silver',3=>'bronze',default=>''};
        $rowCls  = $pass ? 'pass-row' : 'fail-row';
    ?>
      <tr class="<?= $rowCls ?>">
        <td class="rank-cell <?= $rankCls ?>"><?= $r['rank'] ?></td>
        <td>
          <div class="student-name"><?= htmlspecialchars($r['student_name']) ?></div>
          <div class="student-email"><?= htmlspecialchars($r['email']) ?></div>
          <?php if ($r['reg_no']): ?><div class="student-reg"><?= htmlspecialchars($r['reg_no']) ?></div><?php endif; ?>
        </td>
        <td><?= htmlspecialchars($r['department'] ?: '—') ?></td>
        <td>
          <div class="score-pct" style="color:<?= $pass?'var(--emerald)':'var(--rose)' ?>;"><?= $r['best_pct'] ?>%</div>
          <div class="score-bar-wrap"><div class="score-bar-fill <?= $pass?'pass':'fail' ?>" style="width:<?= min(100,$r['best_pct']) ?>%"></div></div>
        </td>
        <td><?= $r['best_score'] ?> / <?= $totalMarks ?></td>
        <td><span class="badge <?= $pass?'pass':'fail' ?>"><?= $pass?'✅ Pass':'❌ Fail' ?></span></td>
        <td style="color:var(--ink3);font-size:10.5px;"><?= fmtShort($r['submitted_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<div class="page-footer">
  <div><strong>PREPAURA</strong> — Placement Training Platform · Generated on <?= date('d M Y, H:i') ?></div>
  <div><?= $count ?> result(s) · Pass mark: <?= $passingMarks ?> / <?= $totalMarks ?> (<?= $passPct ?>%)<?= $isMulti ? ' · Max '.$maxAttempts.' attempts' : '' ?></div>
</div>

<?php if ($autoPrint): ?>
<script>
  window.addEventListener('load', function() { setTimeout(function(){ window.print(); }, 800); });
</script>
<?php endif; ?>
</body>
</html>