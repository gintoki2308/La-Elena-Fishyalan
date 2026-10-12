<?php
if (!function_exists('isAdmin') || !isLoggedIn() || !canManageBackOffice()) { http_response_code(403); exit; }
require_once __DIR__.'/workforce_admin_helper.php';
$db=(new Database())->connect(); $error=''; $staff=[]; $detail=null;
$filters=workforceFilters([]); $data=['items'=>[],'days'=>[],'date_pagination'=>[],'summary'=>['total'=>0,'incomplete'=>0,'late'=>0]];
try {
    if (!$db) throw new RuntimeException('The database is unavailable.');
    workforceReady($db);
    $filters=workforceFilters($_GET);
    $staff=$db->query('SELECT staff_id,first_name,last_name FROM staffs ORDER BY first_name,last_name')->fetchAll();
    $data=workforceAttendanceList($db,$filters,(int)($_GET['page']??1));
    if (isset($_GET['attendance_id'])) {
        $query=$db->prepare('SELECT a.*,s.first_name,s.last_name,sh.start_time,sh.end_time,sh.notes FROM staff_attendance a JOIN staffs s ON s.staff_id=a.staff_id LEFT JOIN staff_shifts sh ON sh.shift_id=a.shift_id WHERE a.attendance_id=?');
        $query->execute([(int)$_GET['attendance_id']]); $detail=$query->fetch();
        if (!$detail) { http_response_code(404); $error='Attendance record not found.'; }
    }
} catch (Throwable $e) { $error=$e instanceof PDOException?'Attendance could not be loaded. Please try again.':$e->getMessage(); }
$e='workforceEscape';
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Staff Attendance - Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="../../staffSide/workforce.css"></head>
<body><nav class="navbar"><div class="container d-flex gap-2 flex-wrap"><a class="navbar-brand" href="../admin-dashboard.php">&larr; Admin Panel</a><div class="d-flex gap-2 flex-wrap"><a class="btn btn-outline-secondary" href="viewStaffRecords.php">Staff records</a><a class="btn btn-outline-secondary" href="viewPayroll.php">Staff Payroll</a><a class="btn btn-outline-secondary" href="viewSchedule.php">Schedules</a></div></div></nav>
<main class="container workforce-page"><div class="workforce-heading"><div><p class="eyebrow">EMPLOYEE MANAGEMENT</p><h1>Staff Attendance</h1><p>All staff, every recorded session. Filter by employee, date, or status.</p></div></div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= $e($error) ?></div><?php endif ?>
<div class="workforce-summary"><div>Matching sessions<strong><?= (int)$data['summary']['total'] ?></strong></div><div>Incomplete sessions<strong><?= (int)$data['summary']['incomplete'] ?></strong></div><div>Completed late sessions<strong><?= (int)$data['summary']['late'] ?></strong></div></div>
<form method="get" class="session-card row g-3"><div class="col-md-4"><label for="staff" class="form-label">Staff member</label><select id="staff" name="staff_id" class="form-select"><option value="0">All staff</option><?php foreach ($staff as $person): ?><option value="<?= (int)$person['staff_id'] ?>" <?= $filters['staff_id']==$person['staff_id']?'selected':'' ?>><?= $e($person['first_name'].' '.$person['last_name']) ?></option><?php endforeach ?></select></div><div class="col-md-4"><label for="search" class="form-label">Search name</label><input id="search" name="search" class="form-control" value="<?= $e($filters['search']) ?>"></div><div class="col-md-4"><label for="status" class="form-label">Status</label><select id="status" name="status" class="form-select"><?php foreach (['ALL','PRESENT','LATE','ABSENT','INCOMPLETE','INVALID'] as $status): ?><option <?= $filters['status']===$status?'selected':'' ?>><?= $status ?></option><?php endforeach ?></select></div>
<?php foreach (['from'=>'From date','to'=>'To date'] as $key=>$label): ?><div class="col-md-4"><label for="<?= $key ?>" class="form-label"><?= $label ?></label><input id="<?= $key ?>" type="date" name="<?= $key ?>" class="form-control" value="<?= $e($filters[$key]) ?>"></div><?php endforeach ?><div class="col-md-4 d-flex gap-2 align-items-end"><button class="btn btn-primary">Apply filters</button><a class="btn btn-outline-secondary" href="viewAttendance.php">All records</a></div></form>
<?php if ($detail): ?><section class="session-card"><h2>Attendance #<?= (int)$detail['attendance_id'] ?> · <?= $e($detail['first_name'].' '.$detail['last_name']) ?></h2><div class="row g-3"><?php foreach (['attendance_date'=>'Date','session_period'=>'Session','start_time'=>'Scheduled shift start','end_time'=>'Scheduled shift end','check_in_time'=>'Time in','check_out_time'=>'Time out','status'=>'Recorded status','created_at'=>'Recorded at'] as $key=>$label): ?><div class="col-md-6"><small><?= $label ?></small><p><strong><?= $e($detail[$key]??'Not recorded') ?></strong></p></div><?php endforeach ?></div><p>Completed time: <strong><?= workforceDuration(workforceMinutes($detail)) ?></strong></p><p class="workforce-notes">Shift notes: <?= $e($detail['notes']?:'None') ?></p><a class="btn btn-outline-secondary" href="viewStaffRecords.php?staff_id=<?= (int)$detail['staff_id'] ?>">Employee details</a> </section><?php endif ?>
<section class="session-card"><h2>Attendance records</h2><p class="small text-secondary">All recorded staff sessions are grouped by day, newest first. Each page shows up to seven attendance dates with all matching sessions for those dates. Staff without a recorded session are not listed. Completed time counts scheduled minutes, or actual completed time for flexible sessions.</p>
<?php if (!$data['days']): ?><p class="text-secondary">No matching attendance records.</p><?php endif ?>
<?php foreach ($data['days'] as $attendanceDate=>$records): $staffCount=count(array_unique(array_column($records,'staff_id'))); ?>
<section class="attendance-day" data-attendance-date="<?= $e($attendanceDate) ?>" aria-labelledby="attendance-day-<?= $e($attendanceDate) ?>">
    <div class="attendance-day-heading"><h3 id="attendance-day-<?= $e($attendanceDate) ?>"><time datetime="<?= $e($attendanceDate) ?>"><?= $e(date('l, F j, Y',strtotime($attendanceDate))) ?></time></h3><span><?= $staffCount ?> staff &middot; <?= count($records) ?> session(s)</span></div>
    <div class="table-responsive"><table class="table"><caption class="visually-hidden">Staff attendance for <?= $e($attendanceDate) ?></caption><thead><tr><th scope="col">Staff member</th><th scope="col">Session</th><th scope="col">Time in</th><th scope="col">Time out</th><th scope="col">Completed time</th><th scope="col">Status</th><th scope="col">Details</th></tr></thead><tbody>
    <?php foreach ($records as $row): ?><tr><td><a href="viewStaffRecords.php?staff_id=<?= (int)$row['staff_id'] ?>"><?= $e($row['first_name'].' '.$row['last_name']) ?></a></td><td><?= $e($row['session_period']) ?><br><small><?= $row['shift_id']?'Scheduled':'Flexible' ?></small></td><td><?= $e($row['check_in_time']??'—') ?></td><td><?= $e($row['check_out_time']??'—') ?></td><td><?= workforceDuration(workforceMinutes($row)) ?></td><td><?= $e($row['display_status']) ?></td><td><a class="btn btn-sm btn-outline-secondary" href="?<?= $e(http_build_query(array_merge($filters,['page'=>$data['current_page'],'attendance_id'=>$row['attendance_id']]))) ?>">View</a></td></tr><?php endforeach ?>
    </tbody></table></div>
</section>
<?php endforeach ?>
<?= render_pagination($data['date_pagination'],'page',[], 'viewAttendance.php') ?></section></main></body></html>
