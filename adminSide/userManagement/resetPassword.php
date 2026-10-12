<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

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

// Role-based restriction: regular admins cannot modify other admin accounts
if ($type === 'admin' && !isSuperAdmin() && $id != $_SESSION['user_id']) {
    header('Location: viewUsers.php?error=' . urlencode('Regular admins cannot reset passwords for other admin accounts.'));
    exit;
}

if ($type === 'admin' && isImmutableSuperadminAccount($db, $id) && $id != $_SESSION['user_id']) {
    header('Location: viewUsers.php?error=' . urlencode('The protected Super Admin password can only be reset by its owner.'));
    exit;
}

// Handle POST request
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = $_POST['new_password'] ?? '';
    $adminPassword = $_POST['admin_password'] ?? '';
    
    if (strlen($newPassword) < 6) {
        $error = "Password must be at least 6 characters long.";
    } elseif (empty($adminPassword)) {
        $error = "Your Admin Password is required to authorize this action.";
    } else {
        try {
            // Require the currently signed-in administrator's real password.
            verifyAdminPassword($db, $_SESSION['user_id'], $adminPassword);

            // Store every replacement password as a modern one-way hash.
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);

            $updateStmt = $db->prepare("UPDATE $table SET password = :pwd WHERE $idColumn = :id");
            if ($updateStmt->execute([':pwd' => $hashedPassword, ':id' => $id])) {
                sendAdminSecurityNotice($targetName, "Reset user password");
                
                // Log the action
                $audit_query = "INSERT INTO audit_logs (admin_id, action, action_details, table_affected, record_id, ip_address, timestamp) 
                                VALUES (:admin_id, 'RESET_PASSWORD', 'Password reset by admin', :table, :record_id, :ip, NOW())";
                $audit_stmt = $db->prepare($audit_query);
                $audit_stmt->execute([
                    ':admin_id' => $_SESSION['user_id'],
                    ':table' => $table,
                    ':record_id' => $id,
                    ':ip' => $_SERVER['REMOTE_ADDR']
                ]);

                // Send generic notice without the new password
                sendUserNotification(
                    $targetEmail, 
                    $targetName, 
                    [], // no changes array
                    "Your password has been reset by an Administrator. If you did not request this, please contact support immediately. Your new password has NOT been included in this email for security purposes."
                );

                header("Location: viewUsers.php?success=" . urlencode("Password reset successfully for $targetName."));
                exit;
            } else {
                throw new Exception("Failed to update password in database.");
            }

        } catch (Exception $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - <?php echo htmlspecialchars($targetName); ?></title>
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
        .bg-darker { background-color: #181818; }
        
        /* Premium Input Styling */
        .form-control { 
            background-color: #2a2a2a; 
            border: 1px solid #444; 
            color: #fff; 
            transition: all 0.3s ease;
        }
        .form-control:focus { 
            background-color: #2f2f2f; 
            border-color: #d4af37; 
            color: #fff; 
            box-shadow: 0 0 8px rgba(212, 175, 55, 0.3); 
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
            color: #d4af37;
        }
        
        /* Superadmin Field Specifics */
        .input-admin:focus {
            border-color: #ffc107;
            box-shadow: 0 0 8px rgba(255, 193, 7, 0.3);
        }

        /* Buttons */
        .btn-warning { 
            background-color: #d4af37; 
            border: none; 
            color: #121212; 
            font-weight: 600; 
            transition: transform 0.2s, background-color 0.2s;
        }
        .btn-warning:hover { 
            background-color: #f1c40f; 
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

        /* Password Strength Meter */
        .strength-meter {
            height: 4px;
            background-color: #333;
            border-radius: 2px;
            margin-top: 8px;
            overflow: hidden;
        }
        .strength-meter-fill {
            height: 100%;
            width: 0%;
            transition: width 0.3s ease, background-color 0.3s ease;
        }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 p-3">

    <div class="card p-4 p-md-5 shadow-lg" style="width: 100%; max-width: 500px;">
        <div class="text-center mb-4">
            <h2 class="text-gold fw-bold"><i class="fas fa-key me-2"></i>Reset Password</h2>
        </div>

        <div class="bg-darker p-3 rounded border border-secondary mb-4">
            <small class="text-gold text-uppercase fw-semibold" style="letter-spacing: 0.5px;">Modifying Account For:</small>
            <div class="d-flex align-items-center mt-2">
                <div class="rounded-circle bg-secondary d-flex align-items-center justify-content-center me-3" style="width: 40px; height: 40px;">
                    <i class="fas fa-user text-white"></i>
                </div>
                <div>
                    <strong class="d-block text-white fs-5"><?php echo htmlspecialchars($targetName); ?></strong>
                    <span class="text-gold"><i class="fas fa-envelope me-1"></i><?php echo htmlspecialchars($targetEmail); ?></span>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger bg-danger text-white border-0 d-flex align-items-center shadow-sm">
                <i class="fas fa-exclamation-circle fs-4 me-3"></i>
                <div><?php echo htmlspecialchars($error); ?></div>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="mb-4">
                <label for="new_password" class="form-label text-light fw-medium">New Password</label>
                <div class="input-group shadow-sm">
                    <span class="input-group-text bg-dark text-gold"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" id="new_password" name="new_password" required minlength="6" placeholder="Enter new password">
                    <button class="btn btn-toggle-pswd" type="button" onclick="togglePassword('new_password', this)">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>
                
                <div class="strength-meter">
                    <div class="strength-meter-fill" id="strength-fill"></div>
                </div>
                <div class="d-flex justify-content-between mt-1">
                    <small class="text-gold">Minimum 6 characters.</small>
                    <small id="strength-text" class="text-gold fw-bold"></small>
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
                <button type="submit" class="btn btn-warning px-4 shadow-sm"><i class="fas fa-save me-2"></i>Reset Password</button>
            </div>
        </form>
    </div>

    <script>
        // Reusable function to toggle password visibility
        function togglePassword(inputId, btn) {
            const input = document.getElementById(inputId);
            const icon = btn.querySelector('i');
            
            if (input.type === 'password') {
                input.type = 'text';
                icon.classList.replace('fa-eye', 'fa-eye-slash');
                icon.classList.add('text-gold');
            } else {
                input.type = 'password';
                icon.classList.replace('fa-eye-slash', 'fa-eye');
                icon.classList.remove('text-gold');
            }
        }

        // Simple Password Strength Evaluator
        const newPasswordInput = document.getElementById('new_password');
        const strengthFill = document.getElementById('strength-fill');
        const strengthText = document.getElementById('strength-text');

        newPasswordInput.addEventListener('input', function() {
            const val = newPasswordInput.value;
            let strength = 0;

            if (val.length >= 6) strength += 25; // Base length
            if (val.length >= 10) strength += 25; // Bonus length
            if (/[A-Z]/.test(val)) strength += 15; // Uppercase
            if (/[0-9]/.test(val)) strength += 15; // Numbers
            if (/[^A-Za-z0-9]/.test(val)) strength += 20; // Special chars

            // Cap at 100
            strength = Math.min(strength, 100);

            // Update UI
            strengthFill.style.width = strength + '%';

            if (val.length === 0) {
                strengthFill.style.width = '0%';
                strengthText.textContent = '';
            } else if (strength < 40) {
                strengthFill.style.backgroundColor = '#dc3545'; // Danger
                strengthText.textContent = 'Weak';
                strengthText.style.color = '#dc3545';
            } else if (strength < 75) {
                strengthFill.style.backgroundColor = '#ffc107'; // Warning
                strengthText.textContent = 'Moderate';
                strengthText.style.color = '#ffc107';
            } else {
                strengthFill.style.backgroundColor = '#198754'; // Success
                strengthText.textContent = 'Strong';
                strengthText.style.color = '#198754';
            }
        });
    </script>
</body>
</html>
