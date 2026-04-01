<?php
date_default_timezone_set('Africa/Accra');
session_start();
require_once "../config/db.php";

$error = $success = "";
$step = isset($_SESSION['otp_lecturer_id']) ? 2 : 1;

// ── STEP 1: Request OTP ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_otp'])) {
    $email = trim($_POST['email'] ?? '');
    if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Enter a valid email address.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM lecturers WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            $error = "No lecturer account found with that email.";
        } else {
            $lecturer_id = $res->fetch_assoc()['id'];
            $otp         = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $expires     = date('Y-m-d H:i:s', strtotime('+15 minutes'));

            $upd = $conn->prepare("UPDATE lecturers SET reset_token = ?, reset_expires = ? WHERE id = ?");
            $upd->bind_param("ssi", $otp, $expires, $lecturer_id);
            $upd->execute();
            $upd->close();

            $_SESSION['otp_lecturer_id']    = $lecturer_id;
            $_SESSION['otp_lecturer_email'] = $email;
            $step = 2;

            // ---- FOR LOCAL TESTING ONLY ----
            $success = "OTP (testing only): <strong>$otp</strong>";
        }
        $stmt->close();
    }
}

// ── STEP 2: Verify OTP + Set New Password ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $lecturer_id = $_SESSION['otp_lecturer_id'] ?? null;
    $otp_in      = trim($_POST['otp'] ?? '');
    $password    = $_POST['password']  ?? '';
    $password2   = $_POST['password2'] ?? '';

    if (!$lecturer_id) {
        $error = "Session expired. Please start over.";
        $step  = 1; 
        unset($_SESSION['otp_lecturer_id'], $_SESSION['otp_lecturer_email']);
    } elseif (strlen($otp_in) !== 6 || !ctype_digit($otp_in)) {
        $error = "Enter the complete 6-digit code.";
    } elseif (strlen($password) < 8) {
        $error = "Password must be at least 8 characters.";
    } elseif ($password !== $password2) {
        $error = "Passwords do not match.";
    } else {
        $stmt = $conn->prepare("SELECT id FROM lecturers WHERE id = ? AND reset_token = ? AND reset_expires > NOW()");
        $stmt->bind_param("is", $lecturer_id, $otp_in);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res->num_rows === 0) {
            $error = "Invalid or expired code. Try again or request a new one.";
        } else {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE lecturers SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
            $upd->bind_param("si", $hashed, $lecturer_id);
            $upd->execute();
            $upd->close();

            unset($_SESSION['otp_lecturer_id'], $_SESSION['otp_lecturer_email']);
            $step    = 3;
            $success = "Password updated! <a href='login.php'>Login now &rarr;</a>";
        }
        $stmt->close();
    }
}

// ── Resend OTP ─────────────────────────────────────────────────────────
if (isset($_POST['resend'])) {
    unset($_SESSION['otp_lecturer_id'], $_SESSION['otp_lecturer_email']);
    header("Location: " . $_SERVER['PHP_SELF']); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password – OES Lecturer</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
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
            background: var(--teal-dark);
            display: flex;
            flex-direction: column;
        }

        /* ── Topbar ── */
        .topbar {
            background: var(--teal);
            height: 56px;
            display: flex; align-items: center;
            padding: 0 20px; gap: 14px;
            box-shadow: 0 2px 6px rgba(0,0,0,.2);
            flex-shrink: 0;
        }
        .topbar-brand { display:flex; align-items:center; gap:11px; text-decoration:none; }
        .topbar-brand-icon {
            width:38px; height:38px;
            background:rgba(255,255,255,.95);
            border-radius:50%; display:grid; place-items:center;
            color:var(--teal); font-size:1.1rem; flex-shrink:0;
        }
        .topbar-brand-text .uni {
            font-size:.66rem; font-weight:700; letter-spacing:.05em;
            text-transform:uppercase; color:rgba(255,255,255,.8); line-height:1;
        }
        .topbar-brand-text .sys { font-size:.95rem; font-weight:700; color:#fff; line-height:1.3; }
        .topbar-spacer { flex:1; }
        .topbar-links a {
            color:rgba(255,255,255,.85); text-decoration:none;
            font-size:.8rem; font-weight:600; padding:5px 12px;
            border-radius:3px; transition:background .15s;
        }
        .topbar-links a:hover { background:rgba(255,255,255,.18); color:#fff; }

        /* ── Breadcrumb ── */
        .breadcrumb-row {
            background:#fff; border-bottom:1px solid var(--teal-border);
            padding:8px 20px; display:flex; align-items:center; gap:6px;
            font-size:.78rem; color:#6b7c8d; flex-shrink:0;
        }
        .breadcrumb-row a { color:var(--teal); text-decoration:none; }
        .breadcrumb-row a:hover { text-decoration:underline; }

        /* ── Page body ── */
        .page-body {
            flex:1; display:flex; align-items:flex-start;
            justify-content:center; padding:44px 16px 56px;
        }

        /* ── Card ── */
        .login-card {
            background:#fff;
            border:1px solid var(--teal-border);
            border-top:4px solid var(--teal);
            border-radius:5px;
            width:100%; max-width:440px;
            box-shadow:0 3px 16px rgba(0,0,0,.09);
            overflow:hidden;
            animation:slideUp .3s ease both;
        }
        @keyframes slideUp {
            from { opacity:0; transform:translateY(16px); }
            to   { opacity:1; transform:translateY(0); }
        }
        .card-head {
            background:var(--teal-pale);
            border-bottom:1px solid var(--teal-border);
            padding:18px 26px;
            display:flex; align-items:center; gap:13px;
        }
        .card-head-icon {
            width:42px; height:42px;
            background:var(--teal); border-radius:50%;
            display:grid; place-items:center;
            color:#fff; font-size:1.1rem; flex-shrink:0;
        }
        .card-head-text h2 { font-size:1rem; font-weight:700; color:var(--teal-dark); margin-bottom:2px; }
        .card-head-text p  { font-size:.76rem; color:#6b7c8d; }

        .card-body { padding:24px 26px 28px; }

        /* ── Steps indicator ── */
        .steps { display:flex; align-items:center; justify-content:center; gap:.5rem; margin-bottom:1.6rem; }
        .step-dot {
            width:28px; height:28px; border-radius:50%;
            display:flex; align-items:center; justify-content:center;
            font-size:.75rem; font-weight:700;
            background:#e2e8f0; color:#94a3b8; transition:all .3s;
        }
        .step-dot.active { background:var(--teal); color:#fff; }
        .step-dot.done   { background:#22c55e; color:#fff; }
        .step-line { flex:1; height:2px; background:var(--teal-border); max-width:48px; }

        /* ── Alerts ── */
        .alert-error {
            display:flex; align-items:flex-start; gap:10px;
            background:#fef2f2; border:1px solid #fca5a5;
            border-left:4px solid #b91c1c;
            border-radius:5px; padding:10px 14px;
            font-size:.84rem; color:#b91c1c; margin-bottom:16px;
        }
        .alert-info {
            display:flex; align-items:flex-start; gap:10px;
            background:var(--teal-pale); border:1px solid var(--teal-border);
            border-left:4px solid var(--teal);
            border-radius:5px; padding:10px 14px;
            font-size:.84rem; color:var(--teal-dark); margin-bottom:16px;
        }
        .alert-success {
            display:flex; align-items:flex-start; gap:10px;
            background:#f0fdf4; border:1px solid #86efac;
            border-left:4px solid #166534;
            border-radius:5px; padding:10px 14px;
            font-size:.84rem; color:#166534; margin-bottom:16px;
        }

        /* ── Fields ── */
        .field { margin-bottom:16px; }
        .field label {
            display:block; font-size:.78rem; font-weight:700;
            color:#4a5568; margin-bottom:5px;
        }
        .input-wrap { position:relative; }
        .input-icon {
            position:absolute; left:11px; top:50%;
            transform:translateY(-50%);
            color:#aab; font-size:.9rem; pointer-events:none;
            transition:color .15s;
        }
        .form-input {
            width:100%;
            border:1.5px solid #ced4da;
            border-radius:5px;
            padding:10px 40px 10px 34px;
            font-family:inherit; font-size:.88rem;
            color:#2c3e50; background:#fff; outline:none;
            transition:border-color .18s, box-shadow .18s;
        }
        .form-input::placeholder { color:#c0c8d0; }
        .form-input:focus {
            border-color:var(--teal);
            box-shadow:0 0 0 3px var(--teal-glow);
        }
        .eye-btn {
            position:absolute; right:10px; top:50%;
            transform:translateY(-50%);
            background:none; border:none; color:#aab;
            cursor:pointer; font-size:.88rem; padding:4px;
            line-height:1; transition:color .15s;
        }
        .eye-btn:hover { color:var(--teal); }

        /* ── OTP boxes ── */
        .otp-group { display:flex; gap:.45rem; justify-content:center; margin-bottom:1.2rem; }
        .otp-box {
            width:48px; height:54px; font-size:1.4rem; font-weight:700;
            text-align:center; border:1.5px solid #ced4da; border-radius:5px;
            color:#2c3e50; background:#fff; outline:none;
            transition:border-color .18s, box-shadow .18s;
            font-family:inherit;
        }
        .otp-box:focus {
            border-color:var(--teal);
            box-shadow:0 0 0 3px var(--teal-glow);
        }

        /* ── Strength bar ── */
        .strength-bar { height:4px; border-radius:4px; background:#e2e8f0; margin-top:.4rem; overflow:hidden; }
        .strength-fill { height:100%; border-radius:4px; width:0; transition:width .3s,background .3s; }

        /* ── Buttons ── */
        .btn-teal {
            background:var(--teal); color:#fff; border:none;
            width:100%; padding:.65rem; border-radius:5px;
            font-family:inherit; font-size:.9rem; font-weight:700;
            cursor:pointer; transition:background .18s;
            display:flex; align-items:center; justify-content:center; gap:8px;
        }
        .btn-teal:hover { background:var(--teal-dark); }

        /* ── Card foot ── */
        .card-foot {
            border-top:1px solid #eee;
            background:#f8fafb;
            padding:13px 26px;
            text-align:center; font-size:.74rem; color:#6b7c8d;
        }
        .card-foot a { color:var(--teal); text-decoration:none; }
        .card-foot a:hover { text-decoration:underline; }

        /* ── Back / resend links ── */
        .back-link { text-align:center; margin-top:12px; font-size:.78rem; color:#6b7c8d; }
        .back-link a { color:var(--teal); text-decoration:none; font-weight:600; }
        .back-link a:hover { text-decoration:underline; }
        .resend-btn {
            background:none; border:none; color:var(--teal);
            font-weight:600; padding:0; cursor:pointer;
            font-size:.78rem; font-family:inherit;
        }
        .resend-btn:hover { text-decoration:underline; }

        /* ── Countdown ── */
        .countdown-wrap { text-align:center; font-size:.8rem; color:#6b7c8d; margin-bottom:.8rem; }
        .countdown-wrap span { font-weight:700; color:var(--teal); }

        /* ── Site footer ── */
        .site-footer {
            background:var(--teal-dark); color:rgba(255,255,255,.65);
            text-align:center; padding:12px 16px;
            font-size:.72rem; flex-shrink:0;
        }
        .site-footer strong { color:#fff; }

        @media (max-width:460px) {
            .card-body { padding:18px 16px 22px; }
            .card-head { padding:14px 16px; }
            .otp-box   { width:42px; height:48px; font-size:1.2rem; }
        }
    </style>
</head>
<body>

<!-- Topbar -->
<header class="topbar">
    <a class="topbar-brand" href="#">
        <div class="topbar-brand-icon"><i class="bi bi-person-video3"></i></div>
        <div class="topbar-brand-text">
            <div class="uni">USTED — Online Exam System</div>
            <div class="sys">Lecturer Portal</div>
        </div>
    </a>
    <div class="topbar-spacer"></div>
    <div class="topbar-links">
        <a href="../auth/login.php"><i class="bi bi-box-arrow-in-right"></i> Login</a>
    </div>
</header>

<!-- Breadcrumb -->
<div class="breadcrumb-row">
    <a href="login.php">Home</a>
    <i class="bi bi-chevron-right" style="font-size:.6rem;color:#ccc;"></i>
    <a href="login.php">Login</a>
    <i class="bi bi-chevron-right" style="font-size:.6rem;color:#ccc;"></i>
    <span>Forgot Password</span>
</div>

<!-- Page body -->
<div class="page-body">
<div class="login-card">

    <!-- Card head -->
    <div class="card-head">
        <div class="card-head-icon">
            <i class="bi bi-<?= $step === 3 ? 'check-circle' : ($step === 2 ? 'key' : 'lock') ?>"></i>
        </div>
        <div class="card-head-text">
            <h2><?= $step === 1 ? 'Forgot Password' : ($step === 2 ? 'Enter Code & New Password' : 'Password Updated') ?></h2>
            <p>
                <?php if ($step === 1): ?>Enter your lecturer email to receive a reset code.
                <?php elseif ($step === 2): ?>Check your email for the 6-digit OTP.
                <?php else: ?>Your password has been changed successfully.<?php endif; ?>
            </p>
        </div>
    </div>

    <div class="card-body">

        <!-- Steps -->
        <div class="steps">
            <div class="step-dot <?= $step >= 1 ? ($step > 1 ? 'done' : 'active') : '' ?>">
                <?= $step > 1 ? '<i class="bi bi-check" style="font-size:.7rem"></i>' : '1' ?>
            </div>
            <div class="step-line"></div>
            <div class="step-dot <?= $step >= 2 ? ($step > 2 ? 'done' : 'active') : '' ?>">
                <?= $step > 2 ? '<i class="bi bi-check" style="font-size:.7rem"></i>' : '2' ?>
            </div>
            <div class="step-line"></div>
            <div class="step-dot <?= $step >= 3 ? 'done' : '' ?>">
                <?= $step >= 3 ? '<i class="bi bi-check" style="font-size:.7rem"></i>' : '3' ?>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert-error"><i class="bi bi-exclamation-triangle-fill"></i><span><?= htmlspecialchars($error) ?></span></div>
        <?php endif; ?>

        <?php if ($step === 1): ?>
        <!-- ══ STEP 1 ══ -->
        <form method="POST" novalidate>
            <div class="field">
                <label><i class="bi bi-envelope-fill"></i> Email Address</label>
                <div class="input-wrap">
                    <input type="email" name="email" class="form-input"
                           placeholder="lecturer@oes.edu.gh"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus>
                    <i class="bi bi-envelope input-icon"></i>
                </div>
            </div>
            <button type="submit" name="request_otp" class="btn-teal">
                <i class="bi bi-send"></i> Send Reset Code
            </button>
        </form>

        <?php elseif ($step === 2): ?>
        <!-- ══ STEP 2 ══ -->
        <?php if ($success): ?>
            <div class="alert-info"><i class="bi bi-info-circle-fill"></i><span><?= $success ?></span></div>
        <?php endif; ?>

        <div class="countdown-wrap">Code expires in: <span id="countdown">15:00</span></div>

        <form method="POST" novalidate id="resetForm">
            <input type="hidden" name="otp" id="otpHidden">

            <div class="field">
                <label style="text-align:center;display:block;"><i class="bi bi-shield-lock"></i> 6-Digit Reset Code</label>
                <div class="otp-group">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <input type="text" class="otp-box" id="otp<?= $i ?>"
                               maxlength="1" inputmode="numeric" pattern="[0-9]" autocomplete="off">
                    <?php endfor; ?>
                </div>
            </div>

            <div class="field">
                <label><i class="bi bi-key-fill"></i> New Password</label>
                <div class="input-wrap">
                    <input type="password" name="password" id="pw1" class="form-input"
                           placeholder="Min. 8 characters" required minlength="8">
                    <i class="bi bi-lock input-icon"></i>
                    <button type="button" class="eye-btn" onclick="togglePw('pw1','eye1')">
                        <i class="bi bi-eye" id="eye1"></i>
                    </button>
                </div>
                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                <small id="strengthLabel" style="font-size:.74rem;"></small>
            </div>

            <div class="field">
                <label><i class="bi bi-lock-fill"></i> Confirm Password</label>
                <div class="input-wrap">
                    <input type="password" name="password2" id="pw2" class="form-input"
                           placeholder="Repeat password" required>
                    <i class="bi bi-lock-fill input-icon"></i>
                    <button type="button" class="eye-btn" onclick="togglePw('pw2','eye2')">
                        <i class="bi bi-eye" id="eye2"></i>
                    </button>
                </div>
                <small class="text-danger d-none" id="matchMsg" style="font-size:.74rem;">Passwords do not match.</small>
            </div>

            <button type="submit" name="reset_password" class="btn-teal">
                <i class="bi bi-floppy"></i> Update Password
            </button>
        </form>

        <div class="back-link" style="margin-top:10px;">
            Didn't get the code?
            <form method="POST" style="display:inline">
                <button type="submit" name="resend" class="resend-btn">Resend</button>
            </form>
        </div>

        <?php elseif ($step === 3): ?>
        <!-- ══ STEP 3 ══ -->
        <div class="alert-success" style="justify-content:center;text-align:center;display:block;padding:1.2rem;">
            <i class="bi bi-check-circle-fill" style="font-size:2rem;display:block;margin-bottom:.5rem;"></i>
            <?= $success ?>
        </div>
        <?php endif; ?>

        <?php if ($step < 3): ?>
        <div class="back-link">
            <a href="../auth/login.php"><i class="bi bi-arrow-left"></i> Back to Login</a>
        </div>
        <?php endif; ?>

    </div><!-- card-body -->

    <div class="card-foot">
        &copy; <?= date('Y') ?> USTED &mdash; Online Examination System
    </div>

</div><!-- login-card -->
</div><!-- page-body -->

<footer class="site-footer">
    &copy; <?= date('Y') ?> <strong>University of Science, Technology, Environment and Development (USTED)</strong> &mdash; Online Examination System
</footer>

<script>
// ── OTP boxes ────────────────────────────────────────────────────────────────
const boxes = document.querySelectorAll('.otp-box');
boxes.forEach((box, i) => {
    box.addEventListener('input', () => {
        box.value = box.value.replace(/\D/g, '');
        if (box.value && i < boxes.length - 1) boxes[i + 1].focus();
        combineOtp();
    });
    box.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !box.value && i > 0) {
            boxes[i - 1].focus(); boxes[i - 1].value = ''; combineOtp();
        }
    });
    box.addEventListener('paste', e => {
        e.preventDefault();
        const digits = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
        digits.split('').forEach((d, j) => { if (boxes[j]) boxes[j].value = d; });
        boxes[Math.min(digits.length, 5)]?.focus();
        combineOtp();
    });
});
function combineOtp() {
    const h = document.getElementById('otpHidden');
    if (h) h.value = [...boxes].map(b => b.value).join('');
}

// ── Show/hide password ───────────────────────────────────────────────────────
function togglePw(inputId, iconId) {
    const inp = document.getElementById(inputId);
    const ico = document.getElementById(iconId);
    inp.type = inp.type === 'password' ? 'text' : 'password';
    ico.className = inp.type === 'text' ? 'bi bi-eye-slash' : 'bi bi-eye';
}

// ── Strength meter ───────────────────────────────────────────────────────────
document.getElementById('pw1')?.addEventListener('input', function () {
    const val = this.value, fill = document.getElementById('strengthFill'), label = document.getElementById('strengthLabel');
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        { pct:'0%',   color:'#e2e8f0', text:'' },
        { pct:'25%',  color:'#ef4444', text:'Weak' },
        { pct:'50%',  color:'#f97316', text:'Fair' },
        { pct:'75%',  color:'#eab308', text:'Good' },
        { pct:'100%', color:'#22c55e', text:'Strong ✓' },
    ];
    fill.style.width = levels[score].pct;
    fill.style.background = levels[score].color;
    label.textContent = levels[score].text;
    label.style.color = levels[score].color;
});

// ── Match check ──────────────────────────────────────────────────────────────
document.getElementById('pw2')?.addEventListener('input', function () {
    document.getElementById('matchMsg')?.classList.toggle('d-none', this.value === document.getElementById('pw1').value);
});

// ── Block submit on mismatch ─────────────────────────────────────────────────
document.getElementById('resetForm')?.addEventListener('submit', function (e) {
    combineOtp();
    if (document.getElementById('pw1')?.value !== document.getElementById('pw2')?.value) {
        e.preventDefault();
        document.getElementById('matchMsg')?.classList.remove('d-none');
    }
});

// ── 15-min countdown ─────────────────────────────────────────────────────────
(function () {
    const el = document.getElementById('countdown');
    if (!el) return;
    let secs = 15 * 60;
    const tick = setInterval(() => {
        secs--;
        if (secs <= 0) {
            clearInterval(tick);
            el.parentElement.innerHTML = '<span style="color:#b91c1c;font-weight:600;">Code expired. Please <a href="" style="color:#b91c1c;">request a new one</a>.</span>';
            return;
        }
        const m = String(Math.floor(secs / 60)).padStart(2, '0');
        const s = String(secs % 60).padStart(2, '0');
        el.textContent = `${m}:${s}`;
        if (secs <= 60) el.style.color = '#b91c1c';
    }, 1000);
})();
</script>
</body>
</html>