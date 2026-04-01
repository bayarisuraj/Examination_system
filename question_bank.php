<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: login.php");
    exit();
}

include '../config/db.php';

$lecturer_id  = $_SESSION['user_id'];
$lecturer_name = $_SESSION['full_name'] ?? 'Lecturer';

// ── Handle POST actions ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── ADD QUESTION ─────────────────────────────────────────────────────────
    if ($_POST['action'] === 'add_question') {
        $exam_id       = intval($_POST['exam_id']);
        $question_text = trim($_POST['question_text']);
        $question_type = in_array($_POST['question_type'], ['mcq','truefalse'])
                         ? $_POST['question_type'] : 'mcq';
        $marks         = intval($_POST['marks']);
        $optionA       = trim($_POST['option_a']);
        $optionB       = trim($_POST['option_b']);
        $optionC       = trim($_POST['option_c']);
        $optionD       = trim($_POST['option_d']);
        $correct       = trim($_POST['correct_answer']); // stores letter: a/b/c/d/true/false

        // 1. Insert into questions
        $stmt = $conn->prepare("
            INSERT INTO questions
                (question_text, question_type, marks, option_a, option_b, option_c, option_d, correct_option)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssisssss",
            $question_text, $question_type, $marks,
            $optionA, $optionB, $optionC, $optionD, $correct
        );
        $stmt->execute();
        $new_question_id = $conn->insert_id;
        $stmt->close();

        // 2. Link question to exam via junction table
        $stmt2 = $conn->prepare("
            INSERT IGNORE INTO exam_questions (exam_id, question_id)
            VALUES (?, ?)
        ");
        $stmt2->bind_param("ii", $exam_id, $new_question_id);
        $stmt2->execute();
        $stmt2->close();

        $success = "Question added successfully.";
    }

    // ── DELETE QUESTION ───────────────────────────────────────────────────────
    if ($_POST['action'] === 'delete_question') {
        $qid = intval($_POST['question_id']);

        // Remove from junction table first
        $stmt = $conn->prepare("DELETE FROM exam_questions WHERE question_id = ?");
        $stmt->bind_param("i", $qid);
        $stmt->execute();
        $stmt->close();

        // Remove from questions table
        $stmt = $conn->prepare("DELETE FROM questions WHERE id = ?");
        $stmt->bind_param("i", $qid);
        $stmt->execute();
        $stmt->close();

        $success = "Question deleted.";
    }

    // ── EDIT QUESTION ─────────────────────────────────────────────────────────
    if ($_POST['action'] === 'edit_question') {
        $qid           = intval($_POST['question_id']);
        $question_text = trim($_POST['question_text']);
        $marks         = intval($_POST['marks']);
        $optionA       = trim($_POST['option_a']);
        $optionB       = trim($_POST['option_b']);
        $optionC       = trim($_POST['option_c']);
        $optionD       = trim($_POST['option_d']);
        $correct       = trim($_POST['correct_answer']);

        $stmt = $conn->prepare("
            UPDATE questions
            SET question_text = ?, marks = ?,
                option_a = ?, option_b = ?, option_c = ?, option_d = ?,
                correct_option = ?
            WHERE id = ?
        ");
        $stmt->bind_param("sisssssi",
            $question_text, $marks,
            $optionA, $optionB, $optionC, $optionD,
            $correct, $qid
        );
        $stmt->execute();
        $stmt->close();

        $success = "Question updated successfully.";
    }
}

// ── Fetch exams belonging to this lecturer ────────────────────────────────────
$stmt = $conn->prepare("
    SELECT e.id, e.title, c.course_name
    FROM exams e
    JOIN courses c ON e.course_id = c.id
    WHERE e.created_by = ?
    ORDER BY e.created_at DESC
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ── Filter by selected exam ───────────────────────────────────────────────────
$selected_exam_id = isset($_GET['exam_id']) ? intval($_GET['exam_id']) : ($exams[0]['id'] ?? 0);
$questions        = [];
$selected_exam    = null;

if ($selected_exam_id) {
    $stmt = $conn->prepare("
        SELECT q.*
        FROM questions q
        JOIN exam_questions eq ON eq.question_id = q.id
        WHERE eq.exam_id = ?
        ORDER BY q.id ASC
    ");
    $stmt->bind_param("i", $selected_exam_id);
    $stmt->execute();
    $questions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($exams as $e) {
        if ($e['id'] == $selected_exam_id) {
            $selected_exam = $e;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Question Bank | Online Exam System</title>
<?php include '../includes/header.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root {
    --bg: #0d0f14;
    --surface: #151820;
    --card: #1c2030;
    --border: #252a3a;
    --accent: #4f8ef7;
    --accent2: #a78bfa;
    --success: #34d399;
    --danger: #f87171;
    --warning: #fbbf24;
    --text: #e8eaf0;
    --muted: #6b7280;
    --sidebar-w: 240px;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'DM Sans', sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; display: flex; }

  /* SIDEBAR */
  .sidebar {
    width: var(--sidebar-w); background: var(--surface); border-right: 1px solid var(--border);
    display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100;
  }
  .sidebar-logo {
    padding: 24px 20px; font-family: 'Syne', sans-serif; font-size: 1.1rem; font-weight: 800;
    color: var(--accent); letter-spacing: -0.5px; border-bottom: 1px solid var(--border);
  }
  .sidebar-logo span { color: var(--text); }
  .nav-section { padding: 16px 12px 8px; font-size: 0.65rem; color: var(--muted); text-transform: uppercase; letter-spacing: 1.5px; font-weight: 600; }
  .nav-item {
    display: flex; align-items: center; gap: 12px; padding: 10px 16px; margin: 2px 8px;
    border-radius: 8px; color: var(--muted); text-decoration: none; font-size: 0.88rem;
    transition: all 0.2s;
  }
  .nav-item:hover, .nav-item.active { background: rgba(79,142,247,0.1); color: var(--accent); }
  .nav-item i { width: 16px; text-align: center; }
  .sidebar-footer { margin-top: auto; padding: 16px; border-top: 1px solid var(--border); }
  .sidebar-user { display: flex; align-items: center; gap: 10px; }
  .avatar { width: 34px; height: 34px; background: linear-gradient(135deg, var(--accent), var(--accent2)); border-radius: 8px; display: flex; align-items: center; justify-content: center; font-family: 'Syne', sans-serif; font-weight: 700; font-size: 0.85rem; }
  .user-info .name { font-size: 0.85rem; font-weight: 500; }
  .user-info .role { font-size: 0.72rem; color: var(--muted); }

  /* MAIN */
  .main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; }
  .topbar {
    padding: 16px 32px; background: var(--surface); border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 50;
  }
  .page-title { font-family: 'Syne', sans-serif; font-size: 1.2rem; font-weight: 700; }
  .topbar-right { display: flex; align-items: center; gap: 12px; }
  .btn {
    display: inline-flex; align-items: center; gap: 8px; padding: 9px 18px;
    border-radius: 8px; font-size: 0.85rem; font-weight: 500; cursor: pointer;
    border: none; transition: all 0.2s; font-family: 'DM Sans', sans-serif;
  }
  .btn-primary { background: var(--accent); color: #fff; }
  .btn-primary:hover { background: #3a7bf0; }
  .btn-danger { background: rgba(248,113,113,0.15); color: var(--danger); border: 1px solid rgba(248,113,113,0.3); }
  .btn-danger:hover { background: rgba(248,113,113,0.25); }
  .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--border); }
  .btn-ghost:hover { color: var(--text); border-color: var(--accent); }
  .btn-sm { padding: 6px 12px; font-size: 0.78rem; }

  .content { padding: 28px 32px; flex: 1; }

  /* ALERT */
  .alert { padding: 12px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 0.88rem; display: flex; align-items: center; gap: 10px; }
  .alert-success { background: rgba(52,211,153,0.1); color: var(--success); border: 1px solid rgba(52,211,153,0.25); }

  /* EXAM SELECTOR */
  .exam-selector { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 28px; }
  .exam-pill {
    padding: 8px 16px; border-radius: 20px; font-size: 0.82rem; font-weight: 500; cursor: pointer;
    border: 1px solid var(--border); background: var(--card); color: var(--muted);
    text-decoration: none; transition: all 0.2s;
  }
  .exam-pill:hover { border-color: var(--accent); color: var(--accent); }
  .exam-pill.active { background: var(--accent); color: #fff; border-color: var(--accent); }

  /* STATS BAR */
  .stats-bar { display: flex; gap: 16px; margin-bottom: 28px; }
  .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px 20px; flex: 1; }
  .stat-label { font-size: 0.72rem; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
  .stat-value { font-family: 'Syne', sans-serif; font-size: 1.6rem; font-weight: 700; }
  .stat-value.blue { color: var(--accent); }
  .stat-value.purple { color: var(--accent2); }
  .stat-value.green { color: var(--success); }

  /* QUESTION LIST */
  .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
  .section-title { font-family: 'Syne', sans-serif; font-size: 1rem; font-weight: 700; }

  .question-card {
    background: var(--card); border: 1px solid var(--border); border-radius: 12px;
    padding: 20px; margin-bottom: 14px; transition: border-color 0.2s;
  }
  .question-card:hover { border-color: rgba(79,142,247,0.4); }
  .question-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 14px; }
  .question-num { width: 28px; height: 28px; background: rgba(79,142,247,0.15); color: var(--accent); border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 0.75rem; font-weight: 700; flex-shrink: 0; }
  .question-text { font-size: 0.92rem; line-height: 1.5; flex: 1; }
  .question-meta { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
  .badge { padding: 3px 10px; border-radius: 12px; font-size: 0.72rem; font-weight: 600; }
  .badge-marks { background: rgba(251,191,36,0.1); color: var(--warning); }
  .badge-type { background: rgba(167,139,250,0.1); color: var(--accent2); }

  .options-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-bottom: 14px; }
  .option-item {
    display: flex; align-items: center; gap: 8px; padding: 8px 12px;
    background: var(--surface); border-radius: 6px; font-size: 0.83rem; border: 1px solid var(--border);
  }
  .option-item.correct { border-color: rgba(52,211,153,0.5); background: rgba(52,211,153,0.08); color: var(--success); }
  .option-letter { width: 20px; height: 20px; border-radius: 4px; background: var(--border); display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: 700; flex-shrink: 0; }
  .option-item.correct .option-letter { background: var(--success); color: #0d0f14; }
  .question-actions { display: flex; gap: 8px; }

  /* EMPTY STATE */
  .empty-state { text-align: center; padding: 60px 20px; color: var(--muted); }
  .empty-state i { font-size: 3rem; margin-bottom: 14px; opacity: 0.4; }
  .empty-state p { font-size: 0.9rem; }

  /* MODAL */
  .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); z-index: 200; align-items: center; justify-content: center; }
  .modal-overlay.open { display: flex; }
  .modal { background: var(--card); border: 1px solid var(--border); border-radius: 16px; padding: 28px; width: 100%; max-width: 580px; max-height: 90vh; overflow-y: auto; }
  .modal-title { font-family: 'Syne', sans-serif; font-size: 1.1rem; font-weight: 700; margin-bottom: 22px; }

  .form-group { margin-bottom: 16px; }
  .form-label { display: block; font-size: 0.8rem; color: var(--muted); margin-bottom: 6px; font-weight: 500; }
  .form-control {
    width: 100%; background: var(--surface); border: 1px solid var(--border); color: var(--text);
    padding: 10px 14px; border-radius: 8px; font-size: 0.88rem; font-family: 'DM Sans', sans-serif;
    transition: border-color 0.2s; outline: none;
  }
  .form-control:focus { border-color: var(--accent); }
  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
  .form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px; }

  /* Scrollbar */
  ::-webkit-scrollbar { width: 6px; }
  ::-webkit-scrollbar-track { background: var(--bg); }
  ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

  @media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .main { margin-left: 0; }
    .options-grid { grid-template-columns: 1fr; }
    .stats-bar { flex-wrap: wrap; }
  }
</style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-logo">Exam<span>Pro</span></div>
  <div class="nav-section">Main</div>
  <a href="dashboard.php" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
  <a href="manage_courses.php" class="nav-item"><i class="fas fa-book"></i> Courses</a>
  <a href="manage_exams.php" class="nav-item"><i class="fas fa-file-alt"></i> Exams</a>
  <a href="question_bank.php" class="nav-item active"><i class="fas fa-database"></i> Question Bank</a>
  <a href="view_result.php" class="nav-item"><i class="fas fa-chart-bar"></i> Results</a>
  <div class="nav-section">Account</div>
  <a href="profile.php" class="nav-item"><i class="fas fa-user-cog"></i> Profile</a>
  <a href="logout.php" class="nav-item"><i class="fas fa-sign-out-alt"></i> Logout</a>
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="avatar"><?= strtoupper(substr($lecturer_name, 0, 2)) ?></div>
      <div class="user-info">
        <div class="name"><?= htmlspecialchars($lecturer_name) ?></div>
        <div class="role">Lecturer</div>
      </div>
    </div>
  </div>
</aside>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <div class="page-title"><i class="fas fa-database" style="color:var(--accent);margin-right:10px;"></i>Question Bank</div>
    <div class="topbar-right">
      <?php if ($selected_exam_id): ?>
      <button class="btn btn-primary" onclick="document.getElementById('addModal').classList.add('open')">
        <i class="fas fa-plus"></i> Add Question
      </button>
      <?php endif; ?>
    </div>
  </div>

  <div class="content">
    <?php if (isset($success)): ?>
    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Exam Selector -->
    <div class="exam-selector">
      <?php foreach ($exams as $exam): ?>
        <a href="?exam_id=<?= $exam['id'] ?>" class="exam-pill <?= $exam['id'] == $selected_exam_id ? 'active' : '' ?>">
          <?= htmlspecialchars($exam['title']) ?>
        </a>
      <?php endforeach; ?>
      <?php if (empty($exams)): ?>
        <p style="color:var(--muted);font-size:0.88rem;">No exams found. <a href="manage_exams.php" style="color:var(--accent);">Create one</a> first.</p>
      <?php endif; ?>
    </div>

    <?php if ($selected_exam): ?>
    <!-- Stats -->
    <?php
      $total_q    = count($questions);
      $total_marks = array_sum(array_column($questions, 'marks'));
      $mcq_count  = count(array_filter($questions, fn($q) => $q['question_type'] === 'mcq'));
    ?>
    <div class="stats-bar">
      <div class="stat-card"><div class="stat-label">Total Questions</div><div class="stat-value blue"><?= $total_q ?></div></div>
      <div class="stat-card"><div class="stat-label">Total Marks</div><div class="stat-value purple"><?= $total_marks ?></div></div>
      <div class="stat-card"><div class="stat-label">MCQ Questions</div><div class="stat-value green"><?= $mcq_count ?></div></div>
    </div>

    <!-- Questions -->
    <div class="section-header">
      <div class="section-title">Questions — <?= htmlspecialchars($selected_exam['title']) ?></div>
    </div>

    <?php if (empty($questions)): ?>
    <div class="empty-state">
      <i class="fas fa-question-circle"></i>
      <p>No questions yet. Click <strong>Add Question</strong> to get started.</p>
    </div>
    <?php else: ?>
    <?php foreach ($questions as $i => $q): ?>
    <div class="question-card">
      <div class="question-header">
        <div class="question-num"><?= $i + 1 ?></div>
        <div class="question-text"><?= htmlspecialchars($q['question_text']) ?></div>
        <div class="question-meta">
          <span class="badge badge-marks"><?= intval($q['marks']) ?> mk</span>
          <span class="badge badge-type"><?= strtoupper(htmlspecialchars($q['question_type'])) ?></span>
        </div>
      </div>

      <?php if ($q['question_type'] === 'mcq'): ?>
      <div class="options-grid">
        <?php
          $opts = ['a' => $q['option_a'], 'b' => $q['option_b'], 'c' => $q['option_c'], 'd' => $q['option_d']];
          foreach ($opts as $letter => $val):
            $isCorrect = strtolower($q['correct_option']) === $letter;
        ?>
        <div class="option-item <?= $isCorrect ? 'correct' : '' ?>">
          <div class="option-letter"><?= strtoupper($letter) ?></div>
          <?= htmlspecialchars($val ?: '—') ?>
          <?php if ($isCorrect): ?><i class="fas fa-check" style="margin-left:auto;font-size:0.75rem;"></i><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="question-actions">
        <button class="btn btn-ghost btn-sm" onclick="openEdit(<?= htmlspecialchars(json_encode($q), ENT_QUOTES) ?>)">
          <i class="fas fa-pen"> </i> Edit
        </button>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this question?')">
          <input type="hidden" name="action" value="delete_question">
          <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> Delete</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-title"><i class="fas fa-plus-circle" style="color:var(--accent);margin-right:8px;"></i>Add New Question</div>
    <form method="POST">
      <input type="hidden" name="action" value="add_question">
      <input type="hidden" name="exam_id" value="<?= $selected_exam_id ?>">
      <div class="form-group">
        <label class="form-label">Question Text</label>
        <textarea name="question_text" class="form-control" rows="3" required placeholder="Enter the question..."></textarea>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Question Type</label>
          <select name="question_type" class="form-control">
            <option value="mcq">Multiple Choice (MCQ)</option>
            <option value="truefalse">True / False</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Marks</label>
          <input type="number" name="marks" class="form-control" value="1" min="1" required>
        </div>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Option A</label><input type="text" name="option_a" class="form-control" placeholder="Option A"></div>
        <div class="form-group"><label class="form-label">Option B</label><input type="text" name="option_b" class="form-control" placeholder="Option B"></div>
        <div class="form-group"><label class="form-label">Option C</label><input type="text" name="option_c" class="form-control" placeholder="Option C"></div>
        <div class="form-group"><label class="form-label">Option D</label><input type="text" name="option_d" class="form-control" placeholder="Option D"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Correct Answer</label>
        <select name="correct_answer" class="form-control">
          <option value="a">A</option>
          <option value="b">B</option>
          <option value="c">C</option>
          <option value="d">D</option>
          <option value="true">True</option>
          <option value="false">False</option>
        </select>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('addModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Add Question</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-title"><i class="fas fa-pen" style="color:var(--accent2);margin-right:8px;"></i>Edit Question</div>
    <form method="POST">
      <input type="hidden" name="action" value="edit_question">
      <input type="hidden" name="question_id" id="edit_qid">
      <div class="form-group">
        <label class="form-label">Question Text</label>
        <textarea name="question_text" id="edit_question_text" class="form-control" rows="3" required></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Marks</label>
        <input type="number" name="marks" id="edit_marks" class="form-control" min="1" required>
      </div>
      <div class="form-row">
        <div class="form-group"><label class="form-label">Option A</label><input type="text" name="option_a" id="edit_oa" class="form-control"></div>
        <div class="form-group"><label class="form-label">Option B</label><input type="text" name="option_b" id="edit_ob" class="form-control"></div>
        <div class="form-group"><label class="form-label">Option C</label><input type="text" name="option_c" id="edit_oc" class="form-control"></div>
        <div class="form-group"><label class="form-label">Option D</label><input type="text" name="option_d" id="edit_od" class="form-control"></div>
      </div>
      <div class="form-group">
        <label class="form-label">Correct Answer</label>
        <select name="correct_answer" id="edit_correct" class="form-control">
          <option value="a">A</option>
          <option value="b">B</option>
          <option value="c">C</option>
          <option value="d">D</option>
          <option value="true">True</option>
          <option value="false">False</option>
        </select>
      </div>
      <div class="form-actions">
        <button type="button" class="btn btn-ghost" onclick="document.getElementById('editModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEdit(q) {
  document.getElementById('edit_qid').value          = q.id;
  document.getElementById('edit_question_text').value = q.question_text;
  document.getElementById('edit_marks').value         = q.marks;
  document.getElementById('edit_oa').value            = q.option_a  || '';
  document.getElementById('edit_ob').value            = q.option_b  || '';
  document.getElementById('edit_oc').value            = q.option_c  || '';
  document.getElementById('edit_od').value            = q.option_d  || '';
  // correct_option is the DB column (a/b/c/d/true/false)
  document.getElementById('edit_correct').value       = q.correct_option || 'a';
  document.getElementById('editModal').classList.add('open');
}

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
  overlay.addEventListener('click', function(e) {
    if (e.target === this) this.classList.remove('open');
  });
});
</script>
</body>
</html>