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
if ($id === null) {
    header('Location: viewUsers.php?error=' . urlencode('Missing or invalid Admin ID.'));
    exit;
}

$database = new Database();
$db = $database->connect();

// Fetch Admin
$stmt = $db->prepare("SELECT * FROM admins WHERE admin_id = :id");
$stmt->execute([':id' => $id]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    header('Location: viewUsers.php?error=Admin not found');
    exit;
}

// Role-based restriction: regular admins cannot edit other admin accounts
if (!isSuperAdmin() && $id != $_SESSION['user_id']) {
    header('Location: viewUsers.php?error=' . urlencode('Regular admins cannot edit other admin accounts.'));
    exit;
}

if (isImmutableSuperadminAccount($db, $id) && $id != $_SESSION['user_id']) {
    header('Location: viewUsers.php?error=' . urlencode('The protected Super Admin account can only be edited by its owner.'));
    exit;
}

$error = '';
$success = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? 'Admin';
    $admin_password = $_POST['admin_password'] ?? '';

    // Basic Validation
    if(empty($first_name) || empty($last_name) || empty($email) || empty($admin_password)) {
        $error = "Please fill all required fields, including your admin password.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email format.";
    } elseif (!isSuperAdmin() && $role !== $admin['role']) {
        $error = "Only Super Admins can change administrator roles.";
    } else {
        try {
            // Verify admin password before proceeding
            verifyAdminPassword($db, $_SESSION['user_id'], $admin_password);

            // Enforce immutable Super Admin role protection.
            enforceImmutableSuperadminPolicy($db, $id, 'change_role', $role, $email);

            // An email address identifies one account across the system.
            $emailCheck = $db->prepare(
                'SELECT 1 FROM admins WHERE email = :admin_email AND admin_id <> :admin_id
                 UNION SELECT 1 FROM staffs WHERE email = :staff_email
                 UNION SELECT 1 FROM customer WHERE email = :customer_email
                 LIMIT 1'
            );
            $emailCheck->execute([
                ':admin_email' => $email,
                ':admin_id' => $id,
                ':staff_email' => $email,
                ':customer_email' => $email,
            ]);
            if ($emailCheck->fetchColumn()) {
                throw new Exception('This email address is already in use by another account.');
            }

            // Update DB
        $updateQuery = "UPDATE admins SET first_name = :fname, last_name = :lname, email = :email, phone = :phone, role = :role WHERE admin_id = :id";
        $stmtUpdate = $db->prepare($updateQuery);
        $stmtUpdate->bindParam(':fname', $first_name);
        $stmtUpdate->bindParam(':lname', $last_name);
        $stmtUpdate->bindParam(':email', $email);
        $stmtUpdate->bindParam(':phone', $phone);
        $stmtUpdate->bindParam(':role', $role);
        $stmtUpdate->bindParam(':id', $id);

        if ($stmtUpdate->execute()) {
            $changes = [];
            if ($first_name != $admin['first_name']) $changes['First Name'] = "Changed from '{$admin['first_name']}' to '{$first_name}'";
            if ($last_name != $admin['last_name']) $changes['Last Name'] = "Changed from '{$admin['last_name']}' to '{$last_name}'";
            if ($email != $admin['email']) $changes['Email'] = "Changed from '{$admin['email']}' to '{$email}'";
            if ($phone != $admin['phone']) $changes['Phone'] = "Changed from '{$admin['phone']}' to '{$phone}'";
            if ($role != $admin['role']) $changes['Role'] = "Changed from '{$admin['role']}' to '{$role}'";
            
            $detailsString = empty($changes) ? "No actual changes made." : implode(" | ", array_map(function($k, $v) { return "$k: $v"; }, array_keys($changes), $changes));

            $audit_query = "INSERT INTO audit_logs (admin_id, action, action_details, table_affected, record_id, ip_address, timestamp) 
                            VALUES (:admin_id, 'EDIT_ADMIN', :details, 'admins', :record_id, :ip, NOW())";
            $audit_stmt = $db->prepare($audit_query);
            $audit_stmt->execute([
                ':admin_id' => $_SESSION['user_id'],
                ':details' => $detailsString,
                ':record_id' => $id,
                ':ip' => $_SERVER['REMOTE_ADDR']
            ]);

            sendAdminSecurityNotice("$first_name $last_name", "Updated Admin account details. " . $detailsString);
            
            if (!empty($changes)) {
                sendUserNotification($email, "$first_name $last_name", $changes);
            }
            $success = "Admin account updated successfully!";
            // Refresh data
            $admin['first_name'] = $first_name;
            $admin['last_name'] = $last_name;
            $admin['email'] = $email;
            $admin['phone'] = $phone;
            $admin['role'] = $role;
            
            // If they edited themselves, update session
            if ($id == $_SESSION['user_id']) {
                $_SESSION['first_name'] = $first_name;
                $_SESSION['last_name'] = $last_name;
                $_SESSION['email'] = $email;
                $_SESSION['admin_role'] = $role;
                if (isset($_SESSION['authenticated_identity'])) {
                    $_SESSION['authenticated_identity']['email'] = $email;
                }
            }
        } else {
            $error = "Failed to update admin account.";
        }
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
    <title>Edit Admin | La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #121212; color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        .card { background-color: #1e1e1e; border: 1px solid #333; }
        .text-gold { color: #d4af37; }
        .form-control, .form-select { background-color: #2a2a2a; border: 1px solid #444; color: #e0e0e0; }
        .form-control:focus, .form-select:focus { background-color: #333; border-color: #d4af37; color: #e0e0e0; box-shadow: 0 0 0 0.2rem rgba(212, 175, 55, 0.25); }
        .btn-warning { background-color: #d4af37; border: none; color: #000; font-weight: 600; }
        .btn-warning:hover { background-color: #f1c40f; color: #000; }
    </style>
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 py-5">

    <div class="card p-5 shadow-lg" style="width: 100%; max-width: 650px;">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <h2 class="text-gold"><i class="fas fa-edit me-2"></i>Edit Admin Profile</h2>
            <a href="viewUsers.php" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger bg-danger text-light border-0"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert alert-success bg-success text-light border-0"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label text-gold">First Name *</label>
                    <input type="text" class="form-control" name="first_name" required value="<?php echo htmlspecialchars($admin['first_name']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label text-gold">Last Name *</label>
                    <input type="text" class="form-control" name="last_name" required value="<?php echo htmlspecialchars($admin['last_name']); ?>">
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label text-gold">Email Address *</label>
                    <input type="email" class="form-control" name="email" required value="<?php echo htmlspecialchars($admin['email']); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label text-gold">Phone Number</label>
                    <input type="text" class="form-control" name="phone" value="<?php echo htmlspecialchars($admin['phone']); ?>">
                </div>
            </div>

            <div class="row g-3 mb-4">
                <div class="col-md-12">
                    <label class="form-label text-gold">Role *</label>
                    <?php if (isSuperAdmin()): ?>
                        <select class="form-select" name="role" required>
                            <option value="Admin" <?php echo ($admin['role'] === 'Admin') ? 'selected' : ''; ?>>Admin</option>
                            <option value="Super Admin" <?php echo ($admin['role'] === 'Super Admin') ? 'selected' : ''; ?>>Super Admin</option>
                        </select>
                    <?php else: ?>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($admin['role']); ?>" readonly>
                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($admin['role']); ?>">
                    <?php endif; ?>
                </div>
            </div>

            <hr class="border-secondary mb-4">

            <div class="mb-4">
                <label class="form-label text-warning fw-medium"><i class="fas fa-shield-alt me-1"></i>Require Admin Password *</label>
                <div class="input-group">
                    <span class="input-group-text bg-dark text-warning border-secondary"><i class="fas fa-user-shield"></i></span>
                    <input type="password" class="form-control" name="admin_password" required placeholder="Verify your admin password">
                </div>
                <small class="text-gold mt-1 d-block">Authorize this action with your own credentials.</small>
            </div>

            <div class="d-grid shadow-sm">
                <button type="submit" class="btn btn-warning btn-lg"><i class="fas fa-save me-2"></i>Save Changes</button>
            </div>
        </form>
    </div>

<script src="../../assets/js/phone-validator.js"></script>
</body>
</html>
