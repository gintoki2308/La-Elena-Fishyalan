<?php
// Run: C:\xampp\php\php.exe tests/reservations.php
// Connection-local temporary tables shadow the application tables; no real records change.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../config/reservation_helper.php';
require_once __DIR__ . '/../config/reservation_management_helper.php';

$db = (new Database())->connect();
if (!$db) {
    throw new RuntimeException('Start the local database before running these tests.');
}
$db->exec("CREATE TEMPORARY TABLE reservations (
    reservation_id INT AUTO_INCREMENT PRIMARY KEY, member_id INT NOT NULL,
    reservation_date DATETIME NOT NULL, party_size INT NOT NULL, special_requests TEXT,
    status ENUM('PENDING', 'CONFIRMED', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'PENDING'
) ENGINE=InnoDB");
$db->exec('CREATE TEMPORARY TABLE customer (member_id INT PRIMARY KEY, email VARCHAR(100), first_name VARCHAR(50), last_name VARCHAR(50)) ENGINE=InnoDB');
$db->exec("INSERT INTO customer VALUES (1, 'test@example.invalid', 'Test', 'Customer'), (2, 'other@example.invalid', 'Other', 'Customer')");
$db->exec('CREATE TEMPORARY TABLE audit_logs (log_id INT AUTO_INCREMENT PRIMARY KEY, admin_id INT NULL CHECK (admin_id > 0), staff_id INT NULL CHECK (staff_id > 0), action VARCHAR(100), table_affected VARCHAR(100), record_id INT, ip_address VARCHAR(45), timestamp DATETIME) ENGINE=InnoDB');
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $checks++;
}
function rejects(callable $operation, string $message): void {
    try {
        $operation();
    } catch (InvalidArgumentException $error) {
        check(true, $message);
        return;
    }
    throw new RuntimeException($message);
}
function status(PDO $db, int $id): string {
    return (string)$db->query('SELECT status FROM reservations WHERE reservation_id = ' . $id)->fetchColumn();
}
$day = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
rejects(fn() => validateReservationDate('2099-02-30T12:00'), 'Impossible calendar dates must fail.');
rejects(fn() => validateReservationDate(date('Y-m-d') . 'T12:00'), 'Same-day booking must fail.');
rejects(fn() => validateReservationDate($day . 'T10:59'), 'Before opening must fail.');
rejects(fn() => validateReservationDate($day . 'T22:00'), 'Closing time must fail.');
check(validateReservationDate($day . 'T11:00')->format('H:i') === '11:00', 'Opening time is valid.');
check(validateReservationDate($day . 'T21:59')->format('H:i') === '21:59', 'Last minute before closing is valid.');
rejects(fn() => createReservation($db, 1, $day . 'T12:00', '2.5', ''), 'Fractional parties must fail.');
rejects(fn() => createReservation($db, 1, $day . 'T12:00', '21', ''), 'Oversized parties must fail.');
rejects(fn() => validateReservationCsrf(null), 'Missing CSRF token must fail.');
rejects(fn() => validateReservationCsrf('invalid'), 'Invalid CSRF token must fail.');
validateReservationCsrf(reservationCsrfToken());
check(true, 'Current CSRF token must pass.');

$first = createReservation($db, 1, $day . 'T12:00', '20', 'Window seat');
check(status($db, $first) === 'PENDING', 'New reservations must be pending.');
rejects(fn() => createReservation($db, 2, $day . 'T12:00', '1', ''), 'Occupied exact times must fail.');
$second = createReservation($db, 2, $day . 'T12:15', '20', '');
$third = createReservation($db, 1, $day . 'T12:30', '10', '');
rejects(fn() => createReservation($db, 1, $day . 'T12:45', '1', ''), 'Hourly capacity must be enforced.');
rejects(fn() => cancelReservation($db, $first, 2), 'Other customers cannot cancel a booking.');
check(status($db, $first) === 'PENDING', 'Unauthorized cancellation must not modify status.');
cancelReservation($db, $first, 1);
check(status($db, $first) === 'CANCELLED', 'Owner can cancel.');
$replacement = createReservation($db, 2, $day . 'T12:00', '20', '');
rejects(fn() => updateReservationStatus($db, $first, 'CONFIRMED', 'admin', 1), 'Reactivation cannot take an occupied slot.');
check(status($db, $first) === 'CANCELLED', 'Failed reactivation must leave status unchanged.');
updateReservationStatus($db, $second, 'CONFIRMED', 'staff', 1);
check(status($db, $second) === 'CONFIRMED', 'Confirmation must exclude its own guests from capacity.');
check((int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === 1, 'Status changes must be audited.');
check(updateReservationStatus($db, $second, 'CONFIRMED', 'staff', 1) === null, 'Unchanged status must be a no-op.');
check((int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === 1, 'No-op must not duplicate audit records.');
rejects(fn() => updateReservationStatus($db, $second, 'CANCELLED', 'admin', 1, 'PENDING'), 'A stale form must not overwrite a newer status.');
check(status($db, $second) === 'CONFIRMED', 'Conflicting updates must preserve the current status.');
check((int)$db->query('SELECT COUNT(*) FROM audit_logs')->fetchColumn() === 1, 'Conflicting updates must not add audit records.');
try {
    updateReservationStatus($db, $third, 'CONFIRMED', 'admin', -1);
    throw new RuntimeException('Invalid audit actor must cause database failure.');
} catch (PDOException $expected) {
    check(status($db, $third) === 'PENDING', 'Audit failure must roll back the status change.');
}
updateReservationStatus($db, $third, 'COMPLETED', 'admin', 1);
rejects(fn() => cancelReservation($db, $third, 1), 'Completed bookings cannot be cancelled by customers.');
createReservation($db, 1, $day . 'T12:45', '10', '');
rejects(fn() => updateReservationStatus($db, $third, 'PENDING', 'admin', 1), 'Reactivation must enforce hourly capacity.');
rejects(fn() => updateReservationStatus($db, 999999, 'CONFIRMED', 'admin', 1), 'Unknown reservation must fail.');
rejects(fn() => updateReservationStatus($db, $first, 'INVALID', 'admin', 1), 'Unknown status must fail.');
$past = createReservation($db, 1, $day . 'T13:00', '2', '');
$db->exec("UPDATE reservations SET reservation_date = '2000-01-01 13:00:00', status = 'CANCELLED' WHERE reservation_id = $past");
rejects(fn() => updateReservationStatus($db, $past, 'PENDING', 'admin', 1), 'Past reservations cannot be reactivated.');
check(!$db->inTransaction(), 'Operations must close transactions even on failure.');
$lockName = 'reservations:' . sha1((string)$db->query('SELECT DATABASE()')->fetchColumn());
check((int)$db->query('SELECT IS_FREE_LOCK(' . $db->quote($lockName) . ')')->fetchColumn() === 1, 'Operations must release the reservation lock.');
$historyInsert = $db->prepare("INSERT INTO reservations (member_id, reservation_date, party_size, special_requests, status) VALUES
    (1, '2000-01-01 12:00:00', 2, '', 'PENDING'),
    (1, '2099-01-01 12:00:00', 2, '', 'COMPLETED'),
    (1, '2099-01-02 12:00:00', 2, '', 'CANCELLED'),
    (1, '2099-01-03 12:00:00', 2, '', 'CONFIRMED'),
    (2, '2000-01-01 12:00:00', 2, '', 'CANCELLED')");
$historyInsert->execute();
$deletedHistoryCount = clearReservationHistory($db, 1);
check($deletedHistoryCount >= 4, "History clearing must remove only the customer's past, completed, or cancelled reservations.");
check((int)$db->query("SELECT COUNT(*) FROM reservations WHERE member_id = 1 AND reservation_date = '2099-01-03 12:00:00' AND status = 'CONFIRMED'")->fetchColumn() === 1, 'History clearing must preserve future active reservations.');
check((int)$db->query("SELECT COUNT(*) FROM reservations WHERE member_id = 2 AND status = 'CANCELLED'")->fetchColumn() === 1, "History clearing must not remove another customer's reservations.");
rejects(fn() => clearReservationHistory($db, 0), 'History clearing must reject an invalid customer.');
$otherConnection = (new Database())->connect();
$db->query('SELECT GET_LOCK(' . $db->quote($lockName) . ', 0)')->fetchColumn();
try {
    rejects(fn() => reservationTransaction($otherConnection, function () {
        throw new RuntimeException('A concurrent operation must not enter a locked reservation transaction.');
    }), 'Concurrent reservation writers must wait and fail safely if the lock stays busy.');
} finally {
    $db->query('SELECT RELEASE_LOCK(' . $db->quote($lockName) . ')')->fetchColumn();
}
check(reservationTransaction($otherConnection, fn() => true), 'Another connection can proceed after the lock is released.');
$db->exec('ALTER TABLE customer ADD phone VARCHAR(30)');
$db->exec("UPDATE customer SET phone = '09123456789' WHERE member_id = 1");
$db->exec("INSERT INTO customer VALUES (3, 'literal@example.invalid', '100%_!', 'Literal', NULL)");
$db->exec('DELETE FROM reservations');
$insert = $db->prepare('INSERT INTO reservations (reservation_id, member_id, reservation_date, party_size, special_requests, status) VALUES (?, ?, ?, ?, ?, ?)');
for ($i = 1; $i <= 12; $i++) {
    $insert->execute([$i, 1, sprintf('2099-01-%02d 12:00:00', $i), 2, '', 'PENDING']);
}
$insert->execute([13, 2, date('Y-m-d') . ' 11:00:00', 4, '', 'CONFIRMED']);
$insert->execute([14, 2, date('Y-m-d') . ' 23:59:59', 3, '', 'CANCELLED']);
$insert->execute([15, 3, '2000-01-01 11:00:00', 1, '', 'COMPLETED']);
$insert->execute([16, 999, '2099-02-01 12:00:00', 2, '', 'CONFIRMED']);
$load = static fn(array $filters = []) => loadReservationManagement($db, reservationListFilters($filters));
$list = $load();
check($list['pagination']['total_items'] === 16 && count($list['items']) === 10, 'Reservation lists must paginate in the database.');
check($list['summary']['total'] === 16 && $list['summary']['pending'] === 12, 'Summary counts must cover all records.');
check($list['summary']['today'] === 1 && $list['summary']['guests'] === 4, 'Today counts must exclude cancelled and other dates.');
check($load(['page' => 999])['pagination']['current_page'] === 2, 'Out-of-range pages must clamp after filtering.');
check($load(['q' => 'Test Customer'])['pagination']['total_items'] === 12, 'Search must match full customer names.');
check($load(['q' => '091234'])['pagination']['total_items'] === 12, 'Search must match phone numbers.');
check($load(['q' => '#16'])['items'][0]['first_name'] === null, 'Bookings with a missing customer must remain visible.');
check($load(['q' => '%_!'])['pagination']['total_items'] === 1, 'LIKE wildcard characters must be searched literally.');
check($load(['q' => "' OR 1=1 --"])['pagination']['total_items'] === 0, 'Search text must be parameterized.');
check($load(['date' => date('Y-m-d')])['pagination']['total_items'] === 2, 'Date filters must include the complete day.');
check($load(['date' => date('Y-m-d'), 'status' => 'CONFIRMED'])['pagination']['total_items'] === 1, 'Date and status filters must combine.');
check($load(['q' => 'Test Customer', 'status' => 'CANCELLED'])['pagination']['total_items'] === 0, 'Name and status filters must combine.');
check($load(['status' => 'PENDING'])['items'][0]['reservation_id'] == 1, 'Upcoming reservations must sort by nearest schedule.');
check($load(['sort' => 'newest'])['items'][0]['reservation_id'] == 16, 'Newest bookings must sort by creation ID.');
check($load(['sort' => 'oldest'])['items'][0]['reservation_id'] == 15, 'Oldest dates must sort first.');
$invalidFilters = reservationListFilters(['q' => [], 'status' => [], 'date' => '2026-02-30', 'sort' => 'DROP TABLE', 'page' => []]);
check($invalidFilters === ['q' => '', 'status' => '', 'date' => '', 'sort' => 'schedule', 'page' => 1], 'Malformed filters must fall back safely.');
$returnFilters = reservationListFilters(['q' => 'Test & Customer', 'status' => 'PENDING', 'date' => '2099-01-01', 'page' => 2, 'sort' => 'newest']);
parse_str(reservationListQuery($returnFilters), $roundTrip);
check(reservationListFilters($roundTrip) === $returnFilters, 'Redirects must preserve encoded search, filters, sort and page.');
check(!str_contains(reservationListQuery(reservationListFilters(['redirect' => 'https://example.invalid'])), 'redirect'), 'Return URLs must use only allowlisted filters.');
check(reservationListQuery(reservationListFilters([])) === '', 'Default filters must use a clean URL.');

echo "Passed $checks reservation checks using temporary tables.\n";
