<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once '../config/feedback_helper.php';

if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}
$database = new Database();
$db = $database->connect();
$success_msg = $_SESSION['feedback_success'] ?? null;
unset($_SESSION['feedback_success']);
$error = null;
$form = ['rating' => '', 'category' => '', 'comment' => '', 'is_public' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit_feedback') {
    foreach ($form as $key => $value) {
        $form[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    }
    try {
        validateFeedbackCsrf($_POST['csrf_token'] ?? null);
        if (!$db instanceof PDO) {
            throw new RuntimeException('Database unavailable.');
        }
        submitCustomerFeedback($db, (int)$_SESSION['user_id'], $_POST);
        $_SESSION['feedback_success'] = 'Thank you! Your feedback has been submitted.';
        header('Location: feedback.php', true, 303);
        exit;
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
    } catch (Exception $e) {
        error_log('Customer feedback: ' . $e->getMessage());
        $error = 'We could not send your feedback. Please try again in a moment.';
    }
}
$categories = ['Food Quality' => 'Food quality & taste', 'Service' => 'Staff service & hospitality', 'Delivery' => 'Delivery & packaging', 'Atmosphere' => 'Restaurant atmosphere', 'General' => 'General feedback'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Feedback - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Montserrat:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="customer-theme.css">
    <link rel="stylesheet" href="feedback.css">
</head>
<body class="customer-feedback-page">
    <?php require __DIR__ . '/customer-navbar.php'; ?>
    <main class="feedback-container">
        <header class="feedback-heading">
            <span class="feedback-eyebrow">WE'RE LISTENING</span>
            <h1>How did we do?</h1>
            <p>Good food brings us together. Your feedback makes us better.</p>
        </header>
        <?php if ($success_msg): ?>
            <div class="alert alert-success" role="status"><i class="fas fa-circle-check me-2" aria-hidden="true"></i><?php echo htmlspecialchars($success_msg); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
            <section class="feedback-form-card" aria-labelledby="feedback-form-title">
                <div class="feedback-card-heading">
                    <span class="feedback-heading-icon"><i class="far fa-comment-dots" aria-hidden="true"></i></span>
                    <div><h2 id="feedback-form-title">Tell us about your visit</h2><p>A little feedback goes a long way.</p></div>
                </div>
                <form action="feedback.php" method="POST" id="feedbackForm">
                    <input type="hidden" name="action" value="submit_feedback">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(feedbackCsrfToken()); ?>">
                    <fieldset class="feedback-field feedback-rating">
                        <legend>Overall rating <span class="feedback-required">*</span></legend>
                        <div class="feedback-rating-row">
                            <div class="rating-container">
                                <?php for ($star = 1; $star <= 5; $star++): ?>
                                    <input type="radio" id="star<?php echo $star; ?>" name="rating" value="<?php echo $star; ?>" required <?php echo $form['rating'] === (string)$star ? 'checked' : ''; ?>>
                                    <label for="star<?php echo $star; ?>" title="<?php echo $star; ?> out of 5 stars">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 2.78 5.63L21 9.54l-4.5 4.38 1.06 6.19L12 17.19l-5.56 2.92 1.06-6.19L3 9.54l6.22-.91L12 3Z"/></svg>
                                        <span class="visually-hidden"><?php echo $star; ?> out of 5 stars</span>
                                    </label>
                                <?php endfor; ?>
                            </div>
                            <span class="rating-description" id="ratingDescription" aria-live="polite">Select a rating</span>
                        </div>
                    </fieldset>
                    <div class="feedback-field">
                        <label for="feedbackCategory">What is your feedback about? <span class="feedback-required">*</span></label>
                        <select id="feedbackCategory" name="category" class="form-select" required>
                            <option value="" disabled <?php echo $form['category'] === '' ? 'selected' : ''; ?>>Choose a category</option>
                            <?php foreach ($categories as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $form['category'] === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="feedback-field">
                        <label for="feedbackComment">Your experience <span class="feedback-required">*</span></label>
                        <textarea id="feedbackComment" name="comment" class="form-control" rows="4" maxlength="2000" placeholder="What did you love? What could we do better?" aria-describedby="commentHint commentCount" required><?php echo htmlspecialchars($form['comment']); ?></textarea>
                        <div class="feedback-comment-help"><span id="commentHint">We'd love to hear the details.</span><span id="commentCount">0 / 2,000</span></div>
                    </div>
                    <div class="feedback-sharing">
                        <input type="checkbox" id="shareReview" name="is_public" value="1" aria-describedby="sharingHint" <?php echo $form['is_public'] === '1' ? 'checked' : ''; ?>>
                        <div><label for="shareReview">Share my review on the homepage</label><p id="sharingHint">Only your first name and last initial will appear. Leave unchecked to keep your feedback private and visible only to our team. Shared reviews may be hidden from the homepage by our team.</p></div>
                    </div>
                    <button type="submit" class="feedback-submit">Send feedback <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                    <p class="feedback-form-note"><i class="fas fa-lock" aria-hidden="true"></i> Please keep personal contact details out of your comments.</p>
                </form>
            </section>
    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="feedback-form.js"></script>
</body>
</html>
