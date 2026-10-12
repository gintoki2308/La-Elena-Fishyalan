<?php
// Isolated attendance tests deliberately omit salary and payroll tables.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('session.save_path', sys_get_temp_dir());
require __DIR__ . '/../config/config.php';
require __DIR__ . '/../config/workforce_admin_helper.php';
$db = (new Database())->connect();
if (!$db) throw new RuntimeException('Start MySQL before running workforce tests.');
$checks = 0;
function checkWorkforce(bool $result, string $message): void {
    global $checks;
    if (!$result) throw new RuntimeException($message);
    $checks++;
}
function rejectWorkforce(callable $operation, string $message): void {
    try { $operation(); } catch (InvalidArgumentException $e) { checkWorkforce(true,$message); return; }
    throw new RuntimeException('Expected rejection: '.$message);
}
$fixture = 'workforce_test_' . bin2hex(random_bytes(6));
$db->exec("CREATE DATABASE $fixture");
try {
    $db->exec("USE $fixture");
    $db->exec("CREATE TABLE staffs (staff_id INT PRIMARY KEY, first_name VARCHAR(100), last_name VARCHAR(100), account_status VARCHAR(20)) ENGINE=InnoDB");
    $db->exec("CREATE TABLE staff_sessions (session_id INT AUTO_INCREMENT PRIMARY KEY, staff_id INT, php_session_id VARCHAR(128)) ENGINE=InnoDB");
    $db->exec("CREATE TABLE staff_shifts (shift_id INT AUTO_INCREMENT PRIMARY KEY, staff_id INT, shift_date DATE, start_time TIME, end_time TIME, notes TEXT) ENGINE=InnoDB");
    $db->exec("CREATE TABLE staff_attendance (attendance_id INT AUTO_INCREMENT PRIMARY KEY, staff_id INT, shift_id INT, attendance_date DATE, session_period VARCHAR(5) NOT NULL DEFAULT 'SHIFT', check_in_time DATETIME, check_out_time DATETIME, status VARCHAR(20), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_attendance_session(staff_id,shift_id,session_period)) ENGINE=InnoDB");
    workforceReady($db);
    checkWorkforce(true,'Attendance setup works without payroll tables.');
    $db->exec("INSERT INTO staffs VALUES (101,'Alex','Rivera','ACTIVE'),(102,'Jamie','Santos','ACTIVE'),(103,'Disabled','Staff','DISABLED')");
    foreach (['email','phone','role','hire_date','display_name','bio','professional_summary','address_street','address_city','address_state','address_zip','emergency_contact_name','emergency_contact_relation','emergency_contact_phone','created_at'] as $column) $db->exec("ALTER TABLE staffs ADD $column VARCHAR(100) NULL");
    $db->exec("UPDATE staffs SET email='synthetic@example.test',role='Kitchen Staff'");
    $db->exec("INSERT INTO staff_shifts VALUES (1,101,'2020-01-06','08:00','17:00',NULL),(2,102,'2020-01-06','08:00','17:00',NULL),(3,103,'2020-01-06','08:00','17:00',NULL),(4,101,'2020-01-07','22:00','06:00',NULL),(5,101,'2020-01-08','08:00','17:00',NULL)");
    $stamp = static fn($time) => strtotime('2020-01-06 '.$time);
    rejectWorkforce(fn()=>workforcePunch($db,101,2,'AM','in',$stamp('08:00')),'Cannot punch another staff shift.');
    rejectWorkforce(fn()=>workforcePunch($db,103,3,'AM','in',$stamp('08:00')),'Disabled staff cannot punch.');
    rejectWorkforce(fn()=>workforcePunch($db,101,1,'AM','in',$stamp('07:44')),'Too early.');
    rejectWorkforce(fn()=>workforcePunch($db,101,1,'AM','out',$stamp('08:00')),'No time out before time in.');
    workforcePunch($db,101,1,'AM','in',$stamp('07:45'));
    rejectWorkforce(fn()=>workforcePunch($db,101,1,'AM','in',$stamp('08:00')),'Duplicate time in.');
    rejectWorkforce(fn()=>workforcePunch($db,101,1,'PM','in',$stamp('12:00')),'No simultaneous open sessions.');
    workforcePunch($db,101,1,'AM','out',$stamp('12:05'));
    rejectWorkforce(fn()=>workforcePunch($db,101,1,'AM','out',$stamp('12:06')),'Duplicate time out.');
    workforcePunch($db,101,1,'PM','in',$stamp('13:00'));
    workforcePunch($db,101,1,'PM','out',$stamp('18:00'));
    checkWorkforce(workforceDaySummary($db,101,'2020-01-06')['minutes']===480,'Scheduled time excludes early arrival, late departure and session gaps.');
    checkWorkforce($db->query("SELECT status FROM staff_attendance WHERE session_period='PM'")->fetchColumn()==='LATE','Late flag saved.');
    workforcePunch($db,101,4,'PM','in',strtotime('2020-01-07 22:00'));
    workforcePunch($db,101,4,'PM','out',strtotime('2020-01-08 06:00'));
    checkWorkforce(workforceDaySummary($db,101,'2020-01-07')['minutes']===480,'Overnight shift duration.');
    $db->exec("INSERT INTO staff_attendance(staff_id,shift_id,attendance_date,check_in_time,status) VALUES (101,5,'2020-01-08','2020-01-08 08:00','PRESENT')");
    rejectWorkforce(fn()=>workforcePunch($db,101,5,'AM','in',strtotime('2020-01-08 08:00')),'Legacy whole shift cannot be double-counted.');
    workforcePunch($db,101,5,'SHIFT','out',strtotime('2020-01-08 17:00'));
    checkWorkforce(workforceDaySummary($db,101,'2020-01-08')['minutes']===540,'Legacy whole shift remains available.');
    workforceFlexiblePunch($db,102,'AM','in',0,$stamp('08:00')+86400);
    $flexId=(int)$db->query('SELECT MAX(attendance_id) FROM staff_attendance')->fetchColumn();
    rejectWorkforce(fn()=>workforceFlexiblePunch($db,102,'AM','in',0,$stamp('08:01')+86400),'Duplicate flexible time in.');
    rejectWorkforce(fn()=>workforceFlexiblePunch($db,101,'AM','out',$flexId,$stamp('09:00')+86400),'Cannot time out another employee session.');
    workforceFlexiblePunch($db,102,'AM','out',$flexId,$stamp('09:00')+86400);
    checkWorkforce(workforceDaySummary($db,102,'2020-01-07')['minutes']===60,'Flexible attendance works without payroll.');
    checkWorkforce(count(workforceFlexibleCards($db,102,$stamp('10:00')+86400)['AM'])===1,'Flexible cards load without payroll.');
    $filters=workforceFilters([]);
    checkWorkforce(workforceAttendanceList($db,$filters,1)['total_items']===5,'All attendance history loads.');
    $daily=workforceAttendanceList($db,$filters,1);
    checkWorkforce(array_keys($daily['days'])===['2020-01-08','2020-01-07','2020-01-06'],'Attendance is separated by date, newest first.');
    checkWorkforce(array_column($daily['days']['2020-01-07'],'staff_id')===[101,102],'All recorded staff for a date stay together in name order.');
    checkWorkforce(count($daily['days']['2020-01-06'])===2,'AM and PM sessions remain together under one date.');
    checkWorkforce(workforceAttendanceList($db,workforceFilters(['staff_id'=>102]),1)['total_items']===1,'Staff attendance filter.');
    checkWorkforce(workforceAttendanceList($db,workforceFilters(['from'=>'2020-01-06','to'=>'2020-01-06']),1)['total_items']===2,'Attendance date filter.');
    checkWorkforce(workforceAttendanceList($db,workforceFilters(['status'=>'LATE']),1)['total_items']===1,'Attendance status filter.');
    rejectWorkforce(fn()=>workforceFilters(['from'=>'bad']),'Invalid date filter.');
    rejectWorkforce(fn()=>workforceVerifyCsrf('wrong'),'Invalid CSRF.');
    rejectWorkforce(fn()=>workforcePeriod('2020-02-30','2020-03-01'),'Invalid date.');
    rejectWorkforce(fn()=>workforceDeleteShift($db,1),'Shifts with attendance cannot be removed.');
    $tomorrow=date('Y-m-d',strtotime('+1 day'));
    $scheduled=workforceScheduleShift($db,101,$tomorrow,'08:00','12:00','Test schedule');
    rejectWorkforce(fn()=>workforceScheduleShift($db,101,$tomorrow,'11:00','13:00',''),'Overlapping schedule rejected.');
    rejectWorkforce(fn()=>workforceScheduleShift($db,103,$tomorrow,'08:00','12:00',''),'Inactive staff schedule rejected.');
    rejectWorkforce(fn()=>workforceScheduleShift($db,101,$tomorrow,'25:00','26:00',''),'Invalid shift times rejected.');
    workforceDeleteShift($db,$scheduled['shift_id']);
    checkWorkforce((int)$db->query('SELECT COUNT(*) FROM staff_shifts WHERE shift_id='.$scheduled['shift_id'])->fetchColumn()===0,'Unused shift can be removed.');
    $output = sys_get_temp_dir().'/la-elena-workforce-preview';
    if (!is_dir($output)) mkdir($output);
    foreach (['attendance','admin-attendance','super-attendance','admin-records','super-records','admin-detail','super-detail','staff-admin-records','staff-admin-attendance','staff-payroll'] as $page) {
        $process=proc_open([PHP_BINARY,__DIR__.'/workforce_render.php',$fixture,$page], [1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $html=stream_get_contents($pipes[1]); $errors=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $code=proc_close($process);
        if (str_contains($page,'payroll') || str_starts_with($page,'staff-admin-')) {
            checkWorkforce($code===0 && $errors==='' && trim($html)==='', 'Removed or restricted route discloses no data: '.$page);
            continue;
        }
        checkWorkforce($code===0 && $errors==='' && str_contains($html,'</html>'), 'Page renders without payroll tables: '.$page.' '.$errors);
        checkWorkforce(!preg_match('/salary|payslip|earnings|pending pay|pay rate/i',$html),'Attendance and staff records have no salary amounts: '.$page);
        if (str_contains($page,'detail')) checkWorkforce(str_contains($html,'Emergency contact') && str_contains($html,'2020-01-06'),'Profile and schedule history remain available.');
        if (str_contains($page,'attendance') && $page!=='attendance') checkWorkforce(str_contains($html,'Attendance #1'),'Attendance detail remains available.');
        if (in_array($page,['admin-attendance','super-attendance'])) checkWorkforce(substr_count($html,'data-attendance-date=')===3 && str_contains($html,'Tuesday, January 7, 2020') && str_contains($html,'2 staff &middot; 2 session(s)'), 'Daily sections show dates and all recorded staff counts.');
        if ($page==='attendance') checkWorkforce(str_contains($html,'Time In'),'Staff attendance buttons remain available.');
        if (in_array($page,['attendance','admin-attendance','admin-records','admin-detail'])) file_put_contents($output.'/'.$page.'.html',$html);
    }
    checkWorkforce(str_contains(file_get_contents(__DIR__.'/../adminSide/admin-dashboard.php'),'viewPayroll.php'),'Dashboard provides Staff Payroll.');
    for ($i=0;$i<25;$i++) $db->exec("INSERT INTO staff_attendance(staff_id,attendance_date,status) VALUES (102,'2020-02-01','ABSENT')");
    for ($i=2;$i<=10;$i++) {
        $date='2020-02-'.str_pad((string)$i,2,'0',STR_PAD_LEFT);
        $db->prepare("INSERT INTO staff_attendance(staff_id,attendance_date,status) VALUES (102,?,'ABSENT')")->execute([$date]);
    }
    $first=workforceAttendanceList($db,$filters,1); $second=workforceAttendanceList($db,$filters,2);
    checkWorkforce($first['total_items']===39 && count($first['days'])===7 && count($second['days'])===6 && count($first['items'])+count($second['items'])===39,'Pagination shows seven complete dates per page without omitting sessions.');
    checkWorkforce(!isset($first['days']['2020-02-01']) && count($second['days']['2020-02-01'])===25,'A day with more than twenty sessions is never split across pages.');
    checkWorkforce(count(array_intersect(array_keys($first['days']),array_keys($second['days'])))===0,'Daily groups do not repeat on adjacent pages.');
    checkWorkforce(workforceAttendanceList($db,$filters,999)['current_page']===2,'Out-of-range page selects the last page.');
    $filtered=workforceAttendanceList($db,workforceFilters(['from'=>'2020-02-01','to'=>'2020-02-01','staff_id'=>102,'status'=>'ABSENT']),1);
    checkWorkforce($filtered['total_items']===25 && count($filtered['days'])===1 && count($filtered['items'])===25,'Date, staff, and status filters retain every session in a daily group.');
    $empty=workforceAttendanceList($db,workforceFilters(['search'=>'No matching employee']),1);
    checkWorkforce($empty['days']===[] && $empty['date_pagination']['total_items']===0 && $empty['current_page']===1,'Empty daily attendance results remain valid.');
    checkWorkforce($first['summary']===$second['summary'],'Attendance totals include every page.');
    echo "Workforce tests passed: $checks checks. Synthetic page previews: $output\n";
} finally {
    $db->exec('USE '.DB_NAME);
    $db->exec("DROP DATABASE $fixture");
    session_destroy();
}
