<?php
// Uses connection-local temporary tables; real feedback and customers are unchanged.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
session_save_path(sys_get_temp_dir());
require __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';
require __DIR__ . '/../config/feedback_helper.php';
$db = (new Database())->connect();
if (!$db) throw new RuntimeException('Start MySQL before running feedback checks.');
$db->exec('CREATE TEMPORARY TABLE customer (member_id INT PRIMARY KEY, first_name VARCHAR(100), last_name VARCHAR(100))');
$db->exec('CREATE TEMPORARY TABLE customer_feedback (feedback_id INT AUTO_INCREMENT PRIMARY KEY, member_id INT, rating INT, category VARCHAR(100), comment TEXT, feedback_date DATETIME, is_public TINYINT DEFAULT 0)');
$db->exec("INSERT INTO customer VALUES (1, 'Ana', 'Santos'), (2, 'Élodie', 'Ñunez')");
$checks = 0;
function checkFeedback(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$consentMigration = file_get_contents(__DIR__ . '/../database/migrations/20261010_add_feedback_sharing_consent.sql');
$db->exec("INSERT INTO customer_feedback (feedback_id, member_id, rating, category, comment, feedback_date, is_public) VALUES
    (-1, 1, 5, 'General', 'Legacy visible review', NOW(), 1),
    (-2, 1, 5, 'General', 'Legacy private review', NOW(), 0)");
$db->exec($consentMigration);
checkFeedback((int)$db->query('SELECT COUNT(*) FROM customer_feedback WHERE customer_shared = 1 OR is_public = 1')->fetchColumn() === 0, 'Legacy visibility is not treated as customer consent.');
checkFeedback((int)$db->query('SELECT COUNT(*) FROM customer_feedback')->fetchColumn() === 2, 'Consent migration preserves legacy review records.');
$db->exec('DELETE FROM customer_feedback WHERE feedback_id < 0');
$valid = ['rating' => '2', 'category' => 'General', 'comment' => 'Helpful staff.'];
submitCustomerFeedback($db, 1, $valid + ['customer_shared' => '1']);
checkFeedback(publicCustomerFeedback($db) === [], 'Private feedback is excluded.');
checkFeedback((int)$db->query('SELECT customer_shared FROM customer_feedback WHERE feedback_id = 1')->fetchColumn() === 0, 'Unchecked sharing stays private even with a forged consent field.');
submitCustomerFeedback($db, 1, $valid + ['is_public' => '1']);
$reviews = publicCustomerFeedback($db);
checkFeedback(count($reviews) === 1 && $reviews[0]['rating'] === 2, 'Public reviews include low ratings.');
checkFeedback($reviews[0]['name'] === 'Ana S.' && !isset($reviews[0]['member_id'], $reviews[0]['last_name']), 'Only display names are exposed.');
submitCustomerFeedback($db, 2, array_replace($valid, ['comment' => '<img src=x onerror=alert(1)>', 'is_public' => '1']));
$reviews = publicCustomerFeedback($db);
checkFeedback($reviews[0]['name'] === 'Élodie Ñ.' && $reviews[0]['initial'] === 'É', 'Unicode names and newest-first ordering.');
checkFeedback($reviews[0]['comment'] === '<img src=x onerror=alert(1)>', 'Comments preserved for safe text rendering.');
checkFeedback((int)$db->query('SELECT customer_shared FROM customer_feedback WHERE feedback_id = 2')->fetchColumn() === 1, 'Checked sharing records customer consent.');
$db->exec($consentMigration);
checkFeedback(count(publicCustomerFeedback($db)) === 2, 'Repeated migration preserves explicitly shared reviews.');
foreach ([['rating' => '5.5'], ['rating' => []], ['rating' => '0'], ['category' => 'invalid'], ['category' => []], ['comment' => []], ['comment' => '  '], ['comment' => str_repeat('a', 2001)]] as $invalid) {
    $rejected = false;
    try { submitCustomerFeedback($db, 1, array_replace($valid, $invalid)); }
    catch (InvalidArgumentException $e) { $rejected = true; }
    checkFeedback($rejected, 'Reject invalid input: ' . json_encode($invalid));
}
checkFeedback((int)$db->query('SELECT COUNT(*) FROM customer_feedback')->fetchColumn() === 3, 'Invalid requests create no records.');
validateFeedbackCsrf(feedbackCsrfToken());
foreach ([null, [], 'bad-token'] as $token) {
    $rejected = false;
    try { validateFeedbackCsrf($token); } catch (InvalidArgumentException $e) { $rejected = true; }
    checkFeedback($rejected, 'Invalid CSRF token rejected.');
}
$db->exec('UPDATE customer_feedback SET is_public = 0');
checkFeedback(publicCustomerFeedback($db) === [], 'Hidden reviews disappear.');
setFeedbackVisibility($db, 2, 'display');
checkFeedback(count(publicCustomerFeedback($db)) === 1, 'Admin can restore a hidden review with customer consent.');
setFeedbackVisibility($db, 2, 'display');
checkFeedback(count(publicCustomerFeedback($db)) === 1, 'Displaying an already public review is safe.');
setFeedbackVisibility($db, 2, 'hide');
setFeedbackVisibility($db, 2, 'hide');
checkFeedback(publicCustomerFeedback($db) === [], 'Admin can hide a displayed review repeatedly without deleting it.');
checkFeedback((int)$db->query('SELECT customer_shared FROM customer_feedback WHERE feedback_id = 2')->fetchColumn() === 1, 'Hiding a review preserves customer consent.');
$db->exec($consentMigration);
checkFeedback(publicCustomerFeedback($db) === [], 'Repeated migration does not restore moderated reviews.');
setFeedbackVisibility($db, 2, 'display');
checkFeedback(count(publicCustomerFeedback($db)) === 1, 'Admin can display a previously hidden shared review again.');
foreach ([[2, 'delete'], [2, ['display']], [0, 'display'], [999999, 'display'], [1, 'display']] as [$reviewId, $action]) {
    $rejected = false;
    try { setFeedbackVisibility($db, $reviewId, $action); }
    catch (InvalidArgumentException $e) { $rejected = true; }
    checkFeedback($rejected, 'Invalid actions, missing reviews, and publishing without customer consent are rejected.');
}
checkFeedback(count(publicCustomerFeedback($db)) === 1, 'Rejected visibility updates preserve the public feed.');
checkFeedback((int)$db->query('SELECT is_public FROM customer_feedback WHERE feedback_id = 1')->fetchColumn() === 0, 'Rejected publication leaves private feedback hidden.');
$db->exec('UPDATE customer_feedback SET is_public = 1 WHERE feedback_id = 1');
checkFeedback(count(publicCustomerFeedback($db)) === 1, 'Public feed excludes non-consenting reviews even if the visibility flag is set.');
setFeedbackVisibility($db, 1, 'hide');
checkFeedback((int)$db->query('SELECT COUNT(*) FROM customer_feedback')->fetchColumn() === 3, 'Visibility updates preserve all review records.');
setFeedbackVisibility($db, 2, 'hide');
for ($i = 0; $i < 15; $i++) submitCustomerFeedback($db, 1, $valid + ['is_public' => '1']);
checkFeedback(count(publicCustomerFeedback($db)) === 12, 'Public endpoint limits result size.');
echo "Passed $checks feedback checks.\n";
