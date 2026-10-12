<?php
require_once __DIR__ . '/recipe_inventory_helper.php';
// Match the existing checkout's 10% added tax by default; configurable in basis points.
function posTaxBasisPoints(): int {
    $value = getEnvValue(['POS_TAX_BASIS_POINTS'], '1000');
    if (!ctype_digit((string)$value) || (int)$value > 10000) {
        throw new RuntimeException('Invalid POS tax configuration.');
    }
    return (int)$value;
}

function posCashier(PDO $db, int $staffId): ?array {
    $stmt = $db->prepare("SELECT staff_id, first_name, last_name FROM staffs WHERE staff_id = ? AND role = 'Cashier' AND account_status = 'ACTIVE'");
    $stmt->execute([$staffId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function posCsrfToken(): string {
    return $_SESSION['pos_csrf'] ?? ($_SESSION['pos_csrf'] = bin2hex(random_bytes(32)));
}

function posValidateCsrf($token): void {
    if (!is_string($token) || !hash_equals(posCsrfToken(), $token)) {
        throw new InvalidArgumentException('Your session token expired. Reload this page before trying again.');
    }
}

function posMoney($value): int {
    if ((!is_string($value) && !is_int($value)) || !preg_match('/^\d{1,8}(?:\.\d{1,2})?$/D', (string)$value)) {
        throw new InvalidArgumentException('Enter a valid amount with at most two decimal places.');
    }
    $parts = explode('.', (string)$value);
    return ((int)$parts[0] * 100) + (int)str_pad($parts[1] ?? '', 2, '0');
}

function posDecimal(int $cents): string {
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

function posText(array $data, string $key, int $max, string $default = ''): string {
    $value = $data[$key] ?? $default;
    if (!is_string($value) || strlen(trim($value)) > $max) {
        throw new InvalidArgumentException('Invalid ' . str_replace('_', ' ', $key) . '.');
    }
    return trim($value);
}

function posCatalog(PDO $db): array {
    return $db->query("SELECT m.item_id, m.item_name, m.category, m.price, m.image_url,
        IF(m.is_available = 1, GREATEST(0, COALESCE(m.stock_quantity,0) - COALESCE(p.reserved,0)), 0) AS available
        FROM menu_items m LEFT JOIN (
            SELECT oi.item_id, SUM(oi.quantity) AS reserved FROM online_order_items oi
            JOIN online_orders o ON o.order_id = oi.order_id WHERE o.status = 'PENDING'
            GROUP BY oi.item_id
        ) p ON p.item_id = m.item_id ORDER BY m.category, m.item_name")->fetchAll(PDO::FETCH_ASSOC);
}

function posReceipt(PDO $db, int $id, int $staffId): array {
    $stmt = $db->prepare('SELECT * FROM pos_sales WHERE sale_id = ? AND staff_id = ?');
    $stmt->execute([$id, $staffId]);
    $sale = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sale) throw new InvalidArgumentException('Sale not found for this cashier.');
    $stmt = $db->prepare('SELECT item_id, item_name, quantity, unit_price, line_total FROM pos_sale_items WHERE sale_id = ? ORDER BY sale_item_id');
    $stmt->execute([$id]);
    $sale['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return $sale;
}

function posAudit(PDO $db, int $staffId, int $saleId, string $action): void {
    $stmt = $db->prepare("INSERT INTO audit_logs (staff_id, action, table_affected, record_id, ip_address, timestamp) VALUES (?, ?, 'pos_sales', ?, ?, NOW())");
    $stmt->execute([$staffId, $action, $saleId, $_SERVER['REMOTE_ADDR'] ?? '']);
}

function posCheckout(PDO $db, int $staffId, array $data): array {
    $cashier = posCashier($db, $staffId);
    if (!$cashier) throw new InvalidArgumentException('Only active cashiers can complete sales.');
    $key = posText($data, 'request_key', 32);
    if (!preg_match('/^[a-f0-9]{32}$/D', $key)) throw new InvalidArgumentException('Invalid checkout reference.');
    $cart = $data['items'] ?? null;
    if (!is_array($cart) || !$cart || count($cart) > 100) throw new InvalidArgumentException('Add between 1 and 100 different menu items.');
    $quantities = [];
    $expectedPrices = [];
    foreach ($cart as $line) {
        if (!is_array($line)) throw new InvalidArgumentException('Invalid cart item.');
        $id = filter_var($line['item_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $qty = filter_var($line['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 999]]);
        if (!$id || !$qty || isset($quantities[$id])) throw new InvalidArgumentException('Invalid or duplicate item quantity.');
        $quantities[$id] = $qty;
        $expectedPrices[$id] = posMoney($line['unit_price'] ?? '');
    }
    ksort($quantities); // All tills lock stock in a consistent order.
    $service = posText($data, 'service_type', 20);
    $method = posText($data, 'payment_method', 10);
    if (!in_array($service, ['DINE_IN','TAKEAWAY'], true) || !in_array($method, ['CASH','GCASH','CARD'], true)) {
        throw new InvalidArgumentException('Choose a valid order type and payment method.');
    }
    $customer = posText($data, 'customer_name', 100) ?: 'Walk-in';
    $table = $service === 'DINE_IN' ? posText($data, 'table_number', 30) : '';
    $notes = posText($data, 'notes', 500);
    $discount = posMoney($data['discount_amount'] ?? '0');
    $reason = posText($data, 'discount_reason', 200);
    if ($discount > 0 && $reason === '') throw new InvalidArgumentException('Enter a reason for the discount.');
    $reference = posText($data, 'payment_reference', 100);
    if ($method !== 'CASH' && ($reference === '' || ($data['payment_confirmed'] ?? false) !== true)) {
        throw new InvalidArgumentException('Verify payment on your terminal or GCash account and enter its reference.');
    }
    $tendered = $method === 'CASH' ? posMoney($data['amount_tendered'] ?? '') : 0;
    $hash = hash('sha256', json_encode([$cart, $service, $method, $customer, $table, $notes, $discount, $reason, $reference, $tendered]));
    try {
        $db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        $db->beginTransaction();
        // Serializes retry requests for the same cashier, including across browser tabs.
        $lock = $db->prepare("SELECT staff_id FROM staffs WHERE staff_id = ? AND role = 'Cashier' AND account_status = 'ACTIVE' FOR UPDATE");
        $lock->execute([$staffId]);
        if (!$lock->fetchColumn()) throw new InvalidArgumentException('Cashier access is no longer active.');
        $existing = $db->prepare('SELECT sale_id, staff_id, request_hash FROM pos_sales WHERE request_key = ?');
        $existing->execute([$key]);
        if ($sale = $existing->fetch(PDO::FETCH_ASSOC)) {
            if ((int)$sale['staff_id'] !== $staffId || !hash_equals($sale['request_hash'], $hash)) {
                throw new InvalidArgumentException('This checkout reference has already been used. Open the original receipt from history.');
            }
            $db->commit();
            return posReceipt($db, (int)$sale['sale_id'], $staffId);
        }
        $subtotal = 0;
        $items = [];
        $stock = $db->prepare('SELECT * FROM menu_items WHERE item_id = ? FOR UPDATE');
        $reserved = $db->prepare("SELECT COALESCE(SUM(oi.quantity),0) FROM online_order_items oi JOIN online_orders o ON o.order_id = oi.order_id WHERE oi.item_id = ? AND o.status = 'PENDING'");
        foreach ($quantities as $id => $qty) {
            $stock->execute([$id]);
            $item = $stock->fetch(PDO::FETCH_ASSOC);
            if (!$item || !(int)$item['is_available']) throw new InvalidArgumentException('An item is no longer available. Refresh the menu.');
            $reserved->execute([$id]);
            $available = max(0, (int)$item['stock_quantity'] - (int)$reserved->fetchColumn());
            if ($qty > $available) throw new InvalidArgumentException($item['item_name'] . ': only ' . $available . ' available. Refresh the menu.');
            $price = posMoney($item['price']);
            if ($expectedPrices[$id] !== $price) throw new InvalidArgumentException($item['item_name'] . ': the price changed. Refresh the menu and review the total.');
            $lineTotal = $price * $qty;
            $subtotal += $lineTotal;
            $items[] = [$id, $item['item_name'], $qty, posDecimal($price), posDecimal($lineTotal)];
        }
        if ($discount > $subtotal) throw new InvalidArgumentException('Discount cannot exceed the subtotal.');
        $taxRate = posTaxBasisPoints();
        $tax = intdiv(($subtotal - $discount) * $taxRate + 5000, 10000);
        $total = $subtotal - $discount + $tax;
        if ($total > 9999999999 || $subtotal > 9999999999) throw new InvalidArgumentException('Sale exceeds the supported amount.');
        if ($method !== 'CASH') $tendered = $total;
        if ($tendered < $total) throw new InvalidArgumentException('Cash received is less than the total due.');
        $insert = $db->prepare('INSERT INTO pos_sales (receipt_number, request_key, request_hash, staff_id, cashier_name, customer_name, service_type, table_number, notes, subtotal, discount_amount, discount_reason, tax_rate, tax_amount, total_amount, payment_method, payment_reference, amount_tendered, change_amount, sold_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $insert->execute(['POS-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(5))), $key, $hash, $staffId,
            trim($cashier['first_name'] . ' ' . $cashier['last_name']), $customer, $service, $table, $notes,
            posDecimal($subtotal), posDecimal($discount), $reason, posDecimal($taxRate), posDecimal($tax), posDecimal($total),
            $method, $method === 'CASH' ? null : $reference, posDecimal($tendered), posDecimal($tendered - $total), date('Y-m-d H:i:s')]);
        $saleId = (int)$db->lastInsertId();
        $insertItem = $db->prepare('INSERT INTO pos_sale_items (sale_id, item_id, item_name, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');
        // Set availability before assigning stock: MySQL evaluates assignments left to right.
        $deduct = $db->prepare('UPDATE menu_items SET is_available = IF(stock_quantity - ? <= 0, 0, is_available), stock_quantity = stock_quantity - ? WHERE item_id = ?');
        foreach ($items as $item) {
            $insertItem->execute(array_merge([$saleId], $item));
            $deduct->execute([$item[2], $item[2], $item[0]]);
        }
        deductRecipeInventory($db, 'POS', $saleId, $quantities, $staffId);
        posAudit($db, $staffId, $saleId, 'POS_SALE_COMPLETED');
        $db->commit();
        return posReceipt($db, $saleId, $staffId);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function posRefund(PDO $db, int $staffId, int $saleId, string $reason, bool $restock): array {
    if (!posCashier($db, $staffId)) throw new InvalidArgumentException('Only active cashiers can refund sales.');
    $reason = trim($reason);
    if ($reason === '' || strlen($reason) > 200) throw new InvalidArgumentException('Enter a refund reason (up to 200 characters).');
    try {
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT * FROM pos_sales WHERE sale_id = ? AND staff_id = ? FOR UPDATE');
        $stmt->execute([$saleId, $staffId]);
        $sale = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$sale) throw new InvalidArgumentException('Sale not found for this cashier.');
        if ($sale['status'] !== 'REFUNDED') {
            if ($restock) {
                $stmt = $db->prepare('SELECT item_id, quantity FROM pos_sale_items WHERE sale_id = ? ORDER BY item_id');
                $stmt->execute([$saleId]);
                $restore = $db->prepare('UPDATE menu_items SET is_available = IF(stock_quantity = 0, 1, is_available), stock_quantity = stock_quantity + ? WHERE item_id = ?');
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $item) {
                    if ($item['item_id'] !== null) $restore->execute([$item['quantity'], $item['item_id']]);
                }
                restoreRecipeInventory($db, 'POS', $saleId, $staffId);
            }
            $stmt = $db->prepare("UPDATE pos_sales SET status = 'REFUNDED', refunded_at = ?, refund_reason = ?, stock_restored = ? WHERE sale_id = ?");
            $stmt->execute([date('Y-m-d H:i:s'), $reason, (int)$restock, $saleId]);
            posAudit($db, $staffId, $saleId, 'POS_SALE_REFUNDED');
        }
        $db->commit();
        return posReceipt($db, $saleId, $staffId);
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
