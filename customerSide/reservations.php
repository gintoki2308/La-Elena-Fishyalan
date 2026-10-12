<?php
require_once '../config/config.php';
require_once '../config/Database.php';
require_once '../config/pagination_helper.php';
require_once '../config/reservation_helper.php';
require_once '../config/reservation_payment_helper.php';

if (!isLoggedIn() || !isCustomer()) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

$error = null;
$success_msg = $_SESSION['reservation_success'] ?? null;
unset($_SESSION['reservation_success']);
$csrfToken = reservationCsrfToken();
$form_date = '';
$form_time = '';
$form_party = 2;
$form_notes = '';
$payment = $_SESSION['reservation_payment'] ?? null;
if ($payment && $payment['member_id'] !== (int)$_SESSION['user_id']) {
    unset($_SESSION['reservation_payment']);
    $payment = null;
}
$showPayment = $payment && (($_GET['step'] ?? '') === 'payment');
$paymentReference = is_string($_POST['payment_reference'] ?? null) ? trim($_POST['payment_reference']) : '';
$reservation_min_input = (new DateTimeImmutable('tomorrow'))->format('Y-m-d');
$reservation_min_time = sprintf('%02d:00', RESTAURANT_OPEN_HOUR);
$reservation_max_time = sprintf('%02d:59', RESTAURANT_CLOSE_HOUR - 1);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form_date = is_string($_POST['reservation_date'] ?? null) ? $_POST['reservation_date'] : '';
    $form_time = is_string($_POST['reservation_time'] ?? null) ? $_POST['reservation_time'] : '';
    $form_party = is_string($_POST['party_size'] ?? null) ? $_POST['party_size'] : '';
    $form_notes = is_string($_POST['special_requests'] ?? null) ? trim($_POST['special_requests']) : '';
    try {
        validateReservationCsrf($_POST['csrf_token'] ?? null);
        if (!$db) {
            throw new RuntimeException('Database unavailable.');
        }
        $action = $_POST['action'] ?? '';
        if ($action === 'make_reservation') {
            $showPayment = false;
            $_SESSION['reservation_payment'] = prepareReservationPayment($db, (int)$_SESSION['user_id'], $form_date . 'T' . $form_time, $form_party, $form_notes, $_SESSION['cart'] ?? []);
            header('Location: ' . app_url('customerSide/reservations.php?step=payment'), true, 303);
            exit;
        } elseif ($action === 'submit_payment') {
            $showPayment = (bool)$payment;
            $reservationId = submitReservationPayment($db, (int)$_SESSION['user_id'], $_POST['payment_token'] ?? null, $paymentReference, $_POST['payment_confirmed'] ?? null);
            $_SESSION['reservation_success'] = 'Reservation #' . $reservationId . ' submitted. Your GCash deposit and booking are pending staff confirmation.';
            header('Location: ' . app_url('customerSide/customer-dashboard.php'), true, 303);
            exit;
        } elseif ($action === 'edit_reservation' && $payment) {
            [$form_date, $form_time] = explode('T', $payment['date']);
            $form_party = $payment['party'];
            $form_notes = $payment['notes'];
            unset($_SESSION['reservation_payment']);
            $payment = null;
            $showPayment = false;
        } elseif ($action === 'cancel_reservation') {
            $id = filter_var($_POST['reservation_id'] ?? null, FILTER_VALIDATE_INT);
            cancelReservation($db, (int)$id, (int)$_SESSION['user_id']);
            $_SESSION['reservation_success'] = 'Reservation cancelled successfully.';
        } elseif ($action === 'clear_reservation_history') {
            $deletedCount = clearReservationHistory($db, (int)$_SESSION['user_id']);
            $_SESSION['reservation_success'] = $deletedCount > 0
                ? $deletedCount . ' past, completed, or cancelled reservation' . ($deletedCount === 1 ? '' : 's') . ' cleared.'
                : 'There were no past, completed, or cancelled reservations to clear.';
        } else {
            throw new InvalidArgumentException('Invalid reservation action.');
        }
        if ($action !== 'edit_reservation') {
            header('Location: ' . app_url('customerSide/reservations.php'), true, 303);
            exit;
        }
    } catch (Throwable $e) {
        $error = reservationError($e);
    }
}

// load existing reservations
$reservations = [];
$clearableHistoryCount = 0;
try {
    $stmt = $db->prepare("SELECT * FROM reservations WHERE member_id = :mid ORDER BY reservation_date DESC");
    $stmt->execute([':mid' => $_SESSION['user_id']]);
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $clearableStmt = $db->prepare("SELECT COUNT(*) FROM reservations
        WHERE member_id = :mid
        AND (reservation_date < NOW() OR status IN ('COMPLETED', 'CANCELLED'))");
    $clearableStmt->execute([':mid' => $_SESSION['user_id']]);
    $clearableHistoryCount = (int)$clearableStmt->fetchColumn();
} catch (Throwable $e) {
    if (!$error) $error = reservationError($e);
}

$reservationsPagination = paginate_array($reservations, 8);
$reservations = $reservationsPagination['items'];

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
$cart_count = array_sum($_SESSION['cart']);

$favorite_count = 0;
try {
    $favoriteStmt = $db->prepare("SELECT COUNT(*) FROM favorites WHERE member_id = :mid");
    $favoriteStmt->execute([':mid' => $_SESSION['user_id']]);
    $favorite_count = (int) $favoriteStmt->fetchColumn();
} catch (Throwable $e) {
    $favorite_count = 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservations - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600;700&family=Montserrat:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../style.css">
    
    <link rel="stylesheet" href="customer-theme.css">
    <link rel="stylesheet" href="reservations.css">
</head>
<body class="customer-reservations-page">

    <?php
    $role = $_SESSION['user_role'] ?? 'customer';
    $dashboardUrl = '../customerSide/customer-dashboard.php';
    if ($role === 'staff') {
        $dashboardUrl = '../staffSide/staff-dashboard.php';
    } elseif ($role === 'admin') {
        $dashboardUrl = '../adminSide/admin-dashboard.php';
    }
    ?>
    <?php
    $customerNavPage = 'reservations.php';
    $customerNavDashboardUrl = $dashboardUrl;
    require 'customer-navbar.php';
    ?>

    <main class="reservations-page">
        <div class="container reservations-container">
            
            <header class="reservations-header">
                <span class="reservations-eyebrow">A seat at our table</span>
                <h1>My Reservations</h1>
                <p>Plan your next gathering at La Elena Fishyalan.</p>
            </header>

            <?php if ($success_msg): ?>
                <div class="alert alert-success shadow-sm" role="status">
                    <i class="fas fa-check-circle me-2"></i> <?php echo htmlspecialchars($success_msg); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="alert alert-danger shadow-sm" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($showPayment): ?>
                <section class="reservation-card payment-card" aria-labelledby="payment-title">
                    <div class="booking-heading">
                        <span class="booking-icon" aria-hidden="true"><i class="fas fa-wallet"></i></span>
                        <div>
                            <h2 class="section-title" id="payment-title">Pay your 50% deposit</h2>
                            <p>Scan with GCash, then submit your payment reference to book.</p>
                        </div>
                    </div>
                    <div class="reservation-payment-grid">
                        <div class="payment-qr">
                            <a href="../images/payments/reservation-gcash.jpg" target="_blank" rel="noopener" aria-label="Open full-size GCash payment QR image">
                                <img src="../images/payments/reservation-gcash.jpg" alt="GCash payment QR code for the reservation deposit" width="1080" height="2026">
                            </a>
                            <a href="../images/payments/reservation-gcash.jpg" download="La-Elena-GCash.jpg">Download QR image</a>
                            <p>On your phone, save this image and select it in your payment app's QR scanner. Check the recipient before sending.</p>
                        </div>
                        <div class="payment-details">
                            <p><strong><?php echo htmlspecialchars(date('M d, Y, h:i A', strtotime($payment['date']))); ?></strong><br><?php echo (int)$payment['party']; ?> guests</p>
                            <h3>Your reservation items</h3>
                            <ul class="payment-items">
                                <?php foreach ($payment['items'] as $item): ?>
                                    <li><span><?php echo (int)$item['quantity']; ?> &times; <?php echo htmlspecialchars($item['name']); ?><small>&#8369;<?php echo number_format($item['price'] / 100, 2); ?> each</small></span><strong>&#8369;<?php echo number_format($item['subtotal'] / 100, 2); ?></strong></li>
                                <?php endforeach; ?>
                            </ul>
                            <div class="payment-total"><span>Items total</span><strong>&#8369;<?php echo number_format($payment['total'] / 100, 2); ?></strong></div>
                            <div class="payment-total payment-deposit"><span>Pay now &middot; 50%</span><strong>&#8369;<?php echo number_format($payment['deposit'] / 100, 2); ?></strong></div>
                            <div class="payment-total"><span>Remaining balance</span><strong>&#8369;<?php echo number_format(($payment['total'] - $payment['deposit']) / 100, 2); ?></strong></div>
                            <p class="payment-help">The deposit is based on item prices and quantities, before any fees or discounts. Your table is checked again when you submit. Staff will verify the payment and confirm your reservation.</p>
                            <form method="POST" action="reservations.php?step=payment" class="booking-form payment-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="submit_payment">
                                <input type="hidden" name="payment_token" value="<?php echo htmlspecialchars($payment['token']); ?>">
                                <label for="payment_reference" class="form-label">GCash reference number</label>
                                <input type="text" class="form-control" id="payment_reference" name="payment_reference" value="<?php echo htmlspecialchars($paymentReference); ?>" inputmode="numeric" pattern="[0-9]{10,20}" minlength="10" maxlength="20" placeholder="Reference number on your receipt" aria-describedby="reference-help" required>
                                <p id="reference-help" class="payment-help">Enter the 10 to 20 digit reference from your completed payment.</p>
                                <label class="payment-confirmation"><input type="checkbox" name="payment_confirmed" value="1" required><span>I have sent the &#8369;<?php echo number_format($payment['deposit'] / 100, 2); ?> deposit to the account shown.</span></label>
                                <button type="submit" class="book-table-btn">I've paid &mdash; Submit reservation <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                            </form>
                            <form method="POST" action="reservations.php" class="payment-back">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="edit_reservation">
                                <button type="submit" class="btn btn-outline-danger">Back to booking</button>
                            </form>
                            <p class="payment-help">If you have already sent payment and cannot finish booking, keep your reference and contact the restaurant before paying again.</p>
                        </div>
                    </div>
                </section>
            <?php else: ?>
            <div class="reservation-card">
                <div class="booking-heading">
                    <span class="booking-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M12 14v4M10 16h4"/></svg>
                    </span>
                    <div>
                        <h2 class="section-title">Book a Table</h2>
                        <p>Choose a date, bring your people, and leave the cooking to us.</p>
                    </div>
                </div>

                <div class="rules-info">
                    <h3><i class="fas fa-info-circle me-2" aria-hidden="true"></i>Good to know</h3>
                    <p>Book from tomorrow onward. One reservation per exact time slot, with up to <?php echo RESTAURANT_MAX_CAPACITY; ?> guests per hour.</p>
                    <p>A <strong>50% deposit on your cart items</strong> is required. <a href="menu.php">Add items from the menu</a> or <a href="cart.php">review your cart</a>, then book your table to see the GCash QR code.</p>
                    <div class="booking-facts">
                        <div>
                            <i class="far fa-clock me-1"></i> <strong>Operating Hours:</strong> <?php echo RESTAURANT_OPEN_HOUR; ?>:00 AM - <?php echo (RESTAURANT_CLOSE_HOUR > 12 ? RESTAURANT_CLOSE_HOUR - 12 . ":00 PM" : RESTAURANT_CLOSE_HOUR . ":00 AM"); ?>
                        </div>
                        <div>
                            <i class="fas fa-users me-1"></i> <strong>Guest Limits:</strong> <?php echo RESERVATION_MIN_PARTY; ?> to <?php echo RESERVATION_MAX_PARTY; ?> guests per table
                        </div>
                    </div>
                </div>

                <form method="POST" class="booking-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="make_reservation">
                    <div class="booking-grid">
                        <div class="booking-field">
                            <label for="reservation_date" class="form-label"><i class="far fa-calendar-alt me-2"></i>Date</label>
                            <input type="date" id="reservation_date" name="reservation_date" class="form-control"
                                   min="<?php echo htmlspecialchars($reservation_min_input); ?>"
                                   value="<?php echo htmlspecialchars($form_date); ?>" required>
                        </div>
                        <div class="booking-field">
                            <label for="reservation_time" class="form-label"><i class="far fa-clock me-2"></i>Time</label>
                            <input type="time" id="reservation_time" name="reservation_time" class="form-control"
                                   min="<?php echo htmlspecialchars($reservation_min_time); ?>"
                                   max="<?php echo htmlspecialchars($reservation_max_time); ?>"
                                   step="60" value="<?php echo htmlspecialchars($form_time); ?>" required>
                        </div>
                        <div class="booking-field">
                            <label for="party_size" class="form-label"><i class="fas fa-users me-2" aria-hidden="true"></i>Party Size</label>
                            <input type="number" id="party_size" name="party_size" class="form-control"
                                   min="<?php echo RESERVATION_MIN_PARTY; ?>" 
                                   max="<?php echo RESERVATION_MAX_PARTY; ?>" 
                                   value="<?php echo htmlspecialchars($form_party); ?>" required>
                        </div>
                        <div class="booking-field booking-requests">
                            <label for="special_requests" class="form-label"><i class="far fa-comment me-2" aria-hidden="true"></i>Special Requests <span class="field-optional">(optional)</span></label>
                            <input type="text" id="special_requests" name="special_requests" class="form-control"
                                   value="<?php echo htmlspecialchars($form_notes); ?>" maxlength="4000"
                                   placeholder="e.g., dietary restrictions">
                        </div>
                        <div class="booking-submit">
                            <button type="submit" class="book-table-btn">Book a Table <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
                        </div>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <?php if (!empty($reservations)): ?>
                <div class="reservation-history-heading">
                    <h2 class="section-title">Reservation History</h2>
                    <form method="POST" onsubmit="return confirm('Clear past, completed, or cancelled reservations from your history? Upcoming active reservations will be kept. This cannot be undone.');">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="clear_reservation_history">
                        <button type="submit" class="btn btn-outline-danger clear-history-btn" <?php echo $clearableHistoryCount === 0 ? 'disabled title="No past, completed, or cancelled reservations to clear"' : ''; ?>>
                            <i class="fas fa-trash-alt me-2" aria-hidden="true"></i>Clear History
                        </button>
                    </form>
                </div>
                
                <div class="reservations-table table-responsive">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Date & Time</th>
                                <th>Guests</th>
                                <th>Special Requests</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $r): 
                                $res_ts = strtotime($r['reservation_date']);
                                $db_st = $r['status'] ?? 'PENDING';
                                $st_cls = match($db_st) {
                                    'CONFIRMED' => 'status-confirmed',
                                    'COMPLETED' => 'bg-secondary text-white',
                                    'CANCELLED' => 'bg-danger text-white',
                                    default => 'status-pending'
                                };
                            ?>
                                <tr>
                                    <td><strong><?php echo date('M d, Y', $res_ts); ?></strong><br><small class="text-muted"><?php echo date('h:i A', $res_ts); ?></small></td>
                                    <td><span class="badge bg-light text-dark p-2 border"><i class="fas fa-user me-1"></i> <?php echo htmlspecialchars($r['party_size']); ?> </span></td>
                                    <td><?php echo (!empty($r['special_requests'])) ? htmlspecialchars($r['special_requests']) : '<span class="text-muted fst-italic">None</span>'; ?></td>
                                    <td><span class="status-badge <?php echo $st_cls; ?>"><?php echo htmlspecialchars($db_st); ?></span></td>
                                    <td>
                                        <?php if (in_array($db_st, ['PENDING', 'CONFIRMED'])): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to cancel?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="action" value="cancel_reservation">
                                                <input type="hidden" name="reservation_id" value="<?php echo $r['reservation_id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mobile-reservation-cards">
                    <?php foreach ($reservations as $r): 
                        $res_ts = strtotime($r['reservation_date']);
                        $db_st = $r['status'] ?? 'PENDING';
                    ?>
                        <div class="mobile-res-card">
                            <div class="mobile-res-heading">
                                <div>
                                    <strong><?php echo date('M d, Y', $res_ts); ?></strong><br>
                                    <small><?php echo date('h:i A', $res_ts); ?> - <?php echo $r['party_size']; ?> guests</small>
                                </div>
                                <span class="status-badge <?php echo match($db_st) {
                                    'CONFIRMED' => 'status-confirmed',
                                    'COMPLETED' => 'bg-secondary text-white',
                                    'CANCELLED' => 'bg-danger text-white',
                                    default => 'status-pending'
                                }; ?>"><?php echo htmlspecialchars($db_st); ?></span>
                            </div>
                            <?php if (!empty($r['special_requests'])): ?>
                                <p class="mobile-res-notes"><?php echo htmlspecialchars($r['special_requests']); ?></p>
                            <?php endif; ?>
                            <?php if (in_array($db_st, ['PENDING', 'CONFIRMED'])): ?>
                                <form method="POST" class="mt-2">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                    <input type="hidden" name="action" value="cancel_reservation">
                                    <input type="hidden" name="reservation_id" value="<?php echo $r['reservation_id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger w-100">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php echo render_pagination($reservationsPagination); ?>
            <?php else: ?>
                <div class="reservation-empty-state">
                    <span class="empty-state-icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18M8 15h2M14 15h2"/></svg>
                    </span>
                    <div>
                        <h2>No reservations yet</h2>
                        <p>Your next good meal starts here. Book a table above to plan your visit.</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
