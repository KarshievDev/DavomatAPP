<?php
session_start();
require_once 'config.php';

header('Content-Type: application/json');

// Support multiple admin roles from Version 3 unified session
$user_role = $_SESSION['user']['role'] ?? $_SESSION['role'] ?? null;
$allowed_roles = ['superadmin', 'admin', 'manager'];
if (!$user_role || !in_array($user_role, $allowed_roles)) {
    echo json_encode(['status' => 'error', 'message' => 'Ruxsat berilmadi']);
    exit();
}

$employee_id = $_POST['employee_id'] ?? null;

if (!$employee_id) {
    echo json_encode(['status' => 'error', 'message' => 'Xodim ID topilmadi']);
    exit();
}

try {
    // 1. Get employee data
    $stmt = $pdo->prepare("SELECT id, full_name, branch_id, hourly_rate FROM employees WHERE id = ? AND is_blocked = 0");
    $stmt->execute([$employee_id]);
    $emp = $stmt->fetch();

    if (!$emp) {
        throw new Error("Xodim topilmadi yoki bloklangan");
    }

    // 2. Determine last status and date
    $last_stmt = $pdo->prepare("SELECT type, DATE(timestamp) as last_date FROM attendance WHERE employee_id = ? ORDER BY timestamp DESC LIMIT 1");
    $last_stmt->execute([$employee_id]);
    $last_log = $last_stmt->fetch();

    $today = date('Y-m-d');
    $last_date = $last_log['last_date'] ?? null;
    $last_type = $last_log['type'] ?? null;

    // "New Day" logic: If the last record was NOT today, always start with 'check-in'
    if ($last_date !== $today) {
        $next_type = 'check-in';
    } else {
        // Same day toggle: In -> Out -> In...
        $next_type = ($last_type === 'check-in') ? 'check-out' : 'check-in';
    }

    $action_label = ($next_type === 'check-in') ? "Ishga keldi" : "Ishdan ketdi";

    // 3. Save Image from Face ID snapshot
    $img_base64 = $_POST['image_base64'] ?? '';
    $image_path = null;
    if ($img_base64) {
        // saveImage is defined in config.php
        $image_path = saveImage($img_base64, 'face_' . $emp['id']); 
    }

    // 4. Save attendance
    $local_now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("
        INSERT INTO attendance (employee_id, type, timestamp, branch_id, hourly_rate, image_url)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$emp['id'], $next_type, $local_now, $emp['branch_id'], $emp['hourly_rate'], $image_path]);

    echo json_encode([
        'status' => 'success',
        'message' => "{$emp['full_name']} muvaffaqiyatli davomat qilindi.",
        'name' => $emp['full_name'],
        'action' => $next_type,
        'label' => $action_label,
        'time' => date('H:i')
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
