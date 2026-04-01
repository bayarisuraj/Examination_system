<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

$lecturer_id   = (int)($_SESSION['user_id'] ?? 0);
$lecturer_name = htmlspecialchars($_SESSION['name'] ?? 'Lecturer');

if (!$lecturer_id) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit();
}

// ── Handle publish toggle ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_publish'])) {
    $eid     = (int)($_POST['exam_id'] ?? 0);
    $new_val = (int)($_POST['new_val'] ?? 0);
    if ($eid) {
        $stmt = $conn->prepare("UPDATE exams SET result_published = ? WHERE id = ? AND created_by = ?");
        $stmt->bind_param("iii", $new_val, $eid, $lecturer_id);
        $stmt->execute();
        $stmt->close();
    }
    header("Location: grade_submissions.php?exam_id={$eid}");
    exit();
}

// ── Fetch all exams by this lecturer ──────────────────────────────
$stmt = $conn->prepare("
    SELECT
        e.id,
        e.title,
        e.result_published,
        c.course_name,
        (SELECT COUNT(*) FROM exam_attempts a
         WHERE a.exam_id = e.id AND a.status = 'completed') AS total_submissions
    FROM exams e
    JOIN courses c ON c.id = e.course_id
    WHERE e.created_by = ?
    ORDER BY e.exam_date DESC
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$selected_exam_id = (int)($_GET['exam_id'] ?? ($exams[0]['id'] ?? 0));
$selected_exam    = null;
$submissions      = [];

if ($selected_exam_id) {
    foreach ($exams as $e) {
        if ($e['id'] == $selected_exam_id) { $selected_exam = $e; break; }
    }

    $stmt = $conn->prepare("
        SELECT
            a.id              AS attempt_id,
            a.score,
            a.correct_answers,
            a.wrong_answers,
            a.skipped_answers,
            a.total_questions,
            a.end_time        AS submitted_at,
            u.name            AS student_name,
            u.email
        FROM exam_attempts a
        JOIN users u ON u.id = a.student_id
        WHERE a.exam_id = ? AND a.status = 'completed'
        ORDER BY a.score DESC
    ");
    $stmt->bind_param("i", $selected_exam_id);
    $stmt->execute();
    $submissions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

function grade_label(float $s): string {
    if ($s >= 80) return 'A'; if ($s >= 70) return 'B';
    if ($s >= 60) return 'C'; if ($s >= 50) return 'D'; return 'F';
}
function grade_color(float $s): string {
    if ($s >= 80) return '#3fb950'; if ($s >= 70) return '#58a6ff';
    if ($s >= 60) return '#d29922'; if ($s >= 50) return '#f59e0b';
    return '#f85149';
}

include "../includes/header.php";
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap');
:root{--bg:#0d1117;--surface:#161b22;--surface2:#1c2330;--border:#30363d;--text:#e6edf3;--muted:#8b949e;--accent:#58a6ff;--green:#3fb950;--red:#f85149;--amber:#d29922;--radius:12px;--sans:'DM Sans',sans-serif;--serif:'Sora',sans-serif;--mono:'DM Mono',monospace;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh;}
.page-shell{max-width:1100px;margin:0 auto;padding:2.5rem 1.25rem 5rem;}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:2rem;}
.page-header h1{font-family:var(--serif);font-size:1.5rem;font-weight:700;color:var(--text);}
.btn-back{display:inline-flex;align-items:center;gap:.4rem;padding:.48rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--muted);font-size:.83rem;font-weight:600;text-decoration:none;transition:all .18s;}
.btn-back:hover{border-color:var(--accent);color:var(--accent);}
.exam-tabs{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.75rem;}
.exam-tab{padding:.38rem .9rem;border-radius:20px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:.82rem;font-weight:600;text-decoration:none;transition:all .18s;}
.exam-tab:hover{border-color:var(--accent);color:var(--accent);}
.exam-tab.active{background:var(--accent);border-color:var(--accent);color:#0d1117;}
.publish-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1rem 1.4rem;margin-bottom:1.5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;}
.pub-published{color:var(--green);font-size:.85rem;font-weight:700;}
.pub-hidden{color:var(--amber);font-size:.85rem;font-weight:700;}
.btn-pub{display:inline-flex;align-items:center;gap:.4rem;padding:.45rem 1rem;border-radius:8px;font-size:.83rem;font-weight:700;border:none;cursor:pointer;transition:filter .18s;}
.btn-pub.do-publish{background:var(--green);color:#0d1117;}
.btn-pub.do-unpublish{background:var(--red);color:#fff;}
.btn-pub:hover{filter:brightness(1.12);}
.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:.87rem;}
thead th{background:var(--surface2);padding:.7rem 1rem;text-align:left;font-size:.71rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap;}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s;}
tbody tr:last-child{border-bottom:none;}
tbody tr:hover{background:var(--surface2);}
tbody td{padding:.8rem 1rem;vertical-align:middle;}
.score-cell{display:flex;align-items:center;gap:.6rem;}
.score-bar-bg{flex:1;height:6px;background:var(--border);border-radius:3px;overflow:hidden;min-width:60px;}
.score-bar-fill{height:100%;border-radius:3px;}
.score-num{font-family:var(--mono);font-size:.85rem;font-weight:600;min-width:44px;text-align:right;}
.grade-badge{display:inline-block;padding:.2rem .6rem;border-radius:20px;font-family:var(--mono);font-size:.75rem;font-weight:700;background:var(--surface2);}
.breakdown{display:flex;gap:.5rem;font-size:.77rem;font-family:var(--mono);}
.bc{color:var(--green);}.bw{color:var(--red);}.bs{color:var(--muted);}
.btn-detail{display:inline-flex;align-items:center;gap:.3rem;padding:.32rem .75rem;border-radius:7px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:.78rem;font-weight:600;text-decoration:none;transition:all .18s;white-space:nowrap;}
.btn-detail:hover{border-color:var(--accent);color:var(--accent);}
.empty-state{text-align:center;padding:4rem 2rem;color:var(--muted);}
.empty-state i{font-size:2.5rem;display:block;margin-bottom:1rem;color:var(--border);}
</style>

<div class="page-shell">

    <div class="page-header">
        <h1><i class="bi bi-check-double" style="color:var(--accent);margin-right:.4rem"></i>Grade &amp; Results</h1>
        <a href="dashboard.php" class="btn-back"><i class="bi bi-arrow-left"></i> Dashboard</a>
    </div>

    <?php if (empty($exams)): ?>
    <div class="empty-state">
        <i class="bi bi-journal-x"></i>
        <p>No exams found. Create exams first.</p>
    </div>
    <?php else: ?>

    <!-- Exam tabs -->
    <div class="exam-tabs">
        <?php foreach ($exams as $ex): ?>
        <a href="grade_submissions.php?exam_id=<?= $ex['id'] ?>"
           class="exam-tab <?= $ex['id'] == $selected_exam_id ? 'active' : '' ?>">
            <?= htmlspecialchars($ex['title']) ?>
            <span style="font-size:.7rem;opacity:.75">(<?= $ex['total_submissions'] ?>)</span>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ($selected_exam): ?>

    <!-- Publish toggle -->
    <div class="publish-bar">
        <div>
            <?php if ($selected_exam['result_published']): ?>
            <span class="pub-published">
                <i class="bi bi-check-circle-fill"></i>
                Results published — students can view their scores
            </span>
            <?php else: ?>
            <span class="pub-hidden">
                <i class="bi bi-eye-slash-fill"></i>
                Results hidden — students cannot see scores yet
            </span>
            <?php endif; ?>
        </div>
        <form method="POST">
            <input type="hidden" name="exam_id" value="<?= $selected_exam_id ?>">
            <input type="hidden" name="new_val"  value="<?= $selected_exam['result_published'] ? 0 : 1 ?>">
            <button type="submit" name="toggle_publish"
                    class="btn-pub <?= $selected_exam['result_published'] ? 'do-unpublish' : 'do-publish' ?>">
                <i class="bi bi-<?= $selected_exam['result_published'] ? 'eye-slash' : 'megaphone' ?>"></i>
                <?= $selected_exam['result_published'] ? 'Hide Results' : 'Publish Results' ?>
            </button>
        </form>
    </div>

    <?php if (empty($submissions)): ?>
    <div class="empty-state">
        <i class="bi bi-inbox"></i>
        <p>No submissions yet for this exam.</p>
    </div>
    <?php else: ?>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student</th>
                    <th>Score</th>
                    <th>Grade</th>
                    <th>Breakdown</th>
                    <th>Submitted</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($submissions as $rank => $s):
                $sc = (float)$s['score'];
                $gc = grade_color($sc);
            ?>
            <tr>
                <td style="font-family:var(--mono);font-size:.78rem;color:var(--muted)"><?= $rank + 1 ?></td>

                <td>
                    <strong style="display:block;color:var(--text)"><?= htmlspecialchars($s['student_name']) ?></strong>
                    <span style="font-size:.77rem;color:var(--muted)"><?= htmlspecialchars($s['email']) ?></span>
                </td>

                <td>
                    <div class="score-cell">
                        <div class="score-bar-bg">
                            <div class="score-bar-fill" style="width:<?= min($sc,100) ?>%;background:<?= $gc ?>"></div>
                        </div>
                        <span class="score-num" style="color:<?= $gc ?>"><?= number_format($sc,1) ?>%</span>
                    </div>
                </td>

                <td>
                    <span class="grade-badge" style="color:<?= $gc ?>;border:1px solid <?= $gc ?>33">
                        <?= grade_label($sc) ?>
                    </span>
                </td>

                <td>
                    <div class="breakdown">
                        <span class="bc"><i class="bi bi-check-lg"></i><?= (int)$s['correct_answers'] ?></span>
                        <span class="bw"><i class="bi bi-x-lg"></i><?= (int)$s['wrong_answers'] ?></span>
                        <span class="bs"><i class="bi bi-dash"></i><?= (int)$s['skipped_answers'] ?></span>
                    </div>
                </td>

                <td style="font-family:var(--mono);font-size:.77rem;color:var(--muted);white-space:nowrap">
                    <?= $s['submitted_at'] ? date('d M Y, H:i', strtotime($s['submitted_at'])) : '—' ?>
                </td>

                <td>
                    <?php
                    // Build the detail URL using the same directory as this file
                    // This avoids path issues regardless of project folder name
                    $detail_url = 'student_result_detail.php?attempt_id=' . (int)$s['attempt_id'];
                    ?>
                    <a href="<?= $detail_url ?>" class="btn-detail">
                        <i class="bi bi-eye"></i> View Detail
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
    <?php endif; ?>
    <?php endif; ?>

</div>

<?php include "../includes/footer.php"; ?>