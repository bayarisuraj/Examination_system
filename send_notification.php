<?php
// CORRECT FILE — lecturer/send_notification.php
session_start();

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/db.php";

// ── Resolve lecturer row ──────────────────────────────────────────
$user_id = (int)($_SESSION['user_id'] ?? 0);
$lec = $conn->query("SELECT id, name FROM lecturers WHERE id = $user_id")->fetch_assoc()
    ?? $conn->query("SELECT id, name FROM lecturers LIMIT 1")->fetch_assoc();

if (!$lec) {
    die("Lecturer record not found.");
}
$lecturer_id = (int)$lec['id'];

$success = $error = '';

// ── Handle POST ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title   = trim($_POST['title']   ?? '');
    $message = trim($_POST['message'] ?? '');
    $type    = in_array($_POST['type'] ?? '', ['info','success','warning','danger'])
               ? $_POST['type'] : 'info';

    if ($title === '' || $message === '') {
        $error = 'Title and message are required.';
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO notifications (lecturer_id, title, message, type) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param("isss", $lecturer_id, $title, $message, $type);
        if ($stmt->execute()) {
            $success = 'Notification sent to all students!';
        } else {
            $error = 'Database error: ' . $conn->error;
        }
        $stmt->close();
    }
}

// ── Past notifications by this lecturer ──────────────────────────
$past = $conn->query("
    SELECT id, title, message, type, created_at
    FROM notifications
    WHERE lecturer_id = $lecturer_id
    ORDER BY created_at DESC
    LIMIT 30
")->fetch_all(MYSQLI_ASSOC);

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

.page-shell{max-width:860px;margin:0 auto;padding:2.5rem 1.25rem 5rem}

.page-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:2rem}
.page-header h1{font-family:var(--serif);font-size:1.55rem;font-weight:700}
.page-header .sub{font-size:.83rem;color:var(--muted)}
.btn-back{display:inline-flex;align-items:center;gap:.4rem;padding:.48rem 1rem;border-radius:8px;border:1px solid var(--border);background:var(--surface);color:var(--muted);font-size:.83rem;font-weight:600;text-decoration:none;transition:all .18s}
.btn-back:hover{border-color:var(--accent);color:var(--accent)}

/* ── Form card ── */
.form-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.75rem;margin-bottom:2rem;animation:fadeUp .4s ease both}
@keyframes fadeUp{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}

.form-card h2{font-family:var(--serif);font-size:1.1rem;font-weight:700;margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem}
.form-card h2 i{color:var(--accent)}

.form-group{margin-bottom:1.1rem}
.form-group label{display:block;font-size:.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.4rem}
.form-group input,
.form-group textarea,
.form-group select{
    width:100%;background:var(--surface2);border:1px solid var(--border);
    border-radius:8px;padding:.6rem .85rem;color:var(--text);
    font-size:.88rem;font-family:var(--sans);outline:none;
    transition:border-color .2s;resize:vertical
}
.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus{border-color:var(--accent)}
.form-group textarea{min-height:110px}
.form-group select option{background:var(--surface2)}

/* Type pills */
.type-pills{display:flex;gap:.5rem;flex-wrap:wrap}
.type-pill{
    display:inline-flex;align-items:center;gap:.35rem;
    padding:.35rem .9rem;border-radius:20px;
    border:1.5px solid var(--border);background:transparent;
    color:var(--muted);font-size:.8rem;font-weight:600;
    cursor:pointer;transition:all .18s
}
.type-pill input{display:none}
.type-pill.sel-info    {border-color:var(--accent);color:var(--accent);background:rgba(88,166,255,.12)}
.type-pill.sel-success {border-color:var(--green); color:var(--green); background:rgba(63,185,80,.12)}
.type-pill.sel-warning {border-color:var(--amber); color:var(--amber); background:rgba(210,153,34,.12)}
.type-pill.sel-danger  {border-color:var(--red);   color:var(--red);   background:rgba(248,81,73,.12)}

.btn-send{
    display:inline-flex;align-items:center;gap:.45rem;
    padding:.6rem 1.5rem;border-radius:8px;border:none;
    background:var(--accent);color:#0d1117;
    font-size:.9rem;font-weight:700;cursor:pointer;
    transition:filter .18s;width:100%;justify-content:center;margin-top:.4rem
}
.btn-send:hover{filter:brightness(1.12)}

/* Alert banners */
.alert{padding:.7rem 1rem;border-radius:8px;font-size:.85rem;font-weight:600;margin-bottom:1.2rem;display:flex;align-items:center;gap:.5rem}
.alert-success{background:rgba(63,185,80,.12);border:1px solid var(--green);color:var(--green)}
.alert-danger {background:rgba(248,81,73,.12); border:1px solid var(--red);  color:var(--red)}

/* ── History table ── */
.section-title{font-family:var(--serif);font-size:1rem;font-weight:700;color:var(--text);margin-bottom:.85rem;display:flex;align-items:center;gap:.5rem}
.section-title i{color:var(--accent)}

.table-wrap{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:.86rem}
thead th{background:var(--surface2);padding:.65rem 1rem;text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.07em;font-weight:700;color:var(--muted);border-bottom:1px solid var(--border);white-space:nowrap}
tbody tr{border-bottom:1px solid var(--border);transition:background .15s}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--surface2)}
tbody td{padding:.7rem 1rem;vertical-align:middle}

.nbadge{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:20px;font-size:.72rem;font-weight:700;font-family:var(--mono)}
.nbadge.info   {background:rgba(88,166,255,.12);color:var(--accent);border:1px solid rgba(88,166,255,.3)}
.nbadge.success{background:rgba(63,185,80,.12); color:var(--green); border:1px solid rgba(63,185,80,.3)}
.nbadge.warning{background:rgba(210,153,34,.12);color:var(--amber); border:1px solid rgba(210,153,34,.3)}
.nbadge.danger {background:rgba(248,81,73,.12); color:var(--red);   border:1px solid rgba(248,81,73,.3)}

.td-msg{max-width:340px;font-size:.82rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.td-date{font-family:var(--mono);font-size:.75rem;color:var(--muted);white-space:nowrap}

.btn-del{display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .65rem;border-radius:6px;border:1px solid var(--border);background:transparent;color:var(--muted);font-size:.75rem;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none}
.btn-del:hover{border-color:var(--red);color:var(--red)}

.empty-row td{padding:2.5rem;text-align:center;color:var(--muted)}
.empty-row i{display:block;font-size:2rem;margin-bottom:.5rem;color:var(--border)}

@media(max-width:600px){
    .page-shell{padding:1.25rem .75rem 4rem}
    .page-header h1{font-size:1.3rem}
}
</style>

<div class="page-shell">

    <div class="page-header">
        <div>
            <h1><i class="bi bi-bell-fill" style="color:var(--accent);margin-right:.4rem"></i>Send Notification</h1>
            <div class="sub">Broadcast alerts to all enrolled students</div>
        </div>
        <a href="dashboard.php" class="btn-back"><i class="bi bi-arrow-left"></i> Dashboard</a>
    </div>

    <!-- Form -->
    <div class="form-card">
        <h2><i class="bi bi-send-fill"></i> New Notification</h2>

        <?php if ($success): ?>
        <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i><?= $success ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill"></i><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" id="notifForm">

            <div class="form-group">
                <label>Type</label>
                <div class="type-pills" id="typePills">
                    <?php foreach (['info'=>'bi-info-circle-fill','success'=>'bi-check-circle-fill','warning'=>'bi-exclamation-triangle-fill','danger'=>'bi-x-octagon-fill'] as $t => $icon): ?>
                    <label class="type-pill <?= $t === 'info' ? 'sel-'.$t : '' ?>" id="pill-<?= $t ?>">
                        <input type="radio" name="type" value="<?= $t ?>" <?= $t === 'info' ? 'checked' : '' ?>>
                        <i class="bi <?= $icon ?>"></i> <?= ucfirst($t) ?>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group">
                <label for="title">Title</label>
                <input type="text" name="title" id="title"
                       placeholder="e.g. Exam Rescheduled — Wednesday 18 March"
                       maxlength="150"
                       value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required>
            </div>

            <div class="form-group">
                <label for="message">Message</label>
                <textarea name="message" id="message"
                          placeholder="Write your notification message here…"
                          maxlength="1000" required><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
            </div>

            <button type="submit" class="btn-send">
                <i class="bi bi-send-fill"></i> Send to All Students
            </button>
        </form>
    </div>

    <!-- History -->
    <div class="section-title"><i class="bi bi-clock-history"></i> Recent Notifications</div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Title</th>
                    <th>Message</th>
                    <th>Sent</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($past)): ?>
            <tr class="empty-row">
                <td colspan="5"><i class="bi bi-bell-slash"></i>No notifications sent yet.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($past as $n): ?>
            <tr>
                <td>
                    <span class="nbadge <?= $n['type'] ?>">
                        <?php
                        $icons = ['info'=>'bi-info-circle-fill','success'=>'bi-check-circle-fill','warning'=>'bi-exclamation-triangle-fill','danger'=>'bi-x-octagon-fill'];
                        ?>
                        <i class="bi <?= $icons[$n['type']] ?? 'bi-bell' ?>"></i>
                        <?= ucfirst($n['type']) ?>
                    </span>
                </td>
                <td style="font-weight:600;max-width:200px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <?= htmlspecialchars($n['title']) ?>
                </td>
                <td class="td-msg"><?= htmlspecialchars($n['message']) ?></td>
                <td class="td-date">
                    <?= date('d M Y', strtotime($n['created_at'])) ?><br>
                    <span style="font-size:.7rem"><?= date('H:i', strtotime($n['created_at'])) ?></span>
                </td>
                <td>
                    <a href="delete_notification.php?id=<?= (int)$n['id'] ?>"
                       class="btn-del"
                       onclick="return confirm('Delete this notification?')">
                        <i class="bi bi-trash3"></i> Delete
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
// Type pill selection highlight
document.querySelectorAll('#typePills input[type=radio]').forEach(radio => {
    radio.addEventListener('change', () => {
        document.querySelectorAll('.type-pill').forEach(p => {
            p.className = 'type-pill'; // reset
        });
        const lbl = radio.closest('.type-pill');
        lbl.classList.add('sel-' + radio.value);
    });
});
</script>

<?php include "../includes/footer.php"; ?>