<?php

function reservationCsrfToken(): string {
    if (empty($_SESSION['reservation_csrf'])) {
        $_SESSION['reservation_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['reservation_csrf'];
}

function validateReservationCsrf($token): void {
    if (!is_string($token) || !hash_equals(reservationCsrfToken(), $token)) {
        throw new InvalidArgumentException('Your session has changed. Refresh the page and try again.');
    }
}

function reservationError(Throwable $error): string {
    if ($error instanceof InvalidArgumentException) {
        return $error->getMessage();
    }
    error_log('Reservation error: ' . $error->getMessage());
    return 'Unable to save or load reservations right now. Please try again.';
}

function validateReservationDate(string $input, bool $newBooking = true): DateTimeImmutable {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $input);
    if (!$date || $date->format('Y-m-d\TH:i') !== $input) {
        throw new InvalidArgumentException('Please provide a valid reservation date and time.');
    }
    if ($newBooking && $date < new DateTimeImmutable('tomorrow')) {
        throw new InvalidArgumentException('Reservations must be booked starting tomorrow.');
    }
    if (!$newBooking && $date <= new DateTimeImmutable()) {
        throw new InvalidArgumentException('Past reservations cannot be made pending or confirmed.');
    }
    $hour = (int)$date->format('H');
    if ($hour < RESTAURANT_OPEN_HOUR || $hour >= RESTAURANT_CLOSE_HOUR) {
        throw new InvalidArgumentException('Reservations are only available between ' . RESTAURANT_OPEN_HOUR . ':00 and ' . RESTAURANT_CLOSE_HOUR . ':00.');
    }
    return $date;
}

// All reservation writes share a database-scoped lock, including empty time slots.
// The transaction makes a status update and its audit record succeed together.
function reservationTransaction(PDO $db, callable $operation) {
    $lockName = 'reservations:' . sha1((string)$db->query('SELECT DATABASE()')->fetchColumn());
    $lock = $db->prepare('SELECT GET_LOCK(:name, 5)');
    $lock->execute([':name' => $lockName]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new InvalidArgumentException('Reservations are busy. Please try again in a moment.');
    }
    try {
        $db->beginTransaction();
        $result = $operation();
        $db->commit();
        return $result;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    } finally {
        $release = $db->prepare('SELECT RELEASE_LOCK(:name)');
        $release->execute([':name' => $lockName]);
    }
}

function checkReservationAvailability(PDO $db, DateTimeImmutable $date, int $party, int $excludeId = 0): void {
    if ($party < RESERVATION_MIN_PARTY || $party > RESERVATION_MAX_PARTY) {
        throw new InvalidArgumentException('Party size must be between ' . RESERVATION_MIN_PARTY . ' and ' . RESERVATION_MAX_PARTY . ' guests.');
    }
    $duplicate = $db->prepare("SELECT COUNT(*) FROM reservations WHERE reservation_date = :date AND reservation_id <> :id AND status IN ('PENDING', 'CONFIRMED')");
    $duplicate->execute([':date' => $date->format('Y-m-d H:i:s'), ':id' => $excludeId]);
    if ((int)$duplicate->fetchColumn() > 0) {
        throw new InvalidArgumentException('This date and time slot is already reserved. Please choose a different schedule.');
    }
    $capacity = $db->prepare("SELECT COALESCE(SUM(party_size), 0) FROM reservations WHERE reservation_date >= :start AND reservation_date <= :end AND reservation_id <> :id AND status IN ('PENDING', 'CONFIRMED')");
    $capacity->execute([':start' => $date->format('Y-m-d H:00:00'), ':end' => $date->format('Y-m-d H:59:59'), ':id' => $excludeId]);
    $available = max(0, RESTAURANT_MAX_CAPACITY - (int)$capacity->fetchColumn());
    if ($party > $available) {
        throw new InvalidArgumentException("Sorry, we only have space for $available more guests at this time.");
    }
}

function createReservation(PDO $db, int $memberId, string $input, $partyInput, string $notes): int {
    $date = validateReservationDate($input);
    $party = filter_var($partyInput, FILTER_VALIDATE_INT);
    if ($party === false || $party < RESERVATION_MIN_PARTY || $party > RESERVATION_MAX_PARTY) {
        throw new InvalidArgumentException('Party size must be a whole number between ' . RESERVATION_MIN_PARTY . ' and ' . RESERVATION_MAX_PARTY . '.');
    }
    if (strlen($notes) > 65535) {
        throw new InvalidArgumentException('Special requests are too long. Please shorten them.');
    }
    return reservationTransaction($db, function () use ($db, $memberId, $date, $party, $notes) {
        checkReservationAvailability($db, $date, $party);
        $insert = $db->prepare("INSERT INTO reservations (member_id, reservation_date, party_size, special_requests, status) VALUES (:member, :date, :party, :notes, 'PENDING')");
        $insert->execute([':member' => $memberId, ':date' => $date->format('Y-m-d H:i:s'), ':party' => $party, ':notes' => $notes]);
        return (int)$db->lastInsertId();
    });
}

function cancelReservation(PDO $db, int $id, int $memberId): void {
    reservationTransaction($db, function () use ($db, $id, $memberId) {
        $update = $db->prepare("UPDATE reservations SET status = 'CANCELLED' WHERE reservation_id = :id AND member_id = :member AND status IN ('PENDING', 'CONFIRMED')");
        $update->execute([':id' => $id, ':member' => $memberId]);
        if ($update->rowCount() !== 1) {
            throw new InvalidArgumentException('Reservation not found or it can no longer be cancelled.');
        }
    });
}

function clearReservationHistory(PDO $db, int $memberId): int {
    if ($memberId < 1) {
        throw new InvalidArgumentException('Please sign in to clear reservation history.');
    }
    return reservationTransaction($db, function () use ($db, $memberId) {
        $delete = $db->prepare("DELETE FROM reservations
            WHERE member_id = :member
            AND (reservation_date < NOW() OR status IN ('COMPLETED', 'CANCELLED'))");
        $delete->execute([':member' => $memberId]);
        return $delete->rowCount();
    });
}

function updateReservationStatus(PDO $db, int $id, string $status, string $role, int $actorId, ?string $expectedStatus = null): ?array {
    if ($id < 1 || !in_array($status, ['PENDING', 'CONFIRMED', 'COMPLETED', 'CANCELLED'], true) || !in_array($role, ['admin', 'staff'], true)) {
        throw new InvalidArgumentException('Please select a valid reservation and status.');
    }
    return reservationTransaction($db, function () use ($db, $id, $status, $role, $actorId, $expectedStatus) {
        $query = $db->prepare('SELECT r.*, m.email, m.first_name, m.last_name FROM reservations r LEFT JOIN customer m ON m.member_id = r.member_id WHERE r.reservation_id = :id FOR UPDATE');
        $query->execute([':id' => $id]);
        $reservation = $query->fetch(PDO::FETCH_ASSOC);
        if (!$reservation) {
            throw new InvalidArgumentException('Reservation not found.');
        }
        if ($expectedStatus !== null && $reservation['status'] !== $expectedStatus) {
            throw new InvalidArgumentException('This reservation was updated by someone else. Review its current status and try again.');
        }
        if ($reservation['status'] === $status) {
            return null;
        }
        if (in_array($status, ['PENDING', 'CONFIRMED'], true)) {
            $date = validateReservationDate(str_replace(' ', 'T', substr($reservation['reservation_date'], 0, 16)), false);
            checkReservationAvailability($db, $date, (int)$reservation['party_size'], $id);
        }
        $update = $db->prepare('UPDATE reservations SET status = :status WHERE reservation_id = :id');
        $update->execute([':status' => $status, ':id' => $id]);
        $actorColumn = $role === 'admin' ? 'admin_id' : 'staff_id';
        $audit = $db->prepare("INSERT INTO audit_logs ($actorColumn, action, table_affected, record_id, ip_address, timestamp) VALUES (:actor, 'UPDATE_RESERVATION_STATUS', 'reservations', :id, :ip, NOW())");
        $audit->execute([':actor' => $actorId, ':id' => $id, ':ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
        return $reservation;
    });
}

function notifyReservationStatus(array $reservation, string $status): string {
    if (!in_array($status, ['CONFIRMED', 'COMPLETED', 'CANCELLED'], true)) {
        return '';
    }
    try {
        require_once __DIR__ . '/Mailer.php';
        $date = date('M d, Y h:i A', strtotime($reservation['reservation_date']));
        $body = '<h2>Hello ' . htmlspecialchars($reservation['first_name'] ?? 'Customer') . ',</h2>';
        $body .= "<p>Your reservation for <strong>$date</strong> has been marked as <strong>$status</strong>.</p><p>Regards,<br>La Elena Fishyalan Team</p>";
        if (sendMail($reservation['email'] ?? '', trim(($reservation['first_name'] ?? '') . ' ' . ($reservation['last_name'] ?? '')), 'Reservation Update - La Elena Fishyalan', $body)) {
            return '';
        }
    } catch (Throwable $error) {
        error_log('Reservation notification error: ' . $error->getMessage());
    }
    return ' The update was saved, but the email notification could not be sent.';
}
