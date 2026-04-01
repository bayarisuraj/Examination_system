<?php
session_start();
require_once "../config/db.php";

// ── Safety: only run if logged in as lecturer ──────────────────────
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    die("Not logged in as lecturer. Please login first.");
}

$session_email = $_SESSION['user_email'] ?? $_SESSION['email'] ?? '';
$lecturer_id   = (int)($_SESSION['user_id'] ?? 0);

if ($session_email) {
    $stmt = $conn->prepare("SELECT id FROM lecturers WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $session_email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) $lecturer_id = (int)$row['id'];
}

echo "<style>body{font-family:monospace;padding:20px;background:#f4f6fb} table{border-collapse:collapse;width:100%;margin-bottom:20px} th,td{border:1px solid #ccc;padding:6px 10px;text-align:left;font-size:13px} th{background:#e9ecef} h2{color:#2563eb;margin-top:30px} .ok{color:green;font-weight:bold} .bad{color:red;font-weight:bold} .box{background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:20px}</style>";

echo "<h1>🔍 Results Debug — Lecturer ID: <b>{$lecturer_id}</b></h1>";
echo "<p>Session email: <b>" . htmlspecialchars($session_email) . "</b></p>";

// ── 1. Check exam_attempts table structure ─────────────────────────
echo "<div class='box'><h2>1. exam_attempts columns</h2>";
$r = $conn->query("DESCRIBE exam_attempts");
if ($r) {
    echo "<table><tr><th>Field</th><th>Type</th><th>Null</th><th>Default</th></tr>";
    while ($row = $r->fetch_assoc()) {
        $hi = in_array($row['Field'], ['id','student_id','exam_id','score','status','end_time']) ? " style='background:#fffbcc'" : "";
        echo "<tr{$hi}><td>{$row['Field']}</td><td>{$row['Type']}</td><td>{$row['Null']}</td><td>{$row['Default']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='bad'>❌ Table 'exam_attempts' NOT FOUND or error: " . $conn->error . "</p>";
}
echo "</div>";

// ── 2. Check distinct status values ───────────────────────────────
echo "<div class='box'><h2>2. Distinct status values in exam_attempts</h2>";
$r = $conn->query("SELECT status, COUNT(*) as cnt FROM exam_attempts GROUP BY status");
if ($r && $r->num_rows > 0) {
    echo "<table><tr><th>status value</th><th>count</th></tr>";
    while ($row = $r->fetch_assoc()) {
        $hi = $row['status'] === 'completed' ? " class='ok'" : " class='bad'";
        echo "<tr><td{$hi}>" . htmlspecialchars($row['status'] ?? 'NULL') . "</td><td>{$row['cnt']}</td></tr>";
    }
    echo "</table>";
    echo "<p><b>The query in results.php uses: AND a.status = 'completed'</b><br>Make sure the value above matches exactly.</p>";
} else {
    echo "<p class='bad'>❌ No rows in exam_attempts at all, or error: " . $conn->error . "</p>";
}
echo "</div>";

// ── 3. Check exams owned by this lecturer ─────────────────────────
echo "<div class='box'><h2>3. Exams belonging to lecturer ID {$lecturer_id}</h2>";
$stmt = $conn->prepare("SELECT id, title, lecturer_id, created_by, course_id FROM exams WHERE lecturer_id = ? OR created_by = ?");
$stmt->bind_param("ii", $lecturer_id, $lecturer_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows > 0) {
    echo "<p class='ok'>✅ Found {$res->num_rows} exam(s)</p>";
    echo "<table><tr><th>id</th><th>title</th><th>lecturer_id</th><th>created_by</th><th>course_id</th></tr>";
    $exam_ids = [];
    while ($row = $res->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>" . htmlspecialchars($row['title']) . "</td><td>{$row['lecturer_id']}</td><td>{$row['created_by']}</td><td>{$row['course_id']}</td></tr>";
        $exam_ids[] = $row['id'];
    }
    echo "</table>";
} else {
    echo "<p class='bad'>❌ No exams found for lecturer_id={$lecturer_id} in either lecturer_id or created_by columns.</p>";
    // Show all exams for reference
    $all = $conn->query("SELECT id, title, lecturer_id, created_by FROM exams LIMIT 10");
    if ($all && $all->num_rows > 0) {
        echo "<p>All exams in the table (first 10):</p><table><tr><th>id</th><th>title</th><th>lecturer_id</th><th>created_by</th></tr>";
        while ($row = $all->fetch_assoc()) {
            echo "<tr><td>{$row['id']}</td><td>" . htmlspecialchars($row['title']) . "</td><td>{$row['lecturer_id']}</td><td>{$row['created_by']}</td></tr>";
        }
        echo "</table>";
    }
}
$stmt->close();
echo "</div>";

// ── 4. Check exam_attempts for those exams ─────────────────────────
if (!empty($exam_ids)) {
    $in = implode(',', $exam_ids);
    echo "<div class='box'><h2>4. exam_attempts for lecturer's exams (exam IDs: {$in})</h2>";
    $r = $conn->query("SELECT a.id, a.student_id, a.exam_id, a.score, a.status, a.end_time FROM exam_attempts a WHERE a.exam_id IN ({$in}) LIMIT 20");
    if ($r && $r->num_rows > 0) {
        echo "<p class='ok'>✅ Found {$r->num_rows} attempt(s)</p>";
        echo "<table><tr><th>attempt id</th><th>student_id</th><th>exam_id</th><th>score</th><th>status</th><th>end_time</th></tr>";
        while ($row = $r->fetch_assoc()) {
            $sc = $row['status'] === 'completed' ? " style='background:#d1fae5'" : " style='background:#fee2e2'";
            echo "<tr{$sc}><td>{$row['id']}</td><td>{$row['student_id']}</td><td>{$row['exam_id']}</td><td>{$row['score']}</td><td>" . htmlspecialchars($row['status'] ?? 'NULL') . "</td><td>{$row['end_time']}</td></tr>";
        }
        echo "</table>";
    } else {
        echo "<p class='bad'>❌ No attempts found for these exams. Students may not have taken the exams yet.</p>";
    }
    echo "</div>";
}

// ── 5. Check users table for students ─────────────────────────────
echo "<div class='box'><h2>5. Users table — checking student records</h2>";
$r = $conn->query("SELECT role, COUNT(*) as cnt FROM users GROUP BY role");
if ($r && $r->num_rows > 0) {
    echo "<table><tr><th>role</th><th>count</th></tr>";
    while ($row = $r->fetch_assoc()) {
        echo "<tr><td>" . htmlspecialchars($row['role'] ?? 'NULL') . "</td><td>{$row['cnt']}</td></tr>";
    }
    echo "</table>";
    echo "<p>The query joins <b>exam_attempts.student_id → users.id</b>. Make sure students are in the <b>users</b> table (not a separate students table).</p>";
} else {
    echo "<p class='bad'>❌ Could not query users table: " . $conn->error . "</p>";
    // Maybe students are in a different table
    $tables = $conn->query("SHOW TABLES");
    echo "<p>All tables in DB:</p><ul>";
    while ($t = $tables->fetch_row()) echo "<li>" . htmlspecialchars($t[0]) . "</li>";
    echo "</ul>";
}
echo "</div>";

// ── 6. Full join test — exact query from results.php ──────────────
echo "<div class='box'><h2>6. Full JOIN test (exact query from results.php)</h2>";
$stmt = $conn->prepare("
    SELECT a.id, u.name, u.email, e.title, a.score, a.status
    FROM exam_attempts a
    JOIN users   u ON u.id  = a.student_id
    JOIN exams   e ON e.id  = a.exam_id
    JOIN courses c ON c.id  = e.course_id
    WHERE (e.lecturer_id = ? OR e.created_by = ?)
      AND a.status = 'completed'
    LIMIT 10
");
$stmt->bind_param("ii", $lecturer_id, $lecturer_id);
$stmt->execute();
$res = $stmt->get_result();
if ($res->num_rows > 0) {
    echo "<p class='ok'>✅ JOIN works! Found {$res->num_rows} completed attempt(s). Your results.php SHOULD be showing data.</p>";
    echo "<table><tr><th>attempt id</th><th>student name</th><th>email</th><th>exam</th><th>score</th><th>status</th></tr>";
    while ($row = $res->fetch_assoc()) {
        echo "<tr><td>{$row['id']}</td><td>" . htmlspecialchars($row['name']) . "</td><td>" . htmlspecialchars($row['email']) . "</td><td>" . htmlspecialchars($row['title']) . "</td><td>{$row['score']}</td><td>{$row['status']}</td></tr>";
    }
    echo "</table>";
} else {
    echo "<p class='bad'>❌ The full JOIN returned 0 rows. See the checks above to find which step is failing.</p>";
}
$stmt->close();
echo "</div>";

echo "<hr><p style='color:#888;font-size:12px'>⚠️ Delete this file after debugging — it exposes DB info.</p>";
?>