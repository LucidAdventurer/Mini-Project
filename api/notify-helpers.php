<?php
// ============================================================
// api/notify-helpers.php
//
// Shared helper functions for inserting notifications.
// Included by:
//   - api/assessment/update-status.php  (assessment published)
//   - api/materials/create-material.php (resource uploaded)
//   - cron/cron-deadline-reminders.php  (2-day expiry reminder)
//
// notifications schema:
//   notification_id, user_id, title, message,
//   type ENUM('info','success','warning','error','assessment','result','material'),
//   related_entity_type, related_entity_id, is_read, created_at
//
// All functions are idempotent:
//   duplicate (user_id + related_entity_id + type + title) rows
//   are skipped via INSERT IGNORE + unique key, so running the
//   same trigger twice never produces double notifications.
// ============================================================


// ──────────────────────────────────────────────────────────────
// HELPER: resolve_assessment_students
//
// Returns an array of user_ids for every student who can access
// the given assessment, based on visibility + assessment_targets.
//
// visibility='public'  → all active students
// visibility='group'   → students in targeted groups or targeted directly
// visibility='private' → no students (teacher-only)
// ──────────────────────────────────────────────────────────────
function resolve_assessment_students(mysqli $conn, int $assessmentId): array {
    // Get visibility
    $r = safePreparedQuery($conn,
        "SELECT visibility FROM assessments WHERE assessment_id = ?",
        "i", [$assessmentId]
    );
    if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) return [];
    $row        = $r['result']->fetch_assoc();
    $r['result']->free();
    $visibility = $row['visibility'];

    if ($visibility === 'private') return [];

    if ($visibility === 'public') {
        // All active students
        $r2 = safePreparedQuery($conn,
            "SELECT user_id FROM users WHERE role = 'student' AND is_active = 1",
            "", []
        );
        $ids = [];
        if ($r2['success'] && $r2['result']) {
            while ($row = $r2['result']->fetch_assoc()) $ids[] = (int)$row['user_id'];
            $r2['result']->free();
        }
        return $ids;
    }

    // visibility = 'group' — resolve via assessment_targets
    $r3 = safePreparedQuery($conn,
        "SELECT DISTINCT u.user_id
         FROM users u
         WHERE u.role = 'student' AND u.is_active = 1
           AND (
               -- Directly targeted student
               EXISTS (
                   SELECT 1 FROM assessment_targets at2
                   WHERE at2.assessment_id = ?
                     AND at2.target_type   = 'student'
                     AND at2.target_id     = u.user_id
               )
               OR
               -- Member of a targeted group
               EXISTS (
                   SELECT 1 FROM assessment_targets at3
                   JOIN group_members gm ON gm.group_id = at3.target_id
                   WHERE at3.assessment_id = ?
                     AND at3.target_type   = 'group'
                     AND gm.student_id     = u.user_id
               )
           )",
        "ii", [$assessmentId, $assessmentId]
    );
    $ids = [];
    if ($r3['success'] && $r3['result']) {
        while ($row = $r3['result']->fetch_assoc()) $ids[] = (int)$row['user_id'];
        $r3['result']->free();
    }
    return $ids;
}


// ──────────────────────────────────────────────────────────────
// HELPER: resolve_material_students
//
// Returns student user_ids who can access a given material,
// using the same visibility/targeting logic as assessments
// but against the materials + material_targets tables.
// ──────────────────────────────────────────────────────────────
function resolve_material_students(mysqli $conn, int $materialId): array {
    $r = safePreparedQuery($conn,
        "SELECT visibility FROM materials WHERE material_id = ?",
        "i", [$materialId]
    );
    if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) return [];
    $row        = $r['result']->fetch_assoc();
    $r['result']->free();
    $visibility = $row['visibility'];

    if ($visibility === 'private') return [];

    if ($visibility === 'public') {
        $r2 = safePreparedQuery($conn,
            "SELECT user_id FROM users WHERE role = 'student' AND is_active = 1",
            "", []
        );
        $ids = [];
        if ($r2['success'] && $r2['result']) {
            while ($row = $r2['result']->fetch_assoc()) $ids[] = (int)$row['user_id'];
            $r2['result']->free();
        }
        return $ids;
    }

    // 'group' visibility — resolve via material_targets
    $r3 = safePreparedQuery($conn,
        "SELECT DISTINCT u.user_id
         FROM users u
         WHERE u.role = 'student' AND u.is_active = 1
           AND (
               EXISTS (
                   SELECT 1 FROM material_targets mt
                   WHERE mt.material_id  = ?
                     AND mt.target_type  = 'student'
                     AND mt.target_id    = u.user_id
               )
               OR
               EXISTS (
                   SELECT 1 FROM material_targets mt2
                   JOIN group_members gm ON gm.group_id = mt2.target_id
                   WHERE mt2.material_id = ?
                     AND mt2.target_type = 'group'
                     AND gm.student_id   = u.user_id
               )
           )",
        "ii", [$materialId, $materialId]
    );
    $ids = [];
    if ($r3['success'] && $r3['result']) {
        while ($row = $r3['result']->fetch_assoc()) $ids[] = (int)$row['user_id'];
        $r3['result']->free();
    }
    return $ids;
}


// ──────────────────────────────────────────────────────────────
// HELPER: bulk_insert_notifications
//
// Inserts one notification row per user_id in $userIds.
// Uses INSERT IGNORE so re-running the same trigger never
// creates duplicates (requires a unique key on the table —
// see SQL note at bottom of this file).
//
// $type  : one of 'assessment' | 'material' | 'warning'
// $entityType : 'assessment' | 'material'
// $entityId   : the PK of the related row
// ──────────────────────────────────────────────────────────────
function bulk_insert_notifications(
    mysqli $conn,
    array  $userIds,
    string $type,
    string $title,
    string $message,
    string $entityType,
    int    $entityId,
    string $actionUrl = ''   // ← URL to redirect when notification is clicked
): void {
    if (empty($userIds)) return;

    // Prepare once, execute per user — safePreparedQuery handles
    // individual rows; batching here avoids N round-trips.
    $stmt = $conn->prepare(
        "INSERT IGNORE INTO notifications
            (user_id, title, message, type,
             related_entity_type, related_entity_id, action_url, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, NOW())"
    );
    if (!$stmt) {
        error_log("bulk_insert_notifications prepare failed: " . $conn->error);
        return;
    }
    $stmt->bind_param("issssiss",
        $uid, $title, $message, $type, $entityType, $entityId, $actionUrl
    );
    foreach ($userIds as $userId) {
        $uid = $userId;
        $stmt->execute();
    }
    $stmt->close();
}


// ──────────────────────────────────────────────────────────────
// PUBLIC: notifyAssessmentPublished
//
// Called by update-status.php when an assessment transitions
// to 'published'. Sends an 'assessment' notification to every
// student who has access.
// ──────────────────────────────────────────────────────────────
function notifyAssessmentPublished(mysqli $conn, int $assessmentId): void {
    // Fetch assessment details for the message
    $r = safePreparedQuery($conn,
        "SELECT title, category, difficulty, end_time FROM assessments WHERE assessment_id = ?",
        "i", [$assessmentId]
    );
    if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) return;
    $a = $r['result']->fetch_assoc();
    $r['result']->free();

    $deadline = $a['end_time']
        ? ' Deadline: ' . date('M j, Y g:i A', strtotime($a['end_time'])) . '.'
        : '';

    $title   = 'New Assessment: ' . $a['title'];
    $message = ucfirst($a['category']) . ' · ' . ucfirst($a['difficulty'])
             . ' — A new assessment has been published for you.' . $deadline;

    $students  = resolve_assessment_students($conn, $assessmentId);
    $actionUrl = 'student-assessments.php?id=' . $assessmentId; // ⚠️ update path if different
    bulk_insert_notifications(
        $conn, $students,
        'assessment', $title, $message,
        'assessment', $assessmentId, $actionUrl
    );
}


// ──────────────────────────────────────────────────────────────
// PUBLIC: notifyMaterialUploaded
//
// Called by create-material.php after a resource is saved.
// Sends a 'material' notification to every student who has access.
// ──────────────────────────────────────────────────────────────
function notifyMaterialUploaded(mysqli $conn, int $materialId): void {
    $r = safePreparedQuery($conn,
        "SELECT m.title, m.category, m.difficulty, u.full_name AS teacher_name
         FROM materials m
         JOIN users u ON u.user_id = m.created_by
         WHERE m.material_id = ?",
        "i", [$materialId]
    );
    if (!$r['success'] || !$r['result'] || $r['result']->num_rows === 0) return;
    $mat = $r['result']->fetch_assoc();
    $r['result']->free();

    $title   = 'New Resource: ' . $mat['title'];
    $message = 'A new ' . ucfirst($mat['category'] ?? 'study') . ' resource'
             . ' has been uploaded by ' . $mat['teacher_name'] . '.';
    if (!empty($mat['difficulty'])) {
        $message .= ' Difficulty: ' . ucfirst($mat['difficulty']) . '.';
    }

    $students  = resolve_material_students($conn, $materialId);
    $actionUrl = 'student-resources.php?id=' . $materialId; // ⚠️ update path if different
    bulk_insert_notifications(
        $conn, $students,
        'material', $title, $message,
        'material', $materialId, $actionUrl
    );
}

/*
 * ── Required unique key on notifications table ──────────────────
 * To make INSERT IGNORE idempotent, add this index once in MySQL:
 *
 *   ALTER TABLE notifications
 *   ADD UNIQUE KEY uq_notif_user_entity_type (
 *       user_id, related_entity_type, related_entity_id, type, title(100)
 *   );
 *
 * This prevents double-notifications if a teacher publishes,
 * un-publishes, then publishes again — the second publish is ignored.
 * Remove the key if re-notification on re-publish is desired.
 * ────────────────────────────────────────────────────────────────
 */
