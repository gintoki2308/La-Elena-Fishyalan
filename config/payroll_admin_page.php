<?php
if (!function_exists('isLoggedIn') || !isLoggedIn() || !isAdmin()) { http_response_code(403); exit; }
require_once __DIR__.'/payroll_helper.php';
$db=(new Database())->connect(); $error=''; $staff=[]; $person=null; $rate=null; $payslip=null; $preview=null; $previewError=''; $ready=false;
$history=['items'=>[],'summary'=>['total'=>0,'pending'=>0,'paid'=>0]];
$filters=workforceFilters([]); $staffId=max(0,(int)(is_scalar($_POST['staff_id']??$_GET['staff_id']??null)?($_POST['staff_id']??$_GET['staff_id']):0));
$payrollId=max(0,(int)(is_scalar($_POST['payroll_id']??$_GET['payroll_id']??null)?($_POST['payroll_id']??$_GET['payroll_id']):0));
$success=$_SESSION['payroll_success']??''; unset($_SESSION['payroll_success']);
$input=['period_start'=>date('Y-m-01'),'period_end'=>date('Y-m-d'),'pay_basis'=>'DAILY','rate'=>'','allowances'=>'0.00','deductions'=>'0.00','notes'=>''];
$salary=['pay_basis'=>'DAILY','rate'=>'']; $edit=$input;
$e='workforceEscape'; $money=static fn($value)=>'PHP '.number_format((float)$value,2);
$bases=['DAILY'=>'Daily (8-hour day)','HOURLY'=>'Hourly','MONTHLY'=>'Fixed monthly salary'];
try {
    if (!$db) throw new RuntimeException('The database is unavailable.');
    payrollReady($db); $ready=true;
    $staff=$db->query('SELECT staff_id,first_name,last_name FROM staffs ORDER BY first_name,last_name')->fetchAll();
    if ($payrollId>0) {
        $query=$db->prepare('SELECT p.*,s.first_name,s.last_name FROM staff_payroll p JOIN staffs s ON s.staff_id=p.staff_id WHERE p.payroll_id=?'); $query->execute([$payrollId]); $payslip=$query->fetch();
        if (!$payslip) throw new InvalidArgumentException('Payslip not found.');
        if ($_SERVER['REQUEST_METHOD']==='POST' && $staffId!==(int)$payslip['staff_id']) throw new InvalidArgumentException('Payroll does not belong to the selected staff member.');
        $staffId=(int)$payslip['staff_id'];
        foreach ($edit as $key=>$default) $edit[$key]=$payslip[$key];
    }
    foreach ($staff as $item) if ((int)$item['staff_id']===$staffId) $person=$item;
    if ($staffId && !$person) throw new InvalidArgumentException('Select an existing staff member.');
    $query=$db->prepare('SELECT * FROM staff_pay_rates WHERE staff_id=?'); $query->execute([$staffId]); $rate=$query->fetch();
    if ($rate) { $salary=['pay_basis'=>$rate['pay_basis'],'rate'=>$rate['rate']]; $input=array_replace($input,$salary); }
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        workforceVerifyCsrf($_POST['csrf_token']??null);
        $action=payrollText($_POST['action']??'');
        if (!$person) throw new InvalidArgumentException('Select a staff member first.');
        if ($action==='save_rate') {
            foreach ($salary as $key=>$default) $salary[$key]=payrollText($_POST[$key]??'');
            payrollSaveRate($db,$staffId,$salary);
            $_SESSION['payroll_success']='Salary rate saved. Existing payslips retain their recorded rates.';
        } elseif ($action==='preview' || $action==='generate') {
            foreach ($input as $key=>$default) $input[$key]=payrollText($_POST[$key]??$default);
            if ($action==='preview') $preview=payrollPreview($db,$staffId,$input,false);
            else { $payrollId=payrollGenerate($db,$staffId,(int)$_SESSION['user_id'],$input); $_SESSION['payroll_success']='Payroll generated. Review the payslip before payment.'; }
        } elseif ($action==='update' || $action==='mark_paid') {
            if (!$payslip) throw new InvalidArgumentException('Select a payslip first.');
            if ($action==='update') foreach (['pay_basis','rate','allowances','deductions','notes'] as $key) $edit[$key]=payrollText($_POST[$key]??'');
            $revision=payrollText($_POST['revision']??'');
            if (!ctype_digit($revision)) throw new InvalidArgumentException('Reload this payslip before saving.');
            payrollUpdate($db,$staffId,$payrollId,(int)$revision,$edit,$action==='mark_paid');
            $_SESSION['payroll_success']=$action==='mark_paid'?'Payroll marked as paid.':'Payroll updated and net pay recalculated.';
        } else throw new InvalidArgumentException('Unknown payroll action.');
        if ($action!=='preview') { header('Location: viewPayroll.php?'.http_build_query(['staff_id'=>$staffId]+($payrollId?['payroll_id'=>$payrollId]:[])),true,303); exit; }
    }
} catch (Throwable $exception) {
    $error=$exception instanceof PDOException?'Payroll could not be loaded or saved. Please try again.':$exception->getMessage();
}
try {
    if ($ready) { $filters=workforceFilters($_GET); $filters['staff_id']=$staffId; $history=payrollList($db,$filters,max(1,(int)($_GET['page']??1))); }
} catch (Throwable $exception) { if (!$error) $error='Payroll history could not be loaded.'; }
$snapshot=$payslip?json_decode($payslip['attendance_snapshot'],true):[];
if ($ready && $person && !$payslip && !$preview && !$error && $input['rate']!=='') {
    try { $preview=payrollPreview($db,$staffId,$input,false); }
    catch (Throwable $exception) { $previewError=$exception instanceof InvalidArgumentException?$exception->getMessage():'Attendance earnings could not be loaded. Please try again.'; }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Staff Payroll - La Elena</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="<?= app_url('staffSide/workforce.css') ?>"></head>
<body><nav class="navbar no-print"><div class="container d-flex gap-2 flex-wrap"><a class="navbar-brand" href="<?= app_url('adminSide/admin-dashboard.php') ?>">&larr; Admin Panel</a><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="viewAttendance.php">Attendance</a><a class="btn btn-outline-secondary" href="viewStaffRecords.php">Staff records</a></div></div></nav>
<main class="container workforce-page">
<div class="workforce-heading no-print"><div><p class="eyebrow">EMPLOYEE MANAGEMENT</p><h1>Staff Payroll</h1><p>Manage staff salaries, deductions, allowances, and payslips.</p></div></div>
<?php if ($error): ?><div class="alert alert-danger no-print" role="alert"><?= $e($error) ?></div><?php endif ?>
<?php if ($success): ?><div class="alert alert-success no-print" role="status"><?= $e($success) ?></div><?php endif ?>
<?php if ($ready): ?>
<div class="workforce-summary no-print"><div>Payslips<strong><?= (int)$history['summary']['total'] ?></strong></div><div>Pending payment<strong><?= $money($history['summary']['pending']) ?></strong></div><div>Total paid<strong><?= $money($history['summary']['paid']) ?></strong></div></div>
<form method="get" class="session-card no-print"><label class="form-label" for="staffPicker">Staff member</label><div class="d-flex gap-2"><select name="staff_id" id="staffPicker" class="form-select"><option value="0">All staff payroll history</option><?php foreach ($staff as $item): ?><option value="<?= (int)$item['staff_id'] ?>" <?= (int)$item['staff_id']===$staffId?'selected':'' ?>><?= $e($item['first_name'].' '.$item['last_name']) ?></option><?php endforeach ?></select><button class="btn btn-primary">View</button></div></form>
<?php if ($person): ?>
<section class="session-card no-print" id="staff-salary"><h2>Staff salary &middot; <?= $e($person['first_name'].' '.$person['last_name']) ?></h2>
<form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= workforceCsrf() ?>"><input type="hidden" name="staff_id" value="<?= $staffId ?>"><input type="hidden" name="action" value="save_rate">
<div class="col-md-5"><label class="form-label" for="salaryBasis">Pay basis</label><select id="salaryBasis" name="pay_basis" class="form-select" required><?php foreach ($bases as $key=>$label): ?><option value="<?= $key ?>" <?= $salary['pay_basis']===$key?'selected':'' ?>><?= $label ?></option><?php endforeach ?></select></div>
<div class="col-md-4"><label class="form-label" for="salaryRate">Salary / rate (PHP)</label><input id="salaryRate" type="number" name="rate" min="0.01" max="9999999999.99" step="0.01" value="<?= $e($salary['rate']) ?>" class="form-control" required></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-primary">Save salary</button></div>
<p class="small text-secondary mb-0">The saved rate fills new payroll forms. Enter the amount per day, hour, or month. Changing this rate keeps existing payslips unchanged.</p></form></section>
<?php if (!$payslip): ?>
<section class="session-card no-print" id="create-payroll"><h2>Create payroll</h2><form method="post" class="row g-3" data-payroll-preview-url="<?= $e(app_url('api/payrollPreview.php')) ?>"><input type="hidden" name="csrf_token" value="<?= workforceCsrf() ?>"><input type="hidden" name="staff_id" value="<?= $staffId ?>">
<?php foreach (['period_start'=>'Period start','period_end'=>'Period end'] as $key=>$label): ?><div class="col-md-6"><label class="form-label" for="<?= $key ?>"><?= $label ?></label><input class="form-control" id="<?= $key ?>" type="date" name="<?= $key ?>" value="<?= $e($input[$key]) ?>" max="<?= date('Y-m-d') ?>" required></div><?php endforeach ?>
<?php $fields=$input; $prefix='create'; require __DIR__.'/payroll_amount_fields.php'; ?>
<div class="d-flex gap-2 flex-wrap"><button name="action" value="preview" class="btn btn-outline-secondary">Preview payroll</button><button name="action" value="generate" class="btn btn-primary" data-generate-payroll <?= !$preview || $input['period_end']>=date('Y-m-d') || ($preview['open_sessions']??0) || ($preview['invalid_sessions']??0)?'disabled':'' ?>>Generate payroll</button></div>
</form><p class="small text-secondary mt-3 mb-0">Daily pay combines completed sessions up to eight hours per date; hourly pay uses completed time. Monthly salary is prorated by calendar days. Allowances can include approved bonuses or overtime; deductions are entered manually. Net pay = basic salary + allowances &minus; deductions. Generate only for completed dates.</p>
<div id="attendance-payroll-preview" class="mt-3" aria-live="polite"><?php require __DIR__.'/payroll_preview_panel.php'; ?></div>
<p class="small text-secondary mt-2 mb-0" data-payroll-refresh-status>Attendance is detected automatically for this staff member and period. This view refreshes every 30 seconds and when you change payroll fields.</p>
</section>
<?php endif ?>
<?php else: ?><p class="alert alert-info no-print">Select a staff member to set their salary and create payroll.</p><?php endif ?>
<?php if ($payslip): ?>
<section class="session-card" id="payslip"><div class="d-flex justify-content-between gap-2 flex-wrap"><h2>La Elena &middot; Payslip #<?= (int)$payslip['payroll_id'] ?></h2><button class="btn btn-outline-secondary no-print" type="button" onclick="window.print()">Print payslip</button></div>
<p><strong><?= $e($payslip['first_name'].' '.$payslip['last_name']) ?></strong><br><?= $e($payslip['period_start'].' to '.$payslip['period_end']) ?></p><p><?= $e($bases[$payslip['pay_basis']]) ?> &middot; <?= $money($payslip['rate']) ?><br><?= workforceDuration((int)$payslip['worked_minutes']) ?> completed &middot; <?= (int)$payslip['worked_days'] ?> attended date(s)</p>
<table class="table payroll-amounts"><tbody><?php foreach (['basic_pay'=>'Basic salary','allowances'=>'Allowances / bonuses','deductions'=>'Deductions','net_pay'=>'Net pay'] as $key=>$label): ?><tr><th scope="row"><?= $label ?></th><td><?= $money($payslip[$key]) ?></td></tr><?php endforeach ?></tbody></table>
<p>Status: <strong><?= $e($payslip['status']) ?></strong><?= $payslip['paid_at']?' &middot; Paid '.$e($payslip['paid_at']):'' ?></p><p class="workforce-notes"><?= $e($payslip['notes']) ?></p><p class="small text-secondary">Created <?= $e($payslip['created_at']) ?> by administrator #<?= (int)$payslip['created_by'] ?><?= $payslip['updated_at']?' &middot; Updated '.$e($payslip['updated_at']):'' ?></p>
<details class="no-print"><summary>Recorded attendance used for this payslip</summary><div class="table-responsive"><table class="table mt-3"><thead><tr><th>Date</th><th>Session</th><th>Time in</th><th>Time out</th><th>Completed time</th></tr></thead><tbody><?php foreach (is_array($snapshot)?$snapshot:[] as $row): ?><tr><td><?= $e($row['attendance_date']) ?></td><td><?= $e($row['session_period']??'SHIFT') ?></td><td><?= $e($row['check_in_time']??'—') ?></td><td><?= $e($row['check_out_time']??'—') ?></td><td><?= workforceDuration((int)($row['payable_minutes']??0)) ?></td></tr><?php endforeach ?></tbody></table></div></details>
</section>
<?php if ($payslip['status']==='PENDING'): ?>
<section class="session-card no-print" id="edit-payroll"><h2>Edit pending payroll</h2><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= workforceCsrf() ?>"><input type="hidden" name="staff_id" value="<?= $staffId ?>"><input type="hidden" name="payroll_id" value="<?= $payrollId ?>"><input type="hidden" name="revision" value="<?= (int)$payslip['revision'] ?>"><input type="hidden" name="action" value="update">
<?php $fields=$edit; $prefix='edit'; require __DIR__.'/payroll_amount_fields.php'; ?><div><button class="btn btn-primary">Save payroll changes</button></div><p class="small text-secondary mb-0">Changes recalculate net pay from this payslip's saved attendance. Its staff member and period remain fixed. Paid payslips cannot be edited.</p></form>
<form method="post" class="mt-3"><input type="hidden" name="csrf_token" value="<?= workforceCsrf() ?>"><input type="hidden" name="staff_id" value="<?= $staffId ?>"><input type="hidden" name="payroll_id" value="<?= $payrollId ?>"><input type="hidden" name="revision" value="<?= (int)$payslip['revision'] ?>"><input type="hidden" name="action" value="mark_paid"><button class="btn btn-outline-success">Mark saved payroll as paid</button><p class="small text-secondary mt-2 mb-0">Save any changes above first, then mark paid after making payment. This records payment status and does not transfer money.</p></form></section>
<?php endif ?>
<a class="btn btn-outline-secondary mb-3 no-print" href="viewPayroll.php?staff_id=<?= $staffId ?>">Create another payroll</a>
<?php endif ?>
<section class="session-card no-print" id="payroll-history"><h2>Payroll history</h2><form method="get" class="row g-2 mb-3"><input type="hidden" name="staff_id" value="<?= $staffId ?>"><div class="col-md-4"><label for="historySearch" class="form-label">Staff name</label><input id="historySearch" class="form-control" name="search" value="<?= $e($filters['search']) ?>"></div><div class="col-md-3"><label for="historyStatus" class="form-label">Payment status</label><select id="historyStatus" name="status" class="form-select"><?php foreach (['ALL','PENDING','PAID'] as $status): ?><option <?= $filters['status']===$status?'selected':'' ?>><?= $status ?></option><?php endforeach ?></select></div><div class="col-md-3 d-flex align-items-end"><button class="btn btn-outline-secondary">Filter history</button></div></form>
<div class="table-responsive"><table class="table"><thead><tr><th>Staff member</th><th>Period</th><th>Basic salary</th><th>Allowances</th><th>Deductions</th><th>Net pay</th><th>Status</th><th>Payslip</th></tr></thead><tbody><?php if (!$history['items']): ?><tr><td colspan="8">No payroll records found.</td></tr><?php endif ?><?php foreach ($history['items'] as $row): ?><tr><td><?= $e($row['first_name'].' '.$row['last_name']) ?></td><td><?= $e($row['period_start'].' to '.$row['period_end']) ?></td><td><?= $money($row['basic_pay']) ?></td><td><?= $money($row['allowances']) ?></td><td><?= $money($row['deductions']) ?></td><td><strong><?= $money($row['net_pay']) ?></strong></td><td><?= $e($row['status']) ?></td><td><a class="btn btn-sm btn-outline-secondary" href="viewPayroll.php?payroll_id=<?= (int)$row['payroll_id'] ?>">View / <?= $row['status']==='PENDING'?'edit':'print' ?></a></td></tr><?php endforeach ?></tbody></table></div><?= render_pagination($history,'page',['payroll_id'=>null],'viewPayroll.php') ?></section>
<?php endif ?>
</main><script src="<?= app_url('adminSide/employeeCrud/payroll.js') ?>"></script></body></html>
