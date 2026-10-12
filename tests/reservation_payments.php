<?php
// Run with a writable session.save_path. All writes use connection-local temporary tables.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/reservations.php';
require_once __DIR__ . '/../config/reservation_payment_helper.php';
$db->exec('DELETE FROM reservations');
$db->exec('CREATE TEMPORARY TABLE menu_items (item_id INT PRIMARY KEY, item_name VARCHAR(100), price DECIMAL(10,2), stock_quantity INT, is_available TINYINT)');
$db->exec("INSERT INTO menu_items VALUES (1, 'Fish platter', 350.00, 10, 1), (2, 'Rice', 25.01, 20, 1), (3, 'Unavailable', 50, 5, 0)");
$db->exec('CREATE TEMPORARY TABLE online_orders (order_id INT PRIMARY KEY, member_id INT, status VARCHAR(30))');
$db->exec('CREATE TEMPORARY TABLE online_order_items (order_id INT, item_id INT, quantity INT)');
$prepare = fn($cart) => prepareReservationPayment($db, 1, $day . 'T12:00', '2', 'Window seat', $cart);
rejects(fn() => $prepare([]), 'An empty cart cannot proceed to payment.');
rejects(fn() => $prepare([3 => 1]), 'Unavailable items cannot proceed to payment.');
rejects(fn() => $prepare([1 => 11]), 'Insufficient stock must fail before displaying payment.');
rejects(fn() => $prepare([1 => '1.5']), 'Fractional quantities must fail.');
$draft = $prepare([1 => 2, 2 => 1]);
check($draft['total'] === 72501 && $draft['deposit'] === 36251, 'Deposit is 50% of quantities times prices, rounded up to one centavo.');
check((int)$db->query('SELECT COUNT(*) FROM reservations')->fetchColumn() === 0, 'Displaying payment must not create a booking.');
$_SESSION['reservation_payment'] = $draft;
$_SESSION['cart'] = [1 => 3, 2 => 1, 99 => 1];
rejects(fn() => submitReservationPayment($db, 2, $draft['token'], '1234567890123', '1'), 'Another account cannot use the draft.');
rejects(fn() => submitReservationPayment($db, 1, 'wrong', '1234567890123', '1'), 'An old or forged payment token must fail.');
rejects(fn() => submitReservationPayment($db, 1, $draft['token'], '1234567890123', null), 'Payment acknowledgement is required on the server.');
rejects(fn() => submitReservationPayment($db, 1, $draft['token'], '', '1'), 'Missing reference must fail.');
rejects(fn() => submitReservationPayment($db, 1, $draft['token'], '<script>123456</script>', '1'), 'Invalid reference must fail.');
// The displayed quote must remain stable if the menu changes after payment.
$db->exec('UPDATE menu_items SET price = 400 WHERE item_id = 1');
$id = submitReservationPayment($db, 1, $draft['token'], '1234567890123', '1');
$saved = $db->query('SELECT * FROM reservations WHERE reservation_id = ' . $id)->fetch();
check($saved['status'] === 'PENDING', 'Customer acknowledgement must not mark the booking confirmed.');
check(str_contains($saved['special_requests'], 'PHP 362.51') && str_contains($saved['special_requests'], 'PHP 362.50') && str_contains($saved['special_requests'], '1234567890123') && str_contains($saved['special_requests'], 'Window seat'), 'Deposit, balance, reference and requests must persist with the booking.');
check(str_contains($saved['special_requests'], '2 x Fish platter @ PHP 350.00'), 'Original item prices and quantities must persist.');
check(!isset($_SESSION['reservation_payment']), 'Success consumes the payment draft.');
check($_SESSION['cart'] === [1 => 1, 99 => 1], 'Only reservation quantities are removed from the cart.');
rejects(fn() => submitReservationPayment($db, 1, $draft['token'], '1234567890123', '1'), 'Repeated submission cannot book twice.');
check((int)$db->query('SELECT COUNT(*) FROM reservations')->fetchColumn() === 1, 'Repeated submission must preserve the single booking.');
$draft = prepareReservationPayment($db, 1, $day . 'T13:00', 2, '', [1 => 1]);
$_SESSION['reservation_payment'] = $draft;
createReservation($db, 2, $day . 'T13:00', 2, '');
rejects(fn() => submitReservationPayment($db, 1, $draft['token'], '1234567890123', '1'), 'Availability must be rechecked after payment.');
check(isset($_SESSION['reservation_payment']), 'A booking conflict must preserve payment details for recovery.');
echo "Passed $checks reservation and payment checks using temporary tables.\n";
