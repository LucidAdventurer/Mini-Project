<?php
/* ========================================
 * API: Upload Test from JSON (FINAL FIXED)
 * ======================================== */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db-guard.php';

header('Content-Type: application/json');

// ── DB connection ─────────────────────────
$conn = createDatabaseConnection();
if (!$conn) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// ── Auth: admin OR teacher ───────────────
$user = null;
foreach (['admin', 'teacher'] as $role) {
    try { $user = validateSession($conn, $role); break; } catch (Exception $e) {}
}
if (!$user) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── CSRF ──────────────────────────────────
validateCsrfToken();

// ── Parse request ─────────────────────────
$body = json_decode(file_get_contents('php://input'), true);
if (!$body || empty($body['test'])) {
    echo json_encode(['success' => false, 'error' => 'Missing test payload']);
    exit;
}

$t = $body['test'];

// ── Validation helper ─────────────────────
function vErr($msg) {
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// ── Basic fields ──────────────────────────
$title = trim($t['title'] ?? '');
if (!$title) vErr('title is required');
if (strlen($title) > 200) vErr('title too long');

$description      = trim($t['description'] ?? '');
$category         = trim($t['category'] ?? '');
$duration_minutes = max(1, intval($t['duration_minutes'] ?? 0));
$total_marks      = max(1, intval($t['total_marks'] ?? 0));
$passing_marks    = max(0, intval($t['passing_marks'] ?? 0));
$max_attempts     = max(1, intval($t['max_attempts'] ?? 1));

if ($passing_marks > $total_marks) {
    vErr('passing_marks cannot exceed total_marks');
}

$randomize_q = !empty($t['randomize_questions']) ? 1 : 0;
$randomize_o = !empty($t['randomize_options']) ? 1 : 0;

$difficulty = in_array($t['difficulty'] ?? '', ['easy','medium','hard']) ? $t['difficulty'] : 'medium';
$visibility = in_array($t['visibility'] ?? '', ['public','group','private']) ? $t['visibility'] : 'public';

// ── TIME FIELDS ───────────────────────────
$start_time = !empty($t['start_time']) ? $t['start_time'] : null;
$end_time   = !empty($t['end_time'])   ? $t['end_time']   : null;

// ── Questions ─────────────────────────────
$questions = $t['questions'] ?? [];
if (!is_array($questions) || count($questions) === 0) {
    vErr('questions missing');
}

$allowed_qtypes = ['mcq','multiple_select','true_false','short_answer'];

foreach ($questions as $i => $q) {
    $n = $i + 1;

    $qText = trim($q['question_text'] ?? '');
    if (!$qText) vErr("Question $n: text required");

    $qType = $q['question_type'] ?? '';
    if (!in_array($qType, $allowed_qtypes)) {
        vErr("Question $n: invalid type");
    }

    // TRUE/FALSE auto fix
    if ($qType === 'true_false') {
        $correct = strtolower(trim($q['correct_answer'] ?? ''));

        if (!in_array($correct, ['true', 'false'])) {
            vErr("Question $n: invalid true/false answer");
        }

        $questions[$i]['options'] = [
            ['option_text' => 'True',  'is_correct' => ($correct === 'true')],
            ['option_text' => 'False', 'is_correct' => ($correct === 'false')],
        ];
    }

    // Validate options (skip for short_answer)
    if ($qType !== 'short_answer') {
        if (!isset($questions[$i]['options']) || !is_array($questions[$i]['options'])) {
            vErr("Question $n: invalid options format");
        }

        $hasCorrect = false;
        foreach ($questions[$i]['options'] as $o) {
            if (empty(trim($o['option_text'] ?? ''))) {
                vErr("Question $n: empty option");
            }
            if (!empty($o['is_correct'])) $hasCorrect = true;
        }

        if (!$hasCorrect && $qType !== 'multiple_select') {
            vErr("Question $n: no correct answer");
        }
    }
}

// ── TRANSACTION ───────────────────────────
$conn->begin_transaction();

try {

    // ── Insert assessment ─────────────────
    // Format string: i s s s s s i i i i i i s s  = 14 params
    $stmt = $conn->prepare(
        "INSERT INTO assessments
        (created_by, title, description, visibility, status, category, difficulty,
         duration_minutes, total_marks, passing_marks, max_attempts,
         randomize_questions, randomize_options,
         start_time, end_time,
         created_at, updated_at)
        VALUES (?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
    );

    $userId = (int)$user['user_id'];

    $stmt->bind_param(
        "isssssiiiiiiss",
        $userId,
        $title,
        $description,
        $visibility,
        $category,
        $difficulty,
        $duration_minutes,
        $total_marks,
        $passing_marks,
        $max_attempts,
        $randomize_q,
        $randomize_o,
        $start_time,
        $end_time
    );

    $stmt->execute();
    $assessmentId = $conn->insert_id;
    $stmt->close();

    // ── Insert questions ─────────────────
    $qStmt = $conn->prepare(
        "INSERT INTO questions
        (assessment_id, question_text, question_type, marks, negative_marks,
         explanation, question_order, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    $oStmt = $conn->prepare(
        "INSERT INTO question_options
        (question_id, option_text, is_correct, option_order)
        VALUES (?, ?, ?, ?)"
    );

    foreach ($questions as $index => $q) {
        $qText = trim($q['question_text']);
        $qType = $q['question_type'];
        $marks = max(1, intval($q['marks'] ?? 1));
        $neg   = max(0.0, floatval($q['negative_marks'] ?? 0));
        $expl  = trim($q['explanation'] ?? '');
        $order = $index + 1;

        $qStmt->bind_param("issidsi",
            $assessmentId, $qText, $qType, $marks, $neg, $expl, $order
        );
        $qStmt->execute();

        $questionId = $conn->insert_id;

        if ($qType !== 'short_answer') {
            foreach ($q['options'] as $optIndex => $o) {
                $text     = trim($o['option_text']);
                $correct  = !empty($o['is_correct']) ? 1 : 0;
                $optOrder = $optIndex + 1;

                $oStmt->bind_param("isii",
                    $questionId, $text, $correct, $optOrder
                );
                $oStmt->execute();
            }
        }
    }

    $qStmt->close();
    $oStmt->close();

    $conn->commit();

    echo json_encode([
        'success'       => true,
        'assessment_id' => $assessmentId,
        'message'       => 'Assessment created successfully (draft)'
    ]);

} catch (Exception $e) {
    $conn->rollback();
    error_log('[upload-test] ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'error'   => 'Database error: ' . $e->getMessage()
    ]);
}