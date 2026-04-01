<?php
require_once "../config/db.php"; // ✅ FIXED PATH

header('Content-Type: application/json');

$department_id = (int)($_GET['department_id'] ?? 0);
$rows = [];

if ($department_id > 0) {
    $stmt = $conn->prepare("SELECT id, name FROM programs WHERE department_id = ? ORDER BY name");
    $stmt->bind_param("i", $department_id);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

echo json_encode($rows);