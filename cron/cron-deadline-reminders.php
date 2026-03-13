<?php
// ============================================================
// cron/cron-deadline-reminders.php
//
// Sends "2 days left" deadline reminder notifications.
// Run once daily via cron — recommended time: 08:00 server time.
//
//   Crontab entry:
//   0 8 * * * php /var/www/html/cron/cron-deadline-reminders.php >> /var/log/pta-cron.log 2>&1
//
// Logic:
//   1. Find all published assessments whose end_time falls within
//      the next 24–48 hours (the "2-day window").
//   2. For each assessment, find students who:
//        a) Have access to the assessment (via visibility/targets)
//        b) Have NOT yet submitted or timed out
//   3. Insert a 'warning' notification for each such student.
//      INSERT IGNORE prevents duplicates if cron runs twice in one day.
//
// Safe to run multiple times — idempotent via INSERT IGNORE +
// unique key on (user_id, related_entity_type, related_entity_id, type, title).
// ============================================================

// ── Bootstrap ──
// Resolve paths relative to project root regardless of cwd
$projectRoot = dirname(__DIR__);
require_once $projectRoot . '/config.php';
require_once $projectRoot . '/db-guard.php';
require_once $projectRoot . '/api/notify-helpers.php';

// CLI-only guard — prevents accidental web execution
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script must be run from the command line.\n";
    exit(1);
}

$now   = date('Y-m-d H:i:s');
// Window: assessments expiring between 24 h and 48 h from now
$in24h = date('Y-m-d H:i:s', strtotime('+24 hours'));
$in48h = date('Y-m-d H:i:s', strtotime('+48 hours'));

echo "[" . date('Y-m-d H:i:s') . "] Deadline reminder cron started.\n";

// ── Fetch assessments expiring in the 2-day window ──
$assessResult = safePreparedQuery($conn,
    "SELECT assessment_id, title, category, difficulty, end_time, visibility
     FROM assessments
     WHERE status   = 'published'
       AND end_time >= ?
       AND end_time <  ?",
    "ss", [$in24h, $in48h]
);

if (!$assessResult['success'] || !$assessResult['result']) {
    echo "ERROR: Could not fetch assessments.\n";
    exit(1);
}

$assessments = [];
while ($row = $assessResult['result']->fetch_assoc()) {
    $assessments[] = $row;
}
$assessResult['result']->free();

if (empty($assessments)) {
    echo "No assessments expiring in the next 24–48 hours. Nothing to do.\n";
    exit(0);
}

echo "Found " . count($assessments) . " assessment(s) expiring in 24–48 hours.\n";

$totalNotified = 0;

foreach ($assessments as $assessment) {
    $assessmentId = (int)$assessment['assessment_id'];
    $title        = $assessment['title'];
    $endTime      = $assessment['end_time'];
    $visibility   = $assessment['visibility'];

    // ── Get all students who have access ──
    $allStudents = resolve_assessment_students($conn, $assessmentId);

    if (empty($allStudents)) {
        echo "  Assessment #$assessmentId \"$title\": no eligible students, skipping.\n";
        continue;
    }

    // ── Exclude students who already submitted or timed out ──
    // Build a placeholder list for IN (?, ?, ...)
    $placeholders  = implode(',', array_fill(0, count($allStudents), '?'));
    $types         = 'i' . str_repeat('i', count($allStudents));
    $params        = array_merge([$assessmentId], $allStudents);

    $submittedResult = safePreparedQuery($conn,
        "SELECT DISTINCT user_id FROM assessment_attempts
         WHERE assessment_id = ?
           AND user_id IN ($placeholders)
           AND status IN ('submitted', 'timeout')",
        $types, $params
    );

    $submitted = [];
    if ($submittedResult['success'] && $submittedResult['result']) {
        while ($row = $submittedResult['result']->fetch_assoc()) {
            $submitted[] = (int)$row['user_id'];
        }
        $submittedResult['result']->free();
    }

    // Students who still need to attempt
    $pendingStudents = array_values(array_diff($allStudents, $submitted));

    if (empty($pendingStudents)) {
        echo "  Assessment #$assessmentId \"$title\": all students already submitted, skipping.\n";
        continue;
    }

    // ── Format deadline for human-readable message ──
    $deadlineFormatted = date('M j, Y \a\t g:i A', strtotime($endTime));
    $hoursLeft         = round((strtotime($endTime) - time()) / 3600);

    $notifTitle   = '⏰ Deadline Reminder: ' . $title;
    $notifMessage = 'This assessment expires in approximately ' . $hoursLeft . ' hours'
                  . ' (' . $deadlineFormatted . '). '
                  . 'Make sure to complete it before the deadline!';

    // ── Insert notifications (INSERT IGNORE — safe to re-run) ──
    bulk_insert_notifications(
        $conn,
        $pendingStudents,
        'warning',
        $notifTitle,
        $notifMessage,
        'assessment',
        $assessmentId
    );

    $count = count($pendingStudents);
    $totalNotified += $count;
    echo "  Assessment #$assessmentId \"$title\": notified $count student(s). Deadline: $deadlineFormatted\n";
}

echo "Done. Total notifications sent: $totalNotified.\n";
echo "[" . date('Y-m-d H:i:s') . "] Cron finished.\n";
