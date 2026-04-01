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

$exam_id = (int)($_GET['exam_id'] ?? 0);
if (!$exam_id) {
    header("Location: dashboard.php");
    exit();
}

// ── Handle publish/unpublish results from this page ───────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_result_publish'])) {
    $new_val = (int)($_POST['new_published'] ?? 0);
    $stmt = $conn->prepare("UPDATE exams SET result_published = ? WHERE id = ? AND created_by = ?");
    $stmt->bind_param("iii", $new_val, $exam_id, $lecturer_id);
    $stmt->execute();
    $stmt->close();
    header("Location: view_submission.php?exam_id={$exam_id}");
    exit();
}

// ── Verify exam belongs to this lecturer ─────────────────────────
$stmt = $conn->prepare("
    SELECT e.id, e.title, e.duration, e.exam_date, e.status, e.result_published,
           c.course_name,
           (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.id) AS total_questions
    FROM exams e
    JOIN courses c ON c.id = e.course_id
    WHERE e.id = ? AND e.created_by = ?
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

// ── Fetch all completed attempts ──────────────────────────────────
// FIX: JOIN students (not users), status IN ('completed','submitted')
$stmt = $conn->prepare("
    SELECT
        s.id              AS student_id,
        s.name            AS student_name,
        s.email,
        s.student_number,
        s.program,
        a.id              AS attempt_id,
        a.score,
        a.correct_answers,
        a.wrong_answers,
        a.skipped_answers,
        a.total_questions,
        a.end_time        AS date_submitted
    FROM exam_attempts a
    JOIN students s ON s.id = a.student_id
    WHERE a.exam_id = ? AND a.status IN ('completed', 'submitted')
    ORDER BY a.score DESC, a.end_time ASC
");
$stmt->bind_param("i", $exam_id);
$stmt->execute();
$res = $stmt->get_result();
$stmt->close();

$submissions = [];
while ($row = $res->fetch_assoc()) {
    $submissions[] = $row;
}

$total_submissions = count($submissions);

// ── Aggregate stats ───────────────────────────────────────────────
$avg_score  = 0;
$highest    = 0;
$lowest     = 100;
$pass_count = 0;

if ($total_submissions > 0) {
    $sum = 0;
    foreach ($submissions as $s) {
        $sc   = (float)$s['score'];
        $sum += $sc;
        if ($sc > $highest) $highest = $sc;
        if ($sc < $lowest)  $lowest  = $sc;
        if ($sc >= 50) $pass_count++;
    }
    $avg_score = round($sum / $total_submissions, 1);
}

function grade_label(float $s): string {
    if ($s >= 80) return 'A';
    if ($s >= 70) return 'B';
    if ($s >= 60) return 'C';
    if ($s >= 50) return 'D';
    return 'F';
}
function grade_color(float $s): string {
    if ($s >= 80) return '#16a34a';
    if ($s >= 70) return '#2563eb';
    if ($s >= 60) return '#d97706';
    if ($s >= 50) return '#f59e0b';
    return '#dc2626';
}

$buckets = ['0–49' => 0, '50–59' => 0, '60–69' => 0, '70–79' => 0, '80–100' => 0];
foreach ($submissions as $s) {
    $sc = (float)$s['score'];
    if ($sc < 50)       $buckets['0–49']++;
    elseif ($sc < 60)   $buckets['50–59']++;
    elseif ($sc < 70)   $buckets['60–69']++;
    elseif ($sc < 80)   $buckets['70–79']++;
    else                $buckets['80–100']++;
}
$max_bucket = max($buckets) ?: 1;

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
.page-shell { max-width: 1100px; margin: 0 auto; padding: 2.5rem 1.25rem 5rem; }

/* ── Publish Banner ── */
.publish-banner {
    border-radius: var(--radius);
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .75rem;
    border: 1px solid;
}
.publish-banner.published { background: #0d2818; border-color: #238636; color: var(--green); }
.publish-banner.hidden    { background: #1c1a10; border-color: #6e5208; color: var(--amber); }
.publish-banner-text strong { display: block; font-size: .92rem; font-weight: 700; }
.publish-banner-text span   { font-size: .8rem; opacity: .75; }

.btn-pub {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .45rem 1rem; border-radius: 8px;
    font-size: .83rem; font-weight: 700;
    border: none; cursor: pointer; transition: filter .18s;
}
.btn-pub.do-publish   { background: var(--green); color: #0d1117; }
.btn-pub.do-unpublish { background: var(--red);   color: #fff; }
.btn-pub:hover { filter: brightness(1.12); }

/* ── Page header ── */
.page-header {
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: 1rem;
    flex-wrap: wrap; margin-bottom: 2rem;
}
.page-header h1 {
    font-family: var(--serif); font-size: 1.6rem;
    font-weight: 700; color: var(--text);
    margin-bottom: .3rem; line-height: 1.25;
}
.page-header .meta {
    font-size: .83rem; color: var(--muted);
    display: flex; align-items: center; gap: .5rem; flex-wrap: wrap;
}
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
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: .85rem; margin-bottom: 2rem;
}
.stat-card {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 1rem 1.1rem;
    display: flex; flex-direction: column; gap: .3rem;
}
.stat-card .s-label { font-size: .72rem; text-transform: uppercase; letter-spacing: .08em; font-weight: 700; color: var(--muted); }
.stat-card .s-val   { font-family: var(--mono); font-size: 1.5rem; font-weight: 600; }
.sc-total .s-val { color: var(--accent); }
.sc-avg   .s-val { color: var(--purple); }
.sc-high  .s-val { color: var(--green); }
.sc-low   .s-val { color: var(--red); }
.sc-pass  .s-val { color: var(--green); }

/* ── Distribution ── */
.dist-section {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); padding: 1.25rem 1.25rem 1rem;
    margin-bottom: 2rem;
}
.dist-section h3 { font-family: var(--serif); font-size: .95rem; font-weight: 700; color: var(--text); margin-bottom: 1.1rem; }
.dist-chart { display: flex; align-items: flex-end; gap: .75rem; height: 90px; }
.dist-col { flex: 1; display: flex; flex-direction: column; align-items: center; gap: .35rem; height: 100%; justify-content: flex-end; }
.dist-bar-wrap { width: 100%; display: flex; align-items: flex-end; justify-content: center; flex: 1; }
.dist-bar { width: 60%; border-radius: 4px 4px 0 0; min-height: 4px; transition: height .8s cubic-bezier(.4,0,.2,1); background: var(--accent); opacity: .7; }
.dist-bar.fail-bar { background: var(--red); }
.dist-count { font-family: var(--mono); font-size: .75rem; font-weight: 600; color: var(--text); }
.dist-label { font-size: .7rem; color: var(--muted); text-align: center; white-space: nowrap; }

/* ── Toolbar ── */
.toolbar { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-bottom: 1.25rem; }
.search-box { position: relative; flex: 1; min-width: 180px; max-width: 320px; }
.search-box i { position: absolute; left: .75rem; top: 50%; transform: translateY(-50%); color: var(--muted); font-size: .85rem; pointer-events: none; }
.search-box input { width: 100%; background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: .5rem .75rem .5rem 2.1rem; color: var(--text); font-size: .84rem; font-family: var(--sans); outline: none; transition: border-color .2s; }
.search-box input::placeholder { color: var(--muted); }
.search-box input:focus { border-color: var(--accent); }
.sort-select { background: var(--surface); border: 1px solid var(--border); border-radius: 8px; padding: .5rem .85rem; color: var(--text); font-size: .83rem; font-family: var(--sans); cursor: pointer; outline: none; transition: border-color .2s; }
.sort-select:focus { border-color: var(--accent); }
.result-count { font-size: .82rem; color: var(--muted); white-space: nowrap; }

/* ── Table ── */
.table-wrap { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: .88rem; }
thead th { background: var(--surface2); padding: .75rem 1rem; text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .07em; font-weight: 700; color: var(--muted); border-bottom: 1px solid var(--border); white-space: nowrap; cursor: pointer; user-select: none; transition: color .15s; }
thead th:hover { color: var(--accent); }
tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--surface2); }
tbody td { padding: .8rem 1rem; color: var(--text); vertical-align: middle; }

.td-rank { font-family: var(--mono); font-size: .78rem; color: var(--muted); width: 40px; }
.td-rank.rank-1 { color: #ffd700; font-weight: 700; }
.td-rank.rank-2 { color: #c0c0c0; font-weight: 700; }
.td-rank.rank-3 { color: #cd7f32; font-weight: 700; }

.td-student strong { display: block; color: var(--text); }
.td-student .stu-email  { font-size: .77rem; color: var(--muted); display: block; }
.td-student .stu-meta   { font-size: .72rem; color: var(--accent); font-family: var(--mono); display: block; margin-top: 1px; }

.score-cell { display: flex; align-items: center; gap: .6rem; }
.score-bar-bg { flex: 1; height: 6px; background: var(--border); border-radius: 3px; overflow: hidden; min-width: 60px; }
.score-bar-fill { height: 100%; border-radius: 3px; }
.score-num { font-family: var(--mono); font-size: .85rem; font-weight: 600; min-width: 44px; text-align: right; }

.grade-badge { display: inline-block; padding: .2rem .6rem; border-radius: 20px; font-family: var(--mono); font-size: .75rem; font-weight: 700; background: var(--surface2); }
.pf-pill { display: inline-block; padding: 2px 9px; border-radius: 20px; font-size: .72rem; font-weight: 700; }
.pf-pass { background: #0d2818; color: var(--green); border: 1px solid #238636; }
.pf-fail { background: #2d1117; color: var(--red);   border: 1px solid #6e1c1c; }

.mini-stats { display: flex; gap: .5rem; font-size: .77rem; color: var(--muted); font-family: var(--mono); flex-wrap: wrap; }
.mini-correct { color: var(--green); }
.mini-wrong   { color: var(--red); }
.mini-skip    { color: var(--muted); }

.td-date { font-size: .78rem; color: var(--muted); font-family: var(--mono); white-space: nowrap; }

.btn-detail { display: inline-flex; align-items: center; gap: .3rem; padding: .32rem .75rem; border-radius: 7px; border: 1px solid var(--border); background: transparent; color: var(--muted); font-size: .78rem; font-weight: 600; text-decoration: none; transition: all .18s; white-space: nowrap; }
.btn-detail:hover { border-color: var(--accent); color: var(--accent); }

.empty-state { text-align: center; padding: 4rem 2rem; color: var(--muted); }
.empty-state i { font-size: 2.5rem; display: block; margin-bottom: 1rem; color: var(--border); }
.empty-state h5 { color: var(--text); font-family: var(--serif); margin-bottom: .5rem; }

.btn-export { display: inline-flex; align-items: center; gap: .4rem; padding: .5rem 1rem; border-radius: 8px; border: 1px solid var(--green); background: transparent; color: var(--green); font-size: .83rem; font-weight: 600; cursor: pointer; transition: all .18s; }
.btn-export:hover { background: var(--green); color: #0d1117; }

@media (max-width: 640px) {
    .page-header h1 { font-size: 1.3rem; }
    .dist-chart { height: 70px; }
    .publish-banner { flex-direction: column; align-items: flex-start; }
}
</style>

<div class="page-shell">

    <!-- ── Publish Results Banner ── -->
    <div class="publish-banner <?= $exam['result_published'] ? 'published' : 'hidden' ?>">
        <div class="publish-banner-text">
            <strong>
                <i class="bi bi-<?= $exam['result_published'] ? 'check-circle-fill' : 'eye-slash-fill' ?>"></i>
                <?= $exam['result_published'] ? 'Results are visible to students' : 'Results are hidden from students' ?>
            </strong>
            <span>
                <?= $exam['result_published']
                    ? 'Students can view their score and grade on the results page.'
                    : 'Students will see "Results not yet available" until you publish.' ?>
            </span>
        </div>
        <form method="POST" action="view_submission.php?exam_id=<?= $exam_id ?>">
            <input type="hidden" name="new_published" value="<?= $exam['result_published'] ? 0 : 1 ?>">
            <button type="submit" name="toggle_result_publish"
                    class="btn-pub <?= $exam['result_published'] ? 'do-unpublish' : 'do-publish' ?>">
                <i class="bi bi-<?= $exam['result_published'] ? 'eye-slash' : 'megaphone' ?>"></i>
                <?= $exam['result_published'] ? 'Hide Results' : 'Publish Results' ?>
            </button>
        </form>
    </div>

    <!-- Page header -->
    <div class="page-header">
        <div>
            <h1><i class="bi bi-people" style="color:var(--accent)"></i> <?= htmlspecialchars($exam['title']) ?></h1>
            <div class="meta">
                <i class="bi bi-book"></i> <?= htmlspecialchars($exam['course_name']) ?>
                &bull;
                <i class="bi bi-calendar3"></i> <?= date('d M Y, H:i', strtotime($exam['exam_date'])) ?>
                &bull;
                <i class="bi bi-stopwatch"></i> <?= (int)$exam['duration'] ?> min
                &bull;
                <i class="bi bi-question-circle"></i> <?= (int)$exam['total_questions'] ?> questions
            </div>
        </div>
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
            <button class="btn-export" onclick="exportCSV()">
                <i class="bi bi-download"></i> Export CSV
            </button>
            <a href="manage_exams.php" class="btn-back">
                <i class="bi bi-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="stat-grid">
        <div class="stat-card sc-total">
            <span class="s-label">Submissions</span>
            <span class="s-val"><?= $total_submissions ?></span>
        </div>
        <div class="stat-card sc-avg">
            <span class="s-label">Average Score</span>
            <span class="s-val"><?= $total_submissions ? $avg_score . '%' : '—' ?></span>
        </div>
        <div class="stat-card sc-high">
            <span class="s-label">Highest</span>
            <span class="s-val"><?= $total_submissions ? $highest . '%' : '—' ?></span>
        </div>
        <div class="stat-card sc-low">
            <span class="s-label">Lowest</span>
            <span class="s-val"><?= $total_submissions ? $lowest . '%' : '—' ?></span>
        </div>
        <div class="stat-card sc-pass">
            <span class="s-label">Pass Rate</span>
            <span class="s-val"><?= $total_submissions ? round(($pass_count / $total_submissions) * 100) . '%' : '—' ?></span>
        </div>
    </div>

    <!-- Score distribution -->
    <?php if ($total_submissions > 0): ?>
    <div class="dist-section">
        <h3><i class="bi bi-bar-chart-fill" style="color:var(--accent);margin-right:.4rem"></i>Score Distribution</h3>
        <div class="dist-chart" id="distChart">
            <?php foreach ($buckets as $label => $count): ?>
            <div class="dist-col">
                <div class="dist-bar-wrap">
                    <div class="dist-bar <?= $label === '0–49' ? 'fail-bar' : '' ?>"
                         style="height:0%"
                         data-height="<?= round(($count / $max_bucket) * 100) ?>%">
                    </div>
                </div>
                <span class="dist-count"><?= $count ?></span>
                <span class="dist-label"><?= $label ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="searchInput" placeholder="Search name, email or student number…">
        </div>
        <select class="sort-select" id="sortSelect">
            <option value="rank">Sort: Rank (High → Low)</option>
            <option value="rank_asc">Sort: Rank (Low → High)</option>
            <option value="name">Sort: Name A–Z</option>
            <option value="date">Sort: Date Submitted</option>
        </select>
        <span class="result-count" id="resultCount"><?= $total_submissions ?> student<?= $total_submissions !== 1 ? 's' : '' ?></span>
    </div>

    <!-- Table -->
    <?php if ($total_submissions > 0): ?>
    <div class="table-wrap">
        <table id="submissionsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student</th>
                    <th>Score</th>
                    <th>Grade</th>
                    <th>P/F</th>
                    <th>Breakdown</th>
                    <th>Submitted</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php foreach ($submissions as $rank => $s):
                    $sc      = (float)$s['score'];
                    $grade   = grade_label($sc);
                    $gc      = grade_color($sc);
                    $pass    = $sc >= 50;
                    $rank1   = $rank + 1;
                    $rankCls = match($rank1) { 1 => 'rank-1', 2 => 'rank-2', 3 => 'rank-3', default => '' };
                ?>
                <tr data-name="<?= strtolower(htmlspecialchars($s['student_name'])) ?>"
                    data-email="<?= strtolower(htmlspecialchars($s['email'])) ?>"
                    data-stunum="<?= strtolower(htmlspecialchars($s['student_number'] ?? '')) ?>"
                    data-score="<?= $sc ?>"
                    data-date="<?= $s['date_submitted'] ?>">

                    <td class="td-rank <?= $rankCls ?>"><?= $rank1 ?></td>

                    <td class="td-student">
                        <strong><?= htmlspecialchars($s['student_name']) ?></strong>
                        <span class="stu-email"><?= htmlspecialchars($s['email']) ?></span>
                        <?php if (!empty($s['student_number'])): ?>
                        <span class="stu-meta">
                            <?= htmlspecialchars($s['student_number']) ?>
                            <?php if (!empty($s['program'])): ?>
                            &nbsp;·&nbsp; <?= htmlspecialchars($s['program']) ?>
                            <?php endif; ?>
                        </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <div class="score-cell">
                            <div class="score-bar-bg">
                                <div class="score-bar-fill" style="width:<?= $sc ?>%;background:<?= $gc ?>"></div>
                            </div>
                            <span class="score-num" style="color:<?= $gc ?>"><?= number_format($sc,1) ?>%</span>
                        </div>
                    </td>

                    <td>
                        <span class="grade-badge" style="color:<?= $gc ?>;border:1px solid <?= $gc ?>40">
                            <?= $grade ?>
                        </span>
                    </td>

                    <td>
                        <span class="pf-pill <?= $pass ? 'pf-pass' : 'pf-fail' ?>">
                            <?= $pass ? 'Pass' : 'Fail' ?>
                        </span>
                    </td>

                    <td>
                        <div class="mini-stats">
                            <span class="mini-correct"><i class="bi bi-check-lg"></i><?= (int)$s['correct_answers'] ?></span>
                            <span class="mini-wrong"><i class="bi bi-x-lg"></i><?= (int)$s['wrong_answers'] ?></span>
                            <span class="mini-skip"><i class="bi bi-dash"></i><?= (int)$s['skipped_answers'] ?></span>
                        </div>
                    </td>

                    <td class="td-date">
                        <?= $s['date_submitted'] ? date('d M Y\<\b\r\>H:i', strtotime($s['date_submitted'])) : '—' ?>
                    </td>

                    <td>
                        <a href="student_result_detail.php?attempt_id=<?= (int)$s['attempt_id'] ?>" class="btn-detail">
                            <i class="bi bi-eye"></i> Detail
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php else: ?>
    <div class="empty-state">
        <i class="bi bi-inbox"></i>
        <h5>No Submissions Yet</h5>
        <p>Students who complete this exam will appear here.</p>
    </div>
    <?php endif; ?>

</div>

<script>
// ── Animate distribution bars ──
setTimeout(() => {
    document.querySelectorAll('.dist-bar').forEach(bar => {
        bar.style.height = bar.dataset.height;
    });
}, 300);

// ── Search (name + email + student number) ──
const searchInput = document.getElementById('searchInput');
const tableBody   = document.getElementById('tableBody');
const resultCount = document.getElementById('resultCount');

function filterRows() {
    const q = searchInput.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('#tableBody tr').forEach(row => {
        const match = !q
            || (row.dataset.name   || '').includes(q)
            || (row.dataset.email  || '').includes(q)
            || (row.dataset.stunum || '').includes(q);
        row.style.display = match ? '' : 'none';
        if (match) visible++;
    });
    resultCount.textContent = `${visible} student${visible !== 1 ? 's' : ''}`;
}
searchInput.addEventListener('input', filterRows);

// ── Sort ──
document.getElementById('sortSelect').addEventListener('change', function () {
    const rows = Array.from(document.querySelectorAll('#tableBody tr'));
    rows.sort((a, b) => {
        switch (this.value) {
            case 'rank':     return parseFloat(b.dataset.score) - parseFloat(a.dataset.score);
            case 'rank_asc': return parseFloat(a.dataset.score) - parseFloat(b.dataset.score);
            case 'name':     return (a.dataset.name || '').localeCompare(b.dataset.name || '');
            case 'date':     return new Date(a.dataset.date) - new Date(b.dataset.date);
            default:         return 0;
        }
    });
    rows.forEach((r, i) => {
        r.querySelector('.td-rank').textContent = i + 1;
        tableBody.appendChild(r);
    });
});

// ── Export CSV ──
function exportCSV() {
    const rows  = document.querySelectorAll('#tableBody tr');
    const lines = [['Rank','Name','Email','Student No.','Program','Score (%)','Grade','P/F','Correct','Wrong','Skipped','Submitted']];
    rows.forEach(row => {
        if (row.style.display === 'none') return;
        const cells = row.querySelectorAll('td');
        const pass  = parseFloat(row.dataset.score) >= 50 ? 'Pass' : 'Fail';
        lines.push([
            cells[0].textContent.trim(),
            row.dataset.name,
            row.dataset.email,
            row.dataset.stunum || '',
            cells[1].querySelector('.stu-meta')?.textContent.trim().split('·')[1]?.trim() || '',
            parseFloat(row.dataset.score).toFixed(1),
            cells[3].textContent.trim(),
            pass,
            cells[5].querySelector('.mini-correct')?.textContent.replace(/\D/g,'') ?? '',
            cells[5].querySelector('.mini-wrong')?.textContent.replace(/\D/g,'')  ?? '',
            cells[5].querySelector('.mini-skip')?.textContent.replace(/\D/g,'')   ?? '',
            row.dataset.date,
        ]);
    });
    const csv  = lines.map(r => r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')).join('\n');
    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `<?= preg_replace('/[^a-z0-9]+/i', '_', $exam['title']) ?>_submissions.csv`;
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<?php include "../includes/footer.php"; ?>