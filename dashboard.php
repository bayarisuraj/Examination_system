<?php
session_start();
date_default_timezone_set('Africa/Accra');

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/db.php";

$username    = htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'Lecturer');
$lecturer_id = (int)($_SESSION['user_id'] ?? 0);

if (!$lecturer_id) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit();
}

/* ── Helper: safe count query ── */
function getCount($conn, $sql, $type, $param) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($type, $param);
    $stmt->execute();
    return (int)($stmt->get_result()->fetch_assoc()['total'] ?? 0);
}

/* ── Stat Counts ── */
$course_count = getCount($conn,
    "SELECT COUNT(*) AS total FROM courses WHERE lecturer_id = ?",
    "i", $lecturer_id);

$exam_count = getCount($conn,
    "SELECT COUNT(*) AS total FROM exams WHERE lecturer_id = ?",
    "i", $lecturer_id);

$question_count = getCount($conn,
    "SELECT COUNT(q.id) AS total
     FROM questions q
     JOIN courses c ON q.course_id = c.id
     WHERE c.lecturer_id = ?",
    "i", $lecturer_id);

$student_attempts = getCount($conn,
    "SELECT COUNT(DISTINCT a.student_id) AS total
     FROM exam_attempts a
     JOIN exams e ON a.exam_id = e.id
     WHERE e.lecturer_id = ? AND a.status = 'completed'",
    "i", $lecturer_id);

$students_enrolled = getCount($conn,
    "SELECT COUNT(DISTINCT ce.student_id) AS total
     FROM course_enrollments ce
     JOIN courses c ON ce.course_id = c.id
     WHERE c.lecturer_id = ?",
    "i", $lecturer_id);

/* ── Recent Exams ── */
$stmt = $conn->prepare("
    SELECT e.id, e.title, e.exam_date, e.duration, e.status, c.course_name
    FROM exams e
    JOIN courses c ON e.course_id = c.id
    WHERE e.lecturer_id = ?
    ORDER BY e.exam_date DESC
    LIMIT 5
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$recent_exams = $stmt->get_result();

/* ── Recent Student Enrollments ── */
$stmt2 = $conn->prepare("
    SELECT u.name, c.course_name, ce.enrolled_at
    FROM course_enrollments ce
    JOIN users u   ON u.id  = ce.student_id
    JOIN courses c ON c.id  = ce.course_id
    WHERE c.lecturer_id = ?
    ORDER BY ce.enrolled_at DESC
    LIMIT 5
");
$stmt2->bind_param("i", $lecturer_id);
$stmt2->execute();
$recent_students = $stmt2->get_result();

include "../includes/header.php";
?>

<style>
:root {
    --teal:        #3d8b8d;
    --teal-dark:   #2d6e70;
    --teal-light:  #56a8aa;
    --teal-pale:   #eaf5f5;
    --teal-border: #c0dfe0;
    --amber:       #d29922;
    --green:       #2e7d5e;
    --red:         #c0392b;
    --surface:     #ffffff;
    --surface2:    #f8fafb;
    --text:        #1a2e35;
    --muted:       #6b7c8d;
    --radius:      12px;
}
*, *::before, *::after { box-sizing: border-box; }

.dash-shell { max-width: 1200px; margin: 0 auto; padding: 1.75rem 1.25rem 4rem; }
.welcome-bar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.75rem; margin-bottom:1.75rem; }
.welcome-bar h1 { font-size:1.45rem; font-weight:700; color:var(--text); margin:0 0 .2rem; }
.welcome-bar .date-line { font-size:.82rem; color:var(--muted); }

.stat-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:.85rem; margin-bottom:2rem; }
.stat-card { background:var(--surface); border:1px solid var(--teal-border); border-radius:var(--radius); overflow:hidden; transition:transform .25s, box-shadow .25s; }
.stat-card:hover { transform:translateY(-3px); box-shadow:0 8px 24px rgba(61,139,141,.15); }
.stat-accent { height:3px; }
.stat-body { display:flex; justify-content:space-between; align-items:center; padding:1.1rem; }
.stat-left .s-label { font-size:.7rem; text-transform:uppercase; letter-spacing:.08em; font-weight:700; color:var(--muted); margin-bottom:.25rem; }
.stat-left .s-val { font-size:1.65rem; font-weight:700; font-family:monospace; }
.stat-icon { font-size:1.6rem; opacity:.25; }
.stat-footer { display:block; padding:.45rem 1.1rem .65rem; font-size:.78rem; font-weight:600; text-decoration:none; border-top:1px solid var(--teal-border); color:var(--muted); transition:color .18s; }
.stat-footer:hover { color:var(--teal); }

.main-grid { display:grid; grid-template-columns:1fr 340px; gap:1.25rem; align-items:start; }
@media (max-width:900px){ .main-grid { grid-template-columns:1fr; } }

.panel { background:var(--surface); border:1px solid var(--teal-border); border-radius:var(--radius); overflow:hidden; margin-bottom:1.25rem; }
.panel-head { display:flex; justify-content:space-between; align-items:center; padding:.85rem 1.1rem; border-bottom:1px solid var(--teal-border); background:var(--teal-pale); }
.panel-head h3 { font-size:.92rem; font-weight:700; color:var(--teal-dark); margin:0; display:flex; align-items:center; gap:.4rem; }
.btn-view-all { font-size:.78rem; font-weight:600; color:var(--teal); text-decoration:none; padding:.25rem .65rem; border-radius:6px; border:1px solid var(--teal-border); transition:all .18s; }
.btn-view-all:hover { background:var(--teal); color:#fff; border-color:var(--teal); }

.table { width:100%; border-collapse:collapse; font-size:.85rem; }
.table thead th { background:var(--teal-dark); color:#fff; padding:.6rem .9rem; text-align:left; font-size:.7rem; text-transform:uppercase; letter-spacing:.07em; font-weight:700; white-space:nowrap; }
.table tbody tr { border-bottom:1px solid var(--teal-border); transition:background .15s; }
.table tbody tr:hover { background:var(--teal-pale); }
.table tbody td { padding:.7rem .9rem; vertical-align:middle; color:var(--text); }

.status-badge { display:inline-block; padding:.2rem .6rem; border-radius:20px; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }

.btn-take { display:inline-flex; align-items:center; gap:.3rem; padding:.3rem .75rem; border-radius:7px; border:none; background:var(--teal); color:#fff; font-size:.78rem; font-weight:700; text-decoration:none; transition:background .18s; white-space:nowrap; }
.btn-take:hover { background:var(--teal-dark); color:#fff; }
.btn-disabled { display:inline-block; padding:.3rem .75rem; border-radius:7px; border:1px solid var(--teal-border); background:transparent; color:var(--muted); font-size:.78rem; font-weight:600; cursor:not-allowed; white-space:nowrap; }

.quick-link { display:flex; align-items:center; gap:.75rem; padding:.75rem 1rem; border-bottom:1px solid var(--teal-border); color:var(--muted); text-decoration:none; font-size:.88rem; font-weight:500; transition:background .15s,color .15s; }
.quick-link:last-child { border-bottom:none; }
.quick-link:hover { background:var(--teal-pale); color:var(--teal-dark); }
.quick-link i { font-size:1rem; width:20px; text-align:center; }
</style>

<div class="dash-shell">

    <!-- Welcome bar -->
    <div class="welcome-bar">
        <h1>Welcome back, <?= $username ?></h1>
        <div class="date-line"><?= date('l, d F Y') ?> &mdash; Lecturer Dashboard</div>
    </div>

    <!-- Stat cards -->
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-accent" style="background:var(--teal)"></div>
            <div class="stat-body">
                <div class="stat-left">
                    <div class="s-label">Courses</div>
                    <div class="s-val"><?= $course_count ?></div>
                </div>
                <i class="bi bi-journal-bookmark stat-icon"></i>
            </div>
            <a href="manage_courses.php" class="stat-footer">View Courses <i class="bi bi-arrow-right"></i></a>
        </div>

        <div class="stat-card">
            <div class="stat-accent" style="background:var(--teal-dark)"></div>
            <div class="stat-body">
                <div class="stat-left">
                    <div class="s-label">Exams</div>
                    <div class="s-val"><?= $exam_count ?></div>
                </div>
                <i class="bi bi-file-earmark-text stat-icon"></i>
            </div>
            <a href="manage_exams.php" class="stat-footer">View Exams <i class="bi bi-arrow-right"></i></a>
        </div>

        <div class="stat-card">
            <div class="stat-accent" style="background:var(--teal-light)"></div>
            <div class="stat-body">
                <div class="stat-left">
                    <div class="s-label">Questions</div>
                    <div class="s-val"><?= $question_count ?></div>
                </div>
                <i class="bi bi-patch-question stat-icon"></i>
            </div>
            <a href="question_bank.php" class="stat-footer">View Questions <i class="bi bi-arrow-right"></i></a>
        </div>

        <div class="stat-card">
            <div class="stat-accent" style="background:#4a9a9c"></div>
            <div class="stat-body">
                <div class="stat-left">
                    <div class="s-label">Students Attempted</div>
                    <div class="s-val"><?= $student_attempts ?></div>
                </div>
                <i class="bi bi-people stat-icon"></i>
            </div>
            <a href="results.php" class="stat-footer">View Results <i class="bi bi-arrow-right"></i></a>
        </div>

        <div class="stat-card">
            <div class="stat-accent" style="background:#246060"></div>
            <div class="stat-body">
                <div class="stat-left">
                    <div class="s-label">Students Enrolled</div>
                    <div class="s-val"><?= $students_enrolled ?></div>
                </div>
                <i class="bi bi-person-check stat-icon"></i>
            </div>
            <a href="manage_courses.php" class="stat-footer">View All <i class="bi bi-arrow-right"></i></a>
        </div>
    </div>

    <!-- Main grid -->
    <div class="main-grid">

        <!-- Recent Exams -->
        <div class="panel">
            <div class="panel-head">
                <h3><i class="bi bi-clock-history" style="color:var(--teal)"></i> Recent Exams</h3>
                <a href="manage_exams.php" class="btn-view-all">View All</a>
            </div>
            <div style="overflow-x:auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Course</th>
                            <th>Exam</th>
                            <th>Date</th>
                            <th>Duration</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($recent_exams && $recent_exams->num_rows>0): ?>
                            <?php while($exam = $recent_exams->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($exam['course_name']) ?></td>
                                <td><?= htmlspecialchars($exam['title']) ?></td>
                                <td><?= date('d M Y', strtotime($exam['exam_date'])) ?></td>
                                <td><?= (int)$exam['duration'] ?> min</td>
                                <td>
                                    <?php if($exam['status']==='published'): ?>
                                        <span class="status-badge bg-teal-light text-white">Published</span>
                                    <?php else: ?>
                                        <span class="status-badge bg-teal-dark text-white">Draft</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="edit_exam.php?exam_id=<?= $exam['id'] ?>" class="btn btn-teal btn-sm">Edit</a>
                                    <a href="add_question.php?exam_id=<?= $exam['id'] ?>" class="btn btn-teal-light btn-sm">Questions</a>
                                    <a href="view_submission.php?exam_id=<?= $exam['id'] ?>" class="btn btn-sm" style="background-color:#4a9a9c;color:#fff;border-color:#4a9a9c;">Results</a>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No exams yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Student Enrollments -->
        <div class="panel">
            <div class="panel-head">
                <h3><i class="bi bi-person-lines-fill" style="color:var(--amber)"></i> Recent Student Enrollments</h3>
                <a href="manage_courses.php" class="btn-view-all">View All</a>
            </div>
            <div style="overflow-x:auto">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Student</th>
                            <th>Course</th>
                            <th>Enrolled Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($recent_students && $recent_students->num_rows>0): ?>
                            <?php while($s = $recent_students->fetch_assoc()): ?>
                            <tr>
                                <td><?= htmlspecialchars($s['name']) ?></td>
                                <td><?= htmlspecialchars($s['course_name']) ?></td>
                                <td><?= date('d M Y', strtotime($s['enrolled_at'])) ?></td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="3" class="text-center text-muted py-4">No students enrolled yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>

</div>

<?php include "../includes/footer.php"; ?>