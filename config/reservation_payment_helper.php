<?php
require_once __DIR__ . '/reservation_helper.php';

// Prices and quantities are captured on the server before displaying the QR code.
function prepareReservationPayment(PDO $db, int $memberId, string $input, $partyInput, string $notes, array $cart): array {
    $date = validateReservationDate($input);
    $party = filter_var($partyInput, FILTER_VALIDATE_INT);
    if ($party === false) {
        throw new InvalidArgumentException('Please enter a whole number of guests.');
    }
    checkReservationAvailability($db, $date, $party);
    if (strlen($notes) > 4000) {
        throw new InvalidArgumentException('Please keep special requests under 4,000 characters.');
    }
    if (!$cart) {
        throw new InvalidArgumentException('Add your reservation items from the menu to your cart first. Your deposit is 50% of their total price.');
    }
    require_once __DIR__ . '/../customerSide/stock_availability.php';
    $query = $db->prepare('SELECT item_id, item_name, price, stock_quantity FROM menu_items WHERE item_id = :id AND is_available = 1');
    $items = [];
    $total = 0;
    foreach ($cart as $id => $quantity) {
        $quantity = filter_var($quantity, FILTER_VALIDATE_INT);
        if (!$quantity || $quantity < 1 || $quantity > 10000) {
            throw new InvalidArgumentException('Please review the item quantities in your cart.');
        }
        $query->execute([':id' => $id]);
        $item = $query->fetch(PDO::FETCH_ASSOC);
        if (!$item) {
            throw new InvalidArgumentException('An item is no longer available. Please review your cart.');
        }
        $stock = getOrderableStock($db, (int)$id, $memberId, (int)$item['stock_quantity']);
        if ($quantity > $stock['available']) {
            throw new InvalidArgumentException('There is not enough stock for ' . $item['item_name'] . '. Please review your cart.');
        }
        $price = (int)round((float)$item['price'] * 100);
        if ($price < 0) {
            throw new InvalidArgumentException('An item price is unavailable. Please contact the restaurant.');
        }
        $items[] = ['id' => (int)$id, 'name' => $item['item_name'], 'quantity' => $quantity, 'price' => $price, 'subtotal' => $price * $quantity];
        $total += $price * $quantity;
    }
    if ($total <= 0) {
        throw new InvalidArgumentException('Please select items with a payable total.');
    }
    return ['token' => bin2hex(random_bytes(32)), 'member_id' => $memberId,
        'date' => $input, 'party' => $party, 'notes' => $notes, 'items' => $items,
        'total' => $total, 'deposit' => (int)ceil($total / 2)];
}

function submitReservationPayment(PDO $db, int $memberId, $token, $reference, $confirmed): int {
    $draft = $_SESSION['reservation_payment'] ?? null;
    if (!$draft || $draft['member_id'] !== $memberId || !is_string($token) || !hash_equals($draft['token'], $token)) {
        throw new InvalidArgumentException('This payment form is no longer active. Please return to My Reservations.');
    }
    if ($confirmed !== '1') {
        throw new InvalidArgumentException('Please confirm that you have sent the displayed deposit.');
    }
    if (!is_string($reference) || !preg_match('/\A[0-9]{10,20}\z/', trim($reference))) {
        throw new InvalidArgumentException('Enter the 10 to 20 digit reference number from your payment receipt.');
    }
    // Keep the item/price snapshot and customer-reported payment visible to staff
    // in the existing reservation details, without claiming provider verification.
    $lines = [$draft['notes'], '--- Reservation items / GCash deposit ---'];
    foreach ($draft['items'] as $item) {
        $lines[] = $item['quantity'] . ' x ' . $item['name'] . ' @ PHP ' . number_format($item['price'] / 100, 2, '.', '') . ' = PHP ' . number_format($item['subtotal'] / 100, 2, '.', '');
    }
    $lines[] = 'Items total: PHP ' . number_format($draft['total'] / 100, 2, '.', '');
    $lines[] = '50% deposit reported: PHP ' . number_format($draft['deposit'] / 100, 2, '.', '');
    $lines[] = 'Remaining balance: PHP ' . number_format(($draft['total'] - $draft['deposit']) / 100, 2, '.', '');
    $lines[] = 'GCash reference: ' . trim($reference) . ' (customer submitted; payment verification pending).';
    $id = createReservation($db, $memberId, $draft['date'], $draft['party'], implode("\n", array_filter($lines, fn($line) => $line !== '')));
    unset($_SESSION['reservation_payment']);
    // Remove only the quantities attached to this reservation, preserving later additions.
    foreach ($draft['items'] as $item) {
        if (isset($_SESSION['cart'][$item['id']])) {
            $remaining = (int)$_SESSION['cart'][$item['id']] - $item['quantity'];
            if ($remaining > 0) $_SESSION['cart'][$item['id']] = $remaining;
            else unset($_SESSION['cart'][$item['id']]);
        }
    }
    return $id;
}
