<?php
function getPendingReservationSummary(PDO $db, int $itemId, int $memberId): array {
    $stmt = $db->prepare("
        SELECT
            COALESCE(SUM(CASE WHEN oo.member_id = :mid_own THEN oi.quantity ELSE 0 END), 0) AS own_pending,
            COALESCE(SUM(CASE WHEN oo.member_id <> :mid_other OR oo.member_id IS NULL THEN oi.quantity ELSE 0 END), 0) AS other_pending
        FROM online_order_items oi
        INNER JOIN online_orders oo ON oo.order_id = oi.order_id
        WHERE oi.item_id = :iid
          AND UPPER(oo.status) = 'PENDING'
    ");
    $stmt->execute([':mid_own' => $memberId, ':mid_other' => $memberId, ':iid' => $itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $ownPending = max(0, (int)($row['own_pending'] ?? 0));
    $otherPending = max(0, (int)($row['other_pending'] ?? 0));

    return [
        'own_pending' => $ownPending,
        'other_pending' => $otherPending,
        'total_pending' => $ownPending + $otherPending,
    ];
}

function getOrderableStock(PDO $db, int $itemId, int $memberId, ?int $physicalStock = null): array {
    if ($physicalStock === null) {
        $stockStmt = $db->prepare("SELECT stock_quantity FROM menu_items WHERE item_id = :iid LIMIT 1");
        $stockStmt->execute([':iid' => $itemId]);
        $physicalStock = (int)$stockStmt->fetchColumn();
    }

    $physicalStock = max(0, $physicalStock);
    $pending = getPendingReservationSummary($db, $itemId, $memberId);
    $available = max(0, $physicalStock - $pending['total_pending']);

    return array_merge($pending, [
        'physical_stock' => $physicalStock,
        'available' => $available,
    ]);
}

function stockReservationMessage(string $itemName, array $availability): string {
    $physicalStock = (int)($availability['physical_stock'] ?? 0);
    $available = (int)($availability['available'] ?? 0);
    $ownPending = (int)($availability['own_pending'] ?? 0);

    if ($available <= 0 && $ownPending > 0 && $ownPending >= $physicalStock) {
        return "You already have a pending order that reserves all current stock of {$itemName} ({$ownPending} units). Please wait until that order is processed before ordering more.";
    }

    if ($available <= 0) {
        return "Insufficient stock available due to existing pending orders. No units are currently left for {$itemName}.";
    }

    if ($ownPending > 0) {
        return "You already have a pending order reserving {$ownPending} unit(s) of {$itemName}. Only {$available} unit(s) are currently available to order.";
    }

    return "Insufficient stock available due to existing pending orders. Only {$available} unit(s) left for {$itemName}.";
}
?>
