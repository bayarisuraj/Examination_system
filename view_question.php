<?php
session_start();
require_once "../config/db.php";

if(!isset($_SESSION['role']) || $_SESSION['role'] != "lecturer"){
    header("Location: ../auth/login.php");
    exit();
}

$lecturer_id = (int)($_SESSION['user_id'] ?? 0);
$exam_id     = (int)($_GET['exam_id'] ?? 0); // optional — 0 means "all questions"

// ── If exam_id given, fetch & verify that exam belongs to this lecturer ──
$exam = null;
if($exam_id > 0){
    $stmt = $conn->prepare("
        SELECT e.id, e.title, e.status, e.total_marks, e.duration, e.exam_date, c.course_name
        FROM exams e
        JOIN courses c ON e.course_id = c.id
        WHERE e.id = ? AND c.lecturer_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $exam_id, $lecturer_id);
    $stmt->execute();
    $exam = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if(!$exam){
        $_SESSION['error'] = "Exam not found or access denied.";
        header("Location: manage_exams.php");
        exit();
    }
}

// ── Handle Publish / Unpublish (only when exam_id present) ──
$message = $msg_type = "";
if($exam_id > 0 && isset($_POST['toggle_publish'])){
    $new_status = ($exam['status'] === 'published') ? 'draft' : 'published';
    $stmt = $conn->prepare("UPDATE exams SET status = ? WHERE id = ? AND lecturer_id = ?");
    $stmt->bind_param("sii", $new_status, $exam_id, $lecturer_id);
    if($stmt->execute()){
        $exam['status'] = $new_status;
        $message  = $new_status === 'published'
            ? "Exam published successfully! Students can now take it."
            : "Exam unpublished. Students can no longer see it.";
        $msg_type = $new_status === 'published' ? 'success' : 'warning';
    } else {
        $message  = "Failed to update exam status.";
        $msg_type = 'danger';
    }
    $stmt->close();
}

// ── Handle Remove Question from Exam (only when exam_id present) ──
if($exam_id > 0 && isset($_POST['remove_question'])){
    $question_id = (int)($_POST['question_id'] ?? 0);
    if($question_id > 0){
        $stmt = $conn->prepare("DELETE FROM exam_questions WHERE exam_id = ? AND question_id = ?");
        $stmt->bind_param("ii", $exam_id, $question_id);
        $stmt->execute();
        $stmt->close();
        $message  = "Question removed from exam.";
        $msg_type = 'info';
    }
}

// ── Fetch questions ──
// If exam_id given: questions linked to that exam
// If no exam_id:    all questions across lecturer's courses
if($exam_id > 0){
    $stmt = $conn->prepare("
        SELECT q.id, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d,
               q.correct_option, c.course_name
        FROM questions q
        JOIN exam_questions eq ON q.id = eq.question_id
        JOIN courses c ON q.course_id = c.id
        WHERE eq.exam_id = ?
        ORDER BY q.id ASC
    ");
    $stmt->bind_param("i", $exam_id);
} else {
    $stmt = $conn->prepare("
        SELECT q.id, q.question_text, q.option_a, q.option_b, q.option_c, q.option_d,
               q.correct_option, c.course_name
        FROM questions q
        JOIN courses c ON q.course_id = c.id
        WHERE c.lecturer_id = ?
        ORDER BY c.course_name ASC, q.id ASC
    ");
    $stmt->bind_param("i", $lecturer_id);
}
$stmt->execute();
$questions      = $stmt->get_result();
$question_count = $questions->num_rows;
$stmt->close();

$is_published = $exam ? $exam['status'] === 'published' : false;

include "../includes/header.php";
?>

<style>
:root {
    --teal:        #3d8b8d;
    --teal-dark:   #2d6e70;
    --teal-light:  #56a8aa;
    --teal-pale:   #eaf5f5;
    --teal-border: #c0dfe0;
}

/* ── Exam Banner (only shown when exam_id present) ── */
.exam-banner {
    background: linear-gradient(135deg, var(--teal-dark) 0%, var(--teal) 60%, var(--teal-light) 100%);
    border-radius: 14px;
    padding: 1.4rem 1.8rem;
    color: #fff;
    margin-bottom: 1.5rem;
}
.exam-banner h4 { font-weight: 700; margin-bottom: 0.3rem; }
.exam-banner .meta { font-size: 0.88rem; opacity: 0.88; }

/* ── Table ── */
.table thead { background: var(--teal-dark); color: #fff; }
.table tbody tr:hover { background-color: var(--teal-pale); transition: 0.2s; }
.table td, .table th { vertical-align: middle; font-size: 0.9rem; }

/* ── Card header ── */
.card-header {
    font-weight: 600;
    font-size: 1rem;
    background-color: var(--teal-pale);
    border-bottom: 2px solid var(--teal-border);
    color: var(--teal-dark);
}

/* ── Answer badge ── */
.answer-badge {
    font-size: 0.82rem;
    padding: 4px 12px;
    border-radius: 20px;
    font-weight: 700;
    background: var(--teal);
    color: #fff;
}

/* ── Empty state ── */
.empty-state {
    text-align: center;
    padding: 3rem 1rem;
    color: #aaa;
}
.empty-state i {
    font-size: 3rem;
    display: block;
    margin-bottom: 0.8rem;
    color: var(--teal-border);
}

/* ── Button helpers ── */
.btn-teal {
    background-color: var(--teal);
    border-color: var(--teal);
    color: #fff;
}
.btn-teal:hover {
    background-color: var(--teal-dark);
    border-color: var(--teal-dark);
    color: #fff;
}
.btn-outline-teal {
    border-color: var(--teal);
    color: var(--teal);
}
.btn-outline-teal:hover {
    background-color: var(--teal);
    color: #fff;
}
</style>

<div class="container-fluid px-4">

    <?php if($exam): ?>
    <!-- Exam Banner — only when viewing a specific exam's questions -->
    <div class="exam-banner mt-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <h4><?= htmlspecialchars($exam['title']) ?></h4>
                <div class="meta d-flex flex-wrap gap-3">
                    <span><i class="bi bi-journal-bookmark me-1"></i><?= htmlspecialchars($exam['course_name']) ?></span>
                    <span><i class="bi bi-calendar3 me-1"></i><?= date("d M Y", strtotime($exam['exam_date'])) ?></span>
                    <span><i class="bi bi-stopwatch me-1"></i><?= $exam['duration'] ?> min</span>
                    <span><i class="bi bi-award me-1"></i><?= $exam['total_marks'] ?> marks</span>
                    <span><i class="bi bi-patch-question me-1"></i><?= $question_count ?> question<?= $question_count !== 1 ? 's' : '' ?></span>
                </div>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <span class="badge fs-6" style="background:<?= $is_published ? '#2d6e70' : '#6c757d' ?>">
                    <?= $is_published ? 'Published' : 'Draft' ?>
                </span>
                <form method="POST" class="d-inline">
                    <button type="submit" name="toggle_publish"
                        class="btn btn-sm <?= $is_published ? 'btn-warning' : 'btn-light' ?> fw-bold"
                        onclick="return confirm('<?= $is_published ? 'Unpublish this exam?' : 'Publish this exam? Students will be able to take it.' ?>')">
                        <i class="bi bi-<?= $is_published ? 'eye-slash' : 'check-circle' ?>"></i>
                        <?= $is_published ? 'Unpublish' : 'Publish' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Plain title when browsing full question bank -->
    <div class="mt-4 mb-3">
        <h4 class="fw-bold mb-0" style="color:var(--teal-dark)">
            <i class="bi bi-patch-question-fill" style="color:var(--teal)"></i>
            Question Bank
        </h4>
        <p class="text-muted small mb-0">All questions across your courses — <?= $question_count ?> total</p>
    </div>
    <?php endif; ?>

    <!-- Flash Message -->
    <?php if($message): ?>
        <div class="alert alert-<?= $msg_type ?> alert-dismissible fade show">
            <i class="bi bi-<?= $msg_type === 'success' ? 'check-circle-fill' : ($msg_type === 'danger' ? 'exclamation-triangle-fill' : 'info-circle-fill') ?> me-2"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Action Bar -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h5 class="fw-bold mb-0" style="color:var(--teal-dark)">
            <i class="bi bi-list-ol" style="color:var(--teal)"></i>
            <?= $exam ? 'Exam Questions' : 'All Questions' ?>
        </h5>
        <div class="d-flex gap-2">
            <?php if($exam_id > 0): ?>
            <a href="add_question.php?exam_id=<?= $exam_id ?>" class="btn btn-teal btn-sm">
                <i class="bi bi-plus-circle"></i> Add Question
            </a>
            <a href="manage_exams.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Back to Exams
            </a>
            <?php else: ?>
            <a href="manage_exams.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Back to Exams
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Questions Table -->
    <?php if($question_count > 0): ?>
    <div class="card shadow-sm border-0">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">#</th>
                            <?php if(!$exam_id): ?>
                            <th>Course</th>
                            <?php endif; ?>
                            <th>Question</th>
                            <th>Option A</th>
                            <th>Option B</th>
                            <th>Option C</th>
                            <th>Option D</th>
                            <th>Answer</th>
                            <?php if($exam_id > 0): ?>
                            <th>Action</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $i = 1; while($q = $questions->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-3 text-muted"><?= $i++ ?></td>
                            <?php if(!$exam_id): ?>
                            <td>
                                <span class="badge" style="background:var(--teal);font-size:.75rem;">
                                    <?= htmlspecialchars($q['course_name']) ?>
                                </span>
                            </td>
                            <?php endif; ?>
                            <td style="max-width:220px;"><?= htmlspecialchars($q['question_text']) ?></td>
                            <td><?= htmlspecialchars($q['option_a']) ?></td>
                            <td><?= htmlspecialchars($q['option_b']) ?></td>
                            <td><?= htmlspecialchars($q['option_c']) ?></td>
                            <td><?= htmlspecialchars($q['option_d']) ?></td>
                            <td>
                                <span class="answer-badge">
                                    <?= strtoupper(htmlspecialchars($q['correct_option'])) ?>
                                </span>
                            </td>
                            <?php if($exam_id > 0): ?>
                            <td>
                                <form method="POST" class="d-inline"
                                      onsubmit="return confirm('Remove this question from the exam?');">
                                    <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
                                    <button type="submit" name="remove_question"
                                            class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php else: ?>
    <div class="empty-state">
        <i class="bi bi-patch-question"></i>
        <h6 class="text-muted">No questions found</h6>
        <p class="text-muted small">
            <?= $exam_id > 0 ? 'Add questions to this exam before publishing.' : 'No questions exist across your courses yet.' ?>
        </p>
        <?php if($exam_id > 0): ?>
        <a href="add_question.php?exam_id=<?= $exam_id ?>" class="btn btn-teal btn-sm mt-1">
            <i class="bi bi-plus-circle"></i> Add First Question
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

</div>

<?php include "../includes/footer.php"; ?>