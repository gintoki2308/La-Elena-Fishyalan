<?php

function feedbackCsrfToken(): string {
    if (empty($_SESSION['feedback_csrf'])) {
        $_SESSION['feedback_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['feedback_csrf'];
}

function validateFeedbackCsrf($token): void {
    if (!is_string($token) || !hash_equals(feedbackCsrfToken(), $token)) {
        throw new InvalidArgumentException('Your form expired. Refresh the page and try again.');
    }
}

function submitCustomerFeedback(PDO $db, int $memberId, array $input): void {
    $rating = $input['rating'] ?? null;
    $category = $input['category'] ?? null;
    $comment = $input['comment'] ?? null;
    if (!is_scalar($rating) || !preg_match('/^[1-5]$/D', (string)$rating)) {
        throw new InvalidArgumentException('Please select a valid star rating.');
    }
    if (!is_string($category) || !in_array($category, ['Food Quality', 'Service', 'Delivery', 'Atmosphere', 'General'], true)) {
        throw new InvalidArgumentException('Please select a feedback category.');
    }
    if (!is_string($comment) || trim($comment) === '' || mb_strlen(trim($comment), 'UTF-8') > 2000) {
        throw new InvalidArgumentException('Please enter a comment of up to 2,000 characters.');
    }
    $customerShared = ($input['is_public'] ?? '') === '1' ? 1 : 0;
    $stmt = $db->prepare('INSERT INTO customer_feedback (member_id, rating, category, comment, feedback_date, customer_shared, is_public) VALUES (?, ?, ?, ?, NOW(), ?, ?)');
    $stmt->execute([$memberId, (int)$rating, $category, trim($comment), $customerShared, $customerShared]);
}

function setFeedbackVisibility(PDO $db, int $feedbackId, $action): void {
    if ($feedbackId < 1 || !in_array($action, ['display', 'hide'], true)) {
        throw new InvalidArgumentException('Invalid review action.');
    }
    $query = 'UPDATE customer_feedback SET is_public = ? WHERE feedback_id = ?';
    if ($action === 'display') {
        $query .= ' AND customer_shared = 1';
    }
    $stmt = $db->prepare($query);
    $stmt->execute([$action === 'display' ? 1 : 0, $feedbackId]);
    if ($stmt->rowCount() === 0) {
        $exists = $db->prepare('SELECT customer_shared FROM customer_feedback WHERE feedback_id = ?');
        $exists->execute([$feedbackId]);
        $customerShared = $exists->fetchColumn();
        if ($customerShared === false) {
            throw new InvalidArgumentException('This review no longer exists. Refresh the page and try again.');
        }
        if ($action === 'display' && (int)$customerShared !== 1) {
            throw new InvalidArgumentException('This customer has not agreed to share their review on the homepage.');
        }
    }
}

function publicCustomerFeedback(PDO $db): array {
    $rows = $db->query("SELECT cf.rating, cf.comment, cf.category, cf.feedback_date, c.first_name, c.last_name
        FROM customer_feedback cf JOIN customer c ON c.member_id = cf.member_id
        WHERE cf.customer_shared = 1 AND cf.is_public = 1 AND cf.rating BETWEEN 1 AND 5 AND TRIM(cf.comment) <> ''
        ORDER BY cf.feedback_date DESC, cf.feedback_id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    return array_map(static function (array $row): array {
        $first = trim($row['first_name'] ?? '') ?: 'Guest';
        $lastInitial = mb_substr(trim($row['last_name'] ?? ''), 0, 1, 'UTF-8');
        return [
            'name' => $first . ($lastInitial !== '' ? ' ' . $lastInitial . '.' : ''),
            'initial' => mb_strtoupper(mb_substr($first, 0, 1, 'UTF-8'), 'UTF-8'),
            'rating' => (int)$row['rating'],
            'comment' => $row['comment'],
            'category' => $row['category'],
            'date' => substr($row['feedback_date'], 0, 10),
        ];
    }, $rows);
}
