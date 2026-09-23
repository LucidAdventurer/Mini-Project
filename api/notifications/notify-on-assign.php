<?php
/**
 * notify-on-assign.php
 * ─────────────────────────────────────────────────────────────────────
 * Call createAssignmentNotification() right after you INSERT into
 * assessment_targets (teacher-side assignment logic).
 *
 * USAGE EXAMPLE (teacher assign controller):
 *
 *   require_once 'notify-on-assign.php';
 *
 *   // 1. Insert the assignment as you normally do
 *   safePreparedQuery($conn,
 *       "INSERT INTO assessment_targets (assessment_id, target_type, target_id) VALUES (?,?,?)",
 *       "isi", [$assessmentId, 'student', $studentId]
 *   );
 *
 *   // 2. Fire the notification — that's all you need to add
 *   createAssignmentNotification($conn, $assessmentId, 'student', $studentId);
 *
 * For GROUP assignments, call it once per group, or loop over members:
 *
 *   // Option A – one notification per group member (recommended)
 *   $members = getGroupMembers($conn, $groupId);   // your existing helper
 *   foreach ($members as $memberId) {
 *       createAssignmentNotification($conn, $assessmentId, 'student', $memberId);
 *   }
 *
 *   // Option B – one notification per group (if you prefer)
 *   createAssignmentNotification($conn, $assessmentId, 'group', $groupId);
 * ─────────────────────────────────────────────────────────────────────
 */

/**
 * Insert a notification row so the student sees the bell alert.
 *
 * @param PDO     $conn           Active DB connection
 * @param int     $assessmentId   The assessment just assigned
 * @param string  $targetType     'student' or 'group'
 * @param int     $targetId       student user_id  OR  group_id
 */
function createAssignmentNotification(
    PDO $conn,
    int $assessmentId,
    string $targetType,
    int $targetId
): void {

    // ── Fetch the assessment title once ──────────────────────────────
    $result = safePreparedQuery(
        $conn,
        "SELECT title
        FROM assessments
        WHERE assessment_id = ?
        LIMIT 1",
        "i",
        [$assessmentId]
    );

    if (!$result['success'] || !$result['result']) {
        return;
    }

    $row = $result['result']->fetch_assoc();
    $result['result']->free();

    if (!$row) {
        return;
    }

    $title = $row['title'];

    // ── Resolve the list of student user_ids to notify ───────────────
    $studentIds = [];

    if ($targetType === 'student') {
        $studentIds[] = $targetId;

    } elseif ($targetType === 'group') {
        $result = safePreparedQuery(
    $conn,
        "SELECT student_id
        FROM group_members
        WHERE group_id = ?",
        "i",
        [$targetId]
    );

    if (!$result['success'] || !$result['result']) {
        return;
    }

    while ($r = $result['result']->fetch_assoc()) {
        $studentIds[] = (int) $r['student_id'];
    }

    $result['result']->free();
    }

    if (empty($studentIds)) return;

    // ── Insert one notification row per student ───────────────────────
    foreach ($studentIds as $uid) {
    $message = "\"$title\" has been assigned to you. Good luck!";

    safePreparedQuery(
        $conn,
        "INSERT INTO notifications
            (user_id, title, message, type, related_entity_type,
             related_entity_id, is_read, created_at)
         VALUES
            (?, ?, ?, 'assessment', 'assessment', ?, FALSE, NOW())",
        "issi",
        [$uid, 'New Test Assigned', $message, $assessmentId]
    );
}