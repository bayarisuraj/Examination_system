<?php
session_start();
require_once "../config/db.php";

if(isset($_SESSION['role'])){
    $role = $_SESSION['role'];
    if($role === 'admin')    header("Location: ../admin/dashboard.php");
    elseif($role === 'lecturer') header("Location: ../lecturer/dashboard.php");
    elseif($role === 'student')  header("Location: ../student/dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login | OES</title>
<link rel="stylesheet" href="../assets/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
    --teal:        #3d8b8d;
    --teal-dark:   #2d6e70;
    --teal-light:  #56a8aa;
    --teal-pale:   #eaf5f5;
    --teal-border: #c0dfe0;
    --teal-glow:   rgba(61,139,141,.15);
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

html, body {
    height: 100%;
    font-family: 'Open Sans', 'Segoe UI', sans-serif;
    background: #2d6e70;
    display: flex;
    /* background-image: url('../img/aamusted_logo.jpg'); */
    flex-direction: column;
}

/* ── Topbar ── */
.topbar {
    background: var(--teal);
    height: 56px;
    display: flex;
    align-items: center;
    padding: 0 20px;
    gap: 14px;
    box-shadow: 0 2px 6px rgba(0,0,0,.2);
    flex-shrink: 0;
}
.topbar-brand {
    display: flex; align-items: center; gap: 11px;
    text-decoration: none;
}
.topbar-brand-icon {
    width: 38px; height: 38px;
    background: rgba(255,255,255,.95);
    border-radius: 50%;
    display: grid; place-items: center;
    color: var(--teal); font-size: 1.1rem;
    flex-shrink: 0;
}
.topbar-brand-text .uni {
    font-size: .66rem; font-weight: 700;
    letter-spacing: .05em; text-transform: uppercase;
    color: rgba(255,255,255,.8); line-height: 1;
}
.topbar-brand-text .sys {
    font-size: .95rem; font-weight: 700;
    color: #fff; line-height: 1.3;
}
.topbar-spacer { flex: 1; }
.topbar-links { display: flex; gap: 2px; }
.topbar-links a {
    color: rgba(255,255,255,.85);
    text-decoration: none; font-size: .8rem; font-weight: 600;
    padding: 5px 12px; border-radius: 3px;
    transition: background .15s;
}
.topbar-links a:hover { background: rgba(255,255,255,.18); color: #fff; }

/* ── Breadcrumb ── */
.breadcrumb-row {
    background: #fff;
    border-bottom: 1px solid var(--teal-border);
    padding: 8px 20px;
    display: flex; align-items: center; gap: 6px;
    font-size: .78rem; color: #6b7c8d;
    flex-shrink: 0;
}
.breadcrumb-row a { color: var(--teal); text-decoration: none; }
.breadcrumb-row a:hover { text-decoration: underline; }

/* ── Page body ── */
.page-body {
    flex: 1;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 44px 16px 56px;
}

/* ── Login card ── */
.login-card {
    background: #fff;
    border: 1px solid var(--teal-border);
    border-top: 4px solid var(--teal);
    border-radius: 5px;
    width: 100%; max-width: 420px;
    box-shadow: 0 3px 16px rgba(0,0,0,.09);
    overflow: hidden;
    animation: slideUp .3s ease both;
}
@keyframes slideUp {
    from { opacity:0; transform:translateY(16px); }
    to   { opacity:1; transform:translateY(0); }
}

.card-head {
    background: var(--teal-pale);
    border-bottom: 1px solid var(--teal-border);
    padding: 18px 26px;
    display: flex; align-items: center; gap: 13px;
}
.card-head-icon {
    width: 42px; height: 42px;
    background: var(--teal); border-radius: 50%;
    display: grid; place-items: center;
    color: #fff; font-size: 1.1rem; flex-shrink: 0;
}
.card-head-text h2 {
    font-size: 1rem; font-weight: 700;
    color: var(--teal-dark); margin-bottom: 2px;
}
.card-head-text p { font-size: .76rem; color: #6b7c8d; }

.card-body { padding: 24px 26px 28px; }

/* Alerts */
.alert-error {
    display: flex; align-items: flex-start; gap: 10px;
    background: #fef2f2;
    border: 1px solid #fca5a5; border-left: 4px solid #b91c1c;
    border-radius: 5px; padding: 10px 14px;
    font-size: .84rem; color: #b91c1c; margin-bottom: 18px;
}
.alert-success {
    display: flex; align-items: flex-start; gap: 10px;
    background: #f0fdf4;
    border: 1px solid #86efac; border-left: 4px solid #166534;
    border-radius: 5px; padding: 10px 14px;
    font-size: .84rem; color: #166534; margin-bottom: 18px;
}

/* Form fields */
.field { margin-bottom: 16px; }
.field label {
    display: block; font-size: .78rem; font-weight: 700;
    color: #4a5568; margin-bottom: 5px;
}
.input-wrap { position: relative; }
.input-icon {
    position: absolute; left: 11px; top: 50%;
    transform: translateY(-50%);
    color: #aab; font-size: .9rem; pointer-events: none;
    transition: color .15s;
}
.form-control {
    width: 100%;
    border: 1.5px solid #ced4da !important;
    border-radius: 5px !important;
    padding: 10px 40px 10px 34px !important;
    font-family: inherit; font-size: .88rem;
    color: #2c3e50; background: #fff; outline: none;
    transition: border-color .18s, box-shadow .18s;
}
.form-control::placeholder { color: #c0c8d0; }
.form-control:focus {
    border-color: var(--teal) !important;
    box-shadow: 0 0 0 3px var(--teal-glow) !important;
}
.form-control:focus ~ .input-icon { color: var(--teal); }

.eye-btn {
    position: absolute; right: 10px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none; color: #aab;
    cursor: pointer; font-size: .88rem; padding: 4px;
    line-height: 1; transition: color .15s;
}
.eye-btn:hover { color: var(--teal); }

/* Remember me */
.form-check-input:checked {
    background-color: var(--teal) !important;
    border-color: var(--teal) !important;
}
.form-check-input:focus {
    box-shadow: 0 0 0 3px var(--teal-glow) !important;
    border-color: var(--teal) !important;
}

/* Login button */
.btn-primary {
    background-color: var(--teal) !important;
    border-color: var(--teal) !important;
    font-weight: 700 !important;
    letter-spacing: .02em;
    transition: background .18s !important;
}
.btn-primary:hover:not(:disabled) {
    background-color: var(--teal-dark) !important;
    border-color: var(--teal-dark) !important;
}
.btn-primary:disabled { opacity: .6; cursor: not-allowed; }

/* Register + footer links */
.register-link {
    text-align: center; margin-top: 14px;
    font-size: .78rem; color: #6b7c8d;
}
.register-link a { color: var(--teal); text-decoration: none; font-weight: 600; }
.register-link a:hover { text-decoration: underline; }

.card-foot {
    border-top: 1px solid #eee;
    background: #f8fafb;
    padding: 13px 26px;
    text-align: center; font-size: .74rem; color: #6b7c8d;
}
.card-foot a { color: var(--teal); text-decoration: none; }
.card-foot a:hover { text-decoration: underline; }

/* Site footer */
.site-footer {
    background: var(--teal-dark);
    color: rgba(255,255,255,.65);
    text-align: center; padding: 12px 16px;
    font-size: .72rem; flex-shrink: 0;
}
.site-footer strong { color: #fff; }

@media (max-width: 460px) {
    .card-body  { padding: 18px 16px 22px; }
    .card-head  { padding: 14px 16px; }
}
</style>
</head>
<body>

<!-- Topbar -->
<header class="topbar">
    <a class="topbar-brand" href="#">
        <div class="topbar-brand-icon">
            <i class="bi bi-mortarboard-fill"></i>
        </div>
        <div class="topbar-brand-text">
            <div class="uni">USTED — Online Exam System</div>
            <div class="sys">OES Portal</div>
        </div>
    </a>
    <div class="topbar-spacer"></div>
    <div class="topbar-links">
        <a href="#"><i class="bi bi-house-fill"></i> Home</a>
        <a href="../student/help.php"><i class="bi bi-question-circle"></i> Help</a>
    </div>
</header>

<!-- Breadcrumb -->
<div class="breadcrumb-row">
    <a href="#">Home</a>
    <i class="bi bi-chevron-right" style="font-size:.6rem;color:#ccc;"></i>
    <span>Login</span>
</div>

<!-- Page body -->
<div class="page-body">
    <div class="login-card">

        <div class="card-head">
            <div class="card-head-icon">
                <i class="bi bi-box-arrow-in-right"></i>
            </div>
            <div class="card-head-text">
                <h2>Sign in to your account</h2>
                <p>Student &bull; Lecturer &bull; Admin</p>
            </div>
        </div>

        <div class="card-body">

            <?php if(isset($_GET['error'])): ?>
            <div class="alert-error">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span>Invalid email or password</span>
            </div>
            <?php endif; ?>

            <?php if(isset($_GET['success'])): ?>
            <div class="alert-success">
                <i class="bi bi-check-circle-fill"></i>
                <span><?= htmlspecialchars($_GET['success']) ?></span>
            </div>
            <?php endif; ?>

            <form action="login_process.php" method="POST">

                <div class="field">
                    <label for="email">
                        <i class="bi bi-envelope-fill"></i> Email Address
                    </label>
                    <div class="input-wrap">
                        <input type="email" name="email" id="email"
                               class="form-control"
                               placeholder="you@example.com" required
                               autocomplete="email">
                        <i class="bi bi-envelope input-icon"></i>
                    </div>
                </div>

                <div class="field">
                    <label for="password">
                        <i class="bi bi-key-fill"></i> Password
                    </label>
                    <div class="input-wrap">
                        <input type="password" name="password" id="password"
                               class="form-control"
                               placeholder="Password" required
                               autocomplete="current-password">
                        <i class="bi bi-lock input-icon"></i>
                        <button type="button" class="eye-btn" onclick="togglePass()" title="Show/hide">
                            <i class="bi bi-eye" id="eye-icon"></i>
                        </button>
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input type="checkbox" class="form-check-input" id="remember">
                    <label class="form-check-label" for="remember" style="font-size:.85rem;">
                        Remember me
                    </label>
                </div>

                <div class="d-grid mb-2">
                    <button type="submit" class="btn btn-primary" id="loginBtn" disabled>
                        <i class="bi bi-box-arrow-in-right"></i> Login
                    </button>
                </div>

            </form>

            <div class="register-link">
                Don't have an account?
                <a href="register.php">Create one here</a>
            </div>

        </div>

        <!-- <div class="card-foot">
            Forgot your password? Contact the
            <a href="mailto:ict@usted.edu.gh">ICT Support Desk</a>
            <a href="mailto:bayarusraj@gmail.com">ICT Support Desk</a>

        </div> -->

        <div class="card-foot">
    Forgot your password? &nbsp;
    <a href="../student/student_forgot_pword.php">
        <i class="bi bi-mortarboard"></i> Student
    </a>
    &nbsp;&bull;&nbsp;
    <a href="../lecturer/lecturer_forgot_pword.php">
        <i class="bi bi-person-video3"></i> Lecturer
    </a>
    &nbsp;&bull;&nbsp;
    <a href="../admin/forgot_pword.php">
        <i class="bi bi-shield-lock"></i> Admin
    </a>
</div>


    </div>
</div>

<!-- Site footer -->
<footer class="site-footer">
    &copy; <?= date('Y') ?>
    <strong>University of skills training, and Entrepreneurial Development (USTED)</strong>
    &mdash; Online Examination System
</footer>

<script src="../assets/js/bootstrap.bundle.min.js"></script>
<script>
// ── Your original logic: enable button only when Remember me is checked ──
const checkbox = document.getElementById("remember");
const button   = document.getElementById("loginBtn");
checkbox.addEventListener("change", function () {
    button.disabled = !this.checked;
});

// ── Password show/hide ──
function togglePass() {
    const pw   = document.getElementById('password');
    const icon = document.getElementById('eye-icon');
    const hidden = pw.type === 'password';
    pw.type        = hidden ? 'text'       : 'password';
    icon.className = hidden ? 'bi bi-eye-slash' : 'bi bi-eye';
}
</script>

</body>
</html>