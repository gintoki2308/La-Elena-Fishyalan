<?php
require_once __DIR__.'/workforce_admin_helper.php';

function payrollReady(PDO $db): void
{
    workforceReady($db);
    try {
        $db->query('SELECT staff_id,pay_basis,rate FROM staff_pay_rates LIMIT 0');
        $db->query('SELECT payroll_id,revision,updated_at FROM staff_payroll LIMIT 0');
    } catch (PDOException $e) {
        throw new RuntimeException('Payroll setup is required. Apply database/migrations/20261012_add_payroll_editing.sql.');
    }
}

function payrollText($value): string
{
    if (!is_string($value) && !is_int($value)) throw new InvalidArgumentException('Enter valid payroll values.');
    return trim((string)$value);
}

function payrollMoney($value): int
{
    $text=payrollText($value);
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D',$text)) throw new InvalidArgumentException('Enter a non-negative amount with up to two decimal places.');
    [$whole,$decimal]=array_pad(explode('.',$text),2,'');
    return (int)$whole*100+(int)str_pad($decimal,2,'0');
}

function payrollRate(array $input): array
{
    $basis=payrollText($input['pay_basis']??''); $cents=payrollMoney($input['rate']??'');
    if (!in_array($basis,['HOURLY','DAILY','MONTHLY'],true) || $cents<=0) throw new InvalidArgumentException('Choose a pay basis and enter a positive salary rate.');
    return [$basis,$cents];
}

function payrollStaffLock(PDO $db, int $staffId): void
{
    $query=$db->prepare('SELECT staff_id FROM staffs WHERE staff_id=? FOR UPDATE'); $query->execute([$staffId]);
    if (!$query->fetchColumn()) throw new InvalidArgumentException('Select an existing staff member.');
}

function payrollSaveRate(PDO $db, int $staffId, array $input): void
{
    [$basis,$rate]=payrollRate($input);
    $db->beginTransaction();
    try {
        payrollStaffLock($db,$staffId);
        $db->prepare('INSERT INTO staff_pay_rates(staff_id,pay_basis,rate) VALUES (?,?,?) ON DUPLICATE KEY UPDATE pay_basis=VALUES(pay_basis),rate=VALUES(rate)')->execute([$staffId,$basis,$rate/100]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

// All monetary calculations round once to cents. Daily sessions share an eight-hour cap.
function payrollCalculate(array $records, array $input): array
{
    $start=payrollText($input['period_start']??''); $end=payrollText($input['period_end']??''); workforcePeriod($start,$end);
    [$basis,$rate]=payrollRate($input); $dates=[]; $minutes=0; $regular=0;
    foreach ($records as &$record) {
        $date=$record['attendance_date']; $worked=max(0,(int)$record['payable_minutes']);
        $dates[$date]=$dates[$date]??0;
        $record['regular_minutes']=min($worked,max(0,480-$dates[$date]));
        $record['calculation_rule']='DAILY_8H';
        $regular+=$record['regular_minutes']; $dates[$date]+=$worked; $minutes+=$worked;
    }
    unset($record);
    if ($basis==='HOURLY') $basic=round($rate*$minutes/60);
    elseif ($basis==='DAILY') $basic=round($rate*$regular/480);
    else {
        $basic=0;
        for ($date=new DateTimeImmutable($start); $date<=new DateTimeImmutable($end); $date=$date->modify('+1 day')) $basic+=$rate/(int)$date->format('t');
        $basic=round($basic);
    }
    $allowances=payrollMoney($input['allowances']??'0'); $deductions=payrollMoney($input['deductions']??'0');
    $net=$basic+$allowances-$deductions;
    if ($net<0) throw new InvalidArgumentException('Deductions cannot exceed basic salary plus allowances.');
    if ($basic>999999999999 || $net>999999999999) throw new InvalidArgumentException('The payroll amount is too large.');
    $notes=payrollText($input['notes']??'');
    if (strlen($notes)>1000) throw new InvalidArgumentException('Payroll notes must be at most 1,000 bytes.');
    return ['period_start'=>$start,'period_end'=>$end,'pay_basis'=>$basis,'rate'=>$rate/100,'worked_minutes'=>$minutes,'worked_days'=>count(array_filter($dates,static fn($value)=>$value>0)),
        'basic_pay'=>$basic/100,'allowances'=>$allowances/100,'deductions'=>$deductions/100,'net_pay'=>$net/100,'notes'=>$notes,'attendance_snapshot'=>json_encode($records,JSON_THROW_ON_ERROR)];
}

function payrollPreview(PDO $db, int $staffId, array $input, bool $requireClosed=true): array
{
    workforcePeriod(payrollText($input['period_start']??''),payrollText($input['period_end']??''));
    $query=$db->prepare('SELECT staff_id FROM staffs WHERE staff_id=?'); $query->execute([$staffId]);
    if (!$query->fetchColumn()) throw new InvalidArgumentException('Select an existing staff member.');
    $query=$db->prepare('SELECT a.*,sh.start_time,sh.end_time FROM staff_attendance a LEFT JOIN staff_shifts sh ON sh.shift_id=a.shift_id WHERE a.staff_id=? AND a.attendance_date BETWEEN ? AND ? ORDER BY a.attendance_date,a.check_in_time,a.attendance_id');
    $query->execute([$staffId,$input['period_start'],$input['period_end']]); $records=$query->fetchAll(); $open=0; $invalid=0;
    foreach ($records as &$record) {
        if (!empty($record['check_in_time']) && empty($record['check_out_time'])) {
            $open++;
            if ($requireClosed) throw new InvalidArgumentException('An attendance session is still open in this period. Record time out first.');
        }
        if (!empty($record['check_out_time']) && (empty($record['check_in_time']) || workforceTimestamp($record['check_out_time'],$record['attendance_date'])<=workforceTimestamp($record['check_in_time'],$record['attendance_date']))) {
            $invalid++;
            if ($requireClosed) throw new InvalidArgumentException('An attendance record has invalid times. Review attendance first.');
        }
        $record['payable_minutes']=workforceMinutes($record);
    }
    unset($record);
    $calculated=payrollCalculate($records,$input);
    // Estimates report excluded sessions; saved payroll still requires closed, valid attendance.
    if (!$requireClosed) $calculated+=['open_sessions'=>$open,'invalid_sessions'=>$invalid];
    return $calculated;
}

function payrollGenerate(PDO $db, int $staffId, int $adminId, array $input): int
{
    $db->beginTransaction();
    try {
        payrollStaffLock($db,$staffId);
        workforcePeriod(payrollText($input['period_start']??''),payrollText($input['period_end']??''));
        if ($input['period_end']>=date('Y-m-d')) throw new InvalidArgumentException('Generate payroll for completed dates ending before today.');
        $query=$db->prepare('SELECT payroll_id FROM staff_payroll WHERE staff_id=? AND period_start<=? AND period_end>=? LIMIT 1');
        $query->execute([$staffId,$input['period_end'],$input['period_start']]);
        if ($query->fetchColumn()) throw new InvalidArgumentException('Payroll already exists for part or all of this period.');
        $row=payrollPreview($db,$staffId,$input); $row['staff_id']=$staffId; $row['created_by']=$adminId;
        $columns=array_keys($row);
        $db->prepare('INSERT INTO staff_payroll('.implode(',',$columns).') VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));
        $id=(int)$db->lastInsertId(); $db->commit(); return $id;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function payrollUpdate(PDO $db, int $staffId, int $id, int $revision, array $input, bool $markPaid=false): void
{
    $db->beginTransaction();
    try {
        payrollStaffLock($db,$staffId);
        $query=$db->prepare('SELECT * FROM staff_payroll WHERE payroll_id=? AND staff_id=? FOR UPDATE'); $query->execute([$id,$staffId]); $saved=$query->fetch();
        if (!$saved) throw new InvalidArgumentException('Payroll not found for this staff member.');
        if ($saved['status']!=='PENDING') throw new InvalidArgumentException('Paid payroll cannot be edited.');
        if ((int)$saved['revision']!==$revision) throw new InvalidArgumentException('This payroll changed since you opened it. Reload the payslip before saving.');
        if ($markPaid) {
            $db->prepare("UPDATE staff_payroll SET status='PAID',paid_at=?,revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE payroll_id=?")->execute([date('Y-m-d H:i:s'),$id]);
        } else {
            $input['period_start']=$saved['period_start']; $input['period_end']=$saved['period_end'];
            $records=json_decode($saved['attendance_snapshot'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($records)) throw new RuntimeException('The saved attendance snapshot is unavailable.');
            $row=payrollCalculate($records,$input);
            $assignments=implode(',',array_map(static fn($key)=>$key.'=?',array_keys($row)));
            $db->prepare('UPDATE staff_payroll SET '.$assignments.',revision=revision+1,updated_at=CURRENT_TIMESTAMP WHERE payroll_id=?')->execute(array_merge(array_values($row),[$id]));
        }
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function payrollList(PDO $db, array $filters, int $page): array
{
    [$where,$params]=workforceListConditions($filters,'p','period_end');
    if (in_array($filters['status'],['PENDING','PAID'],true)) { $where[]='p.status=?'; $params[]=$filters['status']; }
    $where=implode(' AND ',$where); $from='FROM staff_payroll p JOIN staffs s ON s.staff_id=p.staff_id';
    $result=workforcePage($db,'p.*,s.first_name,s.last_name',$from,$where,$params,'p.period_end DESC,p.payroll_id DESC',$page);
    $query=$db->prepare("SELECT COUNT(*) AS total,COALESCE(SUM(CASE WHEN p.status='PENDING' THEN p.net_pay ELSE 0 END),0) AS pending,COALESCE(SUM(CASE WHEN p.status='PAID' THEN p.net_pay ELSE 0 END),0) AS paid $from WHERE $where");
    $query->execute($params); $result['summary']=$query->fetch(); return $result;
}
