<?php
// Shared attendance rules. All timestamps use the application's Manila timezone.
function workforceEscape($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function workforceCsrf(): string
{
    return $_SESSION['workforce_csrf'] ?? ($_SESSION['workforce_csrf'] = bin2hex(random_bytes(32)));
}

function workforceVerifyCsrf($token): void
{
    if (!is_string($token) || !hash_equals(workforceCsrf(), $token)) {
        throw new InvalidArgumentException('Your form expired. Refresh the page and try again.');
    }
}

function workforceReady(PDO $db): void
{
    try {
        $db->query('SELECT session_period FROM staff_attendance LIMIT 0');
    } catch (PDOException $e) {
        throw new RuntimeException('Attendance setup is required. Ask your administrator to apply the attendance session migration.');
    }
}

function workforceWindows(array $shift): array
{
    $start = strtotime($shift['shift_date'] . ' ' . $shift['start_time']);
    $end = strtotime($shift['shift_date'] . ' ' . $shift['end_time']);
    if ($end <= $start) $end = strtotime('+1 day', $end);
    $noon = strtotime($shift['shift_date'] . ' 12:00:00');
    $windows = [];
    if ($start < $noon) $windows['AM'] = [$start, min($end, $noon)];
    if ($end > $noon) $windows['PM'] = [max($start, $noon), $end];
    return $windows;
}

function workforceTimestamp(?string $value, string $date): ?int
{
    if (!$value) return null;
    $stamp = strtotime(strlen($value) <= 8 ? $date . ' ' . $value : $value);
    return $stamp === false ? null : $stamp;
}

function workforceMinutes(array $record): int
{
    $start = workforceTimestamp($record['check_in_time'] ?? null, $record['attendance_date']);
    $end = workforceTimestamp($record['check_out_time'] ?? null, $record['attendance_date']);
    if (!$start || !$end || $end <= $start) return 0;
    // Count completed time within the scheduled session.
    if (!empty($record['start_time'])) {
        $windows = workforceWindows(['shift_date' => $record['attendance_date']] + $record);
        $period = $record['session_period'] ?? 'SHIFT';
        $window = $windows[$period] ?? [reset($windows)[0], end($windows)[1]];
        $start = max($start, $window[0]);
        $end = min($end, $window[1]);
    }
    return max(0, (int)floor(($end - $start) / 60));
}

function workforceDuration(int $minutes): string
{
    return sprintf('%dh %02dm', intdiv($minutes, 60), $minutes % 60);
}

function workforceAttendanceButtons(array $card, bool $hasOpenSession, int $now, string $unavailable = ''): array
{
    $record = $card['record'];
    $hasIn = !empty($record['check_in_time']);
    $hasOut = !empty($record['check_out_time']);
    $canIn = false; $canOut = false;
    if ($unavailable !== '') $reason = $unavailable;
    elseif ($hasOut && !$hasIn) $reason = 'This record needs an administrator to review its missing time in.';
    elseif ($hasOut) $reason = 'This session is complete. Your times have been saved.';
    elseif ($hasIn) { $canOut = true; $reason = 'You are timed in. Use Time Out when you finish this session.'; }
    elseif ($hasOpenSession) $reason = 'Time out of your open session before starting another one.';
    elseif ($now < $card['start'] - (!empty($card['flexible']) ? 0 : 900)) $reason = 'Time in opens at ' . date('g:i A', $card['start'] - (!empty($card['flexible']) ? 0 : 900)) . '.';
    elseif ($now >= $card['end']) $reason = 'This session has ended. Time in is no longer available.';
    else { $canIn = true; $reason = 'Ready to time in. Your current Philippine time will be recorded.'; }
    return ['can_in'=>$canIn,'can_out'=>$canOut,'reason'=>$reason];
}

function workforceHasAssignedShift(PDO $db, int $staffId, int $now): bool
{
    $date=date('Y-m-d',$now);
    $query=$db->prepare('SELECT shift_id FROM staff_shifts WHERE staff_id=? AND (shift_date=? OR (shift_date=? AND end_time<=start_time AND end_time>?)) LIMIT 1');
    $query->execute([$staffId,$date,date('Y-m-d',strtotime('-1 day',$now)),date('H:i:s',$now)]);
    return (bool)$query->fetchColumn();
}

function workforceFlexiblePunch(PDO $db, int $staffId, string $period, string $action, int $attendanceId = 0, ?int $now = null): void
{
    if (!in_array($period,['AM','PM'],true) || !in_array($action,['in','out'],true)) throw new InvalidArgumentException('Invalid attendance action.');
    $now=$now??time(); $date=date('Y-m-d',$now);
    $db->beginTransaction();
    try {
        $query=$db->prepare("SELECT staff_id FROM staffs WHERE staff_id=? AND account_status='ACTIVE' FOR UPDATE");
        $query->execute([$staffId]);
        if (!$query->fetchColumn()) throw new InvalidArgumentException('Your staff account is not active.');
        if ($action==='in') {
            if (workforceHasAssignedShift($db,$staffId,$now)) throw new InvalidArgumentException('You have an assigned shift. Refresh and use its scheduled attendance buttons.');
            if ($period!==(date('H',$now)<12?'AM':'PM')) throw new InvalidArgumentException('Use the session that matches the current Philippine time.');
            $query=$db->prepare('SELECT attendance_id FROM staff_attendance WHERE staff_id=? AND check_in_time IS NOT NULL AND check_out_time IS NULL LIMIT 1');
            $query->execute([$staffId]);
            if ($query->fetchColumn()) throw new InvalidArgumentException('Time out of your open session first.');
            $query=$db->prepare('SELECT attendance_id FROM staff_attendance WHERE staff_id=? AND attendance_date=? AND session_period=? AND shift_id IS NULL LIMIT 1');
            $query->execute([$staffId,$date,$period]);
            if ($query->fetchColumn()) throw new InvalidArgumentException('You already recorded this flexible session today.');
        } else {
            $query=$db->prepare('SELECT * FROM staff_attendance WHERE attendance_id=? AND staff_id=? AND shift_id IS NULL AND session_period=? FOR UPDATE');
            $query->execute([$attendanceId,$staffId,$period]); $record=$query->fetch();
            if (!$record || empty($record['check_in_time'])) throw new InvalidArgumentException('Time in before recording time out.');
            if (!empty($record['check_out_time'])) throw new InvalidArgumentException('You already timed out of this session.');
            if ($now<=workforceTimestamp($record['check_in_time'],$record['attendance_date'])) throw new InvalidArgumentException('Time out must be after time in.');
            $date=$record['attendance_date'];
        }
        if ($action==='in') {
            $query=$db->prepare("INSERT INTO staff_attendance(staff_id,shift_id,attendance_date,session_period,check_in_time,status) VALUES (?,NULL,?,?,?,'PRESENT')");
            $query->execute([$staffId,$date,$period,date('Y-m-d H:i:s',$now)]);
        } else {
            $query=$db->prepare('UPDATE staff_attendance SET check_out_time=? WHERE attendance_id=?');
            $query->execute([date('Y-m-d H:i:s',$now),$attendanceId]);
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function workforceFlexibleCards(PDO $db, int $staffId, int $now): array
{
    $date=date('Y-m-d',$now);
    $query=$db->prepare('SELECT a.* FROM staff_attendance a WHERE a.staff_id=? AND a.shift_id IS NULL AND a.session_period IN (\'AM\',\'PM\') AND (a.attendance_date=? OR (a.check_in_time IS NOT NULL AND a.check_out_time IS NULL)) ORDER BY a.attendance_date,a.attendance_id');
    $query->execute([$staffId,$date]);
    $records=[];
    foreach ($query->fetchAll() as $row) $records[$row['attendance_date']][$row['session_period']]=$row;
    // Show completed/open flexible records even if an admin subsequently adds a shift.
    if (!workforceHasAssignedShift($db,$staffId,$now)) {
        foreach (['AM','PM'] as $period) $records[$date][$period]=$records[$date][$period]??[];
    }
    $cards=['AM'=>[],'PM'=>[]];
    foreach ($records as $day=>$sessions) foreach ($sessions as $period=>$record) {
        $start=strtotime($day.($period==='AM'?' 00:00:00':' 12:00:00'));
        $end=$period==='AM'?strtotime($day.' 12:00:00'):strtotime($day.' +1 day');
        $cards[$period][]=['flexible'=>true,'period'=>$period,'record'=>$record,'start'=>$start,'end'=>$end,'shift'=>['shift_id'=>0,'shift_date'=>$day]];
    }
    return $cards;
}

function workforceDaySummary(PDO $db, int $staffId, string $date): array
{
    $query=$db->prepare('SELECT a.*,s.start_time,s.end_time FROM staff_attendance a LEFT JOIN staff_shifts s ON s.shift_id=a.shift_id WHERE a.staff_id=? AND a.attendance_date=?');
    $query->execute([$staffId,$date]); $rows=$query->fetchAll();
    $minutes=0; $open=false; $late=false;
    foreach ($rows as $row) {
        $minutes+=workforceMinutes($row);
        $open=$open || (!empty($row['check_in_time']) && empty($row['check_out_time']));
        $late=$late || $row['status']==='LATE';
    }
    return ['minutes'=>$minutes,'status'=>$open?'TIMED IN':($late?'LATE':($rows?'PRESENT':'NOT TIMED IN'))];
}

function workforcePunch(PDO $db, int $staffId, int $shiftId, string $period, string $action, ?int $now = null): void
{
    if (!in_array($action, ['in', 'out'], true) || !in_array($period, ['AM', 'PM', 'SHIFT'], true)) {
        throw new InvalidArgumentException('Invalid attendance action.');
    }
    $now = $now ?? time();
    $db->beginTransaction();
    try {
        // Serialize attendance actions for the same staff member.
        $staff = $db->prepare('SELECT staff_id FROM staffs WHERE staff_id = ? AND account_status = ? FOR UPDATE');
        $staff->execute([$staffId, 'ACTIVE']);
        if (!$staff->fetchColumn()) throw new InvalidArgumentException('Your staff account is not active.');
        $query = $db->prepare('SELECT * FROM staff_shifts WHERE shift_id = ? AND staff_id = ? FOR UPDATE');
        $query->execute([$shiftId, $staffId]);
        $shift = $query->fetch();
        if (!$shift) throw new InvalidArgumentException('This shift does not belong to you.');
        $query = $db->prepare('SELECT * FROM staff_attendance WHERE staff_id = ? AND shift_id = ? FOR UPDATE');
        $query->execute([$staffId, $shiftId]);
        $records = array_column($query->fetchAll(), null, 'session_period');
        $windows = workforceWindows($shift);
        if (isset($records['SHIFT'])) {
            $windows = ['SHIFT' => [reset($windows)[0], end($windows)[1]]];
        }
        if (!isset($windows[$period])) throw new InvalidArgumentException('This session is not scheduled.');
        [$start, $end] = $windows[$period];
        $record = $records[$period] ?? null;
        if ($action === 'in') {
            if ($period === 'SHIFT' && !$record) throw new InvalidArgumentException('Select a morning or afternoon session.');
            if (!empty($record['check_in_time']) || !empty($record['check_out_time'])) throw new InvalidArgumentException('You already recorded this session.');
            if ($now < $start - 900 || $now >= $end) throw new InvalidArgumentException('Time in opens 15 minutes before the session and closes when it ends.');
            $open = $db->prepare('SELECT attendance_id FROM staff_attendance WHERE staff_id = ? AND check_in_time IS NOT NULL AND check_out_time IS NULL LIMIT 1');
            $open->execute([$staffId]);
            if ($open->fetchColumn()) throw new InvalidArgumentException('Time out of your open session first.');
            $status = $now > $start ? 'LATE' : 'PRESENT';
            if ($record) {
                $query = $db->prepare('UPDATE staff_attendance SET check_in_time = ?, status = ? WHERE attendance_id = ?');
                $query->execute([date('Y-m-d H:i:s', $now), $status, $record['attendance_id']]);
            } else {
                $query = $db->prepare('INSERT INTO staff_attendance (staff_id,shift_id,attendance_date,session_period,check_in_time,status) VALUES (?,?,?,?,?,?)');
                $query->execute([$staffId,$shiftId,$shift['shift_date'],$period,date('Y-m-d H:i:s',$now),$status]);
            }
        } else {
            if (empty($record['check_in_time'])) throw new InvalidArgumentException('Time in before recording time out.');
            if (!empty($record['check_out_time'])) throw new InvalidArgumentException('You already timed out of this session.');
            if ($now <= workforceTimestamp($record['check_in_time'], $shift['shift_date'])) throw new InvalidArgumentException('Time out must be after time in.');
            $query = $db->prepare('UPDATE staff_attendance SET check_out_time = ? WHERE attendance_id = ?');
            $query->execute([date('Y-m-d H:i:s',$now),$record['attendance_id']]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

function workforcePeriod(string $start, string $end): void
{
    foreach ([$start, $end] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) throw new InvalidArgumentException('Enter valid dates.');
    }
    if ($end < $start || (strtotime($end) - strtotime($start)) > 366 * 86400) {
        throw new InvalidArgumentException('Choose a date range of at most one year with the end on or after the start.');
    }
}

