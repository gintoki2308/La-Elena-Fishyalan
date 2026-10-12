<?php
require_once '../config/config.php';
require_once '../config/Database.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

$success_msg = null;
$error = null;

// ---------- HANDLE ACTIONS (POST) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    try {
        // 1. Update Profile Info
        if ($action === 'update_profile') {
            $displayName = trim($_POST['display_name']);
            $jobTitle = trim($_POST['job_title']);
            $professionalSummary = trim($_POST['professional_summary']);
            $phone = trim($_POST['phone']);
            $firstName = trim($_POST['first_name']);
            $lastName = trim($_POST['last_name']);

            $updateStmt = $db->prepare("UPDATE admins SET 
                display_name = :display_name, 
                job_title = :job_title, 
                professional_summary = :professional_summary,
                phone = :phone,
                first_name = :first_name,
                last_name = :last_name
                WHERE admin_id = :id");
            $updateStmt->execute([
                ':display_name' => $displayName,
                ':job_title' => $jobTitle,
                ':professional_summary' => $professionalSummary,
                ':phone' => $phone,
                ':first_name' => $firstName,
                ':last_name' => $lastName,
                ':id' => $_SESSION['user_id']
            ]);

            $_SESSION['first_name'] = $firstName;
            $_SESSION['last_name'] = $lastName;
            $success_msg = "Profile updated successfully.";
        }

        // 2. Handle Profile Picture Upload
        if ($action === 'update_avatar') {
            if (!isset($_FILES['profile_picture'])) {
                throw new Exception("Choose an image to upload.");
            }

            $upload = $_FILES['profile_picture'];
            if ($upload['error'] !== UPLOAD_ERR_OK) {
                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE => "Avatar file exceeds the server upload limit.",
                    UPLOAD_ERR_FORM_SIZE => "Avatar file is too large.",
                    UPLOAD_ERR_PARTIAL => "Avatar upload was incomplete. Please try again.",
                    UPLOAD_ERR_NO_FILE => "Choose an image to upload.",
                    UPLOAD_ERR_NO_TMP_DIR => "Avatar upload failed because the server temporary folder is missing.",
                    UPLOAD_ERR_CANT_WRITE => "Avatar upload failed because the server could not write the file.",
                    UPLOAD_ERR_EXTENSION => "Avatar upload was blocked by a server extension."
                ];
                throw new Exception($uploadErrors[$upload['error']] ?? "Avatar upload failed. Please try again.");
            }

            $file_extension = strtolower(pathinfo($upload['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif'];
            if (!in_array($file_extension, $allowed, true) || @getimagesize($upload['tmp_name']) === false) {
                throw new Exception("Upload a valid JPG, PNG, or GIF image.");
            }
            if ($upload['size'] > 2 * 1024 * 1024) {
                throw new Exception("Avatar file too large (max 2MB).");
            }

            $upload_dir = dirname(__DIR__) . '/images/profiles/admin/';
            if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
                throw new Exception("Avatar upload folder could not be created.");
            }

            $file_name = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_extension;
            $target = $upload_dir . $file_name;
            if (!move_uploaded_file($upload['tmp_name'], $target)) {
                throw new Exception("Avatar could not be saved. Check the server folder permissions and try again.");
            }

            $oldPicStmt = $db->prepare("SELECT profile_picture FROM admins WHERE admin_id = :id");
            $oldPicStmt->execute([':id' => $_SESSION['user_id']]);
            $oldPic = $oldPicStmt->fetchColumn();
            $oldPicPath = dirname(__DIR__) . '/' . ltrim((string) $oldPic, '/');
            $avatar_dir = realpath($upload_dir);
            $old_file = realpath($oldPicPath);
            if ($old_file && $avatar_dir && strpos($old_file, $avatar_dir . DIRECTORY_SEPARATOR) === 0) {
                unlink($old_file);
            }

            $picPath = 'images/profiles/admin/' . $file_name;
            $upd = $db->prepare("UPDATE admins SET profile_picture = :pic WHERE admin_id = :id");
            $upd->execute([':pic' => $picPath, ':id' => $_SESSION['user_id']]);
            $success_msg = "Avatar updated successfully.";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// ---------- FETCH CURRENT DATA ----------
$stmt = $db->prepare("SELECT * FROM admins WHERE admin_id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

$displayName = trim($admin['display_name'] ?? '') ?: trim($admin['first_name'] . ' ' . $admin['last_name']);
$avatarSrc = getProfileAvatar($admin['profile_picture'] ?? '', $admin['first_name'] ?? '', $admin['last_name'] ?? '');
$jobTitle = trim($admin['job_title'] ?? '') ?: 'Administrator';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - La Elena Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="profile.css?v=<?php echo filemtime(__DIR__ . '/profile.css'); ?>">
</head>
<body class="profile-page">
    <nav class="navbar sticky-top profile-navbar" aria-label="Main navigation">
        <div class="container-fluid">
            <a class="navbar-brand" href="admin-dashboard.php">Admin Panel</a>
            <div class="dropdown ms-auto">
                <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                    <img src="<?php echo htmlspecialchars($avatarSrc); ?>" alt="" class="nav-avatar" width="36" height="36">
                    <span class="d-none d-sm-inline"><?php echo htmlspecialchars($displayName); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="adminDropdown">
                    <li><a class="dropdown-item active" href="profile.php" aria-current="page"><i class="fas fa-user me-2" aria-hidden="true"></i>My profile</a></li>
                    <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2" aria-hidden="true"></i>Settings</a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="logout-link dropdown-item text-danger" href="../auth/logout.php"><i class="fas fa-sign-out-alt me-2" aria-hidden="true"></i>Logout</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="profile-workspace">
        <div class="page-heading">
            <div>
                <div class="eyebrow">YOUR ACCOUNT</div>
                <h1>My profile</h1>
                <p>Manage your personal details and how you appear to your team.</p>
            </div>
            <a class="back-link" href="admin-dashboard.php"><i class="fas fa-arrow-left" aria-hidden="true"></i> Back to dashboard</a>
        </div>

        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-dismissible fade show" role="status">
                <i class="fas fa-check-circle me-2" aria-hidden="true"></i><?php echo htmlspecialchars($success_msg); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss notification"></button>
            </div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-circle me-2" aria-hidden="true"></i><?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss error"></button>
            </div>
        <?php endif; ?>

        <section class="profile-identity" aria-labelledby="profile-name">
            <div class="profile-cover">
                <span class="cover-brand"><i class="fas fa-utensils" aria-hidden="true"></i> LA ELENA<span>ADMIN WORKSPACE</span></span>
                <span class="cover-decoration" aria-hidden="true"></span>
            </div>
            <div class="identity-body">
                <div class="profile-avatar-wrap">
                    <img src="<?php echo htmlspecialchars($avatarSrc); ?>" alt="<?php echo htmlspecialchars($displayName); ?>'s profile photo" class="profile-avatar" width="112" height="112">
                    <button type="button" class="photo-button" onclick="document.getElementById('avatarInput').click()" aria-label="Change profile photo" title="Change profile photo"><i class="fas fa-camera" aria-hidden="true"></i></button>
                </div>
                <div class="identity-details">
                    <span class="profile-kicker">PERSONAL PROFILE</span>
                    <h2 id="profile-name"><?php echo htmlspecialchars($displayName); ?></h2>
                    <div class="identity-meta"><span><?php echo htmlspecialchars($jobTitle); ?></span><span class="role-badge"><i class="fas fa-shield-alt" aria-hidden="true"></i><?php echo htmlspecialchars($admin['role'] ?? 'Admin'); ?></span></div>
                </div>
                <button type="button" class="btn btn-profile-primary edit-profile-button" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fas fa-pen me-2" aria-hidden="true"></i>Edit profile</button>
            </div>
            <div class="identity-footer"><i class="far fa-image" aria-hidden="true"></i> Make it yours. Upload a profile photo using the camera button.<span>JPG, PNG or GIF · Max 2 MB</span></div>
            <form action="profile.php" method="POST" enctype="multipart/form-data" id="avatarForm">
                <input type="hidden" name="action" value="update_avatar">
                <input type="file" name="profile_picture" id="avatarInput" accept="image/jpeg,image/png,image/gif" aria-label="Upload profile photo" hidden onchange="if (this.files.length) this.form.submit();">
            </form>
        </section>

        <div class="profile-grid">
            <section class="profile-card contact-card" aria-labelledby="contact-title">
                <div class="card-heading"><span class="section-icon"><i class="far fa-address-card" aria-hidden="true"></i></span><div><h2 id="contact-title">Contact details</h2><p>Your personal and account information.</p></div></div>
                <dl class="contact-list">
                    <div class="contact-row"><span class="contact-icon"><i class="far fa-user" aria-hidden="true"></i></span><div><dt>Full name</dt><dd><?php echo htmlspecialchars($admin['first_name'] . ' ' . $admin['last_name']); ?></dd></div></div>
                    <div class="contact-row"><span class="contact-icon"><i class="far fa-envelope" aria-hidden="true"></i></span><div><dt>Email address</dt><dd><?php echo htmlspecialchars($admin['email'] ?? ''); ?></dd></div></div>
                    <div class="contact-row"><span class="contact-icon"><i class="fas fa-phone" aria-hidden="true"></i></span><div><dt>Phone number</dt><dd><?php echo !empty($admin['phone']) ? htmlspecialchars($admin['phone']) : '<span class="empty-value">Not added yet</span>'; ?></dd></div></div>
                    <div class="contact-row"><span class="contact-icon"><i class="fas fa-shield-alt" aria-hidden="true"></i></span><div><dt>System role</dt><dd><?php echo htmlspecialchars($admin['role'] ?? 'Admin'); ?></dd></div></div>
                </dl>
                <div class="contact-note"><i class="fas fa-lock" aria-hidden="true"></i> Your account details are kept private.</div>
            </section>

            <div class="profile-right-column">
                <section class="profile-card summary-card" aria-labelledby="summary-title">
                    <div class="card-heading"><span class="section-icon"><i class="fas fa-align-left" aria-hidden="true"></i></span><div><h2 id="summary-title">Professional summary</h2><p>A little about you and your role at La Elena.</p></div></div>
                    <?php if (trim($admin['professional_summary'] ?? '') !== ''): ?>
                        <p class="summary-text"><?php echo nl2br(htmlspecialchars($admin['professional_summary'])); ?></p>
                        <button type="button" class="text-action" data-bs-toggle="modal" data-bs-target="#editProfileModal">Edit summary <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                    <?php else: ?>
                        <div class="summary-empty"><span class="empty-icon"><i class="fas fa-pen-nib" aria-hidden="true"></i></span><h3>Your story starts here</h3><p>Share your experience, responsibilities, or what inspires you to lead your team.</p><button type="button" class="btn btn-profile-secondary" data-bs-toggle="modal" data-bs-target="#editProfileModal"><i class="fas fa-plus me-2" aria-hidden="true"></i>Add a summary</button></div>
                    <?php endif; ?>
                </section>
                <a class="settings-shortcut" href="settings.php"><span class="section-icon"><i class="fas fa-sliders" aria-hidden="true"></i></span><span><strong>Account settings</strong><span>Manage your password and account preferences.</span></span><i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </div>
        </div>
        <p class="workspace-footer">La Elena Fishyalan <span>·</span> A personal touch for your workspace</p>
    </main>

    <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div><span class="eyebrow">YOUR ACCOUNT</span><h2 class="modal-title" id="editProfileTitle">Edit profile</h2><p class="modal-intro">Keep your details up to date.</p></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="profile.php" method="POST" class="profile-edit-form">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6"><label for="firstName" class="form-label">First name <span class="required-mark">*</span></label><input type="text" name="first_name" id="firstName" class="form-control" autocomplete="given-name" value="<?php echo htmlspecialchars($admin['first_name'] ?? ''); ?>" required></div>
                            <div class="col-md-6"><label for="lastName" class="form-label">Last name <span class="required-mark">*</span></label><input type="text" name="last_name" id="lastName" class="form-control" autocomplete="family-name" value="<?php echo htmlspecialchars($admin['last_name'] ?? ''); ?>" required></div>
                            <div class="col-md-6"><label for="displayName" class="form-label">Display name</label><input type="text" name="display_name" id="displayName" class="form-control" value="<?php echo htmlspecialchars($admin['display_name'] ?? ''); ?>" placeholder="Name shown on your dashboard" aria-describedby="displayNameHelp"><div id="displayNameHelp" class="form-text">Leave blank to use your full name.</div></div>
                            <div class="col-md-6"><label for="jobTitle" class="form-label">Job title</label><input type="text" name="job_title" id="jobTitle" class="form-control" autocomplete="organization-title" value="<?php echo htmlspecialchars($admin['job_title'] ?? ''); ?>" placeholder="e.g. Restaurant Manager"></div>
                            <div class="col-12"><label for="phoneNumber" class="form-label">Phone number</label><input type="tel" name="phone" id="phoneNumber" class="form-control" autocomplete="tel" value="<?php echo htmlspecialchars($admin['phone'] ?? ''); ?>" placeholder="Your contact number"></div>
                            <div class="col-12"><label for="professionalSummary" class="form-label">Professional summary</label><textarea name="professional_summary" id="professionalSummary" class="form-control" rows="5" placeholder="Tell your team about your role, experience, and vision..."><?php echo htmlspecialchars($admin['professional_summary'] ?? ''); ?></textarea></div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-profile-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-profile-primary"><i class="fas fa-check me-2" aria-hidden="true"></i>Save changes</button></div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
    <script src="../assets/js/phone-validator.js"></script>
</body>
</html>
