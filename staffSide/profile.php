<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once '../config/Mailer.php';

if (!isLoggedIn() || !isStaff()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$user = getCurrentUser();
$database = new Database();
$db = $database->connect();

$success_msg = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        if ($action === 'update_profile') {
            $firstName           = trim($_POST['first_name']);
            $lastName            = trim($_POST['last_name']);
            $phone               = trim($_POST['phone_number']);
            $displayName         = trim($_POST['display_name']);
            $pronouns            = trim($_POST['pronouns']);
            $professionalSummary = trim($_POST['professional_summary']);
            $hobbies             = trim($_POST['hobbies']);

            $updateStmt = $db->prepare("UPDATE staffs SET
                display_name = :display_name,
                pronouns = :pronouns,
                professional_summary = :professional_summary,
                hobbies = :hobbies,
                first_name = :first_name,
                last_name = :last_name,
                phone_number = :phone_number
                WHERE staff_id = :id");
            $updateStmt->execute([
                ':display_name'         => $displayName,
                ':pronouns'             => $pronouns,
                ':professional_summary' => $professionalSummary,
                ':hobbies'              => $hobbies,
                ':first_name'           => $firstName,
                ':last_name'            => $lastName,
                ':phone_number'         => $phone,
                ':id'                   => $_SESSION['user_id']
            ]);
            $_SESSION['display_name'] = $displayName;
            $success_msg = "Profile updated successfully.";
        }

        if ($action === 'update_avatar') {
            if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
                $upload_dir    = dirname(__DIR__) . '/images/profiles/staff/';
                if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
                    throw new Exception("Avatar upload folder could not be created.");
                }
                $file_extension = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
                $allowed = ['jpg', 'jpeg', 'png', 'gif'];

                if (in_array($file_extension, $allowed)) {
                    if ($_FILES['profile_picture']['size'] < 2 * 1024 * 1024) {
                        $file_name = 'avatar_' . $_SESSION['user_id'] . '_' . time() . '.' . $file_extension;
                        $target    = $upload_dir . $file_name;

                        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target)) {
                            $oldPicStmt = $db->prepare("SELECT profile_picture FROM staffs WHERE staff_id = :id");
                            $oldPicStmt->execute([':id' => $_SESSION['user_id']]);
                            $oldPic = $oldPicStmt->fetchColumn();
                            $old_file = realpath(dirname(__DIR__) . '/' . ltrim((string) $oldPic, '/'));
                            $avatar_dir = realpath($upload_dir);
                            if ($old_file && $avatar_dir && strpos($old_file, $avatar_dir . DIRECTORY_SEPARATOR) === 0) {
                                unlink($old_file);
                            }

                            $picPath = 'images/profiles/staff/' . $file_name;
                            $upd = $db->prepare("UPDATE staffs SET profile_picture = :pic WHERE staff_id = :id");
                            $upd->execute([':pic' => $picPath, ':id' => $_SESSION['user_id']]);
                            $_SESSION['profile_picture'] = $picPath;
                            $success_msg = "Avatar updated successfully.";
                        }
                    } else throw new Exception("Avatar file too large (max 2MB).");
                } else throw new Exception("Invalid file type for avatar.");
            }
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

$stmt = $db->prepare("SELECT * FROM staffs WHERE staff_id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

$avatarSrc = getProfileAvatar($staff['profile_picture'] ?? '', $staff['first_name'], $staff['last_name']);
$profileName = trim($staff['display_name'] ?? '') ?: trim($staff['first_name'] . ' ' . $staff['last_name']);
$navName = trim($staff['display_name'] ?? '') ?: $staff['first_name'];
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professional Profile - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="profile.css">
</head>

<body class="staff-profile-page">

    <!-- ═══ NAVBAR ════════════════════════════════════════════════ -->
    <nav class="site-navbar">
        <a class="brand d-flex align-items-center gap-2" href="staff-dashboard.php">
            <i class="fas fa-utensils flex-shrink-0"></i>
            <span>La Elena Fishyalan</span>
            <span class="staff-portal-badge">Staff Portal</span>
        </a>

        <div class="dropdown ms-3">
            <a class="nav-avatar-btn dropdown-toggle"
                href="#" id="staffDropdown" role="button"
                data-bs-toggle="dropdown" aria-expanded="false" aria-label="Open staff account menu">
                <img src="<?php echo htmlspecialchars($avatarSrc); ?>" alt=""
                    class="nav-avatar rounded-circle flex-shrink-0"
                    width="42" height="42">
                <span class="nav-user-details d-none d-sm-inline text-start">
                    <span class="d-block"><?php echo htmlspecialchars($navName); ?></span>
                    <small class="d-block text-gold"><?php echo htmlspecialchars($staff['role'] ?? 'Staff'); ?></small>
                </span>
            </a>
            <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark-custom">
                <li><a class="dropdown-item" href="viewAttendance.php"><i class="fas fa-clock me-2"></i>My Attendance</a></li>
                <li>
                    <a class="dropdown-item active" href="profile.php">
                        <i class="fas fa-user-tie me-2"></i>Professional Profile
                    </a>
                </li>
                <li>
                    <a class="dropdown-item" href="settings.php">
                        <i class="fas fa-cog me-2"></i>Account Settings
                    </a>
                </li>
                <li>
                    <hr class="dropdown-divider">
                </li>
                <li>
                    <a class="logout-link dropdown-item text-danger" href="../auth/logout.php">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </li>
            </ul>
        </div>
    </nav>

    <!-- ═══ PROFILE HEADER ════════════════════════════════════════ -->
    <header class="profile-header">
        <button type="button" class="avatar-wrap" aria-label="Change profile photo"
            onclick="document.getElementById('avatarInput').click();">
            <img src="<?php echo htmlspecialchars($avatarSrc); ?>" alt="Profile photo" id="avatarPreview">
            <span class="avatar-overlay" aria-hidden="true">
                <i class="fas fa-camera fa-xl text-white"></i>
            </span>
        </button>

        <form action="profile.php" method="POST" enctype="multipart/form-data" id="avatarForm">
            <input type="hidden" name="action" value="update_avatar">
            <input type="file" name="profile_picture" id="avatarInput" accept=".jpg,.jpeg,.png,.gif" hidden
                onchange="this.form.submit();">
        </form>

        <h1 class="profile-name"><?php echo htmlspecialchars($profileName); ?></h1>

        <div class="profile-role">
            <?php echo htmlspecialchars($staff['role'] ?? 'Staff'); ?>
            <?php if (!empty($staff['pronouns'])): ?>
                <span style="font-size:0.85em;"> (<?php echo htmlspecialchars($staff['pronouns']); ?>)</span>
            <?php endif; ?>
        </div>

        <div class="profile-meta-badges">
            <span class="meta-badge">
                <i class="fas fa-calendar-alt me-1"></i>
                Joined <?php echo date('M Y', strtotime($staff['hire_date'])); ?>
            </span>
            <span class="meta-badge">
                <i class="fas fa-envelope me-1"></i>
                <?php echo htmlspecialchars($staff['email']); ?>
            </span>
        </div>

        <button type="button" class="btn profile-edit-btn rounded-pill px-4"
            data-bs-toggle="modal" data-bs-target="#editProfileModal">
            <i class="fas fa-edit me-2"></i>Edit Profile
        </button>
    </header>

    <!-- ═══ MAIN CONTENT ══════════════════════════════════════════ -->
    <main class="profile-content container-fluid">

        <!-- Alerts -->
        <?php if ($success_msg): ?>
            <div class="alert-success-custom d-flex align-items-center gap-2 mb-4 alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle flex-shrink-0"></i>
                <span><?php echo htmlspecialchars($success_msg); ?></span>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Dismiss message"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert-danger-custom d-flex align-items-center gap-2 mb-4 alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle flex-shrink-0"></i>
                <span><?php echo htmlspecialchars($error); ?></span>
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Dismiss message"></button>
            </div>
        <?php endif; ?>

        <!-- Cards row -->
        <div class="row g-4 profile-cards-row">

            <!-- Contact details -->
            <div class="col-12 col-md-5">
                <section class="premium-card h-100" aria-labelledby="contactHeading">
                    <h2 id="contactHeading" class="card-heading">
                        <i class="fas fa-address-card me-2"></i>Contact Details
                    </h2>

                    <div class="info-label">Full Name</div>
                    <div class="info-value fs-5 fw-bold">
                        <?php echo htmlspecialchars($staff['first_name'] . ' ' . $staff['last_name']); ?>
                    </div>

                    <?php if (!empty($staff['display_name'])): ?>
                        <div class="info-label">Display Name</div>
                        <div class="info-value">
                            <?php echo htmlspecialchars($staff['display_name']); ?>
                        </div>
                    <?php endif; ?>

                    <div class="info-label">Email Address</div>
                    <div class="info-value contact-email">
                        <i class="fas fa-envelope me-2"></i>
                        <?php echo htmlspecialchars($staff['email']); ?>
                    </div>

                    <div class="info-label">Phone Number</div>
                    <div class="info-value">
                        <i class="fas fa-phone me-2 text-muted"></i>
                        <?php echo !empty($staff['phone_number'])
                            ? htmlspecialchars($staff['phone_number'])
                            : '<span class="text-gold">Not provided</span>'; ?>
                    </div>

                    <div class="info-label">Pronouns</div>
                    <div class="info-value">
                        <?php echo !empty($staff['pronouns'])
                            ? htmlspecialchars($staff['pronouns'])
                            : '<span class="text-gold">Prefer not to say</span>'; ?>
                    </div>

                    <div class="info-label pt-3 border-top">
                        System Role
                    </div>
                    <div class="info-value">
                        <span class="badge system-role-badge rounded-pill px-3 py-2">
                            <i class="fas fa-shield-alt me-1"></i>
                            <?php echo htmlspecialchars($staff['role'] ?? 'Staff'); ?>
                        </span>
                    </div>
                </section>
            </div>

            <!-- Summary + hobbies -->
            <div class="col-12 col-md-7">
                <section class="premium-card h-100 d-flex flex-column" aria-labelledby="summaryHeading">
                    <h2 id="summaryHeading" class="card-heading">
                        <i class="fas fa-quote-left me-2"></i>Professional Summary
                    </h2>

                    <div class="flex-grow-1">
                        <?php if (!empty($staff['professional_summary'])): ?>
                            <p class="professional-summary lh-lg">
                                "<?php echo nl2br(htmlspecialchars($staff['professional_summary'])); ?>"
                            </p>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-pen-fancy fa-3x mb-3 text-secondary"></i>
                                <p class="text-gold">No professional summary provided.<br>Click "Edit Profile" to add your bio.</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mt-auto pt-3 border-top">
                        <div class="info-label mb-3">
                            <i class="fas fa-heart me-2"></i>Interests &amp; Hobbies
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if (!empty($staff['hobbies'])): ?>
                                <?php foreach (explode(',', $staff['hobbies']) as $tag): ?>
                                    <?php if (trim($tag) !== ''): ?>
                                        <span class="hobby-badge">
                                            <?php echo htmlspecialchars(trim($tag)); ?>
                                        </span>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-gold small">No interests added.</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            </div>

        </div><!-- /row -->
    </main>

    <!-- ═══ MODALS ════════════════════════════════════════════════ -->

    <!-- Edit Profile -->
    <div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="editProfileTitle"><i class="fas fa-edit me-2"></i>Edit Profile Info</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form action="profile.php" method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="modal-body p-4">
                        <div class="row g-3">
                            <div class="col-12 col-sm-6">
                                <label class="form-label fw-bold">First Name</label>
                                <input type="text" name="first_name"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($staff['first_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label fw-bold">Last Name</label>
                                <input type="text" name="last_name"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($staff['last_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label fw-bold">Display Name</label>
                                <input type="text" name="display_name"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($staff['display_name'] ?? ''); ?>"
                                    placeholder="e.g. John S.">
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label fw-bold">Phone Number</label>
                                <input type="text" name="phone_number"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($staff['phone_number'] ?? ''); ?>">
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label fw-bold">Pronouns</label>
                                <select name="pronouns" class="form-select">
                                    <option value="">Prefer not to say</option>
                                    <?php foreach (['he/him', 'she/her', 'they/them', 'custom'] as $p): ?>
                                        <option value="<?php echo $p; ?>"
                                            <?php echo ($staff['pronouns'] === $p) ? 'selected' : ''; ?>>
                                            <?php echo $p; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-sm-6">
                                <label class="form-label fw-bold">Interests &amp; Hobbies</label>
                                <input type="text" name="hobbies"
                                    class="form-control"
                                    value="<?php echo htmlspecialchars($staff['hobbies'] ?? ''); ?>"
                                    placeholder="Comma separated keywords">
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-bold">Professional Summary</label>
                                <textarea name="professional_summary"
                                    class="form-control" rows="5" maxlength="500"
                                    placeholder="A short blurb about your career goals and background..."><?php echo htmlspecialchars($staff['professional_summary'] ?? ''); ?></textarea>
                                <div class="text-end small text-gold mt-1">Max 500 characters</div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gold px-4">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Skill -->
    <div class="modal fade" id="addSkillModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Skill</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="profile.php" method="POST">
                    <input type="hidden" name="action" value="add_skill">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Skill Name</label>
                            <input type="text" name="skill_name" class="form-control" required
                                placeholder="e.g. Master Chef, Wine Pairing">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Proficiency</label>
                            <select name="proficiency" class="form-select">
                                <option>Beginner</option>
                                <option>Intermediate</option>
                                <option>Advanced</option>
                                <option>Expert</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-gold">Add Skill</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Certification -->
    <div class="modal fade" id="addCertModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Certification</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="profile.php" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="add_certification">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Certification Name</label>
                            <input type="text" name="cert_name" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Issuing Organization</label>
                            <input type="text" name="issuer" class="form-control" required>
                        </div>
                        <div class="row g-2">
                            <div class="col-6 mb-3">
                                <label class="form-label">Date Earned</label>
                                <input type="date" name="date_earned" class="form-control" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">Expiry Date</label>
                                <input type="date" name="expiry_date" class="form-control">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">File attachment (optional)</label>
                            <input type="file" name="cert_file" class="form-control">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-gold">Save Certification</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Add Experience -->
    <div class="modal fade" id="addExpModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Past Work Experience</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form action="profile.php" method="POST">
                    <input type="hidden" name="action" value="add_experience">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Job Title</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Company</label>
                            <input type="text" name="company" class="form-control" required>
                        </div>
                        <div class="row g-2">
                            <div class="col-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control">
                                <small class="text-gold">Leave blank if current.</small>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description / Achievements</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" class="btn btn-gold">Add Experience</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- ═══ SCRIPTS ═══════════════════════════════════════════════ -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
    <script>
        document.addEventListener('click', function(e) {
            const link = e.target.closest('.logout-link');
            if (!link) return;
            e.preventDefault();
            const href = link.getAttribute('href') || '../auth/login.php';
            Swal.fire({
                title: 'Ready to leave?',
                text: 'You will be logged out of your session.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#143d4a',
                cancelButtonColor: '#c9533b',
                confirmButtonText: 'Yes, logout',
                background: '#fffdf8',
                color: '#143d4a'
            }).then(result => {
                if (result.isConfirmed) window.location.href = href;
            });
        });
    </script>
    <script src="../assets/js/phone-validator.js"></script>

</body>

</html>
