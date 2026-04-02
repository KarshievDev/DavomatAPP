<?php
require 'backend/config.php';
$name = 'MASHXURBEK';
$stmt = $pdo->prepare("SELECT id, full_name FROM employees WHERE full_name LIKE ?");
$stmt->execute(["%$name%"]);
$emps = $stmt->fetchAll();
foreach ($emps as $e) {
    echo "ID: " . $e['id'] . " | Name: " . $e['full_name'] . "\n";
    $stmt2 = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? ORDER BY timestamp DESC LIMIT 10");
    $stmt2->execute([$e['id']]);
    $recs = $stmt2->fetchAll();
    foreach ($recs as $r) {
        echo "  " . $r['type'] . " at " . $r['timestamp'] . " (ID: " . $r['id'] . ")\n";
    }
}
?>
