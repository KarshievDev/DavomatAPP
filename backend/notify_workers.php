<?php
require_once 'config.php';
require_once 'api_functions.php';

/**
 * Worker Notification Script
 * This script should be run via cron every minute.
 * Example: * * * * * php /path/to/backend/notify_workers.php
 */

$ten_mins_later = date('H:i', strtotime('+10 minutes'));

// Find employees starting in 10 minutes
$stmtStart = $pdo->prepare("SELECT push_token, full_name FROM employees WHERE TIME_FORMAT(work_start_time, '%H:%i') = ? AND push_token IS NOT NULL");
$stmtStart->execute([$ten_mins_later]);
$startingEmployees = $stmtStart->fetchAll();

foreach ($startingEmployees as $worker) {
    sendPush($worker['push_token'], "Ish vaqti yaqinlashmoqda", "Salom " . $worker['full_name'] . ", 10 daqiqadan so'ng ish vaqtingiz boshlanadi. Ilovada 'Kirib keldim' tugmasini bosish yodingizdan chiqmasin!");
}

// Find employees ending in 10 minutes
$stmtEnd = $pdo->prepare("SELECT push_token, full_name FROM employees WHERE TIME_FORMAT(work_end_time, '%H:%i') = ? AND push_token IS NOT NULL");
$stmtEnd->execute([$ten_mins_later]);
$endingEmployees = $stmtEnd->fetchAll();

foreach ($endingEmployees as $worker) {
    sendPush($worker['push_token'], "Ish vaqti yakunlanmoqda", "Salom " . $worker['full_name'] . ", 10 daqiqadan so'ng ish vaqtingiz yakunlanadi. Ilovada 'Chiqish' tugmasini bosish yodingizdan chiqmasin!");
}

echo "Checked for " . $ten_mins_later . ". Sent " . (count($startingEmployees) + count($endingEmployees)) . " notifications.\n";

// Automatic No-Show Fine Check
require_once 'check_noshows.php';
