<?php
// ============================================================
// insert-questions.php  — ONE-TIME USE
// Directly inserts 20 MCQ questions into the specified assessment.
// Deploy to: Mini-Project/insert-questions.php
// Run once: http://localhost/pta/Mini-Project/insert-questions.php
// DELETE this file after running.
// ============================================================

require_once "config.php";

// ── SET THIS TO YOUR ASSESSMENT ID ──
$assessmentId = 15; // Change if tep test has a different ID

$questions = [
    ["What is the capital of India?", ["Mumbai","New Delhi","Chennai","Kolkata"], "b"],
    ["Which language is primarily used for web page structure?", ["HTML","Python","Java","C++"], "a"],
    ["What does CPU stand for?", ["Central Process Unit","Central Processing Unit","Computer Personal Unit","Control Processing Unit"], "b"],
    ["Which data structure uses FIFO principle?", ["Stack","Queue","Tree","Graph"], "b"],
    ["What is 5 + 7?", ["10","11","12","13"], "c"],
    ["Which device is used to input text into a computer?", ["Monitor","Keyboard","Speaker","Printer"], "b"],
    ["Which protocol is used to transfer web pages?", ["FTP","HTTP","SMTP","TCP"], "b"],
    ["What is the square root of 64?", ["6","7","8","9"], "c"],
    ["Which storage is temporary in a computer?", ["RAM","Hard Disk","SSD","DVD"], "a"],
    ["Which symbol is used to end a statement in C language?", [":",".",";",","], "c"],
    ["What does URL stand for?", ["Uniform Resource Locator","Universal Resource Link","Uniform Reference Link","User Resource Locator"], "a"],
    ["Which company developed Windows OS?", ["Apple","Microsoft","Google","IBM"], "b"],
    ["What is 9 × 6?", ["54","56","52","48"], "a"],
    ["Which device displays output visually?", ["Mouse","Printer","Monitor","Scanner"], "c"],
    ["What is the binary value of decimal 2?", ["10","01","11","00"], "a"],
    ["Which network covers a small geographical area?", ["WAN","LAN","MAN","PAN"], "b"],
    ["What does DBMS stand for?", ["Data Base Management System","Data Backup Management System","Digital Base Management System","Data Binary Management System"], "a"],
    ["What is 15 − 7?", ["6","7","8","9"], "c"],
    ["Which programming language is known for web development?", ["PHP","C","Assembly","COBOL"], "a"],
    ["Which key is used to start a new line in typing?", ["Shift","Enter","Ctrl","Alt"], "b"],
];

// Verify assessment exists
$chk = $conn->prepare("SELECT assessment_id, title FROM assessments WHERE assessment_id = ?");
$chk->bind_param("i", $assessmentId);
$chk->execute();
$row = $chk->get_result()->fetch_assoc();
$chk->close();

if (!$row) {
    die("ERROR: Assessment ID $assessmentId not found. Check your DB and update \$assessmentId.");
}

// Delete existing questions for this assessment
$conn->query("DELETE qo FROM question_options qo INNER JOIN questions q ON q.question_id = qo.question_id WHERE q.assessment_id = $assessmentId");
$conn->query("DELETE FROM questions WHERE assessment_id = $assessmentId");

$inserted = 0;
foreach ($questions as $order => $q) {
    $text        = $q[0];
    $options     = $q[1];
    $correctLetter = strtolower($q[2]); // a/b/c/d
    $correctIndex  = ord($correctLetter) - ord('a'); // 0/1/2/3
    $qOrder      = $order + 1;

    $stmt = $conn->prepare(
        "INSERT INTO questions (assessment_id, question_text, question_type, marks, negative_marks, question_order)
         VALUES (?, ?, 'mcq', 1, 0, ?)"
    );
    $stmt->bind_param("isi", $assessmentId, $text, $qOrder);
    $stmt->execute();
    $questionId = $stmt->insert_id;
    $stmt->close();

    foreach ($options as $oi => $optText) {
        $isCorrect = ($oi === $correctIndex) ? 1 : 0;
        $optOrder  = $oi + 1;
        $ostmt = $conn->prepare(
            "INSERT INTO question_options (question_id, option_text, is_correct, option_order)
             VALUES (?, ?, ?, ?)"
        );
        $ostmt->bind_param("isii", $questionId, $optText, $isCorrect, $optOrder);
        $ostmt->execute();
        $ostmt->close();
    }
    $inserted++;
}

// Update total_marks
$conn->query("UPDATE assessments SET total_marks = $inserted, passing_marks = " . floor($inserted * 0.4) . ", updated_at = NOW() WHERE assessment_id = $assessmentId");

echo "SUCCESS: Inserted $inserted questions into assessment_id=$assessmentId ({$row['title']}).\n";
echo "IMPORTANT: Delete this file now! (Mini-Project/insert-questions.php)\n";
