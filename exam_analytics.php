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

$exam_id = (int)($_GET['exam_id'] ?? 0);
if (!$exam_id) {
    header("Location: dashboard.php");
    exit();
}

// ── Verify exam belongs to this lecturer (lecturer_id) ────────────
$stmt = $conn->prepare("
    SELECT e.id, e.title, e.duration, e.exam_date, e.status,
           c.id AS course_id, c.course_name,
           (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.id) AS total_questions
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
    header("Location: dashboard.php?error=not_found");
    exit();
}

// ── Total enrolled students for this course ───────────────────────
$stmt = $conn->prepare("SELECT COUNT(*) AS total_enrolled FROM course_enrollments WHERE course_id = ?");
$stmt->bind_param("i", $exam['course_id']);
$stmt->execute();
$total_enrolled = (int)$stmt->get_result()->fetch_assoc()['total_enrolled'];
$stmt->close();

// ── All completed attempts — uses users.name (not username) ───────
$stmt = $conn->prepare("
    SELECT
        a.id              AS attempt_id,
        a.student_id,
        a.score,
        a.correct_answers,
        a.wrong_answers,
        a.skipped_answers,
        a.total_questions,
        a.end_time        AS date_submitted,
        u.name            AS student_name,
        u.email
    FROM exam_attempts a
    JOIN users u ON u.id = a.student_id
    WHERE a.exam_id = ? AND a.status = 'completed'
    ORDER BY a.score DESC
");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$attempts      = [];
$attempted_ids = [];
while ($row = $res->fetch_assoc()) {
    $attempts[]                        = $row;
    $attempted_ids[$row['student_id']] = true;
}

$total_attempted = count($attempts);
$not_attempted   = max(0, $total_enrolled - $total_attempted);

// ── Aggregate stats ───────────────────────────────────────────────
$avg_score    = 0;
$highest      = 0;
$lowest       = 100;
$pass_count   = 0;
$grade_buckets = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];

function grade_label(float $s): string {
    if ($s >= 80) return 'A'; if ($s >= 70) return 'B';
    if ($s >= 60) return 'C'; if ($s >= 50) return 'D'; return 'F';
}
function grade_color(string $g): string {
    return match($g) {
        'A' => '#3fb950', 'B' => '#58a6ff',
        'C' => '#d29922', 'D' => '#f0883e',
        default => '#f85149',
    };
}

if ($total_attempted > 0) {
    $sum = 0;
    foreach ($attempts as $a) {
        $sc   = (float)$a['score'];
        $sum += $sc;
        if ($sc > $highest) $highest = $sc;
        if ($sc < $lowest)  $lowest  = $sc;
        if ($sc >= 50) $pass_count++;
        $grade_buckets[grade_label($sc)]++;
    }
    $avg_score = round($sum / $total_attempted, 1);
} else {
    $lowest = 0;
}

$pass_rate = $total_attempted > 0
    ? round(($pass_count / $total_attempted) * 100, 1)
    : 0;

// ── Question difficulty analysis (uses student_answers) ───────────
$stmt = $conn->prepare("
    SELECT
        q.id            AS question_id,
        q.question_text,
        COUNT(sa.id)       AS total_answered,
        SUM(sa.is_correct) AS correct_count
    FROM questions q
    JOIN exam_questions eq ON eq.question_id = q.id
    LEFT JOIN student_answers sa
           ON sa.question_id = q.id
          AND sa.attempt_id IN (
              SELECT id FROM exam_attempts
              WHERE exam_id = ? AND status = 'completed'
          )
    WHERE eq.exam_id = ?
    GROUP BY q.id, q.question_text
    ORDER BY
        CASE WHEN COUNT(sa.id) = 0 THEN 1 ELSE 0 END,
        (SUM(sa.is_correct) / NULLIF(COUNT(sa.id), 0)) ASC
");
$stmt->bind_param("ii", $exam_id, $exam_id);
$stmt->execute();
$q_res = $stmt->get_result();
$stmt->close();

$question_stats = [];
$qnum = 1;
while ($r = $q_res->fetch_assoc()) {
    $total_ans  = (int)$r['total_answered'];
    $correct    = (int)$r['correct_count'];
    $pct        = $total_ans > 0 ? round(($correct / $total_ans) * 100, 1) : 0;
    $difficulty = match(true) {
        $pct >= 80  => ['Easy',   '#3fb950'],
        $pct >= 50  => ['Medium', '#d29922'],
        default     => ['Hard',   '#f85149'],
    };
    $question_stats[] = [
        'num'        => $qnum++,
        'text'       => $r['question_text'],
        'total_ans'  => $total_ans,
        'correct'    => $correct,
        'pct'        => $pct,
        'difficulty' => $difficulty,
    ];
}

// ── Non-submitters (uses users table, name column) ────────────────
$stmt = $conn->prepare("
    SELECT u.id, u.name, u.email
    FROM course_enrollments ce
    JOIN users u ON u.id = ce.student_id
    WHERE ce.course_id = ?
      AND u.id NOT IN (
          SELECT student_id FROM exam_attempts
          WHERE exam_id = ? AND status = 'completed'
      )
    ORDER BY u.name ASC
");
$stmt->bind_param("ii", $exam['course_id'], $exam_id);
$stmt->execute();
$ns_res = $stmt->get_result();
$stmt->close();

$non_submitters = [];
while ($r = $ns_res->fetch_assoc()) {
    $non_submitters[] = $r;
}

$max_grade_bucket = max($grade_buckets) ?: 1;

include "../includes/header.php";
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap');
:root{--bg:#0d1117;--surface:#161b22;--surface2:#1c2330;--border:#30363d;--text:#e6edf3;--muted:#8b949e;--accent:#58a6ff;--green:#3fb950;--red:#f85149;--amber:#d29922;--orange:#f0883e;--purple:#bc8cff;--radius:12px;--sans:'DM Sans',sans-serif;--serif:'Sora',sans-serif;--mono:'DM Mono',monospace;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;}
.page-shell{max-width:1100px;margin:0 auto;padding:2.5rem 1.25rem 5rem;}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:2rem;}
.page-header h1{font-family:var(--serif);font-size:1.6rem;font-weight:700;color:var(--text);margin-bottom:.3rem;}
.page-header .meta{font-size:.82rem;color:var(--muted);display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;}
.header-actions{display:flex;gap:.6rem;flex-wrap:wrap;}
.btn-outline{display:inline-flex;align-items:center;gap:.4rem;padding:.48rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--muted);font-size:.83rem;font-weight:600;text-decoration:none;cursor:pointer;transition:all .18s;}
.btn-outline:hover{border-color:var(--accent);color:var(--accent);}
.btn-green{border-color:var(--green);color:var(--green);}
.btn-green:hover{background:var(--green);color:#0d1117;border-color:var(--green);}
.section-title{font-family:var(--serif);font-size:1rem;font-weight:700;color:var(--text);margin-bottom:1rem;display:flex;align-items:center;gap:.5rem;}
.section-title i{color:var(--accent);}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:.85rem;margin-bottom:2rem;}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem 1.1rem;}
.stat-card .s-label{font-size:.7rem;text-transform:uppercase;letter-spacing:.08em;font-weight:700;color:var(--muted);margin-bottom:.3rem;}
.stat-card .s-val{font-family:var(--mono);font-size:1.5rem;font-weight:700;}
.stat-card .s-sub{font-size:.73rem;color:var(--muted);margin-top:.2rem;}
.sc-enrolled .s-val{color:var(--accent);}.sc-attempted .s-val{color:var(--purple);}.sc-avg .s-val{color:var(--amber);}.sc-high .s-val{color:var(--green);}.sc-low .s-val{color:var(--red);}.sc-pass .s-val{color:var(--green);}
.two-col{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:2rem;}
@media(max-width:720px){.two-col{grid-template-columns:1fr;}}
.panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem;}
.grade-chart{display:flex;align-items:flex-end;gap:.85rem;height:120px;margin-top:.75rem;}
.grade-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:.35rem;height:100%;justify-content:flex-end;}
.grade-bar-wrap{width:100%;display:flex;align-items:flex-end;justify-content:center;flex:1;}
.grade-bar{width:55%;border-radius:5px 5px 0 0;min-height:4px;}
.grade-count{font-family:var(--mono);font-size:.78rem;font-weight:600;color:var(--text);}
.grade-label{font-family:var(--mono);font-size:.75rem;font-weight:700;}
.donut-wrap{display:flex;align-items:center;justify-content:center;gap:1.5rem;margin-top:.75rem;flex-wrap:wrap;}
.donut-svg{width:110px;height:110px;flex-shrink:0;}
.donut-legend{display:flex;flex-direction:column;gap:.6rem;}
.legend-item{display:flex;align-items:center;gap:.5rem;font-size:.82rem;color:var(--text);}
.legend-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0;}
.legend-val{font-family:var(--mono);font-weight:700;margin-left:auto;padding-left:.75rem;}
.diff-table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;overflow-x:auto;margin-bottom:2rem;}
table{width:100%;border-collapse:collapse;font-size:.87rem;}
thead th{background:var(--surface2);padding:.7rem 1rem;text-align:left;font-size:.71rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap;}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:var(--surface2);}
tbody td{padding:.75rem 1rem;vertical-align:middle;}
.td-qnum{font-family:var(--mono);font-size:.78rem;color:var(--muted);width:40px;}
.td-qtext{max-width:340px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;color:var(--text);}
.pct-cell{display:flex;align-items:center;gap:.65rem;min-width:160px;}
.pct-bar-bg{flex:1;height:6px;background:var(--border);border-radius:3px;overflow:hidden;}
.pct-bar-fill{height:100%;border-radius:3px;}
.pct-num{font-family:var(--mono);font-size:.82rem;font-weight:600;min-width:42px;text-align:right;}
.diff-badge{display:inline-block;padding:.2rem .6rem;border-radius:20px;font-size:.71rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;}
.ns-panel{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:2rem;}
.ns-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border);background:var(--surface2);flex-wrap:wrap;gap:.5rem;}
.ns-header h3{font-family:var(--serif);font-size:.95rem;font-weight:700;}
.ns-count{background:#1e1212;border:1px solid #6e2020;color:var(--red);font-size:.75rem;font-weight:700;padding:.2rem .65rem;border-radius:20px;font-family:var(--mono);}
.ns-row{display:flex;align-items:center;justify-content:space-between;padding:.75rem 1.25rem;border-bottom:1px solid var(--border);gap:.75rem;flex-wrap:wrap;}
.ns-row:last-child{border-bottom:none;}
.ns-row:hover{background:var(--surface2);}
.ns-student strong{display:block;font-size:.88rem;color:var(--text);}
.ns-student span{font-size:.77rem;color:var(--muted);}
.btn-remind{display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .75rem;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:.77rem;font-weight:600;text-decoration:none;transition:all .18s;white-space:nowrap;}
.btn-remind:hover{border-color:var(--amber);color:var(--amber);}
.ns-empty{padding:2rem;text-align:center;color:var(--green);font-size:.88rem;display:flex;align-items:center;justify-content:center;gap:.5rem;}
.empty-analytics{text-align:center;padding:4rem 2rem;color:var(--muted);background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:2rem;}
.empty-analytics i{font-size:2.5rem;display:block;margin-bottom:1rem;color:var(--border);}
.empty-analytics h5{color:var(--text);font-family:var(--serif);margin-bottom:.4rem;}
@media(max-width:600px){.page-shell{padding:1.5rem .75rem 4rem;}.page-header h1{font-size:1.3rem;}}
</style>

<div class="page-shell">

    <div class="page-header">
        <div>
            <h1><i class="bi bi-graph-up-arrow" style="color:var(--accent)"></i> <?= htmlspecialchars($exam['title']) ?></h1>
            <div class="meta">
                <i class="bi bi-book"></i> <?= htmlspecialchars($exam['course_name']) ?>
                &bull; <i class="bi bi-calendar3"></i> <?= date('d M Y, H:i', strtotime($exam['exam_date'])) ?>
                &bull; <i class="bi bi-stopwatch"></i> <?= (int)$exam['duration'] ?> min
                &bull; <i class="bi bi-question-circle"></i> <?= (int)$exam['total_questions'] ?> questions
            </div>
        </div>
        <div class="header-actions">
            <button class="btn-outline btn-green" onclick="exportCSV()">
                <i class="bi bi-download"></i> Export CSV
            </button>
            <a href="view_submission.php?exam_id=<?= $exam_id ?>" class="btn-outline">
                <i class="bi bi-people"></i> Submissions
            </a>
            <a href="manage_exams.php" class="btn-outline">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="stat-grid">
        <div class="stat-card sc-enrolled"><div class="s-label">Enrolled</div><div class="s-val"><?= $total_enrolled ?></div><div class="s-sub">students in course</div></div>
        <div class="stat-card sc-attempted"><div class="s-label">Attempted</div><div class="s-val"><?= $total_attempted ?></div><div class="s-sub"><?= $total_enrolled > 0 ? round(($total_attempted/$total_enrolled)*100) : 0 ?>% participation</div></div>
        <div class="stat-card sc-avg"><div class="s-label">Average Score</div><div class="s-val"><?= $total_attempted ? $avg_score . '%' : '—' ?></div></div>
        <div class="stat-card sc-high"><div class="s-label">Highest</div><div class="s-val"><?= $total_attempted ? $highest . '%' : '—' ?></div></div>
        <div class="stat-card sc-low"><div class="s-label">Lowest</div><div class="s-val"><?= $total_attempted ? $lowest . '%' : '—' ?></div></div>
        <div class="stat-card sc-pass"><div class="s-label">Pass Rate</div><div class="s-val"><?= $total_attempted ? $pass_rate . '%' : '—' ?></div><div class="s-sub"><?= $pass_count ?> of <?= $total_attempted ?> passed</div></div>
    </div>

    <?php if ($total_attempted > 0): ?>

    <div class="two-col">
        <div class="panel">
            <div class="section-title"><i class="bi bi-bar-chart-fill"></i> Grade Distribution</div>
            <div class="grade-chart">
                <?php foreach ($grade_buckets as $g => $count):
                    $gc  = grade_color($g);
                    $pct = round(($count / $max_grade_bucket) * 100);
                ?>
                <div class="grade-col">
                    <div class="grade-bar-wrap">
                        <div class="grade-bar" style="height:0%;background:<?= $gc ?>" data-height="<?= $pct ?>%"></div>
                    </div>
                    <span class="grade-count"><?= $count ?></span>
                    <span class="grade-label" style="color:<?= $gc ?>"><?= $g ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="panel">
            <div class="section-title"><i class="bi bi-pie-chart-fill"></i> Participation</div>
            <?php
            $circ_d   = 2 * M_PI * 40;
            $att_pct  = $total_enrolled > 0 ? ($total_attempted / $total_enrolled) : 0;
            $att_dash = $att_pct * $circ_d;
            ?>
            <div class="donut-wrap">
                <svg class="donut-svg" viewBox="0 0 100 100">
                    <circle cx="50" cy="50" r="40" fill="none" stroke="var(--border)" stroke-width="14"/>
                    <circle cx="50" cy="50" r="40" fill="none" stroke="var(--green)" stroke-width="14"
                            stroke-dasharray="<?= $att_dash ?> <?= $circ_d ?>"
                            stroke-dashoffset="<?= $circ_d * 0.25 ?>"/>
                    <circle cx="50" cy="50" r="40" fill="none" stroke="var(--red)" stroke-width="14"
                            stroke-dasharray="<?= $circ_d - $att_dash ?> <?= $circ_d ?>"
                            stroke-dashoffset="<?= $circ_d * 0.25 - $att_dash ?>"/>
                    <text x="50" y="46" text-anchor="middle" font-family="DM Mono,monospace" font-size="13" font-weight="700" fill="var(--text)"><?= round($att_pct * 100) ?>%</text>
                    <text x="50" y="60" text-anchor="middle" font-family="DM Sans,sans-serif" font-size="7" fill="var(--muted)">attempted</text>
                </svg>
                <div class="donut-legend">
                    <div class="legend-item"><span class="legend-dot" style="background:var(--green)"></span>Attempted<span class="legend-val" style="color:var(--green)"><?= $total_attempted ?></span></div>
                    <div class="legend-item"><span class="legend-dot" style="background:var(--red)"></span>Not attempted<span class="legend-val" style="color:var(--red)"><?= $not_attempted ?></span></div>
                    <div class="legend-item"><span class="legend-dot" style="background:var(--accent)"></span>Total enrolled<span class="legend-val" style="color:var(--accent)"><?= $total_enrolled ?></span></div>
                </div>
            </div>
        </div>
    </div>

    <div class="section-title" style="margin-bottom:.75rem"><i class="bi bi-patch-question-fill" style="color:var(--accent)"></i> Question Difficulty Analysis</div>
    <div class="diff-table-wrap">
        <table>
            <thead><tr><th>#</th><th>Question</th><th>Correct Rate</th><th>Correct / Total</th><th>Difficulty</th></tr></thead>
            <tbody>
                <?php foreach ($question_stats as $qs):
                    [$diff_label, $diff_color] = $qs['difficulty'];
                    $bar_color = match($diff_label) { 'Easy' => '#3fb950', 'Medium' => '#d29922', default => '#f85149' };
                ?>
                <tr>
                    <td class="td-qnum">Q<?= $qs['num'] ?></td>
                    <td class="td-qtext" title="<?= htmlspecialchars($qs['text']) ?>"><?= htmlspecialchars(mb_strimwidth($qs['text'], 0, 80, '…')) ?></td>
                    <td>
                        <div class="pct-cell">
                            <div class="pct-bar-bg"><div class="pct-bar-fill" style="width:<?= $qs['pct'] ?>%;background:<?= $bar_color ?>"></div></div>
                            <span class="pct-num" style="color:<?= $bar_color ?>"><?= $qs['pct'] ?>%</span>
                        </div>
                    </td>
                    <td style="font-family:var(--mono);font-size:.82rem;color:var(--muted)"><?= $qs['correct'] ?> / <?= $qs['total_ans'] ?></td>
                    <td><span class="diff-badge" style="background:<?= $diff_color ?>22;color:<?= $diff_color ?>;border:1px solid <?= $diff_color ?>44"><?= $diff_label ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
    <div class="empty-analytics">
        <i class="bi bi-inbox"></i>
        <h5>No Submissions Yet</h5>
        <p>Analytics will appear here once students start submitting.</p>
    </div>
    <?php endif; ?>

    <div class="ns-panel">
        <div class="ns-header">
            <h3><i class="bi bi-person-x" style="color:var(--red);margin-right:.4rem"></i>Students Who Haven't Submitted</h3>
            <span class="ns-count"><?= count($non_submitters) ?> student<?= count($non_submitters) !== 1 ? 's' : '' ?></span>
        </div>
        <?php if (empty($non_submitters)): ?>
        <div class="ns-empty"><i class="bi bi-check-circle-fill"></i> All enrolled students have submitted this exam.</div>
        <?php else: ?>
            <?php foreach ($non_submitters as $ns): ?>
            <div class="ns-row">
                <div class="ns-student">
                    <strong><?= htmlspecialchars($ns['name']) ?></strong>
                    <span><?= htmlspecialchars($ns['email']) ?></span>
                </div>
                <a href="mailto:<?= htmlspecialchars($ns['email']) ?>?subject=<?= urlencode('Reminder: ' . $exam['title']) ?>&body=<?= urlencode('Hi ' . $ns['name'] . ', this is a reminder that you have not yet submitted the exam: ' . $exam['title'] . '. Please log in to complete it.') ?>"
                   class="btn-remind"><i class="bi bi-envelope"></i> Send Reminder</a>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

</div>

<script>
setTimeout(() => {
    document.querySelectorAll('.grade-bar').forEach(bar => {
        bar.style.transition = 'height .9s cubic-bezier(.4,0,.2,1)';
        bar.style.height = bar.dataset.height;
    });
}, 300);

const attempts = <?= json_encode(array_map(fn($a) => [
    'name'      => $a['student_name'],
    'email'     => $a['email'],
    'score'     => $a['score'],
    'grade'     => grade_label((float)$a['score']),
    'correct'   => $a['correct_answers'],
    'wrong'     => $a['wrong_answers'],
    'skipped'   => $a['skipped_answers'],
    'submitted' => $a['date_submitted'],
], $attempts)) ?>;

function exportCSV() {
    const header = ['Rank','Name','Email','Score (%)','Grade','Correct','Wrong','Skipped','Submitted'];
    const rows   = attempts.map((a, i) => [
        i+1, a.name, a.email,
        parseFloat(a.score).toFixed(1), a.grade,
        a.correct, a.wrong, a.skipped, a.submitted
    ]);
    const csv  = [header, ...rows].map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `<?= preg_replace('/[^a-z0-9]+/i','_',$exam['title']) ?>_analytics.csv`;
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<?php include "../includes/footer.php"; ?>