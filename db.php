<?php
$host = "localhost";
$user = "root";
$password = "Bayari,99.";
$dbname = "exam_system";

$conn = new mysqli($host, $user, $password, $dbname);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}
?>