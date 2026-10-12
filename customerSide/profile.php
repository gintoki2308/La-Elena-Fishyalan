<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once '../config/Mailer.php';

if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$user = getCurrentUser();
$database = new Database();
$db = $database->connect();
$supportsCustomerAddressComponents = $db instanceof PDO
    && dbTableHasColumn($db, 'customer', 'purok')
    && dbTableHasColumn($db, 'customer', 'barangay')
    && dbTableHasColumn($db, 'customer', 'municipality')
    && dbTableHasColumn($db, 'customer', 'province');

$success_msg = null;
$error = null;

// ---------- HANDLE PROFILE UPDATE ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_profile') {
    try {
        $firstName = trim($_POST['first_name']);
        $lastName  = trim($_POST['last_name']);
        $username  = trim($_POST['username'] ?? '');
        $email     = trim($_POST['email']);
        $phone     = trim($_POST['phone'] ?? '');
        // Combine 6 address fields into comma-separated string while preserving
        // the dedicated purok field for the Purok/Sitio column, which should not
        // absorb the street name when a legacy address is being edited.
        $addr_house    = trim($_POST['addr_house'] ?? '');
        $addr_street   = trim($_POST['addr_street'] ?? '');
        $addr_barangay = trim($_POST['addr_barangay'] ?? '');
        $addr_city     = trim($_POST['addr_city'] ?? '');
        $addr_province = trim($_POST['addr_province'] ?? '');
        $addr_zip      = trim($_POST['addr_zip'] ?? '');
        $has_addr = ($addr_house || $addr_street || $addr_barangay || $addr_city || $addr_province || $addr_zip);
        $address = $has_addr ? implode(', ', array_filter([
            $addr_house,
            $addr_street,
            $addr_barangay,
            $addr_city,
            $addr_province,
            $addr_zip,
        ], static function ($value) {
            return $value !== '';
        })) : '';
        $purok = trim((string) $addr_house);
        $bio       = trim($_POST['bio'] ?? '');
        $birthdate = trim($_POST['birthdate'] ?? '');
        $gender    = trim($_POST['gender'] ?? '');
        $jobTitle  = trim($_POST['job_title'] ?? '');
        $company   = trim($_POST['company'] ?? '');

        if (empty($firstName) || empty($lastName) || empty($email))
            throw new Exception("First Name, Last Name, and Email are required.");
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            throw new Exception("Please enter a valid email address.");
        if (strlen($bio) > 300)
            throw new Exception("Bio must be less than 300 characters.");

        if (!empty($username)) {
            $chk = $db->prepare("SELECT member_id FROM customer WHERE username=:u AND member_id!=:id");
            $chk->execute([':u' => $username, ':id' => $_SESSION['user_id']]);
            if ($chk->rowCount() > 0) throw new Exception("This username is already taken.");
        }

        $cur = $db->prepare("SELECT email, profile_picture, pending_email, email_token FROM customer WHERE member_id=:id");
        $cur->execute([':id' => $_SESSION['user_id']]);
        $currentRecord = $cur->fetch(PDO::FETCH_ASSOC);
        $currentEmail  = $currentRecord['email'];
        $currentPic    = $currentRecord['profile_picture'];
        $pendingEmail  = $currentRecord['pending_email'];
        $email_token   = $currentRecord['email_token'];

        $emailChanged = ($currentEmail !== $email && $email !== $pendingEmail);
        if ($emailChanged) {
            $ec = $db->prepare("SELECT member_id FROM customer WHERE email=:e AND member_id!=:id");
            $ec->execute([':e' => $email, ':id' => $_SESSION['user_id']]);
            if ($ec->rowCount() > 0) throw new Exception("Email already associated with another account.");
            $pendingEmail = $email;
            $email_token  = sprintf("%06d", mt_rand(100000, 999999));
            $email = $currentEmail;
            $subject = "Verify Your New Email - La Elena Fishyalan";
            $htmlBody = "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#333;'>
                <h2 style='color:#d4af37;'>Email Verification Code</h2>
                <p>Hello $firstName,</p>
                <p>Please use the code below to verify your new email address.</p>
                <div style='background:#f4f4f4;padding:20px;text-align:center;border-radius:8px;margin:20px 0;'>
                    <span style='font-size:32px;font-weight:bold;letter-spacing:5px;color:#1a1a1a;'>{$email_token}</span>
                </div></div>";
            sendMail($pendingEmail, $firstName, $subject, $htmlBody);
            $success_msg = "Profile updated. OTP sent to $pendingEmail. Please verify to apply the new email.";
        } else {
            $email = $currentEmail;
            $success_msg = "Your profile has been successfully updated!";
        }

        $profilePicPath = $currentPic;
        if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = dirname(__DIR__) . '/images/profiles/customer/';
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
            $ext = strtolower(pathinfo($_FILES['profile_picture']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg','jpeg','png'])) {
                if ($_FILES['profile_picture']['size'] < 2*1024*1024) {
                    $fname  = 'user_'.$_SESSION['user_id'].'_'.time().'.'.$ext;
                    $target = $upload_dir.$fname;
                    if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target))
                        $profilePicPath = 'images/profiles/customer/'.$fname;
                } else throw new Exception("Profile picture must be less than 2MB.");
            } else throw new Exception("Only JPG and PNG are allowed.");
        }

        $updateFields = "first_name=:fn,last_name=:ln,username=:un,email=:em,phone=:ph,
            address=:ad,bio=:bi,birthdate=:bd,gender=:ge,job_title=:jt,company=:co,
            profile_picture=:pp,pending_email=:pe,email_token=:et";
        $updateParams = [
            ':fn' => $firstName, ':ln' => $lastName, ':un' => empty($username) ? null : $username,
            ':em' => $email, ':ph' => $phone, ':ad' => $address, ':bi' => $bio,
            ':bd' => empty($birthdate) ? null : $birthdate, ':ge' => empty($gender) ? null : $gender,
            ':jt' => $jobTitle, ':co' => $company, ':pp' => $profilePicPath,
            ':pe' => $pendingEmail, ':et' => $email_token, ':id' => $_SESSION['user_id'],
        ];

        if ($supportsCustomerAddressComponents) {
            $updateFields .= ',purok=:purok,barangay=:barangay,municipality=:municipality,province=:province';
            $updateParams[':purok'] = $purok;
            $updateParams[':barangay'] = $addr_barangay;
            $updateParams[':municipality'] = $addr_city;
            $updateParams[':province'] = $addr_province;
        }

        $db->prepare("UPDATE customer SET {$updateFields} WHERE member_id=:id")
           ->execute($updateParams);

        $_SESSION['first_name']      = $firstName;
        $_SESSION['last_name']       = $lastName;
        $_SESSION['profile_picture'] = $profilePicPath ?: null;
        $user['first_name']          = $firstName;

    } catch (Exception $e) { $error = $e->getMessage(); }
}

// ---------- FETCH PROFILE ----------
$profileData = ['first_name'=>'','last_name'=>'','username'=>'','email'=>'','phone'=>'',
                'address'=>'','bio'=>'','birthdate'=>'','gender'=>'','job_title'=>'',
                'company'=>'','profile_picture'=>'','pending_email'=>'','join_date'=>'',
                'purok'=>'','barangay'=>'','municipality'=>'','province'=>''];
try {
    $s = $db->prepare("SELECT * FROM customer WHERE member_id=:id");
    $s->execute([':id' => $_SESSION['user_id']]);
    if ($row = $s->fetch(PDO::FETCH_ASSOC)) $profileData = array_merge($profileData, $row);
} catch (Exception $e) { $error = "Could not load profile. ".$e->getMessage(); }

// Prefer the address components collected at registration. Fall back to the
// legacy combined address for customer accounts created before this change.
$addr_parts = ['', '', '', '', '', ''];
$profilePurok = trim((string)($profileData['purok'] ?? ''));
$profilePurokParts = array_map('trim', preg_split('/\s*,\s*/', $profilePurok, 2));
$profilePurok = $profilePurokParts[0] ?? '';
$profileStreet = $profilePurokParts[1] ?? '';
$hasStructuredAddress = $supportsCustomerAddressComponents
    && (!empty($profileData['purok']) || !empty($profileData['barangay'])
        || !empty($profileData['municipality']) || !empty($profileData['province']));
if ($hasStructuredAddress) {
    $addr_parts = [
        $profilePurok,
        $profileStreet,
        $profileData['barangay'] ?? '',
        $profileData['municipality'] ?? '',
        $profileData['province'] ?? '',
        '',
    ];
} elseif (!empty($profileData['address'])) {
    $parsed = array_map('trim', explode(',', $profileData['address']));
    for ($i = 0; $i < min(count($parsed), 6); $i++) {
        $addr_parts[$i] = $parsed[$i];
    }
}

$avatarSrc = getProfileAvatar($profileData['profile_picture'] ?? '', $profileData['first_name'], $profileData['last_name']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile — La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@300;400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="customer-theme.css">
    <link rel="stylesheet" href="profile.css?v=<?php echo filemtime(__DIR__ . '/profile.css'); ?>">
</head>
<body class="customer-unified-layout customer-profile-page">
    <?php
    $customerNavPage = 'profile.php';
    require 'customer-navbar.php';
    ?>

<!-- ── NAVBAR ───────────────────────────────────────────────── -->


<!-- ── PROFILE HEADER ───────────────────────────────────────── -->
<main class="container profile-container">
    <a class="profile-back" href="customer-dashboard.php"><i class="fas fa-arrow-left me-2" aria-hidden="true"></i>Back to Dashboard</a>
    <div class="profile-page-heading">
        <h1>My Profile</h1>
        <p>Keep your personal details and contact information up to date.</p>
    </div>
    <section class="profile-header" aria-label="Profile overview">

        <!-- Avatar with upload overlay -->
        <button type="button" class="avatar-wrap" aria-label="Change profile photo" title="Change profile photo" onclick="document.getElementById('avatarInput').click();">
            <img src="<?php echo htmlspecialchars($avatarSrc); ?>" alt="Avatar" id="avatarPreview">
            <span class="avatar-overlay"><i class="fas fa-camera" aria-hidden="true"></i></span>
        </button>

        <!-- Hidden avatar upload form -->
        <form action="profile.php" method="POST" enctype="multipart/form-data" id="avatarForm" hidden>
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="first_name" value="<?php echo htmlspecialchars($profileData['first_name']); ?>">
            <input type="hidden" name="last_name"  value="<?php echo htmlspecialchars($profileData['last_name']); ?>">
            <input type="hidden" name="email"       value="<?php echo htmlspecialchars($profileData['pending_email'] ?: $profileData['email']); ?>">
            <input type="hidden" name="addr_house"    value="<?php echo htmlspecialchars($addr_parts[0]); ?>">
            <input type="hidden" name="addr_street"   value="<?php echo htmlspecialchars($addr_parts[1]); ?>">
            <input type="hidden" name="addr_barangay" value="<?php echo htmlspecialchars($addr_parts[2]); ?>">
            <input type="hidden" name="addr_city"     value="<?php echo htmlspecialchars($addr_parts[3]); ?>">
            <input type="hidden" name="addr_province" value="<?php echo htmlspecialchars($addr_parts[4]); ?>">
            <input type="hidden" name="addr_zip"      value="<?php echo htmlspecialchars($addr_parts[5]); ?>">
            <input type="file" name="profile_picture" id="avatarInput" hidden accept=".jpg,.jpeg,.png"
                   onchange="previewAndSubmit(this)">
        </form>

        <div class="profile-identity">
        <span class="profile-eyebrow">Your account</span>
        <h2 class="profile-name">
            <?php echo htmlspecialchars($profileData['first_name'].' '.$profileData['last_name']); ?>
        </h2>

        <?php if (!empty($profileData['username'])): ?>
            <p class="profile-username">@<?php echo htmlspecialchars($profileData['username']); ?></p>
        <?php endif; ?>

        <?php if (!empty($profileData['join_date'])): ?>
            <span class="profile-member">
                <i class="fas fa-award me-2 text-gold"></i>
                Member since <?php echo date('M Y', strtotime($profileData['join_date'])); ?>
            </span>
        <?php endif; ?>

        </div>
        <div class="profile-actions">
            <button type="button" class="btn btn-gold"
                    data-bs-toggle="modal" data-bs-target="#editProfileModal">
                <i class="fas fa-edit me-2"></i>Edit Profile
            </button>
            <a class="profile-settings" href="settings.php"><i class="fas fa-cog me-1" aria-hidden="true"></i>Account settings</a>
        </div>
    </section>

<!-- ── MAIN CONTENT ──────────────────────────────────────────── -->
<div class="profile-details">

    <!-- Alerts -->
    <?php if ($success_msg): ?>
        <div class="alert alert-success alert-dismissible border mb-3">
            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success_msg); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss notification"></button>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible border mb-3">
            <i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Dismiss notification"></button>
        </div>
    <?php endif; ?>
    <?php if ($profileData['pending_email']): ?>
        <div class="alert alert-warning border-0 shadow mb-3">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Pending email change to <strong><?php echo htmlspecialchars($profileData['pending_email']); ?></strong>.
            <a href="verify_email_change.php" class="text-dark fw-bold text-decoration-underline ms-1">Verify Now</a>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Contact Details -->
        <div class="col-12 col-lg-5">
            <div class="premium-card">
                <h4 class="text-gold border-bottom border-secondary pb-3 mb-4" style="font-size:1.05rem;">
                    <i class="fas fa-address-card me-2"></i>Contact Details
                </h4>

                <div class="info-label">Full Name</div>
                <div class="info-value">
                    <?php echo htmlspecialchars($profileData['first_name'].' '.$profileData['last_name']); ?>
                </div>

                <div class="info-label">Email Address</div>
                <div class="info-value text-info" style="word-break:break-all;">
                    <i class="fas fa-envelope me-2"></i>
                    <?php echo htmlspecialchars($profileData['email']); ?>
                </div>

                <div class="info-label">Phone Number</div>
                <div class="info-value">
                    <i class="fas fa-phone me-2"></i>
                    <?php echo !empty($profileData['phone'])
                        ? htmlspecialchars($profileData['phone'])
                        : '<span class="text-muted">Not provided</span>'; ?>
                </div>

                <div class="info-label border-top border-secondary pt-3 mt-1">Address Information</div>
                <?php if ($hasStructuredAddress || !empty($profileData['address'])): ?>
                    <?php $addressLabels = $hasStructuredAddress
                        ? ['Purok/Sitio', 'Street', 'Barangay', 'Municipality/City', 'Province', 'ZIP']
                        : ['House/Unit', 'Street', 'Barangay', 'City', 'Province', 'ZIP']; ?>
                    <dl class="profile-address">
                        <?php foreach ($addressLabels as $index => $label): ?>
                            <?php if (trim((string)$addr_parts[$index]) !== ''): ?>
                                <div>
                                    <dt><?php echo htmlspecialchars($label); ?></dt>
                                    <dd><?php echo htmlspecialchars($addr_parts[$index]); ?></dd>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </dl>
                <?php else: ?>
                    <div class="info-value mb-0"><span class="text-muted">Not provided</span></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- About Me -->
        <div class="col-12 col-lg-7">
            <div class="premium-card">
                <h4 class="text-gold border-bottom border-secondary pb-3 mb-4" style="font-size:1.05rem;">
                    <i class="fas fa-user-tag me-2"></i>About Me
                </h4>

                <?php if (!empty($profileData['bio'])): ?>
                    <p class="profile-bio mb-4">
                        "<?php echo nl2br(htmlspecialchars($profileData['bio'])); ?>"
                    </p>
                <?php else: ?>
                    <div class="profile-bio-empty">
                        <i class="fas fa-pen-fancy" aria-hidden="true"></i>
                        <p>A little about you</p>
                        <span>Add a short bio using Edit Profile.</span>
                    </div>
                <?php endif; ?>

                <div class="row g-3 pt-3 border-top border-secondary profile-personal-details">
                    <div class="col-6">
                        <div class="info-label">Job Title</div>
                        <div class="info-value mb-0" style="font-size:.9rem;">
                            <?php echo !empty($profileData['job_title']) ? htmlspecialchars($profileData['job_title']) : '<span class="text-muted">—</span>'; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Company</div>
                        <div class="info-value mb-0" style="font-size:.9rem;">
                            <?php echo !empty($profileData['company']) ? htmlspecialchars($profileData['company']) : '<span class="text-muted">—</span>'; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Birthdate</div>
                        <div class="info-value mb-0" style="font-size:.9rem;">
                            <?php echo !empty($profileData['birthdate'])
                                ? date('M d, Y', strtotime($profileData['birthdate']))
                                : '<span class="text-muted">—</span>'; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="info-label">Gender</div>
                        <div class="info-value mb-0" style="font-size:.9rem;">
                            <?php echo !empty($profileData['gender']) ? htmlspecialchars($profileData['gender']) : '<span class="text-muted">—</span>'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- ── EDIT PROFILE MODAL ────────────────────────────────────── -->
</main>

<div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-gold" id="editProfileTitle"><i class="fas fa-edit me-2"></i>Edit My Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="profile.php" method="POST">
                <input type="hidden" name="action" value="update_profile">
                <div class="modal-body p-4">

                    <h6 class="text-gold mb-3 border-bottom border-secondary pb-2">Identity &amp; Contact</h6>
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">First Name *</label>
                            <input type="text" name="first_name" class="form-control"
                                   value="<?php echo htmlspecialchars($profileData['first_name']); ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">Last Name *</label>
                            <input type="text" name="last_name" class="form-control"
                                   value="<?php echo htmlspecialchars($profileData['last_name']); ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">Username</label>
                            <div class="input-group">
                                <span class="input-group-text">@</span>
                                <input type="text" name="username" class="form-control"
                                       value="<?php echo htmlspecialchars($profileData['username']); ?>">
                            </div>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">Email *</label>
                            <input type="email" name="email" class="form-control"
                                   value="<?php echo htmlspecialchars($profileData['pending_email'] ?: $profileData['email']); ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">Phone</label>
                            <input type="tel" name="phone" class="form-control"
                                   value="<?php echo htmlspecialchars($profileData['phone']); ?>">
                        </div>
                        <div class="col-12 mt-3">
                            <h6 class="text-gold mb-3 border-bottom border-secondary pb-2">Address Information</h6>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">House/Unit No. *</label>
                            <input type="text" name="addr_house" class="form-control" placeholder="e.g. Blk 1 Lot 2"
                                   value="<?php echo htmlspecialchars($addr_parts[0]); ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">Street *</label>
                            <input type="text" name="addr_street" class="form-control" placeholder="e.g. Main Street"
                                   value="<?php echo htmlspecialchars($addr_parts[1]); ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">Barangay *</label>
                            <input type="text" name="addr_barangay" class="form-control" placeholder="e.g. Labuyo"
                                   value="<?php echo htmlspecialchars($addr_parts[2]); ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">City/Municipality *</label>
                            <input type="text" name="addr_city" class="form-control" placeholder="e.g. Bonifacio"
                                   value="<?php echo htmlspecialchars($addr_parts[3]); ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">Province *</label>
                            <input type="text" name="addr_province" class="form-control" placeholder="e.g. Miss.Occ"
                                   value="<?php echo htmlspecialchars($addr_parts[4]); ?>" required>
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">ZIP Code *</label>
                            <input type="text" name="addr_zip" class="form-control" placeholder="e.g. 1000"
                                   value="<?php echo htmlspecialchars($addr_parts[5]); ?>" required>
                        </div>
                    </div>

                    <h6 class="text-gold mb-3 border-bottom border-secondary pb-2">Personal Details</h6>
                    <div class="row g-3">
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">Job Title</label>
                            <input type="text" name="job_title" class="form-control"
                                   value="<?php echo htmlspecialchars($profileData['job_title']); ?>">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">Company</label>
                            <input type="text" name="company" class="form-control"
                                   value="<?php echo htmlspecialchars($profileData['company']); ?>">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">Birthdate</label>
                            <input type="date" name="birthdate" class="form-control"
                                   value="<?php echo htmlspecialchars($profileData['birthdate']); ?>">
                        </div>
                        <div class="col-12 col-sm-6">
                            <label class="form-label text-gold small text-uppercase">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="">Select...</option>
                                <?php foreach (['Male','Female','Other','Prefer not to say'] as $g): ?>
                                    <option value="<?php echo $g; ?>" <?php echo $profileData['gender']===$g?'selected':''; ?>>
                                        <?php echo $g; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 mt-2">
                            <label class="form-label text-gold small text-uppercase">Bio / About Me</label>
                            <textarea name="bio" class="form-control" rows="3" maxlength="300"
                                      placeholder="A short bio about yourself..."><?php echo htmlspecialchars($profileData['bio']); ?></textarea>
                            <div class="form-text text-muted" style="font-size:.75rem;">Max 300 characters</div>
                        </div>
                    </div>

                </div>
                <div class="modal-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <a href="settings.php#security" class="text-gold text-decoration-none small">
                        <i class="fas fa-lock me-1"></i>Security Settings
                    </a>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-gold px-4">Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
<script>
    // ── AVATAR PREVIEW + SUBMIT ───────────────────────────────
    function previewAndSubmit(input) {
        const file = input.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = e => {
            document.getElementById('avatarPreview').src = e.target.result;
        };
        reader.readAsDataURL(file);
        document.getElementById('avatarForm').submit();
    }

    // ── LOGOUT CONFIRM ────────────────────────────────────────
    document.addEventListener('click', function(e) {
        const link = e.target.closest('.logout-link');
        if (!link) return;
        e.preventDefault();
        const href = link.href || '../auth/logout.php';
        Swal.fire({
            title: 'Ready to leave?',
            text: 'You will be logged out of your session.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#c9533b',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Yes, logout',
            cancelButtonText: 'Stay',
            background: '#fffdf8',
            color: '#143d4a',
            reverseButtons: true,
            focusCancel: true,
        }).then(r => { if (r.isConfirmed) window.location.href = href; });
    });
</script>
<script src="../assets/js/phone-validator.js"></script>
</body>
</html>
