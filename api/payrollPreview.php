<?php
header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store');
require_once __DIR__.'/../config/config.php';
if (!isLoggedIn() || !isAdmin()) { http_response_code(403); echo json_encode(['error'=>'Administrator access is required.']); exit; }
if ($_SERVER['REQUEST_METHOD']!=='POST') { http_response_code(405); header('Allow: POST'); echo json_encode(['error'=>'Use POST to calculate payroll.']); exit; }
require_once __DIR__.'/../config/payroll_helper.php';
try {
    workforceVerifyCsrf($_POST['csrf_token']??null);
    $staff=payrollText($_POST['staff_id']??'');
    if (!ctype_digit($staff) || (int)$staff<=0) throw new InvalidArgumentException('Select a staff member.');
    $input=[];
    foreach (['period_start','period_end','pay_basis','rate','allowances','deductions','notes'] as $key) $input[$key]=payrollText($_POST[$key]??'');
    $db=(new Database())->connect(); if (!$db) throw new RuntimeException('Database unavailable.');
    payrollReady($db); $preview=payrollPreview($db,(int)$staff,$input,false);
    $e='workforceEscape'; $money=static fn($value)=>'PHP '.number_format((float)$value,2); $previewError='';
    ob_start(); require __DIR__.'/../config/payroll_preview_panel.php'; $html=ob_get_clean();
    echo json_encode(['html'=>$html,'can_generate'=>$preview['period_end']<date('Y-m-d') && $preview['open_sessions']===0 && $preview['invalid_sessions']===0],JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code($exception instanceof InvalidArgumentException?422:500);
    echo json_encode(['error'=>$exception instanceof InvalidArgumentException?$exception->getMessage():'Attendance salary could not be calculated. Please try again.']);
}
