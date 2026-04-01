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

// ── Fetch all courses for this lecturer ───────────────────────────
$stmt = $conn->prepare("
    SELECT id, course_name, course_code, program
    FROM courses
    WHERE lecturer_id = ?
    ORDER BY course_name ASC
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$courses       = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$course_ids    = array_column($courses, 'id');
$course_map    = array_column($courses, null, 'id');
$total_courses = count($courses);

// ── Collect all unique programs from courses ──────────────────────
$all_programs = array_unique(array_filter(array_column($courses, 'program')));
sort($all_programs);

// ── Fetch all enrolled students across all lecturer courses ───────
$students       = [];
$total_students = 0;

if ($course_ids) {
    $placeholders = implode(',', array_fill(0, count($course_ids), '?'));
    $types        = str_repeat('i', count($course_ids));

    /*
     * Program priority:
     *   1. s.program  — student's own declared program (most reliable)
     *   2. c.program  — the program the course belongs to (fallback)
     *
     * COALESCE + NULLIF(TRIM(...), '') treats blank strings as null
     * so we never show an empty pill when a real value exists elsewhere.
     */
    $stmt = $conn->prepare("
        SELECT
            s.id,
            s.name,
            s.email,
            s.student_number,
            ce.course_id,
ce.enrolled_at,
COALESCE(
    NULLIF(TRIM(s.program), ''),
    NULLIF(TRIM(c.program), '')
)                                                AS display_program,
            (
                SELECT COUNT(DISTINCT a.exam_id)
                FROM exam_attempts a
                JOIN exams e ON e.id = a.exam_id
                WHERE a.student_id = s.id
                  AND e.course_id  = ce.course_id
                  AND a.status     = 'completed'
            ) AS exams_taken
        FROM course_enrollments ce
        JOIN students s ON s.id = ce.student_id
        JOIN courses  c ON c.id = ce.course_id
        WHERE ce.course_id IN ($placeholders)
        ORDER BY ce.enrolled_at DESC
    ");
    $stmt->bind_param($types, ...$course_ids);
    $stmt->execute();
    $students       = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $total_students = count($students);
    $stmt->close();
}

// ── Stat: unique students ─────────────────────────────────────────
$unique_student_ids = array_unique(array_column($students, 'id'));
$unique_count       = count($unique_student_ids);

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
    --teal:    #3d8b8d;
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
.sc-courses  .s-val { color: var(--accent); }
.sc-enrol    .s-val { color: var(--purple); }
.sc-unique   .s-val { color: var(--green); }
.sc-programs .s-val { color: var(--teal); }

/* ── Toolbar ── */
.toolbar {
    display: flex; align-items: center; gap: .75rem;
    flex-wrap: wrap; margin-bottom: 1.1rem;
}
.search-box { position: relative; flex: 1; min-width: 180px; max-width: 260px; }
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

.filter-select {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: 8px; padding: .48rem .85rem;
    color: var(--text); font-size: .83rem; font-family: var(--sans);
    cursor: pointer; outline: none; transition: border-color .2s;
}
.filter-select:focus { border-color: var(--accent); }

.result-count { font-size: .82rem; color: var(--muted); white-space: nowrap; }

.btn-export {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .45rem 1rem; border-radius: 8px;
    border: 1px solid var(--green); background: transparent;
    color: var(--green); font-size: .83rem; font-weight: 600;
    cursor: pointer; transition: all .18s; margin-left: auto;
}
.btn-export:hover { background: var(--green); color: #0d1117; }

/* ── Table ── */
.table-wrap {
    background: var(--surface); border: 1px solid var(--border);
    border-radius: var(--radius); overflow: hidden; overflow-x: auto;
}
table { width: 100%; border-collapse: collapse; font-size: .87rem; }
thead th {
    background: var(--surface2); padding: .7rem 1rem; text-align: left;
    font-size: .71rem; text-transform: uppercase; letter-spacing: .07em;
    font-weight: 700; color: var(--muted);
    border-bottom: 1px solid var(--border); white-space: nowrap;
}
tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
tbody tr:last-child { border-bottom: none; }
tbody tr:hover { background: var(--surface2); }
tbody td { padding: .75rem 1rem; vertical-align: middle; }

.td-rank { font-family: var(--mono); font-size: .77rem; color: var(--muted); width: 36px; }

.td-student strong { display: block; color: var(--text); font-size: .88rem; }
.td-student a      { font-size: .77rem; color: var(--muted); text-decoration: none; transition: color .15s; }
.td-student a:hover { color: var(--accent); }

.td-meta { font-family: var(--mono); font-size: .76rem; color: var(--muted); }
.td-date { font-family: var(--mono); font-size: .77rem; color: var(--muted); white-space: nowrap; }

/* ── Program pill ── */
.program-pill {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .2rem .65rem; border-radius: 20px;
    font-size: .74rem; font-weight: 600;
    background: #0e1f1f; border: 1px solid var(--teal);
    color: var(--teal); white-space: nowrap; max-width: 180px;
    overflow: hidden; text-overflow: ellipsis;
}
.program-pill.unknown {
    background: var(--surface2); border-color: var(--border);
    color: var(--muted);
}

/* ── Course pill ── */
.course-pill {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .2rem .65rem; border-radius: 20px;
    font-size: .74rem; font-weight: 600;
    background: #131c2b; border: 1px solid var(--accent);
    color: var(--accent); white-space: nowrap;
    text-decoration: none; transition: background .18s;
}
.course-pill:hover { background: var(--accent); color: #0d1117; }

.attempts-badge {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .22rem .65rem; border-radius: 20px;
    font-size: .75rem; font-weight: 700; font-family: var(--mono);
}
.badge-active { background: #0d2818; color: var(--green); border: 1px solid #238636; }
.badge-none   { background: var(--surface2); color: var(--muted); border: 1px solid var(--border); }

.btn-view-detail {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .28rem .7rem; border-radius: 7px;
    border: 1px solid var(--border); background: transparent;
    color: var(--muted); font-size: .77rem; font-weight: 600;
    text-decoration: none; transition: all .18s; white-space: nowrap;
}
.btn-view-detail:hover { border-color: var(--accent); color: var(--accent); }

/* ── Empty state ── */
.empty-row td { padding: 3.5rem 1rem; text-align: center; color: var(--muted); }
.empty-row i  { display: block; font-size: 2.5rem; margin-bottom: .75rem; color: var(--border); }
.empty-row h6 { color: var(--text); font-family: var(--serif); margin-bottom: .35rem; }

@media (max-width: 600px) {
    .page-shell { padding: 1.25rem .75rem 4rem; }
    .page-header h1 { font-size: 1.25rem; }
}
</style>

<div class="page-shell">

    <!-- Page header -->
    <div class="page-header">
        <div>
            <h1>
                <i class="bi bi-people-fill" style="color:var(--accent);margin-right:.4rem"></i>
                Enrolled Students
            </h1>
            <div class="meta">
                <i class="bi bi-journal-bookmark"></i>
                All <?= $total_courses ?> course<?= $total_courses !== 1 ? 's' : '' ?>
            </div>
        </div>
    </div>

    <!-- Stat cards -->
    <div class="stat-grid">
        <div class="stat-card sc-courses">
            <span class="s-label">My Courses</span>
            <span class="s-val"><?= $total_courses ?></span>
        </div>
        <div class="stat-card sc-enrol">
            <span class="s-label">Enrollments</span>
            <span class="s-val"><?= $total_students ?></span>
        </div>
        <div class="stat-card sc-unique">
            <span class="s-label">Unique Students</span>
            <span class="s-val"><?= $unique_count ?></span>
        </div>
        <div class="stat-card sc-programs">
            <span class="s-label">Programs</span>
            <span class="s-val"><?= count($all_programs) ?: '—' ?></span>
        </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" id="searchInput"
                   placeholder="Search name, email or ID…"
                   autocomplete="off" spellcheck="false">
        </div>

        <select class="filter-select" id="courseFilter">
            <option value="">All Courses</option>
            <?php foreach ($courses as $c): ?>
            <option value="<?= $c['id'] ?>">
                <?= htmlspecialchars($c['course_code']) ?> — <?= htmlspecialchars($c['course_name']) ?>
            </option>
            <?php endforeach; ?>
        </select>

        <?php if (!empty($all_programs)): ?>
        <select class="filter-select" id="programFilter">
            <option value="">All Programs</option>
            <?php foreach ($all_programs as $prog): ?>
            <option value="<?= htmlspecialchars($prog) ?>">
                <?= htmlspecialchars($prog) ?>
            </option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <span class="result-count" id="resultCount">
            <?= $total_students ?> enrollment<?= $total_students !== 1 ? 's' : '' ?>
        </span>

        <button class="btn-export" onclick="exportCSV()">
            <i class="bi bi-download"></i> Export CSV
        </button>
    </div>

    <!-- Table -->
    <div class="table-wrap">
        <table id="studentsTable">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student</th>
                    <th>Student ID</th>
                    <th>Program</th>
                    <th>Course</th>
                    <th>Enrolled</th>
                    <th>Exams Taken</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="tableBody">
                <?php if ($total_students > 0):
                    $i = 1;
                    foreach ($students as $s):
                        $attempts        = (int)$s['exams_taken'];
                        $cid             = (int)$s['course_id'];
                        $cname           = $course_map[$cid]['course_name'] ?? '—';
                        $ccode           = $course_map[$cid]['course_code'] ?? '—';
                        $display_program = trim($s['display_program'] ?? '');
                        $has_program     = $display_program !== '';
                ?>
                <tr data-search="<?= strtolower(htmlspecialchars(
                        $s['name'] . ' ' . $s['email'] . ' ' . ($s['student_number'] ?? '') . ' ' . $cname . ' ' . $ccode . ' ' . $display_program
                    )) ?>"
                    data-course="<?= $cid ?>"
                    data-program="<?= htmlspecialchars($display_program) ?>">

                    <td class="td-rank"><?= $i++ ?></td>

                    <td class="td-student">
                        <strong><?= htmlspecialchars($s['name']) ?></strong>
                        <a href="mailto:<?= htmlspecialchars($s['email']) ?>">
                            <?= htmlspecialchars($s['email']) ?>
                        </a>
                    </td>

                    <td class="td-meta">
                        <?= htmlspecialchars($s['student_number'] ?? '—') ?>
                    </td>

                    <!-- Program: sourced via COALESCE(ce.program, c.program, s.program) -->
                    <td>
                        <?php if ($has_program): ?>
                        <span class="program-pill" title="<?= htmlspecialchars($display_program) ?>">
                            <i class="bi bi-mortarboard"></i>
                            <?= htmlspecialchars($display_program) ?>
                        </span>
                        <?php else: ?>
                        <span class="program-pill unknown">
                            <i class="bi bi-dash"></i> Not set
                        </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <a href="course_detail.php?course_id=<?= $cid ?>"
                           class="course-pill">
                            <i class="bi bi-journal-bookmark"></i>
                            <?= htmlspecialchars($ccode) ?>
                        </a>
                    </td>

                    <td class="td-date">
                        <?= date('d M Y', strtotime($s['enrolled_at'])) ?>
                    </td>

                    <td>
                        <?php if ($attempts > 0): ?>
                        <span class="attempts-badge badge-active">
                            <i class="bi bi-check-lg"></i>
                            <?= $attempts ?> exam<?= $attempts !== 1 ? 's' : '' ?>
                        </span>
                        <?php else: ?>
                        <span class="attempts-badge badge-none">
                            <i class="bi bi-dash"></i> None yet
                        </span>
                        <?php endif; ?>
                    </td>

                    <td>
                        <a href="student_progress.php?student_id=<?= (int)$s['id'] ?>&course_id=<?= $cid ?>"
                           class="btn-view-detail">
                            <i class="bi bi-eye"></i> Progress
                        </a>
                    </td>
                </tr>
                <?php endforeach; else: ?>
                <tr class="empty-row">
                    <td colspan="8">
                        <i class="bi bi-person-x"></i>
                        <h6>No Students Enrolled Yet</h6>
                        <p style="font-size:.83rem">
                            Students will appear here once they enroll in your courses.
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div><!-- /page-shell -->

<script>
const searchInput   = document.getElementById('searchInput');
const courseFilter  = document.getElementById('courseFilter');
const programFilter = document.getElementById('programFilter');
const resultCount   = document.getElementById('resultCount');

function applyFilters() {
    const q    = searchInput.value.trim().toLowerCase();
    const cid  = courseFilter?.value  || '';
    const prog = programFilter?.value || '';
    let visible = 0;

    document.querySelectorAll('#tableBody tr:not(.empty-row)').forEach(row => {
        const matchSearch  = !q    || row.dataset.search.includes(q);
        const matchCourse  = !cid  || row.dataset.course  === cid;
        const matchProgram = !prog || row.dataset.program === prog;
        const show = matchSearch && matchCourse && matchProgram;
        row.style.display = show ? '' : 'none';

        if (show) {
            visible++;
            row.querySelector('.td-rank').textContent = visible;
        }
    });

    resultCount.textContent = `${visible} enrollment${visible !== 1 ? 's' : ''}`;
}

searchInput.addEventListener('input', applyFilters);
courseFilter?.addEventListener('change', applyFilters);
programFilter?.addEventListener('change', applyFilters);

// ── Export CSV ────────────────────────────────────────────────────
const allStudents = <?= json_encode(array_map(fn($s) => [
    'name'           => $s['name'],
    'email'          => $s['email'],
    'student_number' => $s['student_number'] ?? '',
    'program'        => trim($s['display_program'] ?? ''),
    'course_code'    => $course_map[$s['course_id']]['course_code'] ?? '',
    'course_name'    => $course_map[$s['course_id']]['course_name'] ?? '',
    'enrolled'       => date('d M Y', strtotime($s['enrolled_at'])),
    'exams_taken'    => $s['exams_taken'],
], $students)) ?>;

function exportCSV() {
    const header = ['#','Name','Email','Student ID','Program','Course Code','Course Name','Enrolled','Exams Taken'];
    const visible = Array.from(document.querySelectorAll('#tableBody tr:not(.empty-row)'))
        .filter(r => r.style.display !== 'none')
        .map(r => parseInt(r.querySelector('.td-rank').textContent) - 1);

    const rows = visible.map((idx, i) => {
        const s = allStudents[idx];
        return [i+1, s.name, s.email, s.student_number, s.program,
                s.course_code, s.course_name, s.enrolled, s.exams_taken];
    });

    const csv  = [header, ...rows].map(r =>
        r.map(v => `"${String(v).replace(/"/g,'""')}"`).join(',')
    ).join('\n');

    const blob = new Blob([csv], { type: 'text/csv' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'enrolled_students_all_courses.csv';
    a.click();
    URL.revokeObjectURL(url);
}
</script>

<?php include "../includes/footer.php"; ?>