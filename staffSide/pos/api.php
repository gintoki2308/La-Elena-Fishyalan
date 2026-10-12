<?php
require __DIR__ . '/access.php';
header('Content-Type: application/json; charset=utf-8');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $action = $_GET['action'] ?? 'catalog';
        if ($action === 'catalog') {
            $result = ['items' => posCatalog($db), 'tax_basis_points' => posTaxBasisPoints()];
        } elseif ($action === 'receipt') {
            $result = posReceipt($db, (int)($_GET['id'] ?? 0), $staffId);
        } elseif ($action === 'history') {
            $day = $_GET['date'] ?? date('Y-m-d');
            if (!is_string($day) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) || date('Y-m-d', strtotime($day)) !== $day) {
                throw new InvalidArgumentException('Choose a valid date.');
            }
            $page = max(1, (int)($_GET['page'] ?? 1));
            $start = $day . ' 00:00:00';
            $end = date('Y-m-d', strtotime($day . ' +1 day')) . ' 00:00:00';
            $where = 'staff_id = ? AND sold_at >= ? AND sold_at < ?';
            $stmt = $db->prepare("SELECT COUNT(*) AS sales_count, COALESCE(SUM(IF(status = 'COMPLETED',total_amount,0)),0) AS net_sales, COALESCE(SUM(IF(status = 'REFUNDED',total_amount,0)),0) AS refunds, COALESCE(SUM(IF(status = 'COMPLETED' AND payment_method = 'CASH',total_amount,0)),0) AS cash_sales FROM pos_sales WHERE $where");
            $stmt->execute([$staffId, $start, $end]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            $page = min($page, max(1, (int)ceil($summary['sales_count'] / 30)));
            $offset = ($page - 1) * 30;
            $stmt = $db->prepare("SELECT sale_id, receipt_number, customer_name, sold_at, total_amount, payment_method, status FROM pos_sales WHERE $where ORDER BY sale_id DESC LIMIT 30 OFFSET $offset");
            $stmt->execute([$staffId, $start, $end]);
            $result = ['sales' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'summary' => $summary, 'page' => $page, 'pages' => max(1, (int)ceil($summary['sales_count'] / 30))];
        } else {
            throw new InvalidArgumentException('Unknown POS action.');
        }
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents('php://input', false, null, 0, 65537);
        if (strlen($raw) > 65536) throw new InvalidArgumentException('Request is too large.');
        $data = json_decode($raw, true);
        if (!is_array($data)) throw new InvalidArgumentException('Invalid request.');
        posValidateCsrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
        if (($data['action'] ?? '') === 'checkout') {
            $result = posCheckout($db, $staffId, $data);
        } elseif (($data['action'] ?? '') === 'refund') {
            if (($data['refund_confirmed'] ?? false) !== true) throw new InvalidArgumentException('Confirm that the payment has been returned to the customer.');
            $result = posRefund($db, $staffId, (int)($data['sale_id'] ?? 0), posText($data, 'reason', 200), ($data['restock'] ?? false) === true);
        } else {
            throw new InvalidArgumentException('Unknown POS action.');
        }
    } else {
        header('Allow: GET, POST');
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed.']);
        exit;
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
} catch (InvalidArgumentException $error) {
    http_response_code(422);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    error_log('POS: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Unable to finish this request. Retry with the same cart or check sales history before starting another sale.']);
}
