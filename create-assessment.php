<?php
// ============================================================
// DEPENDENCIES
// config.php: establishes $conn (mysqli) + starts session
// db-guard.php: provides safePreparedQuery(), validateSession()
// ============================================================
require_once 'config.php';
require_once 'db-guard.php';

// ============================================================
// SECURITY: Require teacher session
// validateSession() checks $_SESSION['uid'] + $_SESSION['role'],
// verifies the user is active in the DB, and redirects otherwise.
// ============================================================
$currentUser = validateSession($conn, 'teacher');
$teacher_id  = (int) $currentUser['user_id'];

// ============================================================
// HANDLE AJAX REQUESTS
// The frontend posts JSON to this same file with ?action=...
// We respond with JSON and exit before any HTML is sent.
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    header('Content-Type: application/json');

    $action = $_GET['action'];
    $body   = json_decode(file_get_contents('php://input'), true);

    if (!$body) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body.']);
        exit;
    }

    // ----------------------------------------------------------
    // SHARED: save_draft OR publish
    // Both write to assessments + questions tables.
    // The only difference is the status column value.
    // ----------------------------------------------------------
    if ($action === 'save_draft' || $action === 'publish') {

        $status = ($action === 'publish') ? 'active' : 'draft';

        // ── Sanitise inputs ──
        $title        = trim($body['title']        ?? '');
        $description  = trim($body['description']  ?? '');
        $category     = trim($body['category']     ?? '');
        $difficulty   = in_array($body['difficulty'] ?? '', ['easy', 'medium', 'hard'])
                        ? $body['difficulty'] : 'medium';
        $duration     = max(1, (int)($body['duration']     ?? 0));
        $totalMarks   = max(1, (int)($body['totalMarks']   ?? 0));
        $passingMarks = max(1, (int)($body['passingMarks'] ?? 0));
        $maxAttempts  = max(1, (int)($body['maxAttempts']  ?? 1));
        $instructions = trim($body['instructions'] ?? '');

        $availableFrom  = !empty($body['availableFrom'])
                          ? date('Y-m-d H:i:s', strtotime($body['availableFrom'])) : null;
        $availableUntil = !empty($body['availableUntil'])
                          ? date('Y-m-d H:i:s', strtotime($body['availableUntil'])) : null;

        $settings = $body['settings'] ?? [];
        $showResultsImmediately = !empty($settings['showResultsImmediately']) ? 1 : 0;
        $showCorrectAnswers     = !empty($settings['showCorrectAnswers'])     ? 1 : 0;
        $randomizeQuestions     = !empty($settings['randomizeQuestions'])     ? 1 : 0;
        $randomizeOptions       = !empty($settings['randomizeOptions'])       ? 1 : 0;

        $questions      = $body['questions']      ?? [];
        $correctAnswers = $body['correctAnswers'] ?? [];

        // ── Server-side validation for publish ──
        if ($action === 'publish') {
            if (!$title) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Assessment title is required.']);
                exit;
            }
            if (!$category) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Category is required.']);
                exit;
            }
            if (empty($questions)) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'At least one question is required.']);
                exit;
            }
            if ($passingMarks > $totalMarks) {
                http_response_code(422);
                echo json_encode(['success' => false, 'error' => 'Passing marks cannot exceed total marks.']);
                exit;
            }
            foreach ($questions as $q) {
                $qid = $q['id'];
                if (empty($correctAnswers[$qid])) {
                    http_response_code(422);
                    echo json_encode(['success' => false, 'error' => "Question {$qid} is missing a correct answer."]);
                    exit;
                }
            }
        }

        // ── Begin transaction (mysqli) ──
        $conn->begin_transaction();

        // ── Insert into assessments ──
        // Types: s=string, i=int
        // Order must match the ? placeholders exactly.
        $assessmentResult = safePreparedQuery(
            $conn,
            "INSERT INTO assessments (
                title, description, created_by, category, difficulty,
                duration_minutes, total_marks, passing_marks, max_attempts,
                show_results_immediately, show_correct_answers,
                randomize_questions, randomize_options,
                available_from, available_until,
                instructions, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            "ssisssiiiiiiiisss",
            [
                $title,
                $description,
                $teacher_id,
                $category,
                $difficulty,
                $duration,
                $totalMarks,
                $passingMarks,
                $maxAttempts,
                $showResultsImmediately,
                $showCorrectAnswers,
                $randomizeQuestions,
                $randomizeOptions,
                $availableFrom,
                $availableUntil,
                $instructions,
                $status,
            ]
        );

        if (!$assessmentResult['success']) {
            $conn->rollback();
            error_log('create-assessment.php: assessment insert failed');
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to save assessment. Please try again.']);
            exit;
        }

        $assessmentId = (int) $assessmentResult['insert_id'];

        // ── Insert questions one by one ──
        foreach ($questions as $order => $q) {
            $qid     = $q['id'];
            $options = $q['options'] ?? ['', '', '', ''];
            $correct = strtolower(trim($correctAnswers[$qid] ?? ''));
            $marks   = max(1, (int)($q['marks'] ?? 1));

            $qResult = safePreparedQuery(
                $conn,
                "INSERT INTO questions (
                    assessment_id, question_type, question_text, marks,
                    option_a, option_b, option_c, option_d,
                    correct_answer, question_order
                ) VALUES (?, 'mcq', ?, ?, ?, ?, ?, ?, ?, ?)",
                "isisssssi",
                [
                    $assessmentId,
                    trim($q['text'] ?? ''),
                    $marks,
                    trim($options[0] ?? ''),
                    trim($options[1] ?? ''),
                    trim($options[2] ?? ''),
                    trim($options[3] ?? ''),
                    $correct,
                    $order + 1,
                ]
            );

            if (!$qResult['success']) {
                $conn->rollback();
                error_log("create-assessment.php: question insert failed for order " . ($order + 1));
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to save questions. Please try again.']);
                exit;
            }
        }

        // ── All good — commit ──
        $conn->commit();

        echo json_encode([
            'success'       => true,
            'assessment_id' => $assessmentId,
            'status'        => $status,
            'message'       => $action === 'publish'
                               ? 'Assessment published successfully.'
                               : 'Draft saved successfully.',
        ]);
        exit;
    }

    // Unknown action
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

// ============================================================
// PAGE RENDER STARTS HERE
// Everything below is sent to the browser as HTML.
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Assessment - Placement Portal</title>

    <!-- PDF.js for PDF text extraction -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <!-- Mammoth.js for Word document text extraction -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/mammoth/1.6.0/mammoth.browser.min.js"></script>

    <style>
        :root {
            --font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --color-primary: #234C6A;
            --color-teacher-primary: #2E073F;
            --color-teacher-secondary: #AD49E1;
            --color-text: #2d3748;
            --color-text-light: #718096;
            --color-bg: #D3DAD9;
            --color-white: #ffffff;
            --color-border: #e2e8f0;
            --color-success: #48bb78;
            --color-error: #f56565;
            --shadow-md: 0 4px 20px rgba(0,0,0,0.08);
            --border-radius: 10px;
            --border-radius-lg: 20px;
            --transition: all 0.3s ease;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-family);
            background: var(--color-bg);
            min-height: 100vh;
            color: var(--color-text);
            padding-top: 71px;
            overflow-x: hidden;
        }

        /* ── NAVBAR ── */
        .navbar {
            background: var(--color-teacher-primary);
            box-shadow: 0 2px 10px rgba(0,0,0,0.08);
            padding: 12px 28px;
            display: flex; align-items: center; justify-content: space-between;
            position: fixed; top: 0; left: 0; right: 0;
            z-index: 1000;
            border-bottom: 3px solid var(--color-teacher-primary);
        }
        .navbar-brand {
            display: flex; align-items: center; gap: 12px;
            font-size: 20px; font-weight: 700; color: white; text-decoration: none;
        }
        .brand-logo {
            width: 45px; height: 45px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-weight: bold; font-size: 20px;
        }
        .btn-back {
            padding: 10px 24px;
            background: white; color: var(--color-teacher-primary);
            border: 2px solid var(--color-teacher-primary);
            border-radius: 10px; font-weight: 600; font-size: 14px;
            cursor: pointer; transition: var(--transition);
            text-decoration: none; display: flex; align-items: center; gap: 8px;
        }
        .btn-back:hover { background: var(--color-teacher-primary); color: white; transform: translateY(-2px); }

        /* ── LAYOUT ── */
        .container { max-width: 1200px; margin: 0 auto; padding: 30px; }

        .page-header {
            background: white; border-radius: var(--border-radius-lg);
            padding: 30px; margin-bottom: 30px; box-shadow: var(--shadow-md);
        }
        .page-title { font-size: 32px; font-weight: 700; color: var(--color-text); margin-bottom: 8px; }
        .page-description { font-size: 16px; color: var(--color-text-light); }

        .form-section {
            background: white; border-radius: var(--border-radius-lg);
            padding: 30px; margin-bottom: 20px; box-shadow: var(--shadow-md);
        }
        .section-title {
            font-size: 20px; font-weight: 700; color: var(--color-text);
            margin-bottom: 20px; display: flex; align-items: center; gap: 10px;
        }
        .section-icon {
            width: 40px; height: 40px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            border-radius: 10px;
            display: flex; align-items: center; justify-content: center;
            color: white; font-size: 20px;
        }

        /* ── FORM ELEMENTS ── */
        .form-group { margin-bottom: 20px; }
        .form-label { display: block; font-size: 14px; font-weight: 600; color: var(--color-text); margin-bottom: 8px; }
        .form-label .required { color: var(--color-error); margin-left: 4px; }
        .form-input {
            width: 100%; padding: 12px 16px;
            border: 2px solid var(--color-border); border-radius: var(--border-radius);
            font-size: 14px; transition: var(--transition); background: white;
            font-family: var(--font-family);
        }
        .form-input:focus { outline: none; border-color: var(--color-teacher-primary); box-shadow: 0 0 0 3px rgba(173,73,225,0.1); }
        .form-textarea { min-height: 100px; resize: vertical; }
        .form-select {
            width: 100%; padding: 12px 16px;
            border: 2px solid var(--color-border); border-radius: var(--border-radius);
            font-size: 14px; background: white; cursor: pointer; transition: var(--transition);
            font-family: var(--font-family);
        }
        .form-select:focus { outline: none; border-color: var(--color-teacher-primary); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; }

        /* ── UPLOAD ── */
        .upload-area {
            border: 3px dashed var(--color-border); border-radius: 16px;
            padding: 40px; text-align: center; transition: var(--transition);
            cursor: pointer; background: #f7fafc;
        }
        .upload-area:hover  { border-color: var(--color-teacher-secondary); background: rgba(173,73,225,0.05); }
        .upload-area.dragover { border-color: var(--color-teacher-secondary); background: rgba(173,73,225,0.1); transform: scale(1.02); }
        .upload-icon  { font-size: 64px; margin-bottom: 20px; opacity: 0.5; }
        .upload-text  { font-size: 18px; font-weight: 600; color: var(--color-text); margin-bottom: 8px; }
        .upload-hint  { font-size: 14px; color: var(--color-text-light); margin-bottom: 20px; }
        .upload-button {
            display: inline-block; padding: 12px 30px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            color: white; border: none; border-radius: var(--border-radius);
            font-weight: 700; font-size: 14px; cursor: pointer; transition: var(--transition);
        }
        .upload-button:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(173,73,225,0.4); }
        .upload-file-types { margin-top: 12px; font-size: 12px; color: var(--color-text-light); }
        .upload-file-types span { display: inline-block; background: #edf2f7; padding: 3px 8px; border-radius: 4px; margin: 2px; font-weight: 600; }
        #fileInput { display: none; }

        /* ── UPLOADED FILE ── */
        .uploaded-file {
            display: none; padding: 20px; background: #f7fafc;
            border-radius: 12px; margin-top: 20px; border: 2px solid var(--color-border);
        }
        .uploaded-file.active { display: flex; align-items: center; justify-content: space-between; }
        .file-info  { display: flex; align-items: center; gap: 12px; }
        .file-icon  { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; color: white; font-size: 20px; }
        .file-icon.pdf-icon  { background: #e53e3e; }
        .file-icon.docx-icon { background: #3182ce; }
        .file-details { flex: 1; }
        .file-name  { font-size: 14px; font-weight: 600; color: var(--color-text); }
        .file-size  { font-size: 12px; color: var(--color-text-light); }
        .remove-file {
            padding: 8px 16px; background: #fff; color: var(--color-error);
            border: 2px solid var(--color-error); border-radius: 8px;
            font-weight: 600; font-size: 12px; cursor: pointer; transition: var(--transition);
        }
        .remove-file:hover { background: var(--color-error); color: white; }

        /* ── PARSING STATUS ── */
        .parsing-status {
            display: none; padding: 24px;
            background: linear-gradient(135deg, #f0f4ff 0%, #faf0ff 100%);
            border-radius: 12px; margin-top: 20px; border: 2px solid #c3dafe; text-align: center;
        }
        .parsing-status.active { display: block; }
        .parsing-steps { margin-top: 16px; text-align: left; display: inline-block; }
        .parsing-step {
            display: flex; align-items: center; gap: 10px;
            padding: 8px 0; font-size: 14px; color: var(--color-text-light); transition: var(--transition);
        }
        .parsing-step.active { color: var(--color-teacher-primary); font-weight: 600; }
        .parsing-step.done   { color: var(--color-success); }
        .step-icon { width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .spinner {
            display: inline-block; width: 20px; height: 20px;
            border: 3px solid #e2e8f0; border-top-color: var(--color-teacher-primary);
            border-radius: 50%; animation: spin 0.8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* ── ERROR BANNER ── */
        .error-banner {
            display: none; padding: 16px 20px; background: #fff5f5;
            border: 2px solid var(--color-error); border-radius: 12px;
            margin-top: 20px; color: var(--color-error); font-weight: 600;
        }
        .error-banner.active { display: flex; align-items: flex-start; gap: 10px; }
        .error-banner-detail { font-size: 13px; font-weight: normal; color: #c53030; margin-top: 4px; }

        /* ── TOAST ── */
        .toast {
            position: fixed; bottom: 30px; right: 30px; z-index: 9999;
            padding: 16px 24px; border-radius: 12px; font-weight: 600; font-size: 14px;
            color: white; box-shadow: 0 8px 30px rgba(0,0,0,0.2);
            transform: translateY(100px); opacity: 0;
            transition: transform 0.3s ease, opacity 0.3s ease;
            max-width: 380px;
        }
        .toast.show    { transform: translateY(0); opacity: 1; }
        .toast.success { background: #276749; }
        .toast.error   { background: #c53030; }
        .toast.info    { background: var(--color-teacher-primary); }

        /* ── QUESTIONS PREVIEW ── */
        .questions-preview { display: none; margin-top: 20px; }
        .questions-preview.active { display: block; }
        .preview-header {
            padding: 20px; background: #c6f6d5; border-radius: 12px;
            margin-bottom: 20px; border: 2px solid var(--color-success);
            display: flex; align-items: center; justify-content: space-between;
        }
        .preview-title    { font-size: 18px; font-weight: 700; color: #22543d; }
        .preview-subtitle { font-size: 14px; color: #276749; }
        .btn-reparse {
            padding: 8px 16px; background: white; color: #276749;
            border: 2px solid #276749; border-radius: 8px;
            font-size: 13px; font-weight: 600; cursor: pointer; transition: var(--transition);
        }
        .btn-reparse:hover { background: #276749; color: white; }

        .question-card {
            padding: 20px; background: white; border: 2px solid var(--color-border);
            border-radius: 12px; margin-bottom: 15px; transition: var(--transition);
        }
        .question-card:hover { border-color: var(--color-teacher-secondary); box-shadow: 0 4px 12px rgba(173,73,225,0.15); }
        .question-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px; }
        .question-number { font-size: 14px; font-weight: 700; color: var(--color-teacher-secondary); }
        .question-actions { display: flex; gap: 8px; }
        .btn-edit-q {
            padding: 6px 14px; background: #ebf8ff; color: var(--color-primary);
            border: 1px solid #bee3f8; border-radius: 6px;
            font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition);
        }
        .btn-edit-q:hover { background: var(--color-primary); color: white; }
        .btn-delete-q {
            padding: 6px 14px; background: #fff5f5; color: var(--color-error);
            border: 1px solid #fed7d7; border-radius: 6px;
            font-size: 12px; font-weight: 600; cursor: pointer; transition: var(--transition);
        }
        .btn-delete-q:hover { background: var(--color-error); color: white; }
        .question-text { font-size: 15px; font-weight: 600; color: var(--color-text); margin-bottom: 12px; line-height: 1.5; }
        .options-list  { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .option-item   {
            display: flex; align-items: flex-start; gap: 8px;
            padding: 8px 12px; background: #f7fafc; border-radius: 8px;
            border: 1px solid var(--color-border); font-size: 14px;
        }
        .option-label { font-weight: 700; color: var(--color-teacher-secondary); min-width: 20px; }

        /* ── CORRECT ANSWERS ── */
        .correct-answers-section { display: none; margin-top: 24px; padding-top: 24px; border-top: 2px dashed var(--color-border); }
        .correct-answers-section.active { display: block; }
        .answer-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 12px; margin-top: 16px; }
        .answer-input-card {
            display: flex; align-items: center; gap: 12px;
            padding: 14px 16px; background: #f7fafc; border: 2px solid var(--color-border);
            border-radius: 10px; transition: var(--transition);
        }
        .answer-input-card:hover { border-color: var(--color-teacher-secondary); }
        .answer-q-number { font-size: 13px; font-weight: 700; color: var(--color-teacher-secondary); white-space: nowrap; }
        .answer-select {
            flex: 1; padding: 8px 12px; border: 2px solid var(--color-border);
            border-radius: 8px; font-size: 13px; background: white; cursor: pointer;
            font-family: var(--font-family);
        }
        .answer-select:focus { outline: none; border-color: var(--color-teacher-secondary); }
        .answer-select.answered { border-color: var(--color-success); background: #f0fff4; }

        /* ── MANUAL ADD ── */
        .manual-section { border-top: 2px dashed var(--color-border); margin-top: 24px; padding-top: 24px; }
        .btn-add-manual {
            display: flex; align-items: center; gap: 8px;
            padding: 10px 20px; background: white;
            border: 2px solid var(--color-teacher-primary); color: var(--color-teacher-primary);
            border-radius: var(--border-radius); font-weight: 600; font-size: 14px;
            cursor: pointer; transition: var(--transition);
        }
        .btn-add-manual:hover { background: var(--color-teacher-primary); color: white; }

        /* ── MODAL ── */
        .modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.5); z-index: 2000;
            align-items: center; justify-content: center;
        }
        .modal-overlay.active { display: flex; }
        .modal {
            background: white; border-radius: var(--border-radius-lg);
            padding: 30px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .modal-title { font-size: 20px; font-weight: 700; margin-bottom: 20px; color: var(--color-text); }
        .modal-actions { display: flex; gap: 12px; justify-content: flex-end; margin-top: 20px; }
        .btn-modal-cancel {
            padding: 10px 20px; background: white; color: var(--color-text-light);
            border: 2px solid var(--color-border); border-radius: var(--border-radius);
            font-weight: 600; cursor: pointer; transition: var(--transition);
        }
        .btn-modal-cancel:hover { border-color: var(--color-error); color: var(--color-error); }
        .btn-modal-save {
            padding: 10px 24px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            color: white; border: none; border-radius: var(--border-radius);
            font-weight: 700; cursor: pointer; transition: var(--transition);
        }
        .btn-modal-save:hover { transform: translateY(-1px); box-shadow: 0 4px 15px rgba(173,73,225,0.4); }
        .modal-option-row { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; }
        .option-prefix { width: 28px; font-weight: 700; color: var(--color-teacher-secondary); flex-shrink: 0; text-align: center; }

        /* ── SETTINGS TOGGLES ── */
        .toggle-row {
            display: flex; align-items: center; justify-content: space-between;
            padding: 12px 0; border-bottom: 1px solid var(--color-border);
        }
        .toggle-row:last-child { border-bottom: none; }
        .toggle-label { font-size: 14px; font-weight: 600; color: var(--color-text); }
        .toggle-desc  { font-size: 12px; color: var(--color-text-light); margin-top: 2px; }
        .toggle-switch { position: relative; display: inline-block; width: 46px; height: 26px; }
        .toggle-switch input { opacity: 0; width: 0; height: 0; }
        .toggle-slider {
            position: absolute; cursor: pointer; inset: 0;
            background: #cbd5e0; border-radius: 26px; transition: var(--transition);
        }
        .toggle-slider:before {
            position: absolute; content: ''; height: 20px; width: 20px;
            left: 3px; bottom: 3px; background: white; border-radius: 50%; transition: var(--transition);
        }
        .toggle-switch input:checked + .toggle-slider { background: var(--color-teacher-secondary); }
        .toggle-switch input:checked + .toggle-slider:before { transform: translateX(20px); }

        /* ── ACTION BAR ── */
        .action-section {
            background: white; border-radius: var(--border-radius-lg);
            padding: 24px 30px; margin-bottom: 20px; box-shadow: var(--shadow-md);
            display: flex; align-items: center; justify-content: space-between;
        }
        .action-info { font-size: 14px; color: var(--color-text-light); }
        .action-buttons { display: flex; gap: 12px; }
        .btn-save-draft {
            padding: 12px 30px; background: white; color: var(--color-text);
            border: 2px solid var(--color-border); border-radius: var(--border-radius);
            font-weight: 600; font-size: 14px; cursor: pointer; transition: var(--transition);
        }
        .btn-save-draft:hover { border-color: var(--color-teacher-secondary); color: var(--color-teacher-secondary); }
        .btn-save-draft:disabled, .btn-publish:disabled { opacity: 0.6; cursor: not-allowed; transform: none !important; }
        .btn-publish {
            padding: 12px 30px;
            background: linear-gradient(135deg, var(--color-teacher-primary) 0%, var(--color-teacher-secondary) 100%);
            color: white; border: none; border-radius: var(--border-radius);
            font-weight: 700; font-size: 14px; cursor: pointer; transition: var(--transition);
        }
        .btn-publish:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(173,73,225,0.4); }

        /* ── RESPONSIVE ── */
        @media (max-width: 768px) {
            .navbar    { padding: 15px; }
            .container { padding: 15px; }
            .form-section { padding: 20px; }
            .form-grid    { grid-template-columns: 1fr; }
            .options-list { grid-template-columns: 1fr; }
            .action-section { flex-direction: column; gap: 16px; }
            .action-buttons { width: 100%; flex-direction: column; }
            .btn-save-draft, .btn-publish { width: 100%; }
            .answer-grid { grid-template-columns: 1fr; }
            .toast { right: 15px; left: 15px; bottom: 15px; }
        }
    </style>
</head>
<body>

<!-- NAVIGATION -->
<nav class="navbar">
    <a href="teacher-dashboard.php" class="navbar-brand">
        <div class="brand-logo">P</div>
        <span>Placement Portal</span>
    </a>
    <div>
        <a href="teacher-dashboard.php" class="btn-back">← Back to Dashboard</a>
    </div>
</nav>

<!-- MAIN CONTENT -->
<div class="container">

    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">Create New Assessment</h1>
        <p class="page-description">Upload a PDF or Word document to automatically extract questions, or add them manually.</p>
    </div>

    <!-- Basic Information -->
    <div class="form-section">
        <h2 class="section-title"><div class="section-icon">📝</div> Basic Information</h2>

        <div class="form-group">
            <label class="form-label">Assessment Title <span class="required">*</span></label>
            <input type="text" class="form-input" id="assessmentTitle" placeholder="e.g., Quantitative Aptitude - Set 1" required>
        </div>

        <div class="form-group">
            <label class="form-label">Description</label>
            <textarea class="form-input form-textarea" id="assessmentDescription" placeholder="Brief description of this assessment..."></textarea>
        </div>

        <div class="form-grid">
            <div class="form-group">
                <label class="form-label">Category <span class="required">*</span></label>
                <select class="form-select" id="assessmentCategory" required>
                    <option value="">Select Category</option>
                    <option value="aptitude">Aptitude</option>
                    <option value="technical">Technical</option>
                    <option value="coding">Coding</option>
                    <option value="reasoning">Reasoning</option>
                    <option value="english">English</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Difficulty Level <span class="required">*</span></label>
                <select class="form-select" id="difficultyLevel" required>
                    <option value="">Select Difficulty</option>
                    <option value="easy">Easy</option>
                    <option value="medium">Medium</option>
                    <option value="hard">Hard</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Duration (minutes) <span class="required">*</span></label>
                <input type="number" class="form-input" id="duration" min="1" max="180" placeholder="45" required>
            </div>

            <div class="form-group">
                <label class="form-label">Total Marks <span class="required">*</span></label>
                <input type="number" class="form-input" id="totalMarks" min="1" placeholder="100" required>
            </div>

            <div class="form-group">
                <label class="form-label">Passing Marks <span class="required">*</span></label>
                <input type="number" class="form-input" id="passingMarks" min="1" placeholder="40" required>
            </div>

            <div class="form-group">
                <label class="form-label">Max Attempts</label>
                <input type="number" class="form-input" id="maxAttempts" min="1" value="1" placeholder="1">
            </div>
        </div>
    </div>

    <!-- Assessment Settings -->
    <div class="form-section">
        <h2 class="section-title"><div class="section-icon">⚙️</div> Assessment Settings</h2>

        <div class="toggle-row">
            <div>
                <div class="toggle-label">Show Results Immediately</div>
                <div class="toggle-desc">Students see their score right after submission</div>
            </div>
            <label class="toggle-switch">
                <input type="checkbox" id="showResultsImmediately" checked>
                <span class="toggle-slider"></span>
            </label>
        </div>

        <div class="toggle-row">
            <div>
                <div class="toggle-label">Show Correct Answers</div>
                <div class="toggle-desc">Reveal correct answers after submission</div>
            </div>
            <label class="toggle-switch">
                <input type="checkbox" id="showCorrectAnswers" checked>
                <span class="toggle-slider"></span>
            </label>
        </div>

        <div class="toggle-row">
            <div>
                <div class="toggle-label">Randomize Questions</div>
                <div class="toggle-desc">Shuffle question order for each attempt</div>
            </div>
            <label class="toggle-switch">
                <input type="checkbox" id="randomizeQuestions">
                <span class="toggle-slider"></span>
            </label>
        </div>

        <div class="toggle-row">
            <div>
                <div class="toggle-label">Randomize Options</div>
                <div class="toggle-desc">Shuffle answer options for each question</div>
            </div>
            <label class="toggle-switch">
                <input type="checkbox" id="randomizeOptions">
                <span class="toggle-slider"></span>
            </label>
        </div>

        <div class="form-grid" style="margin-top:20px;">
            <div class="form-group">
                <label class="form-label">Available From</label>
                <input type="datetime-local" class="form-input" id="availableFrom">
            </div>
            <div class="form-group">
                <label class="form-label">Available Until</label>
                <input type="datetime-local" class="form-input" id="availableUntil">
            </div>
        </div>

        <div class="form-group">
            <label class="form-label">Instructions for Students</label>
            <textarea class="form-input form-textarea" id="instructions" placeholder="Any special instructions before students begin..."></textarea>
        </div>
    </div>

    <!-- Upload Document -->
    <div class="form-section">
        <h2 class="section-title"><div class="section-icon">📄</div> Upload Questions Document</h2>

        <div class="upload-area" id="uploadArea">
            <div class="upload-icon">📤</div>
            <div class="upload-text">Drag and drop your document here</div>
            <div class="upload-hint">or click to browse (Max 10MB)</div>
            <button class="upload-button" onclick="document.getElementById('fileInput').click()">Choose File</button>
            <div class="upload-file-types">Supported formats: <span>PDF</span> <span>DOCX</span></div>
            <input type="file" id="fileInput"
                   accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                   onchange="handleFileSelect(event)">
        </div>

        <!-- Uploaded File Display -->
        <div class="uploaded-file" id="uploadedFileDisplay">
            <div class="file-info">
                <div class="file-icon" id="fileTypeIcon">📄</div>
                <div class="file-details">
                    <div class="file-name" id="fileName"></div>
                    <div class="file-size" id="fileSize"></div>
                </div>
            </div>
            <button class="remove-file" onclick="removeFile()">Remove</button>
        </div>

        <!-- Parsing Status -->
        <div class="parsing-status" id="parsingStatus">
            <div style="display:flex;align-items:center;gap:10px;justify-content:center;">
                <span class="spinner"></span>
                <span style="font-size:16px;font-weight:600;color:#2d3748;">Processing document...</span>
            </div>
            <div class="parsing-steps">
                <div class="parsing-step" id="step1"><div class="step-icon">⬜</div> Reading file content</div>
                <div class="parsing-step" id="step2"><div class="step-icon">⬜</div> Extracting text</div>
                <div class="parsing-step" id="step3"><div class="step-icon">⬜</div> Analysing questions with AI</div>
                <div class="parsing-step" id="step4"><div class="step-icon">⬜</div> Preparing preview</div>
            </div>
        </div>

        <!-- Error Banner -->
        <div class="error-banner" id="errorBanner">
            <span>⚠️</span>
            <div>
                <div id="errorMessage">Could not extract questions from the document.</div>
                <div class="error-banner-detail" id="errorDetail"></div>
            </div>
        </div>

        <!-- Questions Preview -->
        <div class="questions-preview" id="questionsPreview">
            <div class="preview-header">
                <div>
                    <div class="preview-title">✓ Questions Extracted Successfully!</div>
                    <div class="preview-subtitle" id="questionCount"></div>
                </div>
                <button class="btn-reparse" onclick="removeFile()">Upload Different File</button>
            </div>
            <div id="questionsList"></div>
        </div>

        <!-- Correct Answers Section -->
        <div class="correct-answers-section" id="correctAnswersSection">
            <h3 class="section-title">
                <div class="section-icon">✅</div>
                Specify Correct Answers
            </h3>
            <p style="font-size:14px;color:var(--color-text-light);margin-bottom:16px;">
                Select the correct answer for each question. Questions with pre-detected answers are pre-filled.
            </p>
            <div class="answer-grid" id="correctAnswersList"></div>
        </div>

        <!-- Manual Add -->
        <div class="manual-section">
            <button class="btn-add-manual" onclick="openAddQuestionModal()">
                <span style="font-size:18px;">+</span> Add Question Manually
            </button>
        </div>
    </div>

    <!-- Action Bar -->
    <div class="action-section">
        <div class="action-info">
            <strong>Note:</strong> Save a draft anytime and publish when you're ready.
        </div>
        <div class="action-buttons">
            <button class="btn-save-draft" id="btnDraft"   onclick="submitAssessment('save_draft')">Save as Draft</button>
            <button class="btn-publish"    id="btnPublish" onclick="submitAssessment('publish')">Publish Assessment</button>
        </div>
    </div>

</div><!-- /container -->

<!-- Edit / Add Question Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal">
        <div class="modal-title" id="modalTitle">Edit Question</div>

        <div class="form-group">
            <label class="form-label">Question Text <span class="required">*</span></label>
            <textarea class="form-input form-textarea" id="modalQuestionText"
                      placeholder="Enter your question..." style="min-height:80px;"></textarea>
        </div>

        <div class="form-group">
            <label class="form-label">Answer Options <span class="required">*</span></label>
            <div class="modal-option-row"><span class="option-prefix">A</span><input type="text" class="form-input" id="modalOptionA" placeholder="Option A"></div>
            <div class="modal-option-row"><span class="option-prefix">B</span><input type="text" class="form-input" id="modalOptionB" placeholder="Option B"></div>
            <div class="modal-option-row"><span class="option-prefix">C</span><input type="text" class="form-input" id="modalOptionC" placeholder="Option C"></div>
            <div class="modal-option-row"><span class="option-prefix">D</span><input type="text" class="form-input" id="modalOptionD" placeholder="Option D"></div>
        </div>

        <div class="form-group">
            <label class="form-label">Correct Answer</label>
            <select class="form-select" id="modalCorrectAnswer">
                <option value="">Select correct answer</option>
                <option value="a">A</option>
                <option value="b">B</option>
                <option value="c">C</option>
                <option value="d">D</option>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label">Marks for this question</label>
            <input type="number" class="form-input" id="modalMarks" value="1" min="1">
        </div>

        <div class="modal-actions">
            <button class="btn-modal-cancel" onclick="closeModal()">Cancel</button>
            <button class="btn-modal-save"   onclick="saveModalQuestion()">Save Question</button>
        </div>
    </div>
</div>

<!-- Toast notification -->
<div class="toast" id="toast"></div>

<script>
    // ── PDF.js worker ──
    if (typeof pdfjsLib !== 'undefined') {
        pdfjsLib.GlobalWorkerOptions.workerSrc =
            'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }

    // ── Global state ──
    let assessmentData = {
        title: '', description: '', category: '', difficulty: '',
        duration: 0, totalMarks: 0, passingMarks: 0, maxAttempts: 1,
        questions: [], correctAnswers: {},
        settings: { showResultsImmediately: true, showCorrectAnswers: true, randomizeQuestions: false, randomizeOptions: false },
        availableFrom: null, availableUntil: null, instructions: ''
    };

    let uploadedFile      = null;
    let editingQuestionId = null;

    // ── Toast ──
    function showToast(message, type = 'info', duration = 4000) {
        const toast = document.getElementById('toast');
        toast.textContent = message;
        toast.className = `toast ${type} show`;
        setTimeout(() => toast.classList.remove('show'), duration);
    }

    // ── Drag & drop ──
    const uploadArea = document.getElementById('uploadArea');
    ['dragenter','dragover','dragleave','drop'].forEach(ev => {
        uploadArea.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); });
        document.body.addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); });
    });
    ['dragenter','dragover'].forEach(ev => uploadArea.addEventListener(ev, () => uploadArea.classList.add('dragover')));
    ['dragleave','drop'].forEach(ev =>     uploadArea.addEventListener(ev, () => uploadArea.classList.remove('dragover')));
    uploadArea.addEventListener('drop', e => {
        const files = e.dataTransfer.files;
        if (files.length > 0) handleFile(files[0]);
    });

    function handleFileSelect(event) {
        if (event.target.files[0]) handleFile(event.target.files[0]);
    }

    function handleFile(file) {
        hideError();
        const name   = file.name.toLowerCase();
        const isPDF  = file.type === 'application/pdf' || name.endsWith('.pdf');
        const isDOCX = file.type === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' || name.endsWith('.docx');

        if (name.endsWith('.doc')) {
            showError('Legacy .doc format not supported.', 'Save the file as .docx or export as PDF and re-upload.');
            return;
        }
        if (!isPDF && !isDOCX) {
            showError('Unsupported file type.', 'Please upload a PDF (.pdf) or Word document (.docx).');
            return;
        }
        if (file.size > 10 * 1024 * 1024) {
            showError('File too large.', 'Maximum file size is 10MB.');
            return;
        }

        uploadedFile = file;
        showFileInfo(file, isPDF ? 'pdf' : 'docx');
        if (isPDF) extractFromPDF(file);
        else       extractFromDOCX(file);
    }

    function showFileInfo(file, type) {
        document.getElementById('fileName').textContent = file.name;
        document.getElementById('fileSize').textContent = formatFileSize(file.size);
        const icon = document.getElementById('fileTypeIcon');
        icon.textContent = type === 'pdf' ? '📕' : '📘';
        icon.className   = 'file-icon ' + (type === 'pdf' ? 'pdf-icon' : 'docx-icon');
        document.getElementById('uploadedFileDisplay').classList.add('active');
        uploadArea.style.display = 'none';
    }

    function removeFile() {
        uploadedFile = null;
        document.getElementById('uploadedFileDisplay').classList.remove('active');
        document.getElementById('questionsPreview').classList.remove('active');
        document.getElementById('parsingStatus').classList.remove('active');
        document.getElementById('correctAnswersSection').classList.remove('active');
        uploadArea.style.display = 'block';
        document.getElementById('fileInput').value = '';
        hideError();
        assessmentData.questions      = [];
        assessmentData.correctAnswers = {};
    }

    // ── Step indicators ──
    function setStep(n, state) {
        const el   = document.getElementById('step' + n);
        const icon = el.querySelector('.step-icon');
        el.className     = 'parsing-step ' + state;
        icon.textContent = state === 'active' ? '🔄' : state === 'done' ? '✅' : '⬜';
    }
    function resetSteps() { [1,2,3,4].forEach(i => setStep(i, '')); }
    function showParsingStatus() {
        resetSteps();
        document.getElementById('parsingStatus').classList.add('active');
        document.getElementById('questionsPreview').classList.remove('active');
        document.getElementById('correctAnswersSection').classList.remove('active');
    }
    function hideParsingStatus() {
        document.getElementById('parsingStatus').classList.remove('active');
    }

    // ── PDF extraction ──
    async function extractFromPDF(file) {
        showParsingStatus(); setStep(1, 'active');
        try {
            const buffer = await file.arrayBuffer();
            setStep(1, 'done'); setStep(2, 'active');

            const pdf = await pdfjsLib.getDocument({ data: buffer }).promise;
            let fullText = '';
            for (let i = 1; i <= pdf.numPages; i++) {
                const page    = await pdf.getPage(i);
                const content = await page.getTextContent();
                fullText += content.items.map(item => item.str).join(' ') + '\n';
            }

            setStep(2, 'done'); setStep(3, 'active');
            if (!fullText.trim()) throw new Error('No text found in PDF. The file may be scanned or image-based.');
            await parseTextWithAI(fullText);
        } catch (err) {
            hideParsingStatus();
            showError('Failed to process PDF.', err.message);
        }
    }

    // ── DOCX extraction ──
    async function extractFromDOCX(file) {
        showParsingStatus(); setStep(1, 'active');
        try {
            const buffer   = await file.arrayBuffer();
            setStep(1, 'done'); setStep(2, 'active');

            const result   = await mammoth.extractRawText({ arrayBuffer: buffer });
            const fullText = result.value;

            setStep(2, 'done'); setStep(3, 'active');
            if (!fullText.trim()) throw new Error('No text found in the Word document.');
            await parseTextWithAI(fullText);
        } catch (err) {
            hideParsingStatus();
            showError('Failed to process Word document.', err.message);
        }
    }

    // ── AI question parsing ──
    async function parseTextWithAI(rawText) {
        const prompt = `You are a question extraction assistant. Extract ALL multiple-choice questions from the text below.

Return ONLY a valid JSON array — no markdown fences, no explanation — in this exact format:
[
  {
    "id": 1,
    "text": "The full question text",
    "options": ["Option A text", "Option B text", "Option C text", "Option D text"],
    "correctAnswer": "a"
  }
]

Rules:
- "correctAnswer" must be lowercase "a", "b", "c", or "d". Only set it if the answer is clearly marked in the document (e.g. "Answer: b", "Ans: (c)"). Otherwise set it to null.
- Include ALL questions found, even with inconsistent formatting.
- If a question has fewer than 4 options, fill missing slots with "".
- Clean up OCR artefacts and extra whitespace.
- id values must be sequential starting from 1.

TEXT TO PARSE:
---
${rawText.substring(0, 12000)}
---`;

        try {
            const response = await fetch('https://api.anthropic.com/v1/messages', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    model: 'claude-sonnet-4-20250514',
                    max_tokens: 4000,
                    messages: [{ role: 'user', content: prompt }]
                })
            });

            if (!response.ok) {
                const err = await response.json();
                throw new Error(err.error?.message || 'Claude API error.');
            }

            const data      = await response.json();
            const rawJson   = data.content.map(b => b.text || '').join('');
            const cleaned   = rawJson.replace(/```json|```/gi, '').trim();
            const questions = JSON.parse(cleaned);

            if (!Array.isArray(questions) || questions.length === 0) {
                throw new Error('No questions found. Ensure the document contains MCQ-style questions with labelled options.');
            }

            setStep(3, 'done'); setStep(4, 'active');

            assessmentData.questions      = questions;
            assessmentData.correctAnswers = {};
            questions.forEach(q => {
                if (q.correctAnswer) assessmentData.correctAnswers[q.id] = q.correctAnswer;
            });

            displayQuestions(questions);
            displayCorrectAnswersInputs(questions);
            setStep(4, 'done');
            hideParsingStatus();
            showToast(`${questions.length} questions extracted successfully.`, 'success');

        } catch (err) {
            hideParsingStatus();
            if (err instanceof SyntaxError) {
                showError('AI returned unexpected output.', 'Could not parse questions. Try a different document or add questions manually.');
            } else {
                showError('Question extraction failed.', err.message);
            }
        }
    }

    // ── Render questions ──
    function displayQuestions(questions) {
        const list    = document.getElementById('questionsList');
        const preview = document.getElementById('questionsPreview');
        document.getElementById('questionCount').textContent =
            `${questions.length} question${questions.length !== 1 ? 's' : ''} found`;
        list.innerHTML = '';
        const frag = document.createDocumentFragment();
        questions.forEach((q, idx) => frag.appendChild(createQuestionCard(q, idx)));
        list.appendChild(frag);
        preview.classList.add('active');
    }

    function createQuestionCard(question, index) {
        const card = document.createElement('div');
        card.className = 'question-card';
        card.dataset.questionId = question.id;

        const header = document.createElement('div');
        header.className = 'question-header';

        const num = document.createElement('span');
        num.className   = 'question-number';
        num.textContent = `Question ${index + 1}`;

        const actions = document.createElement('div');
        actions.className = 'question-actions';

        const editBtn = document.createElement('button');
        editBtn.className = 'btn-edit-q';
        editBtn.textContent = 'Edit';
        editBtn.dataset.questionId = question.id;

        const delBtn = document.createElement('button');
        delBtn.className = 'btn-delete-q';
        delBtn.textContent = 'Delete';
        delBtn.dataset.questionId = question.id;

        actions.append(editBtn, delBtn);
        header.append(num, actions);

        const text = document.createElement('div');
        text.className   = 'question-text';
        text.textContent = question.text;

        const optsList = document.createElement('div');
        optsList.className = 'options-list';
        question.options.forEach((opt, i) => {
            if (!opt) return;
            const item = document.createElement('div');
            item.className = 'option-item';
            const lbl = document.createElement('span');
            lbl.className   = 'option-label';
            lbl.textContent = String.fromCharCode(65 + i) + ')';
            item.append(lbl, document.createTextNode(' ' + opt));
            optsList.appendChild(item);
        });

        card.append(header, text, optsList);
        return card;
    }

    document.addEventListener('click', function(e) {
        const qId = e.target.dataset.questionId;
        if (!qId) return;
        if (e.target.classList.contains('btn-edit-q'))   openEditModal(parseInt(qId));
        if (e.target.classList.contains('btn-delete-q')) deleteQuestion(parseInt(qId));
    });

    function displayCorrectAnswersInputs(questions) {
        const list    = document.getElementById('correctAnswersList');
        const section = document.getElementById('correctAnswersSection');
        list.innerHTML = '';

        questions.forEach((q, idx) => {
            const card = document.createElement('div');
            card.className = 'answer-input-card';
            card.id = `answer-card-${q.id}`;

            const label = document.createElement('span');
            label.className   = 'answer-q-number';
            label.textContent = `Q${idx + 1}`;

            const select = document.createElement('select');
            select.className = 'answer-select';
            select.id = `answer-${q.id}`;

            [
                { value: '',  text: 'Select answer' },
                { value: 'a', text: `A) ${q.options[0] || ''}` },
                { value: 'b', text: `B) ${q.options[1] || ''}` },
                { value: 'c', text: `C) ${q.options[2] || ''}` },
                { value: 'd', text: `D) ${q.options[3] || ''}` },
            ].forEach(o => {
                const opt = document.createElement('option');
                opt.value = o.value; opt.textContent = o.text;
                select.appendChild(opt);
            });

            if (assessmentData.correctAnswers[q.id]) {
                select.value = assessmentData.correctAnswers[q.id];
                select.classList.add('answered');
            }

            select.addEventListener('change', () => {
                assessmentData.correctAnswers[q.id] = select.value;
                select.classList.toggle('answered', !!select.value);
            });

            card.append(label, select);
            list.appendChild(card);
        });

        section.classList.add('active');
    }

    function deleteQuestion(questionId) {
        if (!confirm('Delete this question?')) return;
        assessmentData.questions = assessmentData.questions.filter(q => q.id !== questionId);
        delete assessmentData.correctAnswers[questionId];
        document.querySelector(`[data-question-id="${questionId}"]`)?.remove();
        document.getElementById(`answer-card-${questionId}`)?.remove();
        const n = assessmentData.questions.length;
        document.getElementById('questionCount').textContent = `${n} question${n !== 1 ? 's' : ''} found`;
        document.querySelectorAll('.question-card').forEach((card, idx) => {
            card.querySelector('.question-number').textContent = `Question ${idx + 1}`;
        });
    }

    // ── Modal ──
    function openEditModal(questionId) {
        editingQuestionId = questionId;
        const q = assessmentData.questions.find(q => q.id === questionId);
        if (!q) return;
        document.getElementById('modalTitle').textContent          = 'Edit Question';
        document.getElementById('modalQuestionText').value         = q.text;
        document.getElementById('modalOptionA').value              = q.options[0] || '';
        document.getElementById('modalOptionB').value              = q.options[1] || '';
        document.getElementById('modalOptionC').value              = q.options[2] || '';
        document.getElementById('modalOptionD').value              = q.options[3] || '';
        document.getElementById('modalCorrectAnswer').value        = assessmentData.correctAnswers[questionId] || '';
        document.getElementById('modalMarks').value                = q.marks || 1;
        document.getElementById('editModal').classList.add('active');
    }

    function openAddQuestionModal() {
        editingQuestionId = null;
        document.getElementById('modalTitle').textContent   = 'Add New Question';
        document.getElementById('modalQuestionText').value  = '';
        document.getElementById('modalOptionA').value       = '';
        document.getElementById('modalOptionB').value       = '';
        document.getElementById('modalOptionC').value       = '';
        document.getElementById('modalOptionD').value       = '';
        document.getElementById('modalCorrectAnswer').value = '';
        document.getElementById('modalMarks').value         = 1;
        document.getElementById('editModal').classList.add('active');
    }

    function closeModal() { document.getElementById('editModal').classList.remove('active'); }

    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });

    function saveModalQuestion() {
        const text    = document.getElementById('modalQuestionText').value.trim();
        const optA    = document.getElementById('modalOptionA').value.trim();
        const optB    = document.getElementById('modalOptionB').value.trim();
        const optC    = document.getElementById('modalOptionC').value.trim();
        const optD    = document.getElementById('modalOptionD').value.trim();
        const correct = document.getElementById('modalCorrectAnswer').value;
        const marks   = parseInt(document.getElementById('modalMarks').value) || 1;

        if (!text || !optA || !optB) {
            alert('Please fill in the question text and at least two options.');
            return;
        }

        if (editingQuestionId !== null) {
            const q = assessmentData.questions.find(q => q.id === editingQuestionId);
            if (q) {
                q.text = text; q.options = [optA, optB, optC, optD]; q.marks = marks;
                if (correct) assessmentData.correctAnswers[q.id] = correct;
            }
        } else {
            const newId = assessmentData.questions.length > 0
                ? Math.max(...assessmentData.questions.map(q => q.id)) + 1 : 1;
            assessmentData.questions.push({ id: newId, text, options: [optA, optB, optC, optD], marks });
            if (correct) assessmentData.correctAnswers[newId] = correct;
        }

        displayQuestions(assessmentData.questions);
        displayCorrectAnswersInputs(assessmentData.questions);
        document.getElementById('questionsPreview').classList.add('active');
        document.getElementById('correctAnswersSection').classList.add('active');
        closeModal();
    }

    // ── Collect all form data ──
    function collectFormData() {
        assessmentData.title          = document.getElementById('assessmentTitle').value.trim();
        assessmentData.description    = document.getElementById('assessmentDescription').value.trim();
        assessmentData.category       = document.getElementById('assessmentCategory').value;
        assessmentData.difficulty     = document.getElementById('difficultyLevel').value;
        assessmentData.duration       = parseInt(document.getElementById('duration').value);
        assessmentData.totalMarks     = parseInt(document.getElementById('totalMarks').value);
        assessmentData.passingMarks   = parseInt(document.getElementById('passingMarks').value);
        assessmentData.maxAttempts    = parseInt(document.getElementById('maxAttempts').value) || 1;
        assessmentData.instructions   = document.getElementById('instructions').value.trim();
        assessmentData.availableFrom  = document.getElementById('availableFrom').value;
        assessmentData.availableUntil = document.getElementById('availableUntil').value;
        assessmentData.settings = {
            showResultsImmediately: document.getElementById('showResultsImmediately').checked,
            showCorrectAnswers:     document.getElementById('showCorrectAnswers').checked,
            randomizeQuestions:     document.getElementById('randomizeQuestions').checked,
            randomizeOptions:       document.getElementById('randomizeOptions').checked,
        };
        return assessmentData;
    }

    // ── Client-side validation ──
    function validateForm(action) {
        const title    = document.getElementById('assessmentTitle').value.trim();
        const category = document.getElementById('assessmentCategory').value;
        const diff     = document.getElementById('difficultyLevel').value;
        const duration = parseInt(document.getElementById('duration').value);
        const total    = parseInt(document.getElementById('totalMarks').value);
        const passing  = parseInt(document.getElementById('passingMarks').value);

        if (!title)    { alert('Please enter an assessment title.');    return false; }
        if (!category) { alert('Please select a category.');            return false; }
        if (!diff)     { alert('Please select a difficulty level.');    return false; }
        if (!duration || duration <= 0) { alert('Please enter a valid duration.'); return false; }
        if (!total    || total    <= 0) { alert('Please enter valid total marks.'); return false; }
        if (!passing  || passing  <= 0) { alert('Please enter valid passing marks.'); return false; }
        if (passing > total) { alert('Passing marks cannot exceed total marks.'); return false; }

        if (action === 'publish') {
            if (assessmentData.questions.length === 0) {
                alert('Please upload a document or add at least one question manually.');
                return false;
            }
            const missing = assessmentData.questions.filter(q => !assessmentData.correctAnswers[q.id]);
            if (missing.length > 0) {
                alert(`${missing.length} question(s) are missing correct answers.`);
                return false;
            }
        }

        return true;
    }

    // ── POST to PHP backend (same file) ──
    async function submitAssessment(action) {
        if (!validateForm(action)) return;

        const data       = collectFormData();
        const btnDraft   = document.getElementById('btnDraft');
        const btnPublish = document.getElementById('btnPublish');

        btnDraft.disabled = btnPublish.disabled = true;
        btnDraft.textContent   = action === 'save_draft' ? 'Saving...'    : 'Save as Draft';
        btnPublish.textContent = action === 'publish'    ? 'Publishing...' : 'Publish Assessment';

        try {
            const response = await fetch(`create-assessment.php?action=${action}`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                showToast(result.message, 'success', 5000);
                if (action === 'publish') {
                    setTimeout(() => { window.location.href = 'teacher-dashboard.php'; }, 2000);
                }
            } else {
                showToast(result.error || 'Something went wrong.', 'error');
            }

        } catch (err) {
            showToast('Network error. Please check your connection and try again.', 'error');
        } finally {
            btnDraft.disabled = btnPublish.disabled = false;
            btnDraft.textContent   = 'Save as Draft';
            btnPublish.textContent = 'Publish Assessment';
        }
    }

    // ── Error helpers ──
    function showError(msg, detail) {
        document.getElementById('errorMessage').textContent = msg;
        document.getElementById('errorDetail').textContent  = detail || '';
        document.getElementById('errorBanner').classList.add('active');
    }
    function hideError() {
        document.getElementById('errorBanner').classList.remove('active');
    }

    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024, sizes = ['Bytes', 'KB', 'MB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }
</script>
</body>
</html>