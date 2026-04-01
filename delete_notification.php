<?php
session_start();

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

require_once "../config/db.php";

// ── Fetch all notifications (broadcast = no student filter needed) ─
$notifications = $conn->query("
    SELECT
        n.id,
        n.title,
        n.message,
        n.type,
        n.created_at,
        l.name        AS lecturer_name,
        l.department  AS lecturer_dept
    FROM notifications n
    JOIN lecturers l ON l.id = n.lecturer_id
    ORDER BY n.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

$total = count($notifications);

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
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:var(--sans);min-height:100vh}

.page-shell{max-width:780px;margin:0 auto;padding:2.5rem 1.25rem 5rem}

.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:2rem}
.page-header h1{font-family:var(--serif);font-size:1.55rem;font-weight:700}
.page-header .sub{font-size:.83rem;color:var(--muted)}
.btn-back{display:inline-flex;align-items:center;gap:.4rem;padding:.48rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--muted);font-size:.83rem;font-weight:600;text-decoration:none;transition:all .18s}
.btn-back:hover{border-color:var(--accent);color:var(--accent)}

/* Filter bar */
.filter-bar{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem}
.filter-tabs{display:flex;gap:.4rem;flex-wrap:wrap}
.ftab{padding:.32rem .8rem;border-radius:20px;border:1.5px solid var(--border);background:transparent;color:var(--muted);font-size:.78rem;font-weight:600;cursor:pointer;transition:all .18s}
.ftab:hover,.ftab.active{background:var(--text);border-color:var(--text);color:var(--bg)}
.ftab.f-info.active,.ftab.f-info:hover      {background:var(--accent);border-color:var(--accent);color:#fff}
.ftab.f-success.active,.ftab.f-success:hover{background:var(--green); border-color:var(--green); color:#fff}
.ftab.f-warning.active,.ftab.f-warning:hover{background:var(--amber); border-color:var(--amber); color:#fff}
.ftab.f-danger.active,.ftab.f-danger:hover  {background:var(--red);   border-color:var(--red);   color:#fff}
.n-count{font-size:.82rem;color:var(--muted)}

/* ── Notification cards ── */
.notif-list{display:flex;flex-direction:column;gap:.85rem}

.notif-card{
    background:var(--surface);
    border:1px solid var(--border);
    border-radius:var(--radius);
    padding:1.1rem 1.25rem;
    display:flex;gap:1rem;align-items:flex-start;
    border-left:4px solid transparent;
    animation:fadeUp .35s ease both;
    transition:border-color .2s,box-shadow .2s
}
.notif-card:hover{box-shadow:0 6px 20px rgba(0,0,0,.35)}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}

.notif-card.info   {border-left-color:var(--accent)}
.notif-card.success{border-left-color:var(--green)}
.notif-card.warning{border-left-color:var(--amber)}
.notif-card.danger {border-left-color:var(--red)}

.notif-icon{
    width:38px;height:38px;border-radius:10px;
    display:flex;align-items:center;justify-content:center;
    font-size:1rem;flex-shrink:0
}
.notif-card.info    .notif-icon{background:rgba(88,166,255,.12);color:var(--accent)}
.notif-card.success .notif-icon{background:rgba(63,185,80,.12); color:var(--green)}
.notif-card.warning .notif-icon{background:rgba(210,153,34,.12);color:var(--amber)}
.notif-card.danger  .notif-icon{background:rgba(248,81,73,.12); color:var(--red)}

.notif-body{flex:1;min-width:0}
.notif-title{font-family:var(--serif);font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:.25rem}
.notif-message{font-size:.84rem;color:var(--muted);line-height:1.55;margin-bottom:.55rem}
.notif-meta{display:flex;align-items:center;gap:1rem;flex-wrap:wrap}
.meta-item{display:flex;align-items:center;gap:.3rem;font-size:.74rem;color:var(--muted);font-family:var(--mono)}
.meta-item i{font-size:.75rem}

.nbadge{display:inline-flex;align-items:center;gap:.3rem;padding:.15rem .55rem;border-radius:20px;font-size:.7rem;font-weight:700;font-family:var(--mono)}
.nbadge.info   {background:rgba(88,166,255,.12);color:var(--accent);border:1px solid rgba(88,166,255,.3)}
.nbadge.success{background:rgba(63,185,80,.12); color:var(--green); border:1px solid rgba(63,185,80,.3)}
.nbadge.warning{background:rgba(210,153,34,.12);color:var(--amber); border:1px solid rgba(210,153,34,.3)}
.nbadge.danger {background:rgba(248,81,73,.12); color:var(--red);   border:1px solid rgba(248,81,73,.3)}

/* Empty state */
.empty-state{text-align:center;padding:4rem 2rem;color:var(--muted)}
.empty-state i{font-size:3rem;display:block;margin-bottom:1rem;color:var(--border)}
.empty-state h5{font-family:var(--serif);color:var(--text);margin-bottom:.4rem}

@media(max-width:600px){
    .page-shell{padding:1.25rem .75rem 4rem}
    .page-header h1{font-size:1.3rem}
    .notif-card{flex-direction:column;gap:.65rem}
}
</style>

<div class="page-shell">

    <div class="page-header">
        <div>
            <h1>
                <i class="bi bi-bell-fill" style="color:var(--accent);margin-right:.4rem"></i>
                Notifications
            </h1>
            <div class="sub"><?= $total ?> notification<?= $total !== 1 ? 's' : '' ?></div>
        </div>
        <a href="dashboard.php" class="btn-back"><i class="bi bi-arrow-left"></i> Dashboard</a>
    </div>

    <!-- Filter bar -->
    <div class="filter-bar">
        <div class="filter-tabs">
            <button class="ftab active" data-filter="all">All</button>
            <button class="ftab f-info"    data-filter="info">Info</button>
            <button class="ftab f-success" data-filter="success">Success</button>
            <button class="ftab f-warning" data-filter="warning">Warning</button>
            <button class="ftab f-danger"  data-filter="danger">Danger</button>
        </div>
        <span class="n-count" id="nCount"><?= $total ?> shown</span>
    </div>

    <!-- List -->
    <div class="notif-list" id="notifList">

        <?php if ($total === 0): ?>
        <div class="empty-state">
            <i class="bi bi-bell-slash"></i>
            <h5>No Notifications</h5>
            <p>Your lecturers haven't sent any notifications yet.</p>
        </div>
        <?php else: ?>

        <?php
        $type_icons = [
            'info'    => 'bi-info-circle-fill',
            'success' => 'bi-check-circle-fill',
            'warning' => 'bi-exclamation-triangle-fill',
            'danger'  => 'bi-x-octagon-fill',
        ];
        foreach ($notifications as $i => $n):
            $icon = $type_icons[$n['type']] ?? 'bi-bell-fill';
        ?>
        <div class="notif-card <?= $n['type'] ?>"
             data-type="<?= $n['type'] ?>"
             style="animation-delay:<?= min($i * 0.05, 0.4) ?>s">

            <div class="notif-icon">
                <i class="bi <?= $icon ?>"></i>
            </div>

            <div class="notif-body">
                <div class="notif-title"><?= htmlspecialchars($n['title']) ?></div>
                <div class="notif-message"><?= nl2br(htmlspecialchars($n['message'])) ?></div>
                <div class="notif-meta">
                    <span class="nbadge <?= $n['type'] ?>">
                        <i class="bi <?= $icon ?>"></i> <?= ucfirst($n['type']) ?>
                    </span>
                    <span class="meta-item">
                        <i class="bi bi-person-fill"></i>
                        <?= htmlspecialchars($n['lecturer_name']) ?>
                        <?php if (!empty($n['lecturer_dept'])): ?>
                        &middot; <?= htmlspecialchars($n['lecturer_dept']) ?>
                        <?php endif; ?>
                    </span>
                    <span class="meta-item">
                        <i class="bi bi-clock"></i>
                        <?= date('d M Y, H:i', strtotime($n['created_at'])) ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <?php endif; ?>

    </div><!-- /notif-list -->

</div><!-- /page-shell -->

<script>
let activeFilter = 'all';

function applyFilter() {
    const cards   = document.querySelectorAll('.notif-card');
    let   visible = 0;
    cards.forEach(card => {
        const show = activeFilter === 'all' || card.dataset.type === activeFilter;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('nCount').textContent =
        visible + ' shown';
}

document.querySelectorAll('.ftab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.ftab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        activeFilter = tab.dataset.filter;
        applyFilter();
    });
});
</script>

<?php include "../includes/footer.php"; ?>