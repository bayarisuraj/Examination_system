<?php
session_start();
date_default_timezone_set('Africa/Accra'); // Ghana = UTC+0 — must match all exam files
require_once "../config/db.php";

// ── Auth guard ────────────────────────────────────────────────────
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

$lecturer_id = (int)($_SESSION['user_id'] ?? 0);
if (!$lecturer_id) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit();
}

$error = '';

// ── Get Exam ID ───────────────────────────────────────────────────
$exam_id = (int)($_GET['exam_id'] ?? 0);
if ($exam_id <= 0) {
    $_SESSION['error'] = "Invalid Exam ID.";
    header("Location: manage_exams.php");
    exit();
}

// ── Fetch Exam Details ────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT e.id, e.title, e.total_marks, e.duration, e.exam_date, e.course_id, c.course_name
    FROM exams e
    JOIN courses c ON e.course_id = c.id
    WHERE e.id = ? AND c.lecturer_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $exam_id, $lecturer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    $_SESSION['error'] = "Exam not found or you don't have permission.";
    header("Location: manage_exams.php");
    exit();
}

$exam = $result->fetch_assoc();
$stmt->close();

// ── Parse stored exam_date into date + time parts for the form ────
$stored_dt   = DateTime::createFromFormat('Y-m-d H:i:s', $exam['exam_date']);
if (!$stored_dt) $stored_dt = new DateTime($exam['exam_date']);
$form_date   = $stored_dt->format('Y-m-d'); // for <input type="date">
$form_time   = $stored_dt->format('H:i');   // for <input type="time">

// ── Fetch Lecturer Courses ────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, course_name FROM courses WHERE lecturer_id = ? ORDER BY course_name ASC");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$courses_res = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Handle POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title']       ?? '');
    $total_marks = (int)($_POST['total_marks'] ?? 0);
    $duration    = (int)($_POST['duration']    ?? 0);
    $exam_date   = trim($_POST['exam_date']    ?? '');
    $exam_time   = trim($_POST['exam_time']    ?? '00:00');
    $course_id   = (int)($_POST['course_id']   ?? 0);

    if (!$title || !$total_marks || !$duration || !$exam_date || !$course_id) {
        $error = "All fields are required.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exam_date)) {
        $error = "Invalid date format.";
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $exam_time)) {
        $error = "Invalid time format.";
    } else {
        // Combine date + time into full datetime string — same as manage_exams.php
        $datetime_raw = $exam_date . ' ' . $exam_time . ':00';
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $datetime_raw);

        if (!$dt) {
            $error = "Could not parse datetime: " . htmlspecialchars($datetime_raw);
        } else {
            $exam_datetime = $dt->format('Y-m-d H:i:s');

            $stmt = $conn->prepare("
                UPDATE exams
                SET title = ?, total_marks = ?, duration = ?, exam_date = ?, course_id = ?
                WHERE id = ? AND lecturer_id = ?
            ");
            $stmt->bind_param("siisiii",
                $title, $total_marks, $duration,
                $exam_datetime, $course_id,
                $exam_id, $lecturer_id
            );

            if ($stmt->execute()) {
                $_SESSION['success'] = "Exam updated successfully.";
                header("Location: manage_exams.php");
                exit();
            } else {
                $error = "Failed to update exam: " . $stmt->error;
            }
            $stmt->close();
        }
    }
}
?>

<?php include "../includes/header.php"; ?>

<style>
:root {
    --bg:      #0d1117;
    --surface: #161b22;
    --surface2:#1c2330;
    --border:  #30363d;
    --text:    #e6edf3;
    --muted:   #8b949e;
    --accent:  #58a6ff;
    --danger:  #f85149;
    --success: #3fb950;
}

.edit-shell {
    max-width: 560px;
    margin: 2.5rem auto 4rem;
    padding: 0 1rem;
}

.edit-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 14px;
    overflow: hidden;
}

.edit-card-head {
    background: var(--surface2);
    border-bottom: 1px solid var(--border);
    padding: 1.2rem 1.5rem;
    display: flex;
    align-items: center;
    gap: .6rem;
}
.edit-card-head i  { color: var(--accent); font-size: 1.2rem; }
.edit-card-head h2 { margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text); }
.edit-card-head p  { margin: 0; font-size: .8rem; color: var(--muted); }

.edit-card-body { padding: 1.5rem; }

.flash {
    display: flex; align-items: center; gap: .5rem;
    padding: .7rem 1rem; border-radius: 9px;
    font-size: .85rem; margin-bottom: 1.25rem;
    border: 1px solid transparent;
}
.flash-success { background: #0d2818; border-color: #238636; color: var(--success); }
.flash-error   { background: #1e1212; border-color: #6e2020; color: var(--danger); }

.f-label {
    display: block; font-size: .74rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--muted); margin-bottom: .35rem;
}
.f-input, .f-select {
    width: 100%; background: var(--surface2); border: 1px solid var(--border);
    border-radius: 8px; padding: .52rem .85rem;
    color: var(--text); font-size: .88rem; font-family: inherit;
    outline: none; transition: border-color .2s; margin-bottom: 1rem;
    box-sizing: border-box;
}
.f-input:focus, .f-select:focus { border-color: var(--accent); }
.f-input::placeholder { color: #484f58; }

.f-row   { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
.f-row-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: .75rem; }

.f-hint {
    font-size: .73rem; color: var(--muted);
    margin-top: -.7rem; margin-bottom: 1rem;
}

.btn-row {
    display: flex; gap: .75rem; margin-top: .5rem;
}
.btn-primary-custom {
    flex: 1; padding: .62rem; border-radius: 9px; border: none;
    background: var(--accent); color: #0d1117;
    font-size: .88rem; font-weight: 700; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: .4rem;
    transition: filter .18s;
}
.btn-primary-custom:hover { filter: brightness(1.1); }

.btn-cancel {
    flex: 1; padding: .62rem; border-radius: 9px;
    border: 1px solid var(--border); background: transparent;
    color: var(--muted); font-size: .88rem; font-weight: 600;
    text-decoration: none;
    display: flex; align-items: center; justify-content: center; gap: .4rem;
    transition: border-color .2s, color .2s;
}
.btn-cancel:hover { border-color: var(--danger); color: var(--danger); }

.time-note {
    background: #131c2b;
    border: 1px solid #1f3a5f;
    border-radius: 8px;
    padding: .65rem .9rem;
    font-size: .78rem;
    color: var(--accent);
    margin-bottom: 1rem;
    display: flex;
    align-items: flex-start;
    gap: .5rem;
}
.time-note i { flex-shrink: 0; margin-top: 1px; }
</style>

<div class="edit-shell">
    <div class="edit-card">
        <div class="edit-card-head">
            <div>
                <h2><i class="bi bi-pencil-square"></i> Edit Exam</h2>
                <p>Editing: <strong style="color:var(--text)"><?= htmlspecialchars($exam['course_name']) ?></strong></p>
            </div>
        </div>

        <div class="edit-card-body">

            <?php if (!empty($_SESSION['success'])): ?>
                <div class="flash flash-success"><i class="bi bi-check-circle-fill"></i><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="flash flash-error"><i class="bi bi-exclamation-triangle-fill"></i><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="flash flash-error"><i class="bi bi-exclamation-triangle-fill"></i><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="time-note">
                <i class="bi bi-info-circle-fill"></i>
                <span>Make sure the date and start time are correct — students can only take the exam during the active window.</span>
            </div>

            <form method="POST">
                <label class="f-label">Exam Title</label>
                <input type="text" name="title" class="f-input"
                       value="<?= htmlspecialchars($exam['title']) ?>" required>

                <label class="f-label">Course</label>
                <select name="course_id" class="f-select" required>
                    <?php foreach ($courses_res as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $c['id'] == $exam['course_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['course_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <!-- Date + Time side by side -->
                <div class="f-row">
                    <div>
                        <label class="f-label">Exam Date</label>
                        <input type="date" name="exam_date" class="f-input"
                               value="<?= $form_date ?>" required>
                    </div>
                    <div>
                        <label class="f-label">Start Time</label>
                        <input type="time" name="exam_time" class="f-input"
                               value="<?= $form_time ?>" required>
                    </div>
                </div>

                <!-- Duration + Total Marks -->
                <div class="f-row">
                    <div>
                        <label class="f-label">Duration (minutes)</label>
                        <input type="number" name="duration" class="f-input"
                               value="<?= (int)$exam['duration'] ?>"
                               min="1" max="480"
                               oninput="updateHint(this.value)" required>
                        <div class="f-hint" id="durHint"></div>
                    </div>
                    <div>
                        <label class="f-label">Total Marks</label>
                        <input type="number" name="total_marks" class="f-input"
                               value="<?= (int)$exam['total_marks'] ?>" min="1" required>
                    </div>
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn-primary-custom">
                        <i class="bi bi-check-circle"></i> Update Exam
                    </button>
                    <a href="manage_exams.php" class="btn-cancel">
                        <i class="bi bi-x-circle"></i> Cancel
                    </a>
                </div>
            </form>

        </div>
    </div>
</div>

<script>
function updateHint(val) {
    const n  = parseInt(val, 10);
    const el = document.getElementById('durHint');
    if (!n || n < 1) { el.textContent = ''; return; }
    const h  = Math.floor(n / 60);
    const m  = n % 60;
    el.textContent = h && m ? `${h}h ${m}m` : h ? `${h} hour${h > 1 ? 's' : ''}` : `${m} min`;
}
// Run on load so existing value shows hint
updateHint(document.querySelector('[name="duration"]').value);
</script>

<?php include "../includes/footer.php"; ?>