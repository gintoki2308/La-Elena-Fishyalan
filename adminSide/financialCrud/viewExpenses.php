<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';
require_once '../../config/pagination_helper.php';
require_once '../../config/expense_helper.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();
$expenses = [];
$totalExpenses = 0;
$error = '';
$success = $_SESSION['expense_success'] ?? '';
unset($_SESSION['expense_success']);
$form = expenseFormValues($_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : []);

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

// Get expenses by category
try {
    $query = "SELECT * FROM expenses ORDER BY expense_date DESC, expense_id DESC";
    $stmt = $db->query($query);
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalQuery = "SELECT SUM(amount) as total FROM expenses WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $totalStmt = $db->query($totalQuery);
    $result = $totalStmt->fetch(PDO::FETCH_ASSOC);
    $totalExpenses = $result['total'] ?? 0;
} catch (Throwable $e) {
    error_log('Error loading expenses: ' . $e->getMessage());
    $error = $error ?: 'Unable to load expenses. Please try again.';
}

$expensesPagination = paginate_array($expenses, 10);
$expenses = $expensesPagination['items'];

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>View Expenses - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background-color: #0d0d0d; color: #f0f0f0; }
        .table { background-color: #1a1a1a; }
        .table th { color: #d4af37; background-color: #2d2d2d; }
        .card { background-color: #1a1a1a; border-color: #404040; }
        .stat-card { background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 100%); border: 2px solid #404040; padding: 20px; border-radius: 10px; }
        .total-amount { font-size: 2rem; color: #d4af37; font-weight: bold; }
        .form-control { background-color: #2d2d2d; border-color: #404040; color: #f0f0f0; }
        .btn-gold { background-color: #d4af37; color: #1a1a1a; border-color: #d4af37; font-weight: 600; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-4">
        <div class="container">
            <a class="navbar-brand" href="../../adminSide/admin-dashboard.php"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
        </div>
    </nav>

    <div class="container">
        <h1 class="mb-4"><i class="fas fa-receipt text-gold me-2"></i>Expense Tracking</h1>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="stat-card">
                    <h5>Total Expenses</h5>
                    <div class="total-amount">₱<?php echo number_format($totalExpenses, 2); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <button class="btn btn-gold w-100 h-100" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                    <i class="fas fa-plus me-2"></i>Record New Expense
                </button>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5 class="mb-0">Recent Expenses</h5></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Type</th>
                                <th>Category</th>
                                <th>Amount</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$expenses): ?>
                            <tr><td colspan="5" class="text-center py-4">No expenses recorded.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td><?php echo date('M d, Y', strtotime($expense['expense_date'])); ?></td>
                                <td><?php echo htmlspecialchars($expense['expense_type']); ?></td>
                                <td><span class="badge bg-info"><?php echo htmlspecialchars($expense['category']); ?></span></td>
                                <td>₱<?php echo number_format($expense['amount'], 2); ?></td>
                                <td><?php echo htmlspecialchars($expense['description'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php echo render_pagination($expensesPagination); ?>
            </div>
        </div>
    </div>

    <!-- Add Expense Modal -->
    <div class="modal fade" id="addExpenseModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content bg-dark border-secondary">
                <div class="modal-header border-secondary">
                    <h5 class="modal-title">Record Expense</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Expense Type *</label>
                            <input type="text" class="form-control" name="expense_type" maxlength="100" value="<?php echo htmlspecialchars($form['expense_type']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Amount (&#8369;) *</label>
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
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($form['description']); ?></textarea>
                        </div>
                    </div>
                    <div class="modal-footer border-secondary">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        <button type="submit" class="btn btn-gold">Record Expense</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

