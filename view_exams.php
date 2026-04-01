<?php
session_start();
require_once "../config/db.php";

if(!isset($_SESSION['role']) || $_SESSION['role'] != "lecturer"){
    header("Location: ../auth/login.php");
    exit();
}

$lecturer_id = (int)$_SESSION['user_id'];

// ── Flash messages ──
$success = $_SESSION['success'] ?? '';
$error   = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);

// ── Fetch all exams with question count in one query ──
$stmt = $conn->prepare("
    SELECT
        e.id, e.title, e.exam_date, e.duration, e.total_marks, e.status,
        c.course_name,
        COUNT(eq.question_id) AS question_count
    FROM exams e
    JOIN courses c ON e.course_id = c.id
    LEFT JOIN exam_questions eq ON eq.exam_id = e.id
    WHERE e.lecturer_id = ?
    GROUP BY e.id
    ORDER BY e.exam_date DESC
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$exams = $stmt->get_result();
$stmt->close();
?>

<?php include "../includes/header.php"; ?>

<style>
.exam-card {
    border-radius: 14px;
    border: none;
    transition: transform 0.25s ease, box-shadow 0.25s ease;
    overflow: hidden;
}
.exam-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 12px 28px rgba(0,0,0,0.13);
}
.exam-card .card-top {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    padding: 1rem 1.2rem 0.8rem;
    color: #fff;
}
.exam-card .card-top h5 {
    font-weight: 700;
    font-size: 1rem;
    margin-bottom: 0.2rem;
}
.exam-card .card-top small {
    font-size: 0.82rem;
    opacity: 0.85;
}
.exam-meta {
    font-size: 0.88rem;
    color: #555;
    padding: 0.8rem 1.2rem 0;
}
.exam-meta .meta-row {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 5px 0;
    border-bottom: 1px solid #f0f0f0;
}
.exam-meta .meta-row:last-child { border-bottom: none; }
.exam-meta i { color: #6a11cb; width: 18px; }

.status-badge {
    font-size: 0.78rem;
    padding: 4px 10px;
    border-radius: 20px;
    font-weight: 600;
}

.q-count-badge {
    font-size: 0.78rem;
    padding: 3px 9px;
    border-radius: 20px;
}

.action-bar {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    padding: 0.8rem 1.2rem 1rem;
}
.action-bar .btn {
    font-size: 0.82rem;
    padding: 5px 10px;
    border-radius: 7px;
    font-weight: 500;
}

.empty-state {
    text-align: center;
    padding: 4rem 1rem;
    color: #aaa;
}
.empty-state i {
    font-size: 3.5rem;
    display: block;
    margin-bottom: 1rem;
}
</style>

<div class="container-fluid px-4">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mt-4 mb-2 flex-wrap gap-2">
        <div>
            <h2 class="fw-bold mb-0"><i class="bi bi-file-earmark-text text-primary"></i> Your Exams</h2>
            <p class="text-muted small mb-0">All exams you have created</p>
        </div>
        <div class="d-flex gap-2">
            <a href="manage_exams.php" class="btn btn-success btn-sm">
                <i class="bi bi-plus-circle"></i> Add New Exam
            </a>
            <a href="dashboard.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Flash Messages -->
    <?php if($success): ?>
        <div class="alert alert-success alert-dismissible fade show mt-3">
            <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if($error): ?>
        <div class="alert alert-danger alert-dismissible fade show mt-3">
            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Exams Grid -->
    <?php if($exams && $exams->num_rows > 0): ?>
    <div class="row g-4 mt-1">
        <?php while($e = $exams->fetch_assoc()):
            $status        = $e['status'] ?? 'draft';
            $question_count = (int)$e['question_count'];
            $is_today      = date("Y-m-d", strtotime($e['exam_date'])) === date("Y-m-d");
            $is_past       = strtotime($e['exam_date']) < strtotime(date("Y-m-d"));

            $statusColor = match($status){
                'published' => 'success',
                'draft'     => 'secondary',
                'closed'    => 'danger',
                default     => 'secondary'
            };
        ?>
        <div class="col-xl-3 col-lg-4 col-md-6">
            <div class="card exam-card shadow-sm h-100">

                <!-- Card Top Banner -->
                <div class="card-top">
                    <div class="d-flex justify-content-between align-items-start">
                        <h5><?= htmlspecialchars($e['title']) ?></h5>
                        <span class="status-badge bg-<?= $statusColor ?> text-white ms-2">
                            <?= ucfirst($status) ?>
                        </span>
                    </div>
                    <small>
                        <i class="bi bi-journal-bookmark me-1"></i>
                        <?= htmlspecialchars($e['course_name']) ?>
                    </small>
                </div>

                <!-- Exam Meta -->
                <div class="exam-meta">
                    <div class="meta-row">
                        <i class="bi bi-calendar3"></i>
                        <span>
                            <?= date("d M Y", strtotime($e['exam_date'])) ?>
                            <?php if($is_today): ?>
                                <span class="badge bg-success ms-1">Today</span>
                            <?php elseif($is_past): ?>
                                <span class="badge bg-secondary ms-1">Past</span>
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="meta-row">
                        <i class="bi bi-stopwatch"></i>
                        <span><?= $e['duration'] ?> min</span>
                    </div>
                    <div class="meta-row">
                        <i class="bi bi-award"></i>
                        <span><?= $e['total_marks'] ?> marks</span>
                    </div>
                    <div class="meta-row">
                        <i class="bi bi-patch-question"></i>
                        <span>
                            <span class="q-count-badge <?= $question_count > 0 ? 'bg-success text-white' : 'bg-warning text-dark' ?>">
                                <?= $question_count ?> question<?= $question_count !== 1 ? 's' : '' ?>
                            </span>
                        </span>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="action-bar mt-auto">
                    <a href="add_question.php?exam_id=<?= $e['id'] ?>"
                       class="btn btn-success" title="Add Questions">
                        <i class="bi bi-plus-circle"></i> Questions
                    </a>
                    <a href="edit_exam.php?exam_id=<?= $e['id'] ?>"
                       class="btn btn-primary" title="Edit Exam">
                        <i class="bi bi-pencil-square"></i> Edit
                    </a>
                    <a href="view_result.php?exam_id=<?= $e['id'] ?>"
                       class="btn btn-warning" title="View Results">
                        <i class="bi bi-bar-chart"></i> Results
                    </a>
                    <a href="delete_exam.php?exam_id=<?= $e['id'] ?>"
                       class="btn btn-danger" title="Delete Exam"
                       onclick="return confirm('Delete this exam? This cannot be undone.');">
                        <i class="bi bi-trash"></i>
                    </a>
                </div>

            </div>
        </div>
        <?php endwhile; ?>
    </div>

    <?php else: ?>
    <div class="empty-state mt-4">
        <i class="bi bi-file-earmark-x text-muted"></i>
        <h5 class="text-muted">No exams found</h5>
        <p class="text-muted small">You haven't created any exams yet.</p>
        <a href="manage_exams.php" class="btn btn-success mt-2">
            <i class="bi bi-plus-circle"></i> Create Your First Exam
        </a>
    </div>
    <?php endif; ?>

</div>

<?php include "../includes/footer.php"; ?>