<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/reservation_management_helper.php';

if (!isLoggedIn() || (!isAdmin() && !isStaff())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        validateReservationCsrf($_POST['csrf_token'] ?? null);
        $id = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
        $status = is_string($_POST['new_status'] ?? null) ? $_POST['new_status'] : '';
        $expectedStatus = null;
        if (isset($_POST['expected_status'])) {
            if (!is_string($_POST['expected_status']) || !isset(RESERVATION_STATUS_LABELS[$_POST['expected_status']])) {
                throw new InvalidArgumentException('Please reload the reservation and try again.');
            }
            $expectedStatus = $_POST['expected_status'];
        }
        $db = (new Database())->connect();
        if (!$db) {
            throw new RuntimeException('Database unavailable.');
        }
        $reservation = updateReservationStatus($db, (int)$id, $status, isAdmin() ? 'admin' : 'staff', (int)$_SESSION['user_id'], $expectedStatus);
        $_SESSION['reservation_success'] = $reservation
            ? 'Reservation #' . (int)$id . ' marked as ' . strtolower(RESERVATION_STATUS_LABELS[$status]) . '.' . notifyReservationStatus($reservation, $status)
            : 'Reservation already has that status.';
    } catch (Throwable $e) {
        $_SESSION['reservation_error'] = reservationError($e);
    }
}

$redirect = isStaff() ? 'staffSide/viewReservations.php' : 'adminSide/reservationsCrud/viewReservations.php';
if (isAdmin()) {
    $query = reservationListQuery(reservationListFilters($_POST));
    $redirect .= $query !== '' ? '?' . $query : '';
}
header('Location: ' . app_url($redirect), true, 303);
exit;
