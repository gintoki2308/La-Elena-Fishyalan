<?php
require_once __DIR__ . '/workforce_helper.php';
require_once __DIR__ . '/pagination_helper.php';

function workforceFilters(array $input): array
{
    $filters = ['staff_id'=>max(0,(int)($input['staff_id'] ?? 0)), 'search'=>'', 'from'=>'', 'to'=>'', 'status'=>'ALL'];
    foreach (['search','from','to','status'] as $key) {
        if (isset($input[$key]) && is_string($input[$key])) $filters[$key] = trim($input[$key]);
    }
    $filters['search'] = substr($filters['search'],0,150);
    foreach (['from','to'] as $key) {
        if ($filters[$key] !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d',$filters[$key]);
            if (!$date || $date->format('Y-m-d') !== $filters[$key]) throw new InvalidArgumentException('Enter valid filter dates.');
        }
    }
    if ($filters['from'] && $filters['to'] && $filters['from'] > $filters['to']) throw new InvalidArgumentException('The end date must be on or after the start date.');
    return $filters;
}

function workforcePage(PDO $db, string $select, string $from, string $where, array $params, string $order, int $page, int $size = 20): array
{
    $query = $db->prepare('SELECT COUNT(*) '.$from.' WHERE '.$where);
    $query->execute($params);
    $total = (int)$query->fetchColumn();
    $pages = max(1,(int)ceil($total/$size));
    $page = max(1,min($page,$pages)); $offset = ($page-1)*$size;
    $query = $db->prepare('SELECT '.$select.' '.$from.' WHERE '.$where.' ORDER BY '.$order.' LIMIT '.$size.' OFFSET '.$offset);
    $query->execute($params);
    return ['items'=>$query->fetchAll(),'current_page'=>$page,'total_pages'=>$pages,'total_items'=>$total,'start_item'=>$total ? $offset+1 : 0,'end_item'=>min($offset+$size,$total)];
}

function workforceListConditions(array $filters, string $alias, string $dateColumn): array
{
    $where = ['1=1']; $params = [];
    if ($filters['staff_id']) { $where[]="$alias.staff_id = ?"; $params[]=$filters['staff_id']; }
    if ($filters['search'] !== '') { $where[]="CONCAT(COALESCE(s.first_name,''),' ',COALESCE(s.last_name,'')) LIKE ?"; $params[]='%'.$filters['search'].'%'; }
    if ($filters['from']) { $where[]="$alias.$dateColumn >= ?"; $params[]=$filters['from']; }
    if ($filters['to']) { $where[]="$alias.$dateColumn <= ?"; $params[]=$filters['to']; }
    return [$where,$params];
}

function workforceAttendanceStateSql(): string
{
    return "CASE WHEN a.check_in_time IS NULL AND a.check_out_time IS NOT NULL THEN 'INVALID'
        WHEN a.check_in_time IS NOT NULL AND a.check_out_time IS NULL THEN 'INCOMPLETE'
        WHEN a.check_out_time <= a.check_in_time THEN 'INVALID' ELSE a.status END";
}

function workforceAttendanceList(PDO $db, array $filters, int $page): array
{
    [$where,$params] = workforceListConditions($filters,'a','attendance_date');
    $state = workforceAttendanceStateSql();
    if (in_array($filters['status'],['PRESENT','LATE','ABSENT','INCOMPLETE','INVALID'],true)) { $where[]="($state) = ?"; $params[]=$filters['status']; }
    $from = 'FROM staff_attendance a JOIN staffs s ON s.staff_id=a.staff_id LEFT JOIN staff_shifts sh ON sh.shift_id=a.shift_id';
    $where = implode(' AND ',$where);
    $query = $db->prepare("SELECT COUNT(*) AS total, COUNT(DISTINCT a.attendance_date) AS dates, COALESCE(SUM(($state)='INCOMPLETE'),0) AS incomplete, COALESCE(SUM(($state)='LATE'),0) AS late $from WHERE $where");
    $query->execute($params); $summary=$query->fetch();
    // Page by dates so every matching staff session for a day stays together.
    $size=7; $totalDates=(int)$summary['dates'];
    $pages=max(1,(int)ceil($totalDates/$size)); $page=max(1,min($page,$pages)); $offset=($page-1)*$size;
    $query=$db->prepare("SELECT DISTINCT a.attendance_date $from WHERE $where ORDER BY a.attendance_date DESC LIMIT $size OFFSET $offset");
    $query->execute($params); $dates=$query->fetchAll(PDO::FETCH_COLUMN);
    $result=['items'=>[], 'days'=>[], 'total_items'=>(int)$summary['total'], 'current_page'=>$page, 'total_pages'=>$pages, 'summary'=>$summary,
        'date_pagination'=>['current_page'=>$page,'total_pages'=>$pages,'total_items'=>$totalDates,'start_item'=>$totalDates?$offset+1:0,'end_item'=>min($offset+$size,$totalDates)]];
    if ($dates) {
        $placeholders=implode(',',array_fill(0,count($dates),'?'));
        $query=$db->prepare("SELECT a.*,s.first_name,s.last_name,sh.start_time,sh.end_time,sh.notes,($state) AS display_status $from WHERE $where AND a.attendance_date IN ($placeholders) ORDER BY a.attendance_date DESC,s.first_name,s.last_name,s.staff_id,a.check_in_time,a.attendance_id");
        $query->execute(array_merge($params,$dates)); $result['items']=$query->fetchAll();
        foreach ($result['items'] as $record) $result['days'][$record['attendance_date']][]=$record;
    }
    return $result;
}

function workforceDeleteShift(PDO $db, int $shiftId): void
{
    $db->beginTransaction();
    try {
        $query=$db->prepare('SELECT staff_id FROM staff_shifts WHERE shift_id=?');
        $query->execute([$shiftId]); $staffId=$query->fetchColumn();
        if (!$staffId) throw new InvalidArgumentException('Shift not found.');
        $query=$db->prepare('SELECT staff_id FROM staffs WHERE staff_id=? FOR UPDATE'); $query->execute([$staffId]);
        $query=$db->prepare('SELECT * FROM staff_shifts WHERE shift_id=? FOR UPDATE'); $query->execute([$shiftId]); $shift=$query->fetch();
        if (!$shift) throw new InvalidArgumentException('Shift not found.');
        $query=$db->prepare('SELECT attendance_id FROM staff_attendance WHERE shift_id=? LIMIT 1'); $query->execute([$shiftId]);
        if ($query->fetchColumn()) throw new InvalidArgumentException('This shift has attendance records and must be kept for attendance history.');
        $db->prepare('DELETE FROM staff_shifts WHERE shift_id=?')->execute([$shiftId]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function workforceScheduleShift(PDO $db, int $staffId, string $date, string $start, string $end, string $notes): array
{
    workforcePeriod($date,$date);
    if ($date < date('Y-m-d')) throw new InvalidArgumentException('Select a current or future date.');
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D',$end) || $end <= $start) throw new InvalidArgumentException('Enter valid times with the end after the start.');
    $db->beginTransaction();
    try {
        $query=$db->prepare("SELECT first_name,last_name FROM staffs WHERE staff_id=? AND account_status='ACTIVE' FOR UPDATE");
        $query->execute([$staffId]); $staff=$query->fetch();
        if (!$staff) throw new InvalidArgumentException('Choose an active staff member.');
        $query=$db->prepare('SELECT shift_id FROM staff_shifts WHERE staff_id=? AND TIMESTAMP(shift_date,start_time) < ? AND (CASE WHEN end_time <= start_time THEN TIMESTAMP(DATE_ADD(shift_date,INTERVAL 1 DAY),end_time) ELSE TIMESTAMP(shift_date,end_time) END) > ? LIMIT 1');
        $query->execute([$staffId,$date.' '.$end,$date.' '.$start]);
        if ($query->fetchColumn()) throw new InvalidArgumentException('This staff member already has an overlapping shift.');
        $db->prepare('INSERT INTO staff_shifts(staff_id,shift_date,start_time,end_time,notes) VALUES (?,?,?,?,?)')->execute([$staffId,$date,$start,$end,$notes]);
        $staff['shift_id']=(int)$db->lastInsertId();
        $db->commit(); return $staff;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}
