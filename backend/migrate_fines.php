<?php
require_once 'config.php';

echo "Baza yangilanmoqda: Jarimalar (fines) jadvali...<br>\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            amount DECIMAL(15, 2) NOT NULL,
            reason TEXT,
            date DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        );
    ");
    echo "✅ Fines jadvali muvaffaqiyatli yaratildi.<br>\n";
} catch (Exception $e) {
    echo "❌ Xatolik yuz berdi: " . $e->getMessage() . "<br>\n";
}
?>
