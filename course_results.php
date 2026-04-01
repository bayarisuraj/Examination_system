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

$course_id = (int)($_GET['course_id'] ?? 0);
if (!$course_id) {
    header("Location: manage_courses.php");
    exit();
}

// ── Verify course belongs to this lecturer ────────────────────────
$stmt = $conn->prepare("
    SELECT id, course_name, course_code
    FROM courses
    WHERE id = ? AND lecturer_id = ?
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

// ── Fetch all exams for this course ───────────────────────────────
$stmt = $conn->prepare("
    SELECT
        e.id,
        e.title,
        e.exam_date,
        e.duration,
        e.status,
        (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.id) AS total_questions,
        (SELECT COUNT(*) FROM exam_attempts a WHERE a.exam_id = e.id AND a.status = 'completed') AS submission_count,
        (SELECT ROUND(AVG(a.score),1) FROM exam_attempts a WHERE a.exam_id = e.id AND a.status = 'completed') AS avg_score,
        (SELECT MAX(a.score) FROM exam_attempts a WHERE a.exam_id = e.id AND a.status = 'completed') AS highest,
        (SELECT MIN(a.score) FROM exam_attempts a WHERE a.exam_id = e.id AND a.status = 'completed') AS lowest
    FROM exams e
    WHERE e.course_id = ? AND e.created_by = ?
    ORDER BY e.exam_date ASC
");
$stmt->bind_param("ii", $course_id, $lecturer_id);
$stmt->execute();
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_exams = count($exams);
$exam_ids    = array_column($exams, 'id');

// ── Fetch enrolled students ───────────────────────────────────────
$stmt = $conn->prepare("
    SELECT s.id, s.name, s.email, s.student_number, s.program
    FROM course_enrollments ce
    JOIN students s ON s.id = ce.student_id
    WHERE ce.course_id = ?
    ORDER BY s.name ASC
");
$stmt->bind_param("i", $course_id);
$stmt->execute();
$students       = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$total_students = count($students);
$stmt->close();

// ── Fetch all completed attempts for this course ──────────────────
// Build a map: [student_id][exam_id] => attempt data
$score_map = [];
if ($exam_ids && $total_students) {
    $placeholders = implode(',', array_fill(0, count($exam_ids), '?'));
    $types        = str_repeat('i', count($exam_ids));
    $stmt = $conn->prepare("
        SELECT a.student_id, a.exam_id, a.id AS attempt_id,
               a.score, a.correct_answers, a.wrong_answers, a.skipped_answers
        FROM exam_attempts a
        WHERE a.exam_id IN ($placeholders)
          AND a.status = 'completed'
    ");
    $stmt->bind_param($types, ...$exam_ids);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $score_map[$row['student_id']][$row['exam_id']] = $row;
    }
    $stmt->close();
}

// ── Course-level aggregate stats ──────────────────────────────────
$total_submissions = 0;
$all_scores        = [];
$pass_count        = 0;

foreach ($exams as $ex) {
    $total_submissions += (int)$ex['submission_count'];
}
foreach ($score_map as $sid => $exmap) {
    foreach ($exmap as $eid => $att) {
        $sc = (float)$att['score'];
        $all_scores[] = $sc;
        if ($sc >= 50) $pass_count++;
    }
}

$course_avg  = count($all_scores) ? round(array_sum($all_scores) / count($all_scores), 1) : null;
$course_high = count($all_scores) ? max($all_scores) : null;
$course_low  = count($all_scores) ? min($all_scores) : null;
$pass_rate   = count($all_scores) ? round(($pass_count / count($all_scores)) * 100) : null;

// ── Grade helpers ─────────────────────────────────────────────────
function grade_label(float $s): string {
    if ($s >= 80) return 'A';
    if ($s >= 70) return 'B';
    if ($s >= 60) return 'C';
    if ($s >= 50) return 'D';
    return 'F';
}
function grade_color(float $s): string {
    if ($s >= 80) return '#3fb950';
    if ($s >= 70) return '#58a6ff';
    if ($s >= 60) return '#d29922';
    if ($s >= 50) return '#f59e0b';
    return '#f85149';
}

// Per-student average across all exams
$student_avgs = [];
foreach ($students as $st) {
    $sid    = $st['id'];
    $scores = [];
    foreach ($exam_ids as $eid) {
        if (isset($score_map[$sid][$eid])) {
            $scores[] = (float)$score_map[$sid][$eid]['score'];
        }
    }
    $student_avgs[$sid] = count($scores) ? round(array_sum($scores) / count($scores), 1) : null;
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
    --amber:   #d29922;
    --purple:  #bc8cff;
    --radius:  12px;
    --sans:    'DM Sans', sans-serif;
    --serif:   'Sora', sans-serif;
    --mono:    'DM Mono', monospace;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: var(--sans); min-height: 100vh; }
.page-shell { max-width: 1200px; margin: 0 auto; padding: 2.5rem 1.25rem 5rem; }

/* ── Header ── */
.page-header {
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: 1rem;
    flex-wrap: wrap; margin-bottom: 2rem;
}
.page-header h1 {
    font-family: var(--serif); font-size: 1.55rem;
    font-weight: 700; margin-bottom: .3rem; line-height: 1.25;
}
.page-header .meta { font-size: .83rem; color: var(--muted); display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
.btn-back {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .5rem 1rem; border-radius: 8px;
    border: 1px solid var(--border); background: var(--surface);
    color: var(--muted); font-size: .84rem; font-weight: 600;
    text-decoration: none; transition: border-color .2s, color .2s; white-space: nowrap;
}
.btn-back:hover { border-color: var(--accent); color: var(--accent); }

/* ── Stat cards ── */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: .85rem; margin-bottom: 2rem;
}
.stat-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 1rem 1.1rem;
    display: flex; flex-direction: column; gap: .3rem;
}
.s-label { font-size: .71rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 700; color: var(--muted); }
.s-val   { font-family: var(--mono); font-size: 1.45rem; font-weight: 600; }
.sc-exams  .s-val { color: var(--accent); }
.sc-stud   .s-val { color: var(--purple); }
.sc-sub    .s-val { color: var(--amber); }
.sc-avg    .s-val { color: var(--accent); }
.sc-high   .s-val { color: var(--green); }
.sc-pass   .s-val { color: var(--green); }

/* ── Section title ── */
.section-title {
    font-family: var(--serif); font-size: 1rem; font-weight: 700;
    color: var(--text); margin-bottom: 1rem;
    display: flex; align-items: center; gap: .5rem;
}

/* ── Exam cards grid ── */
.exam-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: .85rem; margin-bottom: 2.5rem;
}
.exam-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 1.1rem 1.2rem;
    display: flex; flex-direction: column; gap: .5rem;
    transition: border-color .18s;
}
.exam-card:hover { border-color: var(--accent); }
.exam-card-title {
    font-family: var(--serif); font-size: .95rem; font-weight: 700;
    color: var(--text); margin-bottom: .1rem;
}
.exam-card-meta { font-size: .77rem; color: var(--muted); display: flex; gap: .5rem; flex-wrap: wrap; }
.exam-card-stats {
    display: flex; gap: .5rem; flex-wrap: wrap;
    margin-top: .25rem;
}
.mini-pill {
    display: inline-flex; align-items: center; gap: .25rem;
    padding: .18rem .55rem; border-radius: 20px;
    font-size: .73rem; font-weight: 700; font-family: var(--mono);
    background: var(--surface2); border: 1px solid var(--border); color: var(--muted);
}
.pill-green { background: #0d2818; color: var(--green); border-color: #238636; }
.pill-amber { background: #1c1a0a; color: var(--amber); border-color: #6e5208; }
.pill-red   { background: #2a0e0e; color: var(--red);   border-color: #6e2323; }

.exam-avg-bar {
    height: 5px; background: var(--border); border-radius: 3px;
    overflow: hidden; margin-top: .3rem;
}
.exam-avg-fill { height: 100%; border-radius: 3px; transition: width .8s ease; }

.btn-view-exam {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .3rem .75rem; border-radius: 7px;
    border: 1px solid var(--border); background: transparent;
    color: var(--muted); font-size: .78rem; font-weight: 600;
    text-decoration: none; transition: all .18s; margin-top: .25rem; align-self: flex-start;
}
.btn-view-exam:hover { border-color: var(--accent); color: var(--accent); }

.no-submissions { font-size: .8rem; color: var(--muted); font-style: italic; }

/* ── Matrix table ── */
.matrix-wrap {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); overflow: hidden; overflow-x: auto;
    margin-bottom: 2rem;
}
.matrix-table { width: 100%; border-collapse: collapse; font-size: .82rem; }

.matrix-table thead th {
    background: var(--surface2); padding: .65rem .85rem; text-align: center;
    font-size: .7rem; text-transform: uppercase; letter-spacing: .06em;
    font-weight: 700; color: var(--muted);
    border-bottom: 1px solid var(--border); border-right: 1px solid var(--border);
    white-space: nowrap; min-width: 90px;
}
.matrix-table thead th.th-student {
    text-align: left; min-width: 180px; position: sticky; left: 0;
    background: var(--surface2); z-index: 2;
}
.matrix-table thead th.th-avg { background: #131c2b; color: var(--accent); }

.matrix-table tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
.matrix-table tbody tr:last-child { border-bottom: none; }
.matrix-table tbody tr:hover { background: var(--surface2); }

.matrix-table tbody td {
    padding: .6rem .85rem; text-align: center; vertical-align: middle;
    border-right: 1px solid var(--border);
}
.matrix-table tbody td.td-student {
    text-align: left; position: sticky; left: 0;
    background: var(--surface); z-index: 1;
    border-right: 1px solid var(--border);
}
.matrix-table tbody tr:hover td.td-student { background: var(--surface2); }

.td-student-name { font-size: .85rem; font-weight: 600; color: var(--text); display: block; }
.td-student-meta { font-size: .73rem; color: var(--muted); font-family: var(--mono); }

.score-cell-inner {
    display: flex; flex-direction: column;
    align-items: center; gap: .15rem;
}
.score-val {
    font-family: var(--mono); font-size: .82rem; font-weight: 700;
}
.score-grade {
    font-family: var(--mono); font-size: .65rem; font-weight: 700;
    padding: .1rem .35rem; border-radius: 10px;
    background: var(--surface2);
}
.score-mini-bar {
    width: 48px; height: 3px; background: var(--border);
    border-radius: 2px; overflow: hidden;
}
.score-mini-fill { height: 100%; border-radius: 2px; }

.cell-missed {
    font-family: var(--mono); font-size: .75rem; color: var(--border);
}

.td-avg { background: #0c1521; }
.matrix-table tbody tr:hover td.td-avg { background: #131c2b; }
.avg-val { font-family: var(--mono); font-size: .88rem; font-weight: 700; }

/* ── Toolbar ── */
.toolbar {
    display: flex; align-items: center; gap: .75rem;
    flex-wrap: wrap; margin-bottom: 1.1rem;
}
.search-box { position: relative; flex: 1; min-width: 180px; max-width: 280px; }
.search-box i {
    position: absolute; left: .7rem; top: 50%;
    transform: translateY(-50%); color: var(--muted); font-size: .82rem; pointer-events: none;
}
.search-box input {
    width: 100%; background: var(--surface); border: 1px solid var(--border);
    border-radius: 8px; padding: .48rem .8rem .48rem 2rem;
    color: var(--text); font-size: .83rem; font-family: var(--sans);
    outline: none; transition: border-color .2s;
}
.search-box input::placeholder { color: var(--muted); }
.search-box input:focus { border-color: var(--accent); }
.result-count { font-size: .82rem; color: var(--muted); }

.btn-export {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .45rem 1rem; border-radius: 8px;
    border: 1px solid var(--green); background: transparent;
    color: var(--green); font-size: .83rem; font-weight: 600;
    cursor: pointer; transition: all .18s;
}
.btn-export:hover { background: var(--green); color: #0d1117; }

/* ── Empty state ── */
.empty-state {
    text-align: center; padding: 3rem 2rem; color: var(--muted);
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius);
}
.empty-state i { font-size: 2.2rem; display: block; margin-bottom: .75rem; color: var(--border); }
.empty-state h6 { color: var(--text); font-family: var(--serif); margin-bottom: .4rem; }

@media (max-width: 640px) {
    .page-shell { padding: 1.25rem .75rem 4rem; }
    .page-header h1 { font-size: 1.25rem; }
}
</style>

<div class="page-shell">

    <!-- Page header -->
    <div class="page-header">
        <div>
            <h1>
                <i class="bi bi-bar-chart-fill" style="color:var(--accent);margin-right:.4rem"></i>
                Course Results
            </h1>
            <div class="meta">
                <i class="bi bi-book"></i> <?= htmlspecialchars($course['course_name']) ?>
                &bull;
                <i class="bi bi-tag"></i> <?= htmlspecialchars($course['course_code']) ?>
            </div>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
            <a href="enrolled_students.php?course_id=<?= $course_id ?>" class="btn-back">
                <i class="bi bi-people"></i> Students
            </a>
            <a href="manage_courses.php" class="btn-back">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="stat-grid">
        <div class="stat-card sc-exams">
            <span class="s-label">Total Exams</span>
            <span class="s-val"><?= $total_exams ?></span>
        </div>
        <div class="stat-card sc-stud">
            <span class="s-label">Enrolled Students</span>
            <span class="s-val"><?= $total_students ?></span>
        </div>
        <div class="stat-card sc-sub">
            <span class="s-label">Total Submissions</span>
            <span class="s-val"><?= $total_submissions ?></span>
        </div>
        <div class="stat-card sc-avg">
            <span class="s-label">Course Average</span>
            <span class="s-val"><?= $course_avg !== null ? $course_avg . '%' : '—' ?></span>
        </div>
        <div class="stat-card sc-high">
            <span class="s-label">Highest Score</span>
            <span class="s-val"><?= $course_high !== null ? $course_high . '%' : '—' ?></span>
        </div>
        <div class="stat-card sc-pass">
            <span class="s-label">Pass Rate</span>
            <span class="s-val"><?= $pass_rate !== null ? $pass_rate . '%' : '—' ?></span>
        </div>
    </div>

    <!-- Exams section -->
    <div class="section-title">
        <i class="bi bi-journal-text" style="color:var(--accent)"></i>
        Exams (<?= $total_exams ?>)
    </div>

    <?php if ($total_exams > 0): ?>
    <div class="exam-grid" id="examGrid">
        <?php foreach ($exams as $ex):
            $sub   = (int)$ex['submission_count'];
            $avg   = $ex['avg_score'] !== null ? (float)$ex['avg_score'] : null;
            $high  = $ex['highest']   !== null ? (float)$ex['highest']   : null;
            $low   = $ex['lowest']    !== null ? (float)$ex['lowest']    : null;
            $gc    = $avg !== null ? grade_color($avg) : '#8b949e';
            $prate = $sub > 0 ? round((array_filter(
                array_map(fn($s) => isset($score_map[$s['id']][$ex['id']]) ? (float)$score_map[$s['id']][$ex['id']]['score'] : null, $students),
                fn($sc) => $sc !== null && $sc >= 50
            ) ? count(array_filter(
                array_map(fn($s) => isset($score_map[$s['id']][$ex['id']]) ? (float)$score_map[$s['id']][$ex['id']]['score'] : null, $students),
                fn($sc) => $sc !== null && $sc >= 50
            )) : 0) / $sub * 100) : 0;
        ?>
        <div class="exam-card">
            <div>
                <div class="exam-card-title"><?= htmlspecialchars($ex['title']) ?></div>
                <div class="exam-card-meta">
                    <span><i class="bi bi-calendar3"></i> <?= date('d M Y', strtotime($ex['exam_date'])) ?></span>
                    <span><i class="bi bi-stopwatch"></i> <?= (int)$ex['duration'] ?>min</span>
                    <span><i class="bi bi-question-circle"></i> <?= (int)$ex['total_questions'] ?>q</span>
                </div>
            </div>

            <?php if ($sub > 0): ?>
            <div class="exam-avg-bar">
                <div class="exam-avg-fill"
                     style="width:0%;background:<?= $gc ?>"
                     data-width="<?= $avg ?>%">
                </div>
            </div>
            <div class="exam-card-stats">
                <span class="mini-pill"><?= $sub ?> submitted</span>
                <?php if ($avg !== null): ?>
                <span class="mini-pill <?= $avg >= 50 ? 'pill-green' : 'pill-red' ?>">
                    avg <?= $avg ?>%
                </span>
                <?php endif; ?>
                <?php if ($high !== null): ?>
                <span class="mini-pill pill-green">↑<?= $high ?>%</span>
                <?php endif; ?>
                <?php if ($low !== null): ?>
                <span class="mini-pill pill-red">↓<?= $low ?>%</span>
                <?php endif; ?>
                <span class="mini-pill pill-amber"><?= $prate ?>% pass</span>
            </div>
            <?php else: ?>
            <p class="no-submissions">No submissions yet</p>
            <?php endif; ?>

            <a href="view_submission.php?exam_id=<?= (int)$ex['id'] ?>" class="btn-view-exam">
                <i class="bi bi-eye"></i> View Submissions
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div class="empty-state" style="margin-bottom:2.5rem">
        <i class="bi bi-journal-x"></i>
        <h6>No Exams Created Yet</h6>
        <p style="font-size:.83rem">Exams you create for this course will appear here.</p>
    </div>
    <?php endif; ?>

    <!-- Score matrix -->
    <div class="section-title">
        <i class="bi bi-grid-3x3-gap" style="color:var(--accent)"></i>
        Student × Exam Score Matrix
    </div>

    <?php if ($total_students > 0 && $total_exams > 0): ?>

    <!-- Matrix toolbar -->
    <div class="toolbar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="matrixSearch" placeholder="Search student…" autocomplete="off">
        </div>
        <span class="result-count" id="matrixCount"><?= $total_students ?> student<?= $total_students !== 1 ? 's' : '' ?></span>
        <button class="btn-export" onclick="exportMatrix()">
            <i class="bi bi-download"></i> Export CSV
        </button>
    </div>

    <div class="matrix-wrap">
        <table class="matrix-table" id="matrixTable">
            <thead>
                <tr>
                    <th class="th-student">Student</th>
                    <?php foreach ($exams as $ex): ?>
                    <th title="<?= htmlspecialchars($ex['title']) ?> — <?= date('d M Y', strtotime($ex['exam_date'])) ?>">
                        <?= htmlspecialchars(mb_strimwidth($ex['title'], 0, 14, '…')) ?>
                        <div style="font-size:.63rem;font-weight:400;color:var(--border);margin-top:.15rem">
                            <?= date('d M', strtotime($ex['exam_date'])) ?>
                        </div>
                    </th>
                    <?php endforeach; ?>
                    <th class="th-avg">Avg</th>
                </tr>
            </thead>
            <tbody id="matrixBody">
                <?php foreach ($students as $st):
                    $sid  = $st['id'];
                    $savg = $student_avgs[$sid];
                    $sgc  = $savg !== null ? grade_color($savg) : '#8b949e';
                ?>
                <tr data-name="<?= strtolower(htmlspecialchars($st['name'])) ?>">
                    <td class="td-student">
                        <span class="td-student-name"><?= htmlspecialchars($st['name']) ?></span>
                        <span class="td-student-meta"><?= htmlspecialchars($st['student_number'] ?? '—') ?></span>
                    </td>

                    <?php foreach ($exams as $ex):
                        $eid = $ex['id'];
                        if (isset($score_map[$sid][$eid])):
                            $att = $score_map[$sid][$eid];
                            $sc  = (float)$att['score'];
                            $gc  = grade_color($sc);
                            $gl  = grade_label($sc);
                    ?>
                    <td>
                        <a href="student_result_detail.php?attempt_id=<?= (int)$att['attempt_id'] ?>"
                           style="text-decoration:none">
                            <div class="score-cell-inner">
                                <span class="score-val" style="color:<?= $gc ?>"><?= number_format($sc,1) ?>%</span>
                                <span class="score-grade" style="color:<?= $gc ?>;border:1px solid <?= $gc ?>22"><?= $gl ?></span>
                                <div class="score-mini-bar">
                                    <div class="score-mini-fill" style="width:<?= $sc ?>%;background:<?= $gc ?>"></div>
                                </div>
                            </div>
                        </a>
                    </td>
                    <?php else: ?>
                    <td><span class="cell-missed">—</span></td>
                    <?php endif; ?>
                    <?php endforeach; ?>

                    <td class="td-avg">
                        <?php if ($savg !== null): ?>
                        <span class="avg-val" style="color:<?= $sgc ?>"><?= $savg ?>%</span>
                        <?php else: ?>
                        <span class="cell-missed">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
    <div class="empty-state">
        <i class="bi bi-grid-3x3-gap"></i>
        <h6>Matrix Unavailable</h6>
        <p style="font-size:.83rem">Enroll students and create exams to see the score matrix.</p>
    </div>
    <?php endif; ?>

</div><!-- /page-shell -->

<script>
// ── Animate exam avg bars ─────────────────────────────────────────
setTimeout(() => {
    document.querySelectorAll('.exam-avg-fill').forEach(bar => {
        bar.style.transition = 'width .8s ease';
        bar.style.width = bar.dataset.width;
    });
}, 300);

// ── Matrix search ─────────────────────────────────────────────────
const matrixSearch = document.getElementById('matrixSearch');
const matrixCount  = document.getElementById('matrixCount');
if (matrixSearch) {
    matrixSearch.addEventListener('input', function () {
        const q = this.value.trim().toLowerCase();
        let visible = 0;
        document.querySelectorAll('#matrixBody tr').forEach(row => {
            const show = !q || row.dataset.name.includes(q);
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        matrixCount.textContent = `${visible} student${visible !== 1 ? 's' : ''}`;
    });
}

// ── Export matrix as CSV ──────────────────────────────────────────
function exportMatrix() {
    const examTitles = <?= json_encode(array_column($exams, 'title')) ?>;
    const rows = [];
    const header = ['Student', 'Student ID', ...examTitles, 'Average'];
    rows.push(header);

    document.querySelectorAll('#matrixBody tr').forEach(row => {
        if (row.style.display === 'none') return;
        const name   = row.querySelector('.td-student-name')?.textContent.trim() ?? '';
        const sid    = row.querySelector('.td-student-meta')?.textContent.trim() ?? '';
        const cells  = Array.from(row.querySelectorAll('td')).slice(1);
        const scores = cells.slice(0, -1).map(td => {
            const val = td.querySelector('.score-val');
            return val ? val.textContent.trim().replace('%','') : '';
        });
        const avg = cells.at(-1)?.querySelector('.avg-val')?.textContent.trim().replace('%','') ?? '';
        rows.push([name, sid, ...scores, avg]);
    });

    const csv  = rows.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `<?= preg_replace('/[^a-z0-9]+/i', '_', $course['course_name']) ?>_results.csv`;
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<?php include "../includes/footer.php"; ?>