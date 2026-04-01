<?php
session_start();
require_once "../config/db.php";

// ── Auth guard: works for both lecturer and student ───────────────
if (!isset($_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit();
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$role    = $_SESSION['role'];

if (!$user_id) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit();
}

$success = $error = '';

// ── Handle form submission ────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_pw  = $_POST['current_password']  ?? '';
    $new_pw      = $_POST['new_password']       ?? '';
    $confirm_pw  = $_POST['confirm_password']   ?? '';

    if (!$current_pw || !$new_pw || !$confirm_pw) {
        $error = "All fields are required.";

    } elseif (strlen($new_pw) < 8) {
        $error = "New password must be at least 8 characters.";

    } elseif ($new_pw !== $confirm_pw) {
        $error = "New password and confirmation do not match.";

    } elseif ($new_pw === $current_pw) {
        $error = "New password must be different from your current password.";

    } else {
        // Fetch current hashed password from DB
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            $error = "User account not found.";

        } elseif (!password_verify($current_pw, $row['password'])) {
            $error = "Current password is incorrect.";

        } else {
            // Hash and save new password
            $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $new_hash, $user_id);

            if ($stmt->execute()) {
                $success = "Password changed successfully!";
            } else {
                error_log("change_password update failed: " . $conn->error);
                $error = "Failed to update password. Please try again.";
            }
            $stmt->close();
        }
    }
}

// Back link depends on role
$dashboard = ($role === 'student') ? 'dashboard.php' : 'dashboard.php';

include "../includes/header.php";
?>

<style>
.pw-shell {
    max-width: 520px;
    margin: 0 auto;
    padding: 2rem 1.25rem 4rem;
}

.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.75rem;
}
.page-header h1 {
    font-size: 1.4rem; font-weight: 700;
    color: #e6edf3; margin-bottom: .2rem;
}
.page-header .sub { font-size: .82rem; color: #8b949e; }

.btn-back {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .45rem 1rem;
    border-radius: 8px;
    border: 1px solid #30363d;
    background: #161b22;
    color: #8b949e;
    font-size: .83rem; font-weight: 600;
    text-decoration: none;
    transition: all .18s;
}
.btn-back:hover { border-color: #58a6ff; color: #58a6ff; }

/* Flash */
.flash {
    display: flex; align-items: center; gap: .6rem;
    padding: .75rem 1rem; border-radius: 9px;
    font-size: .85rem; margin-bottom: 1.25rem;
    border: 1px solid transparent;
}
.flash-success { background: #0d2818; border-color: #238636; color: #3fb950; }
.flash-error   { background: #1e1212; border-color: #6e2020; color: #f85149; }

/* Card */
.pw-card {
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 12px;
    overflow: hidden;
}
.pw-card-head {
    padding: .9rem 1.25rem;
    border-bottom: 1px solid #30363d;
    background: #1c2330;
    font-size: .92rem; font-weight: 700;
    color: #e6edf3;
    display: flex; align-items: center; gap: .45rem;
}
.pw-card-head i { color: #58a6ff; }
.pw-card-body { padding: 1.5rem 1.25rem; }

/* Form fields */
.f-group { margin-bottom: 1.1rem; }
.f-label {
    display: block;
    font-size: .74rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: #8b949e; margin-bottom: .38rem;
}

.f-input-wrap { position: relative; }
.f-input {
    width: 100%;
    background: #1c2330;
    border: 1px solid #30363d;
    border-radius: 8px;
    padding: .55rem 2.5rem .55rem .85rem;
    color: #e6edf3;
    font-size: .87rem; font-family: inherit;
    outline: none;
    transition: border-color .2s;
}
.f-input::placeholder { color: #484f58; }
.f-input:focus { border-color: #58a6ff; }
.f-input.valid   { border-color: #3fb950; }
.f-input.invalid { border-color: #f85149; }

/* Eye toggle */
.eye-btn {
    position: absolute; right: .75rem; top: 50%;
    transform: translateY(-50%);
    background: none; border: none;
    color: #8b949e; cursor: pointer;
    font-size: .9rem; padding: 0;
    transition: color .15s;
}
.eye-btn:hover { color: #e6edf3; }

/* Strength meter */
.strength-bar {
    height: 4px;
    border-radius: 2px;
    background: #30363d;
    margin-top: .4rem;
    overflow: hidden;
}
.strength-fill {
    height: 100%;
    border-radius: 2px;
    width: 0;
    transition: width .3s, background .3s;
}
.strength-label {
    font-size: .72rem;
    color: #8b949e;
    margin-top: .25rem;
    min-height: 1rem;
}

/* Requirements checklist */
.req-list {
    display: flex;
    flex-direction: column;
    gap: .3rem;
    margin-top: .6rem;
}
.req-item {
    display: flex; align-items: center; gap: .4rem;
    font-size: .78rem; color: #8b949e;
    transition: color .2s;
}
.req-item.met { color: #3fb950; }
.req-item i { font-size: .75rem; width: 14px; }

.divider {
    height: 1px; background: #30363d;
    margin: 1.25rem 0;
}

/* Submit button */
.btn-submit {
    width: 100%; padding: .65rem;
    border-radius: 9px; border: none;
    background: #58a6ff; color: #0d1117;
    font-size: .9rem; font-weight: 700;
    cursor: pointer; font-family: inherit;
    display: flex; align-items: center; justify-content: center; gap: .45rem;
    transition: filter .18s, transform .15s;
    margin-top: .25rem;
}
.btn-submit:hover  { filter: brightness(1.1); }
.btn-submit:active { transform: scale(.98); }
.btn-submit:disabled { opacity: .5; cursor: not-allowed; filter: none; }
</style>

<div class="pw-shell">

    <!-- Header -->
    <div class="page-header">
        <div>
            <h1><i class="bi bi-lock-fill" style="color:#58a6ff;margin-right:.4rem"></i>Change Password</h1>
            <div class="sub">Update your account password</div>
        </div>
        <a href="<?= $dashboard ?>" class="btn-back">
            <i class="bi bi-arrow-left"></i> Dashboard
        </a>
    </div>

    <!-- Flash messages -->
    <?php if ($success): ?>
    <div class="flash flash-success">
        <i class="bi bi-check-circle-fill"></i> <?= $success ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="flash flash-error">
        <i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>

    <!-- Card -->
    <div class="pw-card">
        <div class="pw-card-head">
            <i class="bi bi-shield-lock-fill"></i> Update Password
        </div>
        <div class="pw-card-body">
            <form method="POST" action="change_password.php" id="pwForm">

                <!-- Current password -->
                <div class="f-group">
                    <label class="f-label">Current Password</label>
                    <div class="f-input-wrap">
                        <input type="password" name="current_password" id="currentPw"
                               class="f-input" placeholder="Enter your current password" required>
                        <button type="button" class="eye-btn" onclick="toggleEye('currentPw', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>

                <div class="divider"></div>

                <!-- New password -->
                <div class="f-group">
                    <label class="f-label">New Password</label>
                    <div class="f-input-wrap">
                        <input type="password" name="new_password" id="newPw"
                               class="f-input" placeholder="At least 8 characters" required
                               oninput="checkStrength(this.value); checkMatch()">
                        <button type="button" class="eye-btn" onclick="toggleEye('newPw', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <!-- Strength meter -->
                    <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                    <div class="strength-label" id="strengthLabel"></div>
                    <!-- Requirements -->
                    <div class="req-list">
                        <div class="req-item" id="req-len">
                            <i class="bi bi-x-circle"></i> At least 8 characters
                        </div>
                        <div class="req-item" id="req-upper">
                            <i class="bi bi-x-circle"></i> One uppercase letter
                        </div>
                        <div class="req-item" id="req-num">
                            <i class="bi bi-x-circle"></i> One number
                        </div>
                        <div class="req-item" id="req-special">
                            <i class="bi bi-x-circle"></i> One special character (!@#$%...)
                        </div>
                    </div>
                </div>

                <!-- Confirm password -->
                <div class="f-group">
                    <label class="f-label">Confirm New Password</label>
                    <div class="f-input-wrap">
                        <input type="password" name="confirm_password" id="confirmPw"
                               class="f-input" placeholder="Re-enter new password" required
                               oninput="checkMatch()">
                        <button type="button" class="eye-btn" onclick="toggleEye('confirmPw', this)">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                    <div class="strength-label" id="matchLabel"></div>
                </div>

                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="bi bi-lock-fill"></i> Update Password
                </button>

            </form>
        </div>
    </div>

</div><!-- /pw-shell -->

<script>
// ── Show / hide password ──────────────────────────────────────────
function toggleEye(inputId, btn) {
    const inp = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

// ── Password strength ─────────────────────────────────────────────
function checkStrength(val) {
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');

    const checks = {
        len:     val.length >= 8,
        upper:   /[A-Z]/.test(val),
        num:     /[0-9]/.test(val),
        special: /[^A-Za-z0-9]/.test(val),
    };

    // Update requirement items
    setReq('req-len',     checks.len);
    setReq('req-upper',   checks.upper);
    setReq('req-num',     checks.num);
    setReq('req-special', checks.special);

    const score = Object.values(checks).filter(Boolean).length;
    const configs = [
        { w: '0%',   color: '#30363d', text: '' },
        { w: '25%',  color: '#f85149', text: 'Weak' },
        { w: '50%',  color: '#d29922', text: 'Fair' },
        { w: '75%',  color: '#58a6ff', text: 'Good' },
        { w: '100%', color: '#3fb950', text: 'Strong' },
    ];
    const cfg = configs[score];
    fill.style.width      = cfg.w;
    fill.style.background = cfg.color;
    label.style.color     = cfg.color;
    label.textContent     = cfg.text;
}

function setReq(id, met) {
    const el   = document.getElementById(id);
    const icon = el.querySelector('i');
    el.classList.toggle('met', met);
    icon.className = met ? 'bi bi-check-circle-fill' : 'bi bi-x-circle';
}

// ── Match check ───────────────────────────────────────────────────
function checkMatch() {
    const np = document.getElementById('newPw').value;
    const cp = document.getElementById('confirmPw').value;
    const lbl = document.getElementById('matchLabel');
    const inp = document.getElementById('confirmPw');

    if (!cp) {
        lbl.textContent = '';
        inp.classList.remove('valid', 'invalid');
        return;
    }
    if (np === cp) {
        lbl.style.color  = '#3fb950';
        lbl.textContent  = 'Passwords match';
        inp.classList.add('valid');
        inp.classList.remove('invalid');
    } else {
        lbl.style.color  = '#f85149';
        lbl.textContent  = 'Passwords do not match';
        inp.classList.add('invalid');
        inp.classList.remove('valid');
    }
}
</script>

<?php include "../includes/footer.php"; ?>