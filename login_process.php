<?php
session_start();
require_once "../config/db.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.php");
    exit();
}

$email    = trim($_POST['email'] ?? '');
$password = trim($_POST['password'] ?? '');

if ($email === '' || $password === '') {
    header("Location: login.php?error=1");
    exit();
}

// ── Helper: attempt login against a single result row ──────────────────────
function tryLogin(mysqli $conn, string $table, array $user, string $password, string $role): bool {
    if (password_verify($password, $user['password'])) {
        return true;
    }
    // Plain-text legacy password — verify then upgrade hash in DB
    if ($password === $user['password']) {
        $new_hashed = password_hash($password, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE $table SET password = ? WHERE id = ?");
        $upd->bind_param("si", $new_hashed, $user['id']);
        $upd->execute();
        $upd->close();
        return true;
    }
    return false;
}

// ── Helper: set session variables after successful login ───────────────────
function setSession(array $user, string $role, string $source): void {
    session_regenerate_id(true);

    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['name']       = $user['name'];
    $_SESSION['username']   = $user['name'];
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['role']       = $role;
    $_SESSION['auth_source'] = $source; // 'legacy' or 'users'

    if ($role === 'student') {
        $_SESSION['student_number'] = $user['student_number'] ?? '';
        $_SESSION['program']        = $user['program']        ?? '';
        // Legacy students table uses 'avatar'; users table has no avatar column
        $avatar = $user['avatar'] ?? '';
        $_SESSION['profile_image']  = ($avatar && $avatar !== 'avatar.png')
            ? 'uploads/avatars/' . $avatar
            : '';
    }

    if ($role === 'lecturer') {
        $_SESSION['department']    = $user['department']    ?? '';
        $_SESSION['profile_image'] = $user['profile_image'] ?? '';
    }

    if ($role === 'admin') {
        $_SESSION['profile_image'] = $user['profile_image'] ?? '';
    }
}

// ── STEP 1: Check legacy tables first (admins, lecturers, students) ─────────
// These are the original separate tables used by self-registered users.
$legacy_tables = [
    'admins'    => 'admin',
    'lecturers' => 'lecturer',
    'students'  => 'student',
];

foreach ($legacy_tables as $table => $role) {
    $stmt = $conn->prepare("SELECT * FROM $table WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $stmt->close();

        if (tryLogin($conn, $table, $user, $password, $role)) {
            setSession($user, $role, 'legacy');
            // Redirect by role
            if ($role === 'admin')        header("Location: ../admin/dashboard.php");
            elseif ($role === 'lecturer') header("Location: ../lecturer/dashboard.php");
            elseif ($role === 'student')  header("Location: ../student/dashboard.php");
            exit();
        } else {
            // Email found but password wrong — no point checking other tables
            header("Location: login.php?error=1");
            exit();
        }
    }
    $stmt->close();
}

// ── STEP 2: Check unified users table (admin-created accounts) ──────────────
// Admin pages (manage_users, manage_students) insert into the users table.
// Those accounts don't exist in the legacy tables, so we check here as a fallback.
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $stmt->close();

    $role = $user['role'] ?? ''; // 'student', 'lecturer', or 'admin'

    if (!in_array($role, ['student', 'lecturer', 'admin'])) {
        header("Location: login.php?error=1");
        exit();
    }

    if (tryLogin($conn, 'users', $user, $password, $role)) {
        setSession($user, $role, 'users');
        if ($role === 'admin')        header("Location: ../admin/dashboard.php");
        elseif ($role === 'lecturer') header("Location: ../lecturer/dashboard.php");
        elseif ($role === 'student')  header("Location: ../student/dashboard.php");
        exit();
    } else {
        // Email found in users table but password wrong
        header("Location: login.php?error=1");
        exit();
    }
}
$stmt->close();

// ── No matching user found in any table ─────────────────────────────────────
header("Location: login.php?error=1");
exit();
?>