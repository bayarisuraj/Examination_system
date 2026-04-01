<?php
session_start();
require_once "../config/db.php";

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

$lecturer_id = (int)$_SESSION['user_id'];

// ── 1. Notifications this lecturer sent (from notifications table) ──
// No is_read column — removed. Table columns: id, lecturer_id, title, message, type, created_at
$stmt = $conn->prepare("
    SELECT id, title, message, type, created_at
    FROM notifications
    WHERE lecturer_id = ?
    ORDER BY created_at DESC
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$sent_notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Tag each as a sent notification
foreach ($sent_notifications as &$n) {
    $n['source'] = 'sent';
}
unset($n);

// ── 2. New students enrolled in this lecturer's courses (last 7 days) ──
$stmt2 = $conn->prepare("
    SELECT s.name, c.course_name, ce.enrolled_at
    FROM course_enrollments ce
    JOIN students s ON ce.student_id = s.id
    JOIN courses  c ON ce.course_id  = c.id
    WHERE c.lecturer_id = ?
      AND ce.enrolled_at >= NOW() - INTERVAL 7 DAY
    ORDER BY ce.enrolled_at DESC
");
$stmt2->bind_param("i", $lecturer_id);
$stmt2->execute();
$new_enrollments = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt2->close();

$activity = [];
foreach ($new_enrollments as $e) {
    $activity[] = [
        'title'      => 'New Student Enrolled',
        'message'    => htmlspecialchars($e['name']) . ' enrolled in ' . htmlspecialchars($e['course_name']) . '.',
        'type'       => 'info',
        'created_at' => $e['enrolled_at'],
        'source'     => 'activity',
    ];
}

// ── 3. Recent exam attempts on this lecturer's exams (last 7 days) ──
// Uses exam_attempts (end_time) — results table has no date_submitted column
$stmt3 = $conn->prepare("
    SELECT s.name  AS student_name,
           e.title AS exam_title,
           a.end_time
    FROM exam_attempts a
    JOIN students s ON a.student_id = s.id
    JOIN exams    e ON a.exam_id    = e.id
    WHERE e.lecturer_id = ?
      AND a.status      = 'completed'
      AND a.end_time   >= NOW() - INTERVAL 7 DAY
    ORDER BY a.end_time DESC
    LIMIT 20
");
$stmt3->bind_param("i", $lecturer_id);
$stmt3->execute();
$recent_attempts = $stmt3->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt3->close();

foreach ($recent_attempts as $a) {
    $activity[] = [
        'title'      => 'Exam Attempted',
        'message'    => htmlspecialchars($a['student_name']) . ' completed "' . htmlspecialchars($a['exam_title']) . '".',
        'type'       => 'success',
        'created_at' => $a['end_time'],
        'source'     => 'activity',
    ];
}

// ── Merge sent + activity, sort by date DESC ──
$all_notifications = array_merge($sent_notifications, $activity);
usort($all_notifications, fn($a, $b) =>
    strtotime($b['created_at']) <=> strtotime($a['created_at'])
);

$total = count($all_notifications);

// ── Filter by type ──
$filter = $_GET['filter'] ?? 'all';
$displayed = ($filter === 'all')
    ? $all_notifications
    : array_values(array_filter($all_notifications, fn($n) => $n['type'] === $filter));

include "../includes/header.php";
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=DM+Sans:wght@400;500;600&family=DM+Mono:wght@400;500&display=swap');

:root {
    --bg:      #0d1117;
    --surface: #161b22;
    --surface2:#1c2330;
    --border:  #30363d;
    --text:    #e6edf3;
    --muted:   #8b949e;
    --accent:  #a78bfa;
    --green:   #34d399;
    --red:     #fb7185;
    --amber:   #f59e0b;
    --purple:  #8b5cf6;
    --staff:   #22d3ee;
    --radius:  12px;
    --sans:    'DM Sans', sans-serif;
    --serif:   'Sora', sans-serif;
    --mono:    'DM Mono', monospace;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body { background: var(--bg); color: var(--text); font-family: var(--sans); min-height: 100vh; }

.page-shell { max-width: 820px; margin: 0 auto; padding: 2.5rem 1.25rem 5rem; }

/* ── Header ── */
.page-header {
    display: flex; align-items: flex-start;
    justify-content: space-between; flex-wrap: wrap;
    gap: 1rem; margin-bottom: 2rem;
    padding: 1rem 1.1rem;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: linear-gradient(180deg, rgba(139,92,246,.12), rgba(34,211,238,.05));
}
.page-header h1 { font-family: var(--serif); font-size: 1.55rem; font-weight: 700; }
.page-header .sub { font-size: .83rem; color: var(--muted); margin-top: .2rem; }

.btn-back {
    display: inline-flex; align-items: center; gap: .4rem;
    padding: .48rem 1rem; border-radius: 8px;
    border: 1px solid var(--border); background: var(--surface);
    color: var(--muted); font-size: .83rem; font-weight: 600;
    text-decoration: none; transition: all .18s;
}
.btn-back:hover { border-color: var(--accent); color: var(--accent); }

/* ── Filter tabs ── */
.filter-bar {
    display: flex; align-items: center;
    justify-content: space-between; flex-wrap: wrap;
    gap: .75rem; margin-bottom: 1.5rem;
}
.filter-tabs { display: flex; gap: .4rem; flex-wrap: wrap; }
.ftab {
    padding: .32rem .85rem; border-radius: 20px;
    border: 1.5px solid var(--border); background: transparent;
    color: var(--muted); font-size: .79rem; font-weight: 600;
    text-decoration: none; transition: all .18s; display: inline-block;
}
.ftab:hover, .ftab.active            { background: var(--text);   border-color: var(--text);   color: var(--bg); }
.ftab.f-info.active,   .ftab.f-info:hover    { background: var(--accent); border-color: var(--accent); color: #fff; }
.ftab.f-success.active,.ftab.f-success:hover { background: var(--green);  border-color: var(--green);  color: #fff; }
.ftab.f-warning.active,.ftab.f-warning:hover { background: var(--amber);  border-color: var(--amber);  color: #fff; }
.ftab.f-danger.active, .ftab.f-danger:hover  { background: var(--red);    border-color: var(--red);    color: #fff; }
.ftab.f-sent.active,   .ftab.f-sent:hover    { background: var(--purple); border-color: var(--purple); color: #fff; }

.n-count { font-size: .82rem; color: var(--muted); }

/* ── Notification cards ── */
.notif-list { display: flex; flex-direction: column; gap: .85rem; }

.notif-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 1.1rem 1.25rem;
    display: flex; gap: 1rem; align-items: flex-start;
    border-left: 4px solid transparent;
    animation: fadeUp .35s ease both;
    transition: box-shadow .2s, border-color .2s;
    position: relative;
    overflow: hidden;
}
.notif-card:hover { box-shadow: 0 6px 22px rgba(0,0,0,.35); }
.notif-card::after {
    content: "";
    position: absolute;
    top: 0;
    right: 0;
    width: 120px;
    height: 100%;
    pointer-events: none;
    opacity: .07;
    background: linear-gradient(135deg, transparent 35%, currentColor 100%);
}
@keyframes fadeUp { from { opacity:0; transform:translateY(10px); } to { opacity:1; transform:none; } }

.notif-card.info    { border-left-color: var(--staff); color: var(--staff); }
.notif-card.success { border-left-color: var(--green); color: var(--green); }
.notif-card.warning { border-left-color: var(--amber); color: var(--amber); }
.notif-card.danger  { border-left-color: var(--red); color: var(--red); }
.notif-card.sent    { border-left-color: var(--purple); color: var(--purple); }

.notif-icon {
    width: 38px; height: 38px; border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1rem; flex-shrink: 0;
}
.notif-card.info    .notif-icon { background: rgba(34,211,238,.14);  color: var(--staff); }
.notif-card.success .notif-icon { background: rgba(52,211,153,.14);  color: var(--green); }
.notif-card.warning .notif-icon { background: rgba(245,158,11,.14);  color: var(--amber); }
.notif-card.danger  .notif-icon { background: rgba(251,113,133,.14); color: var(--red); }
.notif-card.sent    .notif-icon { background: rgba(139,92,246,.16);  color: var(--purple); }

.notif-body { flex: 1; min-width: 0; }
.notif-title { font-family: var(--serif); font-size: .95rem; font-weight: 700; color: var(--text); margin-bottom: .25rem; }
.notif-message { font-size: .84rem; color: var(--muted); line-height: 1.55; margin-bottom: .5rem; }
.notif-meta { display: flex; align-items: center; gap: .75rem; flex-wrap: wrap; }

.nbadge {
    display: inline-flex; align-items: center; gap: .3rem;
    padding: .15rem .55rem; border-radius: 20px;
    font-size: .7rem; font-weight: 700; font-family: var(--mono);
}
.nbadge.info    { background: rgba(34,211,238,.12);  color: var(--staff);  border: 1px solid rgba(34,211,238,.3); }
.nbadge.success { background: rgba(52,211,153,.12);  color: var(--green);  border: 1px solid rgba(52,211,153,.3); }
.nbadge.warning { background: rgba(245,158,11,.12);  color: var(--amber);  border: 1px solid rgba(245,158,11,.3); }
.nbadge.danger  { background: rgba(251,113,133,.12); color: var(--red);    border: 1px solid rgba(251,113,133,.3); }
.nbadge.sent    { background: rgba(139,92,246,.12);  color: var(--purple); border: 1px solid rgba(139,92,246,.3); }

.meta-time { font-family: var(--mono); font-size: .74rem; color: var(--muted); display: flex; align-items: center; gap: .3rem; }

/* ── Empty state ── */
.empty-state { text-align: center; padding: 4rem 2rem; color: var(--muted); }
.empty-state i { font-size: 3rem; display: block; margin-bottom: 1rem; color: var(--border); }
.empty-state h5 { font-family: var(--serif); color: var(--text); margin-bottom: .4rem; }

.btn-view-all {
    display: inline-flex; align-items: center; gap: .4rem;
    margin-top: 1rem; padding: .45rem 1.1rem; border-radius: 8px;
    border: 1px solid var(--border); background: var(--surface);
    color: var(--muted); font-size: .83rem; font-weight: 600;
    text-decoration: none; transition: all .18s;
}
.btn-view-all:hover { border-color: var(--accent); color: var(--accent); }

@media (max-width: 600px) {
    .page-shell { padding: 1.25rem .75rem 4rem; }
    .page-header h1 { font-size: 1.3rem; }
    .notif-card { flex-direction: column; gap: .65rem; }
}
</style>

<div class="page-shell">

    <!-- Header -->
    <div class="page-header">
        <div>
            <h1>
                <i class="bi bi-bell-fill" style="color:var(--accent);margin-right:.4rem"></i>
                Notifications
            </h1>
            <div class="sub">
                <?= $total ?> notification<?= $total !== 1 ? 's' : '' ?> total
                &nbsp;&middot;&nbsp;
                Activity from the last 7 days
            </div>
        </div>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <a href="send_notification.php" class="btn-back" style="border-color:var(--purple);color:var(--purple)">
                <i class="bi bi-send-fill"></i> Send New
            </a>
            <a href="dashboard.php" class="btn-back">
                <i class="bi bi-arrow-left"></i> Dashboard
            </a>
        </div>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
        <div class="filter-tabs">
            <?php
            $tabs = [
                'all'     => ['All',      ''],
                'info'    => ['Enrolled',  'f-info'],
                'success' => ['Attempted', 'f-success'],
                'warning' => ['Warning',   'f-warning'],
                'danger'  => ['Urgent',    'f-danger'],
            ];
            foreach ($tabs as $key => [$label, $cls]):
                $active = $filter === $key ? 'active' : '';
            ?>
            <a href="?filter=<?= $key ?>"
               class="ftab <?= $cls ?> <?= $active ?>">
                <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>
        <span class="n-count" id="nCount"><?= count($displayed) ?> shown</span>
    </div>

    <!-- List -->
    <div class="notif-list">

        <?php if (empty($displayed)): ?>
        <div class="empty-state">
            <i class="bi bi-bell-slash"></i>
            <h5>No Notifications</h5>
            <p><?= $filter !== 'all' ? 'No notifications of this type.' : 'No activity in the last 7 days.' ?></p>
            <?php if ($filter !== 'all'): ?>
            <a href="notifications.php" class="btn-view-all">
                <i class="bi bi-list-ul"></i> View All
            </a>
            <?php endif; ?>
        </div>

        <?php else:
            $type_icons = [
                'info'    => 'bi-info-circle-fill',
                'success' => 'bi-check-circle-fill',
                'warning' => 'bi-exclamation-triangle-fill',
                'danger'  => 'bi-x-octagon-fill',
            ];
            foreach ($displayed as $i => $note):
                $type   = $note['type'] ?? 'info';
                $source = $note['source'] ?? 'activity';
                $icon   = $type_icons[$type] ?? 'bi-bell-fill';
                // Sent notifications get a purple "Sent" badge alongside type badge
                $card_class = ($source === 'sent') ? 'sent' : $type;
        ?>
        <div class="notif-card <?= $card_class ?>"
             style="animation-delay:<?= min($i * 0.05, 0.4) ?>s">

            <div class="notif-icon">
                <i class="bi <?= $source === 'sent' ? 'bi-send-fill' : $icon ?>"></i>
            </div>

            <div class="notif-body">
                <div class="notif-title"><?= htmlspecialchars($note['title']) ?></div>
                <div class="notif-message"><?= nl2br(htmlspecialchars($note['message'])) ?></div>
                <div class="notif-meta">
                    <?php if ($source === 'sent'): ?>
                    <span class="nbadge sent"><i class="bi bi-send-fill"></i> Sent by you</span>
                    <?php else: ?>
                    <span class="nbadge <?= $type ?>">
                        <i class="bi <?= $icon ?>"></i> <?= ucfirst($type) ?>
                    </span>
                    <?php endif; ?>
                    <span class="meta-time">
                        <i class="bi bi-clock"></i>
                        <?= date('d M Y, H:i', strtotime($note['created_at'])) ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; endif; ?>

    </div>

</div>

<?php include "../includes/footer.php"; ?>