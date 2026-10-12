<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$error = '';
$success = '';
$staffId = $_GET['id'] ?? '';

if (empty($staffId)) {
    header('Location: ../userManagement/viewUsers.php#staff');
    exit;
}

$database = new Database();
$db = $database->connect();

// Fetch current details
$stmt = $db->prepare("SELECT * FROM staffs WHERE staff_id = :id");
$stmt->execute([':id' => $staffId]);
$staff = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$staff) {
    header('Location: ../userManagement/viewUsers.php#staff');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first_name = trim($_POST['first_name'] ?? '');
    $last_name = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = trim($_POST['role'] ?? '');
    $admin_password = $_POST['admin_password'] ?? '';
    $allowedRoles = getStaffRoleOptions();

    if (empty($first_name) || empty($last_name) || empty($role) || empty($admin_password)) {
        $error = 'Please fill in all required fields, including your Admin password.';
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error = 'Please select a valid role.';
    } else {
        try {
            // Verify Admin password
            verifyAdminPassword($db, $_SESSION['user_id'], $admin_password);

            $updateQuery = "UPDATE staffs SET first_name = :first_name, last_name = :last_name, phone = :phone, role = :role WHERE staff_id = :id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':first_name', $first_name);
            $updateStmt->bindParam(':last_name', $last_name);
            $updateStmt->bindParam(':phone', $phone);
            $updateStmt->bindParam(':role', $role);
            $updateStmt->bindParam(':id', $staffId);

            if ($updateStmt->execute()) {
                $changes = [];
                if ($first_name != $staff['first_name']) $changes['First Name'] = "Changed from '{$staff['first_name']}' to '{$first_name}'";
                if ($last_name != $staff['last_name']) $changes['Last Name'] = "Changed from '{$staff['last_name']}' to '{$last_name}'";
                if ($phone != $staff['phone']) $changes['Phone'] = "Changed from '{$staff['phone']}' to '{$phone}'";
                if ($role != $staff['role']) $changes['Role'] = "Changed from '{$staff['role']}' to '{$role}'";

                $detailsString = empty($changes) ? "No actual changes made." : implode(" | ", array_map(function($k, $v) { return "$k: $v"; }, array_keys($changes), $changes));

                sendAdminSecurityNotice("$first_name $last_name", "Updated Staff account details. " . $detailsString);
                
                // Log the action
                $audit_query = "INSERT INTO audit_logs (admin_id, action, action_details, table_affected, record_id, ip_address, timestamp) 
                                VALUES (:admin_id, 'EDIT_STAFF', :details, 'staffs', :record_id, :ip, NOW())";
                $audit_stmt = $db->prepare($audit_query);
                $audit_stmt->execute([
                    ':admin_id' => $_SESSION['user_id'],
                    ':details' => $detailsString,
                    ':record_id' => $staffId,
                    ':ip' => $_SERVER['REMOTE_ADDR']
                ]);

                if (!empty($changes)) {
                    sendUserNotification($staff['email'], "$first_name $last_name", $changes);
                }

                header('Location: ../userManagement/viewUsers.php?success=' . urlencode('Staff updated successfully.') . '#staff');
                exit;
            } else {
                $error = 'Failed to update staff member. Please try again.';
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
    <title>Edit Staff | Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        :root { --gold: #d4af37; --dark-bg: #121212; --card-bg: #1e1e1e; --border-color: #333; }
        body { background-color: var(--dark-bg); color: #e0e0e0; font-family: 'Segoe UI', sans-serif; }
        .text-primary-gold { color: var(--gold) !important; }
        .navbar { background-color: #000 !important; border-bottom: 1px solid var(--border-color); }
        .form-card { background: var(--card-bg); border: 1px solid var(--gold); border-radius: 12px; padding: 32px; max-width: 600px; margin: 40px auto; }
        .form-control, .form-select { background-color: #2a2a2a; border: 1px solid var(--border-color); color: #fff; }
        .form-control:focus, .form-select:focus { background-color: #333; border-color: var(--gold); color: #fff; box-shadow: 0 0 0 0.25rem rgba(212, 175, 55, 0.25); }
        .form-control:disabled { background-color: #1a1a1a; color: #777; border-color: #222; }
        label { font-weight: 600; color: #aaa; margin-bottom: 8px; }
        .btn-gold { background-color: var(--gold); border-color: var(--gold); color: #000; font-weight: bold; }
        .btn-gold:hover { background-color: #bfa12e; border-color: #bfa12e; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand text-primary-gold" href="../admin-dashboard.php">LA ELENA FISHYALAN</a>
            <div class="d-flex align-items-center"><a href="../../auth/logout.php" class="logout-link btn btn-sm btn-outline-danger">Logout</a></div>
        </div>
    </nav>

    <div class="container py-4">
        <a href="../userManagement/viewUsers.php#staff" class="btn btn-sm btn-outline-warning mb-3"><i class="fas fa-arrow-left"></i> Back to Directory</a>
        
        <div class="form-card">
            <h2 class="h3 mb-4 text-center">Edit Staff Profile</h2>
            
            <?php if ($error): ?>
                <div class="alert alert-danger bg-danger text-white border-0"><i class="fas fa-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="row g-3">


                    <div class="col-md-6">
                        <label class="form-label">First Name *</label>
                        <input type="text" name="first_name" class="form-control" value="<?php echo htmlspecialchars($_POST['first_name'] ?? $staff['first_name']); ?>" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label">Last Name *</label>
                        <input type="text" name="last_name" class="form-control" value="<?php echo htmlspecialchars($_POST['last_name'] ?? $staff['last_name']); ?>" required>
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label">Phone Number</label>
                        <input type="tel" name="phone" class="form-control" value="<?php echo htmlspecialchars($_POST['phone'] ?? $staff['phone']); ?>">
                    </div>
                    
                    <div class="col-md-6">
                        <label class="form-label">Role *</label>
                        <select name="role" class="form-select" required>
                            <?php foreach (getStaffRoleOptions() as $roleOption): ?>
                                <option value="<?php echo htmlspecialchars($roleOption); ?>" <?php echo ($staff['role'] === $roleOption) ? 'selected' : ''; ?>><?php echo htmlspecialchars($roleOption); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6 mt-4">
                        <p class="mb-1"><span class="text-gold">Status:</span> 
                            <span class="badge <?php echo $staff['account_status'] === 'ACTIVE' ? 'bg-success' : ($staff['account_status'] === 'DISABLED' ? 'bg-danger' : 'bg-warning text-dark'); ?>">
                                <?php echo htmlspecialchars($staff['account_status']); ?>
                            </span>
                        </p>
                    </div>
                    
                    <div class="col-12 mt-3 pt-3 border-top border-secondary">
                        <label class="form-label text-warning fw-medium"><i class="fas fa-shield-alt me-1"></i>Require Admin Password *</label>
                        <div class="input-group">
                            <span class="input-group-text bg-dark text-warning border-secondary"><i class="fas fa-user-shield"></i></span>
                            <input type="password" class="form-control" name="admin_password" required placeholder="Verify your admin password">
                        </div>
                        <small class="text-gold mt-1 d-block">Authorize this action with your own credentials.</small>
                    </div>

                    <div class="col-12 mt-4">
                        <button type="submit" class="btn btn-gold w-100 py-2"><i class="fas fa-save me-2"></i>Save Changes</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
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
<script src="../../assets/js/phone-validator.js"></script>
</body>
</html>
