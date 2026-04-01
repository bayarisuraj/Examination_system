<?php
session_start();
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

// ── Fetch lecturer courses ────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT id, course_name FROM courses
    WHERE lecturer_id = ? ORDER BY course_name ASC
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Handle exam creation ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_exam'])) {
    $course_id   = (int)($_POST['course_id']   ?? 0);
    $title       = trim($_POST['title']        ?? '');
    $exam_date   = trim($_POST['exam_date']    ?? '');
    $exam_time   = trim($_POST['exam_time']    ?? '00:00');
    $duration    = (int)($_POST['duration']    ?? 0);
    $total_marks = (int)($_POST['total_marks'] ?? 0);
    $question_ids = array_map('intval', $_POST['questions'] ?? []);

    // Validate required fields
    if (!$course_id || !$title || !$exam_date || !$duration || !$total_marks) {
        $error = "All fields except questions are required.";

    } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $exam_date)) {
        $error = "Invalid date. Please use the date picker.";

    } else {
        // Build clean DATETIME
        $exam_datetime = $exam_date . ' ' . $exam_time . ':00';
        $dt = DateTime::createFromFormat('Y-m-d H:i:s', $exam_datetime);
        if (!$dt) {
            $error = "Invalid date/time combination.";
        } else {
            $exam_datetime = $dt->format('Y-m-d H:i:s');

            // Insert exam — matches actual schema columns
            $stmt = $conn->prepare("
                INSERT INTO exams
                    (course_id, title, exam_date, duration, total_marks,
                     lecturer_id, created_by, is_published, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'draft')
            ");
            $stmt->bind_param("sisiiii",
                $course_id, $title, $exam_datetime,
                $duration, $total_marks,
                $lecturer_id, $lecturer_id
            );

            if ($stmt->execute()) {
                $exam_id = $conn->insert_id;
                $stmt->close();

                // Attach selected questions to exam
                if (!empty($question_ids)) {
                    $stmt_q = $conn->prepare("
                        INSERT IGNORE INTO exam_questions (exam_id, question_id)
                        VALUES (?, ?)
                    ");
                    foreach ($question_ids as $qid) {
                        if ($qid > 0) {
                            $stmt_q->bind_param("ii", $exam_id, $qid);
                            $stmt_q->execute();
                        }
                    }
                    $stmt_q->close();
                }

                $_SESSION['success'] = "Exam <strong>" . htmlspecialchars($title) . "</strong> created successfully!";
                header("Location: add_question.php?exam_id={$exam_id}&from=create");
                exit();

            } else {
                error_log("create_exams insert failed: " . $conn->error);
                $error = "Failed to create exam. Please try again.";
                $stmt->close();
            }
        }
    }
}

// ── Fetch lecturer's existing questions (to attach) ───────────────
// Fixed: JOIN users not a separate lecturers table; correct column names
$stmt = $conn->prepare("
    SELECT q.id, q.question_text, c.course_name
    FROM questions q
    JOIN courses c ON c.id = q.course_id
    WHERE c.lecturer_id = ?
    ORDER BY q.id DESC
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

include "../includes/header.php";
?>

<style>
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

/* Two-col layout */
.two-col {
    display: grid;
    grid-template-columns: 1fr 360px;
    gap: 1.5rem;
    align-items: start;
}
@media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }

/* Form card */
.form-card {
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 12px;
    overflow: hidden;
}
.card-head {
    padding: .85rem 1.1rem;
    border-bottom: 1px solid #30363d;
    background: #1c2330;
    font-size: .92rem; font-weight: 700;
    color: #e6edf3;
    display: flex; align-items: center; gap: .45rem;
}
.card-head i { color: #58a6ff; }
.card-body-p { padding: 1.25rem; }

/* Form fields */
.f-label {
    display: block;
    font-size: .74rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: #8b949e; margin-bottom: .35rem;
}
.f-input, .f-select {
    width: 100%;
    background: #1c2330;
    border: 1px solid #30363d;
    border-radius: 8px;
    padding: .52rem .85rem;
    color: #e6edf3;
    font-size: .86rem; font-family: inherit;
    outline: none; margin-bottom: .9rem;
    transition: border-color .2s;
}
.f-input::placeholder { color: #484f58; }
.f-input:focus, .f-select:focus { border-color: #58a6ff; }
.f-select option { background: #1c2330; }

.f-row { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }

.btn-create {
    width: 100%; padding: .65rem;
    border-radius: 9px; border: none;
    background: #3fb950; color: #0d1117;
    font-size: .9rem; font-weight: 700;
    cursor: pointer; font-family: inherit;
    display: flex; align-items: center; justify-content: center; gap: .45rem;
    transition: filter .18s;
    margin-top: .25rem;
}
.btn-create:hover { filter: brightness(1.1); }

/* Questions side panel */
.q-panel {
    background: #161b22;
    border: 1px solid #30363d;
    border-radius: 12px;
    overflow: hidden;
    position: sticky;
    top: 1.5rem;
}
.q-panel-search {
    padding: .75rem 1rem;
    border-bottom: 1px solid #30363d;
    position: relative;
}
.q-panel-search i {
    position: absolute; left: 1.65rem; top: 50%;
    transform: translateY(-50%);
    color: #8b949e; font-size: .8rem; pointer-events: none;
}
.q-panel-search input {
    width: 100%;
    background: #1c2330;
    border: 1px solid #30363d;
    border-radius: 8px;
    padding: .42rem .8rem .42rem 2rem;
    color: #e6edf3; font-size: .82rem;
    outline: none; transition: border-color .2s;
}
.q-panel-search input:focus { border-color: #58a6ff; }
.q-panel-search input::placeholder { color: #484f58; }

.q-scroll {
    max-height: 420px;
    overflow-y: auto;
    padding: .5rem 0;
}
.q-scroll::-webkit-scrollbar { width: 4px; }
.q-scroll::-webkit-scrollbar-thumb { background: #30363d; border-radius: 2px; }

.q-check-item {
    display: flex; align-items: flex-start; gap: .65rem;
    padding: .6rem 1rem;
    border-bottom: 1px solid #30363d;
    cursor: pointer;
    transition: background .15s;
}
.q-check-item:last-child { border-bottom: none; }
.q-check-item:hover { background: #1c2330; }

/* Custom checkbox */
.q-checkbox {
    appearance: none;
    width: 17px; height: 17px;
    border-radius: 4px;
    border: 2px solid #30363d;
    background: #1c2330;
    cursor: pointer; flex-shrink: 0;
    margin-top: .1rem;
    transition: all .15s;
}
.q-checkbox:checked {
    background: #58a6ff;
    border-color: #58a6ff;
}

.q-text {
    font-size: .83rem; color: #e6edf3;
    line-height: 1.4; flex: 1;
}
.q-course-badge {
    display: inline-block;
    padding: .1rem .5rem;
    border-radius: 20px;
    font-size: .68rem; font-weight: 700;
    background: #131c2b;
    border: 1px solid #1d3050;
    color: #58a6ff;
    margin-top: .25rem;
}

.q-panel-footer {
    padding: .65rem 1rem;
    border-top: 1px solid #30363d;
    background: #1c2330;
    font-size: .78rem; color: #8b949e;
    display: flex; align-items: center; justify-content: space-between;
}
.selected-count { color: #58a6ff; font-weight: 700; font-family: 'DM Mono', monospace; }

.empty-q {
    padding: 2.5rem 1rem;
    text-align: center; color: #8b949e; font-size: .85rem;
}
.empty-q i { display: block; font-size: 1.8rem; margin-bottom: .5rem; color: #30363d; }
</style>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 style="color: #0d1117;"><i class="bi bi-file-earmark-plus" style="color:#58a6ff;margin-right:.4rem"></i>Create New Exam</h1>
        <div class="sub">Fill in exam details and optionally attach existing questions</div>
    </div>
    <a href="manage_exams.php" class="btn-back">
        <i class="bi bi-arrow-left"></i> Back to Exams
    </a>
</div>

<!-- Flash -->
<?php if ($error): ?>
<div class="flash flash-error"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="two-col">

    <!-- ── Exam details form ── -->
    <div class="form-card">
        <div class="card-head"><i class="bi bi-pencil-square"></i> Exam Details</div>
        <div class="card-body-p">
            <form method="POST" action="create_exams.php">

                <label class="f-label">Course</label>
                <select name="course_id" class="f-select" required>
                    <option value="">-- Select Course --</option>
                    <?php foreach ($courses as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['course_name']) ?></option>
                    <?php endforeach; ?>
                </select>

                <label class="f-label">Exam Title</label>
                <input type="text" name="title" class="f-input"
                       placeholder="e.g. Mid-Semester Examination" required>

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

                <div class="f-row">
                    <div>
                        <label class="f-label">Duration (min)</label>
                        <input type="number" name="duration" class="f-input"
                               min="1" placeholder="e.g. 60" required>
                    </div>
                    <div>
                        <label class="f-label">Total Marks</label>
                        <input type="number" name="total_marks" class="f-input"
                               min="1" placeholder="e.g. 100" required>
                    </div>
                </div>

                <!-- Hidden checkboxes cloned from right panel -->
                <div id="hiddenQuestions"></div>

                <div style="background:#1c2330;border:1px solid #30363d;border-radius:9px;padding:.75rem 1rem;margin-bottom:.9rem;font-size:.83rem;color:#8b949e">
                    <i class="bi bi-info-circle" style="color:#58a6ff;margin-right:.4rem"></i>
                    Questions selected on the right will be attached to this exam.
                    You can also add more questions after creation.
                </div>

                <button type="submit" name="create_exam" class="btn-create">
                    <i class="bi bi-check-circle-fill"></i> Create Exam
                </button>
            </form>
        </div>
    </div>

    <!-- ── Questions side panel ── -->
    <div class="q-panel">
        <div class="card-head" style="border-bottom:1px solid #30363d">
            <i class="bi bi-patch-question" style="color:#3fb950"></i>
            Attach Questions
            <span style="margin-left:auto;font-size:.75rem;color:#8b949e;font-weight:400">optional</span>
        </div>
        <div class="q-panel-search">
            <i class="bi bi-search"></i>
            <input type="text" id="qSearch" placeholder="Search questions…">
        </div>

        <div class="q-scroll" id="questionList">
            <?php if (!empty($questions)): ?>
            <?php foreach ($questions as $q): ?>
            <label class="q-check-item" data-text="<?= strtolower(htmlspecialchars($q['question_text'])) ?>">
                <input type="checkbox" class="q-checkbox q-select"
                       value="<?= $q['id'] ?>"
                       onchange="syncQuestions()">
                <div>
                    <div class="q-text"><?= htmlspecialchars(mb_strimwidth($q['question_text'], 0, 100, '…')) ?></div>
                    <span class="q-course-badge"><?= htmlspecialchars($q['course_name']) ?></span>
                </div>
            </label>
            <?php endforeach; ?>
            <?php else: ?>
            <div class="empty-q">
                <i class="bi bi-patch-question"></i>
                No questions yet.<br>
                <a href="add_question.php" style="color:#58a6ff;font-size:.8rem">Create questions first</a>
            </div>
            <?php endif; ?>
        </div>

        <div class="q-panel-footer">
            <span><span class="selected-count" id="selCount">0</span> selected</span>
            <span><?= count($questions) ?> total</span>
        </div>
    </div>

</div><!-- /two-col -->

<script>
// ── Sync selected questions into hidden inputs inside the form ────
function syncQuestions() {
    const container = document.getElementById('hiddenQuestions');
    container.innerHTML = '';
    let count = 0;
    document.querySelectorAll('.q-select:checked').forEach(cb => {
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = 'questions[]';
        inp.value = cb.value;
        container.appendChild(inp);
        count++;
    });
    document.getElementById('selCount').textContent = count;
}

// ── Search filter ─────────────────────────────────────────────────
document.getElementById('qSearch').addEventListener('input', function () {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.q-check-item').forEach(item => {
        item.style.display = (!q || item.dataset.text.includes(q)) ? '' : 'none';
    });
});
</script>

<?php include "../includes/footer.php"; ?>