<?php
require_once 'config.php';
$stmt = $pdo->prepare("SELECT id, full_name, balance FROM employees WHERE full_name LIKE '%Abdumalik%'");
$stmt->execute();
$users = $stmt->fetchAll();
print_r($users);

if (count($users) > 0) {
    $uid = $users[0]['id'];
    echo "\nPayments for user $uid:\n";
    $stmtP = $pdo->prepare("SELECT * FROM payments WHERE employee_id = ?");
    $stmtP->execute([$uid]);
    print_r($stmtP->fetchAll());
    
    echo "\nFines for user $uid:\n";
    $stmtF = $pdo->prepare("SELECT * FROM fines WHERE employee_id = ? AND status = 'approved'");
    $stmtF->execute([$uid]);
    print_r($stmtF->fetchAll());
    
    echo "\nAttendance count for user $uid this month:\n";
    $startOfMonth = date('Y-m-01 00:00:00');
    $stmtA = $pdo->prepare("SELECT COUNT(*) FROM attendance WHERE employee_id = ? AND timestamp >= ?");
    $stmtA->execute([$uid, $startOfMonth]);
    echo $stmtA->fetchColumn() . "\n";
}
?>
