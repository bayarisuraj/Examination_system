<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

$username = htmlspecialchars($_SESSION['name'] ?? $_SESSION['username'] ?? 'Lecturer');

// ── Safe lecturer_id resolution ───────────────────────────────────
// Try session user_id first. If email is available, verify against lecturers table.
$lecturer_id = (int)($_SESSION['user_id'] ?? 0);

$session_email = $_SESSION['user_email'] ?? $_SESSION['email'] ?? '';
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
    header("Location: ../auth/login.php?error=session");
    exit();
}

$success = '';
$error   = '';

// ── Handle Add Course ─────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_course'])) {
    $course_name = trim($_POST['course_name'] ?? '');
    $course_code = strtoupper(trim($_POST['course_code'] ?? ''));
    $description = trim($_POST['description'] ?? '');

    if ($course_name === '' || $course_code === '') {
        $error = "Course name and code are required.";
    } else {
        $check = $conn->prepare("SELECT id FROM courses WHERE course_code = ? AND lecturer_id = ? LIMIT 1");
        $check->bind_param("si", $course_code, $lecturer_id);
        $check->execute();
        $exists = $check->get_result()->num_rows > 0;
        $check->close();

        if ($exists) {
            $error = "You already have a course with code <strong>{$course_code}</strong>.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO courses (course_name, course_code, lecturer_id, description, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->bind_param("ssis", $course_name, $course_code, $lecturer_id, $description);
            if ($stmt->execute()) {
                $success = "Course <strong>" . htmlspecialchars($course_name) . "</strong> added successfully!";
            } else {
                error_log("manage_courses add failed: " . $conn->error);
                $error = "Failed to add course. Please try again.";
            }
            $stmt->close();
        }
    }
}

// ── Handle Delete Course ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_course'])) {
    $del_id = (int)($_POST['course_id'] ?? 0);
    if ($del_id) {
        $stmt = $conn->prepare("DELETE FROM courses WHERE id = ? AND lecturer_id = ?");
        $stmt->bind_param("ii", $del_id, $lecturer_id);
        $stmt->execute();
        $success = $stmt->affected_rows > 0
            ? "Course deleted successfully."
            : "Could not delete — course not found or access denied.";
        $stmt->close();
    }
}

// ── Fetch all lecturer's courses ──────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        c.id,
        c.course_name,
        c.course_code,
        c.description,
        c.created_at,
        (SELECT COUNT(*) FROM course_enrollments ce WHERE ce.course_id = c.id) AS student_count,
        (SELECT COUNT(*) FROM exams e WHERE e.course_id = c.id)                AS exam_count,
        (SELECT COUNT(*) FROM exams e WHERE e.course_id = c.id AND e.status = 'published') AS published_count
    FROM courses c
    WHERE c.lecturer_id = ?
    ORDER BY c.created_at DESC
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$courses = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$total_courses = count($courses);

include "../includes/header.php";
?>

<style>
:root{--teal:#3d8b8d;--teal-dark:#2d6e70;--teal-light:#56a8aa;--teal-pale:#eaf5f5;--teal-border:#c0dfe0;--teal-glow:rgba(61,139,141,.12);--bg:#f8fafb;--surface:#ffffff;--surface2:#f0f4f5;--border:#e0e7ea;--text:#1a2e35;--muted:#6b7c8d;--red:#c0392b;--amber:#d29922;--green:#2e7d5e;--radius:12px;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
.page-shell{max-width:1200px;margin:0 auto;padding:2rem 1.25rem 5rem;}
.page-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:2rem;}
.page-header h1{font-size:1.45rem;font-weight:700;color:var(--text);margin-bottom:.2rem;}
.page-header .sub{font-size:.83rem;color:var(--muted);}
.flash{display:flex;align-items:center;gap:.6rem;padding:.75rem 1rem;border-radius:9px;font-size:.85rem;font-weight:500;margin-bottom:1.5rem;border:1px solid transparent;border-left-width:4px;}
.flash-success{background:#f0fdf4;border-color:#86efac;border-left-color:#166534;color:#166534;}
.flash-error{background:#fef2f2;border-color:#fca5a5;border-left-color:#b91c1c;color:#b91c1c;}
.main-grid{display:grid;grid-template-columns:320px 1fr;gap:1.5rem;align-items:start;}
@media(max-width:860px){.main-grid{grid-template-columns:1fr;}}
.add-panel{background:var(--surface);border:1px solid var(--teal-border);border-top:3px solid var(--teal);border-radius:var(--radius);overflow:hidden;position:sticky;top:1.5rem;}
.panel-head{padding:.85rem 1.1rem;border-bottom:1px solid var(--teal-border);background:var(--teal-pale);font-size:.92rem;font-weight:700;color:var(--teal-dark);display:flex;align-items:center;gap:.45rem;}
.panel-head i{color:var(--teal);}
.panel-body{padding:1.1rem;}
.form-field{margin-bottom:.85rem;}
.form-field label{display:block;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);margin-bottom:.35rem;}
.form-input,.form-textarea{width:100%;background:var(--surface2);border:1.5px solid var(--border);border-radius:8px;padding:.55rem .85rem;color:var(--text);font-size:.85rem;outline:none;transition:border-color .2s,box-shadow .2s;font-family:inherit;}
.form-input::placeholder,.form-textarea::placeholder{color:var(--muted);}
.form-input:focus,.form-textarea:focus{border-color:var(--teal);box-shadow:0 0 0 3px var(--teal-glow);}
.form-textarea{resize:vertical;min-height:80px;}
.btn-add{width:100%;padding:.6rem 1rem;border-radius:9px;border:none;background:var(--teal);color:#fff;font-size:.88rem;font-weight:700;cursor:pointer;transition:background .18s;display:flex;align-items:center;justify-content:center;gap:.4rem;font-family:inherit;}
.btn-add:hover{background:var(--teal-dark);}
.courses-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.1rem;}
.courses-header h2{font-size:1rem;font-weight:700;color:var(--text);}
.search-box{position:relative;}
.search-box i{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.82rem;pointer-events:none;}
.search-box input{background:var(--surface);border:1.5px solid var(--teal-border);border-radius:8px;padding:.45rem .8rem .45rem 2rem;color:var(--text);font-size:.82rem;outline:none;width:200px;transition:border-color .2s,box-shadow .2s;font-family:inherit;}
.search-box input::placeholder{color:var(--muted);}
.search-box input:focus{border-color:var(--teal);box-shadow:0 0 0 3px var(--teal-glow);}
.courses-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:1.1rem;}
.course-card{background:var(--surface);border:1px solid var(--teal-border);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column;transition:border-color .22s,transform .22s,box-shadow .22s;animation:fadeUp .35s ease both;}
.course-card:hover{border-color:var(--teal);transform:translateY(-3px);box-shadow:0 8px 24px rgba(61,139,141,.15);}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px);}to{opacity:1;transform:translateY(0);}}
.card-top{padding:1rem 1.1rem .8rem;background:var(--teal-pale);border-bottom:1px solid var(--teal-border);}
.card-code{font-size:.73rem;font-weight:700;color:var(--teal);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.3rem;font-family:monospace;}
.card-name{font-size:.97rem;font-weight:700;color:var(--text);line-height:1.3;}
.card-meta{padding:.8rem 1.1rem;display:flex;gap:1rem;flex-wrap:wrap;}
.meta-chip{display:flex;align-items:center;gap:.35rem;font-size:.78rem;color:var(--muted);}
.meta-chip.c-students i{color:var(--teal);}.meta-chip.c-exams i{color:var(--teal-dark);}.meta-chip.c-pub i{color:#2e7d5e;}
.card-desc{padding:0 1.1rem .75rem;font-size:.79rem;color:var(--muted);line-height:1.5;flex:1;}
.card-created{padding:0 1.1rem .6rem;font-size:.72rem;color:var(--muted);font-family:monospace;}
.card-foot{padding:.85rem 1.1rem;border-top:1px solid var(--teal-border);background:var(--teal-pale);display:flex;flex-direction:column;gap:.5rem;}
.btn-card{display:flex;align-items:center;justify-content:center;gap:.4rem;width:100%;padding:.5rem .9rem;border-radius:8px;font-size:.83rem;font-weight:600;text-decoration:none;border:1px solid transparent;transition:all .18s;cursor:pointer;font-family:inherit;}
.btn-open{background:var(--teal);color:#fff;border-color:var(--teal);}
.btn-open:hover{background:var(--teal-dark);border-color:var(--teal-dark);color:#fff;}
.btn-students{background:transparent;color:var(--teal-dark);border-color:var(--teal-border);}
.btn-students:hover{background:var(--teal-dark);color:#fff;border-color:var(--teal-dark);}
.btn-delete{background:transparent;color:var(--red);border-color:var(--border);font-size:.78rem;}
.btn-delete:hover{border-color:var(--red);background:#fef2f2;}
.empty-state{grid-column:1/-1;text-align:center;padding:3.5rem 2rem;color:var(--muted);background:var(--surface);border:1px solid var(--teal-border);border-radius:var(--radius);}
.empty-state i{font-size:2.5rem;display:block;margin-bottom:.75rem;color:var(--teal-border);}
.empty-state h5{color:var(--text);margin-bottom:.35rem;}
@media(max-width:600px){.page-shell{padding:1.25rem .75rem 4rem;}.page-header h1{font-size:1.3rem;}}
</style>

<div class="page-shell">

    <div class="page-header">
        <div>
            <h1><i class="bi bi-journal-richtext" style="color:var(--teal);margin-right:.4rem"></i>Manage Courses</h1>
            <div class="sub"><?= $total_courses ?> course<?= $total_courses !== 1 ? 's' : '' ?> &mdash; add, view, and manage</div>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="flash flash-success"><i class="bi bi-check-circle-fill"></i> <?= $success ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="flash flash-error"><i class="bi bi-exclamation-triangle-fill"></i> <?= $error ?></div>
    <?php endif; ?>

    <div class="main-grid">

        <div class="add-panel">
            <div class="panel-head"><i class="bi bi-plus-circle-fill"></i> Add New Course</div>
            <div class="panel-body">
                <form method="POST" action="manage_courses.php">
                    <div class="form-field">
                        <label>Course Name</label>
                        <input type="text" name="course_name" class="form-input" placeholder="e.g. Introduction to Computing" required>
                    </div>
                    <div class="form-field">
                        <label>Course Code</label>
                        <input type="text" name="course_code" class="form-input" placeholder="e.g. CS101" required style="text-transform:uppercase">
                    </div>
                    <div class="form-field">
                        <label>Description <span style="font-weight:400;text-transform:none">(optional)</span></label>
                        <textarea name="description" class="form-textarea" placeholder="Brief course description…"></textarea>
                    </div>
                    <button type="submit" name="add_course" class="btn-add">
                        <i class="bi bi-plus-lg"></i> Add Course
                    </button>
                </form>
            </div>
        </div>

        <div>
            <div class="courses-header">
                <h2>Your Courses</h2>
                <div class="search-box">
                    <i class="bi bi-search"></i>
                    <input type="text" id="searchInput" placeholder="Search courses…">
                </div>
            </div>

            <div class="courses-grid" id="coursesGrid">
                <?php if (empty($courses)): ?>
                <div class="empty-state">
                    <i class="bi bi-journal-x"></i>
                    <h5>No Courses Yet</h5>
                    <p style="font-size:.83rem">Use the form to add your first course.</p>
                </div>
                <?php else: ?>
                <?php foreach ($courses as $c): ?>
                <div class="course-item" data-name="<?= strtolower(htmlspecialchars($c['course_name'] . ' ' . $c['course_code'])) ?>">
                    <div class="course-card">
                        <div class="card-top">
                            <div class="card-code"><?= htmlspecialchars($c['course_code']) ?></div>
                            <div class="card-name"><?= htmlspecialchars($c['course_name']) ?></div>
                        </div>
                        <div class="card-meta">
                            <span class="meta-chip c-students"><i class="bi bi-people-fill"></i><?= (int)$c['student_count'] ?> student<?= $c['student_count'] != 1 ? 's' : '' ?></span>
                            <span class="meta-chip c-exams"><i class="bi bi-pencil-square"></i><?= (int)$c['exam_count'] ?> exam<?= $c['exam_count'] != 1 ? 's' : '' ?></span>
                            <span class="meta-chip c-pub"><i class="bi bi-broadcast"></i><?= (int)$c['published_count'] ?> published</span>
                        </div>
                        <?php if (!empty($c['description'])): ?>
                        <div class="card-desc"><?= htmlspecialchars(mb_strimwidth($c['description'], 0, 90, '…')) ?></div>
                        <?php endif; ?>
                        <div class="card-created"><i class="bi bi-calendar3"></i> Created <?= date('d M Y', strtotime($c['created_at'])) ?></div>
                        <div class="card-foot">
                            <a href="course_detail.php?course_id=<?= (int)$c['id'] ?>" class="btn-card btn-open">
                                <i class="bi bi-folder2-open"></i> Open Course
                            </a>
                            <a href="view_students.php?course_id=<?= (int)$c['id'] ?>" class="btn-card btn-students">
                                <i class="bi bi-people"></i> View Students
                            </a>
                            <form method="POST" action="manage_courses.php"
                                  onsubmit="return confirm('Delete \'<?= addslashes($c['course_name']) ?>\'? This cannot be undone.')">
                                <input type="hidden" name="course_id" value="<?= (int)$c['id'] ?>">
                                <button type="submit" name="delete_course" class="btn-card btn-delete">
                                    <i class="bi bi-trash3"></i> Delete Course
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </div>
</div>

<script>
document.getElementById('searchInput').addEventListener('input', function () {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.course-item').forEach(item => {
        item.style.display = (!q || item.dataset.name.includes(q)) ? '' : 'none';
    });
});
</script>

<?php include "../includes/footer.php"; ?>