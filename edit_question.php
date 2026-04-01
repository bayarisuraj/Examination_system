<?php
session_start();
require_once "../config/db.php";

// ── Auth guard ────────────────────────────────────────────────────
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

// ── Safe lecturer_id resolution ───────────────────────────────────
$session_email = $_SESSION['user_email'] ?? $_SESSION['email'] ?? '';
$lecturer_id   = (int)($_SESSION['user_id'] ?? 0);

if ($session_email) {
    $stmt = $conn->prepare("SELECT id FROM lecturers WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $session_email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $lecturer_id = (int)$row['id'];
        $_SESSION['user_id'] = $lecturer_id;
    }
}

if (!$lecturer_id) {
    header("Location: ../auth/login.php?error=session");
    exit();
}

// ── Validate params ───────────────────────────────────────────────
$question_id = (int)($_GET['question_id'] ?? 0);
$exam_id     = (int)($_GET['exam_id']     ?? 0);

if (!$question_id || !$exam_id) {
    header("Location: manage_exams.php");
    exit();
}

// ── Verify exam belongs to this lecturer ─────────────────────────
$stmt = $conn->prepare("
    SELECT e.id, e.title, c.course_name, c.id AS course_id
    FROM exams e
    JOIN courses c ON c.id = e.course_id
    WHERE e.id = ? AND e.lecturer_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $exam_id, $lecturer_id);
$stmt->execute();
$exam = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$exam) {
    header("Location: manage_exams.php?error=not_found");
    exit();
}

// ── Fetch the question (must belong to this course) ───────────────
$stmt = $conn->prepare("
    SELECT q.id, q.question_text, q.option_a, q.option_b,
           q.option_c, q.option_d, q.correct_option, q.marks
    FROM questions q
    JOIN exam_questions eq ON eq.question_id = q.id
    WHERE q.id = ? AND eq.exam_id = ? AND q.course_id = ?
    LIMIT 1
");
$stmt->bind_param("iii", $question_id, $exam_id, $exam['course_id']);
$stmt->execute();
$question = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$question) {
    header("Location: add_question.php?exam_id={$exam_id}&error=not_found");
    exit();
}

$success = $error = '';

// ── Handle Update ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_question'])) {
    $question_text  = trim($_POST['question_text'] ?? '');
    $option_a       = trim($_POST['option_a']      ?? '');
    $option_b       = trim($_POST['option_b']      ?? '');
    $option_c       = trim($_POST['option_c']      ?? '');
    $option_d       = trim($_POST['option_d']      ?? '');
    $correct_option = strtolower(trim($_POST['correct_option'] ?? ''));
    $marks          = max(1, (int)($_POST['marks'] ?? 1));

    if ($question_text === '') {
        $error = "Question text is required.";
    } elseif ($option_a === '' || $option_b === '') {
        $error = "Option A and Option B are required.";
    } elseif (!in_array($correct_option, ['a', 'b', 'c', 'd'], true)) {
        $error = "Please select a valid correct answer (A–D).";
    } elseif ($correct_option === 'c' && $option_c === '') {
        $error = "You marked C as correct but Option C is empty.";
    } elseif ($correct_option === 'd' && $option_d === '') {
        $error = "You marked D as correct but Option D is empty.";
    } else {
        $stmt = $conn->prepare("
            UPDATE questions
            SET question_text  = ?,
                option_a       = ?,
                option_b       = ?,
                option_c       = ?,
                option_d       = ?,
                correct_option = ?,
                marks          = ?
            WHERE id = ? AND course_id = ?
        ");
        $stmt->bind_param(
            "ssssssiii",
            $question_text,
            $option_a, $option_b, $option_c, $option_d,
            $correct_option, $marks,
            $question_id, $exam['course_id']
        );

        if ($stmt->execute()) {
            $stmt->close();
            header("Location: add_question.php?exam_id={$exam_id}&updated=1");
            exit();
        } else {
            error_log("Update question failed: " . $stmt->error);
            $error = "Failed to update question. Please try again.";
            $stmt->close();
        }
    }

    // Re-populate form with submitted values on error
    $question['question_text']  = $question_text;
    $question['option_a']       = $option_a;
    $question['option_b']       = $option_b;
    $question['option_c']       = $option_c;
    $question['option_d']       = $option_d;
    $question['correct_option'] = $correct_option;
    $question['marks']          = $marks;
}

include "../includes/header.php";
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap');

:root {
    --bg:      #0d1117;
    --surface: #161b22;
    --surface2:#1c2330;
    --border:  #30363d;
    --text:    #e6edf3;
    --muted:   #8b949e;
    --accent:  #58a6ff;
    --green:   #3fb950;
    --red:     #f85149;
    --radius:  12px;
    --sans:    'DM Sans', sans-serif;
    --serif:   'Sora', sans-serif;
    --mono:    'DM Mono', monospace;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: var(--sans); min-height: 100vh; }

.eq-shell { max-width: 680px; margin: 0 auto; padding: 2.5rem 1.25rem 5rem; }

/* ── Back link ── */
.back-link {
    display: inline-flex; align-items: center; gap: .35rem;
    font-size: .82rem; color: var(--muted); text-decoration: none;
    margin-bottom: 1.5rem; transition: color .18s;
}
.back-link:hover { color: var(--accent); }

/* ── Page header ── */
.eq-header { margin-bottom: 1.75rem; }
.eq-header h1 { font-family: var(--serif); font-size: 1.4rem; font-weight: 700; margin-bottom: .3rem; }
.eq-header .meta { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.exam-pill {
    display: inline-flex; align-items: center; gap: .4rem;
    background: #131c2b; border: 1px solid #1d3050;
    color: var(--accent); padding: .25rem .75rem;
    border-radius: 20px; font-size: .78rem; font-weight: 600;
}

/* ── Flash ── */
.flash {
    display: flex; align-items: center; gap: .6rem;
    padding: .75rem 1rem; border-radius: 9px;
    font-size: .85rem; margin-bottom: 1.25rem;
    border: 1px solid transparent;
}
.flash-success { background: #0d2818; border-color: #238636; color: var(--green); }
.flash-error   { background: #1e1212; border-color: #6e2020; color: var(--red); }

/* ── Card ── */
.eq-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); overflow: hidden;
}
.eq-card-head {
    padding: .9rem 1.25rem; border-bottom: 1px solid var(--border);
    background: var(--surface2);
    display: flex; align-items: center; gap: .5rem;
}
.eq-card-head h2 { font-family: var(--serif); font-size: .97rem; font-weight: 700; margin: 0; }
.q-id-badge {
    margin-left: auto; font-family: var(--mono); font-size: .72rem;
    color: var(--muted); background: var(--bg); border: 1px solid var(--border);
    padding: .15rem .55rem; border-radius: 6px;
}

.eq-body { padding: 1.25rem; }

/* ── Form elements ── */
.f-label {
    display: block; font-size: .74rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--muted); margin-bottom: .35rem;
}
.f-textarea {
    width: 100%; background: var(--surface2); border: 1px solid var(--border);
    border-radius: 8px; padding: .6rem .9rem;
    color: var(--text); font-size: .9rem; font-family: var(--sans);
    outline: none; transition: border-color .2s;
    resize: vertical; min-height: 100px; margin-bottom: 1.1rem;
}
.f-textarea:focus { border-color: var(--accent); }
.f-textarea::placeholder { color: #484f58; }

.section-label {
    font-size: .74rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: var(--muted); margin-bottom: .6rem; display: block;
}
.section-hint {
    font-size: .72rem; color: #484f58; font-weight: 400;
    text-transform: none; letter-spacing: 0;
}

.options-wrap { margin-bottom: 1.1rem; display: flex; flex-direction: column; gap: .55rem; }
.option-row {
    display: flex; align-items: center; gap: .55rem;
    padding: .55rem .75rem; border-radius: 9px;
    border: 1px solid var(--border); background: var(--surface2);
    transition: border-color .18s;
}
.option-row:focus-within { border-color: var(--accent); }
.option-row.is-correct   { border-color: #238636; background: #0d1e12; }

.opt-letter {
    width: 28px; height: 28px; border-radius: 50%;
    background: var(--bg); border: 1.5px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: .73rem; font-weight: 700; color: var(--muted); flex-shrink: 0;
    transition: all .15s;
}
.option-row.is-correct .opt-letter { background: var(--green); border-color: var(--green); color: #fff; }

.f-opt-input {
    flex: 1; background: transparent; border: none;
    color: var(--text); font-size: .87rem; font-family: var(--sans);
    outline: none;
}
.f-opt-input::placeholder { color: #484f58; }

.correct-radio {
    appearance: none; width: 18px; height: 18px;
    border-radius: 50%; border: 2px solid var(--border);
    background: var(--bg); cursor: pointer; flex-shrink: 0;
    transition: all .15s;
}
.correct-radio:checked {
    background: var(--green); border-color: var(--green);
    box-shadow: 0 0 0 3px rgba(63,185,80,.2);
}

.correct-label {
    font-size: .72rem; color: var(--muted); white-space: nowrap;
    display: flex; align-items: center; gap: .3rem; cursor: pointer;
    transition: color .15s;
}
.option-row.is-correct .correct-label { color: var(--green); }

/* ── Marks ── */
.marks-row {
    display: flex; align-items: center; gap: .85rem;
    margin-bottom: 1.25rem;
}
.marks-row .f-label { margin-bottom: 0; white-space: nowrap; }
.marks-input {
    width: 90px; background: var(--surface2); border: 1px solid var(--border);
    border-radius: 8px; padding: .5rem .75rem;
    color: var(--text); font-size: .9rem; font-family: var(--mono);
    outline: none; transition: border-color .2s; text-align: center;
}
.marks-input:focus { border-color: var(--accent); }
.marks-hint { font-size: .78rem; color: var(--muted); }

/* ── Actions ── */
.form-actions {
    display: flex; gap: .75rem; flex-wrap: wrap;
    padding-top: 1rem; border-top: 1px solid var(--border);
    margin-top: 1rem;
}
.btn-update {
    flex: 1; min-width: 160px; padding: .65rem 1rem;
    border-radius: 9px; border: none;
    background: var(--green); color: #0d1117;
    font-size: .9rem; font-weight: 700;
    cursor: pointer; display: flex; align-items: center;
    justify-content: center; gap: .4rem;
    transition: filter .18s;
}
.btn-update:hover { filter: brightness(1.1); }
.btn-cancel {
    padding: .65rem 1.2rem; border-radius: 9px;
    border: 1px solid var(--border); background: transparent;
    color: var(--muted); font-size: .88rem; font-weight: 600;
    text-decoration: none; display: inline-flex; align-items: center; gap: .35rem;
    transition: all .18s;
}
.btn-cancel:hover { border-color: var(--accent); color: var(--accent); }

/* ── Preview panel ── */
.preview-panel {
    background: var(--surface2); border: 1px solid var(--border);
    border-radius: 10px; padding: 1rem 1.1rem;
    margin-top: 1.5rem; font-size: .83rem; color: var(--muted);
}
.preview-panel h4 {
    font-family: var(--serif); font-size: .85rem; font-weight: 700;
    color: var(--text); margin-bottom: .6rem;
    display: flex; align-items: center; gap: .4rem;
}
#preview-text { font-size: .9rem; color: var(--text); line-height: 1.55; margin-bottom: .75rem; min-height: 1.2em; }
.preview-opts { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem; }
.preview-opt {
    display: flex; align-items: center; gap: .45rem;
    padding: .35rem .6rem; border-radius: 7px;
    border: 1px solid var(--border); font-size: .82rem; color: var(--muted);
}
.preview-opt.correct { border-color: #238636; background: #0d1e12; color: var(--green); }
.preview-opt-key {
    width: 20px; height: 20px; border-radius: 50%;
    background: var(--bg); border: 1px solid var(--border);
    display: flex; align-items: center; justify-content: center;
    font-size: .68rem; font-weight: 700; flex-shrink: 0;
}
.preview-opt.correct .preview-opt-key { background: var(--green); border-color: var(--green); color: #fff; }

@media (max-width: 600px) {
    .eq-shell { padding: 1.25rem .75rem 4rem; }
    .preview-opts { grid-template-columns: 1fr; }
    .options-wrap .correct-label span { display: none; }
}
</style>

<div class="eq-shell">

    <a href="add_question.php?exam_id=<?= $exam_id ?>" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Questions
    </a>

    <!-- Header -->
    <div class="eq-header">
        <h1><i class="bi bi-pencil-square" style="color:var(--accent);margin-right:.4rem"></i>Edit Question</h1>
        <div class="meta">
            <span class="exam-pill"><i class="bi bi-journal-check"></i><?= htmlspecialchars($exam['title']) ?></span>
            <span style="color:var(--muted);font-size:.82rem"><?= htmlspecialchars($exam['course_name']) ?></span>
        </div>
    </div>

    <!-- Flash -->
    <?php if ($success): ?>
    <div class="flash flash-success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="flash flash-error"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- Edit card -->
    <div class="eq-card">
        <div class="eq-card-head">
            <i class="bi bi-card-text" style="color:var(--accent)"></i>
            <h2>Question Details</h2>
            <span class="q-id-badge">#<?= $question_id ?></span>
        </div>

        <div class="eq-body">
            <form method="POST" action="edit_question.php?question_id=<?= $question_id ?>&exam_id=<?= $exam_id ?>" id="editForm">

                <!-- Question text -->
                <label class="f-label" for="qtext">Question Text</label>
                <textarea
                    id="qtext"
                    name="question_text"
                    class="f-textarea"
                    placeholder="Type your question here…"
                    oninput="updatePreview()"
                    required><?= htmlspecialchars($question['question_text']) ?></textarea>

                <!-- Options -->
                <span class="section-label">
                    Options
                    <span class="section-hint">— click the circle to mark the correct answer</span>
                </span>

                <div class="options-wrap" id="optionsWrap">
                    <?php
                    $optDefs = [
                        ['key' => 'a', 'name' => 'option_a', 'ph' => 'Option A', 'req' => true],
                        ['key' => 'b', 'name' => 'option_b', 'ph' => 'Option B', 'req' => true],
                        ['key' => 'c', 'name' => 'option_c', 'ph' => 'Option C (optional)', 'req' => false],
                        ['key' => 'd', 'name' => 'option_d', 'ph' => 'Option D (optional)', 'req' => false],
                    ];
                    foreach ($optDefs as $opt):
                        $ltr       = strtoupper($opt['key']);
                        $val       = htmlspecialchars($question[$opt['name']] ?? '');
                        $isCorrect = ($question['correct_option'] === $opt['key']);
                    ?>
                    <div class="option-row <?= $isCorrect ? 'is-correct' : '' ?>" id="row-<?= $opt['key'] ?>">
                        <span class="opt-letter"><?= $ltr ?></span>
                        <input
                            type="text"
                            name="<?= $opt['name'] ?>"
                            class="f-opt-input"
                            placeholder="<?= $opt['ph'] ?>"
                            value="<?= $val ?>"
                            <?= $opt['req'] ? 'required' : '' ?>
                            oninput="updatePreview()">
                        <label class="correct-label" for="radio-<?= $opt['key'] ?>">
                            <input
                                type="radio"
                                id="radio-<?= $opt['key'] ?>"
                                name="correct_option"
                                value="<?= $opt['key'] ?>"
                                class="correct-radio"
                                <?= $isCorrect ? 'checked' : '' ?>
                                onchange="highlightCorrect('<?= $opt['key'] ?>')"
                                required>
                            <span>Correct</span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Marks -->
                <div class="marks-row">
                    <label class="f-label" for="marksInput">Marks</label>
                    <input
                        type="number"
                        id="marksInput"
                        name="marks"
                        class="marks-input"
                        min="1" max="100"
                        value="<?= (int)$question['marks'] ?>">
                    <span class="marks-hint">Points awarded for a correct answer</span>
                </div>

                <!-- Actions -->
                <div class="form-actions">
                    <button type="submit" name="update_question" class="btn-update">
                        <i class="bi bi-check-lg"></i> Save Changes
                    </button>
                    <a href="add_question.php?exam_id=<?= $exam_id ?>" class="btn-cancel">
                        <i class="bi bi-x-lg"></i> Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- Live preview -->
    <div class="preview-panel">
        <h4><i class="bi bi-eye" style="color:var(--accent)"></i> Live Preview</h4>
        <div id="preview-text"><?= htmlspecialchars($question['question_text']) ?></div>
        <div class="preview-opts" id="preview-opts"></div>
    </div>

</div>

<script>
const optKeys  = ['a', 'b', 'c', 'd'];
const optNames = ['option_a', 'option_b', 'option_c', 'option_d'];

function highlightCorrect(key) {
    optKeys.forEach(k => {
        document.getElementById('row-' + k).classList.toggle('is-correct', k === key);
    });
    updatePreview();
}

function updatePreview() {
    // Question text
    const qtxt = document.getElementById('qtext').value.trim();
    document.getElementById('preview-text').textContent = qtxt || '(Question text will appear here)';

    // Options
    const checkedRadio = document.querySelector('.correct-radio:checked');
    const correctKey   = checkedRadio ? checkedRadio.value : null;

    const container = document.getElementById('preview-opts');
    container.innerHTML = '';

    optKeys.forEach((k, i) => {
        const input = document.querySelector(`input[name="${optNames[i]}"]`);
        const val   = input ? input.value.trim() : '';
        if (!val) return;

        const isC = (k === correctKey);
        const div = document.createElement('div');
        div.className = 'preview-opt' + (isC ? ' correct' : '');
        div.innerHTML = `<span class="preview-opt-key">${k.toUpperCase()}</span>
                         <span>${escHtml(val)}</span>
                         ${isC ? '<i class="bi bi-check-lg" style="margin-left:auto;font-size:.75rem"></i>' : ''}`;
        container.appendChild(div);
    });
}

function escHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

// Initialize preview on load
document.addEventListener('DOMContentLoaded', () => {
    updatePreview();
    // Highlight initially checked radio
    const checked = document.querySelector('.correct-radio:checked');
    if (checked) highlightCorrect(checked.value);
});
</script>

<?php include "../includes/footer.php"; ?>