<?php
if (PHP_SAPI !== 'cli' || !preg_match('/^workforce_test_[a-f0-9]{12}$/D',$argv[1] ?? '')) { http_response_code(404); exit; }
ini_set('session.save_path', sys_get_temp_dir());
putenv('GILDED_SPOON_DB_NAME='.$argv[1]);
$kind=$argv[2];
$admin=str_starts_with($kind,'admin-') || str_starts_with($kind,'super-');
$_SERVER['SCRIPT_NAME']=$admin?'/La_Elena/adminSide/employeeCrud/viewAttendance.php':'/La_Elena/staffSide/viewAttendance.php';
$_SERVER['REQUEST_METHOD']='GET';
require __DIR__.'/../config/config.php';
$db=(new Database())->connect();
$_SESSION=['user_id'=>101,'staff_id'=>101,'user_role'=>$admin?'admin':'staff','first_name'=>'Alex','last_name'=>'Rivera','workforce_csrf'=>'test-token'];
register_shutdown_function(static function () { if (session_status()===PHP_SESSION_ACTIVE) session_destroy(); });
if ($admin) $_SESSION['admin_role']=str_starts_with($kind,'super-')?'Super Admin':'Admin';
$query=$db->prepare('INSERT INTO staff_sessions(staff_id,php_session_id) VALUES (?,?)');
$query->execute([101,session_id()]);
if ($kind==='staff-payroll') {
    $_GET=['payslip'=>1,'staff_id'=>101];
    $_SERVER['REQUEST_METHOD']='POST';
    $_POST=['action'=>'save_rate','staff_id'=>101,'pay_basis'=>'DAILY','rate'=>'750','csrf_token'=>'test-token'];
    register_shutdown_function(static function () {
        if (http_response_code() !== 303) { fwrite(STDERR, 'Staff Payroll bookmark must redirect.'); exit(1); }
    });
    require __DIR__.'/../staffSide/viewPayroll.php';
} elseif ($kind==='attendance') {
    require __DIR__.'/../staffSide/viewAttendance.php';
} elseif (str_contains($kind,'attendance')) {
    if ($admin) $_GET=['attendance_id'=>1];
    require __DIR__.'/../adminSide/employeeCrud/viewAttendance.php';
} else {
    if (str_contains($kind,'detail')) $_GET=['staff_id'=>101];
    require __DIR__.'/../adminSide/employeeCrud/viewStaffRecords.php';
}
