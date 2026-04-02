<?php
require_once 'backend/config.php';
$stmt = $pdo->query("SELECT id, full_name, role, push_token FROM employees WHERE role IN ('admin', 'superadmin')");
$admins = $stmt->fetchAll();
header('Content-Type: text/plain');
foreach ($admins as $a) {
    echo "ID: " . $a['id'] . " | Name: " . $a['full_name'] . " | Token: " . ($a['push_token'] ? "EXISTS (" . substr($a['push_token'], 0, 10) . "...)" : "EMPTY") . "\n";
}
