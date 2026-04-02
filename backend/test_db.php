<?php
require 'config.php';
header('Content-Type: text/plain');

$tables = ['attendance', 'fines', 'payments', 'absences'];
foreach ($tables as $t) {
    echo "Table: $t\n";
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$t` LIKE 'is_archived'");
        $col = $stmt->fetch();
        if ($col) {
            echo "  is_archived column FOUND.\n";
        } else {
            echo "  is_archived column NOT FOUND.\n";
            // Try to add it right here
            try {
                echo "  Attempting to add column via ALTER TABLE...\n";
                $pdo->exec("ALTER TABLE `$t` ADD COLUMN is_archived TINYINT(1) DEFAULT 0");
                echo "  SUCCESS.\n";
            } catch (Exception $e) {
                echo "  FAILED to add column: " . $e->getMessage() . "\n";
            }
        }
    } catch (Exception $e) {
        echo "  Error querying table: " . $e->getMessage() . "\n";
    }
}
?>
