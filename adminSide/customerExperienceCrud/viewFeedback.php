<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';
require_once '../../config/pagination_helper.php';
require_once '../../config/feedback_helper.php';

if (!isLoggedIn() || !isAdmin()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();
$feedback = [];
$error = null;
$successMessage = $_SESSION['admin_feedback_success'] ?? null;
unset($_SESSION['admin_feedback_success']);
$feedbackUnavailable = false;
$csrfToken = feedbackCsrfToken();
$feedbackSearch = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$feedbackStatus = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['public', 'hidden', 'private'], true) ? $_GET['status'] : 'all';
$feedbackQueryParams = array_filter(['q' => $feedbackSearch, 'status' => $feedbackStatus === 'all' ? '' : $feedbackStatus], static fn($value) => $value !== '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validateFeedbackCsrf($_POST['csrf_token'] ?? null);
        $id = filter_var($_POST['feedback_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) throw new InvalidArgumentException('Invalid review.');
        if (!$db) throw new RuntimeException('Feedback database unavailable.');
        $action = $_POST['action'] ?? null;
        setFeedbackVisibility($db, $id, $action);
        $_SESSION['admin_feedback_success'] = $action === 'display'
            ? 'Review displayed on the homepage.'
            : 'Review hidden from the homepage. It is still available here.';
        $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;
        $returnParams = $feedbackQueryParams;
        if ($page > 1) $returnParams['page'] = $page;
        header('Location: viewFeedback.php' . ($returnParams ? '?' . http_build_query($returnParams) : ''), true, 303);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Throwable $e) {
        error_log('Update feedback visibility: ' . $e->getMessage());
        $error = 'Unable to update this review. Please try again.';
    }
}

try {
    if (!$db) throw new RuntimeException('Feedback database unavailable.');
    $query = "SELECT cf.*, m.first_name, m.last_name FROM customer_feedback cf
              JOIN customer m ON cf.member_id = m.member_id
              ORDER BY cf.feedback_date DESC";
    $stmt = $db->query($query);
    $feedback = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('View feedback: ' . $e->getMessage());
    $error = 'Unable to load feedback. Please try again.';
    $feedbackUnavailable = true;
}

$totalReviews = count($feedback);
$publicReviews = count(array_filter($feedback, static fn($review) => (int)($review['customer_shared'] ?? 0) === 1 && !empty($review['is_public'])));
$privateReviews = $totalReviews - $publicReviews;
$averageRating = $totalReviews ? number_format(array_sum(array_column($feedback, 'rating')) / $totalReviews, 1) : null;
$filteredFeedback = array_values(array_filter($feedback, static function ($review) use ($feedbackSearch, $feedbackStatus) {
    $shared = (int)($review['customer_shared'] ?? 0) === 1;
    $displayed = $shared && !empty($review['is_public']);
    if ($feedbackStatus === 'public' && !$displayed) return false;
    if ($feedbackStatus === 'hidden' && (!$shared || $displayed)) return false;
    if ($feedbackStatus === 'private' && $shared) return false;
    return $feedbackSearch === '' || mb_stripos($review['first_name'] . ' ' . $review['last_name'] . ' ' . $review['category'] . ' ' . $review['comment'], $feedbackSearch, 0, 'UTF-8') !== false;
}));
$feedbackPagination = paginate_array($filteredFeedback, 10);
$feedback = $feedbackPagination['items'];
$feedbackStatusUrl = static function ($status) use ($feedbackSearch) {
    $params = [];
    if ($feedbackSearch !== '') $params['q'] = $feedbackSearch;
    if ($status !== 'all') $params['status'] = $status;
    return htmlspecialchars('viewFeedback.php' . ($params ? '?' . http_build_query($params) : ''), ENT_QUOTES, 'UTF-8');
};

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Feedback - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../../config/inventory-layout.css?v=<?php echo filemtime(__DIR__ . '/../../config/inventory-layout.css'); ?>">
    <link rel="stylesheet" href="feedback.css?v=<?php echo filemtime(__DIR__ . '/feedback.css'); ?>">
</head>
<body class="feedback-page">
    <?php require __DIR__ . '/../../config/inventory_header.php'; ?>

    <main class="feedback-shell feedback-main">
        <header class="feedback-heading">
            <a class="feedback-back" href="../admin-dashboard.php"><span aria-hidden="true">&larr;</span> Back to Dashboard</a>
            <h1>Customer Feedback</h1>
            <p class="feedback-description">Read guest reviews and manage the feedback shared on your homepage.</p>
        </header>
        <nav class="inventory-sections feedback-sections" aria-label="Feedback views">
            <?php foreach (['all' => 'All Feedback', 'public' => 'On Homepage', 'hidden' => 'Hidden Reviews', 'private' => 'Private Reviews'] as $status => $label): ?>
                <a class="inventory-section" href="<?php echo $feedbackStatusUrl($status); ?>" <?php echo $feedbackStatus === $status ? 'aria-current="page"' : ''; ?>><?php echo $label; ?></a>
            <?php endforeach; ?>
        </nav>
        <?php if ($error): ?><div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($successMessage): ?><div class="feedback-notice" role="status"><?php echo htmlspecialchars($successMessage); ?></div><?php endif; ?>

        <section class="feedback-summary" aria-label="Feedback overview">
            <div class="feedback-stat">
                <span class="feedback-stat-label">Total reviews</span>
                <strong><?php echo $feedbackUnavailable ? '&mdash;' : number_format($totalReviews); ?></strong>
                <span class="feedback-stat-caption">All guest feedback</span>
            </div>
            <div class="feedback-stat">
                <span class="feedback-stat-label">Average rating</span>
                <strong><?php echo $feedbackUnavailable || $averageRating === null ? '&mdash;' : $averageRating; ?><span class="feedback-stat-unit"> / 5</span></strong>
                <span class="feedback-stat-caption">Across all reviews</span>
            </div>
            <div class="feedback-stat">
                <span class="feedback-stat-label"><span class="feedback-dot feedback-dot-public" aria-hidden="true"></span>Public reviews</span>
                <strong><?php echo $feedbackUnavailable ? '&mdash;' : number_format($publicReviews); ?></strong>
                <span class="feedback-stat-caption">Shared on the homepage</span>
            </div>
            <div class="feedback-stat">
                <span class="feedback-stat-label"><span class="feedback-dot feedback-dot-private" aria-hidden="true"></span>Off homepage</span>
                <strong><?php echo $feedbackUnavailable ? '&mdash;' : number_format($privateReviews); ?></strong>
                <span class="feedback-stat-caption">Hidden and private reviews</span>
            </div>
        </section>

        <section class="feedback-panel" aria-labelledby="feedback-list-title">
            <h2 id="feedback-list-title" class="visually-hidden">Customer feedback reviews</h2>
            <div class="feedback-panel-heading">
                <form class="feedback-search" method="GET" action="viewFeedback.php" role="search" aria-label="Search feedback">
                    <?php if ($feedbackStatus !== 'all'): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($feedbackStatus); ?>"><?php endif; ?>
                    <label class="visually-hidden" for="feedback-search">Search customers, categories, or comments</label>
                    <input type="search" id="feedback-search" name="q" class="form-control" value="<?php echo htmlspecialchars($feedbackSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search customer feedback...">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <?php if ($feedbackSearch !== ''): ?><a class="feedback-clear" href="<?php echo htmlspecialchars('viewFeedback.php' . ($feedbackStatus !== 'all' ? '?status=' . $feedbackStatus : ''), ENT_QUOTES, 'UTF-8'); ?>">Clear</a><?php endif; ?>
                </form>
                <div class="feedback-list-meta"><span class="feedback-count"><?php echo $feedbackUnavailable ? '&mdash;' : number_format($feedbackPagination['total_items']); ?></span> reviews<span class="feedback-sort">Newest first</span></div>
            </div>
        <aside class="feedback-privacy">
            <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3"/></svg>
            <p>Only reviews customers agree to share can appear on the homepage. You can hide or restore shared reviews. Private reviews stay visible only to the team.</p>
        </aside>

                <div class="feedback-table-wrap">
                    <table class="table feedback-table mb-0" role="table" aria-labelledby="feedback-list-title">
                        <thead>
                            <tr>
                                <th scope="col">Customer</th>
                                <th scope="col">Rating</th>
                                <th scope="col">Category</th>
                                <th scope="col">Comment</th>
                                <th scope="col">Submitted</th>
                                <th scope="col">Homepage</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$feedback): ?>
                            <tr class="feedback-empty-row"><td colspan="6">
                                <div class="feedback-empty">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 11a8 8 0 0 1-8 8H7l-4 3V7a4 4 0 0 1 4-4h6a8 8 0 0 1 8 8Z"/><path d="M7 9h10M7 13h6"/></svg>
                                    <strong><?php echo $feedbackUnavailable ? 'Feedback is temporarily unavailable' : ($totalReviews ? 'No matching reviews' : 'No reviews yet'); ?></strong>
                                    <p><?php echo $feedbackUnavailable ? 'Please refresh the page to try again.' : ($totalReviews ? 'Try another search or choose a different feedback view.' : 'Guest feedback will appear here when your first review arrives.'); ?></p>
                                </div>
                            </td></tr>
                            <?php endif; ?>
                            <?php foreach ($feedback as $f): ?>
                            <?php
                                $rating = max(0, min(5, (int)$f['rating']));
                                $customerShared = (int)($f['customer_shared'] ?? 0) === 1;
                                $isDisplayed = $customerShared && !empty($f['is_public']);
                            ?>
                            <tr role="row">
                                <td class="feedback-customer" data-label="Customer" role="cell">
                                    <div class="feedback-customer-inner">
                                        <span class="feedback-avatar" aria-hidden="true"><?php echo htmlspecialchars(mb_strtoupper(mb_substr(trim($f['first_name']), 0, 1) . mb_substr(trim($f['last_name']), 0, 1))); ?></span>
                                        <span class="feedback-customer-name"><?php echo htmlspecialchars($f['first_name'] . ' ' . $f['last_name']); ?></span>
                                    </div>
                                </td>
                                <td data-label="Rating" role="cell">
                                    <span class="feedback-rating" role="img" aria-label="<?php echo $rating; ?> out of 5 stars">
                                        <span aria-hidden="true"><?php echo str_repeat('&#9733;', $rating); ?><span class="feedback-empty-stars"><?php echo str_repeat('&#9734;', 5 - $rating); ?></span></span>
                                    </span>
                                </td>
                                <td data-label="Category" role="cell"><span class="feedback-category"><?php echo htmlspecialchars($f['category']); ?></span></td>
                                <td class="feedback-comment" data-label="Comment" role="cell"><span><?php echo htmlspecialchars($f['comment']); ?></span></td>
                                <td class="feedback-date" data-label="Submitted" role="cell"><time datetime="<?php echo date('Y-m-d', strtotime($f['feedback_date'])); ?>"><?php echo date('M d, Y', strtotime($f['feedback_date'])); ?></time></td>
                                <td data-label="Homepage" role="cell">
                                    <div class="feedback-visibility">
                                    <?php if ($isDisplayed): ?>
                                        <span class="feedback-status feedback-status-public"><span class="feedback-dot" aria-hidden="true"></span>Public</span>
                                    <?php elseif ($customerShared): ?>
                                        <span class="feedback-status feedback-status-private">Hidden</span>
                                    <?php else: ?>
                                        <span class="feedback-status feedback-status-private"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Private</span>
                                        <span class="feedback-consent-note">No customer sharing permission</span>
                                    <?php endif; ?>
                                    <?php if ($customerShared): ?>
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                            <input type="hidden" name="feedback_id" value="<?php echo (int)$f['feedback_id']; ?>">
                                            <button class="feedback-hide<?php echo !$isDisplayed ? ' feedback-display' : ''; ?>" name="action" value="<?php echo !$isDisplayed ? 'display' : 'hide'; ?>" aria-label="<?php echo !$isDisplayed ? 'Display' : 'Hide'; ?> review by <?php echo htmlspecialchars($f['first_name'] . ' ' . $f['last_name'], ENT_QUOTES, 'UTF-8'); ?> <?php echo !$isDisplayed ? 'on' : 'from'; ?> homepage"><?php echo !$isDisplayed ? 'Display on homepage' : 'Hide from homepage'; ?></button>
                                        </form>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php if ($feedbackPagination['total_items'] > 0): ?>
            <footer class="feedback-panel-footer">
                <?php if ($feedbackPagination['total_pages'] > 1): ?>
                    <?php echo render_pagination($feedbackPagination, 'page', $feedbackQueryParams); ?>
                <?php else: ?>
                    <span>Showing <?php echo $feedbackPagination['start_item']; ?>&ndash;<?php echo $feedbackPagination['end_item']; ?> of <?php echo number_format($feedbackPagination['total_items']); ?> reviews</span>
                <?php endif; ?>
            </footer>
            <?php endif; ?>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

