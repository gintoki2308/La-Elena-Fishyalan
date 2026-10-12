<?php
require_once __DIR__ . '/../config/config.php';
if (!isLoggedIn() || !isStaff()) { header('Location: ' . app_url('auth/login.php')); exit; }
// Payroll is managed in the admin workspace; staff bookmarks open Attendance.
header('Location: ' . app_url('staffSide/viewAttendance.php'), true, 303);
exit;
