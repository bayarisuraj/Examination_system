<?php
function addNotification($conn, $title, $message, $type = 'system', $role = 'admin') {
    $stmt = $conn->prepare("INSERT INTO notifications (title, message, type, user_role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $title, $message, $type, $role);
    $stmt->execute();
    $stmt->close();
}
?>