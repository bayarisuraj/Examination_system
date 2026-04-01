<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/db.php";

$lecturer_id = (int)$_SESSION['user_id'];
$course_id   = (int)($_GET['course_id'] ?? 0);

if (!$course_id) {
    header("Location: manage_courses.php");
    exit();
}

// ── Fetch course info ─────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT c.course_name, c.course_code, c.description
    FROM courses c
    WHERE c.id = ? AND c.lecturer_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $course_id, $lecturer_id);
$stmt->execute();
$course = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$course) {
    header("Location: manage_courses.php");
    exit();
}

// ── Quick stats ───────────────────────────────────────────────────
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM course_enrollments
    WHERE course_id = ?
");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$total_students = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM exams
    WHERE course_id = ? AND created_by = ?
");
$stmt->bind_param("ii", $course_id, $lecturer_id);
$stmt->execute();
$total_exams = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM exam_attempts a
    JOIN exams e ON e.id = a.exam_id
    WHERE e.course_id = ? AND a.status = 'completed'
");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$total_submissions = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

$stmt = $conn->prepare("
    SELECT ROUND(AVG(a.score), 1) AS avg_score
    FROM exam_attempts a
    JOIN exams e ON e.id = a.exam_id
    WHERE e.course_id = ? AND a.status = 'completed'
");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$avg_score = $stmt->get_result()->fetch_assoc()['avg_score'];
$stmt->close();

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
    --amber:   #d29922;
    --purple:  #bc8cff;
    --radius:  12px;
    --sans:    'DM Sans', sans-serif;
    --serif:   'Sora', sans-serif;
    --mono:    'DM Mono', monospace;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: var(--sans); min-height: 100vh; }
.page-shell { max-width: 900px; margin: 0 auto; padding: 2.5rem 1.25rem 5rem; }

/* ── Back link ── */
.btn-back {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .45rem 1rem; border-radius: 8px;
    border: 1px solid var(--border); background: var(--surface);
    color: var(--muted); font-size: .84rem; font-weight: 600;
    text-decoration: none; transition: border-color .2s, color .2s;
    margin-bottom: 1.75rem;
}
.btn-back:hover { border-color: var(--accent); color: var(--accent); }

/* ── Course hero card ── */
.course-hero {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.75rem 2rem;
    margin-bottom: 1.5rem;
    position: relative;
    overflow: hidden;
}
.course-hero::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--accent), var(--purple));
}
.course-code-tag {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .25rem .75rem; border-radius: 20px;
    font-family: var(--mono); font-size: .78rem; font-weight: 700;
    background: #131c2b; border: 1px solid var(--accent); color: var(--accent);
    margin-bottom: .85rem;
}
.course-hero h1 {
    font-family: var(--serif); font-size: 1.6rem; font-weight: 700;
    color: var(--text); line-height: 1.25; margin-bottom: .85rem;
}
.course-desc {
    font-size: .9rem; color: var(--muted); line-height: 1.7;
    border-left: 3px solid var(--border);
    padding-left: .9rem; margin-bottom: 0;
}

/* ── Stat grid ── */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: .85rem; margin-bottom: 1.75rem;
}
.stat-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 1rem 1.1rem;
    display: flex; flex-direction: column; gap: .3rem;
}
.s-label { font-size: .71rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 700; color: var(--muted); }
.s-val   { font-family: var(--mono); font-size: 1.5rem; font-weight: 600; }
.sc-stud .s-val { color: var(--accent); }
.sc-exam .s-val { color: var(--purple); }
.sc-sub  .s-val { color: var(--amber); }
.sc-avg  .s-val { color: var(--green); }

/* ── Action buttons ── */
.actions-title {
    font-family: var(--serif); font-size: .95rem; font-weight: 700;
    color: var(--text); margin-bottom: 1rem;
    display: flex; align-items: center; gap: .5rem;
}
.action-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: .85rem;
}
.action-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 1.25rem 1.4rem;
    text-decoration: none; display: flex; align-items: flex-start;
    gap: 1rem; transition: border-color .2s, background .2s;
    cursor: pointer;
}
.action-card:hover { border-color: var(--accent); background: var(--surface2); }
.action-card.ac-students:hover { border-color: var(--accent); }
.action-card.ac-exams:hover    { border-color: var(--purple); }
.action-card.ac-results:hover  { border-color: var(--green); }

.action-icon {
    width: 42px; height: 42px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.15rem; flex-shrink: 0;
}
.ac-students .action-icon { background: #131c2b; color: var(--accent); }
.ac-exams    .action-icon { background: #1a1030; color: var(--purple); }
.ac-results  .action-icon { background: #0d2818; color: var(--green); }

.action-body {}
.action-label {
    font-family: var(--serif); font-size: .95rem; font-weight: 700;
    color: var(--text); margin-bottom: .2rem; display: block;
}
.action-desc { font-size: .78rem; color: var(--muted); line-height: 1.4; }

@media (max-width: 600px) {
    .page-shell { padding: 1.25rem .75rem 4rem; }
    .course-hero { padding: 1.25rem; }
    .course-hero h1 { font-size: 1.3rem; }
}
</style>

<div class="page-shell">

    <a href="manage_courses.php" class="btn-back">
        <i class="bi bi-arrow-left"></i> My Courses
    </a>

    <!-- Course hero -->
    <div class="course-hero">
        <div class="course-code-tag">
            <i class="bi bi-tag"></i>
            <?= htmlspecialchars($course['course_code']) ?>
        </div>
        <h1><?= htmlspecialchars($course['course_name']) ?></h1>
        <p class="course-desc">
            <?= nl2br(htmlspecialchars($course['description'] ?? 'No description provided.')) ?>
        </p>
    </div>

    <!-- Stat cards -->
    <div class="stat-grid">
        <div class="stat-card sc-stud">
            <span class="s-label">Students</span>
            <span class="s-val"><?= $total_students ?></span>
        </div>
        <div class="stat-card sc-exam">
            <span class="s-label">Exams</span>
            <span class="s-val"><?= $total_exams ?></span>
        </div>
        <div class="stat-card sc-sub">
            <span class="s-label">Submissions</span>
            <span class="s-val"><?= $total_submissions ?></span>
        </div>
        <div class="stat-card sc-avg">
            <span class="s-label">Avg Score</span>
            <span class="s-val"><?= $avg_score !== null ? $avg_score . '%' : '—' ?></span>
        </div>
    </div>

    <!-- Actions -->
    <div class="actions-title">
        <i class="bi bi-grid-3x3-gap" style="color:var(--accent)"></i>
        Quick Actions
    </div>

    <div class="action-grid">
        <a href="view_students.php?course_id=<?= $course_id ?>" class="action-card ac-students">
            <div class="action-icon"><i class="bi bi-people-fill"></i></div>
            <div class="action-body">
                <span class="action-label">View Students</span>
                <span class="action-desc"><?= $total_students ?> enrolled — view progress and details</span>
            </div>
        </a>

        <a href="manage_exams.php?course_id=<?= $course_id ?>" class="action-card ac-exams">
            <div class="action-icon"><i class="bi bi-pencil-square"></i></div>
            <div class="action-body">
                <span class="action-label">Manage Exams</span>
                <span class="action-desc"><?= $total_exams ?> exam<?= $total_exams !== 1 ? 's' : '' ?> — create, edit, schedule</span>
            </div>
        </a>

        <a href="course_results.php?course_id=<?= $course_id ?>" class="action-card ac-results">
            <div class="action-icon"><i class="bi bi-bar-chart-fill"></i></div>
            <div class="action-body">
                <span class="action-label">View Results</span>
                <span class="action-desc"><?= $total_submissions ?> submission<?= $total_submissions !== 1 ? 's' : '' ?> — scores and grade matrix</span>
            </div>
        </a>
    </div>

</div><!-- /page-shell -->

<?php include "../includes/footer.php"; ?>