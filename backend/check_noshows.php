<?php
/**
 * No-show Fine Checker
 * This script checks for employees who did not show up for work until the end of their shift.
 * If no check-in and no approved off-day is found, it applies a 120,000 UZS fine.
 * Suggested run time: Every 30 minutes via cron.
 */

require_once 'config.php';
require_once 'api_functions.php';

$today = date('Y-m-d');
$now = date('H:i:s');
$timestamp = date('Y-m-d H:i:s');

// 1. Get all employees who have a defined end time and are not blocked
$stmt = $pdo->prepare("SELECT id, full_name, work_end_time, push_token FROM employees WHERE work_end_time IS NOT NULL AND work_end_time != '00:00:00' AND is_blocked = 0");
$stmt->execute();
$employees = $stmt->fetchAll();

$finesApplied = 0;

foreach ($employees as $emp) {
    $empId = $emp['id'];
    $workEndTime = $emp['work_end_time'];
    
    // Add 30 minutes buffer after work end time to check for no-shows
    $checkTime = date('H:i:s', strtotime($workEndTime . ' +30 minutes'));
    
    // Only check if current time is past the check time
    if ($now < $checkTime) {
        continue;
    }

    // 2. Check if already fined for no-show today
    $stmtFine = $pdo->prepare("SELECT id FROM fines WHERE employee_id = ? AND DATE(date) = ? AND reason LIKE 'Sababsiz ishga kelmaganlik%'");
    $stmtFine->execute([$empId, $today]);
    if ($stmtFine->fetch()) {
        continue; // Already fined
    }

    // 3. Check if checked-in today
    $stmtAtt = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND type = 'check-in' AND DATE(timestamp) = ?");
    $stmtAtt->execute([$empId, $today]);
    if ($stmtAtt->fetch()) {
        continue; // They showed up (maybe late, but showed up)
    }

    // 4. Check if has approved off-day request for today
    $stmtOff = $pdo->prepare("SELECT id FROM off_day_requests WHERE employee_id = ? AND request_date = ? AND status = 'approved'");
    $stmtOff->execute([$empId, $today]);
    if ($stmtOff->fetch()) {
        continue; // They have an approved day off
    }

    // 5. Check if has approved absence covering today
    $stmtAbs = $pdo->prepare("SELECT id FROM absences WHERE employee_id = ? AND status = 'approved' AND DATE(start_time) = ?");
    $stmtAbs->execute([$empId, $today]);
    if ($stmtAbs->fetch()) {
        continue; // They have an approved absence
    }

    // 6. Apply the fine!
    $amount = 120000;
    $reason = "Sababsiz ishga kelmaganlik uchun jarima (avtomatik)";
    
    try {
        $pdo->beginTransaction();
        
        // Insert fine
        $stmtIns = $pdo->prepare("INSERT INTO fines (employee_id, amount, reason, `date`, status) VALUES (?, ?, ?, ?, 'approved')");
        $stmtIns->execute([$empId, $amount, $reason, $timestamp]);
        
        // Update balance
        $stmtUpd = $pdo->prepare("UPDATE employees SET balance = balance - ? WHERE id = ?");
        $stmtUpd->execute([$amount, $empId]);
        
        $pdo->commit();
        
        $finesApplied++;
        
        // 6. Notify the employee
        if (!empty($emp['push_token'])) {
            sendPush($emp['push_token'], "Jarima urildi", "Bugun ishga kelmaganingiz sababli sizga 120,000 so'm jarima belgilandi.");
        }
        
        // 7. Notify admins (optional)
        sendExpoNotification("No-show Jarima", $emp['full_name'] . " ishga kelmadi, avtomatik jarima urildi.", $pdo);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        echo "Error fining employee $empId: " . $e->getMessage() . "\n";
    }
}

echo "Check completed for $today $now. Fines applied: $finesApplied\n";
