<?php
session_start();
require_once "../config/db.php";

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

$student_id = (int)($_GET['student_id'] ?? 0);
$course_id  = (int)($_GET['course_id']  ?? 0);

if (!$student_id || !$course_id) {
    header("Location: manage_courses.php");
    exit();
}

// ── Verify course belongs to this lecturer ────────────────────────
$stmt = $conn->prepare("SELECT id, course_name, course_code FROM courses WHERE id = ? AND lecturer_id = ? LIMIT 1");
$stmt->bind_param("ii", $course_id, $lecturer_id);
$stmt->execute();
$course = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$course) {
    header("Location: manage_courses.php");
    exit();
}

// ── Fetch student info from users table ───────────────────────────
$stmt = $conn->prepare("
    SELECT u.id, u.name, u.email, ce.enrolled_at
    FROM users u
    JOIN course_enrollments ce ON ce.student_id = u.id
    WHERE u.id = ? AND ce.course_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $student_id, $course_id);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    header("Location: manage_courses.php");
    exit();
}

// ── Fetch all exams in course + this student's attempt ────────────
// Uses lecturer_id (not created_by)
$stmt = $conn->prepare("
    SELECT
        e.id           AS exam_id,
        e.title,
        e.exam_date,
        e.duration,
        e.status       AS exam_status,
        (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.id) AS total_questions,
        a.id           AS attempt_id,
        a.score,
        a.correct_answers,
        a.wrong_answers,
        a.skipped_answers,
        a.status       AS attempt_status,
        a.end_time     AS submitted_at
    FROM exams e
    LEFT JOIN exam_attempts a
           ON a.exam_id    = e.id
          AND a.student_id = ?
          AND a.status     = 'completed'
    WHERE e.course_id  = ?
      AND e.lecturer_id = ?
    ORDER BY e.exam_date ASC
");
$stmt->bind_param("iii", $student_id, $course_id, $lecturer_id);
$stmt->execute();
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Class averages per exam ───────────────────────────────────────
$exam_ids   = array_column($exams, 'exam_id');
$class_avgs = [];
if ($exam_ids) {
    $placeholders = implode(',', array_fill(0, count($exam_ids), '?'));
    $types        = str_repeat('i', count($exam_ids));
    $stmt = $conn->prepare("
        SELECT exam_id, ROUND(AVG(score),1) AS avg_score
        FROM exam_attempts
        WHERE exam_id IN ($placeholders) AND status = 'completed'
        GROUP BY exam_id
    ");
    $stmt->bind_param($types, ...$exam_ids);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) {
        $class_avgs[$row['exam_id']] = (float)$row['avg_score'];
    }
    $stmt->close();
}

// ── Aggregate stats ───────────────────────────────────────────────
$total_exams = count($exams);
$attempted   = 0;
$score_sum   = 0;
$best_score  = null;
$worst_score = null;
$pass_count  = 0;

foreach ($exams as $ex) {
    if ($ex['attempt_id']) {
        $attempted++;
        $sc = (float)$ex['score'];
        $score_sum += $sc;
        if ($best_score  === null || $sc > $best_score)  $best_score  = $sc;
        if ($worst_score === null || $sc < $worst_score) $worst_score = $sc;
        if ($sc >= 50) $pass_count++;
    }
}

$avg_score      = $attempted ? round($score_sum / $attempted, 1) : null;
$completion_pct = $total_exams ? round(($attempted / $total_exams) * 100) : 0;

function grade_label(float $s): string {
    if ($s >= 80) return 'A'; if ($s >= 70) return 'B';
    if ($s >= 60) return 'C'; if ($s >= 50) return 'D'; return 'F';
}
function grade_color(float $s): string {
    if ($s >= 80) return '#3fb950'; if ($s >= 70) return '#58a6ff';
    if ($s >= 60) return '#d29922'; if ($s >= 50) return '#f59e0b'; return '#f85149';
}

$overall_grade       = $avg_score !== null ? grade_label($avg_score) : '—';
$overall_grade_color = $avg_score !== null ? grade_color($avg_score) : '#8b949e';

include "../includes/header.php";
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap');
:root{--bg:#0d1117;--surface:#161b22;--surface2:#1c2330;--border:#30363d;--text:#e6edf3;--muted:#8b949e;--accent:#58a6ff;--green:#3fb950;--red:#f85149;--amber:#d29922;--purple:#bc8cff;--radius:12px;--sans:'DM Sans',sans-serif;--serif:'Sora',sans-serif;--mono:'DM Mono',monospace;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;}
.page-shell{max-width:1100px;margin:0 auto;padding:2.5rem 1.25rem 5rem;}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:2rem;}
.page-header h1{font-family:var(--serif);font-size:1.55rem;font-weight:700;color:var(--text);margin-bottom:.3rem;line-height:1.25;}
.page-header .meta{font-size:.83rem;color:var(--muted);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;}
.btn-back{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--muted);font-size:.84rem;font-weight:600;text-decoration:none;transition:border-color .2s,color .2s;white-space:nowrap;}
.btn-back:hover{border-color:var(--accent);color:var(--accent);}
.profile-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.4rem 1.6rem;margin-bottom:1.75rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;}
.profile-left{display:flex;align-items:center;gap:1rem;}
.avatar{width:52px;height:52px;border-radius:50%;background:#131c2b;border:2px solid var(--accent);display:flex;align-items:center;justify-content:center;font-family:var(--serif);font-size:1.2rem;font-weight:700;color:var(--accent);flex-shrink:0;}
.profile-name{font-family:var(--serif);font-size:1.1rem;font-weight:700;color:var(--text);margin-bottom:.2rem;}
.profile-meta{font-size:.8rem;color:var(--muted);display:flex;gap:.75rem;flex-wrap:wrap;}
.overall-grade{font-family:var(--mono);font-size:2rem;font-weight:700;padding:.3rem .9rem;border-radius:10px;border:2px solid;background:transparent;}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.85rem;margin-bottom:2rem;}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem 1.1rem;display:flex;flex-direction:column;gap:.3rem;}
.stat-card .s-label{font-size:.71rem;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:var(--muted);}
.stat-card .s-val{font-family:var(--mono);font-size:1.45rem;font-weight:600;}
.sc-total .s-val{color:var(--accent);}.sc-done .s-val{color:var(--purple);}.sc-avg .s-val{color:var(--amber);}.sc-best .s-val{color:var(--green);}.sc-worst .s-val{color:var(--red);}.sc-pass .s-val{color:var(--green);}
.completion-bar-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.1rem 1.4rem;margin-bottom:2rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;}
.completion-label{font-size:.83rem;color:var(--muted);white-space:nowrap;}
.bar-track{flex:1;min-width:120px;height:8px;background:var(--border);border-radius:4px;overflow:hidden;}
.bar-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,var(--accent),var(--purple));transition:width 1s cubic-bezier(.4,0,.2,1);width:0%;}
.completion-pct{font-family:var(--mono);font-size:.9rem;font-weight:600;color:var(--accent);white-space:nowrap;}
.section-title{font-family:var(--serif);font-size:1rem;font-weight:700;color:var(--text);margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;}
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;overflow-x:auto;margin-bottom:2rem;}
table{width:100%;border-collapse:collapse;font-size:.87rem;}
thead th{background:var(--surface2);padding:.7rem 1rem;text-align:left;font-size:.71rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap;}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:var(--surface2);}
tbody td{padding:.8rem 1rem;vertical-align:middle;}
.td-exam strong{display:block;color:var(--text);font-size:.88rem;}.td-exam span{font-size:.76rem;color:var(--muted);font-family:var(--mono);}
.status-pill{display:inline-flex;align-items:center;gap:.3rem;padding:.22rem .65rem;border-radius:20px;font-size:.74rem;font-weight:700;}
.pill-passed{background:#0d2818;color:var(--green);border:1px solid #238636;}.pill-failed{background:#2a0e0e;color:var(--red);border:1px solid #6e2323;}.pill-missed{background:var(--surface2);color:var(--muted);border:1px solid var(--border);}.pill-pending{background:#1c1a0a;color:var(--amber);border:1px solid #6e5208;}
.score-cell{display:flex;align-items:center;gap:.6rem;}.score-bar-bg{flex:1;height:6px;background:var(--border);border-radius:3px;overflow:hidden;min-width:50px;}.score-bar-fill{height:100%;border-radius:3px;}.score-num{font-family:var(--mono);font-size:.84rem;font-weight:600;min-width:44px;text-align:right;}
.vs-avg{font-family:var(--mono);font-size:.76rem;color:var(--muted);white-space:nowrap;}.vs-up{color:var(--green);}.vs-down{color:var(--red);}
.grade-badge{display:inline-block;padding:.2rem .55rem;border-radius:20px;font-family:var(--mono);font-size:.74rem;font-weight:700;background:var(--surface2);}
.mini-breakdown{display:flex;gap:.45rem;font-size:.76rem;font-family:var(--mono);color:var(--muted);}.mc{color:var(--green);}.mw{color:var(--red);}.ms{color:var(--muted);}
.td-date{font-family:var(--mono);font-size:.77rem;color:var(--muted);white-space:nowrap;}
.btn-detail{display:inline-flex;align-items:center;gap:.3rem;padding:.28rem .7rem;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:.77rem;font-weight:600;text-decoration:none;transition:all .18s;white-space:nowrap;}
.btn-detail:hover{border-color:var(--accent);color:var(--accent);}
.chart-section{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem;margin-bottom:2rem;}
.chart-section h3{font-family:var(--serif);font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:1.1rem;}
.trend-chart{display:flex;align-items:flex-end;gap:.5rem;height:100px;padding-bottom:.25rem;}
.trend-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:.3rem;height:100%;justify-content:flex-end;min-width:0;}
.trend-bar-wrap{width:100%;display:flex;align-items:flex-end;justify-content:center;flex:1;}
.trend-bar{width:70%;border-radius:4px 4px 0 0;min-height:4px;}
.trend-score{font-family:var(--mono);font-size:.7rem;color:var(--text);}
.trend-label{font-size:.65rem;color:var(--muted);text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;padding:0 2px;}
.avg-line-label{font-size:.72rem;color:var(--muted);text-align:right;margin-top:.4rem;font-family:var(--mono);}
.empty-state{text-align:center;padding:3rem 2rem;color:var(--muted);}
.empty-state i{font-size:2.2rem;display:block;margin-bottom:.75rem;color:var(--border);}
.empty-state h6{color:var(--text);font-family:var(--serif);margin-bottom:.4rem;}
@media(max-width:600px){.page-shell{padding:1.25rem .75rem 4rem;}.page-header h1{font-size:1.25rem;}.overall-grade{font-size:1.5rem;}}
</style>

<div class="page-shell">

    <div class="page-header">
        <div>
            <h1><i class="bi bi-person-lines-fill" style="color:var(--accent);margin-right:.4rem"></i>Student Progress</h1>
            <div class="meta">
                <i class="bi bi-book"></i> <?= htmlspecialchars($course['course_name']) ?> (<?= htmlspecialchars($course['course_code']) ?>)
            </div>
        </div>
        <a href="manage_courses.php" class="btn-back"><i class="bi bi-arrow-left"></i> Back to Courses</a>
    </div>

    <!-- Student profile -->
    <?php
        $words    = array_slice(explode(' ', trim($student['name'])), 0, 2);
        $initials = implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), $words));
    ?>
    <div class="profile-card">
        <div class="profile-left">
            <div class="avatar"><?= htmlspecialchars($initials) ?></div>
            <div>
                <div class="profile-name"><?= htmlspecialchars($student['name']) ?></div>
                <div class="profile-meta">
                    <span><i class="bi bi-envelope"></i> <?= htmlspecialchars($student['email']) ?></span>
                    <span><i class="bi bi-calendar-check"></i> Enrolled <?= date('d M Y', strtotime($student['enrolled_at'])) ?></span>
                </div>
            </div>
        </div>
        <div class="overall-grade" style="color:<?= $overall_grade_color ?>;border-color:<?= $overall_grade_color ?>20">
            <?= $overall_grade ?>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card sc-total"><span class="s-label">Total Exams</span><span class="s-val"><?= $total_exams ?></span></div>
        <div class="stat-card sc-done"><span class="s-label">Attempted</span><span class="s-val"><?= $attempted ?></span></div>
        <div class="stat-card sc-avg"><span class="s-label">Average Score</span><span class="s-val"><?= $avg_score !== null ? $avg_score . '%' : '—' ?></span></div>
        <div class="stat-card sc-best"><span class="s-label">Best Score</span><span class="s-val"><?= $best_score !== null ? $best_score . '%' : '—' ?></span></div>
        <div class="stat-card sc-worst"><span class="s-label">Lowest Score</span><span class="s-val"><?= $worst_score !== null ? $worst_score . '%' : '—' ?></span></div>
        <div class="stat-card sc-pass"><span class="s-label">Passed</span><span class="s-val"><?= $pass_count ?> / <?= $attempted ?></span></div>
    </div>

    <div class="completion-bar-wrap">
        <span class="completion-label"><i class="bi bi-check2-circle" style="color:var(--accent)"></i> Exam Completion</span>
        <div class="bar-track"><div class="bar-fill" id="completionBar" data-pct="<?= $completion_pct ?>"></div></div>
        <span class="completion-pct"><?= $completion_pct ?>%</span>
        <span style="font-size:.78rem;color:var(--muted)"><?= $attempted ?> of <?= $total_exams ?> exams taken</span>
    </div>

    <!-- Score trend chart -->
    <?php
    $attempted_exams = array_filter($exams, fn($e) => $e['attempt_id'] !== null);
    if (count($attempted_exams) > 0):
    ?>
    <div class="chart-section">
        <h3><i class="bi bi-graph-up" style="color:var(--accent);margin-right:.4rem"></i>Score Trend</h3>
        <div class="trend-chart">
            <?php foreach ($attempted_exams as $ex):
                $sc    = (float)$ex['score'];
                $gc    = grade_color($sc);
                $pct   = round($sc);
                $short = mb_strimwidth($ex['title'], 0, 12, '…');
            ?>
            <div class="trend-col">
                <div class="trend-bar-wrap">
                    <div class="trend-bar" style="height:0%;background:<?= $gc ?>;opacity:.75" data-height="<?= $pct ?>%"></div>
                </div>
                <span class="trend-score"><?= number_format($sc, 0) ?>%</span>
                <span class="trend-label" title="<?= htmlspecialchars($ex['title']) ?>"><?= htmlspecialchars($short) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if ($avg_score !== null): ?>
        <div class="avg-line-label">
            Student avg: <?= $avg_score ?>%
            <?php if ($avg_score >= 50): ?>
                <span style="color:var(--green);margin-left:.5rem"><i class="bi bi-arrow-up-short"></i>Passing</span>
            <?php else: ?>
                <span style="color:var(--red);margin-left:.5rem"><i class="bi bi-arrow-down-short"></i>Below pass</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="section-title"><i class="bi bi-journal-text" style="color:var(--accent)"></i> Exam-by-Exam Breakdown</div>

    <?php if ($total_exams > 0): ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>#</th><th>Exam</th><th>Status</th><th>Score</th><th>Grade</th><th>vs Class Avg</th><th>Breakdown</th><th>Submitted</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($exams as $i => $ex):
                    $attempted_this = $ex['attempt_id'] !== null;
                    $sc    = $attempted_this ? (float)$ex['score'] : null;
                    $gc    = $attempted_this ? grade_color($sc) : '#8b949e';
                    $grade = $attempted_this ? grade_label($sc)  : '—';
                    $cavg  = $class_avgs[$ex['exam_id']] ?? null;

                    if (!$attempted_this) {
                        $exam_dt  = strtotime($ex['exam_date']);
                        if ($ex['exam_status'] === 'published' && $exam_dt > time()) {
                            $pill_cls = 'pill-pending'; $pill_lbl = 'Upcoming'; $pill_icon = 'bi-clock';
                        } else {
                            $pill_cls = 'pill-missed';  $pill_lbl = 'Not Taken'; $pill_icon = 'bi-dash-circle';
                        }
                    } elseif ($sc >= 50) {
                        $pill_cls = 'pill-passed'; $pill_lbl = 'Passed'; $pill_icon = 'bi-check-circle';
                    } else {
                        $pill_cls = 'pill-failed'; $pill_lbl = 'Failed'; $pill_icon = 'bi-x-circle';
                    }
                ?>
                <tr>
                    <td style="font-family:var(--mono);font-size:.77rem;color:var(--muted);width:36px"><?= $i+1 ?></td>
                    <td class="td-exam">
                        <strong><?= htmlspecialchars($ex['title']) ?></strong>
                        <span><i class="bi bi-calendar3"></i> <?= date('d M Y', strtotime($ex['exam_date'])) ?> &bull; <i class="bi bi-stopwatch"></i> <?= (int)$ex['duration'] ?>min &bull; <i class="bi bi-question-circle"></i> <?= (int)$ex['total_questions'] ?>q</span>
                    </td>
                    <td><span class="status-pill <?= $pill_cls ?>"><i class="bi <?= $pill_icon ?>"></i> <?= $pill_lbl ?></span></td>
                    <td>
                        <?php if ($attempted_this): ?>
                        <div class="score-cell">
                            <div class="score-bar-bg"><div class="score-bar-fill" style="width:<?= $sc ?>%;background:<?= $gc ?>"></div></div>
                            <span class="score-num" style="color:<?= $gc ?>"><?= number_format($sc,1) ?>%</span>
                        </div>
                        <?php else: ?><span style="color:var(--muted);font-family:var(--mono);font-size:.82rem">—</span><?php endif; ?>
                    </td>
                    <td><span class="grade-badge" style="color:<?= $gc ?>;border:1px solid <?= $gc ?>22"><?= $grade ?></span></td>
                    <td class="vs-avg">
                        <?php if ($attempted_this && $cavg !== null):
                            $diff = round($sc - $cavg, 1);
                            $cls  = $diff >= 0 ? 'vs-up' : 'vs-down';
                            $icon = $diff >= 0 ? 'bi-arrow-up-short' : 'bi-arrow-down-short';
                        ?>
                        <span class="<?= $cls ?>"><i class="bi <?= $icon ?>"></i><?= $diff >= 0 ? '+' : '' ?><?= $diff ?>%</span>
                        <span style="font-size:.7rem;color:var(--muted);display:block">avg <?= $cavg ?>%</span>
                        <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($attempted_this): ?>
                        <div class="mini-breakdown">
                            <span class="mc"><i class="bi bi-check-lg"></i><?= (int)$ex['correct_answers'] ?></span>
                            <span class="mw"><i class="bi bi-x-lg"></i><?= (int)$ex['wrong_answers'] ?></span>
                            <span class="ms"><i class="bi bi-dash"></i><?= (int)$ex['skipped_answers'] ?></span>
                        </div>
                        <?php else: ?><span style="color:var(--muted);font-size:.8rem">—</span><?php endif; ?>
                    </td>
                    <td class="td-date"><?= $attempted_this ? date('d M Y', strtotime($ex['submitted_at'])) : '—' ?></td>
                    <td>
                        <?php if ($attempted_this): ?>
                        <a href="student_result_detail.php?attempt_id=<?= (int)$ex['attempt_id'] ?>" class="btn-detail"><i class="bi bi-eye"></i> Detail</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><i class="bi bi-journal-x"></i><h6>No Exams in This Course Yet</h6></div>
    <?php endif; ?>

</div>

<script>
setTimeout(() => {
    const bar = document.getElementById('completionBar');
    if (bar) bar.style.width = bar.dataset.pct + '%';
}, 200);
setTimeout(() => {
    document.querySelectorAll('.trend-bar').forEach(b => { b.style.transition = 'height .9s cubic-bezier(.4,0,.2,1)'; b.style.height = b.dataset.height; });
}, 350);
</script>

<?php include "../includes/footer.php"; ?>