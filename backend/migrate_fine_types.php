<?php
require_once 'config.php';

echo "Baza yangilanmoqda: Jarima turlari (fine_types) jadvalini yaratish...<br>\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS fine_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            amount DECIMAL(15, 2) NOT NULL,
            description TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
    ");
    echo "✅ fine_types jadvali muvaffaqiyatli yaratildi.<br>\n";
    
    // Add default fines for lateness if they don't exist
    $check = $pdo->query("SELECT COUNT(*) FROM fine_types")->fetchColumn();
    if ($check == 0) {
        $pdo->exec("
            INSERT INTO fine_types (name, amount, description) VALUES 
            ('Kechikish (10-30 min)', 70000, '10 daqiqadan 30 daqiqagacha kechikish uchun'),
            ('Kechikish (30-60 min)', 100000, '30 daqiqadan 60 daqiqagacha kechikish uchun');
        ");
        echo "✅ Standart jarima turlari qo'shildi.<br>\n";
    }

} catch (Exception $e) {
    echo "❌ Xatolik yuz berdi: " . $e->getMessage() . "<br>\n";
}
?>
