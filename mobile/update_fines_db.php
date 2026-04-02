<?php
require_once 'backend/config.php';

$fines = [
    ['Kechikish (10-30 min)', 50000, '10 daqiqadan 30 daqiqagacha kechikish uchun'],
    ['Kechikish (30-60 min)', 80000, '30 daqiqadan 60 daqiqagacha kechikish uchun'],
    ['Kechikish (1soat+)', 120000, '1 soatdan ko\'p va qopol kechikish uchun'],
    ['Erta ketish 10-30daqiqa', 50000, 'Agarda ish joyidan ogohlantirishsiz erta ketsangiz ushbu jarima qo\'llaniladi'],
    ['Erta ketish (30-60 min)', 80000, 'Ogohlantirishsiz erta ketganlik uchun'],
    ['Erta ketish (1soat+)', 120000, 'Ogohlantirishsiz erta ketganlik uchun']
];

foreach ($fines as $f) {
    // Check if exists
    $stmt = $pdo->prepare("SELECT id FROM fine_types WHERE name = ?");
    $stmt->execute([$f[0]]);
    $id = $stmt->fetchColumn();
    
    if ($id) {
        $stmt = $pdo->prepare("UPDATE fine_types SET amount = ?, description = ? WHERE id = ?");
        $stmt->execute([$f[1], $f[2], $id]);
        echo "Updated: {$f[0]}<br>\n";
    } else {
        $stmt = $pdo->prepare("INSERT INTO fine_types (name, amount, description) VALUES (?, ?, ?)");
        $stmt->execute([$f[0], $f[1], $f[2]]);
        echo "Inserted: {$f[0]}<br>\n";
    }
}
?>
