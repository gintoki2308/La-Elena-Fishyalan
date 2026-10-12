<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/Database.php';
require_once __DIR__ . '/../../config/pagination_helper.php';
require_once __DIR__ . '/../../config/reservation_management_helper.php';

if (!isLoggedIn() || (!isAdmin() && !isStaff())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$success = $_SESSION['reservation_success'] ?? '';
$error = $_SESSION['reservation_error'] ?? '';
unset($_SESSION['reservation_success'], $_SESSION['reservation_error']);
$csrfToken = reservationCsrfToken();
$filters = reservationListFilters($_GET);
$data = null;
try {
    $db = (new Database())->connect();
    if (!$db) {
        throw new RuntimeException('Database unavailable.');
    }
    $data = loadReservationManagement($db, $filters);
    $filters['page'] = $data['pagination']['current_page'];
} catch (Throwable $exception) {
    $error = reservationError($exception);
}
$e = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$dashboard = app_url(isAdmin() ? 'adminSide/admin-dashboard.php' : 'staffSide/staff-dashboard.php');
$filtered = $filters['q'] !== '' || $filters['status'] !== '' || $filters['date'] !== '';
$today = date('Y-m-d');
$now = new DateTimeImmutable();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Reservations - La Elena Fishyalan</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="reservations.css?v=<?= filemtime(__DIR__ . '/reservations.css') ?>">
    <script src="reservations.js?v=<?= filemtime(__DIR__ . '/reservations.js') ?>" defer></script>
</head>

<body class="reservation-page">
    <a class="reservation-skip" href="#main-content">Skip to reservations</a>
    <nav class="reservation-topbar" aria-label="Page navigation">
        <a class="reservation-brand" href="<?= $e($dashboard) ?>"><span class="brand-symbol" aria-hidden="true"><i class="fas fa-utensils"></i></span><span>La Elena<span class="brand-subtitle">FISHYALAN</span></span></a>
        <a class="reservation-back" href="<?= $e($dashboard) ?>"><i class="fas fa-arrow-left" aria-hidden="true"></i>Back to dashboard</a>
    </nav>
    <main class="reservation-main" id="main-content">
        <header class="reservation-heading">
            <div class="reservation-heading-copy">
                <p class="reservation-eyebrow">TABLE &amp; GUEST MANAGEMENT</p>
                <h1>Reservations<span class="heading-dot">.</span></h1>
                <p class="reservation-subtitle">A clear view of every booking, from the first hello to the last course.</p>
            </div>
            <div class="reservation-heading-actions">
                <span class="reservation-current-date"><i class="far fa-calendar-alt" aria-hidden="true"></i><?= $e($now->format('l, F j, Y')) ?></span>
                <a class="reservation-button today-button" href="viewReservations.php?date=<?= $e($today) ?>"><i class="far fa-calendar" aria-hidden="true"></i>Today&rsquo;s reservations<i class="fas fa-arrow-right" aria-hidden="true"></i></a>
            </div>
        </header>

        <?php if ($success): ?><div class="reservation-notice success" role="status"><i class="fas fa-check-circle" aria-hidden="true"></i><span><?= $e($success) ?></span></div><?php endif; ?>
        <?php if ($error): ?><div class="reservation-notice error" role="alert"><i class="fas fa-exclamation-circle" aria-hidden="true"></i><span><?= $e($error) ?></span></div><?php endif; ?>

        <section class="reservation-stats" aria-label="Reservation overview">
            <?php foreach (
                [
                    ['today', "Today's reservations", 'Pending & confirmed', 'fa-calendar-day', 'accent'],
                    ['pending', 'Awaiting confirmation', 'All pending reservations', 'fa-hourglass-half', 'amber'],
                    ['confirmed', 'Upcoming confirmed', 'Ready to welcome', 'fa-calendar-check', 'green'],
                    ['guests', 'Expected guests today', 'Pending & confirmed', 'fa-users', 'coral'],
                ] as [$key, $label, $description, $icon, $color]
            ): ?>
                <div class="reservation-stat <?= $key === 'today' ? 'stat-featured' : '' ?>"><span class="stat-icon <?= $color ?>" aria-hidden="true"><i class="fas <?= $icon ?>"></i></span>
                    <div>
                        <p><?= $e($label) ?></p><strong><?= $data !== null ? number_format($data['summary'][$key]) : '&mdash;' ?></strong><span><?= $e($description) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="reservation-panel" aria-labelledby="reservation-list-title">
            <div class="reservation-panel-heading">
                <div>
                    <h2 id="reservation-list-title">Reservation list <span class="reservation-count"><?= $data !== null ? number_format($data['summary']['total']) : '&mdash;' ?></span></h2>
                    <p>Manage bookings and keep your guests up to date.</p>
                </div><span class="reservation-timezone"><i class="far fa-clock" aria-hidden="true"></i> Restaurant time &middot; <?= $e(date('T')) ?></span>
            </div>
            <form method="get" action="viewReservations.php" class="reservation-filters" role="search" aria-label="Filter reservations">
                <div class="reservation-search"><label for="reservation-search">Search reservations</label>
                    <div class="search-input"><i class="fas fa-search" aria-hidden="true"></i><input id="reservation-search" type="search" name="q" maxlength="100" placeholder="Customer, phone or reservation #" value="<?= $e($filters['q']) ?>"></div>
                </div>
                <div><label for="reservation-status">Status</label><select id="reservation-status" name="status">
                        <option value="">All statuses</option><?php foreach (RESERVATION_STATUS_LABELS as $value => $label): ?><option value="<?= $value ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?>
                    </select></div>
                <div><label for="reservation-date">Reservation date</label><input id="reservation-date" type="date" name="date" value="<?= $e($filters['date']) ?>"></div>
                <div class="reservation-filter-actions">
                    <button type="submit" class="reservation-button primary"><i class="fas fa-sliders" aria-hidden="true"></i>Apply filters</button>
                    <?php if ($filtered): ?><a href="viewReservations.php" class="reservation-reset">Clear</a><?php endif; ?>
                </div>
                <div class="reservation-results">
                    <p aria-live="polite"><?php if ($data !== null): ?><?= number_format($data['pagination']['total_items']) ?> <?= $filtered ? 'matching' : 'total' ?> reservation<?= $data['pagination']['total_items'] === 1 ? '' : 's' ?><?php else: ?>Reservations unavailable<?php endif; ?></p>
                    <div><label for="reservation-sort">Sort by</label><select id="reservation-sort" name="sort">
                            <option value="schedule" <?= $filters['sort'] === 'schedule' ? 'selected' : '' ?>>Upcoming first</option>
                            <option value="newest" <?= $filters['sort'] === 'newest' ? 'selected' : '' ?>>Newest bookings</option>
                            <option value="oldest" <?= $filters['sort'] === 'oldest' ? 'selected' : '' ?>>Date: oldest first</option>
                        </select><noscript><button type="submit">Sort</button></noscript></div>
                </div>
            </form>

            <?php if ($data === null): ?>
                <div class="reservation-empty"><span><i class="fas fa-exclamation-circle" aria-hidden="true"></i></span>
                    <h3>Reservations couldn&rsquo;t be loaded</h3>
                    <p>Please try again in a moment.</p><a class="reservation-button secondary" href="viewReservations.php?<?= $e(reservationListQuery($filters)) ?>">Try again</a>
                </div>
            <?php elseif (!$data['items']): ?>
                <div class="reservation-empty"><div class="empty-calendar" aria-hidden="true"><i class="far fa-calendar-check"></i><span><i class="fas fa-utensils"></i></span></div>
                    <h3><?= $filtered ? 'No matching reservations' : 'No reservations yet' ?></h3>
                    <p><?= $filtered ? 'Try a different search, status or date to find a booking.' : 'New bookings will appear here. You can review guest details and confirm their visit in one place.' ?></p><?php if ($filtered): ?><a class="reservation-button secondary" href="viewReservations.php">Clear filters</a><?php endif; ?>
                </div>
            <?php else: ?>
                <div class="reservation-table-wrap">
                    <table class="reservation-table">
                        <caption class="visually-hidden">Customer reservations and status management</caption>
                        <thead>
                            <tr>
                                <th scope="col">Reservation / Customer</th>
                                <th scope="col">Date &amp; time</th>
                                <th scope="col">Party</th>
                                <th scope="col">Special requests</th>
                                <th scope="col">Status</th>
                                <th scope="col">Update status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($data['items'] as $reservation):
                                $id = (int)$reservation['reservation_id'];
                                $status = $reservation['status'];
                                $date = new DateTimeImmutable($reservation['reservation_date']);
                                $name = trim(($reservation['first_name'] ?? '') . ' ' . ($reservation['last_name'] ?? '')) ?: 'Unknown customer';
                                $past = $date <= $now;
                            ?>
                                <tr>
                                    <td class="reservation-customer"><span class="reservation-id">#<?= $id ?></span><strong><?= $e($name) ?></strong><span class="reservation-phone"><?= $e($reservation['phone'] ?: 'No phone provided') ?></span></td>
                                    <td data-label="Date &amp; time"><strong><?= $e($date->format('M j, Y')) ?></strong><span><?= $e($date->format('g:i A')) ?><?php if ($date->format('Y-m-d') === $today): ?> <span class="today-tag">Today</span><?php endif; ?></span><?php if ($past && in_array($status, ['PENDING', 'CONFIRMED'], true)): ?><span class="reservation-overdue">Past schedule &middot; review status</span><?php endif; ?></td>
                                    <td data-label="Party"><span class="reservation-party"><i class="fas fa-user-group" aria-hidden="true"></i><?= (int)$reservation['party_size'] ?> <?= (int)$reservation['party_size'] === 1 ? 'guest' : 'guests' ?></span></td>
                                    <td data-label="Special requests" class="reservation-notes"><?php if (trim($reservation['special_requests'] ?? '') !== ''): ?><details>
                                                <summary>View requests</summary>
                                                <p><?= $e($reservation['special_requests']) ?></p>
                                            </details><?php else: ?><span>No special requests</span><?php endif; ?></td>
                                    <td data-label="Status"><span class="reservation-status <?= strtolower($e($status)) ?>"><?= $e(RESERVATION_STATUS_LABELS[$status] ?? $status) ?></span></td>
                                    <td class="reservation-actions" data-label="Update status">
                                        <form action="manageReservation.php" method="post" class="reservation-update" data-reservation-id="<?= $id ?>">
                                            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                                            <input type="hidden" name="reservation_id" value="<?= $id ?>">
                                            <input type="hidden" name="expected_status" value="<?= $e($status) ?>">
                                            <?php foreach ($filters as $key => $value): ?><input type="hidden" name="<?= $key ?>" value="<?= $e($value) ?>"><?php endforeach; ?>
                                            <label class="visually-hidden" for="status-<?= $id ?>">New status for reservation #<?= $id ?></label>
                                            <select id="status-<?= $id ?>" name="new_status" required>
                                                <?php foreach (RESERVATION_STATUS_LABELS as $value => $label): ?><option value="<?= $value ?>" <?= $status === $value ? 'selected' : '' ?> <?= $past && $value !== $status && in_array($value, ['PENDING', 'CONFIRMED'], true) ? 'disabled' : '' ?>><?= $label ?></option><?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="reservation-button primary" aria-label="Save status for reservation #<?= $id ?>">Save</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="reservation-pagination"><?php if ($data['pagination']['total_pages'] > 1): ?><?= render_pagination($data['pagination'], 'page', array_diff_key($filters, ['page' => true]), 'viewReservations.php') ?><?php else: ?><span>Showing <?= $data['pagination']['start_item'] ?>&ndash;<?= $data['pagination']['end_item'] ?> of <?= $data['pagination']['total_items'] ?></span><?php endif; ?></div>
            <?php endif; ?>
        </section>
        <p class="reservation-help"><i class="far fa-envelope" aria-hidden="true"></i>Customers are emailed when a reservation is confirmed, completed or cancelled.</p>
    </main>
</body>

</html>
