<?php
session_start();
require_once "../config/db.php";

// ── Auth guard ────────────────────────────────────────────────────
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

// ── Safe lecturer_id resolution ───────────────────────────────────
$session_email = $_SESSION['user_email'] ?? $_SESSION['email'] ?? '';
$lecturer_id   = (int)($_SESSION['user_id'] ?? 0);

if ($session_email) {
    $stmt = $conn->prepare("SELECT id FROM lecturers WHERE email = ? LIMIT 1");
    $stmt->bind_param("s", $session_email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $lecturer_id = (int)$row['id'];
        $_SESSION['user_id'] = $lecturer_id;
    }
}

if (!$lecturer_id) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit();
}

$success = '';

// ── Handle publish / unpublish ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_result_publish'])) {
    $eid     = (int)($_POST['exam_id']       ?? 0);
    $new_val = (int)($_POST['new_published'] ?? 0);
    $new_val = $new_val === 1 ? 1 : 0;

    if ($eid) {
        $chk = $conn->prepare("
            SELECT id FROM exams
            WHERE id = ? AND (lecturer_id = ? OR created_by = ?)
        ");
        $chk->bind_param("iii", $eid, $lecturer_id, $lecturer_id);
        $chk->execute();
        $owns = $chk->get_result()->num_rows > 0;
        $chk->close();

        if ($owns) {
            $stmt = $conn->prepare("
                UPDATE exams SET result_published = ?
                WHERE id = ? AND (lecturer_id = ? OR created_by = ?)
            ");
            $stmt->bind_param("iiii", $new_val, $eid, $lecturer_id, $lecturer_id);
            $stmt->execute();
            $stmt->close();
            $success = $new_val === 1
                ? "Results published — students can now view their marks."
                : "Results hidden from students.";
        }
    }
}

// ── Fetch courses for filter ──────────────────────────────────────
$stmt = $conn->prepare("
    SELECT DISTINCT c.id, c.course_name
    FROM courses c
    JOIN exams e ON e.course_id = c.id
    WHERE e.lecturer_id = ? OR e.created_by = ?
    ORDER BY c.course_name ASC
");
$stmt->bind_param("ii", $lecturer_id, $lecturer_id);
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Fetch exams for publish panel + per-exam analytics ───────────
$stmt = $conn->prepare("
    SELECT
        e.id,
        e.title,
        e.result_published,
        c.course_name,
        COUNT(a.id)           AS total_attempts,
        ROUND(AVG(a.score),1) AS avg_score,
        MAX(a.score)          AS high_score,
        MIN(a.score)          AS low_score,
        SUM(CASE WHEN a.score >= 50 THEN 1 ELSE 0 END) AS passed,
        SUM(CASE WHEN a.score < 50  THEN 1 ELSE 0 END) AS failed
    FROM exams e
    JOIN courses c ON c.id = e.course_id
    LEFT JOIN exam_attempts a ON a.exam_id = e.id AND a.status IN ('completed', 'submitted')
    WHERE e.lecturer_id = ? OR e.created_by = ?
    GROUP BY e.id, e.title, e.result_published, c.course_name
    ORDER BY e.exam_date DESC
");
$stmt->bind_param("ii", $lecturer_id, $lecturer_id);
$stmt->execute();
$exams_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Fetch filters ─────────────────────────────────────────────────
$filter_course = (int)($_GET['course']  ?? 0);
$filter_exam   = (int)($_GET['exam']    ?? 0);
$filter_pf     = trim($_GET['passfail'] ?? '');
$search_name   = trim($_GET['search']   ?? '');
$sort_by       = trim($_GET['sort']     ?? 'date');
$sort_dir      = trim($_GET['dir']      ?? 'desc');
$active_tab    = trim($_GET['tab']      ?? 'flat');

$allowed_sorts = [
    'date'  => 'a.end_time',
    'score' => 'a.score',
    'name'  => 's.name',
];
$sort_col      = $allowed_sorts[$sort_by] ?? 'a.end_time';
$sort_dir_safe = strtolower($sort_dir) === 'asc' ? 'ASC' : 'DESC';

// ── Build attempts query ──────────────────────────────────────────
$sql = "
    SELECT
        a.id                AS attempt_id,
        a.score,
        a.end_time          AS date_submitted,
        a.correct_answers,
        a.wrong_answers,
        a.skipped_answers,
        a.total_questions,
        s.id                AS student_id,
        s.name              AS student_name,
        s.email             AS student_email,
        s.student_number    AS student_number,
        s.program           AS student_program,
        e.id                AS exam_id,
        e.title             AS exam_title,
        e.result_published,
        c.course_name,
        c.id                AS course_id
    FROM exam_attempts a
    JOIN students s ON s.id  = a.student_id
    JOIN exams    e ON e.id  = a.exam_id
    JOIN courses  c ON c.id  = e.course_id
    WHERE (e.lecturer_id = ? OR e.created_by = ?)
      AND a.status IN ('completed', 'submitted')
";

$types  = "ii";
$params = [$lecturer_id, $lecturer_id];

if ($filter_course > 0) {
    $sql     .= " AND c.id = ?";
    $types   .= "i";
    $params[] = $filter_course;
}
if ($filter_exam > 0) {
    $sql     .= " AND e.id = ?";
    $types   .= "i";
    $params[] = $filter_exam;
}
if ($search_name !== '') {
    $like     = "%$search_name%";
    $sql     .= " AND s.name LIKE ?";
    $types   .= "s";
    $params[] = $like;
}
if ($filter_pf === 'pass') {
    $sql .= " AND a.score >= 50";
} elseif ($filter_pf === 'fail') {
    $sql .= " AND a.score < 50";
}

$sql .= " ORDER BY {$sort_col} {$sort_dir_safe}";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$attempts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_attempts = count($attempts);

// ── Aggregate stats (filtered set) ───────────────────────────────
$all_scores = array_column($attempts, 'score');
$avg_score  = $total_attempts ? round(array_sum($all_scores) / $total_attempts, 1) : null;
$high_score = $total_attempts ? max($all_scores) : null;
$low_score  = $total_attempts ? min($all_scores) : null;
$passed     = $total_attempts ? count(array_filter($all_scores, fn($s) => $s >= 50)) : 0;
$failed     = $total_attempts - $passed;

// ── Group attempts by student ─────────────────────────────────────
$students_grouped = [];
foreach ($attempts as $row) {
    $sid = $row['student_id'];
    if (!isset($students_grouped[$sid])) {
        $students_grouped[$sid] = [
            'student_id'      => $sid,
            'student_name'    => $row['student_name'],
            'student_email'   => $row['student_email'],
            'student_number'  => $row['student_number'] ?? '',
            'student_program' => $row['student_program'] ?? '',
            'exams'           => [],
        ];
    }
    $students_grouped[$sid]['exams'][] = $row;
}
usort($students_grouped, fn($a, $b) => strcmp($a['student_name'], $b['student_name']));

// ── CSV Export ────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="results_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['#','Student','Email','Student No.','Program','Exam','Course','Score (%)','Grade','Correct','Wrong','Skipped','Pass/Fail','Published','Submitted']);
    foreach ($attempts as $i => $row) {
        $s = (float)$row['score'];
        $g = $s >= 80 ? 'A' : ($s >= 70 ? 'B' : ($s >= 60 ? 'C' : ($s >= 50 ? 'D' : 'F')));
        fputcsv($out, [
            $i + 1,
            $row['student_name'],
            $row['student_email'],
            $row['student_number'] ?? '',
            $row['student_program'] ?? '',
            $row['exam_title'],
            $row['course_name'],
            number_format($s, 1),
            $g,
            $row['correct_answers'],
            $row['wrong_answers'],
            $row['skipped_answers'],
            $s >= 50 ? 'Pass' : 'Fail',
            $row['result_published'] ? 'Yes' : 'No',
            $row['date_submitted'] ? date('d M Y H:i', strtotime($row['date_submitted'])) : '',
        ]);
    }
    fclose($out);
    exit();
}

// ── Helpers ───────────────────────────────────────────────────────
function grade_label(float $s): string {
    if ($s >= 80) return 'A'; if ($s >= 70) return 'B';
    if ($s >= 60) return 'C'; if ($s >= 50) return 'D'; return 'F';
}
function grade_color(float $s): string {
    if ($s >= 80) return '#16a34a'; if ($s >= 70) return '#2563eb';
    if ($s >= 60) return '#d97706'; if ($s >= 50) return '#f59e0b'; return '#dc2626';
}
function sort_link(string $col, string $label, string $current_sort, string $current_dir): string {
    $new_dir = ($current_sort === $col && $current_dir === 'asc') ? 'desc' : 'asc';
    $icon    = $current_sort === $col ? ($current_dir === 'asc' ? ' ↑' : ' ↓') : '';
    $p       = array_merge($_GET, ['sort' => $col, 'dir' => $new_dir]);
    unset($p['export']);
    return '<a href="results.php?' . http_build_query($p) . '" class="sort-link">' . $label . $icon . '</a>';
}

include "../includes/header.php";
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=DM+Mono:wght@400;500&family=DM+Sans:wght@400;500;600&display=swap');

:root {
    --bg:      #f4f6fb;
    --surface: #ffffff;
    --border:  #e5e9f2;
    --text:    #1a1f2e;
    --muted:   #6b7280;
    --accent:  #2563eb;
    --green:   #16a34a;
    --red:     #dc2626;
    --amber:   #d97706;
    --radius:  14px;
    --sans:    'DM Sans', sans-serif;
    --serif:   'Sora', sans-serif;
    --mono:    'DM Mono', monospace;
    --shadow:  0 2px 16px rgba(30,40,80,.07);
}
*, *::before, *::after { box-sizing: border-box; }
body { background: var(--bg); font-family: var(--sans); color: var(--text); }

.rw { max-width: 1260px; margin: 0 auto; padding: 2rem 1.25rem 4rem; }

.page-hd { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.75rem; }
.page-hd h1 { font-family: var(--serif); font-size: 1.55rem; font-weight: 700; margin: 0; }
.page-hd p  { font-size: .83rem; color: var(--muted); margin: .2rem 0 0; }

.flash { display: flex; align-items: center; gap: .6rem; padding: .8rem 1.1rem; border-radius: 10px; font-size: .85rem; margin-bottom: 1.25rem; border: 1px solid; }
.flash-success { background: #d1fae5; border-color: #6ee7b7; color: #065f46; }

/* ── Summary cards ── */
.sum-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px,1fr)); gap: .85rem; margin-bottom: 1.75rem; }
.sum-card { border-radius: var(--radius); padding: 1.1rem 1.2rem; color: #fff; position: relative; overflow: hidden; }
.sum-card::after { content:''; position:absolute; right:-12px; top:-12px; width:70px; height:70px; border-radius:50%; background:rgba(255,255,255,.1); }
.sum-num   { font-family: var(--mono); font-size: 2rem; font-weight: 700; line-height: 1; }
.sum-label { font-size: .75rem; opacity: .88; margin-top: 5px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; }

/* ── Analytics accordion ── */
.analytics-panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); margin-bottom: 1.5rem; box-shadow: var(--shadow); overflow: hidden; }
.analytics-toggle { width:100%; background:none; border:none; padding:1rem 1.4rem; display:flex; align-items:center; justify-content:space-between; cursor:pointer; font-family:var(--serif); font-size:.97rem; font-weight:700; color:var(--text); }
.analytics-toggle .toggle-icon { transition: transform .25s; }
.analytics-toggle.open .toggle-icon { transform: rotate(180deg); }
.analytics-body { display: none; padding: 0 1.4rem 1.25rem; }
.analytics-body.show { display: block; }
.atbl { width:100%; border-collapse:collapse; font-size:.83rem; }
.atbl th { padding:.5rem .75rem; text-align:left; color:var(--muted); font-weight:700; font-size:.73rem; text-transform:uppercase; letter-spacing:.06em; border-bottom:1px solid var(--border); }
.atbl td { padding:.65rem .75rem; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
.atbl tr:last-child td { border-bottom:none; }
.atbl tr:hover td { background:#f8faff; }
.mini-bar-bg { width:80px; height:5px; background:#e9ecef; border-radius:3px; display:inline-block; vertical-align:middle; }
.mini-bar-fill { height:100%; border-radius:3px; display:block; }

/* ── Publish panel ── */
.publish-panel { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem 1.5rem; box-shadow: var(--shadow); margin-bottom: 1.5rem; }
.publish-panel h6 { font-family: var(--serif); font-weight: 700; font-size: .95rem; color: var(--text); margin-bottom: .5rem; }
.pub-scroll { max-height: 260px; overflow-y: auto; padding-right: .25rem; }
.publish-row { display:flex; align-items:center; justify-content:space-between; padding:.55rem .75rem; border-radius:10px; border:1px solid var(--border); margin-bottom:.5rem; gap:.75rem; flex-wrap:wrap; }
.publish-row:last-child { margin-bottom: 0; }
.pub-exam-name   { font-size:.85rem; font-weight:600; color:var(--text); flex:1; }
.pub-course-name { font-size:.73rem; color:var(--muted); display:block; }
.pub-badge { font-size:.71rem; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap; }
.badge-pub  { background:#d1fae5; color:#065f46; }
.badge-hide { background:#f3f4f6; color:#6b7280; }
.btn-publish   { background:var(--green); border:none; color:#fff; font-size:.78rem; padding:.3rem .75rem; border-radius:7px; cursor:pointer; transition:opacity .15s; display:inline-flex; align-items:center; gap:.3rem; }
.btn-unpublish { background:var(--red);   border:none; color:#fff; font-size:.78rem; padding:.3rem .75rem; border-radius:7px; cursor:pointer; transition:opacity .15s; display:inline-flex; align-items:center; gap:.3rem; }
.btn-publish:hover, .btn-unpublish:hover { opacity:.85; }

/* ── Filter card ── */
.filter-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:1.1rem 1.5rem; box-shadow:var(--shadow); margin-bottom:1.5rem; }
.filter-row  { display:flex; gap:.75rem; flex-wrap:wrap; align-items:flex-end; }
.fg { display:flex; flex-direction:column; gap:.3rem; flex:1; min-width:160px; }
.fg label { font-size:.75rem; font-weight:700; color:var(--muted); text-transform:uppercase; letter-spacing:.05em; }
.fg input, .fg select { border:1px solid var(--border); border-radius:8px; padding:.45rem .75rem; font-size:.85rem; outline:none; transition:border-color .18s; font-family:var(--sans); background:#fff; }
.fg input:focus, .fg select:focus { border-color:var(--accent); }
.filter-actions { display:flex; gap:.5rem; align-items:flex-end; }
.btn-filter  { background:var(--accent); color:#fff; border:none; border-radius:8px; padding:.5rem 1.1rem; font-size:.85rem; font-weight:600; cursor:pointer; display:flex; align-items:center; gap:.35rem; transition:opacity .15s; }
.btn-filter:hover { opacity:.88; }
.btn-clear   { background:#f3f4f6; color:var(--muted); border:1px solid var(--border); border-radius:8px; padding:.5rem .85rem; font-size:.85rem; cursor:pointer; text-decoration:none; display:flex; align-items:center; }
.btn-export  { background:#065f46; color:#fff; border:none; border-radius:8px; padding:.5rem 1rem; font-size:.83rem; font-weight:600; cursor:pointer; text-decoration:none; display:flex; align-items:center; gap:.35rem; white-space:nowrap; transition:opacity .15s; }
.btn-export:hover { opacity:.88; color:#fff; }

/* ── Tab switcher ── */
.tab-bar { display:flex; gap:.3rem; margin-bottom:0; border-bottom:2px solid var(--border); padding:0 1.4rem; background:var(--surface); border-radius:var(--radius) var(--radius) 0 0; border:1px solid var(--border); border-bottom:none; }
.tab-btn { background:none; border:none; padding:.75rem 1.1rem; font-size:.85rem; font-weight:600; color:var(--muted); cursor:pointer; border-bottom:3px solid transparent; margin-bottom:-1px; transition:all .18s; display:flex; align-items:center; gap:.4rem; font-family:var(--sans); }
.tab-btn:hover { color:var(--text); }
.tab-btn.active { color:var(--accent); border-bottom-color:var(--accent); }
.tab-panel { display:none; }
.tab-panel.active { display:block; }

/* ── Results card ── */
.results-card { background:var(--surface); border:1px solid var(--border); border-top:none; border-radius:0 0 var(--radius) var(--radius); box-shadow:var(--shadow); overflow:hidden; }
.tbl-hdr { padding:.85rem 1.4rem; border-bottom:1px solid var(--border); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.5rem; }
.tbl-hdr h6 { font-family:var(--serif); font-weight:700; font-size:.95rem; margin:0; }
.tbl-count { font-size:.78rem; color:var(--muted); font-family:var(--mono); }

/* ── Flat table ── */
.rtable { width:100%; border-collapse:collapse; font-size:.86rem; }
.rtable thead tr { background:#f8f9fc; }
.rtable th { padding:.7rem 1rem; text-align:left; font-weight:700; font-size:.73rem; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); border-bottom:1px solid var(--border); white-space:nowrap; }
.rtable td { padding:.75rem 1rem; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
.rtable tbody tr:last-child td { border-bottom:none; }
.rtable tbody tr:hover td { background:#f8faff; }

.sort-link { color:inherit; text-decoration:none; white-space:nowrap; }
.sort-link:hover { color:var(--accent); }

.score-wrap { display:flex; align-items:center; gap:.5rem; min-width:120px; }
.score-bar-bg   { flex:1; height:5px; background:#e9ecef; border-radius:3px; overflow:hidden; }
.score-bar-fill { height:100%; border-radius:3px; }
.score-pct { font-family:var(--mono); font-weight:700; font-size:.83rem; min-width:42px; text-align:right; }

.grade-pill { display:inline-block; padding:2px 10px; border-radius:20px; font-size:.75rem; font-weight:700; font-family:var(--mono); }
.pf-pill    { display:inline-block; padding:2px 9px; border-radius:20px; font-size:.72rem; font-weight:700; }
.pf-pass { background:#d1fae5; color:#065f46; }
.pf-fail { background:#fee2e2; color:#991b1b; }

.breakdown { display:flex; gap:.4rem; font-size:.76rem; font-family:var(--mono); }
.bc { color:var(--green); } .bw { color:var(--red); } .bs { color:#9ca3af; }

.pub-dot { display:inline-flex; align-items:center; gap:.3rem; font-size:.75rem; font-weight:700; }
.pub-dot.yes { color:var(--green); } .pub-dot.no { color:var(--amber); }

.btn-view { display:inline-flex; align-items:center; gap:.3rem; padding:.28rem .7rem; border-radius:7px; border:1px solid var(--border); background:transparent; color:var(--muted); font-size:.76rem; text-decoration:none; transition:all .18s; white-space:nowrap; }
.btn-view:hover { border-color:var(--accent); color:var(--accent); background:#eef4ff; }

.empty-state { text-align:center; padding:3.5rem 1rem; color:var(--muted); }
.empty-state i { font-size:2.5rem; display:block; margin-bottom:.6rem; opacity:.3; }

/* ── By-Student table ── */
.stbl { width:100%; border-collapse:collapse; font-size:.86rem; }
.stbl thead tr { background:#f8f9fc; }
.stbl th { padding:.7rem 1rem; text-align:left; font-weight:700; font-size:.73rem; text-transform:uppercase; letter-spacing:.06em; color:var(--muted); border-bottom:1px solid var(--border); white-space:nowrap; }
.stbl td { padding:.75rem 1rem; border-bottom:1px solid #f3f4f6; vertical-align:middle; }

.student-row { cursor:pointer; transition:background .15s; }
.student-row:hover td { background:#eef4ff !important; }
.student-row.expanded td { background:#f0f7ff; }
.student-toggle-icon { display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:6px; background:#e9ecef; color:var(--muted); font-size:.75rem; transition:transform .2s, background .15s; margin-right:.5rem; }
.student-row.expanded .student-toggle-icon { transform:rotate(90deg); background:#dbeafe; color:var(--accent); }

.stu-avatar { width:34px; height:34px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700; color:#fff; flex-shrink:0; }

.mini-stat { font-family:var(--mono); font-size:.8rem; font-weight:700; color:var(--text); }
.mini-pill { display:inline-flex; align-items:center; gap:.25rem; padding:2px 9px; border-radius:20px; font-size:.72rem; font-weight:700; }

.exam-sub-row { display:none; }
.exam-sub-row.show { display:table-row; }
.exam-sub-row td { background:#f8fbff; padding:.6rem 1rem .6rem 3.5rem; border-bottom:1px solid #edf0f7; font-size:.83rem; }
.exam-sub-row:last-of-type td { border-bottom:2px solid var(--border); }
.exam-sub-header td { background:#edf2fb; padding:.45rem 1rem .45rem 3.5rem; font-size:.7rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:var(--muted); border-bottom:1px solid #e5e9f2; }

.btn-profile { display:inline-flex; align-items:center; gap:.3rem; padding:.28rem .7rem; border-radius:7px; border:none; background:var(--accent); color:#fff; font-size:.76rem; cursor:pointer; transition:opacity .15s; white-space:nowrap; font-family:var(--sans); }
.btn-profile:hover { opacity:.85; }

/* ── Student Profile Modal ── */
.modal-overlay { position:fixed; inset:0; background:rgba(10,15,35,.5); backdrop-filter:blur(4px); z-index:9999; display:none; align-items:center; justify-content:center; padding:1rem; }
.modal-overlay.open { display:flex; animation:fadeIn .2s ease; }
@keyframes fadeIn { from{opacity:0} to{opacity:1} }
.modal-box { background:var(--surface); border-radius:18px; box-shadow:0 20px 60px rgba(10,15,35,.18); width:100%; max-width:780px; max-height:90vh; display:flex; flex-direction:column; overflow:hidden; animation:slideUp .22s ease; }
@keyframes slideUp { from{transform:translateY(24px);opacity:0} to{transform:translateY(0);opacity:1} }

.modal-hdr { padding:1.25rem 1.5rem; border-bottom:1px solid var(--border); display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-shrink:0; }
.modal-hdr-left { display:flex; align-items:center; gap:.85rem; }
.modal-stu-info h4 { font-family:var(--serif); font-weight:700; font-size:1.05rem; margin:0 0 2px; }
.modal-stu-info p  { font-size:.78rem; color:var(--muted); margin:0; }
.modal-close { background:#f3f4f6; border:none; width:32px; height:32px; border-radius:8px; cursor:pointer; font-size:1rem; color:var(--muted); display:flex; align-items:center; justify-content:center; transition:background .15s; }
.modal-close:hover { background:#e5e9f2; }

.modal-stats { display:grid; grid-template-columns:repeat(4,1fr); gap:.75rem; padding:1rem 1.5rem; border-bottom:1px solid var(--border); flex-shrink:0; }
.mstat { text-align:center; }
.mstat-num   { font-family:var(--mono); font-size:1.4rem; font-weight:700; line-height:1; }
.mstat-label { font-size:.7rem; color:var(--muted); text-transform:uppercase; font-weight:700; letter-spacing:.05em; margin-top:3px; }

.modal-body { overflow-y:auto; padding:1rem 1.5rem 1.5rem; flex:1; }
.modal-body h6 { font-family:var(--serif); font-size:.88rem; font-weight:700; margin:0 0 .75rem; color:var(--muted); text-transform:uppercase; letter-spacing:.06em; }

.exam-card { border:1px solid var(--border); border-radius:12px; padding:1rem 1.15rem; margin-bottom:.75rem; transition:border-color .15s; }
.exam-card:last-child { margin-bottom:0; }
.exam-card:hover { border-color:#b3caf7; }
.exam-card-top { display:flex; align-items:flex-start; justify-content:space-between; gap:.75rem; margin-bottom:.6rem; }
.exam-card-title { font-weight:700; font-size:.9rem; margin:0 0 2px; }
.exam-card-course { font-size:.75rem; color:var(--muted); }
.exam-card-right { display:flex; flex-direction:column; align-items:flex-end; gap:.3rem; flex-shrink:0; }
.exam-card-bottom { display:flex; align-items:center; gap:1.25rem; flex-wrap:wrap; }
.exam-score-big { font-family:var(--mono); font-size:1.5rem; font-weight:700; line-height:1; }
.exam-score-bar { flex:1; height:6px; background:#e9ecef; border-radius:3px; overflow:hidden; min-width:80px; }
.exam-score-bar-fill { height:100%; border-radius:3px; }
.exam-meta { font-size:.75rem; color:var(--muted); display:flex; align-items:center; gap:.3rem; }
.exam-bd { display:flex; gap:.6rem; }
.exam-bd span { font-family:var(--mono); font-size:.78rem; }

@media (max-width:768px) {
    .rtable thead { display:none; }
    .rtable, .rtable tbody, .rtable tr, .rtable td { display:block; width:100%; }
    .rtable tr { margin-bottom:12px; border-bottom:2px solid var(--border); }
    .rtable td { text-align:right; padding-left:50%; position:relative; border-bottom:none; }
    .rtable td::before { content:attr(data-label); position:absolute; left:1rem; width:45%; font-weight:700; text-align:left; color:var(--muted); font-size:.75rem; }
    .publish-row { flex-direction:column; align-items:flex-start; }
    .fg { min-width:100%; }
    .tab-bar { padding:0 .75rem; }
    .modal-stats { grid-template-columns:repeat(2,1fr); }
    .modal-box { max-height:95vh; }
    .stbl thead { display:none; }
    .stbl, .stbl tbody, .stbl tr, .stbl td { display:block; width:100%; }
}
</style>

<div class="rw">

    <!-- Page header -->
    <div class="page-hd">
        <div>
            <h1><i class="bi bi-bar-chart-fill" style="color:var(--accent)"></i> Student Results</h1>
            <p>View, filter, export and publish results — lecturer view</p>
        </div>
        <a href="dashboard.php" class="btn-clear" style="text-decoration:none;color:var(--muted)">
            <i class="bi bi-arrow-left me-1"></i> Dashboard
        </a>
    </div>

    <?php if ($success): ?>
    <div class="flash flash-success">
        <i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>

    <!-- Summary cards -->
    <div class="sum-grid">
        <div class="sum-card" style="background:#2563eb">
            <div class="sum-num"><?= $total_attempts ?></div>
            <div class="sum-label">Total Attempts</div>
        </div>
        <div class="sum-card" style="background:#0891b2">
            <div class="sum-num"><?= count($students_grouped) ?></div>
            <div class="sum-label">Students</div>
        </div>
        <div class="sum-card" style="background:#16a34a">
            <div class="sum-num"><?= $avg_score !== null ? $avg_score.'%' : '—' ?></div>
            <div class="sum-label">Average Score</div>
        </div>
        <div class="sum-card" style="background:#d97706">
            <div class="sum-num"><?= $high_score !== null ? $high_score.'%' : '—' ?></div>
            <div class="sum-label">Highest Score</div>
        </div>
        <div class="sum-card" style="background:#dc2626">
            <div class="sum-num"><?= $low_score !== null ? $low_score.'%' : '—' ?></div>
            <div class="sum-label">Lowest Score</div>
        </div>
        <div class="sum-card" style="background:#065f46">
            <div class="sum-num"><?= $passed ?></div>
            <div class="sum-label">Passed</div>
        </div>
        <div class="sum-card" style="background:#7c3aed">
            <div class="sum-num"><?= $failed ?></div>
            <div class="sum-label">Failed</div>
        </div>
    </div>

    <!-- Per-exam analytics accordion -->
    <?php if (!empty($exams_list)): ?>
    <div class="analytics-panel">
        <button class="analytics-toggle" id="analyticsToggle" onclick="toggleAnalytics()">
            <span><i class="bi bi-graph-up-arrow" style="color:var(--accent);margin-right:.5rem"></i>Per-Exam Analytics</span>
            <i class="bi bi-chevron-down toggle-icon"></i>
        </button>
        <div class="analytics-body" id="analyticsBody">
            <div style="overflow-x:auto">
            <table class="atbl">
                <thead>
                    <tr>
                        <th>Exam</th>
                        <th>Course</th>
                        <th>Attempts</th>
                        <th>Avg Score</th>
                        <th>Highest</th>
                        <th>Lowest</th>
                        <th>Pass Rate</th>
                        <th>Published</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($exams_list as $ex):
                    $ea    = (int)$ex['total_attempts'];
                    $eavg  = $ex['avg_score']  !== null ? (float)$ex['avg_score']  : null;
                    $ehigh = $ex['high_score'] !== null ? (float)$ex['high_score'] : null;
                    $elow  = $ex['low_score']  !== null ? (float)$ex['low_score']  : null;
                    $ep    = (int)$ex['passed'];
                    $prate = $ea > 0 ? round(($ep / $ea) * 100) : 0;
                    $ac    = $eavg !== null ? grade_color($eavg) : '#9ca3af';
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($ex['title']) ?></strong></td>
                    <td style="color:var(--muted)"><?= htmlspecialchars($ex['course_name']) ?></td>
                    <td><span style="font-family:var(--mono);font-weight:700"><?= $ea ?></span></td>
                    <td>
                        <?php if ($eavg !== null): ?>
                        <span style="font-family:var(--mono);font-weight:700;color:<?= $ac ?>"><?= $eavg ?>%</span>
                        <span class="mini-bar-bg" style="margin-left:.4rem">
                            <span class="mini-bar-fill" style="width:<?= min($eavg,100) ?>%;background:<?= $ac ?>"></span>
                        </span>
                        <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($ehigh !== null): ?>
                        <span style="font-family:var(--mono);color:var(--green);font-weight:700"><?= $ehigh ?>%</span>
                        <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($elow !== null): ?>
                        <span style="font-family:var(--mono);color:var(--red);font-weight:700"><?= $elow ?>%</span>
                        <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
                    </td>
                    <td>
                        <?php if ($ea > 0): ?>
                        <span class="pf-pill <?= $prate >= 50 ? 'pf-pass' : 'pf-fail' ?>"><?= $prate ?>%</span>
                        <?php else: ?><span style="color:var(--muted)">—</span><?php endif; ?>
                    </td>
                    <td>
                        <span class="pub-badge <?= $ex['result_published'] ? 'badge-pub' : 'badge-hide' ?>">
                            <?= $ex['result_published'] ? '✓ Published' : 'Hidden' ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Publish panel -->
    <div class="publish-panel">
        <h6><i class="bi bi-megaphone-fill" style="color:var(--green);margin-right:.4rem"></i>Publish Results to Students</h6>
        <p style="font-size:.8rem;color:var(--muted);margin-bottom:.85rem">Students only see their result after you publish it.</p>
        <?php if (empty($exams_list)): ?>
            <p style="font-size:.82rem;color:var(--muted);font-style:italic">No exams found.</p>
        <?php else: ?>
        <div class="pub-scroll">
            <?php foreach ($exams_list as $ex): ?>
            <div class="publish-row">
                <div style="flex:1;min-width:0">
                    <div class="pub-exam-name"><?= htmlspecialchars($ex['title']) ?></div>
                    <span class="pub-course-name"><?= htmlspecialchars($ex['course_name']) ?></span>
                </div>
                <span class="pub-badge <?= $ex['result_published'] ? 'badge-pub' : 'badge-hide' ?>">
                    <?= $ex['result_published'] ? '✓ Published' : 'Hidden' ?>
                </span>
                <form method="POST" style="display:inline;flex-shrink:0">
                    <input type="hidden" name="exam_id"       value="<?= (int)$ex['id'] ?>">
                    <input type="hidden" name="new_published" value="<?= $ex['result_published'] ? 0 : 1 ?>">
                    <button type="submit" name="toggle_result_publish"
                            class="<?= $ex['result_published'] ? 'btn-unpublish' : 'btn-publish' ?>">
                        <i class="bi bi-<?= $ex['result_published'] ? 'eye-slash' : 'megaphone' ?>"></i>
                        <?= $ex['result_published'] ? 'Hide' : 'Publish' ?>
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Filter bar -->
    <div class="filter-card">
        <form method="GET" id="filterForm">
            <div class="filter-row">
                <div class="fg">
                    <label><i class="bi bi-search"></i> Search Student</label>
                    <input type="text" name="search" placeholder="Student name..."
                           value="<?= htmlspecialchars($search_name) ?>">
                </div>
                <div class="fg">
                    <label><i class="bi bi-book"></i> Course</label>
                    <select name="course">
                        <option value="">All Courses</option>
                        <?php foreach ($courses as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $filter_course == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['course_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg">
                    <label><i class="bi bi-journal-check"></i> Exam</label>
                    <select name="exam">
                        <option value="">All Exams</option>
                        <?php foreach ($exams_list as $ex): ?>
                        <option value="<?= $ex['id'] ?>" <?= $filter_exam == $ex['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ex['title']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fg" style="max-width:140px">
                    <label><i class="bi bi-funnel"></i> Pass / Fail</label>
                    <select name="passfail">
                        <option value="">All</option>
                        <option value="pass" <?= $filter_pf === 'pass' ? 'selected' : '' ?>>Pass Only</option>
                        <option value="fail" <?= $filter_pf === 'fail' ? 'selected' : '' ?>>Fail Only</option>
                    </select>
                </div>
                <input type="hidden" name="sort" value="<?= htmlspecialchars($sort_by) ?>">
                <input type="hidden" name="dir"  value="<?= htmlspecialchars($sort_dir) ?>">
                <input type="hidden" name="tab"  value="<?= htmlspecialchars($active_tab) ?>">
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">
                        <i class="bi bi-funnel-fill"></i> Filter
                    </button>
                    <a href="results.php" class="btn-clear" title="Clear filters">
                        <i class="bi bi-x-lg"></i>
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Tab bar -->
    <div class="tab-bar">
        <button class="tab-btn <?= $active_tab === 'flat' ? 'active' : '' ?>"
                onclick="switchTab('flat')">
            <i class="bi bi-table"></i> All Attempts
            <span style="background:#e9ecef;border-radius:20px;padding:1px 8px;font-size:.72rem;font-family:var(--mono)"><?= $total_attempts ?></span>
        </button>
        <button class="tab-btn <?= $active_tab === 'students' ? 'active' : '' ?>"
                onclick="switchTab('students')">
            <i class="bi bi-people-fill"></i> By Student
            <span style="background:#e9ecef;border-radius:20px;padding:1px 8px;font-size:.72rem;font-family:var(--mono)"><?= count($students_grouped) ?></span>
        </button>
    </div>

    <!-- TAB 1: All Attempts -->
    <div class="tab-panel results-card <?= $active_tab === 'flat' ? 'active' : '' ?>" id="tab-flat">
        <div class="tbl-hdr">
            <h6><i class="bi bi-table" style="color:var(--accent);margin-right:.4rem"></i>All Attempts</h6>
            <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                <span class="tbl-count"><?= $total_attempts ?> record<?= $total_attempts !== 1 ? 's' : '' ?></span>
                <?php $ep = array_merge($_GET, ['export' => 'csv']); unset($ep['tab']); ?>
                <a href="results.php?<?= http_build_query($ep) ?>" class="btn-export">
                    <i class="bi bi-download"></i> Export CSV
                </a>
            </div>
        </div>
        <div style="overflow-x:auto">
        <table class="rtable">
            <thead>
                <tr>
                    <th>#</th>
                    <th><?= sort_link('name',  'Student',   $sort_by, $sort_dir) ?></th>
                    <th>Exam</th>
                    <th>Course</th>
                    <th><?= sort_link('score', 'Score',     $sort_by, $sort_dir) ?></th>
                    <th>Grade</th>
                    <th>P/F</th>
                    <th>Breakdown</th>
                    <th>Published</th>
                    <th><?= sort_link('date',  'Submitted', $sort_by, $sort_dir) ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($attempts)): ?>
            <tr><td colspan="11">
                <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <p>No results found<?= ($search_name || $filter_course || $filter_exam || $filter_pf) ? ' — try clearing the filters' : '' ?>.</p>
                </div>
            </td></tr>
            <?php else: ?>
            <?php foreach ($attempts as $i => $row):
                $score = (float)$row['score'];
                $gc    = grade_color($score);
                $grade = grade_label($score);
                $pub   = (int)$row['result_published'];
                $pass  = $score >= 50;
            ?>
            <tr>
                <td data-label="#" style="color:var(--muted);font-size:.8rem"><?= $i + 1 ?></td>
                <td data-label="Student">
                    <strong style="font-size:.87rem"><?= htmlspecialchars($row['student_name']) ?></strong><br>
                    <span style="font-size:.75rem;color:var(--muted)"><?= htmlspecialchars($row['student_email']) ?></span><br>
                    <?php if (!empty($row['student_number'])): ?>
                    <span style="font-size:.72rem;color:var(--accent);font-family:var(--mono)"><?= htmlspecialchars($row['student_number']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($row['student_program'])): ?>
                    <span style="font-size:.72rem;color:var(--muted)"> &middot; <?= htmlspecialchars($row['student_program']) ?></span>
                    <?php endif; ?>
                </td>
                <td data-label="Exam" style="font-size:.85rem"><?= htmlspecialchars($row['exam_title']) ?></td>
                <td data-label="Course" style="font-size:.82rem;color:var(--muted)"><?= htmlspecialchars($row['course_name']) ?></td>
                <td data-label="Score">
                    <div class="score-wrap">
                        <div class="score-bar-bg"><div class="score-bar-fill" style="width:<?= min($score,100) ?>%;background:<?= $gc ?>"></div></div>
                        <span class="score-pct" style="color:<?= $gc ?>"><?= number_format($score,1) ?>%</span>
                    </div>
                </td>
                <td data-label="Grade">
                    <span class="grade-pill" style="color:<?= $gc ?>;background:<?= $gc ?>18;border:1px solid <?= $gc ?>33"><?= $grade ?></span>
                </td>
                <td data-label="P/F">
                    <span class="pf-pill <?= $pass ? 'pf-pass' : 'pf-fail' ?>"><?= $pass ? 'Pass' : 'Fail' ?></span>
                </td>
                <td data-label="Breakdown">
                    <div class="breakdown">
                        <span class="bc"><i class="bi bi-check-lg"></i><?= (int)$row['correct_answers'] ?></span>
                        <span class="bw"><i class="bi bi-x-lg"></i><?= (int)$row['wrong_answers'] ?></span>
                        <span class="bs"><i class="bi bi-dash"></i><?= (int)$row['skipped_answers'] ?></span>
                    </div>
                </td>
                <td data-label="Published">
                    <?php if ($pub): ?>
                    <span class="pub-dot yes"><i class="bi bi-check-circle-fill"></i> Yes</span>
                    <?php else: ?>
                    <span class="pub-dot no"><i class="bi bi-hourglass-split"></i> Pending</span>
                    <?php endif; ?>
                </td>
                <td data-label="Submitted" style="font-size:.8rem;color:var(--muted);white-space:nowrap">
                    <?= $row['date_submitted'] ? date('d M Y, H:i', strtotime($row['date_submitted'])) : '—' ?>
                </td>
                <td>
                    <a href="student_result_detail.php?attempt_id=<?= (int)$row['attempt_id'] ?>" class="btn-view">
                        <i class="bi bi-eye"></i> View
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>

    <!-- TAB 2: By Student -->
    <div class="tab-panel results-card <?= $active_tab === 'students' ? 'active' : '' ?>" id="tab-students">
        <div class="tbl-hdr">
            <h6><i class="bi bi-people-fill" style="color:var(--accent);margin-right:.4rem"></i>Results by Student</h6>
            <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
                <span class="tbl-count"><?= count($students_grouped) ?> student<?= count($students_grouped) !== 1 ? 's' : '' ?></span>
                <button onclick="expandAll()" style="background:#f3f4f6;border:1px solid var(--border);border-radius:7px;padding:.3rem .75rem;font-size:.78rem;cursor:pointer;color:var(--muted)">
                    <i class="bi bi-arrows-expand"></i> Expand All
                </button>
                <button onclick="collapseAll()" style="background:#f3f4f6;border:1px solid var(--border);border-radius:7px;padding:.3rem .75rem;font-size:.78rem;cursor:pointer;color:var(--muted)">
                    <i class="bi bi-arrows-collapse"></i> Collapse All
                </button>
            </div>
        </div>
        <div style="overflow-x:auto">
        <?php if (empty($students_grouped)): ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <p>No results found<?= ($search_name || $filter_course || $filter_exam || $filter_pf) ? ' — try clearing the filters' : '' ?>.</p>
        </div>
        <?php else: ?>
        <table class="stbl">
            <thead>
                <tr>
                    <th style="width:36px"></th>
                    <th>Student</th>
                    <th>Exams Taken</th>
                    <th>Avg Score</th>
                    <th>Best Score</th>
                    <th>Passed</th>
                    <th>Failed</th>
                    <th>Overall</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php
            $avatarColors = ['#2563eb','#7c3aed','#0891b2','#d97706','#065f46','#be185d','#dc2626','#16a34a'];
            $ci = 0;
            foreach ($students_grouped as $stu):
                $sid        = $stu['student_id'];
                $s_exams    = $stu['exams'];
                $s_scores   = array_column($s_exams, 'score');
                $s_total    = count($s_exams);
                $s_avg      = round(array_sum($s_scores) / $s_total, 1);
                $s_best     = max($s_scores);
                $s_passed   = count(array_filter($s_scores, fn($x) => $x >= 50));
                $s_failed   = $s_total - $s_passed;
                $s_gc       = grade_color($s_avg);
                $avcolor    = $avatarColors[$ci % count($avatarColors)];
                $initials   = implode('', array_map(fn($w) => strtoupper($w[0]), array_slice(explode(' ', trim($stu['student_name'])), 0, 2)));
                $ci++;

                $modal_data = json_encode([
                    'id'     => $sid,
                    'name'   => $stu['student_name'],
                    'email'  => $stu['student_email'],
                    'number' => $stu['student_number'] ?? '',
                    'prog'   => $stu['student_program'] ?? '',
                    'avg'    => $s_avg,
                    'best'   => $s_best,
                    'total'  => $s_total,
                    'pass'   => $s_passed,
                    'fail'   => $s_failed,
                    'color'  => $avcolor,
                    'init'   => $initials,
                    'exams'  => array_map(fn($e) => [
                        'attempt_id'       => $e['attempt_id'],
                        'exam_title'       => $e['exam_title'],
                        'course_name'      => $e['course_name'],
                        'score'            => (float)$e['score'],
                        'correct'          => (int)$e['correct_answers'],
                        'wrong'            => (int)$e['wrong_answers'],
                        'skipped'          => (int)$e['skipped_answers'],
                        'result_published' => (int)$e['result_published'],
                        'date_submitted'   => $e['date_submitted'] ?? '',
                    ], $s_exams),
                ], JSON_HEX_APOS | JSON_HEX_QUOT);
            ?>

            <tr class="student-row" id="sr-<?= $sid ?>" onclick="toggleStudent(<?= $sid ?>)">
                <td>
                    <span class="student-toggle-icon" id="icon-<?= $sid ?>">
                        <i class="bi bi-chevron-right"></i>
                    </span>
                </td>
                <td>
                    <div style="display:flex;align-items:center;gap:.65rem">
                        <span class="stu-avatar" style="background:<?= $avcolor ?>"><?= htmlspecialchars($initials) ?></span>
                        <div>
                            <div style="font-weight:700;font-size:.88rem"><?= htmlspecialchars($stu['student_name']) ?></div>
                            <div style="font-size:.74rem;color:var(--muted)"><?= htmlspecialchars($stu['student_email']) ?></div>
                            <?php if (!empty($stu['student_number'])): ?>
                            <div style="font-size:.72rem;color:var(--accent);font-family:var(--mono)"><?= htmlspecialchars($stu['student_number']) ?> &middot; <?= htmlspecialchars($stu['student_program']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </td>
                <td>
                    <span class="mini-stat"><?= $s_total ?></span>
                    <span style="font-size:.73rem;color:var(--muted)"> exam<?= $s_total !== 1 ? 's' : '' ?></span>
                </td>
                <td>
                    <span style="font-family:var(--mono);font-weight:700;color:<?= $s_gc ?>;font-size:.88rem"><?= $s_avg ?>%</span>
                    <div style="width:60px;height:4px;background:#e9ecef;border-radius:3px;margin-top:3px;overflow:hidden;display:inline-block;vertical-align:middle;margin-left:.4rem">
                        <div style="width:<?= min($s_avg,100) ?>%;height:100%;background:<?= $s_gc ?>;border-radius:3px"></div>
                    </div>
                </td>
                <td>
                    <span style="font-family:var(--mono);font-weight:700;color:var(--green)"><?= $s_best ?>%</span>
                </td>
                <td><span class="mini-pill pf-pass"><?= $s_passed ?></span></td>
                <td>
                    <?php if ($s_failed > 0): ?>
                    <span class="mini-pill pf-fail"><?= $s_failed ?></span>
                    <?php else: ?>
                    <span style="color:var(--muted);font-size:.8rem">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php $overall_grade = grade_label($s_avg); $ogc = grade_color($s_avg); ?>
                    <span class="grade-pill" style="color:<?= $ogc ?>;background:<?= $ogc ?>18;border:1px solid <?= $ogc ?>33"><?= $overall_grade ?></span>
                </td>
                <td onclick="event.stopPropagation()">
                    <button class="btn-profile" onclick="openProfile(<?= htmlspecialchars($modal_data, ENT_QUOTES) ?>)">
                        <i class="bi bi-person-lines-fill"></i> Profile
                    </button>
                </td>
            </tr>

            <tr class="exam-sub-row exam-sub-header" id="subhdr-<?= $sid ?>">
                <td colspan="9">
                    EXAM &nbsp;·&nbsp; COURSE &nbsp;·&nbsp; SCORE &nbsp;·&nbsp; GRADE &nbsp;·&nbsp; PASS/FAIL &nbsp;·&nbsp; CORRECT / WRONG / SKIPPED &nbsp;·&nbsp; PUBLISHED &nbsp;·&nbsp; DATE SUBMITTED
                </td>
            </tr>

            <?php foreach ($s_exams as $ex):
                $escore = (float)$ex['score'];
                $egc    = grade_color($escore);
                $egrade = grade_label($escore);
                $epass  = $escore >= 50;
                $epub   = (int)$ex['result_published'];
            ?>
            <tr class="exam-sub-row" id="subrow-<?= $sid ?>">
                <td colspan="9" style="padding:.65rem 1rem .65rem 3.5rem;">
                    <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
                        <div style="flex:2;min-width:140px">
                            <div style="font-weight:700;font-size:.85rem"><?= htmlspecialchars($ex['exam_title']) ?></div>
                            <div style="font-size:.73rem;color:var(--muted)"><?= htmlspecialchars($ex['course_name']) ?></div>
                        </div>
                        <div style="display:flex;align-items:center;gap:.5rem;flex:1;min-width:130px">
                            <div style="flex:1;height:5px;background:#e9ecef;border-radius:3px;overflow:hidden">
                                <div style="width:<?= min($escore,100) ?>%;height:100%;background:<?= $egc ?>;border-radius:3px"></div>
                            </div>
                            <span style="font-family:var(--mono);font-weight:700;color:<?= $egc ?>;font-size:.85rem;min-width:42px"><?= number_format($escore,1) ?>%</span>
                        </div>
                        <div>
                            <span class="grade-pill" style="color:<?= $egc ?>;background:<?= $egc ?>18;border:1px solid <?= $egc ?>33"><?= $egrade ?></span>
                        </div>
                        <div>
                            <span class="pf-pill <?= $epass ? 'pf-pass' : 'pf-fail' ?>"><?= $epass ? 'Pass' : 'Fail' ?></span>
                        </div>
                        <div class="breakdown">
                            <span class="bc" title="Correct"><i class="bi bi-check-lg"></i><?= (int)$ex['correct_answers'] ?></span>
                            <span class="bw" title="Wrong"><i class="bi bi-x-lg"></i><?= (int)$ex['wrong_answers'] ?></span>
                            <span class="bs" title="Skipped"><i class="bi bi-dash"></i><?= (int)$ex['skipped_answers'] ?></span>
                        </div>
                        <div>
                            <?php if ($epub): ?>
                            <span class="pub-dot yes"><i class="bi bi-check-circle-fill"></i> Published</span>
                            <?php else: ?>
                            <span class="pub-dot no"><i class="bi bi-hourglass-split"></i> Pending</span>
                            <?php endif; ?>
                        </div>
                        <div style="font-size:.78rem;color:var(--muted);white-space:nowrap">
                            <i class="bi bi-calendar3"></i>
                            <?= $ex['date_submitted'] ? date('d M Y, H:i', strtotime($ex['date_submitted'])) : '—' ?>
                        </div>
                        <div>
                            <a href="student_result_detail.php?attempt_id=<?= (int)$ex['attempt_id'] ?>" class="btn-view">
                                <i class="bi bi-eye"></i> View
                            </a>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>

            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        </div>
    </div>

</div>

<!-- Student Profile Modal -->
<div class="modal-overlay" id="profileModal" onclick="closeProfileOnBg(event)">
    <div class="modal-box">
        <div class="modal-hdr">
            <div class="modal-hdr-left">
                <span class="stu-avatar" id="modal-avatar" style="width:44px;height:44px;font-size:.9rem"></span>
                <div class="modal-stu-info">
                    <h4 id="modal-name">—</h4>
                    <p id="modal-email">—</p>
                </div>
            </div>
            <button class="modal-close" onclick="closeProfile()"><i class="bi bi-x-lg"></i></button>
        </div>
        <div class="modal-stats">
            <div class="mstat">
                <div class="mstat-num" id="modal-total" style="color:var(--accent)">—</div>
                <div class="mstat-label">Exams Taken</div>
            </div>
            <div class="mstat">
                <div class="mstat-num" id="modal-avg">—</div>
                <div class="mstat-label">Avg Score</div>
            </div>
            <div class="mstat">
                <div class="mstat-num" id="modal-pass" style="color:var(--green)">—</div>
                <div class="mstat-label">Passed</div>
            </div>
            <div class="mstat">
                <div class="mstat-num" id="modal-fail" style="color:var(--red)">—</div>
                <div class="mstat-label">Failed</div>
            </div>
        </div>
        <div class="modal-body">
            <h6><i class="bi bi-journal-check" style="margin-right:.35rem"></i>Exam Results</h6>
            <div id="modal-exams-list"></div>
        </div>
    </div>
</div>

<script>
function switchTab(tab) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.getElementById('tab-' + tab).classList.add('active');
    event.currentTarget.classList.add('active');
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    history.replaceState(null, '', url.toString());
}

function toggleAnalytics() {
    const btn  = document.getElementById('analyticsToggle');
    const body = document.getElementById('analyticsBody');
    btn.classList.toggle('open');
    body.classList.toggle('show');
}

function toggleStudent(sid) {
    const row     = document.getElementById('sr-' + sid);
    const subRows = document.querySelectorAll('#subrow-' + sid);
    const subHdr  = document.getElementById('subhdr-' + sid);
    const isOpen  = row.classList.contains('expanded');
    row.classList.toggle('expanded', !isOpen);
    subHdr && subHdr.classList.toggle('show', !isOpen);
    subRows.forEach(r => r.classList.toggle('show', !isOpen));
}

function expandAll() {
    document.querySelectorAll('.student-row').forEach(row => {
        const sid = row.id.replace('sr-', '');
        if (!row.classList.contains('expanded')) toggleStudent(parseInt(sid));
    });
}
function collapseAll() {
    document.querySelectorAll('.student-row.expanded').forEach(row => {
        const sid = row.id.replace('sr-', '');
        toggleStudent(parseInt(sid));
    });
}

function gradeColor(s) {
    if (s >= 80) return '#16a34a';
    if (s >= 70) return '#2563eb';
    if (s >= 60) return '#d97706';
    if (s >= 50) return '#f59e0b';
    return '#dc2626';
}
function gradeLabel(s) {
    if (s >= 80) return 'A';
    if (s >= 70) return 'B';
    if (s >= 60) return 'C';
    if (s >= 50) return 'D';
    return 'F';
}

function openProfile(data) {
    document.getElementById('modal-avatar').style.background = data.color;
    document.getElementById('modal-avatar').textContent = data.init;
    document.getElementById('modal-name').textContent  = data.name;
    document.getElementById('modal-email').textContent = data.email
        + (data.number ? '  ·  ' + data.number : '')
        + (data.prog   ? '  ·  ' + data.prog   : '');
    document.getElementById('modal-total').textContent = data.total;

    const avgEl = document.getElementById('modal-avg');
    avgEl.textContent = data.avg + '%';
    avgEl.style.color = gradeColor(data.avg);

    document.getElementById('modal-pass').textContent = data.pass;
    document.getElementById('modal-fail').textContent = data.fail;

    const list = document.getElementById('modal-exams-list');
    list.innerHTML = '';
    data.exams.forEach(ex => {
        const gc    = gradeColor(ex.score);
        const grade = gradeLabel(ex.score);
        const pass  = ex.score >= 50;
        const date  = ex.date_submitted
            ? new Date(ex.date_submitted).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'})
            : '—';

        list.innerHTML += `
        <div class="exam-card">
            <div class="exam-card-top">
                <div>
                    <div class="exam-card-title">${ex.exam_title}</div>
                    <div class="exam-card-course">${ex.course_name}</div>
                </div>
                <div class="exam-card-right">
                    <span class="grade-pill" style="color:${gc};background:${gc}18;border:1px solid ${gc}33">${grade}</span>
                    <span class="pf-pill ${pass ? 'pf-pass' : 'pf-fail'}">${pass ? 'Pass' : 'Fail'}</span>
                </div>
            </div>
            <div class="exam-card-bottom">
                <span class="exam-score-big" style="color:${gc}">${ex.score.toFixed(1)}%</span>
                <div class="exam-score-bar">
                    <div class="exam-score-bar-fill" style="width:${Math.min(ex.score,100)}%;background:${gc}"></div>
                </div>
            </div>
            <div style="display:flex;gap:1.25rem;margin-top:.6rem;flex-wrap:wrap;align-items:center">
                <div class="exam-bd">
                    <span style="color:#16a34a"><i class="bi bi-check-lg"></i> ${ex.correct} correct</span>
                    <span style="color:#dc2626"><i class="bi bi-x-lg"></i> ${ex.wrong} wrong</span>
                    <span style="color:#9ca3af"><i class="bi bi-dash"></i> ${ex.skipped} skipped</span>
                </div>
                <div class="exam-meta">
                    ${ex.result_published
                        ? '<span style="color:#16a34a;font-weight:700"><i class="bi bi-check-circle-fill"></i> Published</span>'
                        : '<span style="color:#d97706;font-weight:700"><i class="bi bi-hourglass-split"></i> Pending</span>'}
                </div>
                <div class="exam-meta"><i class="bi bi-calendar3"></i> ${date}</div>
                <a href="student_result_detail.php?attempt_id=${ex.attempt_id}" class="btn-view" style="margin-left:auto">
                    <i class="bi bi-eye"></i> View Detail
                </a>
            </div>
        </div>`;
    });

    document.getElementById('profileModal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeProfile() {
    document.getElementById('profileModal').classList.remove('open');
    document.body.style.overflow = '';
}
function closeProfileOnBg(e) {
    if (e.target === document.getElementById('profileModal')) closeProfile();
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeProfile(); });
</script>

<?php include "../includes/footer.php"; ?>