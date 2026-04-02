<?php
require 'backend/config.php';

try {
    echo "Adding 'is_archived' to attendance...\n";
    $pdo->exec("ALTER TABLE attendance ADD COLUMN is_archived TINYINT(1) DEFAULT 0");
    echo "Done.\n";
} catch(Exception $e) { echo "Attendance error: " . $e->getMessage() . "\n"; }

try {
    echo "Adding 'is_archived' to fines...\n";
    $pdo->exec("ALTER TABLE fines ADD COLUMN is_archived TINYINT(1) DEFAULT 0");
    echo "Done.\n";
} catch(Exception $e) { echo "Fines error: " . $e->getMessage() . "\n"; }

try {
    echo "Adding 'is_archived' to payments...\n";
    $pdo->exec("ALTER TABLE payments ADD COLUMN is_archived TINYINT(1) DEFAULT 0");
    echo "Done.\n";
} catch(Exception $e) { echo "Payments error: " . $e->getMessage() . "\n"; }

echo "Migration finished.\n";
?>
