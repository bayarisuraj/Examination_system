<?php
session_start();
require_once "../config/db.php";

if(!isset($_SESSION['role']) || $_SESSION['role'] != "lecturer"){
    header("Location: ../auth/login.php");
    exit();
}

$lecturer_id = (int)($_SESSION['user_id'] ?? 0);
$lecturer_name = $_SESSION['full_name'] ?? 'Lecturer';

$stmt = $conn->prepare("
    SELECT e.*, c.course_name
    FROM exams e
    JOIN courses c ON e.course_id = c.id
    WHERE e.lecturer_id = ? AND e.exam_date >= CURDATE()
    ORDER BY e.exam_date ASC
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$upcoming_exams = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$today = date("Y-m-d");
$exam_count = count($upcoming_exams);
$today_count = count(array_filter($upcoming_exams, fn($e) => date("Y-m-d", strtotime($e['exam_date'])) === $today));
?>
<?php include "../includes/header.php"; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Scheduled Exams | ExamPro</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
  :root {
    --bg: #0d0f14;
    --surface: #151820;
    --card: #1c2030;
    --card-hover: #202438;
    --border: #252a3a;
    --border-hover: rgba(79,142,247,0.4);
    --accent: #4f8ef7;
    --accent2: #a78bfa;
    --success: #34d399;
    --danger: #f87171;
    --warning: #fbbf24;
    --orange: #fb923c;
    --text: #e8eaf0;
    --muted: #6b7280;
    --sidebar-w: 240px;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: 'DM Sans', sans-serif;
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
  }

  /* ── SIDEBAR ── */
  .sidebar {
    width: var(--sidebar-w);
    background: var(--surface);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    position: fixed;
    height: 100vh;
    z-index: 100;
    transition: transform 0.3s;
  }
  .sidebar-logo {
    padding: 24px 20px;
    font-family: 'Syne', sans-serif;
    font-size: 1.15rem;
    font-weight: 800;
    color: var(--accent);
    letter-spacing: -0.5px;
    border-bottom: 1px solid var(--border);
    flex-shrink: 0;
  }
  .sidebar-logo span { color: var(--text); }

  .nav-section {
    padding: 18px 16px 6px;
    font-size: 0.63rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 1.6px;
    font-weight: 600;
  }
  .nav-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px 16px;
    margin: 2px 8px;
    border-radius: 8px;
    color: var(--muted);
    text-decoration: none;
    font-size: 0.875rem;
    transition: all 0.2s;
    position: relative;
  }
  .nav-item:hover { background: rgba(79,142,247,0.08); color: var(--text); }
  .nav-item.active {
    background: rgba(79,142,247,0.12);
    color: var(--accent);
  }
  .nav-item.active::before {
    content: '';
    position: absolute;
    left: -8px;
    top: 50%;
    transform: translateY(-50%);
    width: 3px;
    height: 20px;
    background: var(--accent);
    border-radius: 0 3px 3px 0;
  }
  .nav-item i { width: 16px; text-align: center; font-size: 0.9rem; }

  .sidebar-footer {
    margin-top: auto;
    padding: 16px;
    border-top: 1px solid var(--border);
  }
  .sidebar-user { display: flex; align-items: center; gap: 10px; }
  .avatar {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    font-family: 'Syne', sans-serif;
    font-weight: 700; font-size: 0.85rem;
    flex-shrink: 0;
  }
  .user-info .name { font-size: 0.85rem; font-weight: 500; }
  .user-info .role { font-size: 0.72rem; color: var(--muted); margin-top: 1px; }

  /* ── MAIN ── */
  .main { margin-left: var(--sidebar-w); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

  /* ── TOPBAR ── */
  .topbar {
    padding: 0 32px;
    height: 62px;
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 50;
  }
  .topbar-left { display: flex; align-items: center; gap: 14px; }
  .page-title {
    font-family: 'Syne', sans-serif;
    font-size: 1.15rem;
    font-weight: 700;
  }
  .breadcrumb-sep { color: var(--border); font-size: 1.1rem; }
  .breadcrumb-current { color: var(--muted); font-size: 0.85rem; }

  .topbar-right { display: flex; align-items: center; gap: 10px; }

  /* ── BUTTONS ── */
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 9px 18px;
    border-radius: 8px;
    font-size: 0.84rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: all 0.2s;
    font-family: 'DM Sans', sans-serif;
    white-space: nowrap;
  }
  .btn-primary { background: var(--accent); color: #fff; }
  .btn-primary:hover { background: #3a7bf0; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(79,142,247,0.35); }
  .btn-ghost { background: transparent; color: var(--muted); border: 1px solid var(--border); }
  .btn-ghost:hover { color: var(--text); border-color: rgba(79,142,247,0.4); }
  .btn-sm { padding: 6px 13px; font-size: 0.78rem; gap: 5px; }
  .btn-icon { padding: 8px; width: 36px; height: 36px; justify-content: center; }

  /* ── CONTENT ── */
  .content { padding: 30px 32px; flex: 1; }

  /* ── STATS ROW ── */
  .stats-row {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 14px;
    margin-bottom: 28px;
  }
  .stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 18px 22px;
    display: flex;
    align-items: center;
    gap: 16px;
    transition: border-color 0.2s;
  }
  .stat-card:hover { border-color: var(--border-hover); }
  .stat-icon {
    width: 44px; height: 44px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.1rem;
    flex-shrink: 0;
  }
  .stat-icon.blue { background: rgba(79,142,247,0.12); color: var(--accent); }
  .stat-icon.green { background: rgba(52,211,153,0.12); color: var(--success); }
  .stat-icon.purple { background: rgba(167,139,250,0.12); color: var(--accent2); }
  .stat-label { font-size: 0.72rem; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px; }
  .stat-value { font-family: 'Syne', sans-serif; font-size: 1.65rem; font-weight: 800; line-height: 1; }

  /* ── SECTION HEADER ── */
  .section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 18px;
  }
  .section-title {
    font-family: 'Syne', sans-serif;
    font-size: 0.95rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .section-title .count-pill {
    background: rgba(79,142,247,0.12);
    color: var(--accent);
    font-size: 0.72rem;
    padding: 2px 9px;
    border-radius: 20px;
    font-family: 'DM Sans', sans-serif;
    font-weight: 600;
  }

  /* ── EXAM GRID ── */
  .exam-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(330px, 1fr));
    gap: 16px;
  }

  /* ── EXAM CARD ── */
  .exam-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 14px;
    padding: 22px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    transition: all 0.25s;
    position: relative;
    overflow: hidden;
    animation: fadeUp 0.4s ease both;
  }
  .exam-card:nth-child(1) { animation-delay: 0.05s; }
  .exam-card:nth-child(2) { animation-delay: 0.1s; }
  .exam-card:nth-child(3) { animation-delay: 0.15s; }
  .exam-card:nth-child(4) { animation-delay: 0.2s; }
  .exam-card:nth-child(5) { animation-delay: 0.25s; }
  .exam-card:nth-child(6) { animation-delay: 0.3s; }

  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .exam-card:hover {
    border-color: var(--border-hover);
    transform: translateY(-3px);
    box-shadow: 0 12px 32px rgba(0,0,0,0.35);
  }
  .exam-card.is-today {
    border-color: rgba(52,211,153,0.4);
  }
  .exam-card.is-today::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--success), rgba(52,211,153,0));
  }

  /* Card top accent line for non-today */
  .exam-card:not(.is-today)::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--accent), rgba(79,142,247,0));
  }

  .card-top { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; }

  .card-title-wrap { flex: 1; min-width: 0; }
  .card-course {
    font-size: 0.7rem;
    color: var(--accent);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 600;
    margin-bottom: 5px;
  }
  .card-title {
    font-family: 'Syne', sans-serif;
    font-size: 1rem;
    font-weight: 700;
    line-height: 1.3;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .today-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: rgba(52,211,153,0.12);
    color: var(--success);
    border: 1px solid rgba(52,211,153,0.3);
    font-size: 0.7rem;
    font-weight: 600;
    padding: 3px 10px;
    border-radius: 20px;
    flex-shrink: 0;
    white-space: nowrap;
  }
  .today-badge i { font-size: 0.62rem; }

  /* Card meta */
  .card-meta { display: flex; flex-direction: column; gap: 7px; }
  .meta-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.83rem;
    color: var(--muted);
  }
  .meta-row i { width: 14px; text-align: center; font-size: 0.8rem; flex-shrink: 0; }
  .meta-row strong { color: var(--text); font-weight: 500; }

  .meta-chips { display: flex; gap: 7px; flex-wrap: wrap; margin-top: 2px; }
  .chip {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 4px 11px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
    border: 1px solid transparent;
  }
  .chip-blue { background: rgba(79,142,247,0.1); color: var(--accent); border-color: rgba(79,142,247,0.2); }
  .chip-purple { background: rgba(167,139,250,0.1); color: var(--accent2); border-color: rgba(167,139,250,0.2); }

  /* Card actions */
  .card-actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
    padding-top: 14px;
    border-top: 1px solid var(--border);
  }
  .btn-action {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 7px 13px;
    border-radius: 7px;
    font-size: 0.78rem;
    font-weight: 500;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: all 0.18s;
    font-family: 'DM Sans', sans-serif;
  }
  .btn-action.green  { background: rgba(52,211,153,0.1);  color: var(--success); border: 1px solid rgba(52,211,153,0.25); }
  .btn-action.blue   { background: rgba(79,142,247,0.1);  color: var(--accent);  border: 1px solid rgba(79,142,247,0.25); }
  .btn-action.yellow { background: rgba(251,191,36,0.1);  color: var(--warning); border: 1px solid rgba(251,191,36,0.25); }
  .btn-action.red    { background: rgba(248,113,113,0.1); color: var(--danger);  border: 1px solid rgba(248,113,113,0.25); }
  .btn-action:hover  { filter: brightness(1.15); transform: translateY(-1px); }

  /* ── EMPTY STATE ── */
  .empty-state {
    text-align: center;
    padding: 70px 24px;
    color: var(--muted);
  }
  .empty-icon {
    width: 72px; height: 72px;
    margin: 0 auto 20px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 18px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.8rem;
    color: var(--border);
  }
  .empty-state h3 { font-family: 'Syne', sans-serif; font-size: 1rem; color: var(--text); margin-bottom: 8px; }
  .empty-state p { font-size: 0.87rem; max-width: 280px; margin: 0 auto 20px; }

  /* ── SCROLLBAR ── */
  ::-webkit-scrollbar { width: 5px; }
  ::-webkit-scrollbar-track { background: var(--bg); }
  ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

  /* ── RESPONSIVE ── */
  @media (max-width: 900px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
  }
  @media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); }
    .main { margin-left: 0; }
    .content { padding: 20px 16px; }
    .topbar { padding: 0 16px; }
    .stats-row { grid-template-columns: 1fr; }
    .exam-grid { grid-template-columns: 1fr; }
  }
</style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
  <div class="sidebar-logo">Exam<span>Pro</span></div>

  <div class="nav-section">Main</div>
  <a href="dashboard.php" class="nav-item"><i class="fas fa-th-large"></i> Dashboard</a>
  <a href="manage_courses.php" class="nav-item"><i class="fas fa-book"></i> Courses</a>
  <a href="manage_exams.php" class="nav-item active"><i class="fas fa-file-alt"></i> Exams</a>
  <a href="question_bank.php" class="nav-item"><i class="fas fa-database"></i> Question Bank</a>
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

<!-- ── MAIN ── -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <div class="topbar-left">
      <div class="page-title"><i class="fas fa-calendar-alt" style="color:var(--accent);margin-right:10px;"></i>Scheduled Exams</div>
    </div>
    <div class="topbar-right">
      <a href="dashboard.php" class="btn btn-ghost">
        <i class="fas fa-arrow-left"></i> Back
      </a>
      <a href="create_exams.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Create Exam
      </a>
    </div>
  </div>

  <div class="content">

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-icon blue"><i class="fas fa-calendar-check"></i></div>
        <div>
          <div class="stat-label">Upcoming Exams</div>
          <div class="stat-value" style="color:var(--accent);"><?= $exam_count ?></div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon green"><i class="fas fa-bolt"></i></div>
        <div>
          <div class="stat-label">Scheduled Today</div>
          <div class="stat-value" style="color:var(--success);"><?= $today_count ?></div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon purple"><i class="fas fa-book-open"></i></div>
        <div>
          <div class="stat-label">Next Exam</div>
          <div class="stat-value" style="color:var(--accent2);font-size:1.05rem;padding-top:4px;">
            <?= !empty($upcoming_exams) ? date("d M", strtotime($upcoming_exams[0]['exam_date'])) : '—' ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Section header -->
    <div class="section-header">
      <div class="section-title">
        All Upcoming Exams
        <?php if ($exam_count > 0): ?>
        <span class="count-pill"><?= $exam_count ?></span>
        <?php endif; ?>
      </div>
    </div>

    <!-- Exam Cards -->
    <?php if (!empty($upcoming_exams)): ?>
    <div class="exam-grid">
      <?php foreach ($upcoming_exams as $e):
        $exam_date_str = date("Y-m-d", strtotime($e['exam_date']));
        $is_today = $exam_date_str === $today;
      ?>
      <div class="exam-card <?= $is_today ? 'is-today' : '' ?>">

        <div class="card-top">
          <div class="card-title-wrap">
            <div class="card-course"><?= htmlspecialchars($e['course_name']) ?></div>
            <div class="card-title" title="<?= htmlspecialchars($e['title']) ?>">
              <?= htmlspecialchars($e['title']) ?>
            </div>
          </div>
          <?php if ($is_today): ?>
          <div class="today-badge"><i class="fas fa-circle"></i> Today</div>
          <?php endif; ?>
        </div>

        <div class="card-meta">
          <div class="meta-row">
            <i class="fas fa-calendar"></i>
            <strong><?= date("d M Y", strtotime($e['exam_date'])) ?></strong>
          </div>
          <div class="meta-chips">
            <span class="chip chip-blue"><i class="fas fa-clock"></i> <?= $e['duration'] ?> min</span>
            <span class="chip chip-purple"><i class="fas fa-star"></i> <?= $e['total_marks'] ?> marks</span>
          </div>
        </div>

        <div class="card-actions">
          <a href="add_question.php?exam_id=<?= $e['id'] ?>" class="btn-action green">
            <i class="fas fa-plus-circle"></i> Add Questions
          </a>
          <a href="view_question.php?exam_id=<?= $e['id'] ?>" class="btn-action blue">
            <i class="fas fa-list-ul"></i> View
          </a>
          <a href="edit_exam.php?exam_id=<?= $e['id'] ?>" class="btn-action yellow">
            <i class="fas fa-pen"></i> Edit
          </a>
          <a href="delete_exam.php?exam_id=<?= $e['id'] ?>"
             class="btn-action red"
             onclick="return confirm('Delete this exam? This cannot be undone.')">
            <i class="fas fa-trash"></i>
          </a>
        </div>

      </div>
      <?php endforeach; ?>
    </div>

    <?php else: ?>
    <div class="empty-state">
      <div class="empty-icon"><i class="fas fa-calendar-times"></i></div>
      <h3>No Upcoming Exams</h3>
      <p>You have no exams scheduled. Create one to get started.</p>
      <a href="create_exams.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> Create Exam
      </a>
    </div>
    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<?php include "../includes/footer.php"; ?>
</body>
</html>