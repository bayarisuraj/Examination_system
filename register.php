<?php
session_start();
require_once "../config/db.php";

$success = $error = "";

// ── Handle registration ──────────────────────────────────────
if (isset($_POST['register'])) {

    $role      = $_POST['role'] ?? '';
    $name      = trim($_POST['name'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $password  = trim($_POST['password'] ?? '');
    $password2 = trim($_POST['password2'] ?? '');

    if (!in_array($role, ['student', 'lecturer'])) {
        $error = "Please select a role.";
    } elseif (!$name || !$email || !$password) {
        $error = "Please fill in all required fields.";
    } elseif ($password !== $password2) {
        $error = "Passwords do not match.";
    } else {

        // Check duplicate email
        $chk = $conn->prepare("
            SELECT id FROM students WHERE email=? 
            UNION 
            SELECT id FROM lecturers WHERE email=?
        ");
        $chk->bind_param("ss", $email, $email);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0) {
            $error = "Email already exists.";
        }
        $chk->close();

        if (!$error) {
            $hashed = password_hash($password, PASSWORD_DEFAULT);

            if ($role === 'student') {
                $student_number = trim($_POST['student_number'] ?? '');
                $program_id     = (int)($_POST['program_id'] ?? 0);

                if (!$student_number) {
                    $error = "Student Number is required.";
                } elseif (!$program_id) {
                    $error = "Please select your program.";
                } else {
                    // Fetch program name text to store alongside program_id
                    $prog_stmt = $conn->prepare("SELECT name FROM programs WHERE id = ? LIMIT 1");
                    $prog_stmt->bind_param("i", $program_id);
                    $prog_stmt->execute();
                    $prog_row     = $prog_stmt->get_result()->fetch_assoc();
                    $prog_stmt->close();
                    $program_name = $prog_row['name'] ?? '';

                    $stmt = $conn->prepare("
                        INSERT INTO students (name, email, password, student_number, program_id, program)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->bind_param("ssssis", $name, $email, $hashed, $student_number, $program_id, $program_name);
                }

            } else {
                $lecturer_id = trim($_POST['lecturer_id'] ?? '');

                if (!$lecturer_id) {
                    $error = "Lecturer ID required.";
                } else {
                    $stmt = $conn->prepare("
                        INSERT INTO lecturers (name, email, password, lecturer_id)
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->bind_param("ssss", $name, $email, $hashed, $lecturer_id);
                }
            }

            if (!$error && isset($stmt)) {
                if ($stmt->execute()) {
                    $success = "Account created! <a href='login.php'>Sign in →</a>";
                } else {
                    $error = "Registration failed. Try again. " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// Fetch programs for dropdown
$programs = [];
$prog_res = $conn->query("SELECT id, name FROM programs ORDER BY name ASC");
if ($prog_res) {
    while ($row = $prog_res->fetch_assoc()) {
        $programs[] = $row;
    }
}

$old = [
    'role'          => htmlspecialchars($_POST['role']           ?? ''),
    'name'          => htmlspecialchars($_POST['name']           ?? ''),
    'email'         => htmlspecialchars($_POST['email']          ?? ''),
    'student_id'    => htmlspecialchars($_POST['student_id']     ?? ''),
    'student_number'=> htmlspecialchars($_POST['student_number'] ?? ''),
    'lecturer_id'   => htmlspecialchars($_POST['lecturer_id']    ?? ''),
    'program_id'    => (int)($_POST['program_id']                ?? 0),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register | OES</title>
<link rel="stylesheet" href="../assets/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Sora:wght@600;700&display=swap" rel="stylesheet">
<style>
:root {
    --teal:    #0d9488;
    --teal-dk: #0a7a71;
    --navy:    #0f1f3d;
    --bg:      #f1f5f9;
    --white:   #ffffff;
    --muted:   #64748b;
    --border:  #e2e8f0;
    --danger:  #ef4444;
    --success: #10b981;
    --r:       10px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', sans-serif;
    background: var(--bg);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
}

nav {
    background: var(--navy);
    padding: .8rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 50;
}
.nav-brand {
    font-family: 'Sora', sans-serif;
    font-size: 1rem;
    color: #fff;
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}
.nav-brand .dot {
    width: 28px; height: 28px;
    background: var(--teal);
    border-radius: 7px;
    display: grid;
    place-items: center;
    font-size: .85rem;
    color: #fff;
    flex-shrink: 0;
}
.nav-links { display: flex; gap: 4px; }
.nav-links a {
    color: rgba(255,255,255,.7);
    font-size: .82rem;
    font-weight: 500;
    padding: .35rem .75rem;
    border-radius: 6px;
    text-decoration: none;
    transition: .15s;
}
.nav-links a:hover, .nav-links a.active { color:#fff; background:rgba(255,255,255,.1); }

.wrap {
    flex: 1;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 2.5rem 1rem 3rem;
}

.card {
    width: 100%;
    max-width: 520px;
    background: var(--white);
    border-radius: 16px;
    box-shadow: 0 4px 24px rgba(0,0,0,.08);
    overflow: hidden;
}
.card-top {
    background: var(--navy);
    padding: 1.5rem 2rem;
    display: flex;
    align-items: center;
    gap: 12px;
}
.card-top-icon {
    width: 42px; height: 42px;
    background: rgba(13,148,136,.25);
    border: 1px solid rgba(13,148,136,.4);
    border-radius: 10px;
    display: grid;
    place-items: center;
    font-size: 1.1rem;
    color: #2dd4bf;
    flex-shrink: 0;
}
.card-top h1 { font-family:'Sora',sans-serif; font-size:1.1rem; color:#fff; margin-bottom:2px; }
.card-top p  { font-size:.8rem; color:rgba(255,255,255,.5); }
.card-body   { padding: 1.8rem 2rem 2rem; }

.alert {
    padding: .7rem 1rem;
    border-radius: var(--r);
    font-size: .85rem;
    font-weight: 500;
    margin-bottom: 1.4rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.alert-success { background:#ecfdf5; color:var(--success); border:1px solid #a7f3d0; }
.alert-danger  { background:#fef2f2; color:var(--danger);  border:1px solid #fecaca; }
.alert a { color:inherit; }

.role-tabs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    background: var(--bg);
    border: 1.5px solid var(--border);
    border-radius: var(--r);
    padding: 4px;
    gap: 4px;
    margin-bottom: 1.5rem;
}
.role-tab {
    padding: .55rem;
    border-radius: 7px;
    border: none;
    background: transparent;
    font-family: 'Inter', sans-serif;
    font-size: .85rem;
    font-weight: 500;
    color: var(--muted);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    transition: .2s;
}
.role-tab:hover { color: var(--navy); }
.role-tab.active {
    background: var(--white);
    color: var(--teal);
    font-weight: 600;
    box-shadow: 0 1px 6px rgba(0,0,0,.08);
}
#roleInput { display: none; }

.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.field { margin-bottom: 1rem; }
.field label {
    display: block;
    font-size: .74rem;
    font-weight: 600;
    color: var(--navy);
    text-transform: uppercase;
    letter-spacing: .4px;
    margin-bottom: 5px;
}
.inp { position: relative; }
.inp i.ic {
    position: absolute;
    left: 11px; top: 50%;
    transform: translateY(-50%);
    color: var(--muted);
    font-size: .85rem;
    pointer-events: none;
    z-index: 1;
}
.inp input {
    width: 100%;
    padding: .6rem .8rem .6rem 2.2rem;
    border: 1.5px solid var(--border);
    border-radius: var(--r);
    font-family: 'Inter', sans-serif;
    font-size: .875rem;
    color: var(--navy);
    background: var(--bg);
    outline: none;
    transition: .2s;
}
.inp input:focus {
    border-color: var(--teal);
    background: var(--white);
    box-shadow: 0 0 0 3px rgba(13,148,136,.1);
}
.eye {
    position: absolute;
    right: 10px; top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: var(--muted);
    font-size: .85rem;
}

.sec {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .7px;
    color: var(--teal);
    margin: 1.1rem 0 .8rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.sec::after { content:''; flex:1; height:1px; background:var(--border); }

.rf { display: none; }
.rf.show { display: block; animation: up .25s ease; }
@keyframes up {
    from { opacity:0; transform:translateY(6px); }
    to   { opacity:1; transform:translateY(0); }
}

.btn-sub {
    width: 100%;
    padding: .75rem;
    background: var(--teal);
    color: #fff;
    border: none;
    border-radius: var(--r);
    font-family: 'Inter', sans-serif;
    font-size: .9rem;
    font-weight: 600;
    cursor: pointer;
    transition: .2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    margin-top: .4rem;
}
.btn-sub:hover { background: var(--teal-dk); }

.signin     { text-align:center; margin-top:.9rem; font-size:.82rem; color:var(--muted); }
.signin a   { color:var(--teal); font-weight:600; text-decoration:none; }
.admin-link { text-align:center; margin-top:.35rem; font-size:.74rem; }
.admin-link a {
    color: #94a3b8;
    text-decoration: none;
    border-bottom: 1px dashed #cbd5e1;
    transition: color .15s;
}
.admin-link a:hover { color: var(--muted); }

footer { text-align:center; padding:1rem; font-size:.75rem; color:var(--muted); }

@media (max-width:480px) {
    .card-body { padding: 1.4rem 1.2rem; }
    .row-2 { grid-template-columns: 1fr; gap: 0; }
}
</style>
</head>
<body>

<nav>
    <a class="nav-brand" href="#">
        <span class="dot"><i class="bi bi-mortarboard-fill"></i></span>
        Online Exam System
    </a>
    <div class="nav-links">
        <a href="register.php" class="active">Register</a>
        <a href="login.php">Login</a>
    </div>
</nav>

<div class="wrap">
<div class="card">

    <div class="card-top">
        <div class="card-top-icon"><i class="bi bi-person-plus-fill"></i></div>
        <div>
            <h1>Create Account</h1>
            <p>Fill in the form to register</p>
        </div>
    </div>

    <div class="card-body">

        <?php if ($success): ?>
            <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> <?= $success ?></div>
        <?php elseif ($error): ?>
            <div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i> <?= $error ?></div>
        <?php endif; ?>

        <form method="POST" id="regForm">
            <input type="hidden" name="role" id="roleInput" value="<?= $old['role'] ?: 'student' ?>">

            <!-- Role -->
            <div class="role-tabs">
                <button type="button" class="role-tab <?= ($old['role'] !== 'lecturer') ? 'active' : '' ?>"
                        id="tabStudent" onclick="setRole('student')">
                    <i class="bi bi-person-workspace"></i> Student
                </button>
                <button type="button" class="role-tab <?= ($old['role'] === 'lecturer') ? 'active' : '' ?>"
                        id="tabLecturer" onclick="setRole('lecturer')">
                    <i class="bi bi-person-badge"></i> Lecturer
                </button>
            </div>

            <!-- Personal Info -->
            <div class="sec">Personal Info</div>
            <div class="row-2">
                <div class="field">
                    <label>Full Name</label>
                    <div class="inp">
                        <i class="bi bi-person ic"></i>
                        <input type="text" name="name" required placeholder="John Mensah" value="<?= $old['name'] ?>">
                    </div>
                </div>
                <div class="field">
                    <label>Email</label>
                    <div class="inp">
                        <i class="bi bi-envelope ic"></i>
                        <input type="email" name="email" required placeholder="you@example.com" value="<?= $old['email'] ?>">
                    </div>
                </div>
            </div>

            <!-- Student-only fields -->
            <div id="sfStudent" class="rf <?= ($old['role'] !== 'lecturer') ? 'show' : '' ?>">
                <div class="sec">Student Details</div>
                <div class="row-2">
            <div class="field">
                <label>index Number</label>
                <div class="inp">
                    <i class="bi bi-123 ic"></i>
                    <input type="text" name="student_number" placeholder="20260001"
                        value="<?= $old['student_number'] ?>">
                </div>
            </div>
            <div class="field">
                <label>Program</label>
                <div class="inp">
                    <i class="bi bi-mortarboard ic"></i>
                    <select name="program_id" style="
                        width:100%; padding:.6rem .8rem .6rem 2.2rem;
                        border:1.5px solid var(--border); border-radius:var(--r);
                        font-family:'Inter',sans-serif; font-size:.875rem;
                        color:var(--navy); background:var(--bg); outline:none;
                        appearance:none; transition:.2s; cursor:pointer;">
                        <option value="">— Select Program —</option>
                        <?php foreach ($programs as $prog): ?>
                        <option value="<?= $prog['id'] ?>"
                            <?= ($old['program_id'] == $prog['id']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($prog['name']) ?>     
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
            </div>

            <!-- Lecturer-only fields -->
            <div id="sfLecturer" class="rf <?= ($old['role'] === 'lecturer') ? 'show' : '' ?>">
                <div class="sec">Lecturer Details</div>
                <div class="field">
                    <label>Lecturer ID</label>
                    <div class="inp">
                        <i class="bi bi-hash ic"></i>
                        <input type="text" name="lecturer_id" placeholder="L001" value="<?= $old['lecturer_id'] ?>">
                    </div>
                </div>
            </div>

            <!-- Security -->
            <div class="sec">Security</div>
            <div class="row-2">
                <div class="field">
                    <label>Password</label>
                    <div class="inp">
                        <i class="bi bi-lock ic"></i>
                        <input type="password" name="password" id="p1" required placeholder="••••••••">
                        <span class="eye" onclick="tp('p1',this)"><i class="bi bi-eye"></i></span>
                    </div>
                </div>
                <div class="field">
                    <label>Confirm Password</label>
                    <div class="inp">
                        <i class="bi bi-lock-fill ic"></i>
                        <input type="password" name="password2" id="p2" required placeholder="••••••••">
                        <span class="eye" onclick="tp('p2',this)"><i class="bi bi-eye"></i></span>
                    </div>
                </div>
            </div>

            <button type="submit" name="register" class="btn-sub">
                <i class="bi bi-person-check-fill"></i> Create Account
            </button>

            <p class="signin">Already have an account? <a href="../auth/login.php">Sign in</a></p>
            <p class="admin-link"><a href="../admin/admin_register.php">Administrator? Register here</a></p>
        </form>
    <!-- <div class="card-foot" style="margin-top:1rem; font-size:.82rem; text-align:center;">
        <p>Forgot your password?</p>
        <div class="forgot-links">
            <a href="../admin/forgot_pword.php" class="forgot-link">Admin</a>
            <a href="../lecturer/lecturer_forgot_pword.php" class="forgot-link">Lecturer</a>
            <a href="../student/student_forgot_pword.php" class="forgot-link">Student</a>
        </div> -->
    </div>
    </div>
</div>
</div>

<footer>&copy; <?= date('Y') ?> Online Exam System</footer>
<style>
.forgot-links {
    display: flex;
    justify-content: center;
    gap: 1rem;
    flex-wrap: wrap;
    margin-top: 20px;
}

.forgot-link {
    text-decoration: none;
    padding: 10px 20px;
    background-color: #4A90E2; /* nice blue color */
    color: white;
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.3s ease;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.forgot-link:hover {
    background-color: #357ABD; /* darker blue on hover */
    transform: translateY(-2px);
    box-shadow: 0 6px 10px rgba(0,0,0,0.15);
}
</style>

<script src="../assets/js/bootstrap.bundle.min.js"></script>
<script>
// ✅ Role toggle
function setRole(r) {
    document.getElementById('roleInput').value = r;
    document.getElementById('tabStudent').classList.toggle('active',  r === 'student');
    document.getElementById('tabLecturer').classList.toggle('active', r === 'lecturer');
    document.getElementById('sfStudent').classList.toggle('show',  r === 'student');
    document.getElementById('sfLecturer').classList.toggle('show', r === 'lecturer');
}

// ✅ Toggle password visibility
function tp(id, el) {
    const input = document.getElementById(id);
    input.type = input.type === 'text' ? 'password' : 'text';
    el.querySelector('i').className =
        input.type === 'text' ? 'bi bi-eye-slash' : 'bi bi-eye';
}

// ✅ Init on page load
document.addEventListener('DOMContentLoaded', () => {
    setRole(document.getElementById('roleInput').value || 'student');
});
</script>
</body>
</html>