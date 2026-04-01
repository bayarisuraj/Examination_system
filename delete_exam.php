<?php
session_start();
require_once "../config/db.php";

// -------------------------
// 1️⃣ Lecturer Authentication
// -------------------------
if(!isset($_SESSION['role']) || $_SESSION['role'] != "lecturer"){
    header("Location: ../auth/login.php");
    exit();
}

$lecturer_id = (int)($_SESSION['user_id'] ?? 0);

// -------------------------
// 2️⃣ Get Exam ID (GET or POST)
// -------------------------
$exam_id = 0;
if(isset($_GET['exam_id'])) $exam_id = (int)$_GET['exam_id'];
elseif(isset($_POST['exam_id'])) $exam_id = (int)$_POST['exam_id'];

if($exam_id <= 0){
    $_SESSION['error'] = "Invalid Exam ID.";
    header("Location: manage_exams.php");
    exit();
}

// -------------------------
// 3️⃣ Verify Lecturer Owns Exam
// -------------------------
$stmt = $conn->prepare("
    SELECT e.id
    FROM exams e
    JOIN courses c ON e.course_id = c.id
    WHERE e.id = ? AND c.lecturer_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $exam_id, $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();
$stmt->close();

if($result->num_rows === 0){
    $_SESSION['error'] = "You are not allowed to delete this exam.";
    header("Location: manage_exams.php");
    exit();
}

// -------------------------
// 4️⃣ Delete Exam Securely
// -------------------------
// If FK ON DELETE CASCADE is set, related exam_questions will be removed automatically
$stmt = $conn->prepare("DELETE FROM exams WHERE id = ? AND lecturer_id = ?");
$stmt->bind_param("ii", $exam_id, $lecturer_id);

if($stmt->execute()){
    $_SESSION['success'] = "Exam deleted successfully.";
} else {
    $_SESSION['error'] = "Error deleting exam: " . $stmt->error;
}

$stmt->close();

// -------------------------
// 5️⃣ Redirect Back to Manage Exams
// -------------------------
header("Location: manage_exams.php");
exit();
?>