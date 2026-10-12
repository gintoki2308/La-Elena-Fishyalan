<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';
require_once '../../config/expense_helper.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();
$error = '';
$success = '';
$form = expenseFormValues($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : []);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$db) {
            throw new RuntimeException('Database connection unavailable.');
        }
        recordExpense($db, $_POST, (int)$_SESSION['user_id']);
        $_SESSION['expense_success'] = 'Expense recorded successfully!';
        header('Location: viewExpenses.php', true, 303);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Error recording expense: ' . $e->getMessage());
        $error = 'Unable to record the expense. Please try again.';
    }
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Record Expense - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #0d0d0d; color: #f0f0f0; }
        .card { background-color: #1a1a1a; border-color: #404040; }
        .form-control { background-color: #2d2d2d; border-color: #404040; color: #f0f0f0; }
        .form-label { color: #d4af37; font-weight: 600; }
        .btn-gold { background-color: #d4af37; color: #1a1a1a; border-color: #d4af37; font-weight: 600; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="viewExpenses.php"><i class="fas fa-arrow-left me-2"></i>Back</a>
        </div>
    </nav>

    <div class="container" style="max-width: 600px;">
        <h1 class="mb-4"><i class="fas fa-plus text-gold me-2"></i>Record New Expense</h1>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body p-4">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Expense Type *</label>
                        <input type="text" class="form-control" name="expense_type" maxlength="100" value="<?php echo htmlspecialchars($form['expense_type']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Amount (₱) *</label>
                        <input type="number" class="form-control" name="amount" step="0.01" min="0.01" max="99999999.99" value="<?php echo htmlspecialchars($form['amount']); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Category *</label>
                        <select class="form-control" name="category" required>
                            <?php foreach (EXPENSE_CATEGORIES as $category): ?>
                            <option value="<?php echo $category; ?>" <?php echo $form['category'] === $category ? 'selected' : ''; ?>><?php echo $category; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="4"><?php echo htmlspecialchars($form['description']); ?></textarea>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-gold btn-lg">Record Expense</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

