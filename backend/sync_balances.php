<?php
require_once 'config.php';

echo "<h2>Xodimlar balansini hisoblash va yangilash...</h2>\n";

try {
    // Barcha xodimlarni olish
    $stmt = $pdo->query("SELECT id, full_name, hourly_rate FROM employees");
    $employees = $stmt->fetchAll();

    foreach ($employees as $emp) {
        $empId = $emp['id'];
        $hRate = $emp['hourly_rate'];
        $fullName = $emp['full_name'];

        // 1. Ishlangan vaqtni hisoblash (Attendance records)
        $stmtAtt = $pdo->prepare("SELECT type, timestamp FROM attendance WHERE employee_id = ? ORDER BY timestamp ASC");
        $stmtAtt->execute([$empId]);
        $records = $stmtAtt->fetchAll();

        $totalSeconds = 0;
        $lastIn = null;

        foreach ($records as $r) {
            if ($r['type'] == 'check-in') {
                $lastIn = strtotime($r['timestamp']);
            } elseif ($r['type'] == 'check-out' && $lastIn) {
                $outTs = strtotime($r['timestamp']);
                $diff = $outTs - $lastIn;
                
                // 18 soatdan ortiq sessiyalarni hisobga olmaymiz (stale data)
                if ($diff > 0 && $diff < 18 * 3600) {
                    $totalSeconds += $diff;
                }
                $lastIn = null;
            }
        }

        $grossEarned = ($totalSeconds / 3600) * $hRate;

        // 2. Tasdiqlangan jarimalarni hisoblash
        $stmtF = $pdo->prepare("SELECT SUM(amount) FROM fines WHERE employee_id = ? AND status = 'approved'");
        $stmtF->execute([$empId]);
        $totalFines = $stmtF->fetchColumn() ?: 0;

        // 3. To'lovlarni (Avans/Oylik) hisoblash
        $stmtP = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE employee_id = ?");
        $stmtP->execute([$empId]);
        $totalPayments = $stmtP->fetchColumn() ?: 0;

        // 4. Yakuniy balans
        $finalBalance = round($grossEarned - $totalFines - $totalPayments);

        // 5. Bazada yangilash
        $upd = $pdo->prepare("UPDATE employees SET balance = ? WHERE id = ?");
        $upd->execute([$finalBalance, $empId]);

        echo "Xodim: <b>$fullName</b> | Ishlab topgan: " . number_format(round($grossEarned)) . " | Jarimalar: " . number_format($totalFines) . " | To'lovlar: " . number_format($totalPayments) . " | <b>Balans: " . number_format($finalBalance) . " UZS</b><br>\n";
    }

    echo "<h3>Barcha xodimlar balansi muvaffaqiyatli yangilandi!</h3>\n";

} catch (Exception $e) {
    echo "<b>Xatolik:</b> " . $e->getMessage();
}
?>
