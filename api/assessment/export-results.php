<?php
// ============================================================
// api/assessment/export-results.php
// Exports assessment results in multiple formats.
//
// GET params:
//   assessment_id  int     (required)
//   format         string  csv | excel | pdf | print
//   filter         string  all | pass | fail  (optional, default: all)
//
// SCHEMA NOTE: Uses only columns confirmed in the LIVE database.
//   Live status values: 'submitted', 'timeout'  (no 'completed')
//   No correct_answers / wrong_answers / unanswered columns.
// ============================================================

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../db-guard.php';

$currentUser = validateSession($conn, 'teacher');
$teacherId   = (int) $currentUser['user_id'];

$assessmentId = (int)($_GET['assessment_id'] ?? 0);
$format       = strtolower(trim($_GET['format'] ?? 'csv'));
$filter       = strtolower(trim($_GET['filter'] ?? 'all'));

$allowedFormats = ['csv', 'excel', 'pdf', 'print'];
$allowedFilters = ['all', 'pass', 'fail'];

if ($assessmentId <= 0) {
    http_response_code(400);
    echo 'Invalid assessment ID.';
    exit;
}
if (!in_array($format, $allowedFormats, true)) {
    http_response_code(400);
    echo 'Invalid format.';
    exit;
}
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

// ── Verify ownership ──
$asmRes = safePreparedQuery(
    $conn,
    "SELECT assessment_id, title, total_marks, passing_marks, max_attempts, category, difficulty
     FROM assessments WHERE assessment_id = ? AND created_by = ?",
    "ii",
    [$assessmentId, $teacherId]
);

if (!$asmRes['success'] || !$asmRes['result'] || $asmRes['result']->num_rows === 0) {
    http_response_code(403);
    echo 'Access denied or assessment not found.';
    exit;
}
$asm = $asmRes['result']->fetch_assoc();
$asmRes['result']->free();

$title        = $asm['title'];
$totalMarks   = (int)$asm['total_marks'];
$passingMarks = (int)$asm['passing_marks'];
$maxAttempts  = (int)($asm['max_attempts'] ?? 1);
$passPct      = $totalMarks > 0 ? round($passingMarks / $totalMarks * 100, 2) : 0;
$isMulti      = $maxAttempts > 1;

// ── Fetch all completed attempts (live schema columns only) ──
$raw = $conn->query(
    "SELECT
        aa.attempt_id,
        aa.user_id,
        aa.attempt_number,
        aa.score,
        aa.percentage,
        aa.submitted_at,
        aa.status,
        u.full_name           AS student_name,
        u.email,
        u.department,
        u.registration_number
     FROM assessment_attempts aa
     LEFT JOIN users u ON u.user_id = aa.user_id
     WHERE aa.assessment_id = $assessmentId
       AND aa.status IN ('submitted', 'timeout')
     ORDER BY aa.user_id ASC, aa.attempt_number ASC"
);

if (!$raw) {
    http_response_code(500);
    echo 'Query failed: ' . $conn->error;
    exit;
}

// ── Group by student ──
$studentMap = [];

while ($row = $raw->fetch_assoc()) {
    $uid = $row['user_id'] !== null ? (int)$row['user_id'] : ('guest_' . $row['attempt_id']);

    if (!isset($studentMap[$uid])) {
        $studentMap[$uid] = [
            'student_name' => $row['student_name'] ?? 'Unknown',
            'email'        => $row['email']         ?? '',
            'department'   => $row['department']    ?? '',
            'reg_no'       => $row['registration_number'] ?? '',
            'attempts'     => [],
            'best_pct'     => -1,
            'best_idx'     => 0,
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

// ── Build rows, applying filter ──
$rows      = [];
$totalPct  = 0;
$passCount = 0;
$rank      = 0;

uasort($studentMap, fn($a, $b) => $b['best_pct'] <=> $a['best_pct']);

foreach ($studentMap as $s) {
    $best     = $s['attempts'][$s['best_idx']];
    $pct      = $best['percentage'];
    $isPassed = $pct >= $passPct;

    if ($filter === 'pass' && !$isPassed) continue;
    if ($filter === 'fail' && $isPassed)  continue;

    $rank++;
    $totalPct += $pct;
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

// ── Helpers ──
function fmtDate(?string $dt): string {
    if (!$dt) return '—';
    return date('d M Y, H:i', strtotime($dt));
}
function fmtShortDate(?string $dt): string {
    if (!$dt) return '—';
    return date('d M Y', strtotime($dt));
}

$exportDate = date('d M Y');
$fileName   = 'Results_' . str_replace(' ', '_', preg_replace('/[^a-zA-Z0-9_\- ]/', '', $title)) . '_' . date('Y-m-d');

// ═══════════════════════════════════════════════════════
// FORMAT: CSV
// ═══════════════════════════════════════════════════════
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.csv"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel

    fputcsv($out, ['Assessment', $title]);
    fputcsv($out, ['Total Marks', $totalMarks, 'Passing Marks', $passingMarks, 'Pass %', $passPct . '%']);
    fputcsv($out, ['Exported On', $exportDate, 'Filter', ucfirst($filter)]);
    fputcsv($out, ['Total Students', $count, 'Avg Score', $avgScore . '%', 'Pass Rate', $passRate . '%']);
    fputcsv($out, []);

    if ($isMulti) {
        fputcsv($out, ['#', 'Student Name', 'Email', 'Department', 'Reg No',
                       'Attempt #', 'Score %', 'Marks', 'Result', 'Submitted At']);
        foreach ($rows as $r) {
            foreach ($r['attempts'] as $idx => $att) {
                $attPass = $att['percentage'] >= $passPct;
                fputcsv($out, [
                    $idx === 0 ? $r['rank'] : '',
                    $idx === 0 ? $r['student_name'] : '',
                    $idx === 0 ? $r['email'] : '',
                    $idx === 0 ? $r['department'] : '',
                    $idx === 0 ? $r['reg_no'] : '',
                    'Attempt ' . $att['attempt_number'],
                    $att['percentage'] . '%',
                    $att['score'] . ' / ' . $totalMarks,
                    $attPass ? 'Pass' : 'Fail',
                    fmtDate($att['submitted_at']),
                ]);
            }
        }
    } else {
        fputcsv($out, ['#', 'Student Name', 'Email', 'Department', 'Reg No',
                       'Score %', 'Marks', 'Result', 'Submitted At']);
        foreach ($rows as $r) {
            fputcsv($out, [
                $r['rank'],
                $r['student_name'],
                $r['email'],
                $r['department'],
                $r['reg_no'],
                $r['best_pct'] . '%',
                $r['best_score'] . ' / ' . $totalMarks,
                $r['passed'] ? 'Pass' : 'Fail',
                fmtDate($r['submitted_at']),
            ]);
        }
    }

    fclose($out);
    exit;
}

// ═══════════════════════════════════════════════════════
// FORMAT: EXCEL
// ═══════════════════════════════════════════════════════
if ($format === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fileName . '.xls"');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    ?>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head><meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
<x:Name>Results</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->
<style>
  body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; }
  .hdr  { background: #4c1d95; color: #fff; font-weight: bold; padding: 6px 10px; font-size: 14pt; }
  .info { background: #ede9f6; font-size: 10pt; padding: 4px 8px; }
  .th   { background: #7c3aed; color: #fff; font-weight: bold; padding: 6px 10px; border: 1px solid #6d28d9; }
  .td   { padding: 5px 10px; border: 1px solid #e2d9f3; }
  .pass { color: #065f46; background: #d1fae5; font-weight: bold; }
  .fail { color: #9f1239; background: #ffe4e6; font-weight: bold; }
  .best { background: #fef9c3; }
  .sub  { color: #6b7280; font-size: 9.5pt; }
  .att  { background: #ddd6fe; font-weight: bold; }
</style></head><body>
<table cellspacing="0" cellpadding="0">
<tr><td colspan="9" class="hdr"><?= htmlspecialchars($title) ?> — Student Results</td></tr>
<tr>
  <td class="info"><b>Total Marks:</b> <?= $totalMarks ?></td>
  <td class="info"><b>Passing Marks:</b> <?= $passingMarks ?> (<?= $passPct ?>%)</td>
  <td class="info"><b>Exported:</b> <?= $exportDate ?></td>
  <td class="info"><b>Filter:</b> <?= ucfirst($filter) ?></td>
  <td class="info" colspan="5"></td>
</tr>
<tr>
  <td class="info"><b>Students:</b> <?= $count ?></td>
  <td class="info"><b>Avg Score:</b> <?= $avgScore ?>%</td>
  <td class="info"><b>Pass Rate:</b> <?= $passRate ?>%</td>
  <td class="info" colspan="6"></td>
</tr>
<tr><td colspan="9" style="padding:4px;"></td></tr>

<?php if ($isMulti): ?>
<tr>
  <th class="th">#</th><th class="th">Student Name</th><th class="th">Email</th>
  <th class="th">Department</th><th class="th">Reg No</th>
  <th class="th">Attempt</th><th class="th">Score %</th>
  <th class="th">Marks</th><th class="th">Result</th><th class="th">Submitted At</th>
</tr>
<?php foreach ($rows as $r):
    $bestPct = $r['best_pct'];
    foreach ($r['attempts'] as $idx => $att):
        $attPass = $att['percentage'] >= $passPct;
        $isBest  = ($att['percentage'] === $bestPct);
?>
<tr<?= $isBest ? ' class="best"' : '' ?>>
  <td class="td"><?= $idx === 0 ? $r['rank'] : '' ?></td>
  <td class="td"><?= $idx === 0 ? htmlspecialchars($r['student_name']) : '' ?></td>
  <td class="td sub"><?= $idx === 0 ? htmlspecialchars($r['email']) : '' ?></td>
  <td class="td"><?= $idx === 0 ? htmlspecialchars($r['department']) : '' ?></td>
  <td class="td"><?= $idx === 0 ? htmlspecialchars($r['reg_no']) : '' ?></td>
  <td class="td att">Attempt <?= $att['attempt_number'] ?><?= $isBest ? ' ★' : '' ?></td>
  <td class="td"><b><?= $att['percentage'] ?>%</b></td>
  <td class="td"><?= $att['score'] ?> / <?= $totalMarks ?></td>
  <td class="td <?= $attPass ? 'pass' : 'fail' ?>"><?= $attPass ? 'Pass' : 'Fail' ?></td>
  <td class="td sub"><?= fmtDate($att['submitted_at']) ?></td>
</tr>
<?php endforeach; endforeach; ?>

<?php else: ?>
<tr>
  <th class="th">#</th><th class="th">Student Name</th><th class="th">Email</th>
  <th class="th">Department</th><th class="th">Reg No</th>
  <th class="th">Score %</th><th class="th">Marks</th>
  <th class="th">Result</th><th class="th">Submitted At</th>
</tr>
<?php foreach ($rows as $r): ?>
<tr>
  <td class="td"><?= $r['rank'] ?></td>
  <td class="td"><b><?= htmlspecialchars($r['student_name']) ?></b></td>
  <td class="td sub"><?= htmlspecialchars($r['email']) ?></td>
  <td class="td"><?= htmlspecialchars($r['department']) ?></td>
  <td class="td"><?= htmlspecialchars($r['reg_no']) ?></td>
  <td class="td"><b><?= $r['best_pct'] ?>%</b></td>
  <td class="td"><?= $r['best_score'] ?> / <?= $totalMarks ?></td>
  <td class="td <?= $r['passed'] ? 'pass' : 'fail' ?>"><?= $r['passed'] ? 'Pass' : 'Fail' ?></td>
  <td class="td sub"><?= fmtDate($r['submitted_at']) ?></td>
</tr>
<?php endforeach; ?>
<?php endif; ?>

</table></body></html>
<?php
    exit;
}

// ═══════════════════════════════════════════════════════
// FORMAT: PDF / PRINT
// ═══════════════════════════════════════════════════════
$autoPrint   = ($format === 'print');
$filterLabel = match($filter) {
    'pass'  => ' — Passed Students Only',
    'fail'  => ' — Failed Students Only',
    default => '',
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($title) ?> — Results<?= htmlspecialchars($filterLabel) ?></title>
<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600;700&family=Syne:wght@700;800&display=swap');
*, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }
:root {
  --violet:#7c3aed; --violet2:#4c1d95; --orchid:#c084fc;
  --emerald:#059669; --rose:#e11d48; --ink:#1a1425;
  --ink2:#4b4565; --ink3:#8b7fa8; --surface:#f7f5fb; --border:#e2d9f3;
}
body { font-family:'DM Sans',sans-serif; font-size:12px; color:var(--ink); background:#fff; }

/* Header */
.page-header {
  background:linear-gradient(135deg,var(--violet2) 0%,var(--violet) 60%,var(--orchid) 100%);
  color:white; padding:28px 32px 24px;
}
.page-header .brand { font-family:'Syne',sans-serif; font-size:11px; font-weight:700;
  letter-spacing:.12em; text-transform:uppercase; opacity:.7; margin-bottom:8px; }
.page-header h1 { font-family:'Syne',sans-serif; font-size:22px; font-weight:800; line-height:1.2; margin-bottom:4px; }
.page-header .subtitle { font-size:12px; opacity:.75; margin-bottom:16px; }

.stat-row { display:grid; grid-template-columns:repeat(5,1fr); gap:12px; margin-top:16px; }
.stat-card { background:rgba(255,255,255,.12); border:1px solid rgba(255,255,255,.2);
  border-radius:10px; padding:12px 14px; text-align:center; }
.stat-value { font-family:'Syne',sans-serif; font-size:22px; font-weight:800; line-height:1; margin-bottom:4px; }
.stat-label { font-size:10px; opacity:.7; text-transform:uppercase; letter-spacing:.06em; }

.filter-badge { display:inline-flex; align-items:center; gap:5px; margin-top:12px;
  padding:4px 12px; background:rgba(255,255,255,.15); border:1px solid rgba(255,255,255,.25);
  border-radius:20px; font-size:10.5px; font-weight:600; letter-spacing:.04em; }

/* Table */
.table-wrap { padding:24px 32px; }
table.results { width:100%; border-collapse:collapse; font-size:11.5px; }
table.results thead tr { background:linear-gradient(90deg,var(--violet2),var(--violet)); color:white; }
table.results thead th { padding:9px 10px; text-align:left; font-weight:600;
  font-size:10px; text-transform:uppercase; letter-spacing:.05em; white-space:nowrap; }
table.results tbody tr { border-bottom:1px solid var(--border); }
table.results tbody tr:nth-child(even) { background:var(--surface); }
table.results tbody td { padding:8px 10px; vertical-align:middle; }

.student-name { font-weight:600; font-size:12px; }
.student-email { font-size:10.5px; color:var(--ink3); margin-top:1px; }
.student-reg   { font-size:10px; color:var(--ink3); }

.score-pct { font-weight:700; font-size:14px; }
.score-bar-wrap { margin-top:3px; height:4px; background:#e2d9f3; border-radius:4px; overflow:hidden; }
.score-bar-fill { height:100%; border-radius:4px; }
.score-bar-fill.pass { background:var(--emerald); }
.score-bar-fill.fail { background:var(--rose); }

.badge { display:inline-flex; align-items:center; gap:4px; padding:3px 10px;
  border-radius:20px; font-size:10.5px; font-weight:700; }
.badge.pass { background:#d1fae5; color:#065f46; }
.badge.fail { background:#ffe4e6; color:#9f1239; }

.rank-cell { font-family:'Syne',sans-serif; font-size:13px; font-weight:800;
  color:var(--ink3); text-align:center; }
.rank-cell.gold   { color:#d97706; }
.rank-cell.silver { color:#6b7280; }
.rank-cell.bronze { color:#92400e; }

/* Multi-attempt rows */
.att-cell { font-size:11px; color:var(--violet); font-weight:600; }
.att-cell.best { color:#b45309; }

/* Footer */
.page-footer { padding:16px 32px; border-top:2px solid var(--border);
  display:flex; justify-content:space-between; align-items:center;
  font-size:10.5px; color:var(--ink3); }
.page-footer strong { color:var(--ink); }

/* Screen print controls */
.print-controls {
  position:fixed; top:0; left:0; right:0;
  background:#1a1425; color:white;
  padding:10px 24px; display:flex; align-items:center;
  justify-content:space-between; gap:16px;
  z-index:1000; box-shadow:0 2px 12px rgba(0,0,0,.3);
}
.ctrl-title { font-weight:600; font-size:13px; }
.ctrl-hint  { font-size:11.5px; opacity:.6; }
.btn-print-now {
  display:flex; align-items:center; gap:8px;
  background:var(--violet); color:white; border:none; border-radius:8px;
  padding:9px 18px; font-size:13px; font-weight:600;
  cursor:pointer; font-family:'DM Sans',sans-serif;
}
.btn-close-preview {
  background:rgba(255,255,255,.1); color:white;
  border:1px solid rgba(255,255,255,.15); border-radius:8px;
  padding:9px 16px; font-size:13px; font-weight:500;
  cursor:pointer; font-family:'DM Sans',sans-serif;
}
.empty-state { text-align:center; padding:48px; color:var(--ink3); }
.empty-state .icon { font-size:40px; margin-bottom:12px; }

@media print {
  .print-controls { display:none !important; }
  body { padding-top:0 !important; }
  .page-header { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  table.results thead tr { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .badge { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  .score-bar-fill { -webkit-print-color-adjust:exact; print-color-adjust:exact; }
  tr { page-break-inside:avoid; }
}
@media screen { body { padding-top:52px; } }
</style>
</head>
<body>

<div class="print-controls">
  <div>
    <div class="ctrl-title">📄 <?= htmlspecialchars($title) ?> — Export Preview</div>
    <div class="ctrl-hint">Click "Print / Save as PDF" to download or print via your browser.</div>
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
    <?= $filter === 'pass' ? '✅ Showing Passed Students Only' : '❌ Showing Failed Students Only' ?>
  </div>
  <?php endif; ?>
</div>

<div class="table-wrap">
<?php if (empty($rows)): ?>
  <div class="empty-state">
    <div class="icon">📭</div>
    <strong>No results match the current filter.</strong>
  </div>

<?php elseif ($isMulti): ?>
  <table class="results">
    <thead>
      <tr>
        <th style="width:36px;">#</th>
        <th>Student</th>
        <th>Department</th>
        <th>Attempt</th>
        <th>Score %</th>
        <th>Marks</th>
        <th>Result</th>
        <th>Submitted</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $rankCls = match($r['rank']) { 1=>'gold', 2=>'silver', 3=>'bronze', default=>'' };
        $bestPct = $r['best_pct'];
        $attCount = count($r['attempts']);
    ?>
      <?php foreach ($r['attempts'] as $idx => $att):
          $attPass = $att['percentage'] >= $passPct;
          $isBest  = ($att['percentage'] === $bestPct);
      ?>
      <tr>
        <?php if ($idx === 0): ?>
        <td class="rank-cell <?= $rankCls ?>" rowspan="<?= $attCount ?>"><?= $r['rank'] ?></td>
        <td rowspan="<?= $attCount ?>">
          <div class="student-name"><?= htmlspecialchars($r['student_name']) ?></div>
          <div class="student-email"><?= htmlspecialchars($r['email']) ?></div>
          <?php if ($r['reg_no']): ?><div class="student-reg"><?= htmlspecialchars($r['reg_no']) ?></div><?php endif; ?>
        </td>
        <td rowspan="<?= $attCount ?>"><?= htmlspecialchars($r['department'] ?: '—') ?></td>
        <?php endif; ?>
        <td class="att-cell<?= $isBest ? ' best' : '' ?>">
          Attempt <?= $att['attempt_number'] ?><?= $isBest ? ' ★' : '' ?>
        </td>
        <td>
          <div class="score-pct" style="color:<?= $attPass ? 'var(--emerald)' : 'var(--rose)' ?>;"><?= $att['percentage'] ?>%</div>
          <div class="score-bar-wrap"><div class="score-bar-fill <?= $attPass ? 'pass' : 'fail' ?>" style="width:<?= min(100,$att['percentage']) ?>%"></div></div>
        </td>
        <td><?= $att['score'] ?> / <?= $totalMarks ?></td>
        <td><span class="badge <?= $attPass ? 'pass' : 'fail' ?>"><?= $attPass ? '✅ Pass' : '❌ Fail' ?></span></td>
        <td style="color:var(--ink3);font-size:10.5px;"><?= fmtShortDate($att['submitted_at']) ?></td>
      </tr>
      <?php endforeach; ?>
    <?php endforeach; ?>
    </tbody>
  </table>

<?php else: ?>
  <table class="results">
    <thead>
      <tr>
        <th style="width:36px;">#</th>
        <th>Student</th>
        <th>Department</th>
        <th>Score %</th>
        <th>Marks</th>
        <th>Result</th>
        <th>Submitted</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($rows as $r):
        $pass    = $r['passed'];
        $rankCls = match($r['rank']) { 1=>'gold', 2=>'silver', 3=>'bronze', default=>'' };
    ?>
      <tr>
        <td class="rank-cell <?= $rankCls ?>"><?= $r['rank'] ?></td>
        <td>
          <div class="student-name"><?= htmlspecialchars($r['student_name']) ?></div>
          <div class="student-email"><?= htmlspecialchars($r['email']) ?></div>
          <?php if ($r['reg_no']): ?><div class="student-reg"><?= htmlspecialchars($r['reg_no']) ?></div><?php endif; ?>
        </td>
        <td><?= htmlspecialchars($r['department'] ?: '—') ?></td>
        <td>
          <div class="score-pct" style="color:<?= $pass ? 'var(--emerald)' : 'var(--rose)' ?>;"><?= $r['best_pct'] ?>%</div>
          <div class="score-bar-wrap"><div class="score-bar-fill <?= $pass ? 'pass' : 'fail' ?>" style="width:<?= min(100,$r['best_pct']) ?>%"></div></div>
        </td>
        <td><?= $r['best_score'] ?> / <?= $totalMarks ?></td>
        <td><span class="badge <?= $pass ? 'pass' : 'fail' ?>"><?= $pass ? '✅ Pass' : '❌ Fail' ?></span></td>
        <td style="color:var(--ink3);font-size:10.5px;"><?= fmtShortDate($r['submitted_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
</div>

<div class="page-footer">
  <div><strong>PREPAURA</strong> — Placement Training Platform · Generated on <?= date('d M Y, H:i') ?></div>
  <div><?= $count ?> result(s) · Pass mark: <?= $passingMarks ?> / <?= $totalMarks ?> (<?= $passPct ?>%)<?= $isMulti ? ' · Max ' . $maxAttempts . ' attempts' : '' ?></div>
</div>

<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function(){ setTimeout(function(){ window.print(); }, 600); });</script>
<?php endif; ?>
</body>
</html>