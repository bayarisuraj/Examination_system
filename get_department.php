<?php
require_once "../config/db.php"; // ✅ FIXED PATH

header('Content-Type: application/json');

$faculty_id = (int)($_GET['faculty_id'] ?? 0);
$rows = [];

if ($faculty_id > 0) {
    $stmt = $conn->prepare("SELECT id, name FROM departments WHERE faculty_id = ? ORDER BY name");
    $stmt->bind_param("i", $faculty_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

echo json_encode($rows);