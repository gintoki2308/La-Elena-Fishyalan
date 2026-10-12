<?php
require_once '../../config/config.php';
require_once '../../config/Database.php';
require_once __DIR__ . '/../../config/recipe_inventory_helper.php';

// only staff (and admin) can update orders
if (!isLoggedIn() || (!isStaff() && !isAdmin())) {
    header('Location: ' . app_url('auth/login.php'));
    exit;
}

$database = new Database();
$db = $database->connect();

function getOrderTransitions($status, $paymentMethod = 'COD') {
    switch ($status) {
        case 'PENDING':
            return ['CONFIRMED' => 'Confirm', 'CANCELLED' => 'Cancel'];
        case 'CONFIRMED':
            return ['PREPARING' => 'Preparing'];
        case 'PREPARING':
            return ['READY' => 'Mark Ready'];
        case 'READY':
            return ['OUT_FOR_DELIVERY' => 'Out for Delivery'];
        case 'OUT_FOR_DELIVERY':
            return ['DELIVERED' => 'Mark Delivered'];
        case 'DELIVERED':
            return ($paymentMethod === 'COD') ? ['COMPLETED' => 'Complete Order'] : [];
        default:
            return [];
    }
}

function ensureStockDeductedColumn(PDO $db) {
    $colStmt = $db->query("SHOW COLUMNS FROM online_orders LIKE 'stock_deducted'");
    if (!$colStmt->fetch(PDO::FETCH_ASSOC)) {
        $db->exec("ALTER TABLE online_orders ADD COLUMN stock_deducted TINYINT(1) DEFAULT 0 AFTER metrics_added");
    }

    $db->exec("UPDATE online_orders SET stock_deducted = 1 WHERE status IN ('DELIVERED', 'COMPLETED') AND COALESCE(stock_deducted, 0) = 0");
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: viewOnlineOrders.php');
    exit;
}

$action = $_POST['action'] ?? '';
$orderId = intval($_POST['order_id'] ?? 0);

try {
    if ($action === 'update_status' && $orderId > 0) {
        ensureStockDeductedColumn($db);
        $db->beginTransaction();

        $newStatus = $_POST['new_status'] ?? '';
        $allowed = ['PENDING','CONFIRMED','PREPARING','READY','OUT_FOR_DELIVERY','DELIVERED','COMPLETED','CANCELLED'];
        if (!in_array($newStatus, $allowed)) {
            throw new Exception('Invalid status value');
        }

        // FIXED: Online_Orders -> online_orders
        $checkStmt = $db->prepare("SELECT * FROM online_orders WHERE order_id = :id FOR UPDATE");
        $checkStmt->execute([':id' => $orderId]);
        $order = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception('Order not found');
        }

        $availableTransitions = getOrderTransitions($order['status'], $order['payment_method'] ?? 'COD');

        // Guard: prevent any modifications once an order is finalized
        $finalStatuses = ['DELIVERED', 'COMPLETED'];
        if (in_array($order['status'], $finalStatuses)) {
            $isAllowed = ($order['status'] === 'DELIVERED' && $newStatus === 'COMPLETED' && ($order['payment_method'] ?? 'COD') === 'COD');
            if (!$isAllowed) {
                throw new Exception('This order has been finalized and can no longer be modified.');
            }
        }

        if (!array_key_exists($newStatus, $availableTransitions)) {
            throw new Exception('This order can only move to the next step in the status flow.');
        }

        // Guard: COMPLETED status is only for COD
        if ($newStatus === 'COMPLETED' && ($order['payment_method'] ?? 'COD') !== 'COD') {
            throw new Exception('The COMPLETED status is only available for Cash on Delivery (COD) orders.');
        }

        $stockDeductStatuses = ['CONFIRMED', 'PREPARING', 'READY', 'OUT_FOR_DELIVERY', 'DELIVERED', 'COMPLETED'];
        $shouldDeductStock = in_array($newStatus, $stockDeductStatuses, true)
            && empty($order['stock_deducted']);

        if ($shouldDeductStock) {
            $itemsStmt = $db->prepare("SELECT item_id, SUM(quantity) AS quantity FROM online_order_items WHERE order_id = :oid GROUP BY item_id ORDER BY item_id");
            $itemsStmt->execute([':oid' => $orderId]);
            $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($orderItems)) {
                throw new Exception('This order has no items to deduct from stock.');
            }

            $stockStmt = $db->prepare("SELECT stock_quantity, item_name FROM menu_items WHERE item_id = :iid FOR UPDATE");
            $updateStock = $db->prepare("UPDATE menu_items SET is_available = IF(stock_quantity - :qty2 <= 0, 0, is_available), stock_quantity = stock_quantity - :qty1 WHERE item_id = :iid");

            foreach ($orderItems as $item) {
                $itemId = (int)($item['item_id'] ?? 0);
                $qty = (int)($item['quantity'] ?? 0);

                if ($itemId <= 0 || $qty <= 0) {
                    throw new Exception('This order contains an invalid item entry.');
                }

                $stockStmt->execute([':iid' => $itemId]);
                $stockData = $stockStmt->fetch(PDO::FETCH_ASSOC);

                if (!$stockData || (int)$stockData['stock_quantity'] < $qty) {
                    throw new Exception("Insufficient stock to update this order for item: " . ($stockData['item_name'] ?? 'Unknown Item'));
                }

                $updateStock->execute([':qty1' => $qty, ':qty2' => $qty, ':iid' => $itemId]);
            }
            deductRecipeInventory($db, 'ONLINE', $orderId, array_column($orderItems, 'quantity', 'item_id'), isStaff() ? (int)$_SESSION['user_id'] : null);
        }

        $paymentStatusUpdate = "";
        if ($newStatus === 'DELIVERED' || $newStatus === 'COMPLETED') {
            $paymentStatusUpdate = ", payment_status = 'PAID'";
        } elseif ($newStatus === 'CANCELLED') {
            $paymentStatusUpdate = ", payment_status = 'FAILED'";
        }

        // FIXED: Online_Orders -> online_orders
        $stockDeductedUpdate = $shouldDeductStock ? ", stock_deducted = 1" : "";
        $upd = $db->prepare("UPDATE online_orders SET status = :status{$paymentStatusUpdate}{$stockDeductedUpdate}, updated_at = NOW() WHERE order_id = :id");
        $upd->execute([':status' => $newStatus, ':id' => $orderId]);

        if ($newStatus === 'COMPLETED' && $order['status'] !== 'COMPLETED') {
            if ($order['payment_method'] === 'COD' && $order['metrics_added'] == 0) {
                // FIXED: Online_Orders -> online_orders
                $setMet = $db->prepare("UPDATE online_orders SET metrics_added = 1 WHERE order_id = :id");
                $setMet->execute([':id' => $orderId]);
            }
        } elseif ($newStatus === 'CANCELLED' && $order['status'] !== 'CANCELLED') {
            if (!empty($order['stock_deducted'])) {
                $itemsStmt = $db->prepare("SELECT item_id, quantity FROM online_order_items WHERE order_id = :oid");
                $itemsStmt->execute([':oid' => $orderId]);
                $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

                $restoreStock = $db->prepare("UPDATE menu_items SET stock_quantity = stock_quantity + :qty, is_available = 1 WHERE item_id = :iid");
                foreach ($orderItems as $item) {
                    $itemId = (int)($item['item_id'] ?? 0);
                    $qty = (int)($item['quantity'] ?? 0);
                    if ($itemId > 0 && $qty > 0) {
                        $restoreStock->execute([':qty' => $qty, ':iid' => $itemId]);
                    }
                }

                $clearStockFlag = $db->prepare("UPDATE online_orders SET stock_deducted = 0 WHERE order_id = :id");
                $clearStockFlag->execute([':id' => $orderId]);
            }

            restoreRecipeInventory($db, 'ONLINE', $orderId, isStaff() ? (int)$_SESSION['user_id'] : null);
            if ($order['metrics_added'] == 1) {
                // FIXED: Online_Orders -> online_orders
                $setMet = $db->prepare("UPDATE online_orders SET metrics_added = 0 WHERE order_id = :id");
                $setMet->execute([':id' => $orderId]);
            }
        }

        // audit the change
        // FIXED: Audit_Logs -> audit_logs AND 'Online_Orders' -> 'online_orders'
        $audit = $db->prepare("INSERT INTO audit_logs (staff_id, action, table_affected, record_id, ip_address, timestamp) VALUES (:staff_id, :action, 'online_orders', :rid, :ip, NOW())");
        $audit->execute([
            ':staff_id' => $_SESSION['user_id'],
            ':action' => 'UPDATE_ORDER_STATUS',
            ':rid' => $orderId,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ]);

        $db->commit();
        unset($_SESSION['order_update_error']);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    $_SESSION['order_update_error'] = $e->getMessage();
    error_log("Order update error: " . $e->getMessage());
}

// go back where we came from or to list
$referer = $_SERVER['HTTP_REFERER'] ?? 'viewOnlineOrders.php';
header('Location: ' . $referer);
exit;
?>
