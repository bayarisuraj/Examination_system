<?php
session_start();
date_default_timezone_set('Africa/Accra'); // Ghana = UTC+0 — must match available_exams.php
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

$success = $error = '';

// ── Handle Add Exam ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_exam'])) {
    $title     = trim($_POST['title']     ?? '');
    $course_id = (int)($_POST['course_id'] ?? 0);
    $exam_date = trim($_POST['exam_date'] ?? '');
    $exam_time = trim($_POST['exam_time'] ?? '00:00');
    $duration  = (int)($_POST['duration'] ?? 0);

    if (!$title || !$course_id || !$exam_date || !$duration) {
        $error = "All fields are required.";
    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exam_date)) {
        $error = "Invalid date format. Got: " . htmlspecialchars($exam_date);
    } elseif (!preg_match('/^\d{2}:\d{2}$/', $exam_time)) {
        $error = "Invalid time format. Got: " . htmlspecialchars($exam_time);
    } else {
        // Build datetime string and parse with explicit format
        $exam_datetime_raw = $exam_date . ' ' . $exam_time . ':00';
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $exam_datetime_raw);

        if (!$dt) {
            $error = "Could not parse datetime: " . htmlspecialchars($exam_datetime_raw);
        } else {
            // Store as clean UTC+0 (Africa/Accra) datetime string — consistent with student side
            $exam_datetime = $dt->format('Y-m-d H:i:s');

            $stmt = $conn->prepare("
                INSERT INTO exams
                    (title, course_id, lecturer_id, exam_date, duration,
                     total_marks, is_published, status, created_by)
                VALUES (?, ?, ?, ?, ?, 0, 0, 'draft', ?)
            ");
            $stmt->bind_param("siisii",
                $title, $course_id, $lecturer_id,
                $exam_datetime, $duration, $lecturer_id
            );
            if ($stmt->execute()) {
                $new_exam_id = $conn->insert_id;
                $success = "Exam <strong>" . htmlspecialchars($title) . "</strong> created! "
                         . "<a href='add_question.php?exam_id={$new_exam_id}' style='color:inherit;font-weight:700'>"
                         . "Add questions now &rarr;</a>";
            } else {
                error_log("manage_exams insert failed: " . $conn->error);
                $error = "Failed to create exam: " . $conn->error;
            }
            $stmt->close();
        }
    }
}

// ── Handle Publish / Unpublish toggle ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $eid        = (int)($_POST['exam_id'] ?? 0);
    $new_status = $_POST['new_status'] === 'published' ? 'published' : 'draft';
    $stmt = $conn->prepare("UPDATE exams SET status = ? WHERE id = ? AND created_by = ?");
    $stmt->bind_param("sii", $new_status, $eid, $lecturer_id);
    $stmt->execute();
    $stmt->close();
    header("Location: manage_exams.php");
    exit();
}

// ── Handle Delete ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_exam'])) {
    $eid = (int)($_POST['exam_id'] ?? 0);
    $stmt = $conn->prepare("DELETE FROM exams WHERE id = ? AND created_by = ?");
    $stmt->bind_param("ii", $eid, $lecturer_id);
    $stmt->execute();
    $stmt->close();
    header("Location: manage_exams.php");
    exit();
}

// ── Fetch lecturer's courses ──────────────────────────────────────
$stmt = $conn->prepare("SELECT id, course_name FROM courses WHERE lecturer_id = ? ORDER BY course_name ASC");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Fetch exams — duration in seconds for JS, datetime formatted for display ─
$stmt = $conn->prepare("
    SELECT
        e.id,
        e.title,
        e.exam_date,
        e.duration,
        e.status,
        c.course_name,
        (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.id) AS question_count,
        (SELECT COUNT(*) FROM exam_attempts a  WHERE a.exam_id  = e.id AND a.status = 'completed') AS attempt_count
    FROM exams e
    JOIN courses c ON c.id = e.course_id
    WHERE e.created_by = ?
    ORDER BY e.exam_date DESC
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_exams = count($exams);

include "../includes/header.php";
?>

<style>
.page-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    margin-bottom: 1.75rem;
}
.page-header h1 { font-size: 1.5rem; font-weight: 700; color: #e6edf3; margin-bottom: .2rem; }
.page-header .sub { font-size: .83rem; color: #8b949e; }

.flash {
    display: flex; align-items: center; gap: .6rem;
    padding: .75rem 1rem; border-radius: 9px;
    font-size: .85rem; margin-bottom: 1.25rem;
    border: 1px solid transparent;
}
.flash-success { background: #0d2818; border-color: #238636; color: #3fb950; }
.flash-error   { background: #1e1212; border-color: #6e2020; color: #f85149; }

.main-grid {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 860px) { .main-grid { grid-template-columns: 1fr; } }

.form-panel {
    background: #161b22; border: 1px solid #30363d;
    border-radius: 12px; overflow: hidden;
    position: sticky; top: 1.5rem;
}
.panel-head {
    padding: .85rem 1.1rem; border-bottom: 1px solid #30363d;
    background: #1c2330; font-size: .92rem; font-weight: 700;
    color: #e6edf3; display: flex; align-items: center; gap: .45rem;
}
.panel-head i { color: #58a6ff; }
.panel-body { padding: 1.1rem; }

.f-label {
    display: block; font-size: .75rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: #8b949e; margin-bottom: .35rem;
}
.f-input, .f-select {
    width: 100%; background: #1c2330; border: 1px solid #30363d;
    border-radius: 8px; padding: .52rem .85rem;
    color: #e6edf3; font-size: .85rem; font-family: inherit;
    outline: none; transition: border-color .2s; margin-bottom: .85rem;
}
.f-input::placeholder { color: #484f58; }
.f-input:focus, .f-select:focus { border-color: #58a6ff; }
.f-row { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }

/* Duration helper hint */
.f-hint {
    font-size: .73rem; color: #8b949e;
    margin-top: -.6rem; margin-bottom: .85rem;
}

.btn-add-exam {
    width: 100%; padding: .6rem; border-radius: 9px; border: none;
    background: #58a6ff; color: #0d1117; font-size: .88rem; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    gap: .4rem; transition: filter .18s;
}
.btn-add-exam:hover { filter: brightness(1.12); }

.table-panel { background: #161b22; border: 1px solid #30363d; border-radius: 12px; overflow: hidden; }
.table-panel-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: .85rem 1.1rem; border-bottom: 1px solid #30363d;
    background: #1c2330; flex-wrap: wrap; gap: .6rem;
}
.table-panel-head h3 { font-size: .92rem; font-weight: 700; color: #e6edf3; margin: 0; }

.search-box { position: relative; }
.search-box i {
    position: absolute; left: .7rem; top: 50%;
    transform: translateY(-50%); color: #8b949e; font-size: .8rem; pointer-events: none;
}
.search-box input {
    background: #0d1117; border: 1px solid #30363d; border-radius: 8px;
    padding: .42rem .8rem .42rem 2rem; color: #e6edf3; font-size: .82rem;
    outline: none; width: 200px; transition: border-color .2s;
}
.search-box input:focus { border-color: #58a6ff; }
.search-box input::placeholder { color: #484f58; }

table { width: 100%; border-collapse: collapse; font-size: .86rem; }
thead th {
    background: #1c2330; padding: .65rem 1rem; text-align: left;
    font-size: .7rem; text-transform: uppercase; letter-spacing: .07em;
    font-weight: 700; color: #8b949e; border-bottom: 1px solid #30363d; white-space: nowrap;
}
tbody tr { border-bottom: 1px solid #30363d; transition: background .15s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: #1c2330; }
tbody td { padding: .72rem 1rem; color: #e6edf3; vertical-align: middle; }

.td-title strong { display: block; color: #e6edf3; }
.td-title span   { font-size: .77rem; color: #8b949e; }
.td-date { font-size: .78rem; color: #8b949e; font-family: 'DM Mono',monospace; white-space: nowrap; }

.status-pill {
    display: inline-block; padding: .2rem .6rem; border-radius: 20px;
    font-size: .71rem; font-weight: 700; text-transform: uppercase;
}
.sp-published { background: #0d2818; color: #3fb950; border: 1px solid #238636; }
.sp-draft     { background: #1c2330; color: #8b949e; border: 1px solid #30363d; }

.q-count { font-size: .78rem; color: #8b949e; font-family: 'DM Mono',monospace; }
.q-count.has-q { color: #3fb950; }

/* Duration display badge */
.dur-badge {
    display: inline-block;
    font-family: 'DM Mono', monospace;
    font-size: .78rem;
    color: #8b949e;
}
.dur-badge.long { color: #d29922; }

.act-btn {
    display: inline-flex; align-items: center; justify-content: center;
    width: 30px; height: 30px; border-radius: 7px;
    border: 1px solid #30363d; background: transparent;
    color: #8b949e; text-decoration: none; font-size: .82rem;
    transition: all .15s; cursor: pointer;
}
.act-btn:hover           { border-color: #58a6ff; color: #58a6ff; }
.act-btn.green:hover     { border-color: #3fb950; color: #3fb950; }
.act-btn.amber:hover     { border-color: #d29922; color: #d29922; }
.act-btn.red:hover       { border-color: #f85149; color: #f85149; }
.act-btn.purple:hover    { border-color: #bc8cff; color: #bc8cff; }
.act-btn.ai:hover        { border-color: #f78166; color: #f78166; }
.act-btn.publish:hover   { border-color: #3fb950; color: #3fb950; }
.act-btn.unpublish:hover { border-color: #d29922; color: #d29922; }

.empty-row td { padding: 3rem; text-align: center; color: #8b949e; }
.empty-row i  { display: block; font-size: 2rem; margin-bottom: .5rem; color: #30363d; }

.actions-group  { display: flex; gap: .35rem; flex-wrap: wrap; align-items: center; }
.actions-divider { width: 1px; height: 20px; background: #30363d; margin: 0 .1rem; }
</style>

<div class="page-header">
    <div>
        <h1 style="color: #0d1117;"><i class="bi bi-pencil-square" style="color:#58a6ff;margin-right:.4rem"></i>Manage Exams</h1>
        <div class="sub"><?= $total_exams ?> exam<?= $total_exams !== 1 ? 's' : '' ?> total</div>
    </div>
</div>

<?php if ($success): ?>
<div class="flash flash-success"><i class="bi bi-check-circle-fill"></i> <?= $success ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flash flash-error"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (!empty($_SESSION['success'])): ?>
<div class="flash flash-success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($_SESSION['success']) ?></div>
<?php unset($_SESSION['success']); endif; ?>

<div class="main-grid">

    <!-- ── Add exam form ── -->
    <div class="form-panel">
        <div class="panel-head"><i class="bi bi-plus-circle-fill"></i> Create New Exam</div>
        <div class="panel-body">
            <form method="POST" action="manage_exams.php">
                <label class="f-label">Exam Title</label>
                <input type="text" name="title" class="f-input" placeholder="e.g. Mid-Semester Exam" required>

                <label class="f-label">Course</label>
                <select name="course_id" class="f-select" required>
                    <option value="">-- Select Course --</option>
                    <?php foreach ($courses as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="f-row">
                    <div>
                        <label class="f-label">Exam Date</label>
                        <input type="date" name="exam_date" class="f-input" required>
                    </div>
                    <div>
                        <label class="f-label">Start Time</label>
                        <input type="time" name="exam_time" class="f-input" required>
                    </div>
                </div>

                <label class="f-label">Duration (minutes)</label>
                <input type="number" name="duration" id="durationInput" class="f-input"
                       min="1" max="480" placeholder="e.g. 60" required
                       oninput="updateDurationHint(this.value)">
                <div class="f-hint" id="durationHint">e.g. 60 = 1 hour, 90 = 1.5 hours</div>

                <button type="submit" name="add_exam" class="btn-add-exam">
                    <i class="bi bi-plus-lg"></i> Create Exam
                </button>
            </form>
        </div>
    </div>

    <!-- ── Exams table ── -->
    <div class="table-panel">
        <div class="table-panel-head">
            <h3><i class="bi bi-list-check" style="color:#58a6ff;margin-right:.4rem"></i>Your Exams</h3>
            <div class="search-box">
                <i class="bi bi-search"></i>
                <input type="text" id="searchExam" placeholder="Search exams…">
            </div>
        </div>
        <div style="overflow-x:auto">
            <table id="examsTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Exam</th>
                        <th>Date &amp; Time</th>
                        <th>Duration</th>
                        <th>Questions</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($exams)): $i = 1;
                    foreach ($exams as $exam):
                        // Parse stored datetime with explicit format for reliable display
                        $exam_dt  = DateTime::createFromFormat('Y-m-d H:i:s', $exam['exam_date']);
                        if (!$exam_dt) $exam_dt = new DateTime($exam['exam_date']);
                        $duration = (int)$exam['duration'];
                        $end_dt   = clone $exam_dt;
                        $end_dt->modify("+{$duration} minutes");
                    ?>
                    <tr data-title="<?= strtolower(htmlspecialchars($exam['title'])) ?>">
                        <td style="color:#8b949e;font-family:'DM Mono',monospace;font-size:.78rem"><?= $i++ ?></td>
                        <td class="td-title">
                            <strong><?= htmlspecialchars($exam['title']) ?></strong>
                            <span><?= htmlspecialchars($exam['course_name']) ?></span>
                        </td>
                        <td class="td-date">
                            <?= $exam_dt->format('d M Y') ?><br>
                            <span><?= $exam_dt->format('H:i') ?> &ndash; <?= $end_dt->format('H:i') ?></span>
                        </td>
                        <td>
                            <span class="dur-badge <?= $duration > 120 ? 'long' : '' ?>">
                                <?= $duration ?> min
                                <?php if ($duration >= 60): ?>
                                    <br><span style="font-size:.7rem;color:#484f58">
                                        (<?= floor($duration/60) ?>h<?= $duration%60 ? ' '.($duration%60).'m' : '' ?>)
                                    </span>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <span class="q-count <?= $exam['question_count'] > 0 ? 'has-q' : '' ?>">
                                <i class="bi bi-patch-question"></i> <?= (int)$exam['question_count'] ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-pill <?= $exam['status'] === 'published' ? 'sp-published' : 'sp-draft' ?>">
                                <?= ucfirst($exam['status']) ?>
                            </span>
                        </td>
                        <td>
                            <div class="actions-group">
                                <a href="add_question.php?exam_id=<?= $exam['id'] ?>"
                                   class="act-btn green" title="Add Questions">
                                    <i class="bi bi-card-checklist"></i>
                                </a>
                                <a href="question_bank.php?exam_id=<?= $exam['id'] ?>"
                                   class="act-btn purple" title="Question Bank">
                                    <i class="bi bi-collection"></i>
                                </a>
                                <a href="ai_question_generator.php?exam_id=<?= $exam['id'] ?>"
                                   class="act-btn ai" title="AI Question Generator">
                                    <i class="bi bi-stars"></i>
                                </a>
                                <div class="actions-divider"></div>
                                <a href="edit_exam.php?exam_id=<?= $exam['id'] ?>"
                                   class="act-btn" title="Edit Exam">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <a href="view_submission.php?exam_id=<?= $exam['id'] ?>"
                                   class="act-btn amber" title="View Submissions">
                                    <i class="bi bi-bar-chart"></i>
                                </a>
                                <a href="exam_analytics.php?exam_id=<?= $exam['id'] ?>"
                                   class="act-btn" title="Analytics" style="color:#bc8cff">
                                    <i class="bi bi-graph-up"></i>
                                </a>
                                <div class="actions-divider"></div>
                                <form method="POST" action="manage_exams.php" style="display:inline">
                                    <input type="hidden" name="exam_id"    value="<?= $exam['id'] ?>">
                                    <input type="hidden" name="new_status" value="<?= $exam['status'] === 'published' ? 'draft' : 'published' ?>">
                                    <button type="submit" name="toggle_status"
                                            class="act-btn <?= $exam['status'] === 'published' ? 'unpublish' : 'publish' ?>"
                                            title="<?= $exam['status'] === 'published' ? 'Unpublish' : 'Publish' ?>">
                                        <i class="bi bi-<?= $exam['status'] === 'published' ? 'eye-slash' : 'broadcast' ?>"></i>
                                    </button>
                                </form>
                                <form method="POST" action="manage_exams.php" style="display:inline"
                                      onsubmit="return confirm('Delete \'<?= addslashes($exam['title']) ?>\'? This cannot be undone.')">
                                    <input type="hidden" name="exam_id" value="<?= $exam['id'] ?>">
                                    <button type="submit" name="delete_exam" class="act-btn red" title="Delete">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr class="empty-row">
                        <td colspan="7">
                            <i class="bi bi-inbox"></i>
                            No exams yet — create your first exam using the form.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<script>
// ── Duration hint under the input ────────────────────────────────
function updateDurationHint(val) {
    const n   = parseInt(val, 10);
    const el  = document.getElementById('durationHint');
    if (!n || n < 1) { el.textContent = 'e.g. 60 = 1 hour, 90 = 1.5 hours'; return; }
    const h   = Math.floor(n / 60);
    const m   = n % 60;
    const lbl = h && m ? `${h}h ${m}m` : h ? `${h} hour${h>1?'s':''}` : `${m} minute${m>1?'s':''}`;
    el.textContent = `Students will have ${lbl} to complete this exam.`;
}

// ── Search ───────────────────────────────────────────────────────
document.getElementById('searchExam').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#examsTable tbody tr:not(.empty-row)').forEach(row => {
        row.style.display = (!q || row.dataset.title.includes(q)) ? '' : 'none';
    });
});
</script>

<?php include "../includes/footer.php"; ?>