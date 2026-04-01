<?php
session_start();
require_once "../config/db.php";

// Only allow lecturers
if(!isset($_SESSION['role']) || $_SESSION['role'] != "lecturer"){
    header("Location: ../auth/login.php");
    exit();
}

$lecturer_id = (int)$_SESSION['user_id'];
$success = $error = "";

// ── Fetch lecturer details ──
$stmt = $conn->prepare("SELECT id, name, email, department, profile_image FROM lecturers WHERE id=?");
$stmt->bind_param("i", $lecturer_id);
$stmt->execute();
$lecturer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$lecturer){
    header("Location: ../auth/login.php");
    exit();
}

// ── Handle Profile Image Upload ──
if(isset($_POST['update_settings'])){
    $name       = trim($_POST['name'] ?? "");
    $email      = trim($_POST['email'] ?? "");
    $department = trim($_POST['department'] ?? "");
    $password   = $_POST['password'] ?? "";
    $password2  = $_POST['password2'] ?? "";

    if(!$name || !$email){
        $error = "Name and email are required.";
    } elseif($password && $password !== $password2){
        $error = "Passwords do not match.";
    } else {

        // ── Handle avatar upload ──
        $profile_image = $lecturer['profile_image'];

        if(isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK){
            $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $mime    = mime_content_type($_FILES['profile_image']['tmp_name']);

            if(!in_array($mime, $allowed)){
                $error = "Only JPG, PNG, GIF or WEBP images are allowed.";
            } elseif($_FILES['profile_image']['size'] > 2 * 1024 * 1024){
                $error = "Image must be under 2MB.";
            } else {
                $ext        = pathinfo($_FILES['profile_image']['name'], PATHINFO_EXTENSION);
                $filename   = "lecturer_" . $lecturer_id . "_" . time() . "." . $ext;
                $uploadPath = "../uploads/" . $filename;

                if(move_uploaded_file($_FILES['profile_image']['tmp_name'], $uploadPath)){
                    $profile_image = "uploads/" . $filename;
                } else {
                    $error = "Failed to upload image. Check uploads folder permissions.";
                }
            }
        }

        if(!$error){
            // ── Update with or without password ──
            if($password){
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("
                    UPDATE lecturers
                    SET name=?, email=?, department=?, profile_image=?, password=?
                    WHERE id=?
                ");
                $stmt->bind_param("sssssi", $name, $email, $department, $profile_image, $hashed, $lecturer_id);
            } else {
                $stmt = $conn->prepare("
                    UPDATE lecturers
                    SET name=?, email=?, department=?, profile_image=?
                    WHERE id=?
                ");
                $stmt->bind_param("ssssi", $name, $email, $department, $profile_image, $lecturer_id);
            }

            if($stmt->execute()){
                // ── Update session ──
                $_SESSION['username']      = $name;
                $_SESSION['profile_image'] = $profile_image;

                // ── Refresh lecturer data ──
                $lecturer['name']          = $name;
                $lecturer['email']         = $email;
                $lecturer['department']    = $department;
                $lecturer['profile_image'] = $profile_image;

                $success = "Settings updated successfully!";
            } else {
                $error = "Failed to update: " . $conn->error;
            }
            $stmt->close();
        }
    }
}

// ── Profile Image for display ──
$profileImage = $lecturer['profile_image'] ?? null;
if(!$profileImage || !file_exists("../" . $profileImage)){
    $profileImage = "../assets/img/avatar.png";
} else {
    $profileImage = "../" . $profileImage;
}
?>

<?php include "../includes/header.php"; ?>

<style>
.settings-card {
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
    background: #fff;
    overflow: hidden;
    transition: transform 0.3s, box-shadow 0.3s;
}
.settings-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 16px 38px rgba(0,0,0,0.13);
}
.settings-header {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    padding: 1.5rem;
    text-align: center;
    color: #fff;
}
.settings-header h4 {
    font-weight: 700;
    margin: 0;
}

/* Avatar Upload */
.avatar-wrap {
    position: relative;
    width: 100px;
    height: 100px;
    margin: 0 auto 0.5rem;
    cursor: pointer;
}
.avatar-wrap img {
    width: 100px;
    height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid #fff;
    box-shadow: 0 3px 10px rgba(0,0,0,0.2);
}
.avatar-wrap .avatar-overlay {
    position: absolute;
    inset: 0;
    border-radius: 50%;
    background: rgba(0,0,0,0.45);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4rem;
    opacity: 0;
    transition: opacity 0.25s;
}
.avatar-wrap:hover .avatar-overlay { opacity: 1; }

/* Section divider */
.section-label {
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #6a11cb;
    margin-bottom: 0.75rem;
    margin-top: 1.25rem;
}

/* Buttons */
.btn-save {
    background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
    border: none;
    color: #fff;
    font-weight: 600;
    border-radius: 8px;
    padding: 10px 0;
    transition: opacity 0.2s;
}
.btn-save:hover { opacity: 0.9; color: #fff; }

/* Password toggle */
.toggle-pw { cursor: pointer; }
</style>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-lg-5 col-md-7">

            <div class="settings-card">

                <!-- Header -->
                <div class="settings-header">

                    <!-- Avatar with upload overlay -->
                    <div class="avatar-wrap" onclick="document.getElementById('avatarInput').click()">
                        <img src="<?= htmlspecialchars($profileImage) ?>"
                             id="avatarPreview" alt="Profile Photo">
                        <div class="avatar-overlay">
                            <i class="bi bi-camera-fill"></i>
                        </div>
                    </div>
                    <small class="d-block text-white-50 mb-2">Click photo to change</small>
                    <h4><i class="bi bi-gear-fill me-2"></i>Profile &amp; Settings</h4>

                </div>

                <!-- Form Body -->
                <div class="px-4 pb-4 pt-3">

                    <?php if($success): ?>
                        <div class="alert alert-success alert-dismissible fade show">
                            <i class="bi bi-check-circle-fill me-2"></i><?= htmlspecialchars($success) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">

                        <!-- Hidden file input -->
                        <input type="file" id="avatarInput" name="profile_image"
                               accept="image/*" class="d-none">

                        <!-- Personal Info -->
                        <div class="section-label">
                            <i class="bi bi-person-fill me-1"></i> Personal Information
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Full Name</label>
                            <input type="text" name="name" class="form-control"
                                   value="<?= htmlspecialchars($lecturer['name']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Email Address</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?= htmlspecialchars($lecturer['email']) ?>" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Department</label>
                            <input type="text" name="department" class="form-control"
                                   value="<?= htmlspecialchars($lecturer['department'] ?? '') ?>"
                                   placeholder="e.g. Computer Science">
                        </div>

                        <!-- Change Password -->
                        <div class="section-label">
                            <i class="bi bi-lock-fill me-1"></i> Change Password
                            <small class="text-muted fw-normal">(leave blank to keep current)</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">New Password</label>
                            <div class="input-group">
                                <input type="password" name="password" id="pw1" class="form-control"
                                       placeholder="Enter new password">
                                <span class="input-group-text toggle-pw" onclick="togglePw('pw1', this)">
                                    <i class="bi bi-eye"></i>
                                </span>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-semibold">Confirm Password</label>
                            <div class="input-group">
                                <input type="password" name="password2" id="pw2" class="form-control"
                                       placeholder="Confirm new password">
                                <span class="input-group-text toggle-pw" onclick="togglePw('pw2', this)">
                                    <i class="bi bi-eye"></i>
                                </span>
                            </div>
                        </div>

                        <!-- Buttons -->
                        <div class="d-grid gap-2">
                            <button type="submit" name="update_settings" class="btn btn-save">
                                <i class="bi bi-check-circle me-1"></i> Save Changes
                            </button>
                            <a href="profile.php" class="btn btn-outline-secondary">
                                <i class="bi bi-person-circle me-1"></i> Back to Profile
                            </a>
                        </div>

                    </form>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
// ── Avatar live preview ──
document.getElementById('avatarInput').addEventListener('change', function(){
    const file = this.files[0];
    if(file){
        const reader = new FileReader();
        reader.onload = e => document.getElementById('avatarPreview').src = e.target.result;
        reader.readAsDataURL(file);
    }
});

// ── Password show/hide toggle ──
function togglePw(inputId, btn){
    const input = document.getElementById(inputId);
    const icon  = btn.querySelector('i');
    if(input.type === 'password'){
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
}
</script>

<?php include "../includes/footer.php"; ?>