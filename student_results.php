<?php
session_start();
require_once "../config/db.php";

// ── Auth guard: students only ─────────────────────────────────────
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header("Location: ../auth/login.php");
    exit();
}

$student_id = (int)($_SESSION['user_id'] ?? 0);
if (!$student_id) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit();
}

// ── Fetch all completed attempts for this student ─────────────────
$stmt = $conn->prepare("
    SELECT
        a.id              AS attempt_id,
        a.exam_id,
        a.score,
        a.correct_answers,
        a.wrong_answers,
        a.skipped_answers,
        a.total_questions,
        a.end_time        AS date_submitted,
        e.title           AS exam_title,
        e.result_published,
        e.exam_date,
        e.duration,
        c.course_name
    FROM exam_attempts a
    JOIN exams e   ON e.id = a.exam_id
    JOIN courses c ON c.id = e.course_id
    WHERE a.student_id = ? AND a.status = 'completed'
    ORDER BY a.end_time DESC
");
$stmt->bind_param("i", $student_id);
$stmt->execute();
$results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

function grade_label(float $s): string {
    if ($s >= 80) return 'A'; if ($s >= 70) return 'B';
    if ($s >= 60) return 'C'; if ($s >= 50) return 'D'; return 'F';
}
function grade_color(float $s): string {
    if ($s >= 80) return '#3fb950'; if ($s >= 70) return '#58a6ff';
    if ($s >= 60) return '#d29922'; if ($s >= 50) return '#f59e0b';
    return '#f85149';
}
function grade_remark(float $s): string {
    if ($s >= 80) return 'Excellent'; if ($s >= 70) return 'Good';
    if ($s >= 60) return 'Average';   if ($s >= 50) return 'Pass';
    return 'Fail';
}

include "../includes/header.php";
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700;800&family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap');
:root{--bg:#0d1117;--surface:#161b22;--surface2:#1c2330;--border:#30363d;--text:#e6edf3;--muted:#8b949e;--accent:#58a6ff;--green:#3fb950;--red:#f85149;--amber:#d29922;--purple:#bc8cff;--radius:14px;--sans:'DM Sans',sans-serif;--serif:'Sora',sans-serif;--mono:'DM Mono',monospace;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;}
.page-shell{max-width:860px;margin:0 auto;padding:2.5rem 1.25rem 5rem;}
.page-header{margin-bottom:2.25rem;text-align:center;}
.page-header h1{font-family:var(--serif);font-size:1.9rem;font-weight:800;color:var(--text);margin-bottom:.4rem;line-height:1.2;}
.page-header p{font-size:.88rem;color:var(--muted);}
.back-link{display:inline-flex;align-items:center;gap:.35rem;font-size:.82rem;color:var(--muted);text-decoration:none;margin-bottom:1.5rem;transition:color .18s;}
.back-link:hover{color:var(--accent);}
.empty-state{text-align:center;padding:4rem 2rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);}
.empty-state .icon{font-size:3rem;display:block;margin-bottom:1rem;color:var(--border);}
.empty-state h5{font-family:var(--serif);font-size:1.1rem;color:var(--text);margin-bottom:.4rem;}
.empty-state p{font-size:.85rem;color:var(--muted);}
.result-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:1.25rem;overflow:hidden;transition:border-color .2s,transform .2s;}
.result-card:hover{border-color:var(--accent);transform:translateY(-2px);}
.card-band{padding:1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;border-bottom:1px solid var(--border);}
.card-band-left h3{font-family:var(--serif);font-size:1rem;font-weight:700;color:var(--text);margin-bottom:.2rem;}
.card-band-left .meta{font-size:.77rem;color:var(--muted);display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;}
.card-band-right{display:flex;align-items:center;gap:.75rem;}
.score-ring{width:68px;height:68px;border-radius:50%;border:3px solid;display:flex;flex-direction:column;align-items:center;justify-content:center;background:var(--surface2);flex-shrink:0;}
.score-ring .score-pct{font-family:var(--mono);font-size:.95rem;font-weight:700;line-height:1;}
.score-ring .score-label{font-size:.6rem;color:var(--muted);margin-top:2px;}
.grade-big{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-family:var(--mono);font-size:1.2rem;font-weight:800;border:2px solid;background:var(--surface2);}
.card-body{padding:1rem 1.25rem;display:flex;gap:1.25rem;flex-wrap:wrap;align-items:center;}
.stat-pills{display:flex;gap:.5rem;flex-wrap:wrap;flex:1;}
.stat-pill{display:inline-flex;align-items:center;gap:.3rem;padding:.28rem .7rem;border-radius:20px;font-family:var(--mono);font-size:.78rem;font-weight:700;background:var(--surface2);border:1px solid var(--border);}
.pill-green{color:var(--green);border-color:#238636;background:#0d2818;}
.pill-red{color:var(--red);border-color:#6e2323;background:#1e0e0e;}
.pill-muted{color:var(--muted);}
.pill-remark{color:var(--accent);border-color:var(--accent);background:#0c1521;}
.score-bar-wrap{width:100%;margin-top:.75rem;}
.score-bar-bg{height:7px;background:var(--border);border-radius:4px;overflow:hidden;}
.score-bar-fill{height:100%;border-radius:4px;transition:width 1s cubic-bezier(.4,0,.2,1);}
.score-bar-labels{display:flex;justify-content:space-between;font-size:.7rem;color:var(--muted);margin-top:.3rem;}
.submitted-date{font-family:var(--mono);font-size:.75rem;color:var(--muted);white-space:nowrap;}
.pending-card{background:var(--surface);border:1px dashed var(--border);border-radius:var(--radius);padding:1.25rem 1.5rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;}
.pending-card .pending-icon{font-size:1.8rem;color:var(--amber);flex-shrink:0;}
.pending-card .pending-info h4{font-family:var(--serif);font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:.2rem;}
.pending-card .pending-info .sub{font-size:.78rem;color:var(--muted);}
.pending-badge{margin-left:auto;padding:.25rem .75rem;border-radius:20px;font-size:.72rem;font-weight:700;background:#1c1a10;color:var(--amber);border:1px solid #6e5208;white-space:nowrap;}
.section-head{font-family:var(--serif);font-size:.88rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.85rem;margin-top:1.75rem;display:flex;align-items:center;gap:.4rem;}
.btn-view-result{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem 1.1rem;border-radius:8px;border:1px solid var(--accent);background:transparent;color:var(--accent);font-size:.83rem;font-weight:700;text-decoration:none;transition:all .18s;}
.btn-view-result:hover{background:var(--accent);color:#0d1117;}
@media(max-width:600px){.card-band{flex-direction:column;align-items:flex-start;}.card-band-right{width:100%;justify-content:flex-end;}.page-header h1{font-size:1.5rem;}}
</style>

<div class="page-shell">

    <a href="available_exams.php" class="back-link">
        <i class="bi bi-arrow-left"></i> Back to Exams
    </a>

    <?php if (($_GET['msg'] ?? '') === 'not_published'): ?>
    <div style="background:#1c1a10;border:1px solid #6e5208;color:#d29922;border-radius:10px;padding:.85rem 1.1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.6rem;font-size:.85rem;">
        <i class="bi bi-lock-fill"></i>
        <span>That result has not been published by your lecturer yet. Check back later.</span>
    </div>
    <?php endif; ?>

    <?php if (($_GET['msg'] ?? '') === 'submitted'): ?>
    <div style="background:#0d2818;border:1px solid #238636;color:#3fb950;border-radius:10px;padding:.85rem 1.1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.6rem;font-size:.85rem;">
        <i class="bi bi-check-circle-fill"></i>
        <span>Exam submitted successfully! Your result will appear below once your lecturer publishes it.</span>
    </div>
    <?php endif; ?>

    <?php if (($_GET['msg'] ?? '') === 'already_submitted'): ?>
    <div style="background:#131c2b;border:1px solid #1d3050;color:#58a6ff;border-radius:10px;padding:.85rem 1.1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.6rem;font-size:.85rem;">
        <i class="bi bi-info-circle-fill"></i>
        <span>You have already submitted this exam.</span>
    </div>
    <?php endif; ?>

    <div class="page-header">
        <h1><i class="bi bi-award" style="color:var(--accent)"></i> My Results</h1>
        <p>Your exam results appear here once your lecturer publishes them.</p>
    </div>

    <?php if (empty($results)): ?>
    <div class="empty-state">
        <span class="icon"><i class="bi bi-journal-x"></i></span>
        <h5>No Completed Exams Yet</h5>
        <p>Once you finish an exam, your results will appear here.</p>
    </div>

    <?php else:
        $published = array_filter($results, fn($r) => (int)$r['result_published'] === 1);
        $pending   = array_filter($results, fn($r) => (int)$r['result_published'] === 0);
    ?>

        <!-- ── Published Results ── -->
        <?php if (!empty($published)): ?>
        <div class="section-head">
            <i class="bi bi-check-circle-fill" style="color:var(--green)"></i>
            Published Results (<?= count($published) ?>)
        </div>

        <?php foreach ($published as $r):
            $sc  = (float)$r['score'];
            $gc  = grade_color($sc);
            $rmk = grade_remark($sc);
        ?>
        <div class="result-card">
            <div class="card-band">
                <div class="card-band-left">
                    <h3><?= htmlspecialchars($r['exam_title']) ?></h3>
                    <div class="meta">
                        <span><i class="bi bi-book"></i> <?= htmlspecialchars($r['course_name']) ?></span>
                        &bull;
                        <span><i class="bi bi-calendar3"></i> <?= date('d M Y', strtotime($r['exam_date'])) ?></span>
                        &bull;
                        <span><i class="bi bi-stopwatch"></i> <?= (int)$r['duration'] ?> min</span>
                    </div>
                </div>
                <div class="card-band-right">
                    <div class="score-ring" style="border-color:<?= $gc ?>">
                        <span class="score-pct" style="color:<?= $gc ?>"><?= number_format($sc, 1) ?>%</span>
                        <span class="score-label">Score</span>
                    </div>
                    <div class="grade-big" style="color:<?= $gc ?>;border-color:<?= $gc ?>">
                        <?= grade_label($sc) ?>
                    </div>
                </div>
            </div>

            <div class="card-body">
                <div class="stat-pills">
                    <span class="stat-pill pill-green"><i class="bi bi-check-lg"></i> <?= (int)$r['correct_answers'] ?> Correct</span>
                    <span class="stat-pill pill-red">  <i class="bi bi-x-lg"></i>     <?= (int)$r['wrong_answers'] ?> Wrong</span>
                    <span class="stat-pill pill-muted"> <i class="bi bi-dash"></i>    <?= (int)$r['skipped_answers'] ?> Skipped</span>
                    <span class="stat-pill pill-remark"><?= $rmk ?></span>
                </div>
                <span class="submitted-date">
                    <i class="bi bi-clock"></i>
                    Submitted: <?= date('d M Y, H:i', strtotime($r['date_submitted'])) ?>
                </span>
            </div>

            <div style="padding:0 1.25rem 1rem;">
                <div class="score-bar-wrap">
                    <div class="score-bar-bg">
                        <div class="score-bar-fill" style="width:0%;background:<?= $gc ?>" data-width="<?= $sc ?>%"></div>
                    </div>
                    <div class="score-bar-labels">
                        <span>0%</span>
                        <span style="color:<?= $gc ?>;font-weight:700"><?= number_format($sc,1) ?>%</span>
                        <span>100%</span>
                    </div>
                </div>
                <div style="margin-top:.85rem;">
                    <!-- FIXED: correct file name for student detail view -->
                    <a href="view_result_detail.php?attempt_id=<?= (int)$r['attempt_id'] ?>" class="btn-view-result">
                        <i class="bi bi-eye"></i> View Full Result
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <!-- ── Pending Results ── -->
        <?php if (!empty($pending)): ?>
        <div class="section-head">
            <i class="bi bi-hourglass-split" style="color:var(--amber)"></i>
            Awaiting Publication (<?= count($pending) ?>)
        </div>

        <?php foreach ($pending as $r): ?>
        <div class="pending-card">
            <div class="pending-icon"><i class="bi bi-hourglass-split"></i></div>
            <div class="pending-info">
                <h4><?= htmlspecialchars($r['exam_title']) ?></h4>
                <div class="sub">
                    <i class="bi bi-book"></i> <?= htmlspecialchars($r['course_name']) ?>
                    &bull;
                    <i class="bi bi-calendar3"></i> <?= date('d M Y', strtotime($r['exam_date'])) ?>
                    &bull;
                    Submitted: <?= date('d M Y, H:i', strtotime($r['date_submitted'])) ?>
                </div>
            </div>
            <span class="pending-badge"><i class="bi bi-lock"></i> Results Not Yet Published</span>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>

    <?php endif; ?>

</div>

<script>
window.addEventListener('load', () => {
    setTimeout(() => {
        document.querySelectorAll('.score-bar-fill').forEach(bar => {
            bar.style.width = bar.dataset.width;
        });
    }, 400);
});
</script>

<?php include "../includes/footer.php"; ?>