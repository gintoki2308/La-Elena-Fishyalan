<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/workforce_helper.php';
if (!isLoggedIn() || !isStaff()) { header('Location: ' . app_url('auth/login.php')); exit; }
$db = (new Database())->connect();
$staffId = (int)($_SESSION['staff_id'] ?? $_SESSION['user_id'] ?? 0);
$error = ''; $unavailable = ''; $cards = ['AM'=>[], 'PM'=>[]]; $history = [];
$success = $_SESSION['attendance_success'] ?? ''; unset($_SESSION['attendance_success']);
$now = time(); $today = date('Y-m-d', $now);
try {
    if (!$db) throw new RuntimeException('The database is unavailable. Please try again.');
    workforceReady($db);
    $account = $db->prepare('SELECT account_status FROM staffs WHERE staff_id = ?');
    $account->execute([$staffId]);
    if ($account->fetchColumn() !== 'ACTIVE') $unavailable = 'Your account must be active to record attendance. Contact an administrator.';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        workforceVerifyCsrf($_POST['csrf_token'] ?? null);
        if (($_POST['mode']??'')==='flexible') {
            workforceFlexiblePunch($db,$staffId,(string)($_POST['period']??''),(string)($_POST['action']??''),(int)($_POST['attendance_id']??0));
        } else {
            workforcePunch($db,$staffId,(int)($_POST['shift_id'] ?? 0),(string)($_POST['period'] ?? ''),(string)($_POST['action'] ?? ''));
        }
        $_SESSION['attendance_success'] = 'Your time ' . ($_POST['action'] === 'in' ? 'in' : 'out') . ' was recorded successfully.';
        header('Location: viewAttendance.php'); exit;
    }
} catch (Throwable $e) { $error = $e instanceof PDOException ? 'Attendance could not be saved. Please refresh and try again.' : $e->getMessage(); }
try {
    if ($db) {
        $query = $db->prepare('SELECT s.* FROM staff_shifts s WHERE s.staff_id = ? AND (s.shift_date = ? OR (s.shift_date=? AND s.end_time<=s.start_time AND s.end_time>?) OR EXISTS (SELECT 1 FROM staff_attendance a WHERE a.shift_id = s.shift_id AND a.staff_id = s.staff_id AND a.check_in_time IS NOT NULL AND a.check_out_time IS NULL)) ORDER BY s.shift_date, s.start_time');
        $query->execute([$staffId,$today,date('Y-m-d',strtotime('-1 day',$now)),date('H:i:s',$now)]);
        foreach ($query->fetchAll() as $shift) {
            $recordsQuery = $db->prepare('SELECT * FROM staff_attendance WHERE staff_id = ? AND shift_id = ?');
            $recordsQuery->execute([$staffId,$shift['shift_id']]);
            $records = array_column($recordsQuery->fetchAll(), null, 'session_period');
            $windows = workforceWindows($shift);
            if (isset($records['SHIFT'])) $windows = ['SHIFT'=>[reset($windows)[0],end($windows)[1]]];
            foreach ($windows as $period => [$start,$end]) {
                $group = $period === 'SHIFT' ? (date('H',$start) < 12 ? 'AM' : 'PM') : $period;
                $cards[$group][] = ['shift'=>$shift,'period'=>$period,'start'=>$start,'end'=>$end,'record'=>$records[$period] ?? []];
            }
        }
        foreach (workforceFlexibleCards($db,$staffId,$now) as $period=>$flexibleCards) $cards[$period]=array_merge($cards[$period],$flexibleCards);
        $query = $db->prepare('SELECT a.*, s.start_time, s.end_time FROM staff_attendance a LEFT JOIN staff_shifts s ON s.shift_id = a.shift_id WHERE a.staff_id = ? ORDER BY a.attendance_date DESC, a.attendance_id DESC LIMIT 60');
        $query->execute([$staffId]); $history = $query->fetchAll();
    }
} catch (Throwable $e) { if (!$error) $error = 'Attendance is unavailable. Please ask your administrator to check setup.'; }
$openSession = false;
foreach ($cards as $group) foreach ($group as $card) if (!empty($card['record']['check_in_time']) && empty($card['record']['check_out_time'])) $openSession = true;
$hasScheduledCards=false;
foreach ($cards as $group) foreach ($group as $card) if (empty($card['flexible'])) $hasScheduledCards=true;
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>My Attendance - La Elena</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="staff-page-theme.css"><link rel="stylesheet" href="workforce.css"></head>
<body class="attendance-page"><nav class="navbar" aria-label="Staff navigation"><div class="container"><a class="navbar-brand" href="staff-dashboard.php">&larr; Staff Panel</a></div></nav>
<main class="container workforce-page">
<div class="workforce-heading"><div><p class="eyebrow">LA ELENA · STAFF WORKSPACE</p><h1>My Attendance</h1><p>Your working day, one session at a time.</p></div><a class="btn btn-outline-secondary" href="viewSchedule.php">View schedule</a></div>
<?php if ($error): ?><div class="alert alert-danger" role="alert"><?= workforceEscape($error) ?></div><?php endif ?>
<?php if ($success): ?><div class="alert alert-success" role="status"><?= workforceEscape($success) ?></div><?php endif ?>
<section class="attendance-clock" data-server-time="<?= $now * 1000 ?>">
<p>CURRENT TIME · PHILIPPINES <span class="clock-name"><?= workforceEscape(trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''))) ?></span></p>
<div class="clock-digits" aria-label="Philippine time"><span id="clockHour"><?= date('h',$now) ?></span><b>:</b><span id="clockMinute"><?= date('i',$now) ?></span><b>:</b><span id="clockSecond"><?= date('s',$now) ?></span><small id="clockPeriod"><?= date('A',$now) ?></small></div>
<p id="clockDate" class="mt-3 mb-0"><?= date('l, F j, Y',$now) ?></p></section>
<div class="schedule-status"><strong><?= $hasScheduledCards ? 'Your scheduled sessions' : 'Flexible attendance' ?></strong><span><?= $hasScheduledCards ? 'Time in opens 15 minutes before each scheduled session.' : 'No assigned shift needed. AM is before noon; PM starts at noon.' ?></span></div>
<?php foreach (['AM'=>'Morning Session','PM'=>'Afternoon Session'] as $group=>$label): ?>
<section class="session-card"><h2><span class="session-tag <?= strtolower($group) ?>"><?= $group ?></span><?= $label ?></h2>
<?php if (!$cards[$group]): ?><p class="text-secondary">No <?= strtolower($label) ?> scheduled.</p><div class="session-actions"><button class="btn btn-primary" disabled>Time In <?= $group ?></button><button class="btn btn-outline-secondary" disabled>Time Out <?= $group ?></button></div><?php endif ?>
<?php foreach ($cards[$group] as $card): $rec=$card['record']; $hasIn=!empty($rec['check_in_time']); $hasOut=!empty($rec['check_out_time']); $buttons=workforceAttendanceButtons($card,$openSession,$now,$unavailable); $canIn=$buttons['can_in']; $canOut=$buttons['can_out']; ?>
<div class="session-entry"><div class="d-flex justify-content-between gap-2 flex-wrap"><strong><?php if (!empty($card['flexible'])): ?><?= date('M j',$card['start']) ?> · Flexible <?= $card['period'] ?> session<?php else: ?><?= date('M j · g:i A',$card['start']) ?> – <?= date('g:i A',$card['end']) ?><?= $card['period']==='SHIFT' ? ' · Existing whole shift' : '' ?><?php endif ?></strong><span class="badge text-bg-<?= $hasOut ? 'success' : ($hasIn ? 'warning' : 'secondary') ?>"><?= $hasOut ? 'Completed' : ($hasIn ? 'Timed in' : ($now >= $card['end'] ? 'Missed' : 'Scheduled')) ?></span></div>
<p class="small text-secondary mt-2">Time in: <?= $hasIn ? date('g:i A',strtotime($rec['check_in_time'])) : '—' ?> &nbsp; · &nbsp; Time out: <?= $hasOut ? date('g:i A',strtotime($rec['check_out_time'])) : '—' ?></p>
<div class="session-actions"><?php foreach (['in'=>'Time In','out'=>'Time Out'] as $action=>$text): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= workforceCsrf() ?>"><input type="hidden" name="shift_id" value="<?= (int)$card['shift']['shift_id'] ?>"><input type="hidden" name="period" value="<?= workforceEscape($card['period']) ?>"><input type="hidden" name="mode" value="<?= !empty($card['flexible'])?'flexible':'scheduled' ?>"><input type="hidden" name="attendance_id" value="<?= (int)($rec['attendance_id']??0) ?>"><button name="action" value="<?= $action ?>" class="btn <?= $action==='in' ? 'btn-primary' : 'btn-outline-secondary' ?>" <?= ($action==='in' ? $canIn : $canOut) ? '' : 'disabled' ?>><?= $text ?> <?= $group ?></button></form><?php endforeach ?></div>
<p class="small text-secondary mt-3 mb-0" role="status"><?= workforceEscape($buttons['reason']) ?></p>
</div><?php endforeach ?></section><?php endforeach ?>
<p class="small text-secondary">Time out when you finish each session. With an assigned shift, completed time counts minutes within scheduled hours. Without a shift, it counts the actual completed time between time in and time out.</p>
<section class="session-card"><h2>Attendance history</h2><p class="text-secondary small">Your latest 60 sessions. Only completed sessions count toward worked time.</p><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Date</th><th>Session</th><th>Time in</th><th>Time out</th><th>Completed time</th><th>Status</th></tr></thead><tbody>
<?php if (!$history): ?><tr><td colspan="6" class="text-center py-4">No attendance recorded yet.</td></tr><?php endif ?>
<?php foreach ($history as $row): ?><tr><td><?= workforceEscape($row['attendance_date']) ?></td><td><?= workforceEscape($row['session_period'] ?? 'SHIFT') ?><br><small><?= $row['shift_id']?'Scheduled':'Flexible' ?></small></td><td><?= $row['check_in_time'] ? date('M j, g:i A',strtotime($row['check_in_time'])) : '—' ?></td><td><?= $row['check_out_time'] ? date('M j, g:i A',strtotime($row['check_out_time'])) : '—' ?></td><td><?= workforceDuration(workforceMinutes($row)) ?></td><td><?= !empty($row['check_in_time']) && empty($row['check_out_time']) ? 'INCOMPLETE' : workforceEscape($row['status']) ?></td></tr><?php endforeach ?>
</tbody></table></div></section></main><script src="attendance.js"></script></body></html>
