<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect if not logged in
if (!isset($_SESSION['role'])) {
    header("Location: ../auth/login.php");
    exit();
}

$username = $_SESSION['name'] ?? $_SESSION['username'] ?? "User";
$role     = $_SESSION['role'];

// ── Profile Image Resolution ──────────────────────────────────────
$profileImage = $_SESSION['profile_image'] ?? '';
$profileImage = ltrim(preg_replace('#^(\.\./|\./)+#', '', $profileImage), '/');
if ($profileImage && file_exists('../' . $profileImage)) {
    $profileImage = '../' . $profileImage;
} else {
    $profileImage = '../assets/img/avatar.png';
}

// ── Notification page URL per role ────────────────────────────────
$notif_url = 'notifications.php'; // always same filename, role folder handles context

// ── Nav items per role ────────────────────────────────────────────
$navItems = [
    'lecturer' => [
        ['url'=>'dashboard.php',         'icon'=>'bi-house-door-fill',   'label'=>'Dashboard'],
        ['url'=>'manage_courses.php',    'icon'=>'bi-journal-bookmark',  'label'=>'My Courses'],
        ['url'=>'manage_exams.php',      'icon'=>'bi-pencil-square',     'label'=>'My Exams'],
        ['label'=>'Exam Tools', 'icon'=>'bi-tools', 'dropdown'=>[
            ['url'=>'create_exams.php',    'label'=>'Create Exam'],
            ['url'=>'view_exams.php',      'label'=>'View Exams'],
            ['url'=>'schedule_exams.php',  'label'=>'Schedule Exams'],
            ['url'=>'view_question.php',   'label'=>'View Questions'],
        ]],
        ['url'=>'view_students.php',     'icon'=>'bi-people-fill',       'label'=>'Enrolled Students'],
        ['url'=>'results.php',           'icon'=>'bi-file-earmark-text', 'label'=>'Student Results'],
        ['url'=>'ai_search.php',         'icon'=>'bi-search-heart',      'label'=>'AI Search'],
        ['url'=>'qr_code/qr_code.php',            'icon'=>'bi-phone',              'label'=>'QR Code Access'],
        ['url'=>'send_notification.php', 'icon'=>'bi-send-fill',         'label'=>'Send Notification'],
        ['url'=>'notifications.php',     'icon'=>'bi-bell-fill',         'label'=>'Notifications'],
    ],

    'student' => [
        ['url'=>'dashboard.php',         'icon'=>'bi-house-door-fill',   'label'=>'Dashboard'],
        ['url'=>'my_courses.php',        'icon'=>'bi-journal-bookmark',  'label'=>'My Courses'],
        ['url'=>'available_exams.php',   'icon'=>'bi-pencil-square',     'label'=>'Take Exams'],
        ['url'=>'my_results.php',        'icon'=>'bi-file-earmark-text', 'label'=>'My Results'],
        ['url'=>'notifications.php',     'icon'=>'bi-bell-fill',         'label'=>'Notifications'],
        ['url'=>'help.php',              'icon'=>'bi-question-circle',   'label'=>'Help / Support'],
    ],
    'admin' => [
        ['url'=>'dashboard.php',         'icon'=>'bi-house-door-fill',   'label'=>'Dashboard'],
        ['url'=>'manage_users.php',      'icon'=>'bi-people-fill',       'label'=>'Manage Users'],
        ['url'=>'manage_courses.php',    'icon'=>'bi-journal-bookmark',  'label'=>'Courses'],
        ['url'=>'manage_exams.php',      'icon'=>'bi-pencil-square',     'label'=>'Exams'],
        ['url'=>'notifications.php',     'icon'=>'bi-bell-fill',         'label'=>'Notifications'],
        ['url'=>'settings.php',          'icon'=>'bi-gear-fill',         'label'=>'System Settings'],
    ],
];

$menu    = $navItems[$role] ?? [];
$current = basename($_SERVER['PHP_SELF']);

// ── Unread count for initial badge render ─────────────────────────
$notif_count = 0;
$last_seen   = $_SESSION['notif_last_seen'] ?? '1970-01-01 00:00:00';
if (isset($conn) && in_array($role, ['lecturer', 'student', 'admin'])) {
    $ns = $conn->prepare("SELECT COUNT(*) AS cnt FROM notifications WHERE created_at > ?");
    if ($ns) {
        $ns->bind_param("s", $last_seen);
        $ns->execute();
        $notif_count = (int)$ns->get_result()->fetch_assoc()['cnt'];
        $ns->close();
    }
}

// ── Bell dropdown preview (latest 5) ─────────────────────────────
$preview_notifs = [];
if (isset($conn)) {
    $pn = $conn->prepare("
        SELECT n.title, n.message, n.type, n.created_at, l.name AS sender
        FROM notifications n
        JOIN lecturers l ON l.id = n.lecturer_id
        ORDER BY n.created_at DESC
        LIMIT 5
    ");
    if ($pn) {
        $pn->execute();
        $preview_notifs = $pn->get_result()->fetch_all(MYSQLI_ASSOC);
        $pn->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- <meta http-equiv="refresh" content="5"> -->
    <title>OES — Online Examination System</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
    :root {
        --sidebar-width: 240px;
        --navbar-height: 56px;
        --teal:          #3d8b8d;
        --teal-dark:     #2d6e70;
        --teal-light:    #56a8aa;
        --teal-pale:     #eaf5f5;
        --teal-border:   #c0dfe0;
    }

    body { margin: 0; padding: 0; background-color: #f8f9fa; }

    /* ── Navbar ── */
    .navbar { height: var(--navbar-height); background-color: var(--teal) !important; }
    .navbar-brand { display:flex; align-items:center; gap:10px; text-decoration:none; }
    .navbar-brand .brand-icon {
        width:34px; height:34px; background:rgba(255,255,255,.95);
        border-radius:50%; display:grid; place-items:center;
        color:var(--teal); font-size:1.05rem; flex-shrink:0;
    }
    .navbar-brand .brand-text .uni {
        font-size:.62rem; font-weight:700; letter-spacing:.05em;
        text-transform:uppercase; color:rgba(255,255,255,.78); line-height:1;
    }
    .navbar-brand .brand-text .sys {
        font-size:.88rem; font-weight:700; color:#fff; line-height:1.3;
    }
    .navbar .avatar { width:35px; height:35px; border-radius:50%; object-fit:cover; }
    .navbar .btn-outline-light { border-color:rgba(255,255,255,.45); color:#fff; }
    .navbar .btn-outline-light:hover {
        background-color:rgba(255,255,255,.18);
        border-color:rgba(255,255,255,.7); color:#fff;
    }

    /* ── Bell ── */
    .bell-wrap { position:relative; }
    .bell-btn {
        position:relative; display:inline-flex; align-items:center; justify-content:center;
        width:38px; height:38px; border-radius:8px;
        border:1px solid rgba(255,255,255,.45);
        color:#fff; font-size:1rem; background:transparent; cursor:pointer;
        transition:background .18s;
    }
    .bell-btn:hover, .bell-btn.open { background:rgba(255,255,255,.18); }
    .bell-badge {
        position:absolute; top:-4px; right:-4px;
        background:#f85149; color:#fff;
        font-size:.58rem; font-weight:700;
        min-width:16px; height:16px; border-radius:20px;
        display:flex; align-items:center; justify-content:center;
        padding:0 3px; pointer-events:none;
    }

    /* ── Bell panel ── */
    .bell-panel {
        display:none; position:absolute; top:calc(100% + 10px); right:0;
        width:320px; background:#161b22; border:1px solid #30363d;
        border-radius:12px; box-shadow:0 12px 40px rgba(0,0,0,.55);
        z-index:9999; overflow:hidden; animation:dropIn .2s ease both;
    }
    .bell-panel.show { display:block; }
    @keyframes dropIn {
        from { opacity:0; transform:translateY(-8px); }
        to   { opacity:1; transform:translateY(0); }
    }
    .bell-panel-head {
        display:flex; align-items:center; justify-content:space-between;
        padding:.75rem 1rem; border-bottom:1px solid #30363d; background:#1c2330;
    }
    .bell-panel-head span { font-size:.8rem; font-weight:700; color:#e6edf3; }
    .bell-panel-head a    { font-size:.75rem; color:#58a6ff; text-decoration:none; font-weight:600; }
    .bell-panel-head a:hover { text-decoration:underline; }

    .bell-panel-list { max-height:300px; overflow-y:auto; }
    .bell-panel-list::-webkit-scrollbar { width:4px; }
    .bell-panel-list::-webkit-scrollbar-thumb { background:#30363d; border-radius:2px; }

    .bell-notif-item {
        display:flex; gap:.7rem; align-items:flex-start;
        padding:.75rem 1rem; border-bottom:1px solid #21262d; transition:background .15s;
    }
    .bell-notif-item:last-child { border-bottom:none; }
    .bell-notif-item:hover { background:#1c2330; }

    .bell-notif-dot { width:8px; height:8px; border-radius:50%; flex-shrink:0; margin-top:5px; }
    .bell-notif-dot.info    { background:#58a6ff; }
    .bell-notif-dot.success { background:#3fb950; }
    .bell-notif-dot.warning { background:#d29922; }
    .bell-notif-dot.danger  { background:#f85149; }

    .bell-notif-body  { flex:1; min-width:0; }
    .bell-notif-title { font-size:.82rem; font-weight:700; color:#e6edf3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; margin-bottom:.1rem; }
    .bell-notif-msg   { font-size:.75rem; color:#8b949e; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .bell-notif-time  { font-size:.68rem; color:#484f58; margin-top:.2rem; }

    .bell-panel-foot {
        padding:.65rem 1rem; border-top:1px solid #30363d;
        background:#1c2330; text-align:center;
    }
    .bell-panel-foot a {
        font-size:.8rem; color:#58a6ff; text-decoration:none;
        font-weight:600; display:inline-flex; align-items:center; gap:.3rem;
    }
    .bell-panel-foot a:hover { text-decoration:underline; }

    .bell-empty { padding:2rem 1rem; text-align:center; color:#484f58; }
    .bell-empty i { font-size:1.8rem; display:block; margin-bottom:.5rem; }
    .bell-empty p { font-size:.8rem; margin:0; }

    /* ── Toast ── */
    #notifToastWrap {
        position:fixed; bottom:1.5rem; right:1.5rem;
        z-index:99999; display:flex; flex-direction:column; gap:.6rem;
        pointer-events:none;
    }
    .notif-toast {
        background:#161b22; border:1px solid #30363d; border-radius:10px;
        padding:.75rem 1rem; display:flex; gap:.65rem; align-items:flex-start;
        min-width:260px; max-width:320px;
        pointer-events:all; box-shadow:0 8px 30px rgba(0,0,0,.5);
        animation:toastIn .3s ease both;
    }
    @keyframes toastIn  { from { opacity:0; transform:translateX(40px); } to { opacity:1; transform:none; } }
    @keyframes toastOut { from { opacity:1; } to { opacity:0; transform:translateX(40px); } }
    .toast-icon {
        width:32px; height:32px; border-radius:8px;
        display:flex; align-items:center; justify-content:center;
        font-size:.9rem; flex-shrink:0;
    }
    .toast-icon.info    { background:rgba(88,166,255,.15);  color:#58a6ff; }
    .toast-icon.success { background:rgba(63,185,80,.15);   color:#3fb950; }
    .toast-icon.warning { background:rgba(210,153,34,.15);  color:#d29922; }
    .toast-icon.danger  { background:rgba(248,81,73,.15);   color:#f85149; }
    .toast-body  { flex:1; min-width:0; }
    .toast-label { font-size:.65rem; text-transform:uppercase; letter-spacing:.07em; font-weight:700; color:#8b949e; margin-bottom:.15rem; }
    .toast-title { font-size:.84rem; font-weight:700; color:#e6edf3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    /* ── Sidebar ── */
    .sidebar {
        position:fixed; top:var(--navbar-height); left:0;
        width:var(--sidebar-width);
        height:calc(100vh - var(--navbar-height));
        background:#fff; border-right:1px solid var(--teal-border);
        overflow-y:auto; overflow-x:hidden;
        z-index:1040; transition:transform .3s ease; padding-top:.5rem;
    }
    .sidebar .nav-link {
        color:#495057; font-weight:500; transition:.2s; padding:.5rem 1rem;
    }
    .sidebar .nav-link:hover,
    .sidebar .nav-link.active {
        background-color:var(--teal); color:#fff; border-radius:.25rem;
    }
    .sidebar .nav-link i { margin-right:8px; font-size:1.05rem; }
    .sidebar::before {
        content:''; display:block; height:3px;
        background:var(--teal); margin-bottom:.5rem;
    }
    .sidebar .nav-link.logout-link { color:#dc3545; }
    .sidebar .nav-link.logout-link:hover { background:#dc3545; color:#fff; border-radius:.25rem; }

    /* ── Page content ── */
    .page-content {
        margin-left:var(--sidebar-width); margin-top:var(--navbar-height);
        padding:1.5rem;
        min-height:calc(100vh - var(--navbar-height));
        transition:margin-left .3s ease;
    }

    /* ── Mobile ── */
    @media (max-width:768px) {
        .sidebar { transform:translateX(-100%); }
        .sidebar.show { transform:translateX(0); box-shadow:4px 0 16px rgba(0,0,0,.2); }
        .page-content { margin-left:0; }
    }
    .sidebar-overlay {
        display:none; position:fixed; inset:0;
        background:rgba(0,0,0,.45); z-index:1039;
    }
    .sidebar-overlay.show { display:block; }
    .chevron-icon { transition:transform .3s; }
    .chevron-icon.rotated { transform:rotate(180deg); }
    </style>
</head>
<body>

<!-- ── Toast container ── -->
<div id="notifToastWrap"></div>

<!-- ── Top Navbar ── -->
<nav class="navbar navbar-expand-lg navbar-dark fixed-top shadow-sm">
    <div class="container-fluid">

        <button class="btn btn-outline-light d-md-none me-2"
                type="button" id="sidebarToggle">
            <i class="bi bi-list fs-5"></i>
        </button>

        <a class="navbar-brand" href="dashboard.php">
            <div class="brand-icon"><i class="bi bi-mortarboard-fill"></i></div>
            <div class="brand-text">
                <div class="uni">USTED — Online Exam System</div>
                <div class="sys">OES Portal</div>
            </div>
        </a>

        <div class="d-flex ms-auto align-items-center gap-2">

            <!-- ── Bell ── -->
            <div class="bell-wrap" id="bellWrap">
                <button class="bell-btn" id="bellBtn" title="Notifications" type="button">
                    <i class="bi bi-bell-fill"></i>
                    <span class="bell-badge" id="bellBadge"
                          style="display:<?= $notif_count > 0 ? 'flex' : 'none' ?>">
                        <?= $notif_count > 99 ? '99+' : $notif_count ?>
                    </span>
                </button>

                <div class="bell-panel" id="bellPanel">
                    <div class="bell-panel-head">
                        <span><i class="bi bi-bell-fill me-1"></i> Notifications</span>
                        <a href="<?= $notif_url ?>">View all</a>
                    </div>

                    <div class="bell-panel-list" id="bellList">
                        <?php if (empty($preview_notifs)): ?>
                            <div class="bell-empty">
                                <i class="bi bi-bell-slash"></i>
                                <p>No notifications yet.</p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($preview_notifs as $n): ?>
                            <div class="bell-notif-item">
                                <div class="bell-notif-dot <?= htmlspecialchars($n['type'] ?? 'info') ?>"></div>
                                <div class="bell-notif-body">
                                    <div class="bell-notif-title"><?= htmlspecialchars($n['title']) ?></div>
                                    <div class="bell-notif-msg"><?= htmlspecialchars($n['message']) ?></div>
                                    <div class="bell-notif-time">
                                        <?= htmlspecialchars($n['sender']) ?>
                                        &middot;
                                        <?= date('d M, H:i', strtotime($n['created_at'])) ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <div class="bell-panel-foot">
                        <a href="<?= $notif_url ?>">
                            <i class="bi bi-arrow-right-circle"></i> See all notifications
                        </a>
                    </div>
                </div>
            </div>

            <!-- ── Profile dropdown ── -->
            <div class="dropdown">
                <button class="btn btn-outline-light dropdown-toggle d-flex align-items-center gap-2"
                        type="button" data-bs-toggle="dropdown">
                    <a href="profile.php" onclick="event.stopPropagation()" title="View Profile">
                        <img src="<?= htmlspecialchars($profileImage) ?>"
                             class="avatar" alt="avatar"
                             style="cursor:pointer;border:2px solid rgba(255,255,255,.6);transition:border-color .18s"
                             onmouseover="this.style.borderColor='#fff'"
                             onmouseout="this.style.borderColor='rgba(255,255,255,.6)'">
                    </a>
                    <span class="d-none d-md-inline"><?= htmlspecialchars($username) ?></span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="profile.php">
                        <i class="bi bi-person-circle me-1"></i> Profile</a></li>
                    <li><a class="dropdown-item" href="change_password.php">
                        <i class="bi bi-lock me-1"></i> Change Password</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="../auth/logout.php">
                        <i class="bi bi-box-arrow-right me-1"></i> Logout</a></li>
                </ul>
            </div>

        </div>
    </div>
</nav>

<!-- ── Sidebar ── -->
<div class="sidebar" id="sidebar">
    <ul class="nav flex-column px-2 pt-1">
        <?php foreach ($menu as $item): ?>

            <?php if (isset($item['dropdown'])): ?>
            <?php
                $groupActive = false;
                foreach ($item['dropdown'] as $sub) {
                    if ($current === $sub['url']) { $groupActive = true; break; }
                }
                $collapseId = 'dd_' . preg_replace('/\W/', '', strtolower($item['label']));
            ?>
            <li class="nav-item mb-1">
                <a class="nav-link d-flex align-items-center justify-content-between <?= $groupActive ? 'active' : '' ?>"
                   href="#<?= $collapseId ?>"
                   data-bs-toggle="collapse"
                   aria-expanded="<?= $groupActive ? 'true' : 'false' ?>">
                    <span><i class="bi <?= $item['icon'] ?>"></i> <?= $item['label'] ?></span>
                    <i class="bi bi-chevron-down chevron-icon <?= $groupActive ? 'rotated' : '' ?>"
                       style="font-size:.7rem"></i>
                </a>
                <div class="collapse <?= $groupActive ? 'show' : '' ?>" id="<?= $collapseId ?>">
                    <ul class="nav flex-column ps-3 mt-1">
                        <?php foreach ($item['dropdown'] as $sub): ?>
                        <li class="nav-item">
                            <a class="nav-link py-1 <?= $current === $sub['url'] ? 'active' : '' ?>"
                               href="<?= $sub['url'] ?>">
                                <i class="bi bi-dot"></i> <?= $sub['label'] ?>
                            </a>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </li>

            <?php else: ?>
            <li class="nav-item mb-1">
                <a class="nav-link <?= $current === $item['url'] ? 'active' : '' ?>"
                   href="<?= $item['url'] ?>">
                    <i class="bi <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
                </a>
            </li>
            <?php endif; ?>

        <?php endforeach; ?>

        <li class="nav-item mt-3 border-top pt-2">
            <a class="nav-link logout-link" href="../auth/logout.php">
                <i class="bi bi-box-arrow-right"></i> Logout
            </a>
        </li>
    </ul>
</div>

<!-- Mobile overlay -->
<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Open <main> — closed in footer.php -->
<main class="page-content">

<script src="../assets/js/bootstrap.bundle.min.js"></script>
<!-- <script>
// Auto-refresh page every 5 seconds (5000 milliseconds)
setTimeout(function() {
    location.reload();
}, 5000);
</script> -->
<script>
// ── Sidebar toggle ────────────────────────────────────────────────
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
const toggler = document.getElementById('sidebarToggle');

toggler.addEventListener('click', () => {
    sidebar.classList.toggle('show');
    overlay.classList.toggle('show');
});
overlay.addEventListener('click', () => {
    sidebar.classList.remove('show');
    overlay.classList.remove('show');
});

// ── Sidebar collapse chevrons ─────────────────────────────────────
document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(trigger => {
    const target  = document.querySelector(trigger.getAttribute('href'));
    if (!target) return;
    const chevron = trigger.querySelector('.chevron-icon');
    target.addEventListener('show.bs.collapse', () => chevron?.classList.add('rotated'));
    target.addEventListener('hide.bs.collapse', () => chevron?.classList.remove('rotated'));
});

// ── Bell dropdown ─────────────────────────────────────────────────
const bellBtn   = document.getElementById('bellBtn');
const bellPanel = document.getElementById('bellPanel');
const bellWrap  = document.getElementById('bellWrap');
const bellBadge = document.getElementById('bellBadge');

bellBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    const isOpen = bellPanel.classList.toggle('show');
    bellBtn.classList.toggle('open', isOpen);

    if (isOpen) {
        // Mark as seen — resets session notif_last_seen
        fetch('ajax/mark_notifications_seen.php').catch(() => {});
        // Hide badge immediately
        bellBadge.style.display = 'none';
    }
});

// Close when clicking outside bell
document.addEventListener('click', (e) => {
    if (!bellWrap.contains(e.target)) {
        bellPanel.classList.remove('show');
        bellBtn.classList.remove('open');
    }
});

// ── Toast system ──────────────────────────────────────────────────
const toastWrap = document.getElementById('notifToastWrap');

const typeIcons = {
    info:    'bi-info-circle-fill',
    success: 'bi-check-circle-fill',
    warning: 'bi-exclamation-triangle-fill',
    danger:  'bi-x-octagon-fill',
};

function showToast(title, type) {
    const safeType = typeIcons[type] ? type : 'info';
    const icon     = typeIcons[safeType];

    const el = document.createElement('div');
    el.className = 'notif-toast';
    el.innerHTML = `
        <div class="toast-icon ${safeType}"><i class="bi ${icon}"></i></div>
        <div class="toast-body">
            <div class="toast-label">New Notification</div>
            <div class="toast-title">${escHtml(title)}</div>
        </div>`;
    toastWrap.appendChild(el);

    setTimeout(() => {
        el.style.animation = 'toastOut .3s ease forwards';
        setTimeout(() => el.remove(), 320);
    }, 5000);
}

function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str || '';
    return d.innerHTML;
}

// ── Auto-poll badge every 30s ─────────────────────────────────────
let lastCount = <?= $notif_count ?>;

(function pollBell() {
    fetch('ajax/get_notification_count.php')
        .then(r => r.json())
        .then(data => {
            const count = data.count ?? 0;

            // Update badge (only when panel is closed)
            if (!bellPanel.classList.contains('show')) {
                if (count > 0) {
                    bellBadge.textContent  = count > 99 ? '99+' : count;
                    bellBadge.style.display = 'flex';
                } else {
                    bellBadge.style.display = 'none';
                }
            }

            // Show toast only when count increases and panel is closed
            if (count > lastCount && data.latest && data.latest.title) {
                if (!bellPanel.classList.contains('show')) {
                    showToast(data.latest.title, data.latest.type);
                }
            }

            lastCount = count;
        })
        .catch(() => {}); // Silently fail

    setTimeout(pollBell, 30000);
})();
</script>