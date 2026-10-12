<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';

if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

$error = '';
$success = '';
$suppliers = [];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $supplier_name = $_POST['supplier_name'] ?? '';
    $contact_person = $_POST['contact_person'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $address = $_POST['address'] ?? '';
    $city = $_POST['city'] ?? '';
    $country = $_POST['country'] ?? '';

    if (empty($supplier_name) || empty($contact_person) || empty($email) || empty($phone)) {
        $error = "Please fill in all required fields.";
    } else {
        try {
            $insertQuery = "INSERT INTO suppliers (supplier_name, contact_person, email, phone, address, city, country, created_at)
                           VALUES (:name, :contact, :email, :phone, :address, :city, :country, NOW())";
            $insertStmt = $db->prepare($insertQuery);
            $insertStmt->bindParam(':name', $supplier_name);
            $insertStmt->bindParam(':contact', $contact_person);
            $insertStmt->bindParam(':email', $email);
            $insertStmt->bindParam(':phone', $phone);
            $insertStmt->bindParam(':address', $address);
            $insertStmt->bindParam(':city', $city);
            $insertStmt->bindParam(':country', $country);
            $insertStmt->execute();

            $success = "Supplier added successfully!";
            header("Refresh:2; url=viewInventory.php");
        } catch (Exception $e) {
            $error = "Error adding supplier: " . $e->getMessage();
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Supplier - Staff Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #0d0d0d;
            color: #f0f0f0;
        }
        .card {
            background-color: #1a1a1a;
            border-color: #404040;
        }
        .form-control {
            background-color: #2d2d2d;
            border-color: #404040;
            color: #f0f0f0;
        }
        .form-control:focus {
            background-color: #2d2d2d;
            border-color: #d4af37;
            color: #f0f0f0;
        }
        .btn-gold {
            background-color: #d4af37;
            border-color: #d4af37;
            color: #1a1a1a;
            font-weight: 600;
        }
        .btn-gold:hover {
            background-color: #b8941f;
            color: #1a1a1a;
        }
        .form-label {
            color: #d4af37;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="viewInventory.php">
                <i class="fas fa-arrow-left me-2"></i>Back to Inventory
            </a>
        </div>
    </nav>

    <div class="container" style="max-width: 600px;">
        <h1 class="mb-4"><i class="fas fa-plus text-gold me-2"></i>Add New Supplier</h1>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body p-4">
                <form method="POST">
                    <div class="mb-3">
                        <label for="supplier_name" class="form-label">Supplier Name *</label>
                        <input type="text" class="form-control" id="supplier_name" name="supplier_name" required>
                    </div>

                    <div class="mb-3">
                        <label for="contact_person" class="form-label">Contact Person *</label>
                        <input type="text" class="form-control" id="contact_person" name="contact_person" required>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">Email *</label>
                        <input type="email" class="form-control" id="email" name="email" required>
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone *</label>
                        <input type="tel" class="form-control" id="phone" name="phone" required>
                    </div>

                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <input type="text" class="form-control" id="address" name="address">
                    </div>

                    <div class="mb-3">
                        <label for="city" class="form-label">City</label>
                        <input type="text" class="form-control" id="city" name="city">
                    </div>

                    <div class="mb-4">
                        <label for="country" class="form-label">Country</label>
                        <input type="text" class="form-control" id="country" name="country">
                    </div>

                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-gold btn-lg">
                            <i class="fas fa-save me-2"></i>Add Supplier
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../../assets/js/phone-validator.js"></script>
</body>
</html>
