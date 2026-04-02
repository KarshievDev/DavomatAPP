<?php
require_once 'config.php';

echo "Baza yangilanmoqda: Jarimalar (fines) jadvaliga status ustunini qo'shish...<br>\n";

try {
    $pdo->exec("ALTER TABLE fines ADD COLUMN status ENUM('pending', 'applied', 'cancelled') DEFAULT 'pending';");
    echo "✅ Fines jadvaliga status jadvali muvaffaqiyatli qo'shildi.<br>\n";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "✅ Status ustuni allaqachon mavjud.<br>\n";
    } else {
        echo "❌ Xatolik yuz berdi: " . $e->getMessage() . "<br>\n";
    }
}
?>
