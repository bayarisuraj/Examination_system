<?php
session_start();
require_once "../config/db.php";

// ── Auth guard ────────────────────────────────────────────────────────────────
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

// ── Safe lecturer_id resolution ───────────────────────────────────────────────
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

// ── Validate exam_id ──────────────────────────────────────────────────────────
$exam_id = (int)($_GET['exam_id'] ?? 0);
if (!$exam_id) {
    header("Location: manage_exams.php");
    exit();
}

// ── Verify exam belongs to this lecturer ─────────────────────────────────────
$stmt = $conn->prepare("
    SELECT e.id, e.title, e.status, e.total_marks,
           c.course_name, c.id AS course_id
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

$success = $error = '';

// ── Upload directory ──────────────────────────────────────────────────────────
define('Q_IMG_DIR', __DIR__ . '/../uploads/question_images/');
define('Q_IMG_URL', '../uploads/question_images/');
if (!is_dir(Q_IMG_DIR)) mkdir(Q_IMG_DIR, 0755, true);

// ── Helper: handle image upload, returns filename or '' ───────────────────────
function handle_image_upload(string $field_name): string {
    if (!isset($_FILES[$field_name]) || $_FILES[$field_name]['error'] === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $file = $_FILES[$field_name];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return '';
    }
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $finfo   = finfo_open(FILEINFO_MIME_TYPE);
    $mime    = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed, true)) return '';
    if ($file['size'] > 5 * 1024 * 1024) return ''; // 5 MB max

    $ext      = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        default      => 'jpg'
    };
    $filename = 'q_' . uniqid('', true) . '.' . $ext;
    move_uploaded_file($file['tmp_name'], Q_IMG_DIR . $filename);
    return $filename;
}

// ── Helper: check if an identical question already exists for this exam ───────
function question_is_duplicate($conn, $exam_id, $question_text) {
    $stmt = $conn->prepare("
        SELECT q.id
        FROM questions q
        JOIN exam_questions eq ON eq.question_id = q.id
        WHERE eq.exam_id = ?
          AND LOWER(TRIM(q.question_text)) = LOWER(TRIM(?))
        LIMIT 1
    ");
    $stmt->bind_param("is", $exam_id, $question_text);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row !== null;
}

// ── Helper: insert a question row ─────────────────────────────────────────────
function insert_question($conn, $course_id, $question_text, $option_a, $option_b,
                         $option_c, $option_d, $correct_option, $marks,
                         string $image_path = '') {
    $stmt = $conn->prepare("
        INSERT INTO questions
            (course_id, question_text, option_a, option_b, option_c, option_d,
             correct_option, marks, image_path)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    if (!$stmt) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }
    $stmt->bind_param("issssssis",
        $course_id, $question_text,
        $option_a, $option_b, $option_c, $option_d,
        $correct_option, $marks, $image_path
    );
    $ok     = $stmt->execute();
    $new_id = $ok ? $conn->insert_id : false;
    if (!$ok) error_log("Insert question failed: " . $stmt->error);
    $stmt->close();
    return $new_id;
}

// ── Helper: link question → exam ──────────────────────────────────────────────
function link_question_to_exam($conn, $question_id, $exam_id) {
    $stmt = $conn->prepare("
        INSERT IGNORE INTO exam_questions (exam_id, question_id)
        VALUES (?, ?)
    ");
    if (!$stmt) { error_log("Prepare link failed: " . $conn->error); return false; }
    $stmt->bind_param("ii", $exam_id, $question_id);
    $ok = $stmt->execute();
    if (!$ok) error_log("Link question failed: " . $stmt->error);
    $stmt->close();
    return $ok;
}

// ── Handle Add Question (manual) ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_question'])) {
    $question_text  = trim($_POST['question_text'] ?? '');
    $option_a       = trim($_POST['option_a'] ?? '');
    $option_b       = trim($_POST['option_b'] ?? '');
    $option_c       = trim($_POST['option_c'] ?? '');
    $option_d       = trim($_POST['option_d'] ?? '');
    $correct_option = strtolower(trim($_POST['correct_option'] ?? ''));
    $marks          = max(1, (int)($_POST['marks'] ?? 1));

    if ($question_text === '') {
        $error = "Question text is required.";
    } elseif ($option_a === '' || $option_b === '') {
        $error = "Option A and Option B are required.";
    } elseif (!in_array($correct_option, ['a','b','c','d'], true)) {
        $error = "Please select a valid correct answer (A–D).";
    } elseif ($correct_option === 'c' && $option_c === '') {
        $error = "You marked C as correct but Option C is empty.";
    } elseif ($correct_option === 'd' && $option_d === '') {
        $error = "You marked D as correct but Option D is empty.";
    } elseif (question_is_duplicate($conn, $exam_id, $question_text)) {
        $error = "This question already exists in the exam.";
    } else {
        // Handle image upload
        $image_path = handle_image_upload('question_image');

        $new_id = insert_question(
            $conn, $exam['course_id'], $question_text,
            $option_a, $option_b, $option_c, $option_d,
            $correct_option, $marks, $image_path
        );
        if ($new_id) {
            link_question_to_exam($conn, $new_id, $exam_id);
            $success = "Question added and linked to this exam!";
        } else {
            // Clean up uploaded image if insert failed
            if ($image_path && file_exists(Q_IMG_DIR . $image_path)) {
                unlink(Q_IMG_DIR . $image_path);
            }
            $error = "Failed to save question. Please try again.";
        }
    }
}

// ── Handle Delete Question ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_question'])) {
    $qid = (int)($_POST['question_id'] ?? 0);
    if ($qid) {
        // Fetch image_path before delete so we can unlink it
        $r = $conn->query("SELECT image_path FROM questions WHERE id = $qid");
        $img_row = $r ? $r->fetch_assoc() : null;

        // Remove from junction first
        $stmt = $conn->prepare("DELETE FROM exam_questions WHERE exam_id = ? AND question_id = ?");
        $stmt->bind_param("ii", $exam_id, $qid);
        $stmt->execute();
        $stmt->close();

        // Remove question row
        $stmt = $conn->prepare("DELETE FROM questions WHERE id = ? AND course_id = ?");
        $stmt->bind_param("ii", $qid, $exam['course_id']);
        $stmt->execute();
        $stmt->close();

        // Delete image file if it existed
        if (!empty($img_row['image_path'])) {
            $img_file = Q_IMG_DIR . $img_row['image_path'];
            if (file_exists($img_file)) unlink($img_file);
        }

        header("Location: add_question.php?exam_id={$exam_id}&deleted=1");
        exit();
    }
}

// ── Handle Remove Question Image ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_image'])) {
    $qid = (int)($_POST['question_id'] ?? 0);
    if ($qid) {
        $r   = $conn->query("SELECT image_path FROM questions WHERE id = $qid");
        $row = $r ? $r->fetch_assoc() : null;
        if (!empty($row['image_path'])) {
            $f = Q_IMG_DIR . $row['image_path'];
            if (file_exists($f)) unlink($f);
        }
        $conn->query("UPDATE questions SET image_path = NULL WHERE id = $qid");
        header("Location: add_question.php?exam_id={$exam_id}&img_removed=1");
        exit();
    }
}

// ── Handle Save AI Questions (AJAX) ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_ai_questions'])) {
    header('Content-Type: application/json');
    $raw     = json_decode($_POST['questions_json'] ?? '[]', true);
    $saved   = 0;
    $skipped = 0;
    $errors  = [];

    if (is_array($raw)) {
        foreach ($raw as $q) {
            $qtext = trim($q['question'] ?? '');
            $opts  = $q['options'] ?? [];
            $marks = 1;
            if (!$qtext) continue;
            if (question_is_duplicate($conn, $exam_id, $qtext)) { $skipped++; continue; }

            $correct_letter = 'a';
            foreach ($opts as $idx => $opt_text) {
                if (strtolower(trim($opt_text)) === strtolower(trim($q['answer'] ?? ''))) {
                    $correct_letter = chr(97 + $idx);
                    break;
                }
            }

            $strip = fn($s) => preg_replace('/^[A-Da-d][.)]\s*/', '', trim($s));
            $oa = $strip($opts[0] ?? '');
            $ob = $strip($opts[1] ?? '');
            $oc = $strip($opts[2] ?? '');
            $od = $strip($opts[3] ?? '');

            if (($q['type'] ?? '') === 'truefalse') {
                $oa = 'True'; $ob = 'False'; $oc = ''; $od = '';
                $correct_letter = (strtolower($q['answer'] ?? '') === 'true') ? 'a' : 'b';
            }

            if ($oa === '') $oa = 'Option A';
            if ($ob === '') $ob = 'Option B';

            $new_id = insert_question(
                $conn, $exam['course_id'], $qtext,
                $oa, $ob, $oc, $od,
                $correct_letter, $marks
            );

            if ($new_id) {
                link_question_to_exam($conn, $new_id, $exam_id);
                $saved++;
            } else {
                $errors[] = "Failed to save: " . substr($qtext, 0, 40);
            }
        }
    }

    echo json_encode(['success' => true, 'saved' => $saved, 'skipped' => $skipped, 'errors' => $errors]);
    exit();
}

// ── Fetch existing questions for THIS exam ────────────────────────────────────
$stmt = $conn->prepare("
    SELECT q.id, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d,
           q.correct_option, q.marks, q.image_path
    FROM questions q
    JOIN exam_questions eq ON eq.question_id = q.id
    WHERE eq.exam_id = ?
    ORDER BY q.id ASC
");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$questions       = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$total_questions = count($questions);
$stmt->close();

$letters = ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D'];

include "../includes/header.php";
?>

<style>
.page-header {
    display: flex; align-items: flex-start; justify-content: space-between;
    flex-wrap: wrap; gap: 1rem; margin-bottom: 1.75rem;
}
.page-header h1 { font-size: 1.4rem; font-weight: 700; color: #e6edf3; margin-bottom: .2rem; }
.page-header .sub { font-size: .82rem; color: #8b949e; }

.exam-pill {
    display: inline-flex; align-items: center; gap: .4rem;
    background: #131c2b; border: 1px solid #1d3050;
    color: #58a6ff; padding: .3rem .85rem;
    border-radius: 20px; font-size: .8rem; font-weight: 600;
}

.flash {
    display: flex; align-items: center; gap: .6rem;
    padding: .75rem 1rem; border-radius: 9px;
    font-size: .85rem; margin-bottom: 1.25rem;
    border: 1px solid transparent;
}
.flash-success { background: #0d2818; border-color: #238636; color: #3fb950; }
.flash-error   { background: #1e1212; border-color: #6e2020; color: #f85149; }

.two-col {
    display: grid;
    grid-template-columns: 400px 1fr;
    gap: 1.5rem; align-items: start;
}
@media (max-width: 900px) { .two-col { grid-template-columns: 1fr; } }

.form-panel {
    background: #161b22; border: 1px solid #30363d;
    border-radius: 12px; overflow: hidden;
    position: sticky; top: 1.5rem;
}

.tab-bar {
    display: flex; border-bottom: 1px solid #30363d;
    background: #1c2330;
}
.tab-btn {
    flex: 1; padding: .7rem .5rem;
    background: none; border: none;
    font-size: .82rem; font-weight: 700;
    color: #8b949e; cursor: pointer;
    display: flex; align-items: center; justify-content: center; gap: .4rem;
    border-bottom: 2px solid transparent;
    transition: all .18s;
}
.tab-btn:hover { color: #e6edf3; }
.tab-btn.active { color: #e6edf3; border-bottom-color: #58a6ff; }
.tab-btn.active.ai-tab { border-bottom-color: #f78166; color: #f78166; }

.tab-pane { display: none; }
.tab-pane.active { display: block; }

.panel-body { padding: 1.1rem; }

.f-label {
    display: block; font-size: .74rem; font-weight: 700;
    text-transform: uppercase; letter-spacing: .06em;
    color: #8b949e; margin-bottom: .35rem;
}
.f-textarea, .f-input, .f-select-sm {
    width: 100%; background: #1c2330; border: 1px solid #30363d;
    border-radius: 8px; padding: .52rem .85rem;
    color: #e6edf3; font-size: .87rem; font-family: inherit;
    outline: none; transition: border-color .2s; box-sizing: border-box;
}
.f-textarea { resize: vertical; min-height: 90px; margin-bottom: .85rem; }
.f-input    { margin-bottom: .85rem; }
.f-select-sm { margin-bottom: .85rem; }
.f-textarea:focus, .f-input:focus, .f-select-sm:focus { border-color: #58a6ff; }
.f-textarea::placeholder, .f-input::placeholder { color: #484f58; }

/* ── Image upload zone ── */
.img-upload-zone {
    border: 2px dashed #30363d; border-radius: 10px;
    padding: 1rem; text-align: center;
    cursor: pointer; transition: border-color .2s, background .2s;
    margin-bottom: .85rem; position: relative;
}
.img-upload-zone:hover,
.img-upload-zone.dragover { border-color: #58a6ff; background: #131c2b; }
.img-upload-zone input[type=file] {
    position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%;
}
.img-upload-zone .upload-icon { font-size: 1.4rem; color: #30363d; margin-bottom: .3rem; }
.img-upload-zone .upload-text { font-size: .78rem; color: #8b949e; }
.img-upload-zone .upload-hint { font-size: .7rem; color: #484f58; margin-top: .2rem; }
.img-preview-wrap {
    display: none; position: relative; margin-bottom: .85rem;
    border: 1px solid #30363d; border-radius: 8px; overflow: hidden;
    background: #0d1117;
}
.img-preview-wrap img {
    width: 100%; max-height: 180px; object-fit: contain;
    display: block; padding: .5rem;
}
.img-preview-clear {
    position: absolute; top: .4rem; right: .4rem;
    background: rgba(13,17,23,.85); border: 1px solid #30363d;
    color: #f85149; border-radius: 6px; padding: .2rem .5rem;
    font-size: .75rem; cursor: pointer; transition: all .15s;
    display: flex; align-items: center; gap: .25rem;
}
.img-preview-clear:hover { background: #f85149; color: #fff; border-color: #f85149; }

.options-wrap { margin-bottom: .85rem; }
.option-row { display: flex; align-items: center; gap: .5rem; margin-bottom: .5rem; }
.opt-letter {
    width: 26px; height: 26px; border-radius: 50%;
    background: #1c2330; border: 1.5px solid #30363d;
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem; font-weight: 700; color: #8b949e; flex-shrink: 0;
}
.f-opt-input {
    flex: 1; background: #1c2330; border: 1px solid #30363d;
    border-radius: 8px; padding: .45rem .75rem;
    color: #e6edf3; font-size: .85rem; font-family: inherit;
    outline: none; transition: border-color .2s;
}
.f-opt-input:focus { border-color: #58a6ff; }
.f-opt-input::placeholder { color: #484f58; }
.correct-radio {
    appearance: none; width: 18px; height: 18px;
    border-radius: 50%; border: 2px solid #30363d;
    background: #1c2330; cursor: pointer; flex-shrink: 0;
    transition: all .15s;
}
.correct-radio:checked {
    background: #3fb950; border-color: #3fb950;
    box-shadow: 0 0 0 3px rgba(63,185,80,.2);
}
.marks-row {
    display: flex; align-items: center; gap: .75rem; margin-bottom: .85rem;
}
.marks-row .f-label { margin-bottom: 0; white-space: nowrap; }
.marks-row input {
    width: 80px; background: #1c2330; border: 1px solid #30363d;
    border-radius: 8px; padding: .45rem .75rem;
    color: #e6edf3; font-size: .87rem; outline: none;
    transition: border-color .2s;
}
.marks-row input:focus { border-color: #58a6ff; }

.btn-submit-q {
    width: 100%; padding: .6rem; border-radius: 9px; border: none;
    background: #3fb950; color: #0d1117; font-size: .88rem; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    gap: .4rem; transition: filter .18s;
}
.btn-submit-q:hover { filter: brightness(1.1); }

.f-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; margin-bottom: .85rem; }
.f-grid-2 .f-label { margin-bottom: .35rem; }
.f-grid-2 > div { display: flex; flex-direction: column; }
.f-grid-2 .f-select-sm { margin-bottom: 0; }

.btn-generate {
    width: 100%; padding: .6rem; border-radius: 9px; border: none;
    background: linear-gradient(135deg, #f78166, #da3633);
    color: #fff; font-size: .88rem; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    gap: .4rem; transition: filter .18s; margin-bottom: .6rem;
}
.btn-generate:hover { filter: brightness(1.1); }
.btn-generate:disabled { filter: grayscale(1) opacity(.6); cursor: not-allowed; }

.ai-status { font-size: .78rem; color: #8b949e; text-align: center; min-height: 18px; margin-bottom: .5rem; }
.ai-status.ok  { color: #3fb950; }
.ai-status.err { color: #f85149; }
.ai-status.warn { color: #d29922; }

.ai-results { margin-top: .5rem; display: flex; flex-direction: column; gap: .6rem; max-height: 420px; overflow-y: auto; }
.ai-q-card { background: #1c2330; border: 1px solid #30363d; border-radius: 8px; padding: .75rem; }
.ai-q-card.selected { border-color: #3fb950; background: #0d1e12; }
.ai-q-card.duplicate { border-color: #d29922; background: #1f1a0e; opacity: .7; }
.ai-q-meta { display: flex; justify-content: space-between; align-items: center; margin-bottom: .4rem; }
.ai-q-num { font-size: .7rem; color: #8b949e; font-weight: 700; }
.ai-q-diff { font-size: .68rem; font-weight: 700; padding: .15rem .45rem; border-radius: 10px; }
.diff-easy   { background: #0d2818; color: #3fb950; }
.diff-medium { background: #1f1a0e; color: #d29922; }
.diff-hard   { background: #1e1212; color: #f85149; }
.ai-q-text { font-size: .84rem; color: #e6edf3; line-height: 1.45; margin-bottom: .5rem; }
.ai-opts { display: grid; grid-template-columns: 1fr 1fr; gap: .3rem; margin-bottom: .5rem; }
.ai-opt { font-size: .76rem; padding: .3rem .5rem; border-radius: 6px; border: 1px solid #30363d; color: #8b949e; line-height: 1.3; }
.ai-opt.correct { border-color: #238636; background: #0d2818; color: #3fb950; }
.ai-q-footer { display: flex; justify-content: space-between; align-items: center; }
.ai-ans { font-size: .72rem; color: #3fb950; }
.dup-badge { font-size: .68rem; color: #d29922; font-weight: 700; }
.ai-check { display: flex; align-items: center; gap: .3rem; font-size: .75rem; color: #8b949e; cursor: pointer; user-select: none; }
.ai-check input[type=checkbox] { accent-color: #3fb950; cursor: pointer; }

.btn-save-selected {
    width: 100%; padding: .55rem; border-radius: 9px; border: none;
    background: #238636; color: #fff; font-size: .85rem; font-weight: 700;
    cursor: pointer; display: flex; align-items: center; justify-content: center;
    gap: .4rem; transition: filter .18s; margin-top: .75rem;
}
.btn-save-selected:hover { filter: brightness(1.1); }
.btn-save-selected:disabled { filter: grayscale(1) opacity(.6); cursor: not-allowed; }

.spinner {
    display: inline-block; width: 14px; height: 14px;
    border: 2px solid rgba(255,255,255,.3);
    border-top-color: #fff; border-radius: 50%;
    animation: spin .7s linear infinite; vertical-align: middle;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Questions panel ── */
.questions-panel { background: #161b22; border: 1px solid #30363d; border-radius: 12px; overflow: hidden; }
.q-panel-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: .85rem 1.1rem; border-bottom: 1px solid #30363d;
    background: #1c2330; flex-wrap: wrap; gap: .5rem;
}
.q-panel-head h3 { font-size: .92rem; font-weight: 700; color: #e6edf3; margin: 0; }
.q-panel-actions { display: flex; align-items: center; gap: .5rem; }
.q-count-badge {
    background: #131c2b; border: 1px solid #1d3050;
    color: #58a6ff; font-size: .75rem; font-weight: 700;
    padding: .2rem .65rem; border-radius: 20px;
}
.btn-qbank {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .3rem .75rem; border-radius: 7px;
    border: 1px solid #30363d; background: transparent;
    color: #bc8cff; font-size: .78rem; font-weight: 600;
    text-decoration: none; transition: all .15s;
}
.btn-qbank:hover { border-color: #bc8cff; }

.q-item { border-bottom: 1px solid #30363d; padding: 1rem 1.1rem; transition: background .15s; }
.q-item:last-child { border-bottom: none; }
.q-item:hover { background: #1c2330; }
.q-item-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; margin-bottom: .65rem; }
.q-num { font-size: .75rem; font-weight: 700; color: #8b949e; flex-shrink: 0; padding-top: .1rem; }
.q-text { font-size: .9rem; color: #e6edf3; line-height: 1.5; flex: 1; }
.q-marks-badge {
    font-size: .72rem; font-weight: 700;
    background: #1c2330; border: 1px solid #30363d;
    color: #8b949e; padding: .15rem .5rem; border-radius: 6px;
    white-space: nowrap; flex-shrink: 0;
}

/* ── Question image display (right panel) ── */
.q-img-wrap {
    margin-bottom: .7rem;
    border: 1px solid #30363d; border-radius: 8px;
    overflow: hidden; background: #0d1117;
    position: relative; display: inline-block; max-width: 100%;
}
.q-img-wrap img {
    display: block; max-width: 100%; max-height: 220px;
    object-fit: contain; padding: .4rem;
}
.q-img-badge {
    position: absolute; top: .35rem; left: .35rem;
    background: rgba(13,17,23,.85); border: 1px solid #30363d;
    border-radius: 5px; padding: .15rem .4rem;
    font-size: .65rem; font-weight: 700; color: #8b949e;
    display: flex; align-items: center; gap: .25rem;
}
.btn-remove-img {
    display: inline-flex; align-items: center; gap: .25rem;
    margin-top: .4rem; padding: .2rem .6rem; border-radius: 6px;
    border: 1px solid #30363d; background: transparent;
    color: #8b949e; font-size: .72rem; cursor: pointer;
    transition: all .15s;
}
.btn-remove-img:hover { border-color: #f85149; color: #f85149; }

.opts-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .35rem .75rem; margin-top: .5rem; }
.opt-display { display: flex; align-items: center; gap: .45rem; font-size: .82rem; padding: .35rem .6rem; border-radius: 7px; border: 1px solid #30363d; }
.opt-display.correct { border-color: #238636; background: #0d2818; color: #3fb950; }
.opt-display.wrong   { color: #8b949e; }
.opt-ltr { font-size: .7rem; font-weight: 700; width: 20px; height: 20px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.opt-display.correct .opt-ltr { background: #3fb950; color: #fff; }
.opt-display.wrong   .opt-ltr { background: #30363d; color: #8b949e; }

.btn-del-q, .btn-edit-q {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .25rem .65rem; border-radius: 7px;
    border: 1px solid #30363d; background: transparent;
    color: #8b949e; font-size: .75rem; font-weight: 600;
    cursor: pointer; text-decoration: none;
    transition: all .15s; white-space: nowrap; flex-shrink: 0;
}
.btn-del-q:hover  { border-color: #f85149; color: #f85149; }
.btn-edit-q:hover { border-color: #58a6ff; color: #58a6ff; }

.empty-qs { padding: 3rem; text-align: center; color: #8b949e; }
.empty-qs i { display: block; font-size: 2rem; margin-bottom: .5rem; color: #30363d; }

.btn-back {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .45rem 1rem; border-radius: 8px;
    border: 1px solid #30363d; background: #161b22;
    color: #8b949e; font-size: .83rem; font-weight: 600;
    text-decoration: none; transition: all .18s;
}
.btn-back:hover { border-color: #58a6ff; color: #58a6ff; }

.ai-results::-webkit-scrollbar { width: 5px; }
.ai-results::-webkit-scrollbar-track { background: transparent; }
.ai-results::-webkit-scrollbar-thumb { background: #30363d; border-radius: 4px; }

/* img upload drag feedback */
.img-upload-zone.has-file { border-color: #3fb950; border-style: solid; }
.img-upload-zone.has-file .upload-icon { color: #3fb950; }
</style>

<!-- Page header -->
<div class="page-header">
    <div>
        <h1 style="color: #555;"><i class="bi bi-card-checklist" style="color:#3fb950;margin-right:.4rem"></i>Add Questions</h1>
        <div class="sub" style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-top:.35rem">
            <span class="exam-pill"><i class="bi bi-pencil-square"></i><?= htmlspecialchars($exam['title']) ?></span>
            <span style="color:#8b949e"><?= htmlspecialchars($exam['course_name']) ?></span>
        </div>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <a href="question_bank.php?exam_id=<?= $exam_id ?>" class="btn-back">
            <i class="bi bi-collection"></i> Question Bank
        </a>
        <a href="manage_exams.php" class="btn-back">
            <i class="bi bi-arrow-left"></i> Back to Exams
        </a>
    </div>
</div>

<!-- Flash messages -->
<?php if ($success): ?>
<div class="flash flash-success"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="flash flash-error"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
<div class="flash flash-success"><i class="bi bi-check-circle-fill"></i> Question deleted.</div>
<?php endif; ?>
<?php if (isset($_GET['img_removed'])): ?>
<div class="flash flash-success"><i class="bi bi-check-circle-fill"></i> Image removed.</div>
<?php endif; ?>
<?php if (isset($_GET['ai_saved'])): ?>
<?php $ai_saved = (int)$_GET['ai_saved']; $ai_skipped = (int)($_GET['ai_skipped'] ?? 0); ?>
<div class="flash flash-success">
    <i class="bi bi-stars"></i>
    <?= $ai_saved ?> AI question<?= $ai_saved !== 1 ? 's' : '' ?> saved.
    <?php if ($ai_skipped): ?>
        <?= $ai_skipped ?> duplicate<?= $ai_skipped !== 1 ? 's' : '' ?> skipped.
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="two-col">

    <!-- ══ LEFT: Form panel ══ -->
    <div class="form-panel">
        <div class="tab-bar">
            <button class="tab-btn active" onclick="switchTab('manual', this)">
                <i class="bi bi-pencil"></i> Manual
            </button>
            <button class="tab-btn ai-tab" onclick="switchTab('ai', this)">
                <i class="bi bi-stars"></i> AI Generator
            </button>
        </div>

        <!-- Manual tab -->
        <div id="tab-manual" class="tab-pane active">
            <div class="panel-body">
                <form method="POST" action="add_question.php?exam_id=<?= $exam_id ?>"
                      enctype="multipart/form-data">

                    <label class="f-label">Question Text</label>
                    <textarea name="question_text" class="f-textarea"
                              placeholder="Type your question here…" required></textarea>

                    <!-- ── Image upload ── -->
                    <label class="f-label">
                        Question Image
                        <span style="font-weight:400;text-transform:none;font-size:.72rem">
                            (optional — JPG, PNG, GIF, WebP · max 5 MB)
                        </span>
                    </label>

                    <!-- Preview (shown when file chosen) -->
                    <div class="img-preview-wrap" id="imgPreviewWrap">
                        <img id="imgPreviewEl" src="" alt="Question image preview">
                        <button type="button" class="img-preview-clear" onclick="clearImage()">
                            <i class="bi bi-x"></i> Remove
                        </button>
                    </div>

                    <!-- Drop zone (hidden when preview showing) -->
                    <div class="img-upload-zone" id="imgDropZone">
                        <input type="file" name="question_image" id="imgFileInput"
                               accept="image/jpeg,image/png,image/gif,image/webp"
                               onchange="previewImage(this)">
                        <div class="upload-icon"><i class="bi bi-image"></i></div>
                        <div class="upload-text">Click to upload or drag &amp; drop</div>
                        <div class="upload-hint">JPG · PNG · GIF · WebP — up to 5 MB</div>
                    </div>

                    <!-- ── Options ── -->
                    <label class="f-label" style="margin-top:.1rem">
                        Options
                        <span style="font-weight:400;text-transform:none;font-size:.72rem">
                            — green dot = correct answer
                        </span>
                    </label>

                    <div class="options-wrap">
                        <?php
                        $optDefs = [
                            ['name'=>'option_a','ph'=>'Option A','required'=>true],
                            ['name'=>'option_b','ph'=>'Option B','required'=>true],
                            ['name'=>'option_c','ph'=>'Option C (optional)','required'=>false],
                            ['name'=>'option_d','ph'=>'Option D (optional)','required'=>false],
                        ];
                        foreach ($optDefs as $i => $opt):
                            $ltr = chr(65 + $i);
                        ?>
                        <div class="option-row">
                            <span class="opt-letter"><?= $ltr ?></span>
                            <input type="text"
                                   name="<?= $opt['name'] ?>"
                                   class="f-opt-input"
                                   placeholder="<?= $opt['ph'] ?>"
                                   <?= $opt['required'] ? 'required' : '' ?>>
                            <input type="radio"
                                   name="correct_option"
                                   value="<?= strtolower($ltr) ?>"
                                   class="correct-radio"
                                   title="Mark as correct answer"
                                   <?= $i === 0 ? 'required' : '' ?>>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="marks-row">
                        <label class="f-label" for="marksInput">Marks</label>
                        <input type="number" id="marksInput" name="marks" min="1" max="100" value="1">
                    </div>

                    <button type="submit" name="add_question" class="btn-submit-q">
                        <i class="bi bi-check-lg"></i> Save Question
                    </button>
                </form>
            </div>
        </div>

        <!-- AI Generator tab -->
        <div id="tab-ai" class="tab-pane">
            <div class="panel-body">
                <label class="f-label">Topic / Focus Area</label>
                <textarea id="ai-topic" class="f-textarea" style="min-height:70px"
                          placeholder="e.g. SQL joins and normalization — INNER JOIN, LEFT JOIN, 3NF"></textarea>

                <div class="f-grid-2">
                    <div>
                        <label class="f-label">Question Type</label>
                        <select id="ai-qtype" class="f-select-sm">
                            <option value="mcq">Multiple choice</option>
                            <option value="truefalse">True / False</option>
                            <option value="mixed">Mixed</option>
                        </select>
                    </div>
                    <div>
                        <label class="f-label">Number</label>
                        <select id="ai-qcount" class="f-select-sm">
                            <option value="5">5 questions</option>
                            <option value="10" selected>10 questions</option>
                            <option value="15">15 questions</option>
                        </select>
                    </div>
                </div>

                <div class="f-grid-2">
                    <div>
                        <label class="f-label">Difficulty</label>
                        <select id="ai-diff" class="f-select-sm">
                            <option value="easy">Easy</option>
                            <option value="medium" selected>Medium</option>
                            <option value="hard">Hard</option>
                            <option value="mixed">Mixed</option>
                        </select>
                    </div>
                    <div>
                        <label class="f-label">Language</label>
                        <select id="ai-lang" class="f-select-sm">
                            <option value="English">English</option>
                            <option value="French">French</option>
                        </select>
                    </div>
                </div>

                <button class="btn-generate" id="ai-gen-btn" onclick="generateAI()">
                    <i class="bi bi-stars"></i> Generate Questions
                </button>
                <div class="ai-status" id="ai-status"></div>

                <div class="ai-results" id="ai-results" style="display:none"></div>

                <button class="btn-save-selected" id="ai-save-btn"
                        style="display:none" onclick="saveSelected()">
                    <i class="bi bi-cloud-check"></i> Save Selected to Question Bank
                </button>
            </div>
        </div>
    </div>

    <!-- ══ RIGHT: Questions linked to this exam ══ -->
    <div class="questions-panel">
        <div class="q-panel-head">
            <h3><i class="bi bi-list-ol" style="color:#58a6ff;margin-right:.4rem"></i>Exam Questions</h3>
            <div class="q-panel-actions">
                <a href="question_bank.php?exam_id=<?= $exam_id ?>" class="btn-qbank">
                    <i class="bi bi-collection"></i> Question Bank
                </a>
                <span class="q-count-badge">
                    <?= $total_questions ?> question<?= $total_questions !== 1 ? 's' : '' ?>
                </span>
            </div>
        </div>

        <?php if (empty($questions)): ?>
        <div class="empty-qs">
            <i class="bi bi-patch-question"></i>
            No questions yet — add your first question using the form.
        </div>
        <?php else: ?>

        <?php foreach ($questions as $i => $q):
            $opts = ['a' => $q['option_a'], 'b' => $q['option_b'],
                     'c' => $q['option_c'], 'd' => $q['option_d']];
            $has_image = !empty($q['image_path']) &&
                         file_exists(Q_IMG_DIR . $q['image_path']);
        ?>
        <div class="q-item">
            <div class="q-item-head">
                <span class="q-num">Q<?= $i + 1 ?></span>
                <span class="q-text"><?= htmlspecialchars($q['question_text']) ?></span>
                <span class="q-marks-badge"><?= (int)$q['marks'] ?> mk</span>
                <div style="display:flex;gap:.35rem;flex-shrink:0">
                    <a href="edit_question.php?question_id=<?= $q['id'] ?>&exam_id=<?= $exam_id ?>"
                       class="btn-edit-q"><i class="bi bi-pencil"></i></a>
                    <form method="POST" action="add_question.php?exam_id=<?= $exam_id ?>"
                          onsubmit="return confirm('Delete this question?')">
                        <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                        <button type="submit" name="delete_question" class="btn-del-q">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </form>
                </div>
            </div>

            <?php if ($has_image): ?>
            <!-- Question image display -->
            <div style="margin-bottom:.65rem">
                <div class="q-img-wrap">
                    <img src="<?= Q_IMG_URL . htmlspecialchars($q['image_path']) ?>"
                         alt="Question image"
                         onclick="openLightbox(this.src)"
                         style="cursor:zoom-in">
                    <span class="q-img-badge"><i class="bi bi-image"></i> Image</span>
                </div>
                <br>
                <form method="POST" action="add_question.php?exam_id=<?= $exam_id ?>"
                      style="display:inline"
                      onsubmit="return confirm('Remove image from this question?')">
                    <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                    <button type="submit" name="remove_image" class="btn-remove-img">
                        <i class="bi bi-trash3"></i> Remove image
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Options grid -->
            <div class="opts-grid">
                <?php foreach ($opts as $key => $text):
                    if ($text === '' || $text === null) continue;
                    $isCorrect = ($q['correct_option'] === $key);
                ?>
                <div class="opt-display <?= $isCorrect ? 'correct' : 'wrong' ?>">
                    <span class="opt-ltr"><?= $letters[$key] ?></span>
                    <span><?= htmlspecialchars($text) ?></span>
                    <?php if ($isCorrect): ?>
                        <i class="bi bi-check-lg" style="margin-left:auto;font-size:.75rem"></i>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>
    </div>

</div>

<!-- ── Lightbox overlay for image zoom ── -->
<div id="lightbox" style="
    display:none; position:fixed; inset:0; z-index:9000;
    background:rgba(0,0,0,.88); backdrop-filter:blur(4px);
    align-items:center; justify-content:center; cursor:zoom-out;"
     onclick="closeLightbox()">
    <img id="lightbox-img" src="" alt=""
         style="max-width:90vw; max-height:88vh; object-fit:contain;
                border-radius:8px; box-shadow:0 20px 60px rgba(0,0,0,.6);">
    <button onclick="closeLightbox()" style="
        position:absolute; top:1rem; right:1rem;
        background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.2);
        color:#fff; border-radius:8px; padding:.4rem .8rem;
        font-size:.85rem; cursor:pointer;">
        <i class="bi bi-x-lg"></i> Close
    </button>
</div>

<script>
// ── Tab switching ─────────────────────────────────────────────────────────────
function switchTab(name, btn) {
    document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
}

// ── Image upload preview ──────────────────────────────────────────────────────
function previewImage(input) {
    if (!input.files || !input.files[0]) return;
    const file = input.files[0];
    if (file.size > 5 * 1024 * 1024) {
        alert('Image must be under 5 MB.');
        input.value = '';
        return;
    }
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('imgPreviewEl').src = e.target.result;
        document.getElementById('imgPreviewWrap').style.display = 'block';
        document.getElementById('imgDropZone').style.display = 'none';
    };
    reader.readAsDataURL(file);
}

function clearImage() {
    document.getElementById('imgFileInput').value = '';
    document.getElementById('imgPreviewEl').src = '';
    document.getElementById('imgPreviewWrap').style.display = 'none';
    document.getElementById('imgDropZone').style.display = 'block';
}

// Drag-and-drop on the zone
(function() {
    const zone = document.getElementById('imgDropZone');
    if (!zone) return;
    zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', ()  => zone.classList.remove('dragover'));
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('dragover');
        const files = e.dataTransfer.files;
        if (files.length) {
            document.getElementById('imgFileInput').files = files;
            previewImage(document.getElementById('imgFileInput'));
        }
    });
})();

// ── Lightbox ──────────────────────────────────────────────────────────────────
function openLightbox(src) {
    const lb = document.getElementById('lightbox');
    document.getElementById('lightbox-img').src = src;
    lb.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('lightbox').style.display = 'none';
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeLightbox(); });

// ── AI Generator ──────────────────────────────────────────────────────────────
const COURSE_NAME = <?= json_encode($exam['course_name']) ?>;
const EXISTING_QUESTIONS = <?= json_encode(
    array_map(fn($q) => strtolower(trim($q['question_text'])), $questions)
) ?>;
const typeMap = {
    mcq:       'multiple choice with 4 options (A, B, C, D) and one correct answer',
    truefalse: 'True/False with the correct answer stated',
    mixed:     'a mix of multiple choice and True/False'
};
let generatedQs = [];

async function generateAI() {
    const topic  = document.getElementById('ai-topic').value.trim();
    const qtype  = document.getElementById('ai-qtype').value;
    const qcount = document.getElementById('ai-qcount').value;
    const diff   = document.getElementById('ai-diff').value;
    const lang   = document.getElementById('ai-lang').value;
    const btn    = document.getElementById('ai-gen-btn');
    const status = document.getElementById('ai-status');

    if (!topic) { status.textContent = 'Please enter a topic first.'; status.className = 'ai-status err'; return; }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Generating…';
    status.textContent = '';
    status.className = 'ai-status';
    document.getElementById('ai-results').style.display = 'none';
    document.getElementById('ai-save-btn').style.display = 'none';

    const prompt = `You are an expert exam question writer for university-level courses.
Generate exactly ${qcount} ${typeMap[qtype]} questions on: "${topic}" for the course: "${COURSE_NAME}".
Difficulty: ${diff}. Language: ${lang}.
Return ONLY a valid JSON array. No markdown, no preamble, no extra text.
Each object: "num":integer, "type":"mcq"|"truefalse", "question":string, "options":array of strings, "answer":exact string matching one option, "difficulty":"easy"|"medium"|"hard"`;

    try {
        const res  = await fetch('ajax/ai_generate.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ prompt })
        });
        const data = await res.json();
        if (data.error) throw new Error(data.error);
        const clean = data.text.replace(/```json|```/g, '').trim();
        generatedQs = JSON.parse(clean);
        renderAIResults(generatedQs);
        const dupCount = generatedQs.filter(q =>
            EXISTING_QUESTIONS.includes(q.question.toLowerCase().trim())
        ).length;
        let msg = '✓ ' + generatedQs.length + ' questions generated.';
        if (dupCount) msg += ' ' + dupCount + ' already exist (marked, auto-unchecked).';
        status.textContent = msg;
        status.className = dupCount ? 'ai-status warn' : 'ai-status ok';
    } catch (e) {
        status.textContent = 'Generation failed: ' + e.message;
        status.className = 'ai-status err';
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-stars"></i> Generate Questions';
}

function renderAIResults(qs) {
    const list = document.getElementById('ai-results');
    list.innerHTML = '';
    list.style.display = 'flex';
    qs.forEach((q, i) => {
        const isDup = EXISTING_QUESTIONS.includes(q.question.toLowerCase().trim());
        const card  = document.createElement('div');
        card.className = 'ai-q-card' + (isDup ? ' duplicate' : ' selected');
        card.id = 'ai-card-' + i;
        let optsHtml = '';
        if (q.options && q.options.length) {
            optsHtml = '<div class="ai-opts">' + q.options.map(o => {
                const isC = o === q.answer;
                return `<div class="ai-opt${isC ? ' correct' : ''}">${esc(o)}</div>`;
            }).join('') + '</div>';
        }
        card.innerHTML = `
            <div class="ai-q-meta">
                <span class="ai-q-num">Q${q.num}</span>
                <span class="ai-q-diff diff-${q.difficulty}">${q.difficulty}</span>
            </div>
            <div class="ai-q-text">${esc(q.question)}</div>
            ${optsHtml}
            <div class="ai-q-footer">
                ${isDup
                    ? '<span class="dup-badge"><i class="bi bi-exclamation-triangle"></i> Already exists</span>'
                    : `<span class="ai-ans"><i class="bi bi-check-circle"></i> ${esc(q.answer)}</span>`
                }
                <label class="ai-check">
                    <input type="checkbox" class="ai-select" data-idx="${i}"
                           ${isDup ? '' : 'checked'}
                           ${isDup ? 'disabled title="Duplicate"' : ''}
                           onchange="updateCard(${i},this.checked)">
                    ${isDup ? 'Duplicate' : 'Include'}
                </label>
            </div>`;
        list.appendChild(card);
    });
    document.getElementById('ai-save-btn').style.display = 'flex';
}

function updateCard(idx, checked) {
    document.getElementById('ai-card-' + idx).classList.toggle('selected', checked);
}
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

async function saveSelected() {
    const selected = [];
    document.querySelectorAll('.ai-select:checked:not(:disabled)').forEach(cb => {
        selected.push(generatedQs[parseInt(cb.dataset.idx)]);
    });
    if (!selected.length) {
        document.getElementById('ai-status').textContent = 'No questions selected.';
        document.getElementById('ai-status').className = 'ai-status err';
        return;
    }
    const btn = document.getElementById('ai-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span> Saving…';
    const form = new FormData();
    form.append('save_ai_questions', '1');
    form.append('questions_json', JSON.stringify(selected));
    try {
        const res  = await fetch('add_question.php?exam_id=<?= $exam_id ?>', { method: 'POST', body: form });
        const data = await res.json();
        if (data.success) {
            window.location.href = 'add_question.php?exam_id=<?= $exam_id ?>&ai_saved=' + data.saved + '&ai_skipped=' + (data.skipped ?? 0);
        } else throw new Error('Save failed');
    } catch (e) {
        document.getElementById('ai-status').textContent = 'Save failed. Try again.';
        document.getElementById('ai-status').className = 'ai-status err';
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-cloud-check"></i> Save Selected to Question Bank';
    }
}
</script>

<?php include "../includes/footer.php"; ?>