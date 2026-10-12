<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';
require_once '../../config/Mailer.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// AUTHENTICATION (Admin + Super Admin)
if (!isLoggedIn() || !canManageBackOffice()) {
    header('Location: ' . app_url('adminSide/admin-dashboard.php'));
    exit;
}

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : null;
$type = $_GET['type'] ?? null;

if ($id === null || !$type) {
    header('Location: viewUsers.php?error=' . urlencode('Missing or invalid parameters.'));
    exit;
}

$database = new Database();
$db = $database->connect();

// Get target user details to display name
$targetName = "";
$targetEmail = "";
$table = "";
$idColumn = "";

try {
    if ($type === 'admin') {
        $table = "admins";
        $idColumn = "admin_id";
    } elseif ($type === 'staff') {
        $table = "staffs";
        $idColumn = "staff_id";
    } elseif ($type === 'customer') {
        $table = "customer";
        $idColumn = "member_id";
    } else {
        throw new Exception("Invalid user type.");
    }

    $stmt = $db->prepare("SELECT first_name, last_name, email FROM $table WHERE $idColumn = :id");
    $stmt->execute([':id' => $id]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow) {
        throw new Exception("User not found.");
    }

    $targetName = trim(($userRow['first_name'] ?? '') . ' ' . ($userRow['last_name'] ?? ''));
    $targetEmail = $userRow['email'];

} catch (Exception $e) {
    header("Location: viewUsers.php?error=" . urlencode($e->getMessage()));
    exit;
}

// Check if modifying self
$isSelf = ($type === 'admin' && $id == $_SESSION['user_id']);

// Role-based restriction: regular admins cannot modify other admin accounts
if ($type === 'admin' && !isSuperAdmin() && $id != $_SESSION['user_id']) {
    header('Location: viewUsers.php?error=' . urlencode('Regular admins cannot change email for other admin accounts.'));
    exit;
}

if ($type === 'admin' && isImmutableSuperadminAccount($db, $id) && $id != $_SESSION['user_id']) {
    header('Location: viewUsers.php?error=' . urlencode('The protected Super Admin email can only be managed by its owner.'));
    exit;
}

// Handle POST request
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newEmail = trim($_POST['new_email'] ?? '');
    $adminPassword = $_POST['admin_password'] ?? '';
    
    if (empty($newEmail) || empty($adminPassword)) {
        $error = "Both New Email and your Admin Password are required.";
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strtolower($newEmail) === strtolower($targetEmail)) {
        $error = "The new email must be different from the current email.";
    } else {
        try {
            // Require the currently signed-in administrator's real password.
            verifyAdminPassword($db, $_SESSION['user_id'], $adminPassword);

            if ($type === 'admin') {
                enforceImmutableSuperadminPolicy($db, $id, 'change_email', null, $newEmail);
            }

        // 2. Uniqueness Check Across All Tables (FIXED)
            $uniqueQuery = "
                SELECT email FROM admins WHERE email = :email1
                UNION
                SELECT email FROM staffs WHERE email = :email2
                UNION
                SELECT email FROM customer WHERE email = :email3
            ";
            $uniqueStmt = $db->prepare($uniqueQuery);
            // Bind using distinct named parameters
            $uniqueStmt->execute([
                ':email1' => $newEmail, 
                ':email2' => $newEmail, 
                ':email3' => $newEmail
            ]);
            
            if ($uniqueStmt->rowCount() > 0) {
                throw new Exception("This email address is already in use by another account.");
            }

            // 3. Update Database
            $updateStmt = $db->prepare("UPDATE $table SET email = :email WHERE $idColumn = :id");
            $updateStmt->execute([':email' => $newEmail, ':id' => $id]);

            // 4. Audit Log
            $details = "Changed email from '{$targetEmail}' to '{$newEmail}'";
            $auditQuery = "INSERT INTO audit_logs (admin_id, action, action_details, table_affected, record_id, ip_address, timestamp) 
                           VALUES (:admin_id, 'CHANGE_EMAIL', :details, :table_name, :record_id, :ip, NOW())";
            $auditStmt = $db->prepare($auditQuery);
            $auditStmt->execute([
                ':admin_id'   => $_SESSION['user_id'],
                ':details'    => $details,
                ':table_name' => $table,
                ':record_id'  => $id,
                ':ip'         => $_SERVER['REMOTE_ADDR']
            ]);

            // 5. Send Security Notification
            sendAdminSecurityNotice($targetName, $details);

            $changes = [
                'Old Email' => $targetEmail, 
                'New Email' => $newEmail
            ];
            
            // Notify target email (the old one)
            sendUserNotification($targetEmail, $targetName, $changes);
            
            // Notify new email
            sendUserNotification($newEmail, $targetName, $changes);

            // Log out if superadmin changed their own email
            if ($isSelf) {
                // Let's redirect to logout
                header('Location: ' . app_url('auth/logout.php?msg=email_changed'));
                exit;
            }

            header("Location: viewUsers.php?success=" . urlencode("Email updated successfully for $targetName."));
            exit;

        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Email - <?php echo htmlspecialchars($targetName); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { 
            background-color: #121212; 
            color: #e0e0e0; 
            font-family: 'Segoe UI', system-ui, sans-serif; 
        }
        .card { 
            background-color: #1e1e1e; 
            border: 1px solid #333;
            border-radius: 12px;
        }
        .text-gold { color: #d4af37; }
        .text-cyan { color: #0dcaf0; }
        .bg-darker { background-color: #181818; }
        
        /* Premium Input Styling */
        .form-control { 
            background-color: #2a2a2a; 
            border: 1px solid #444; 
            color: #fff; 
            transition: all 0.3s ease;
        }
        .input-email:focus { 
            background-color: #2f2f2f; 
            border-color: #0dcaf0; 
            color: #fff; 
            box-shadow: 0 0 8px rgba(13, 202, 240, 0.3); 
        }
        .input-admin:focus {
            background-color: #2f2f2f;
            border-color: #ffc107;
            color: #fff;
            box-shadow: 0 0 8px rgba(255, 193, 7, 0.3);
        }
        
        /* Unified Input Group Elements */
        .input-group-text { border-color: #444; }
        .btn-toggle-pswd {
            background-color: #2a2a2a;
            border: 1px solid #444;
            color: #888;
            transition: all 0.2s;
        }
        .btn-toggle-pswd:hover {
            background-color: #333;
            color: #ffc107;
        }

        /* Buttons */
        .btn-cyan { 
            background-color: #0dcaf0; 
            border: none; 
            color: #000; 
            font-weight: 600; 
            transition: transform 0.2s, background-color 0.2s;
        }
        .btn-cyan:hover { 
            background-color: #31d2f2; 
            color: #000;
            transform: translateY(-1px);
        }
        .btn-outline-light {
            border-color: #555;
            color: #aaa;
        }
        .btn-outline-light:hover {
            background-color: #333;
            color: #fff;
            border-color: #777;
        }
        
        /* Alerts */
        .alert-warning-custom {
            background-color: rgba(255, 193, 7, 0.1);
            border: 1px solid rgba(255, 193, 7, 0.4);
            color: #ffc107;
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3">

    <div class="card p-4 p-md-5 shadow-lg" style="width: 100%; max-width: 500px;">
        <div class="text-center mb-4">
            <h2 class="text-cyan fw-bold"><i class="fas fa-envelope me-2"></i>Change Email</h2>
        </div>
        
        <div class="bg-darker p-3 rounded border border-secondary mb-4">
            <small class="text-gold text-uppercase fw-semibold" style="letter-spacing: 0.5px;">Modifying Account For:</small>
            <div class="d-flex align-items-center mt-2">
                <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                    <i class="fas fa-user text-white"></i>
                </div>
                <div>
                    <strong class="d-block text-white fs-5"><?php echo htmlspecialchars($targetName); ?></strong>
                    <span class="text-gold"><i class="fas fa-at me-1"></i><?php echo htmlspecialchars($targetEmail); ?></span>
                </div>
            </div>
        </div>

        <?php if ($isSelf): ?>
            <div class="alert alert-warning-custom rounded d-flex align-items-start shadow-sm mb-4 p-3">
                <i class="fas fa-exclamation-triangle fs-4 me-3 mt-1"></i>
                <div>
                    <strong>Action Required:</strong> You are changing your own admin email address. Upon saving, you will be automatically logged out and must sign in again using your new email.
                </div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger bg-danger text-white border-0 d-flex align-items-center shadow-sm">
                <i class="fas fa-exclamation-circle fs-4 me-3"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-4">
                <label for="new_email" class="form-label text-light fw-medium">New Email Address</label>
                <div class="input-group shadow-sm">
                    <span class="input-group-text bg-dark text-cyan"><i class="fas fa-paper-plane"></i></span>
                    <input type="email" class="form-control input-email" id="new_email" name="new_email" required placeholder="name@example.com" value="<?php echo htmlspecialchars($_POST['new_email'] ?? ''); ?>">
                </div>
            </div>

            <div class="mb-4 pt-3 border-top border-secondary">
                <label for="admin_password" class="form-label text-warning fw-medium">
                    <i class="fas fa-shield-alt me-1"></i>Require Admin Password
                </label>
                <div class="input-group shadow-sm">
                    <span class="input-group-text bg-dark text-warning"><i class="fas fa-user-shield"></i></span>
                    <input type="password" class="form-control input-admin" id="admin_password" name="admin_password" required placeholder="Verify your admin password">
                    <button class="btn btn-toggle-pswd" type="button" onclick="togglePassword('admin_password', this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                <small class="text-gold mt-2 d-block"><i class="fas fa-info-circle me-1"></i>Authorize this action with your own credentials.</small>
            </div>

            <div class="d-flex justify-content-between mt-5">
                <a href="viewUsers.php" class="btn btn-outline-light px-4">Cancel</a>
                <button type="submit" class="btn btn-cyan px-4 shadow-sm"><i class="fas fa-save me-2"></i>Update Email</button>
            </div>
        </form>
    </div>

    <script>
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
                icon.classList.add('text-warning');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
                icon.classList.remove('text-warning');
            }
        }
    </script>
<!-- SweetAlert2 CDN -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.1/dist/sweetalert2.all.min.js"></script>
<script>
document.addEventListener('click', function(e) {
    const link = e.target.closest('.logout-link');
    if (link) {
        e.preventDefault();
        const targetHref = link.href || link.getAttribute('data-href') || '../auth/login.php';
        Swal.fire({
            title: 'Ready to leave?',
            text: 'You will be logged out of your session.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes, logout',
            background: '#2d2d2d',
            color: '#fff'
        }).then((result) => {
            if (result.isConfirmed) {
                if (link.tagName.toUpperCase() === 'BUTTON' && link.closest('form')) {
                    link.closest('form').submit();
                } else {
                    window.location.href = targetHref;
                }
            }
        });
    }
});
</script>
</body>
</html>
