<?php
session_start();
require_once "../config/db.php";

// ── Auth guard ─────────────────────────────────────────────────────
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'lecturer') {
    header("Location: ../auth/login.php");
    exit();
}

// ── Resolve lecturer_id from session email (same pattern as results.php) ──
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

// ── Handle Profile Image Upload ────────────────────────────────────
$upload_message = $upload_type = "";

if (isset($_POST['upload_image'])) {
    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $max_size      = 2 * 1024 * 1024; // 2 MB

    if (!isset($_FILES['profile_image']) || $_FILES['profile_image']['error'] !== UPLOAD_ERR_OK) {
        $upload_message = "No file uploaded or upload error occurred.";
        $upload_type    = "danger";
    } elseif (!in_array($_FILES['profile_image']['type'], $allowed_types)) {
        $upload_message = "Only JPG, PNG, GIF, and WEBP images are allowed.";
        $upload_type    = "danger";
    } elseif ($_FILES['profile_image']['size'] > $max_size) {
        $upload_message = "Image must be under 2MB.";
        $upload_type    = "danger";
    } else {
        $upload_dir = "../uploads/profile_images/";

        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $ext      = strtolower(pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION));
        $filename = "lecturer_" . $lecturer_id . "_" . time() . "." . $ext;
        $dest     = $upload_dir . $filename;
        $db_path  = "uploads/profile_images/" . $filename;

        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $dest)) {
            // Delete old profile_image if not the default
            $stmt = $conn->prepare("SELECT profile_image FROM lecturers WHERE id = ?");
            $stmt->bind_param("i", $lecturer_id);
            $stmt->execute();
            $old_profile_image = $stmt->get_result()->fetch_assoc()['profile_image'] ?? '';
            $stmt->close();

            if ($old_profile_image && $old_profile_image !== 'avatar.png' && file_exists("../" . $old_profile_image)) {
                unlink("../" . $old_profile_image);
            }

            // Save new profile_image path to lecturers table
            $stmt = $conn->prepare("UPDATE lecturers SET profile_image = ? WHERE id = ?");
            $stmt->bind_param("si", $db_path, $lecturer_id);
            $stmt->execute();
            $stmt->close();

            $_SESSION['profile_image'] = $db_path;

            $upload_message = "Profile photo updated successfully!";
            $upload_type    = "success";
        } else {
            $upload_message = "Failed to save image. Check folder permissions.";
            $upload_type    = "danger";
        }
    }
}

// ── Handle Name Update ────────────────────────────────────────────
$name_message = $name_type = "";

if (isset($_POST['update_name'])) {
    $new_name = trim($_POST['lecturer_name'] ?? '');
    if (strlen($new_name) < 2) {
        $name_message = "Name must be at least 2 characters.";
        $name_type    = "danger";
    } elseif (strlen($new_name) > 100) {
        $name_message = "Name must be under 100 characters.";
        $name_type    = "danger";
    } else {
        $stmt = $conn->prepare("UPDATE lecturers SET name = ? WHERE id = ?");
        $stmt->bind_param("si", $new_name, $lecturer_id);
        $stmt->execute();
        $stmt->close();
        $_SESSION['name'] = $new_name;
        $name_message = "Name updated successfully!";
        $name_type    = "success";
    }
}

// ── Fetch Lecturer from lecturers table ────────────────────────────
$stmt = $conn->prepare("
    SELECT id, name, email, profile_image, created_at
    FROM lecturers
    WHERE id = ?
    LIMIT 1
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$lecturer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$lecturer) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit();
}

// ── Stats: use lecturer_id OR created_by (same as results.php) ─────
// Courses
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM courses WHERE lecturer_id = ?");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$course_count = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// Exams
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM exams WHERE lecturer_id = ? OR created_by = ?");
$stmt->bind_param("ii", $lecturer_id, $lecturer_id);
$stmt->execute();
$exam_count = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// Students who have submitted attempts on this lecturer's exams
$stmt = $conn->prepare("
    SELECT COUNT(DISTINCT a.student_id) AS total
    FROM exam_attempts a
    JOIN exams e ON e.id = a.exam_id
    WHERE (e.lecturer_id = ? OR e.created_by = ?)
      AND a.status IN ('completed', 'submitted')
");
$stmt->bind_param("ii", $lecturer_id, $lecturer_id);
$stmt->execute();
$student_count = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// Questions in question bank
$stmt = $conn->prepare("
    SELECT COUNT(*) AS total
    FROM questions q
    JOIN courses c ON q.course_id = c.id
    WHERE c.lecturer_id = ?
");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$question_count = (int)$stmt->get_result()->fetch_assoc()['total'];
$stmt->close();

// Member since
$member_since = !empty($lecturer['created_at'])
    ? date('F Y', strtotime($lecturer['created_at']))
    : 'N/A';

// ── Resolve profile image path ─────────────────────────────────────
$profile_image = $lecturer['profile_image'] ?? '';
if ($profile_image && $profile_image !== 'avatar.png' && file_exists("../" . $profile_image)) {
    $profileImage = "../" . $profile_image;
} else {
    $profileImage = "../assets/img/avatar.png";
}

include "../includes/header.php";
?>

<style>
@import url('https://fonts.googleapis.com/css2?family=Sora:wght@400;600;700&family=DM+Sans:wght@400;500;600&display=swap');

:root {
    --teal:        #3d8b8d;
    --teal-dark:   #2d6e70;
    --teal-light:  #56a8aa;
    --teal-pale:   #eaf5f5;
    --teal-border: #c0dfe0;
    --serif:       'Sora', sans-serif;
    --sans:        'DM Sans', sans-serif;
}

body { font-family: var(--sans); background: #f4f6fb; }

/* ── Profile Card ── */
.profile-card {
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 8px 32px rgba(30,80,80,.10);
    overflow: hidden;
    transition: transform .3s, box-shadow .3s;
}
.profile-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 16px 48px rgba(30,80,80,.15);
}

/* ── Cover ── */
.profile-cover {
    height: 130px;
    background: linear-gradient(135deg, var(--teal-dark) 0%, var(--teal) 60%, var(--teal-light) 100%);
    position: relative;
}
.profile-cover::after {
    content: '';
    position: absolute;
    inset: 0;
    background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
}

/* ── Avatar ── */
.avatar-wrap {
    position: relative;
    display: inline-block;
    cursor: pointer;
    margin-top: -58px;
}
.profile-avatar {
    width: 116px;
    height: 116px;
    border-radius: 50%;
    object-fit: cover;
    border: 4px solid #fff;
    box-shadow: 0 4px 16px rgba(0,0,0,.15);
    display: block;
    transition: transform .3s;
}
.avatar-wrap:hover .profile-avatar { transform: scale(1.04); }
.avatar-overlay {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: rgba(0,0,0,.42);
    display: flex;
    align-items: center;
    justify-content: center;
    opacity: 0;
    transition: opacity .2s;
}
.avatar-wrap:hover .avatar-overlay { opacity: 1; }
.avatar-overlay i { color: #fff; font-size: 1.4rem; }

/* ── Name / role ── */
.profile-name { font-family: var(--serif); font-size: 1.35rem; font-weight: 700; color: #1a1f2e; }
.profile-role { font-size: .83rem; color: var(--teal); font-weight: 600; margin-top: 2px; }
.profile-since { font-size: .75rem; color: #aaa; margin-top: 2px; }

/* ── Stats ── */
.stat-box {
    text-align: center;
    padding: 13px 8px;
    border-radius: 12px;
    background: var(--teal-pale);
    border: 1px solid var(--teal-border);
    transition: background .2s, transform .2s;
}
.stat-box:hover { background: #c8e8e9; transform: translateY(-2px); }
.stat-number { font-size: 1.55rem; font-weight: 700; color: var(--teal); line-height: 1; font-family: var(--serif); }
.stat-label  { font-size: .72rem; color: #888; margin-top: 4px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; }

/* ── Info rows ── */
.info-section { padding: 0 1.4rem; margin-top: 1.1rem; }
.info-row {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 11px 0;
    border-bottom: 1px solid #f3f4f6;
    font-size: .9rem;
}
.info-row:last-child { border-bottom: none; }
.info-icon  { width: 34px; height: 34px; border-radius: 8px; background: var(--teal-pale); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.info-icon i { color: var(--teal); font-size: 1rem; }
.info-label { font-weight: 600; color: #666; font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; min-width: 90px; }
.info-value { color: #222; font-size: .88rem; word-break: break-all; }

/* ── Upload area ── */
.upload-drop {
    background: var(--teal-pale);
    border: 2px dashed var(--teal-border);
    border-radius: 12px;
    padding: 16px;
    text-align: center;
    cursor: pointer;
    transition: border-color .2s, background .2s;
}
.upload-drop:hover { border-color: var(--teal); background: #d0ecec; }
.upload-drop i { color: var(--teal); font-size: 1.6rem; }
.upload-drop p { font-size: .81rem; color: var(--teal-dark); margin: 5px 0 0; }

/* ── Buttons ── */
.btn-teal {
    background: linear-gradient(135deg, var(--teal-dark), var(--teal));
    color: #fff;
    border: none;
    border-radius: 9px;
    font-weight: 600;
    padding: 10px 0;
    transition: opacity .2s, transform .15s;
}
.btn-teal:hover { opacity: .9; color: #fff; transform: translateY(-1px); }
.btn-outline-teal { border: 1.5px solid var(--teal); color: var(--teal); border-radius: 9px; font-weight: 600; transition: all .2s; }
.btn-outline-teal:hover { background: var(--teal); color: #fff; }

/* ── Flash alert ── */
.alert { border-radius: 10px; font-size: .88rem; }

/* ── Name edit ── */
.btn-edit-name {
    background: var(--teal-pale);
    border: 1px solid var(--teal-border);
    color: var(--teal);
    border-radius: 7px;
    width: 32px; height: 32px;
    display: flex; align-items: center; justify-content: center;
    cursor: pointer;
    font-size: .8rem;
    transition: background .18s, color .18s;
    flex-shrink: 0;
}
.btn-edit-name:hover { background: var(--teal); color: #fff; }

.name-input {
    flex: 1;
    min-width: 140px;
    border: 1.5px solid var(--teal-border);
    border-radius: 8px;
    padding: .4rem .75rem;
    font-size: .9rem;
    font-family: var(--sans);
    color: #222;
    outline: none;
    transition: border-color .18s;
}
.name-input:focus { border-color: var(--teal); box-shadow: 0 0 0 3px rgba(61,139,141,.12); }

.btn-save-name {
    background: var(--teal);
    color: #fff;
    border: none;
    border-radius: 7px;
    padding: .38rem .8rem;
    font-size: .82rem;
    font-weight: 700;
    cursor: pointer;
    display: flex; align-items: center; gap: .3rem;
    transition: opacity .18s;
    white-space: nowrap;
}
.btn-save-name:hover { opacity: .88; }

.btn-cancel-name {
    background: #f3f4f6;
    color: #6b7280;
    border: 1px solid #e5e7eb;
    border-radius: 7px;
    padding: .38rem .65rem;
    font-size: .82rem;
    cursor: pointer;
    display: flex; align-items: center;
    transition: background .15s;
}
.btn-cancel-name:hover { background: #e5e7eb; }
</style>

<div class="container py-5">
<div class="row justify-content-center">
<div class="col-lg-5 col-md-7">

    <?php if ($name_message): ?>
    <div class="alert alert-<?= $name_type ?> alert-dismissible fade show mb-3">
        <i class="bi bi-<?= $name_type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-2"></i>
        <?= htmlspecialchars($name_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($upload_message): ?>
    <div class="alert alert-<?= $upload_type ?> alert-dismissible fade show mb-3">
        <i class="bi bi-<?= $upload_type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?> me-2"></i>
        <?= htmlspecialchars($upload_message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="profile-card">

        <!-- Cover -->
        <div class="profile-cover"></div>

        <!-- Avatar -->
        <div class="text-center px-4 pb-2">
            <label for="avatarInput" class="avatar-wrap d-block mx-auto" style="width:116px" title="Click to change photo">
                <img src="<?= htmlspecialchars($profileImage) ?>"
                     id="avatarPreview"
                     class="profile-avatar"
                     alt="Profile Photo">
                <div class="avatar-overlay">
                    <i class="bi bi-camera-fill"></i>
                </div>
            </label>
            <small class="text-muted d-block mt-2" style="font-size:.71rem">
                <i class="bi bi-camera"></i> Click photo to change
            </small>

            <div class="mt-3">
                <div class="profile-name"><?= htmlspecialchars($lecturer['name']) ?></div>
                <div class="profile-role"><i class="bi bi-mortarboard-fill"></i> Lecturer</div>
                <div class="profile-since"><i class="bi bi-calendar3"></i> Member since <?= $member_since ?></div>
            </div>
        </div>

        <!-- Stats -->
        <div class="row g-2 px-4 mt-2 pb-1">
            <div class="col-3">
                <div class="stat-box">
                    <div class="stat-number"><?= $course_count ?></div>
                    <div class="stat-label">Courses</div>
                </div>
            </div>
            <div class="col-3">
                <div class="stat-box">
                    <div class="stat-number"><?= $exam_count ?></div>
                    <div class="stat-label">Exams</div>
                </div>
            </div>
            <div class="col-3">
                <div class="stat-box">
                    <div class="stat-number"><?= $student_count ?></div>
                    <div class="stat-label">Students</div>
                </div>
            </div>
            <div class="col-3">
                <div class="stat-box">
                    <div class="stat-number"><?= $question_count ?></div>
                    <div class="stat-label">Questions</div>
                </div>
            </div>
        </div>

        <!-- Info Rows -->
        <div class="info-section">
            <div class="info-row" id="name-display-row">
                <div class="info-icon"><i class="bi bi-person-fill"></i></div>
                <div style="flex:1">
                    <div class="info-label">Full Name</div>
                    <div class="info-value" id="name-display"><?= htmlspecialchars($lecturer['name']) ?></div>
                </div>
                <button type="button" onclick="toggleNameEdit()" class="btn-edit-name" title="Edit name">
                    <i class="bi bi-pencil-fill"></i>
                </button>
            </div>
            <!-- Inline name edit form -->
            <div class="info-row name-edit-form" id="name-edit-row" style="display:none">
                <div class="info-icon"><i class="bi bi-pencil-fill" style="color:var(--teal)"></i></div>
                <form method="POST" style="flex:1;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                    <input type="text" name="lecturer_name"
                           value="<?= htmlspecialchars($lecturer['name']) ?>"
                           id="nameEditInput"
                           class="name-input"
                           placeholder="Enter full name"
                           maxlength="100" required>
                    <div style="display:flex;gap:.4rem">
                        <button type="submit" name="update_name" class="btn-save-name">
                            <i class="bi bi-check-lg"></i> Save
                        </button>
                        <button type="button" onclick="toggleNameEdit()" class="btn-cancel-name">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </form>
            </div>
            <div class="info-row">
                <div class="info-icon"><i class="bi bi-envelope-fill"></i></div>
                <div>
                    <div class="info-label">Email Address</div>
                    <div class="info-value"><?= htmlspecialchars($lecturer['email']) ?></div>
                </div>
            </div>
            <div class="info-row">
                <div class="info-icon"><i class="bi bi-shield-lock-fill"></i></div>
                <div>
                    <div class="info-label">Role</div>
                    <div class="info-value">Lecturer</div>
                </div>
            </div>
            <div class="info-row">
                <div class="info-icon"><i class="bi bi-calendar-check-fill"></i></div>
                <div>
                    <div class="info-label">Member Since</div>
                    <div class="info-value"><?= $member_since ?></div>
                </div>
            </div>
        </div>

        <!-- Image Upload Form -->
        <form method="POST" enctype="multipart/form-data" id="uploadForm" class="px-4 mt-3">
            <input type="file" name="profile_image" id="avatarInput"
                   accept="image/jpeg,image/png,image/gif,image/webp"
                   style="display:none">

            <div id="uploadPreviewArea" style="display:none">
                <div class="upload-drop">
                    <i class="bi bi-cloud-arrow-up-fill"></i>
                    <p id="selectedFileName">No file chosen</p>
                </div>
                <div class="d-grid mt-2">
                    <button type="submit" name="upload_image" class="btn btn-teal">
                        <i class="bi bi-upload"></i> Save Photo
                    </button>
                </div>
            </div>
        </form>

        <!-- Action Buttons -->
        <div class="d-grid gap-2 px-4 py-4">
            <a href="change_password.php" class="btn btn-outline-teal">
                <i class="bi bi-lock-fill"></i> Change Password
            </a>
            <a href="dashboard.php" class="btn btn-outline-secondary" style="border-radius:9px;font-weight:600">
                <i class="bi bi-house-door-fill"></i> Back to Dashboard
            </a>
        </div>

    </div><!-- /profile-card -->

</div>
</div>
</div>

<script>
function toggleNameEdit() {
    const displayRow = document.getElementById('name-display-row');
    const editRow    = document.getElementById('name-edit-row');
    const isHidden   = editRow.style.display === 'none';
    editRow.style.display    = isHidden ? '' : 'none';
    displayRow.style.display = isHidden ? 'none' : '';
    if (isHidden) document.getElementById('nameEditInput').focus();
}

const avatarInput       = document.getElementById('avatarInput');
const avatarPreview     = document.getElementById('avatarPreview');
const uploadPreviewArea = document.getElementById('uploadPreviewArea');
const selectedFileName  = document.getElementById('selectedFileName');

avatarInput.addEventListener('change', function () {
    if (this.files && this.files[0]) {
        const file   = this.files[0];
        const reader = new FileReader();
        reader.onload = e => { avatarPreview.src = e.target.result; };
        reader.readAsDataURL(file);
        selectedFileName.textContent = file.name;
        uploadPreviewArea.style.display = 'block';
    }
});
</script>

<?php include "../includes/footer.php"; ?>