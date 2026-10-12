<?php
if (PHP_SAPI!=='cli') exit;
ini_set('session.save_path',sys_get_temp_dir());
require __DIR__.'/../config/config.php'; require __DIR__.'/../config/payroll_helper.php';
$db=(new Database())->connect(); if (!$db) throw new RuntimeException('Start MySQL before running payroll tests.');
$checks=0;
function payrollCheck(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function payrollReject(callable $operation,string $label): void { try { $operation(); } catch (InvalidArgumentException $e) { payrollCheck(true,$label); return; } throw new RuntimeException('Expected rejection: '.$label); }
$fixture='workforce_test_'.bin2hex(random_bytes(6)); $db->exec("CREATE DATABASE $fixture");
try {
    $db->exec("USE $fixture");
    $db->exec('CREATE TABLE staffs(staff_id INT PRIMARY KEY,first_name VARCHAR(100),last_name VARCHAR(100)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE staff_sessions(session_id INT AUTO_INCREMENT PRIMARY KEY,staff_id INT,php_session_id VARCHAR(128)) ENGINE=InnoDB');
    $db->exec('CREATE TABLE staff_shifts(shift_id INT PRIMARY KEY,staff_id INT,shift_date DATE,start_time TIME,end_time TIME) ENGINE=InnoDB');
    $db->exec("CREATE TABLE staff_attendance(attendance_id INT AUTO_INCREMENT PRIMARY KEY,staff_id INT,shift_id INT NULL,attendance_date DATE,session_period VARCHAR(5),check_in_time DATETIME,check_out_time DATETIME,status VARCHAR(20)) ENGINE=InnoDB");
    $db->exec("INSERT INTO staffs VALUES (101,'Alex','Rivera'),(102,'Jamie','Santos')");
    $legacy=file_get_contents(__DIR__.'/../database/migrations/20261006_add_attendance_payroll.sql');
    $db->exec(substr($legacy,strpos($legacy,'CREATE TABLE')));
    $db->exec("INSERT INTO staff_pay_rates(staff_id,pay_basis,rate) VALUES (101,'DAILY',800)");
    $migration=file_get_contents(__DIR__.'/../database/migrations/20261012_add_payroll_editing.sql');
    $db->exec($migration); $db->exec($migration); payrollReady($db);
    payrollCheck((float)$db->query('SELECT rate FROM staff_pay_rates WHERE staff_id=101')->fetchColumn()===800.0,'Migration is repeatable and preserves salary records.');
    $db->exec("INSERT INTO staff_attendance(staff_id,attendance_date,session_period,check_in_time,check_out_time,status) VALUES
        (101,'2020-01-06','AM','2020-01-06 08:00','2020-01-06 12:00','PRESENT'),
        (101,'2020-01-06','PM','2020-01-06 13:00','2020-01-06 19:00','PRESENT'),
        (101,'2020-01-07','AM','2020-01-07 08:00','2020-01-07 12:00','PRESENT')");
    $input=['period_start'=>'2020-01-06','period_end'=>'2020-01-07','pay_basis'=>'DAILY','rate'=>'800','allowances'=>'50','deductions'=>'25','notes'=>'Bonus and uniform deduction'];
    $preview=payrollPreview($db,101,$input);
    payrollCheck($preview['worked_minutes']===840 && $preview['worked_days']===2 && (float)$preview['basic_pay']===1200.0 && (float)$preview['net_pay']===1225.0,'Daily salary combines sessions, caps each date and prorates short days.');
    payrollCheck((float)payrollPreview($db,101,array_replace($input,['pay_basis'=>'HOURLY','rate'=>'100']))['basic_pay']===1400.0,'Hourly salary counts completed time.');
    payrollCheck((float)payrollPreview($db,101,array_replace($input,['pay_basis'=>'MONTHLY','rate'=>'31000']))['basic_pay']===2000.0,'Monthly salary prorates calendar days.');
    payrollCheck((float)payrollPreview($db,102,array_replace($input,['allowances'=>'0','deductions'=>'0']))['net_pay']===0.0,'Staff with no recorded daily attendance earn zero.');
    foreach (['-1','1e3','NaN','1.001',[]] as $value) payrollReject(fn()=>payrollMoney($value),'Invalid money is rejected.');
    payrollReject(fn()=>payrollSaveRate($db,101,['pay_basis'=>'DAILY','rate'=>'0']),'Zero salary rejected.');
    payrollReject(fn()=>payrollSaveRate($db,999,['pay_basis'=>'DAILY','rate'=>'800']),'Unknown staff rejected.');
    payrollReject(fn()=>payrollPreview($db,101,array_replace($input,['deductions'=>'2000'])),'Negative net pay rejected.');
    payrollReject(fn()=>payrollPreview($db,101,array_replace($input,['notes'=>str_repeat('x',1001)])),'Oversized notes rejected.');
    payrollReject(fn()=>payrollGenerate($db,101,1,array_replace($input,['period_end'=>date('Y-m-d')])),'Unfinished period rejected.');
    $id=payrollGenerate($db,101,1,$input);
    payrollReject(fn()=>payrollGenerate($db,101,1,$input),'Duplicate payroll rejected.');
    payrollReject(fn()=>payrollGenerate($db,101,1,array_replace($input,['period_start'=>'2020-01-05','period_end'=>'2020-01-06'])),'Overlapping payroll rejected.');
    payrollSaveRate($db,101,['pay_basis'=>'DAILY','rate'=>'900']);
    payrollCheck((float)$db->query("SELECT net_pay FROM staff_payroll WHERE payroll_id=$id")->fetchColumn()===1225.0,'Salary setting leaves existing payslips unchanged.');
    $db->exec("UPDATE staff_attendance SET check_out_time='2020-01-07 13:00' WHERE attendance_date='2020-01-07'");
    payrollUpdate($db,101,$id,1,array_replace($input,['deductions'=>'100','notes'=>'Revised uniform deduction']));
    $saved=$db->query("SELECT * FROM staff_payroll WHERE payroll_id=$id")->fetch();
    payrollCheck((float)$saved['net_pay']===1150.0 && (int)$saved['revision']===2 && $saved['notes']==='Revised uniform deduction','Pending deduction edits recalculate using saved attendance, not changed live records.');
    payrollReject(fn()=>payrollUpdate($db,101,$id,1,$input),'Stale changes rejected.');
    payrollReject(fn()=>payrollUpdate($db,102,$id,2,$input),'Staff mismatch rejected.');
    payrollUpdate($db,101,$id,2,$input,true);
    $beforeMigration=$db->query("SELECT * FROM staff_payroll WHERE payroll_id=$id")->fetch();
    $db->exec($migration);
    payrollCheck($db->query("SELECT * FROM staff_payroll WHERE payroll_id=$id")->fetch()===$beforeMigration,'Rerunning migration preserves all saved payslip amounts and status.');
    payrollReject(fn()=>payrollUpdate($db,101,$id,3,$input),'Paid salary changes rejected.');
    payrollReject(fn()=>payrollUpdate($db,101,$id,3,$input,true),'Repeated paid action rejected.');
    $db->exec("INSERT INTO staff_attendance(staff_id,attendance_date,session_period,check_in_time,status) VALUES(101,'2020-01-08','AM','2020-01-08 08:00','PRESENT')");
    payrollReject(fn()=>payrollPreview($db,101,array_replace($input,['period_start'=>'2020-01-08','period_end'=>'2020-01-08'])),'Open attendance blocks payroll.');
    $openEstimate=payrollPreview($db,101,array_replace($input,['period_end'=>'2020-01-08']),false);
    payrollCheck($openEstimate['open_sessions']===1 && (float)$openEstimate['basic_pay']===1300.0,'Automatic estimate excludes open sessions but keeps completed attendance.');
    $db->exec("UPDATE staff_attendance SET check_out_time='2020-01-08 07:00' WHERE attendance_date='2020-01-08'");
    payrollReject(fn()=>payrollPreview($db,101,array_replace($input,['period_start'=>'2020-01-08','period_end'=>'2020-01-08'])),'Invalid attendance blocks payroll.');
    payrollCheck(payrollPreview($db,101,array_replace($input,['period_end'=>'2020-01-08']),false)['invalid_sessions']===1,'Automatic estimate identifies invalid sessions without counting them.');
    $db->exec("UPDATE staff_attendance SET check_out_time='2020-01-08 12:00' WHERE attendance_date='2020-01-08'");
    $pendingId=payrollGenerate($db,101,1,array_replace($input,['period_start'=>'2020-01-08','period_end'=>'2020-01-08']));
    $output=sys_get_temp_dir().'/la-elena-workforce-preview'; if (!is_dir($output)) mkdir($output);
    foreach (['all','selected','detail','paid','super','staff','csrf','preview','save-rate','stale','mismatch','update','mark','paid-update'] as $kind) {
        $renderId=$kind==='paid'?$id:$pendingId;
        $revision=(int)$db->query("SELECT revision FROM staff_payroll WHERE payroll_id=$renderId")->fetchColumn();
        $process=proc_open([PHP_BINARY,__DIR__.'/payroll_render.php',$fixture,$kind,(string)$renderId,(string)$revision],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $html=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
        payrollCheck($code===0 && $errors==='','Payroll route has no runtime errors: '.$kind.' '.$errors);
        if (in_array($kind,['save-rate','update','mark','staff'])) { payrollCheck(trim($html)==='','POST redirects or staff access denied: '.$kind); }
        else payrollCheck(str_contains($html,'</html>'),'Payroll renders: '.$kind);
        if ($kind==='csrf') payrollCheck(str_contains($html,'Your form expired.'),'Payroll POST requires CSRF.');
        if ($kind==='preview') payrollCheck(str_contains($html,'Preview net pay: PHP 1,325.00'),'Payroll preview POST calculates actual attendance.');
        if ($kind==='selected') payrollCheck(str_contains($html,'Salary calculated from attendance') && str_contains($html,'data-payroll-preview-url=') && str_contains($html,'This estimate includes today.'),'Staff payroll loads an automatic current-period estimate.');
        if ($kind==='preview') payrollCheck(str_contains($html,'data-detected-attendance') && str_contains($html,'2020-01-06 08:00'),'Payroll shows the time in/out used to calculate salary.');
        if ($kind==='save-rate') payrollCheck((float)$db->query('SELECT rate FROM staff_pay_rates WHERE staff_id=101')->fetchColumn()===900.0,'Salary form saves default rate.');
        if ($kind==='stale') payrollCheck(str_contains($html,'changed since you opened it'),'Stale edit POST is rejected.');
        if ($kind==='mismatch') payrollCheck(str_contains($html,'does not belong'),'Mismatched staff POST is rejected.');
        if ($kind==='update') payrollCheck((float)$db->query("SELECT net_pay FROM staff_payroll WHERE payroll_id=$pendingId")->fetchColumn()===350.0,'Edit form updates deductions and salary totals.');
        if ($kind==='mark') payrollCheck($db->query("SELECT status FROM staff_payroll WHERE payroll_id=$pendingId")->fetchColumn()==='PAID','Payment status form saves.');
        if ($kind==='paid-update') payrollCheck(str_contains($html,'Paid payroll cannot be edited.'),'Paid edit POST is rejected.');
        if (in_array($kind,['all','selected','detail','paid','preview'])) file_put_contents($output.'/payroll-'.$kind.'.html',$html);
    }
    $db->exec("ALTER TABLE staffs ADD account_status VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'");
    $db->exec("INSERT INTO staff_shifts VALUES (1,101,'2020-03-09','08:00','17:00'),(2,101,'2020-03-10','08:00','17:00'),(3,101,'2020-03-11','22:00','06:00')");
    workforcePunch($db,101,1,'AM','in',strtotime('2020-03-09 07:45'));
    payrollCheck((float)payrollPreview($db,101,array_replace($input,['period_start'=>'2020-03-09','period_end'=>'2020-03-09','allowances'=>'0','deductions'=>'0']),false)['basic_pay']===0.0,'Scheduled time in alone earns no completed salary.');
    workforcePunch($db,101,1,'AM','out',strtotime('2020-03-09 12:05'));
    $scheduledInput=array_replace($input,['period_start'=>'2020-03-09','period_end'=>'2020-03-09','allowances'=>'0','deductions'=>'0']);
    payrollCheck((float)payrollPreview($db,101,$scheduledInput,false)['basic_pay']===400.0,'Scheduled time out is detected and calculates half a daily salary.');
    workforcePunch($db,101,1,'PM','in',strtotime('2020-03-09 13:00'));
    workforcePunch($db,101,1,'PM','out',strtotime('2020-03-09 18:00'));
    $scheduled=payrollPreview($db,101,$scheduledInput);
    payrollCheck($scheduled['worked_minutes']===480 && (float)$scheduled['basic_pay']===800.0,'Scheduled AM/PM attendance detects eight paid hours; early arrival, late departure and lunch gaps add no pay.');
    payrollCheck((float)payrollPreview($db,101,array_replace($scheduledInput,['pay_basis'=>'HOURLY','rate'=>'100']))['basic_pay']===800.0,'Hourly payroll uses the detected scheduled hours.');
    workforcePunch($db,101,2,'AM','in',strtotime('2020-03-10 09:00'));
    workforcePunch($db,101,2,'AM','out',strtotime('2020-03-10 11:00'));
    payrollCheck((float)payrollPreview($db,101,array_replace($scheduledInput,['period_start'=>'2020-03-10','period_end'=>'2020-03-10']))['basic_pay']===200.0,'Late arrival and early departure reduce the detected daily salary.');
    workforcePunch($db,101,3,'PM','in',strtotime('2020-03-11 22:00'));
    workforcePunch($db,101,3,'PM','out',strtotime('2020-03-12 06:00'));
    payrollCheck((float)payrollPreview($db,101,array_replace($scheduledInput,['period_start'=>'2020-03-11','period_end'=>'2020-03-11']))['basic_pay']===800.0,'Overnight scheduled attendance calculates salary using the assigned shift date.');
    $db->exec("INSERT INTO staff_attendance(staff_id,attendance_date,session_period,check_in_time,status) VALUES(101,'2020-03-13','AM','2020-03-13 08:00','PRESENT')");
    $beforeApi=(int)$db->query('SELECT COUNT(*) FROM staff_payroll')->fetchColumn();
    foreach (['api-success','api-open','api-csrf','api-unknown','api-staff','api-guest','api-get'] as $kind) {
        $process=proc_open([PHP_BINARY,__DIR__.'/payroll_render.php',$fixture,$kind],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $json=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
        $response=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        payrollCheck($code===0 && $errors==='','Attendance estimate API responds cleanly: '.$kind);
        if ($kind==='api-success') {
            payrollCheck($response['can_generate']===true && str_contains($response['html'],'Preview net pay: PHP 825.00') && str_contains($response['html'],'08:00:00 - 17:00:00'),'API detects scheduled time-outs and calculates salary.');
            file_put_contents($output.'/payroll-api.json',$json);
        } elseif ($kind==='api-open') payrollCheck($response['can_generate']===false && str_contains($response['html'],'Salary updates after time out.'),'Open sessions are excluded and prevent final payroll generation.');
        else payrollCheck(isset($response['error']) && !isset($response['html']),'Invalid or unauthorized API requests reveal no attendance: '.$kind);
    }
    payrollCheck((int)$db->query('SELECT COUNT(*) FROM staff_payroll')->fetchColumn()===$beforeApi && (float)$db->query('SELECT rate FROM staff_pay_rates WHERE staff_id=101')->fetchColumn()===900.0,'Automatic estimates neither create payslips nor change salary settings.');
    for ($day=1;$day<=22;$day++) {
        $date='2020-02-'.str_pad((string)$day,2,'0',STR_PAD_LEFT);
        payrollGenerate($db,102,1,array_replace($input,['period_start'=>$date,'period_end'=>$date,'pay_basis'=>'MONTHLY','rate'=>'29000','allowances'=>'0','deductions'=>'0']));
    }
    $list=payrollList($db,workforceFilters([]),1); $page2=payrollList($db,workforceFilters([]),2);
    payrollCheck($list['total_items']===24 && count($list['items'])===20 && count($page2['items'])===4 && $list['summary']===$page2['summary'],'Payroll history and totals include every page.');
    payrollCheck(payrollList($db,workforceFilters(['search'=>'Jamie','status'=>'PENDING']),1)['total_items']===22,'Payroll name and status filters work.');
    echo "Payroll tests passed: $checks checks.\n";
} finally { $db->exec('USE '.DB_NAME); $db->exec("DROP DATABASE $fixture"); session_destroy(); }
