<?php
if (PHP_SAPI!=='cli' || !preg_match('/^workforce_test_[a-f0-9]{12}$/D',$argv[1]??'')) exit(1);
ini_set('session.save_path',sys_get_temp_dir()); putenv('GILDED_SPOON_DB_NAME='.$argv[1]);
$_SERVER['SCRIPT_NAME']='/La_Elena/adminSide/employeeCrud/viewPayroll.php'; $_SERVER['REQUEST_METHOD']='GET';
require __DIR__.'/../config/config.php';
$kind=$argv[2]; $db=(new Database())->connect();
$_SESSION=['user_id'=>1,'user_role'=>in_array($kind,['staff','api-staff'])?'staff':'admin','admin_role'=>$kind==='super'?'Super Admin':'Admin','workforce_csrf'=>'test-token'];
if (in_array($kind,['staff','api-staff'])) {
    $_SESSION['user_id']=$_SESSION['staff_id']=101;
    $db->prepare('INSERT INTO staff_sessions(staff_id,php_session_id) VALUES (?,?)')->execute([101,session_id()]);
}
register_shutdown_function(static function () { if (session_status()===PHP_SESSION_ACTIVE) session_destroy(); });
if (str_starts_with($kind,'api-')) {
    $_SERVER['REQUEST_METHOD']=$kind==='api-get'?'GET':'POST';
    $_POST=['staff_id'=>101,'csrf_token'=>$kind==='api-csrf'?'wrong':'test-token','pay_basis'=>'DAILY','rate'=>'800','period_start'=>'2020-03-09','period_end'=>'2020-03-09','allowances'=>'50','deductions'=>'25','notes'=>''];
    if ($kind==='api-open') $_POST['period_start']=$_POST['period_end']='2020-03-13';
    if ($kind==='api-unknown') $_POST['staff_id']=999;
    if ($kind==='api-guest') $_SESSION=[];
    require __DIR__.'/../api/payrollPreview.php'; exit;
}
$_GET=$kind==='all'?[]:['staff_id'=>101];
if (in_array($kind,['detail','paid','update','paid-update','stale','mark','mismatch'])) $_GET=['payroll_id'=>(int)($argv[3]??1)];
if (in_array($kind,['csrf','preview','save-rate','update','paid-update','stale','mark','mismatch'])) {
    $_SERVER['REQUEST_METHOD']='POST';
    $_POST=['staff_id'=>101,'csrf_token'=>$kind==='csrf'?'wrong':'test-token','action'=>'preview','pay_basis'=>'DAILY','rate'=>'800.00','period_start'=>'2020-01-06','period_end'=>'2020-01-07','allowances'=>'50.00','deductions'=>'25.00','notes'=>'Test payroll'];
    if ($kind==='save-rate') { $_POST['action']='save_rate'; $_POST['rate']='900.00'; }
    if (in_array($kind,['update','paid-update','stale','mark','mismatch'])) {
        $_POST['action']=$kind==='mark'?'mark_paid':'update'; $_POST['payroll_id']=(int)($argv[3]??1); $_POST['revision']=$kind==='stale'?'0':(string)($argv[4]??1);
        $_POST['deductions']='100.00'; $_POST['notes']='Updated deductions';
        if ($kind==='mismatch') $_POST['staff_id']=102;
    }
    if (in_array($kind,['save-rate','update','mark'])) register_shutdown_function(static function () {
        if (http_response_code()!==303) { fwrite(STDERR,'Successful payroll save must redirect.'); exit(1); }
    });
}
require __DIR__.'/../adminSide/employeeCrud/viewPayroll.php';
