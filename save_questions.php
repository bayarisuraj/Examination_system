<?php
session_start();
require_once "../../config/db.php";

ob_clean();
header('Content-Type: application/json');

// Security Check
if (!isset($_SESSION['lecturer_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!$data || empty($data['exam_id']) || empty($data['questions'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid data provided']);
    exit;
}

$exam_id   = (int) $data['exam_id'];
$questions = $data['questions'];
$saved     = 0;

// Verify exam belongs to lecturer
$check = $pdo->prepare("
    SELECT e.id FROM exams e
    JOIN courses c ON e.course_id = c.id
    WHERE e.id = ? AND c.lecturer_id = ?
");
$check->execute([$exam_id, $_SESSION['lecturer_id']]);
if (!$check->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Exam not found or access denied']);
    exit;
}

// Insert Questions
$stmt = $pdo->prepare("
    INSERT INTO exam_questions 
        (exam_id, question_text, question_type, option_a, option_b, option_c, option_d, correct_answer, difficulty, created_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
");

foreach ($questions as $q) {
    $type       = $q['type']       ?? 'mcq';
    $question   = $q['question']   ?? '';
    $answer     = $q['answer']     ?? '';
    $difficulty = $q['difficulty'] ?? 'medium';
    $options    = $q['options']    ?? [];

    $a = $options[0] ?? null;
    $b = $options[1] ?? null;
    $c = $options[2] ?? null;
    $d = $options[3] ?? null;

    $stmt->execute([$exam_id, $question, $type, $a, $b, $c, $d, $answer, $difficulty]);
    $saved++;
}

echo json_encode(['success' => true, 'saved' => $saved]);