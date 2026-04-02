<?php
ini_set('memory_limit', '256M');
set_time_limit(60);
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'php_error_log.txt');
require_once 'config.php';
require_once 'api_functions.php';

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With, X-Auth-Token");
header('Content-Type: application/json; charset=utf-8');
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);

    if (!$data || !isset($data['action'])) {
        echo json_encode(['error' => 'Notogri sorov']);
        exit;
    }

    $action = $data['action'];

    // Global Block Check (if employee ID is provided)
    $checkId = $data['employeeId'] ?? $data['employee_id'] ?? $data['id'] ?? null;
    if ($checkId && $action !== 'login') {
        $stmtB = $pdo->prepare("SELECT is_blocked FROM employees WHERE id = ?");
        $stmtB->execute([$checkId]);
        if ($stmtB->fetchColumn()) {
            echo json_encode(['error' => 'Hisobingiz bloklangan. Administratorga murojaat qiling!', 'is_blocked' => true]);
            exit;
        }
    }

    switch ($action) {
        case 'login':
            $email = strtolower($data['email'] ?? '');
            $password = $data['password'] ?? '';

            if (empty($email) || empty($password)) {
                echo json_encode(['error' => 'Email va parolni kiriting']);
                break;
            }

            $stmt = $pdo->prepare("SELECT e.*, r.permissions as role_permissions FROM employees e LEFT JOIN roles r ON e.role_id = r.id WHERE LOWER(e.email) = ? AND e.password = ?");
            $stmt->execute([$email, $password]);
            $user = $stmt->fetch();

            if ($user) {
                if ($user['is_blocked']) {
                    echo json_encode(['error' => 'Sizning hisobingiz bloklangan!']);
                    break;
                }
                unset($user['password']); 
                if ($user['role_permissions']) {
                    $user['permissions'] = json_decode($user['role_permissions'], true);
                }

                // Log Login
                $device = $data['device_name'] ?? $_SERVER['HTTP_USER_AGENT'] ?? 'Mobile App';
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $pdo->prepare("INSERT INTO login_logs (employee_id, device_info, ip_address) VALUES (?, ?, ?)")->execute([$user['id'], $device, $ip]);

                if ($user['image_url']) $user['image_url'] = getFullUrl($user['image_url']);
                if (!empty($user['profile_image'])) $user['profile_image'] = getFullUrl($user['profile_image']);
                echo json_encode($user);
            } else {
                echo json_encode(['error' => 'Login yoki parol xato']);
            }
            break;

        case 'save_push_token':
            $stmt = $pdo->prepare("UPDATE employees SET push_token = ? WHERE id = ?");
            $stmt->execute([
                $data['pushToken'] ?? $data['token'] ?? null,
                $data['employeeId'] ?? $data['id'] ?? null
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'get_branches':
            $stmt = $pdo->query("SELECT * FROM branches");
            echo json_encode($stmt->fetchAll());
            break;

        case 'save_branch':
            $stmt = $pdo->prepare("INSERT INTO branches (name, latitude, longitude, radius) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $data['name'],
                $data['latitude'],
                $data['longitude'],
                $data['radius'] ?? 200
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'update_branch':
            $stmt = $pdo->prepare("UPDATE branches SET name=?, latitude=?, longitude=?, radius=? WHERE id=?");
            $stmt->execute([
                $data['name'],
                $data['latitude'],
                $data['longitude'],
                $data['radius'] ?? 200,
                $data['id']
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'delete_branch':
            $stmt = $pdo->prepare("DELETE FROM branches WHERE id=?");
            $stmt->execute([$data['id']]);
            echo json_encode(['success' => true]);
            break;

        case 'get_employees':
            $stmt = $pdo->query("SELECT e.*, r.permissions as role_permissions FROM employees e LEFT JOIN roles r ON e.role_id = r.id");
            $res = $stmt->fetchAll();
            foreach ($res as &$e) {
                if ($e['role_permissions']) {
                    $e['permissions'] = json_decode($e['role_permissions'], true);
                }
                if ($e['image_url']) $e['image_url'] = getFullUrl($e['image_url']);
                if (!empty($e['profile_image'])) $e['profile_image'] = getFullUrl($e['profile_image']);
            }
            echo json_encode($res);
            break;

        case 'get_records':
            $stmt = $pdo->query("SELECT id, employee_id, type, timestamp, latitude, longitude, branch_id, image_url FROM attendance ORDER BY timestamp DESC LIMIT 1000");
            $records = $stmt->fetchAll();
            foreach ($records as &$r) {
                $r['image_url'] = getFullUrl($r['image_url']);
            }
            echo json_encode($records);
            break;

        case 'get_absences':
            $stmt = $pdo->query("SELECT * FROM absences ORDER BY start_time DESC");
            echo json_encode($stmt->fetchAll());
            break;

        case 'save_record':
            $lat = $data['latitude'] ?? 0;
            $lon = $data['longitude'] ?? 0;
            $brId = $data['branchId'] ?? $data['branch_id'] ?? null;
            $type = $data['type'] ?? null;
            $empIdToUse = $data['employeeId'] ?? $data['employee_id'] ?? null;

            // --- OFFLINE SYNC SUPPORT ---
            // Allow client to send its own timestamp if it was recorded offline
            $timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');
            $actionDate = date('Y-m-d', strtotime($timestamp));

            // Prevent duplicate syncs (very common in offline mobile apps)
            $stmtDup = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND `type` = ? AND ABS(TIMESTAMPDIFF(SECOND, `timestamp`, ?)) < 5");
            $stmtDup->execute([$empIdToUse, $type, $timestamp]);
            if ($stmtDup->fetch()) {
                echo json_encode(['success' => true, 'note' => 'Duplicate ignored', 'timestamp' => $timestamp]);
                break;
            }
            // ---------------------------

            $branches = $pdo->query("SELECT * FROM branches")->fetchAll();
            $isNearAny = false;
            $closestDist = 999999;
            $actualBrId = $brId;

            foreach ($branches as $b) {
                $d = getDistance($lat, $lon, $b['latitude'], $b['longitude']);
                if ($d < $closestDist) {
                    $closestDist = $d;
                    if ($d <= $b['radius'] + 100) { 
                        $actualBrId = $b['id'];
                    }
                }
                if ($d <= $b['radius']) {
                    $isNearAny = true;
                }
            }
            
            if ($type == 'check-in' && !$isNearAny) {
                echo json_encode(['error' => 'Hech qaysi filial hududida emassiz (Masofa: ' . round($closestDist) . 'm)']);
                break;
            }

            if ($type == 'check-out' && $closestDist > 500) {
               $actualBrId = $brId; 
            }

            $brId = $actualBrId;
            
            // --- JARIMA HISOBLASH LOGIKASI ---
            if ($type == 'check-in' && $empIdToUse) {
                // Use actionDate instead of today() for offline sync
                $stmtCheck = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND `type` = 'check-in' AND DATE(`timestamp`) = ?");
                $stmtCheck->execute([$empIdToUse, $actionDate]);
                $isFirst = !$stmtCheck->fetch();

                if ($isFirst) {
                    $stmtEmp = $pdo->prepare("SELECT full_name, work_start_time FROM employees WHERE id = ?");
                    $stmtEmp->execute([$empIdToUse]);
                    $emp = $stmtEmp->fetch();
                    if ($emp && !empty($emp['work_start_time']) && $emp['work_start_time'] != '00:00:00') { 
                        $startTime = $actionDate . ' ' . $emp['work_start_time'];
                        $diffMinutes = (strtotime($timestamp) - strtotime($startTime)) / 60;
                        
                        if ($diffMinutes >= 10) {
                            $fineType = 'Kechikish';
                            if ($diffMinutes < 30) { $fineType = 'Kechikish (10-30 min)'; $defaultAmount = 50000; }
                            elseif ($diffMinutes < 60) { $fineType = 'Kechikish (30-60 min)'; $defaultAmount = 80000; }
                            else { $fineType = 'Kechikish (1soat+)'; $defaultAmount = 120000; }
                            
                            $stmtType = $pdo->prepare("SELECT amount FROM fine_types WHERE name = ? LIMIT 1");
                            $stmtType->execute([$fineType]);
                            $typeData = $stmtType->fetch();
                            $amount = $typeData ? $typeData['amount'] : $defaultAmount;
                            
                            $stmtWarn = $pdo->prepare("SELECT id FROM lateness_warnings WHERE employee_id = ? AND DATE(timestamp) = ?");
                            $stmtWarn->execute([$empIdToUse, $actionDate]);
                            $fineStatus = $stmtWarn->fetch() ? 'rejected' : 'approved';
                            
                            $stmtFine = $pdo->prepare("INSERT INTO fines (employee_id, amount, reason, `date`, status) VALUES (?, ?, ?, ?, ?)");
                            $stmtFine->execute([$empIdToUse, $amount, "Ishga ".round($diffMinutes)." daqiqa kechikkanlik uchun jarima", $timestamp, $fineStatus]);
                            
                            if ($fineStatus == 'approved') {
                                $pdo->prepare("UPDATE employees SET balance = balance - ? WHERE id = ?")->execute([$amount, $empIdToUse]);
                            }
                            
                            sendExpoNotification("Yangi kechikish (Jarima)", $emp['full_name'] . " ishga kechikib keldi. Jarimani ko'rib chiqing.", $pdo);
                        }
                    }
                }
            }
            
            $imageData = $data['image'] ?? $data['image_url'] ?? null;
            $img_url = saveImage($imageData, 'att_' . ($empIdToUse ?? '0'));

            $stmtEmpty = $pdo->prepare("SELECT hourly_rate FROM employees WHERE id = ?");
            $stmtEmpty->execute([$empIdToUse]);
            $currentHourlyRate = $stmtEmpty->fetchColumn() ?: 0;

            $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, `type`, `timestamp`, latitude, longitude, branch_id, image_url, hourly_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $empIdToUse,
                $type,
                $timestamp,
                $lat,
                $lon,
                $brId,
                $img_url,
                $currentHourlyRate
            ]);

            // --- BALANCE UPDATE ON CHECK-OUT ---
            if ($type == 'check-out' && $empIdToUse) {
                $stmtIn = $pdo->prepare("SELECT `timestamp` FROM attendance WHERE employee_id = ? AND `type` = 'check-in' AND `timestamp` < ? AND `timestamp` > DATE_SUB(?, INTERVAL 18 HOUR) ORDER BY `timestamp` DESC LIMIT 1");
                $stmtIn->execute([$empIdToUse, $timestamp, $timestamp]);
                $lastIn = $stmtIn->fetch();
                if ($lastIn) {
                    $inTime = strtotime($lastIn['timestamp']);
                    $outTime = strtotime($timestamp);
                    $workMins = ($outTime - $inTime) / 60;
                    if ($workMins > 0) {
                        $stmtEmpInfo = $pdo->prepare("SELECT full_name, work_end_time, work_start_time, hourly_rate FROM employees WHERE id = ?");
                        $stmtEmpInfo->execute([$empIdToUse]);
                        $emp = $stmtEmpInfo->fetch();
                        $hRate = $emp['hourly_rate'] ?? 0;
                        
                        $earned = round(($workMins / 60) * $hRate);
                        if ($earned > 0) {
                            $pdo->prepare("UPDATE employees SET balance = balance + ? WHERE id = ?")->execute([$earned, $empIdToUse]);
                        }

                        // Early Departure Check
                        if ($emp && !empty($emp['work_end_time']) && $emp['work_end_time'] != '00:00:00') {
                            $endTimeStr = date('Y-m-d', $inTime) . ' ' . $emp['work_end_time'];
                            $expectedEnd = strtotime($endTimeStr);
                            $workStart = $emp['work_start_time'] ?? '09:00:00';
                            if (strtotime($emp['work_end_time']) <= strtotime($workStart)) { $expectedEnd += 86400; }

                            if ($outTime < $expectedEnd - 60) {
                                $earlyMins = ($expectedEnd - $outTime) / 60;
                                if ($earlyMins >= 10) {
                                    $fineType = 'Erta ketish';
                                    if ($earlyMins < 30) { $fineType = 'Erta ketish 10-30daqiqa'; $defaultAmount = 50000; }
                                    elseif ($earlyMins < 60) { $fineType = 'Erta ketish (30-60 min)'; $defaultAmount = 80000; }
                                    else { $fineType = 'Erta ketish (1soat+)'; $defaultAmount = 120000; }
                                    
                                    $stmtType = $pdo->prepare("SELECT amount FROM fine_types WHERE name = ? LIMIT 1");
                                    $stmtType->execute([$fineType]);
                                    $typeData = $stmtType->fetch();
                                    $fineAmount = $typeData ? $typeData['amount'] : $defaultAmount;
                                    
                                    if ($fineAmount > 0) {
                                        $stmtWarn = $pdo->prepare("SELECT id FROM lateness_warnings WHERE employee_id = ? AND DATE(timestamp) = ?");
                                        $stmtWarn->execute([$empIdToUse, $actionDate]);
                                        $fineStatus = $stmtWarn->fetch() ? 'rejected' : 'approved';
                                        $stmtFine = $pdo->prepare("INSERT INTO fines (employee_id, amount, reason, `date`, status) VALUES (?, ?, ?, ?, ?)");
                                        $stmtFine->execute([$empIdToUse, $fineAmount, htmlspecialchars($fineType) . " (" . round($earlyMins) . " daqiqa)", $timestamp, $fineStatus]);
                                        if ($fineStatus == 'approved') {
                                            $pdo->prepare("UPDATE employees SET balance = balance - ? WHERE id = ?")->execute([$fineAmount, $empIdToUse]);
                                        }
                                        sendExpoNotification("Vaqtli ketish", $emp['full_name'] . " ishdan vaqtli ketdi (" . round($earlyMins) . " daq).", $pdo);
                                    }
                                }
                            }
                        }
                    }
                }
            }

            echo json_encode(['success' => true, 'timestamp' => $timestamp]);
            break;

        case 'save_absence':
            $empId = $data['employeeId'] ?? $data['employee_id'] ?? null;
            $brId = $data['branchId'] ?? $data['branch_id'] ?? null;
            
            if (!$empId) {
                echo json_encode(['error' => 'Employee ID topilmadi']);
                break;
            }

            // Check if there's already an active (un-ended) absence for this employee
            $stmtCheck = $pdo->prepare("SELECT id FROM absences WHERE employee_id = ? AND end_time IS NULL ORDER BY start_time DESC LIMIT 1");
            $stmtCheck->execute([$empId]);
            $existing = $stmtCheck->fetch();

            if ($existing) {
                // Already has an active absence, return that ID instead of creating a new one
                echo json_encode(['id' => $existing['id'], 'already_active' => true]);
                break;
            }

            $stmt = $pdo->prepare("INSERT INTO absences (employee_id, branch_id, start_time, duration_minutes, status) VALUES (?, ?, ?, 0, 'pending')");
            $stmt->execute([
                $empId,
                $brId,
                $data['startTime'] ?? $data['start_time'] ?? date('Y-m-d H:i:s')
            ]);
            
            $newId = $pdo->lastInsertId();
            $stmtName = $pdo->prepare("SELECT full_name FROM employees WHERE id = ?");
            $stmtName->execute([$empId]);
            $empName = $stmtName->fetchColumn();
            sendExpoNotification("Yangi uzoqlashish", "$empName uzoqlashish uchun so'rov yubordi", $pdo);

            echo json_encode(['id' => $newId]);
            break;

        case 'end_absence':
            $id = $data['id'] ?? null;
            $branch_id = $data['branch_id'] ?? null;
            if (!$id) {
                echo json_encode(['error' => 'ID topilmadi']);
                break;
            }
            $endTime = date('Y-m-d H:i:s');
            
            // Get employee ID from the absence record to cleanup others
            $stmt = $pdo->prepare("SELECT employee_id, start_time FROM absences WHERE id = ?");
            $stmt->execute([$id]);
            $abs = $stmt->fetch();
            
            if ($abs) {
                $empId = $abs['employee_id'];
                
                // Close the specific one
                $start = strtotime($abs['start_time']);
                $end = strtotime($endTime);
                $duration = round(($end - $start) / 60);
                $stmt = $pdo->prepare("UPDATE absences SET end_time = ?, duration_minutes = ? WHERE id = ?");
                $stmt->execute([$endTime, $duration, $id]);

                if ($branch_id) {
                    // Update active session branch
                    $stmtSession = $pdo->prepare("UPDATE attendance 
                        SET branch_id = ? 
                        WHERE employee_id = ? AND type = 'check-in' 
                        AND timestamp > DATE_SUB(NOW(), INTERVAL 16 HOUR)
                        ORDER BY timestamp DESC LIMIT 1");
                    $stmtSession->execute([$branch_id, $empId]);
                }
                
                $stmtOther = $pdo->prepare("UPDATE absences SET end_time = ?, duration_minutes = ROUND((UNIX_TIMESTAMP(?) - UNIX_TIMESTAMP(start_time))/60) WHERE employee_id = ? AND end_time IS NULL AND id != ?");
                $stmtOther->execute([$endTime, $endTime, $empId, $id]);
                
                echo json_encode(['success' => true, 'duration' => $duration]);
            } else {
                echo json_encode(['error' => 'Uzoqlashish topilmadi']);
            }
            break;

        case 'get_fine_types':
            $stmt = $pdo->query("SELECT * FROM fine_types");
            echo json_encode($stmt->fetchAll());
            break;

        case 'save_fine_type':
            $stmt = $pdo->prepare("INSERT INTO fine_types (name, amount, description) VALUES (?, ?, ?)");
            $stmt->execute([
                $data['name'],
                $data['amount'],
                $data['description'] ?? ''
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'update_fine_type':
            $stmt = $pdo->prepare("UPDATE fine_types SET name=?, amount=?, description=? WHERE id=?");
            $stmt->execute([
                $data['name'],
                $data['amount'],
                $data['description'] ?? '',
                $data['id']
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'delete_fine_type':
            $stmt = $pdo->prepare("DELETE FROM fine_types WHERE id=?");
            $stmt->execute([$data['id']]);
            echo json_encode(['success' => true]);
            break;

        case 'update_absence_status':
            $status = $data['status'];
            $id = $data['id'];
            
            $stmt = $pdo->prepare("UPDATE absences SET status = ? WHERE id = ?");
            $stmt->execute([$status, $id]);

            if ($status == 'rejected') {
                $stmt_a = $pdo->prepare("SELECT a.*, e.hourly_rate FROM absences a JOIN employees e ON a.employee_id = e.id WHERE a.id = ?");
                $stmt_a->execute([$id]);
                $abs = $stmt_a->fetch();
                if ($abs) {
                    $fine_amount = round(($abs['duration_minutes'] / 60) * $abs['hourly_rate']);
                    if ($fine_amount > 0) {
                        $stmt_f = $pdo->prepare("INSERT INTO fines (employee_id, amount, reason, date, status) VALUES (?, ?, ?, ?, 'approved')");
                        $stmt_f->execute([
                            $abs['employee_id'], 
                            $fine_amount, 
                            "Uzoqlashish rad etilganligi sababli chegirish (" . $abs['duration_minutes'] . " daqiqa)", 
                            date('Y-m-d H:i:s'),
                        ]);
                        // Deduct from balance
                        $pdo->prepare("UPDATE employees SET balance = balance - ? WHERE id = ?")->execute([$fine_amount, $abs['employee_id']]);
                    }
                }
            }
            echo json_encode(['success' => true]);
            break;

        case 'save_employee':
            $salary = $data['monthlySalary'] ?? $data['monthly_salary'] ?? 0;
            $days = $data['workDays'] ?? $data['work_days_per_month'] ?? 26;
            $start = $data['workStartTime'] ?? $data['work_start_time'] ?? '09:00:00';
            $end = $data['workEndTime'] ?? $data['work_end_time'] ?? '18:00:00';
            
            $start_ts = strtotime($start);
            $end_ts = strtotime($end);
            if ($end_ts <= $start_ts) {
                $end_ts += 86400; // Add 24 hours
            }
            $daily_hours = ($end_ts - $start_ts) / 3600;
            $hourly_rate = ($daily_hours > 0 && $days > 0) ? ($salary / ($daily_hours * $days)) : 0;

            $off_type = $data['off_day_type'] ?? 'custom';
            $stmt = $pdo->prepare("INSERT INTO employees (full_name, phone, email, role, role_id, branch_id, position, hourly_rate, monthly_salary, work_days_per_month, work_start_time, work_end_time, off_day_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['fullName'] ?? $data['full_name'],
                $data['phone'],
                $data['email'],
                $data['role'],
                $data['role_id'] ?? null,
                $data['branchId'] ?? $data['branch_id'],
                $data['position'],
                $hourly_rate,
                $salary,
                $days,
                $start,
                $end,
                $off_type
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'delete_employee':
            $stmt = $pdo->prepare("DELETE FROM employees WHERE id=?");
            $stmt->execute([$data['id']]);
            echo json_encode(['success' => true]);
            break;

        case 'update_password':
            $stmt = $pdo->prepare("UPDATE employees SET password=? WHERE id=?");
            $stmt->execute([
                $data['newPass'] ?? $data['password'],
                $data['employeeId'] ?? $data['id']
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'update_employee':
            $salary = $data['monthlySalary'] ?? $data['monthly_salary'] ?? 0;
            $days = $data['workDays'] ?? $data['work_days_per_month'] ?? 26;
            $start = $data['workStartTime'] ?? $data['work_start_time'] ?? '09:00:00';
            $end = $data['workEndTime'] ?? $data['work_end_time'] ?? '18:00:00';
            
            $start_ts = strtotime($start);
            $end_ts = strtotime($end);
            if ($end_ts <= $start_ts) {
                $end_ts += 86400; // Add 24 hours if end time is before or same as start
            }
            $daily_hours = ($end_ts - $start_ts) / 3600;
            $hourly_rate = ($daily_hours > 0 && $days > 0) ? ($salary / ($daily_hours * $days)) : 0;

            $off_type = $data['off_day_type'] ?? 'custom';
            $stmt = $pdo->prepare("UPDATE employees SET full_name=?, phone=?, email=?, role=?, role_id=?, branch_id=?, position=?, hourly_rate=?, monthly_salary=?, work_days_per_month=?, work_start_time=?, work_end_time=?, off_day_type=? WHERE id=?");
            $stmt->execute([
                $data['fullName'] ?? $data['full_name'],
                $data['phone'],
                $data['email'],
                $data['role'],
                $data['role_id'] ?? null,
                $data['branchId'] ?? $data['branch_id'],
                $data['position'],
                $hourly_rate,
                $salary,
                $days,
                $start,
                $end,
                $off_type,
                $data['id']
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'get_all_data':
            $branches = $pdo->query("SELECT * FROM branches")->fetchAll();
            $employees = $pdo->query("SELECT e.*, r.permissions as role_permissions FROM employees e LEFT JOIN roles r ON e.role_id = r.id WHERE e.is_blocked = 0")->fetchAll();
            foreach ($employees as &$e) {
                if ($e['role_permissions']) {
                    $e['permissions'] = json_decode($e['role_permissions'], true);
                }
                if ($e['image_url']) $e['image_url'] = getFullUrl($e['image_url']);
            }
            // Optimization: Only last 50 unarchived records for general view
            $records = $pdo->query("SELECT a.*, e.full_name, b.name as branch_name FROM attendance a JOIN employees e ON a.employee_id = e.id LEFT JOIN branches b ON a.branch_id = b.id WHERE a.is_archived = 0 ORDER BY a.`timestamp` DESC LIMIT 50")->fetchAll();
            foreach ($records as &$r) {
                $r['image_url'] = getFullUrl($r['image_url'] ?? '');
            }
            $absences = $pdo->query("SELECT * FROM absences WHERE is_archived = 0 AND status = 'pending' ORDER BY start_time DESC")->fetchAll();
            $payments = $pdo->query("SELECT * FROM payments WHERE is_archived = 0 ORDER BY created_at DESC LIMIT 50")->fetchAll();
            $offDayRequests = $pdo->query("SELECT * FROM off_day_requests WHERE status = 'pending' ORDER BY created_at DESC")->fetchAll();
            $roles = $pdo->query("SELECT * FROM roles")->fetchAll();
            foreach ($roles as &$r) {
                $r['permissions'] = json_decode($r['permissions'], true);
            }
            
            $fines = [];
            try {
                $fines = $pdo->query("SELECT * FROM fines WHERE is_archived = 0 AND status = 'approved' AND `date` >= DATE_SUB(NOW(), INTERVAL 30 DAY) ORDER BY `date` DESC LIMIT 100")->fetchAll();
            } catch (Exception $e) { $fines = []; }
            
            $warnings = $pdo->query("SELECT * FROM lateness_warnings WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY `timestamp` DESC")->fetchAll();
            $fineTypes = $pdo->query("SELECT * FROM fine_types")->fetchAll();
            $tasks = $pdo->query("SELECT * FROM tasks WHERE status != 'completed' ORDER BY created_at DESC LIMIT 50")->fetchAll();

            echo json_encode([
                'branches' => $branches,
                'employees' => $employees,
                'records' => $records,
                'absences' => $absences,
                'payments' => $payments,
                'offDayRequests' => $offDayRequests,
                'roles' => $roles,
                'fines' => $fines,
                'fineTypes' => $fineTypes,
                'warnings' => $warnings,
                'tasks' => $tasks
            ]);
            break;

        case 'get_employee_report':
            // Dedicated endpoint for iOS employee reporting screen
            $empId = $data['employeeId'] ?? $data['employee_id'] ?? null;
            if (!$empId) {
                echo json_encode(['error' => 'Employee ID topilmadi']);
                break;
            }

            // Determine month/year to report on
            $repYear  = $data['year']  ?? date('Y');
            $repMonth = $data['month'] ?? date('m');
            $monthStart = sprintf('%04d-%02d-01 00:00:00', $repYear, $repMonth);
            $monthEnd   = date('Y-m-t 23:59:59', strtotime($monthStart));

            // 1. Employee info
            $stmtEmp = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
            $stmtEmp->execute([$empId]);
            $empInfo = $stmtEmp->fetch(PDO::FETCH_ASSOC);
            if (!$empInfo) {
                echo json_encode(['error' => 'Xodim topilmadi']);
                break;
            }

            // 2. Attendance records (unarchived, current month)
            $stmtAtt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND is_archived = 0 AND timestamp BETWEEN ? AND ? ORDER BY timestamp ASC");
            $stmtAtt->execute([$empId, $monthStart, $monthEnd]);
            $attRecs = $stmtAtt->fetchAll(PDO::FETCH_ASSOC);

            // 3. Approved absences for this period
            $stmtAbs = $pdo->prepare("SELECT start_time, end_time, duration_minutes FROM absences WHERE employee_id = ? AND status = 'approved' AND start_time >= ? AND start_time <= ?");
            $stmtAbs->execute([$empId, $monthStart, $monthEnd]);
            $absences_rep = $stmtAbs->fetchAll(PDO::FETCH_ASSOC);

            // 4. Calculate worked sessions
            $totalMins = 0;
            $workedDays = [];
            $lastIn = null;
            $sessions = [];
            $hourlyRate = $empInfo['hourly_rate'] ?? 0;

            foreach ($attRecs as $rec) {
                if ($rec['type'] == 'check-in') {
                    $lastIn = $rec;
                } elseif ($rec['type'] == 'check-out' && $lastIn) {
                    $inTs  = strtotime($lastIn['timestamp']);
                    $outTs = strtotime($rec['timestamp']);
                    $dur   = ($outTs - $inTs) / 60;

                    if ($dur > 0 && $dur < 2880) { // sanity check
                        // Deduct approved absences
                        $deduction = 0;
                        foreach ($absences_rep as $ab) {
                            $absStart = strtotime($ab['start_time']);
                            $absEnd   = $ab['end_time'] ? strtotime($ab['end_time']) : time();
                            $overlapStart = max($inTs, $absStart);
                            $overlapEnd   = min($outTs, $absEnd);
                            if ($overlapStart < $overlapEnd) {
                                $deduction += ($overlapEnd - $overlapStart) / 60;
                            }
                        }
                        $netMins = max(0, $dur - $deduction);
                        $totalMins += $netMins;

                        $dayKey = date('Y-m-d', $inTs);
                        $workedDays[$dayKey] = 1;
                        $sessions[] = [
                            'date'    => $dayKey,
                            'in'      => $lastIn['timestamp'],
                            'out'     => $rec['timestamp'],
                            'in_img'  => $lastIn['image_url'] ?? null,
                            'out_img' => $rec['image_url'] ?? null,
                            'dur_mins'=> round($netMins),
                        ];
                    }
                    $lastIn = null;
                }
            }

            // 5. Fines (approved, unarchived, this month)
            $stmtFines = $pdo->prepare("SELECT SUM(amount) as total, COUNT(*) as count FROM fines WHERE employee_id = ? AND status = 'approved' AND is_archived = 0 AND `date` BETWEEN ? AND ?");
            $stmtFines->execute([$empId, $monthStart, $monthEnd]);
            $fineRow = $stmtFines->fetch(PDO::FETCH_ASSOC);

            // 6. Payments / advances (unarchived, this month)
            $stmtPay = $pdo->prepare("SELECT SUM(amount) as total, COUNT(*) as count FROM payments WHERE employee_id = ? AND is_archived = 0 AND created_at BETWEEN ? AND ?");
            $stmtPay->execute([$empId, $monthStart, $monthEnd]);
            $payRow = $stmtPay->fetch(PDO::FETCH_ASSOC);

            // 7. Approved off-days count this month
            $stmtOff = $pdo->prepare("SELECT COUNT(*) FROM off_day_requests WHERE employee_id = ? AND status = 'approved' AND request_date BETWEEN ? AND ?");
            $stmtOff->execute([$empId, substr($monthStart,0,10), substr($monthEnd,0,10)]);
            $offDaysCount = (int)$stmtOff->fetchColumn();

            // 8. Plan (fixed, not month-length dependent)
            $planOff  = (int)($empInfo['off_days_per_month'] ?? (30 - ($empInfo['work_days_per_month'] ?? 26)));
            $planWork = max(0, 30 - $planOff);

            echo json_encode([
                'employee'         => [
                    'id'             => $empInfo['id'],
                    'full_name'      => $empInfo['full_name'],
                    'monthly_salary' => (float)$empInfo['monthly_salary'],
                    'balance'        => (float)$empInfo['balance'],
                    'hourly_rate'    => (float)$hourlyRate,
                    'work_start'     => $empInfo['work_start_time'],
                    'work_end'       => $empInfo['work_end_time'],
                ],
                'period'           => sprintf('%04d-%02d', $repYear, $repMonth),
                'worked_minutes'   => round($totalMins),
                'worked_hours'     => round($totalMins / 60, 1),
                'worked_days'      => count($workedDays),
                'plan_work_days'   => $planWork,
                'plan_off_days'    => $planOff,
                'off_days_taken'   => $offDaysCount,
                'gross_salary'     => round(($totalMins / 60) * $hourlyRate),
                'fines_total'      => (int)($fineRow['total'] ?? 0),
                'fines_count'      => (int)($fineRow['count'] ?? 0),
                'advances_total'   => (int)($payRow['total'] ?? 0),
                'advances_count'   => (int)($payRow['count'] ?? 0),
                'absence_minutes'  => array_sum(array_column($absences_rep, 'duration_minutes')),
                'sessions'         => $sessions,
            ]);
            break;

        case 'get_payments':
            $stmt = $pdo->query("SELECT * FROM payments ORDER BY created_at DESC");
            echo json_encode($stmt->fetchAll());
            break;

        case 'save_payment':
            $stmt = $pdo->prepare("INSERT INTO payments (employee_id, amount, type, comment, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $data['employeeId'] ?? $data['employee_id'],
                $data['amount'],
                $data['type'] ?? 'advance',
                $data['comment'] ?? '',
                $data['createdBy'] ?? $data['created_by'] ?? null
            ]);
            
            // Update employee balance
            $empIdToUpd = $data['employeeId'] ?? $data['employee_id'];
            $pdo->prepare("UPDATE employees SET balance = balance - ? WHERE id = ?")->execute([$data['amount'], $empIdToUpd]);

            echo json_encode(['success' => true]);
            break;

        case 'save_off_day_request':
            $reqDate = $data['requestDate'];

            $stmt = $pdo->prepare("INSERT INTO off_day_requests (employee_id, request_date, reason) VALUES (?, ?, ?)");
            $stmt->execute([
                $data['employeeId'],
                $reqDate,
                $data['reason']
            ]);
            
            $empId = $data['employeeId'];
            $stmtName = $pdo->prepare("SELECT full_name FROM employees WHERE id = ?");
            $stmtName->execute([$empId]);
            $empName = $stmtName->fetchColumn();
            sendExpoNotification("Dam olish kuni so'rovi", "$empName $reqDate kuni uchun dam olish so'radi", $pdo);

            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        case 'get_off_day_requests':
            $stmt = $pdo->query("SELECT * FROM off_day_requests ORDER BY created_at DESC");
            echo json_encode($stmt->fetchAll());
            break;

        case 'update_off_day_request_status':
            $stmt = $pdo->prepare("UPDATE off_day_requests SET status = ? WHERE id = ?");
            $stmt->execute([
                $data['status'],
                $data['id']
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'get_offday_quota':
            $empId = $data['employeeId'] ?? $data['id'] ?? null;
            if (!$empId) {
                echo json_encode(['error' => 'Employee ID topilmadi']);
                break;
            }

            // Get employee work days per month
            $stmtEmp = $pdo->prepare("SELECT work_days_per_month FROM employees WHERE id = ?");
            $stmtEmp->execute([$empId]);
            $empInfo = $stmtEmp->fetch();
            $workDaysPerMonth = ($empInfo['work_days_per_month'] ?? 26) ?: 26;

            $daysInMonth = date('t');
            $allowedOffDays = max(0, $daysInMonth - $workDaysPerMonth);

            // Count approved off-days for this month
            $stmtTaken = $pdo->prepare("SELECT COUNT(*) FROM off_day_requests WHERE employee_id = ? AND status = 'approved' AND MONTH(request_date) = MONTH(CURRENT_DATE) AND YEAR(request_date) = YEAR(CURRENT_DATE)");
            $stmtTaken->execute([$empId]);
            $takenOffDays = $stmtTaken->fetchColumn() ?: 0;

            $remainingOffDays = max(0, $allowedOffDays - $takenOffDays);

            echo json_encode([
                'allowedOffDays' => $allowedOffDays,
                'takenOffDays' => $takenOffDays,
                'remainingOffDays' => $remainingOffDays,
                'workDaysPerMonth' => $workDaysPerMonth
            ]);
            break;

        case 'get_roles':
            $stmt = $pdo->query("SELECT * FROM roles");
            $res = $stmt->fetchAll();
            foreach ($res as &$r) {
                $r['permissions'] = json_decode($r['permissions'], true);
            }
            echo json_encode($res);
            break;

        case 'save_role':
            $stmt = $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)");
            $stmt->execute([
                $data['name'],
                json_encode($data['permissions'])
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        case 'update_role':
            $stmt = $pdo->prepare("UPDATE roles SET name=?, permissions=? WHERE id=?");
            $stmt->execute([
                $data['name'],
                json_encode($data['permissions']),
                $data['id']
            ]);
            echo json_encode(['success' => true]);
            break;

        case 'delete_role':
            $stmt = $pdo->prepare("DELETE FROM roles WHERE id=?");
            $stmt->execute([$data['id']]);
            echo json_encode(['success' => true]);
            break;

        case 'get_warnings':
            $stmt = $pdo->query("SELECT * FROM lateness_warnings ORDER BY `timestamp` DESC");
            echo json_encode($stmt->fetchAll());
            break;

        case 'update_fine_status':
            $fineId = $data['id'] ?? null;
            $status = $data['status'] ?? null;
            
            if (!$fineId || !$status) {
                echo json_encode(['error' => 'Ma\'lumotlar yetarli emas']);
                break;
            }

            $stmt = $pdo->prepare("SELECT * FROM fines WHERE id = ?");
            $stmt->execute([$fineId]);
            $fine = $stmt->fetch();

            if ($fine) {
                // If approving a previously unapproved fine, subtract from balance
                if ($fine['status'] !== 'approved' && $status === 'approved') {
                    $pdo->prepare("UPDATE employees SET balance = balance - ? WHERE id = ?")->execute([$fine['amount'], $fine['employee_id']]);
                }
                // If rejecting a previously approved fine (changing approved to rejected), add back to balance
                elseif ($fine['status'] === 'approved' && $status === 'rejected') {
                    $pdo->prepare("UPDATE employees SET balance = balance + ? WHERE id = ?")->execute([$fine['amount'], $fine['employee_id']]);
                }

                $stmt = $pdo->prepare("UPDATE fines SET status = ? WHERE id = ?");
                $stmt->execute([$status, $fineId]);
                echo json_encode(['success' => true]);
            } else {
                echo json_encode(['error' => 'Jarima topilmadi']);
            }
            break;

        case 'update_attendance_time':
            $attId = $data['attendanceId'] ?? null;
            $newTime = $data['newTime'] ?? null;
            $adminId = $data['adminId'] ?? null;
            $reason = $data['reason'] ?? '';

            if (!$attId || !$newTime || !$adminId) {
                echo json_encode(['error' => 'Ma\'lumotlar yetarli emas']);
                break;
            }

            // check if admin is superadmin
            $chk = $pdo->prepare("SELECT role FROM employees WHERE id = ?");
            $chk->execute([$adminId]);
            $admin = $chk->fetch();

            if (!$admin || $admin['role'] !== 'superadmin') {
                echo json_encode(['error' => 'Ruxsat berilmagan']);
                break;
            }

            // Get old time
            $stmt = $pdo->prepare("SELECT timestamp FROM attendance WHERE id = ?");
            $stmt->execute([$attId]);
            $oldAtt = $stmt->fetch();
            if (!$oldAtt) {
                echo json_encode(['error' => 'Davomat topilmadi']);
                break;
            }

            $pdo->beginTransaction();
            try {
                // Update attendance
                $upd = $pdo->prepare("UPDATE attendance SET timestamp = ? WHERE id = ?");
                $upd->execute([$newTime, $attId]);

                // Log change
                $log = $pdo->prepare("INSERT INTO attendance_edits (attendance_id, changed_by, old_timestamp, new_timestamp, reason) VALUES (?, ?, ?, ?, ?)");
                $log->execute([$attId, $adminId, $oldAtt['timestamp'], $newTime, $reason]);

                $pdo->commit();
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                $pdo->rollBack();
                throw $e;
            }
            break;

        case 'ping':
            echo json_encode(['status' => 'ok', 'timestamp' => date('Y-m-d H:i:s'), 'server_ip' => $_SERVER['SERVER_ADDR'] ?? 'unknown']);
            break;

        case 'save_warning':
            $empId = $data['employeeId'] ?? null;
            $reason = $data['reason'] ?? '';

            if (!$empId || !$reason) {
                echo json_encode(['error' => 'Ma\'lumotlar yetarli emas']);
                break;
            }

            // Create table if not exists
            $pdo->exec("CREATE TABLE IF NOT EXISTS lateness_warnings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                employee_id INT NOT NULL,
                reason TEXT NOT NULL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
            )");

            $stmt = $pdo->prepare("INSERT INTO lateness_warnings (employee_id, reason) VALUES (?, ?)");
            $stmt->execute([$empId, $reason]);

            // Notify admins
            $stmtName = $pdo->prepare("SELECT full_name FROM employees WHERE id = ?");
            $stmtName->execute([$empId]);
            $empName = $stmtName->fetchColumn();

            sendExpoNotification("Kechikish ogohlantirishi", "$empName: $reason", $pdo);

            echo json_encode(['success' => true]);
            break;

        case 'get_attendance_logs':
            $stmt = $pdo->query("
                SELECT ae.*, e_admin.full_name as admin_name, e_emp.full_name as employee_name
                FROM attendance_edits ae 
                JOIN employees e_admin ON ae.changed_by = e_admin.id 
                JOIN attendance a ON ae.attendance_id = a.id
                JOIN employees e_emp ON a.employee_id = e_emp.id
                ORDER BY ae.created_at DESC
            ");
            echo json_encode($stmt->fetchAll());
            break;
        case 'get_tasks':
            $empId = $data['employeeId'] ?? $data['id'] ?? null;
            if (!$empId) {
                echo json_encode(['error' => 'Employee ID topilmadi']);
                break;
            }
            $stmt = $pdo->prepare("SELECT * FROM tasks WHERE employee_id = ? ORDER BY created_at DESC");
            $stmt->execute([$empId]);
            echo json_encode($stmt->fetchAll());
            break;

        case 'update_task_status':
            $taskId = $data['taskId'] ?? $data['id'] ?? null;
            $status = $data['status'] ?? null;
            if (!$taskId || !$status) {
                echo json_encode(['error' => 'Ma\'lumotlar yetarli emas']);
                break;
            }
            $stmt = $pdo->prepare("UPDATE tasks SET status = ? WHERE id = ?");
            $stmt->execute([$status, $taskId]);
            echo json_encode(['success' => true]);
            break;

        case 'save_task':
            $stmt = $pdo->prepare("INSERT INTO tasks (employee_id, title, description, due_date) VALUES (?, ?, ?, ?)");
            $stmt->execute([
                $data['employee_id'],
                $data['title'],
                $data['description'] ?? '',
                $data['due_date'] ?? null
            ]);
            echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
            break;

        case 'get_all_tasks':
            $stmt = $pdo->query("SELECT t.*, e.full_name as employee_name FROM tasks t JOIN employees e ON t.employee_id = e.id ORDER BY t.created_at DESC");
            echo json_encode($stmt->fetchAll());
            break;

        case 'delete_task':
            $stmt = $pdo->prepare("DELETE FROM tasks WHERE id = ?");
            $stmt->execute([$data['id']]);
            echo json_encode(['success' => true]);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Serverda xatolik yuz berdi: ' . $e->getMessage()]);
}
