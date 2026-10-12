<?php

require_once __DIR__ . '/reservation_helper.php';

const RESERVATION_STATUS_LABELS = [
    'PENDING' => 'Pending', 'CONFIRMED' => 'Confirmed',
    'COMPLETED' => 'Completed', 'CANCELLED' => 'Cancelled',
];

function reservationListFilters(array $input): array {
    $string = static fn(string $key): string => is_string($input[$key] ?? null) ? trim($input[$key]) : '';
    $status = strtoupper($string('status'));
    $date = $string('date');
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return [
        'q' => mb_substr($string('q'), 0, 100),
        'status' => isset(RESERVATION_STATUS_LABELS[$status]) ? $status : '',
        'date' => $parsed && $parsed->format('Y-m-d') === $date ? $date : '',
        'sort' => in_array($string('sort'), ['schedule', 'newest', 'oldest'], true) ? $string('sort') : 'schedule',
        'page' => max(1, (int)filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT)),
    ];
}

function reservationListQuery(array $filters): string {
    return http_build_query(array_filter($filters, static fn($value, $key) =>
        $value !== '' && !($key === 'page' && $value === 1) && !($key === 'sort' && $value === 'schedule'), ARRAY_FILTER_USE_BOTH));
}

function loadReservationManagement(PDO $db, array $filters): array {
    $now = new DateTimeImmutable();
    $today = $now->format('Y-m-d 00:00:00');
    $tomorrow = $now->modify('tomorrow')->format('Y-m-d 00:00:00');
    $summaryQuery = $db->prepare("SELECT COUNT(*) AS total,
        COALESCE(SUM(status = 'PENDING'), 0) AS pending,
        COALESCE(SUM(status = 'CONFIRMED' AND reservation_date >= :now), 0) AS confirmed,
        COALESCE(SUM(status IN ('PENDING', 'CONFIRMED') AND reservation_date >= :today AND reservation_date < :tomorrow), 0) AS today,
        COALESCE(SUM(CASE WHEN status IN ('PENDING', 'CONFIRMED') AND reservation_date >= :guest_start AND reservation_date < :guest_end THEN party_size ELSE 0 END), 0) AS guests
        FROM reservations");
    $summaryQuery->execute([':now' => $now->format('Y-m-d H:i:s'), ':today' => $today, ':tomorrow' => $tomorrow, ':guest_start' => $today, ':guest_end' => $tomorrow]);
    $summary = array_map('intval', $summaryQuery->fetch(PDO::FETCH_ASSOC));
    $where = [];
    $params = [];
    if ($filters['q'] !== '') {
        // Treat wildcard characters as literal customer search text.
        $search = '%' . strtr($filters['q'], ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        $where[] = "(CONCAT_WS(' ', m.first_name, m.last_name) LIKE :name ESCAPE '!' OR m.phone LIKE :phone ESCAPE '!' OR CAST(r.reservation_id AS CHAR) = :id)";
        $params += [':name' => $search, ':phone' => $search, ':id' => ltrim($filters['q'], '#')];
    }
    if ($filters['status'] !== '') {
        $where[] = 'r.status = :status';
        $params[':status'] = $filters['status'];
    }
    if ($filters['date'] !== '') {
        $where[] = 'r.reservation_date >= :date_start AND r.reservation_date < :date_end';
        $params[':date_start'] = $filters['date'] . ' 00:00:00';
        $params[':date_end'] = (new DateTimeImmutable($filters['date']))->modify('+1 day')->format('Y-m-d 00:00:00');
    }
    $from = ' FROM reservations r LEFT JOIN customer m ON r.member_id = m.member_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    $count = $db->prepare('SELECT COUNT(*)' . $from);
    $count->execute($params);
    $total = (int)$count->fetchColumn();
    $perPage = 10;
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($filters['page'], $pages);
    $offset = ($page - 1) * $perPage;
    $order = match ($filters['sort']) {
        'newest' => 'r.reservation_id DESC',
        'oldest' => 'r.reservation_date ASC, r.reservation_id ASC',
        default => "CASE WHEN r.status IN ('PENDING', 'CONFIRMED') AND r.reservation_date >= :schedule_now THEN 0 ELSE 1 END,
            CASE WHEN r.status IN ('PENDING', 'CONFIRMED') AND r.reservation_date >= :upcoming_now THEN r.reservation_date END ASC,
            r.reservation_date DESC, r.reservation_id DESC",
    };
    if ($filters['sort'] === 'schedule') {
        $params[':schedule_now'] = $params[':upcoming_now'] = $now->format('Y-m-d H:i:s');
    }
    $query = $db->prepare('SELECT r.*, m.first_name, m.last_name, m.phone' . $from . " ORDER BY $order LIMIT $perPage OFFSET $offset");
    $query->execute($params);
    return [
        'summary' => $summary,
        'items' => $query->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total_items' => $total,
            'total_pages' => $pages, 'offset' => $offset, 'start_item' => $total ? $offset + 1 : 0,
            'end_item' => min($offset + $perPage, $total)],
    ];
}
