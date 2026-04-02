<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();
require_once 'config.php';


// Login tekshirish
if (isset($_POST['login'])) {
    $email = strtolower($_POST['email']);
    $pass = $_POST['password'];
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE LOWER(email) = ? AND password = ?");
    $stmt->execute([$email, $pass]);
    $user = $stmt->fetch();
    if ($user) {
        if ($user['is_blocked']) {
            $error = "Sizning hisobingiz bloklangan. Administratorga murojaat qiling!";
        } else {
            $_SESSION['user'] = $user;
            
            // Log Login
            $device = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Web Device';
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $pdo->prepare("INSERT INTO login_logs (employee_id, device_info, ip_address) VALUES (?, ?, ?)")->execute([$user['id'], $device, $ip]);

            header("Location: index.php");
            exit;
        }
    } else {
        $error = "Pochta yoki parol xato!";
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

if (isset($_SESSION['user'])) {
    $stmtU = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmtU->execute([$_SESSION['user']['id']]);
    $user = $stmtU->fetch();
    
    if (!$user || $user['is_blocked']) {
        session_destroy();
        header("Location: index.php?error=blocked");
        exit;
    }
    $_SESSION['user'] = $user;
    
    $isAdmin = ($user['role'] == 'superadmin' || $user['role'] == 'admin');
    
    // Fetch custom permissions if any
    $user['permissions'] = [];
    if (!empty($user['role_id'])) {
        $stmt = $pdo->prepare("SELECT permissions FROM roles WHERE id = ?");
        $stmt->execute([$user['role_id']]);
        $perms_json = $stmt->fetchColumn();
        if ($perms_json) {
            $user['permissions'] = json_decode($perms_json, true);
        }
    }

    function hasPermission($user, $tab) {
        if ($user['role'] == 'superadmin') return true;
        if ($tab == 'reporting' || $tab == 'calendar') return true;
        if ($user['role'] == 'admin') return true;
        return in_array($tab, $user['permissions'] ?? []);
    }

    // Mark as read if visiting a tab
    $curr_tab = $_GET['tab'] ?? 'dashboard';
    if ($isAdmin) {
        if ($curr_tab == 'absences') $pdo->exec("UPDATE absences SET is_read = 1 WHERE is_read = 0");
        if ($curr_tab == 'offdays') $pdo->exec("UPDATE off_day_requests SET is_read = 1 WHERE is_read = 0");
        if ($curr_tab == 'fines') $pdo->exec("UPDATE fines SET is_read = 1 WHERE is_read = 0");
        if ($curr_tab == 'warnings') $pdo->exec("UPDATE lateness_warnings SET is_read = 1 WHERE is_read = 0");
        if ($curr_tab == 'notifications') $pdo->exec("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
    }

    if (isset($_GET['mark_notifs_read']) && $isAdmin) {
        $pdo->exec("UPDATE notifications SET is_read = 1 WHERE is_read = 0");
        header("Location: index.php?tab=notifications");
        exit;
    }

    // Pending counts for sidebar
    $pending_abs = 0;
    try { $res_abs = $pdo->query("SELECT COUNT(*) FROM absences WHERE status = 'pending' AND is_read = 0"); $pending_abs = $res_abs ? $res_abs->fetchColumn() : 0; } catch(Exception $e) {}

    $pending_off = 0;
    try { $res_off = $pdo->query("SELECT COUNT(*) FROM off_day_requests WHERE status = 'pending' AND is_read = 0"); $pending_off = $res_off ? $res_off->fetchColumn() : 0; } catch(Exception $e) {}

    $pending_fines = 0;
    try { $res_fines = $pdo->query("SELECT COUNT(*) FROM fines WHERE status = 'pending' AND is_read = 0"); $pending_fines = $res_fines ? $res_fines->fetchColumn() : 0; } catch(Exception $e) {}

    $pending_warnings = 0;
    try { $res_warn = $pdo->query("SELECT COUNT(*) FROM lateness_warnings WHERE is_read = 0"); $pending_warnings = $res_warn ? $res_warn->fetchColumn() : 0; } catch(Exception $e) {}

    $total_pending = (int)$pending_abs + (int)$pending_off + (int)$pending_fines + (int)$pending_warnings;


    function formatMins($mins) {
        $mins = (int)$mins;
        $h = floor(abs($mins) / 60);
        $m = abs($mins) % 60;
        return $h . ":" . ($m < 10 ? "0".$m : $m);
    }
    
    function formatDurationUz($mins) {
        $mins = (int)round($mins);
        $h = (int)floor($mins / 60);
        $m = $mins % 60;
        if ($h > 0) {
            return "$h soat $m daqiqa";
        }
        return "$m daqiqa";
    }

    function syncEmployeeBalance($pdo, $empId) {
        // 1. Get employee data
        $stmtE = $pdo->prepare("SELECT hourly_rate, monthly_salary FROM employees WHERE id = ?");
        $stmtE->execute([$empId]);
        $empData = $stmtE->fetch();
        $rate = $empData['hourly_rate'] ?: 0;

        // 2. Calculate earnings from attendance (Only unarchived)
        $startOfMonth = date('Y-m-01 00:00:00');
        
        // Fetch only UNARCHIVED attendance for this employee
        $stmtAtt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND is_archived = 0 ORDER BY timestamp ASC");
        $stmtAtt->execute([$empId]);
        $records = $stmtAtt->fetchAll();

        // 3. Calculate earnings from attendance (Filter by approved absences)
        $stmtAbs = $pdo->prepare("SELECT start_time, end_time, duration_minutes FROM absences WHERE employee_id = ? AND status = 'approved' AND start_time >= ?");
        $stmtAbs->execute([$empId, $startOfMonth]);
        $absences = $stmtAbs->fetchAll();

        $totalMinutes = 0;
        $totalEarned = 0;
        $lastIn = null;
        
        foreach($records as $r) {
            if ($r['type'] == 'check-in') {
                $lastIn = $r;
            } elseif ($r['type'] == 'check-out' && $lastIn) {
                $sessionRate = $lastIn['hourly_rate'] ?: ($r['hourly_rate'] ?: $rate);
                $inTs = strtotime($lastIn['timestamp']);
                $outTs = strtotime($r['timestamp']);
                $rawDur = ($outTs - $inTs) / 60;
                
                if ($rawDur > 0 && $rawDur < 2880) {
                    // Calculate session deduction based on overlapping approved absences
                    $deduction = 0;
                    foreach ($absences as $a) {
                        $absStart = strtotime($a['start_time']);
                        $absEnd = $a['end_time'] ? strtotime($a['end_time']) : time();
                        
                        $overlapStart = max($inTs, $absStart);
                        $overlapEnd = min($outTs, $absEnd);
                        
                        if ($overlapStart < $overlapEnd) {
                            $deduction += ($overlapEnd - $overlapStart) / 60;
                        }
                    }
                    $totalEarned += (max(0, $rawDur - $deduction) / 60) * $sessionRate;
                }
                $lastIn = null;
            }
        }
        
        // Handle ongoing session
        if ($lastIn) {
            $inTs = strtotime($lastIn['timestamp']);
            $outTs = time();
            $diff_hours = ($outTs - $inTs) / 3600;
            if ($diff_hours > 0 && $diff_hours < 16) {
                $rawDur = ($outTs - $inTs) / 60;
                $deduction = 0;
                foreach ($absences as $a) {
                    $absStart = strtotime($a['start_time']);
                    $absEnd = $a['end_time'] ? strtotime($a['end_time']) : time();
                    
                    $overlapStart = max($inTs, $absStart);
                    $overlapEnd = min($outTs, $absEnd);
                    
                    if ($overlapStart < $overlapEnd) {
                        $deduction += ($overlapEnd - $overlapStart) / 60;
                    }
                }
                $totalEarned += (max(0, $rawDur - $deduction) / 60) * $rate;
            }
        }

        // 4. Subtract approved fines (Only unarchived)
        $stmtF = $pdo->prepare("SELECT SUM(amount) FROM fines WHERE employee_id = ? AND status = 'approved' AND is_archived = 0");
        $stmtF->execute([$empId]);
        $totalFines = $stmtF->fetchColumn() ?: 0;

        // 5. Subtract all payments/advances (Only unarchived)
        $stmtP = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE employee_id = ? AND is_archived = 0");
        $stmtP->execute([$empId]);
        $totalPayments = $stmtP->fetchColumn() ?: 0;

        $newBalance = round($totalEarned - $totalFines - $totalPayments);
        
        $pdo->prepare("UPDATE employees SET balance = ? WHERE id = ?")->execute([$newBalance, $empId]);
        return $newBalance;
    }

    // Submit Off-day Request (Employee/Authenticated)
    if (isset($_POST['submit_offday'])) {
        $reqDate = $_POST['request_date'];
        $stmt = $pdo->prepare("INSERT INTO off_day_requests (employee_id, request_date, reason) VALUES (?, ?, ?)");
        $stmt->execute([$user['id'], $reqDate, $_POST['reason']]);
        header("Location: index.php?tab=offdays&success=1");
        exit;
    }

    // --- ACTIONS --- (Accessible based on role)

    // Web Attendance (for managers and employees)
    if (isset($_POST['web_attendance']) && ($user['role'] == 'employee' || $user['role'] == 'manager')) {
        $type = $_POST['type'];
        $lat = $_POST['lat'] ?? 0;
        $lon = $_POST['lon'] ?? 0;
        $br_id = $_POST['branch_id'] ?? $user['branch_id'];
        $image = $_POST['image'] ?? null;
        $timestamp = date('Y-m-d H:i:s');

        // Check distance against ALL branches
        $branches = $pdo->query("SELECT * FROM branches")->fetchAll();
        $isNearAny = false;
        $closestDist = 999999;
        
        foreach ($branches as $b) {
            $d = getDistance($lat, $lon, $b['latitude'], $b['longitude']);
            if ($d < $closestDist) {
                $closestDist = $d;
                if ($d <= $b['radius'] + 100) {
                    $br_id = $b['id']; // Auto select nearest branch
                }
            }
            if ($d <= $b['radius']) {
                $isNearAny = true;
            }
        }

        if ($type == 'check-in' && !$isNearAny) {
            $error = "Masofa xatosi: Hech qaysi filial hududida emassiz (Masofa: " . round($closestDist) . "m)";
            header("Location: index.php?tab=attendance&error=" . urlencode($error));
            exit;
        }

        $imagePath = saveImage($_POST['image'] ?? null, 'web_' . $user['id']);
        
        // --- FINE LOGIC ---
        if ($type == 'check-in') {
            $today = date('Y-m-d');
            $stmtCheck = $pdo->prepare("SELECT id FROM attendance WHERE employee_id = ? AND type = 'check-in' AND DATE(timestamp) = ?");
            $stmtCheck->execute([$user['id'], $today]);
            $isFirst = !$stmtCheck->fetch();

            if ($isFirst && !empty($user['work_start_time'])) {
                $startTime = $today . ' ' . $user['work_start_time'];
                $diffMinutes = (strtotime($timestamp) - strtotime($startTime)) / 60;
                
                if ($diffMinutes >= 10) {
                    if ($diffMinutes < 30) {
                        $fineType = 'Kechikish (10-30 min)';
                        $defaultAmount = 50000;
                    } elseif ($diffMinutes < 60) {
                        $fineType = 'Kechikish (30-60 min)';
                        $defaultAmount = 80000;
                    } else {
                        $fineType = 'Kechikish (1soat+)';
                        $defaultAmount = 120000;
                    }

                    $stmtType = $pdo->prepare("SELECT amount FROM fine_types WHERE name = ? LIMIT 1");
                    $stmtType->execute([$fineType]);
                    $typeData = $stmtType->fetch();
                    $amount = $typeData ? $typeData['amount'] : $defaultAmount;
                    
                    $stmtWarn = $pdo->prepare("SELECT id FROM lateness_warnings WHERE employee_id = ? AND DATE(timestamp) = ?");
                    $stmtWarn->execute([$user['id'], date('Y-m-d')]);
                    $fineStatus = $stmtWarn->fetch() ? 'rejected' : 'approved';
                    
                    $stmtFine = $pdo->prepare("INSERT INTO fines (employee_id, amount, reason, date, status) VALUES (?, ?, ?, ?, ?)");
                    $stmtFine->execute([$user['id'], $amount, "Ishga ".round($diffMinutes)." daqiqa kechikkanlik uchun jarima", $timestamp, $fineStatus]);
                    
                    if ($fineStatus == 'approved') {
                        $pdo->prepare("UPDATE employees SET balance = balance - ? WHERE id = ?")->execute([$amount, $user['id']]);
                    }

                    require_once 'api_functions.php';
                    sendExpoNotification("Yangi kechikish (Jarima)", $user['full_name'] . " ishga kechikib keldi. Jarimani ko'rib chiqing.", $pdo);
                }
            }
        }

        $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, type, latitude, longitude, branch_id, image_url, timestamp, hourly_rate) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user['id'], $type, $lat, $lon, $br_id, $imagePath, $timestamp, ($user['hourly_rate'] ?? 0)]);

        // --- BALANCE UPDATE ON CHECK-OUT ---
        if ($type == 'check-out') {
            $stmtIn = $pdo->prepare("SELECT timestamp FROM attendance WHERE employee_id = ? AND type = 'check-in' AND timestamp < ? AND timestamp > DATE_SUB(?, INTERVAL 18 HOUR) ORDER BY timestamp DESC LIMIT 1");
            $stmtIn->execute([$user['id'], $timestamp, $timestamp]);
            $lastIn = $stmtIn->fetch();
            if ($lastIn) {
                $inTime = strtotime($lastIn['timestamp']);
                $outTime = strtotime($timestamp);
                $workMins = ($outTime - $inTime) / 60;
                if ($workMins > 0) {
                    $earned = round(($workMins / 60) * ($user['hourly_rate'] ?? 0));
                    if ($earned > 0) {
                        $pdo->prepare("UPDATE employees SET balance = balance + ? WHERE id = ?")->execute([$earned, $user['id']]);
                    }

                    // Early Departure Check
                    if (!empty($user['work_end_time'])) {
                        $endTimeStr = date('Y-m-d', $inTime) . ' ' . $user['work_end_time'];
                        $expectedEnd = strtotime($endTimeStr);
                        if (strtotime($user['work_end_time']) <= strtotime($user['work_start_time'])) {
                            $expectedEnd += 86400; 
                        }
                        if ($outTime < $expectedEnd - 60) {
                            $earlyMins = ($expectedEnd - $outTime) / 60;
                            if ($earlyMins >= 10) {
                                if ($earlyMins < 30) {
                                    $fineType = 'Erta ketish 10-30daqiqa';
                                    $defaultAmount = 50000;
                                } elseif ($earlyMins < 60) {
                                    $fineType = 'Erta ketish (30-60 min)';
                                    $defaultAmount = 80000;
                                } else {
                                    $fineType = 'Erta ketish (1soat+)';
                                    $defaultAmount = 120000;
                                }

                                $stmtType = $pdo->prepare("SELECT amount FROM fine_types WHERE name = ? LIMIT 1");
                                $stmtType->execute([$fineType]);
                                $typeData = $stmtType->fetch();
                                $fineAmount = $typeData ? $typeData['amount'] : $defaultAmount;
                                
                                if ($fineAmount > 0) {
                                    $stmtWarn = $pdo->prepare("SELECT id FROM lateness_warnings WHERE employee_id = ? AND DATE(timestamp) = ?");
                                    $stmtWarn->execute([$user['id'], date('Y-m-d')]);
                                    $fineStatus = $stmtWarn->fetch() ? 'rejected' : 'approved';

                                    $pdo->prepare("INSERT INTO fines (employee_id, amount, reason, date, status) VALUES (?, ?, ?, ?, ?)")->execute([$user['id'], $fineAmount, htmlspecialchars($fineType) . " (" . round($earlyMins) . " daqiqa)", $timestamp, $fineStatus]);
                                    
                                    if ($fineStatus == 'approved') {
                                        $pdo->prepare("UPDATE employees SET balance = balance - ? WHERE id = ?")->execute([$fineAmount, $user['id']]);
                                    }

                                    require_once 'api_functions.php';
                                    sendExpoNotification("Vaqtli ketish", $user['full_name'] . " ishdan vaqtli ketdi (" . round($earlyMins) . " daq).", $pdo);
                                }
                            }
                        }
                    }
                }
            }
        }

        header("Location: index.php?tab=attendance");
        exit;
    }

    // Admin/Superadmin Actions
    if ($user['role'] == 'superadmin' || $user['role'] == 'admin') {
        if (isset($_POST['add_branch'])) {
            $stmt = $pdo->prepare("INSERT INTO branches (name, latitude, longitude, radius) VALUES (?, ?, ?, ?)");
            $stmt->execute([$_POST['name'], $_POST['lat'], $_POST['lon'], $_POST['radius']]);
        }
        if (isset($_POST['edit_branch'])) {
            $stmt = $pdo->prepare("UPDATE branches SET name=?, latitude=?, longitude=?, radius=? WHERE id=?");
            $stmt->execute([$_POST['name'], $_POST['lat'], $_POST['lon'], $_POST['radius'], $_POST['id']]);
        }
        if (isset($_POST['add_employee'])) {
            $salary = floatval($_POST['salary'] ?? 0);
            $off_days = intval($_POST['off_days'] ?? 4);
            $days = 30 - $off_days;
            $start = $_POST['start_time'];
            $end = $_POST['end_time'];
            
            $start_ts = strtotime($start);
            $end_ts = strtotime($end);
            if ($end_ts <= $start_ts) $end_ts += 86400;
            $daily_hours = ($end_ts - $start_ts) / 3600;
            $hourly_rate = ($daily_hours > 0 && $days > 0) ? ($salary / ($daily_hours * $days)) : 0;

            $profile_image = null;
            if (!empty($_POST['captured_image'])) {
                $profile_image = saveImage($_POST['captured_image'], 'emp');
            } elseif (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $profile_image = saveImageFile($_FILES['profile_image'], 'emp');
            }

            $off_type = $_POST['off_day_type'] ?? 'custom';
            $stmt = $pdo->prepare("INSERT INTO employees (full_name, phone, email, password, role, role_id, branch_id, position, monthly_salary, work_days_per_month, hourly_rate, work_start_time, work_end_time, balance, off_day_type, off_days_per_month, profile_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$_POST['name'], $_POST['phone'], $_POST['email'], $_POST['pass'], $_POST['role'], $_POST['role_id'] ?: null, $_POST['branch_id'], $_POST['pos'], $salary, $days, $hourly_rate, $start, $end, floatval($_POST['balance'] ?? 0), $off_type, $off_days, $profile_image]);
            header("Location: index.php?tab=employees");
            exit;
        }
        if (isset($_POST['edit_employee'])) {
            $salary = floatval($_POST['salary'] ?? 0);
            $off_days = intval($_POST['off_days'] ?? 4);
            $days = 30 - $off_days;
            $start = $_POST['start_time'];
            $end = $_POST['end_time'];
            
            $start_ts = strtotime($start);
            $end_ts = strtotime($end);
            if ($end_ts <= $start_ts) $end_ts += 86400;
            $daily_hours = ($end_ts - $start_ts) / 3600;
            $hourly_rate = ($daily_hours > 0 && $days > 0) ? ($salary / ($daily_hours * $days)) : 0;

            $sql = "UPDATE employees SET full_name=?, phone=?, email=?, role=?, role_id=?, branch_id=?, position=?, monthly_salary=?, work_days_per_month=?, hourly_rate=?, work_start_time=?, work_end_time=?, balance=?, off_day_type=?, off_days_per_month=?";
            $params = [$_POST['name'], $_POST['phone'], $_POST['email'], $_POST['role'], $_POST['role_id'] ?: null, $_POST['branch_id'], $_POST['pos'], $salary, $days, $hourly_rate, $start, $end, floatval($_POST['balance'] ?? 0), $_POST['off_day_type'] ?? 'custom', $off_days];

            if (!empty($_POST['captured_image'])) {
                $profile_image = saveImage($_POST['captured_image'], 'emp');
                $sql .= ", profile_image=?";
                $params[] = $profile_image;
            } elseif (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
                $profile_image = saveImageFile($_FILES['profile_image'], 'emp');
                $sql .= ", profile_image=?";
                $params[] = $profile_image;
            }

            if (!empty($_POST['pass'])) {
                $sql .= ", password=?";
                $params[] = $_POST['pass'];
            }

            $sql .= " WHERE id=?";
            $params[] = $_POST['id'];

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            syncEmployeeBalance($pdo, $_POST['id']);
            header("Location: index.php?tab=employees");
            exit;
        }

        // --- Test Push Action ---
        if (isset($_POST['send_test_push'])) {
            require_once 'api_functions.php';
            $empId = $_POST['employee_id'];
            $stmt = $pdo->prepare("SELECT push_token, full_name FROM employees WHERE id = ?");
            $stmt->execute([$empId]);
            $worker = $stmt->fetch();
            
            if ($worker && $worker['push_token']) {
                sendPush($worker['push_token'], "Test xabarnomasi", "Salom " . $worker['full_name'] . ", bu tizimdan yuborilgan test xabaridir! Agar siz buni ko'rayotgan bo'lsangiz, xabarnomalar ishlamoqda.");
                header("Location: index.php?tab=employees&success=push_sent");
            } else {
                header("Location: index.php?tab=employees&error=no_token");
            }
            exit;
        }
        if (isset($_GET['offday_id']) && isset($_GET['status'])) {
            $stmt = $pdo->prepare("UPDATE off_day_requests SET status = ? WHERE id = ?");
            $stmt->execute([$_GET['status'], $_GET['offday_id']]);
            header("Location: index.php?tab=offdays");
            exit;
        }
        if (isset($_GET['del_emp'])) {
            $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$_GET['del_emp']]);
            header("Location: index.php?tab=employees");
            exit;
        }
        if (isset($_GET['toggle_block']) && $user['role'] == 'superadmin') {
            $pdo->prepare("UPDATE employees SET is_blocked = NOT is_blocked WHERE id = ?")->execute([$_GET['toggle_block']]);
            header("Location: index.php?tab=employees");
            exit;
        }
        if (isset($_GET['del_br'])) {
            $pdo->prepare("DELETE FROM branches WHERE id = ?")->execute([$_GET['del_br']]);
            header("Location: index.php?tab=branches");
            exit;
        }
        if (isset($_GET['del_att'])) {
            $stmtD = $pdo->prepare("SELECT employee_id FROM attendance WHERE id = ?");
            $stmtD->execute([$_GET['del_att']]);
            $empId = $stmtD->fetchColumn();
            
            $pdo->prepare("DELETE FROM attendance WHERE id = ?")->execute([$_GET['del_att']]);
            
            if ($empId) syncEmployeeBalance($pdo, $empId);
            
            header("Location: index.php?tab=reporting&success=1");
            exit;
        }

        if (isset($_POST['save_role'])) {
            $stmt = $pdo->prepare("INSERT INTO roles (name, permissions) VALUES (?, ?)");
            $stmt->execute([$_POST['name'], json_encode($_POST['permissions'] ?? [])]);
            header("Location: index.php?tab=roles");
            exit;
        }
        if (isset($_POST['edit_role'])) {
            $stmt = $pdo->prepare("UPDATE roles SET name=?, permissions=? WHERE id=?");
            $stmt->execute([$_POST['name'], json_encode($_POST['permissions'] ?? []), $_POST['id']]);
            header("Location: index.php?tab=roles");
            exit;
        }
        if (isset($_GET['del_role'])) {
            $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$_GET['del_role']]);
            header("Location: index.php?tab=roles");
            exit;
        }


        // Approve/Reject Absences
        if (isset($_GET['abs_id']) && isset($_GET['status'])) {
            $absId = $_GET['abs_id'];
            $status = $_GET['status'];
            
            $stmt = $pdo->prepare("SELECT a.*, e.hourly_rate FROM absences a JOIN employees e ON a.employee_id = e.id WHERE a.id = ?");
            $stmt->execute([$absId]);
            $abs = $stmt->fetch();
            
            if ($abs) {
                $old_status = $abs['status'];
                $empId = $abs['employee_id'];
                
                if ($old_status != $status) {
                    $pdo->prepare("UPDATE absences SET status = ? WHERE id = ?")->execute([$status, $absId]);
                    
                    // If changed to REJECTED from something else
                    if ($status == 'rejected' && $abs['duration_minutes'] > 0) {
                        $fineAmount = round(($abs['duration_minutes'] / 60) * $abs['hourly_rate']);
                        $fineDate = date('Y-m-d H:i:s', strtotime($abs['start_time']));
                        $reason = "Uzoqlashish rad etilganligi uchun chegirilgan vaqt (" . formatDurationUz($abs['duration_minutes']) . ")";
                        
                        $pdo->prepare("INSERT INTO fines (employee_id, amount, reason, date, status, absence_id) VALUES (?, ?, ?, ?, 'approved', ?)")->execute([$empId, $fineAmount, $reason, $fineDate, $absId]);
                        
                        // Update balance
                        $pdo->prepare("UPDATE employees SET balance = balance - ? WHERE id = ?")->execute([$fineAmount, $empId]);
                    }
                    
                    // If changed FROM REJECTED to something else (e.g. Approved)
                    if ($old_status == 'rejected' && $status == 'approved') {
                        $stmt_f = $pdo->prepare("SELECT id, amount FROM fines WHERE absence_id = ?");
                        $stmt_f->execute([$absId]);
                        $fines = $stmt_f->fetchAll();
                        foreach ($fines as $f) {
                            $pdo->prepare("UPDATE employees SET balance = balance + ? WHERE id = ?")->execute([$f['amount'], $empId]);
                            $pdo->prepare("DELETE FROM fines WHERE id = ?")->execute([$f['id']]);
                        }
                    }

                    // Log it
                    $pdo->prepare("INSERT INTO admin_logs (admin_id, employee_id, action_type, target_id, new_status) VALUES (?, ?, 'absence', ?, ?)")->execute([$user['id'], $empId, $absId, $status]);
                }
            }
            header("Location: index.php?tab=absences");
            exit;
        }

        // Approve/Reject Offdays
        if (isset($_GET['offday_id'])) {
            $stmt = $pdo->prepare("UPDATE off_day_requests SET status = ? WHERE id = ?");
            $stmt->execute([$_GET['status'], $_GET['offday_id']]);
            header("Location: index.php?tab=offdays");
            exit;
        }

        // --- Fine Actions ---
        if (isset($_GET['fine_id']) && isset($_GET['status'])) {
            $status = $_GET['status'];
            $fineId = $_GET['fine_id'];

            $stmtF = $pdo->prepare("SELECT employee_id FROM fines WHERE id = ?");
            $stmtF->execute([$fineId]);
            $empId = $stmtF->fetchColumn();

            $pdo->prepare("UPDATE fines SET status = ? WHERE id = ?")->execute([$status, $fineId]);
            
            if ($empId) syncEmployeeBalance($pdo, $empId);
            
            // Log it
            $pdo->prepare("INSERT INTO admin_logs (admin_id, employee_id, action_type, target_id, new_status) VALUES (?, ?, 'fine', ?, ?)")->execute([$user['id'], $empId, $fineId, $status]);

            header("Location: index.php?tab=fines");
            exit;
        }

        if (isset($_POST['add_manual_fine'])) {
            $empId = $_POST['employee_id'];
            $amount = $_POST['amount'];
            $reason = $_POST['reason'];
            $status = $_POST['status'] ?? 'approved';
            
            $stmt = $pdo->prepare("INSERT INTO fines (employee_id, amount, reason, status) VALUES (?, ?, ?, ?)");
            $stmt->execute([$empId, $amount, $reason, $status]);
            
            if ($status == 'approved') {
                syncEmployeeBalance($pdo, $empId);
            }
            
            header("Location: index.php?tab=fines&success=1");
            exit;
        }

        if (isset($_POST['save_fine_type'])) {
            $stmt = $pdo->prepare("INSERT INTO fine_types (name, amount, description) VALUES (?, ?, ?)");
            $stmt->execute([$_POST['name'], $_POST['amount'], $_POST['description']]);
            header("Location: index.php?tab=fine_types");
            exit;
        }

        if (isset($_POST['edit_fine_type'])) {
            $stmt = $pdo->prepare("UPDATE fine_types SET name=?, amount=?, description=? WHERE id=?");
            $stmt->execute([$_POST['name'], $_POST['amount'], $_POST['description'], $_POST['id']]);
            header("Location: index.php?tab=fine_types");
            exit;
        }

        if (isset($_GET['del_fine_type'])) {
            $pdo->prepare("DELETE FROM fine_types WHERE id = ?")->execute([$_GET['del_fine_type']]);
            header("Location: index.php?tab=fine_types");
            exit;
        }

        // --- Admin/Superadmin: Edit Absence ---
        if (isset($_POST['edit_absence']) && $isAdmin) {
            $absId = $_POST['abs_id'];
            $startTime = $_POST['start_time'];
            $endTime = $_POST['end_time'] ?: null;
            $status = $_POST['status'];
            
            $stmt = $pdo->prepare("SELECT a.*, e.hourly_rate FROM absences a JOIN employees e ON a.employee_id = e.id WHERE a.id = ?");
            $stmt->execute([$absId]);
            $abs = $stmt->fetch();
            
            if ($abs) {
                $old_status = $abs['status'];
                $empId = $abs['employee_id'];
                $dur = 0;
                if ($endTime) {
                    $dur = (strtotime($endTime) - strtotime($startTime)) / 60;
                }
                
                $pdo->prepare("UPDATE absences SET start_time = ?, end_time = ?, duration_minutes = ?, status = ? WHERE id = ?")
                    ->execute([$startTime, $endTime, $dur, $status, $absId]);
                
                // If status changed or duration changed, we should probably sync balance
                if ($old_status == 'rejected' || $status == 'rejected') {
                    // Remove existing fines for this absence
                    $stmt_f = $pdo->prepare("SELECT id, amount FROM fines WHERE absence_id = ?");
                    $stmt_f->execute([$absId]);
                    $fines_to_del = $stmt_f->fetchAll();
                    foreach ($fines_to_del as $f) {
                        $pdo->prepare("UPDATE employees SET balance = balance + ? WHERE id = ?")->execute([$f['amount'], $empId]);
                        $pdo->prepare("DELETE FROM fines WHERE id = ?")->execute([$f['id']]);
                    }
                    
                    if ($status == 'rejected' && $dur > 0) {
                        $fineAmount = round(($dur / 60) * $abs['hourly_rate']);
                        $fineDate = date('Y-m-d H:i:s', strtotime($startTime));
                        $reason = "Uzoqlashish tahrirlangan va rad etilganligi uchun chegirilgan vaqt (" . formatDurationUz($dur) . ")";
                        $pdo->prepare("INSERT INTO fines (employee_id, amount, reason, date, status, absence_id) VALUES (?, ?, ?, ?, 'approved', ?)")->execute([$empId, $fineAmount, $reason, $fineDate, $absId]);
                        $pdo->prepare("UPDATE employees SET balance = balance - ? WHERE id = ?")->execute([$fineAmount, $empId]);
                    }
                }
                
                // Log it
                $pdo->prepare("INSERT INTO admin_logs (admin_id, employee_id, action_type, target_id, new_status) VALUES (?, ?, 'absence_edit', ?, ?)")->execute([$user['id'], $empId, $absId, $status]);
            }
            header("Location: index.php?tab=absences&success=edit");
            exit;
        }

        // --- Admin/Superadmin: Edit Offday ---
        if (isset($_POST['edit_offday']) && $isAdmin) {
            $offId = $_POST['offday_id'];
            $reqDate = $_POST['request_date'];
            $reason = $_POST['reason'];
            $status = $_POST['status'];
            
            $pdo->prepare("UPDATE off_day_requests SET request_date = ?, reason = ?, status = ? WHERE id = ?")
                ->execute([$reqDate, $reason, $status, $offId]);
            
            header("Location: index.php?tab=offdays&success=edit");
            exit;
        }

        // --- Admin/Superadmin: Update Attendance Time ---
        if (isset($_POST['update_attendance_time']) && $isAdmin) {
            $attId = $_POST['attendance_id'];
            $newTime = $_POST['new_time']; // 'HH:mm'
            $newDate = $_POST['new_date']; // 'YYYY-MM-DD'
            $reason = $_POST['reason'] ?? '';
            $fullNewTimestamp = $newDate . ' ' . $newTime . ':00';
            $empIdManual = $_POST['manual_employee_id'] ?? null;
            $inIdManual = $_POST['manual_in_id'] ?? null;

            if (!$attId && $empIdManual && $inIdManual) {
                // ADD MISSING CHECKOUT
                $pdo->beginTransaction();
                try {
                    // Get data from check-in record for context
                    $stmtIn = $pdo->prepare("SELECT * FROM attendance WHERE id = ?");
                    $stmtIn->execute([$inIdManual]);
                    $inM = $stmtIn->fetch();
                    
                    if (!$inM) throw new Exception("Kirish ma'lumoti topilmadi");

                    // Get employee for balance
                    $stmtE = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
                    $stmtE->execute([$empIdManual]);
                    $empM = $stmtE->fetch();

                    // Insert attendance (use branch from check-in for consistency)
                    $stmtC = $pdo->prepare("INSERT INTO attendance (employee_id, type, timestamp, branch_id, hourly_rate) VALUES (?, 'check-out', ?, ?, ?)");
                    $stmtC->execute([$empIdManual, $fullNewTimestamp, $inM['branch_id'], ($empM['hourly_rate'] ?? 0)]);
                    $newAttId = $pdo->lastInsertId();

                    // Calculate balance
                    $workM = (strtotime($fullNewTimestamp) - strtotime($inM['timestamp'])) / 60;
                    if ($workM > 0) {
                        $earned = round(($workM / 60) * ($empM['hourly_rate'] ?? 0));
                        if ($earned > 0) {
                            $pdo->prepare("UPDATE employees SET balance = balance + ? WHERE id = ?")->execute([$earned, $empIdManual]);
                        }
                    }

                    // Log the manual creation in attendance_edits
                    $log = $pdo->prepare("INSERT INTO attendance_edits (attendance_id, changed_by, old_timestamp, new_timestamp, reason) VALUES (?, ?, '0000-00-00 00:00:00', ?, ?)");
                    $log->execute([$newAttId, $user['id'], $fullNewTimestamp, $reason]);

                    $pdo->commit();
                    header("Location: index.php?tab=reporting&success=1");
                    exit;
                } catch (Exception $ex) {
                    $pdo->rollBack();
                    $error = "Xatolik: " . $ex->getMessage();
                }
            } else {
                // UPDATE EXISTING
                $stmt = $pdo->prepare("SELECT timestamp FROM attendance WHERE id = ?");
                $stmt->execute([$attId]);
                $oldAtt = $stmt->fetch();

                if ($oldAtt) {
                    $pdo->beginTransaction();
                    try {
                        $upd = $pdo->prepare("UPDATE attendance SET timestamp = ? WHERE id = ?");
                        $upd->execute([$fullNewTimestamp, $attId]);
                        $log = $pdo->prepare("INSERT INTO attendance_edits (attendance_id, changed_by, old_timestamp, new_timestamp, reason) VALUES (?, ?, ?, ?, ?)");
                        $log->execute([$attId, $user['id'], $oldAtt['timestamp'], $fullNewTimestamp, $reason]);
                        $pdo->commit();
                        
                        // Sync balance after time update
                        $stmtE = $pdo->prepare("SELECT employee_id FROM attendance WHERE id = ?");
                        $stmtE->execute([$attId]);
                        $empId = $stmtE->fetchColumn();
                        if ($empId) syncEmployeeBalance($pdo, $empId);
                        
                        header("Location: index.php?tab=reporting&success=1");
                        exit;
                    } catch (Exception $ex) {
                        $pdo->rollBack();
                        $error = "Xatolik: " . $ex->getMessage();
                    }
                }
            }
        }
        if (isset($_GET['sync_all']) && $user['role'] == 'superadmin') {
            $emps = $pdo->query("SELECT id FROM employees")->fetchAll(PDO::FETCH_COLUMN);
            foreach($emps as $eid) {
                syncEmployeeBalance($pdo, $eid);
            }
            header("Location: index.php?tab=employees&synced=1");
            exit;
        }

        // Oy Yakuni (Close Month & Archive)
        if (isset($_GET['close_month']) && $user['role'] == 'superadmin') {
            $monthYear = date('Y-m');
            $startOfMonth = date('Y-m-01 00:00:00');
            
            // 1. Get all employees
            $employees = $pdo->query("SELECT * FROM employees")->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($employees as $e) {
                $empId = $e['id'];
                $rate = $e['hourly_rate'] ?: 0;
                
                // Recalculate earnings (same logic as syncEmployeeBalance)
                $stmtAtt = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? AND timestamp >= ? ORDER BY timestamp ASC");
                $stmtAtt->execute([$empId, $startOfMonth]);
                $records = $stmtAtt->fetchAll();
                
                $totalEarned = 0;
                $totalHours = 0;
                $lastIn = null;
                foreach($records as $r) {
                    if ($r['type'] == 'check-in') $lastIn = $r;
                    elseif ($r['type'] == 'check-out' && $lastIn) {
                        $sessionRate = $lastIn['hourly_rate'] ?: ($r['hourly_rate'] ?: $rate);
                        $dur = (strtotime($r['timestamp']) - strtotime($lastIn['timestamp'])) / 60;
                        if ($dur > 0 && $dur < 2880) {
                            $totalEarned += ($dur / 60) * $sessionRate;
                            $totalHours += ($dur / 60);
                        }
                        $lastIn = null;
                    }
                }
                
                // Subtract approved fines
                $stmtF = $pdo->prepare("SELECT SUM(amount) FROM fines WHERE employee_id = ? AND status = 'approved' AND date >= ?");
                $stmtF->execute([$empId, $startOfMonth]);
                $totalFines = $stmtF->fetchColumn() ?: 0;

                // Subtract payments (Only unarchived)
                $stmtP = $pdo->prepare("SELECT SUM(amount) FROM payments WHERE employee_id = ? AND is_archived = 0");
                $stmtP->execute([$empId]);
                $totalPayments = $stmtP->fetchColumn() ?: 0;

                $netSalary = $totalEarned - $totalFines - $totalPayments;

                // Calculate approved off-days for the month
                $stmtOffCount = $pdo->prepare("SELECT COUNT(*) FROM off_day_requests WHERE employee_id = ? AND status = 'approved' AND request_date >= ?");
                $stmtOffCount->execute([$empId, $startOfMonth]);
                $offDaysCount = $stmtOffCount->fetchColumn() ?: 0;

                // 2. Insert into monthly_reports
                $stmt_ins = $pdo->prepare("INSERT INTO monthly_reports (employee_id, month_year, total_hours, gross_salary, total_fines, total_advances, net_salary, off_days) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt_ins->execute([$empId, $monthYear, $totalHours, $totalEarned, $totalFines, $totalPayments, $netSalary, $offDaysCount]);

                // 3. Archive individual records (attendance, fines, payments, absences, off-days)
                $pdo->prepare("UPDATE attendance SET is_archived = 1 WHERE employee_id = ?")->execute([$empId]);
                $pdo->prepare("UPDATE fines SET is_archived = 1 WHERE employee_id = ?")->execute([$empId]);
                $pdo->prepare("UPDATE payments SET is_archived = 1 WHERE employee_id = ?")->execute([$empId]);
                $pdo->prepare("UPDATE absences SET is_archived = 1 WHERE employee_id = ?")->execute([$empId]);
                $pdo->prepare("UPDATE off_day_requests SET is_archived = 1 WHERE employee_id = ?")->execute([$empId]);

                // 4. Reset employee balance
                $pdo->prepare("UPDATE employees SET balance = 0 WHERE id = ?")->execute([$empId]);
            }
            
            header("Location: index.php?tab=dashboard&closed=1");
            exit;
        }
        
        if (isset($_GET['synced'])) {
             echo "<script>alert('Barcha xodimlar balansi hisobotlar asosida qayta sinxronlandi!');</script>";
        }
        if (isset($_GET['closed'])) {
             echo "<script>alert('Oy yakunlandi. Barcha xodimlar statiskiasi arxivlanib, balanslar 0 ga tushirildi!');</script>";
        }
    }
}

if (!isset($_SESSION['user'])): ?>
    <!DOCTYPE html>
    <html lang="uz">

    <head>
        <meta charset="UTF-8">
        <title>Davomat - Kirish</title>
        <style>
            body {
                background: #f3f4f6;
                display: flex;
                align-items: center;
                justify-content: center;
                height: 100vh;
                font-family: sans-serif;
                margin: 0;
            }

            .login-card {
                background: white;
                padding: 40px;
                border-radius: 20px;
                box-shadow: 0 10px 25px rgba(0, 0, 0, 0.05);
                width: 100%;
                max-width: 400px;
            }

            h1 {
                color: #1e40af;
                text-align: center;
                margin-bottom: 30px;
                font-weight: 900;
            }

            input {
                width: 100%;
                padding: 14px;
                margin-bottom: 20px;
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                box-sizing: border-box;
                outline: none;
                transition: 0.2s;
            }

            input:focus {
                border-color: #3b82f6;
                ring: 2px #3b82f6;
            }

            button {
                width: 100%;
                padding: 14px;
                background: #3b82f6;
                color: white;
                border: none;
                border-radius: 12px;
                cursor: pointer;
                font-weight: bold;
                font-size: 16px;
            }

            .error {
                color: #ef4444;
                text-align: center;
                margin-bottom: 15px;
                font-size: 14px;
            }
        </style>
    </head>

    <body>
        <div class="login-card">
            <h1>Workpay.uz</h1>
            <?php if (isset($error))
                echo "<div class='error'>$error</div>"; ?>
            <form method="POST">
                <input type="email" name="email" placeholder="Email manzilingiz" required>
                <input type="password" name="password" placeholder="Parol" required>
                <button type="submit" name="login">Tizimga kirish</button>
            </form>
        </div>
    </body>

    </html>
<?php else:
    // Data Loading
    $isAdmin = ($user['role'] == 'superadmin' || $user['role'] == 'admin');
    $isManager = ($user['role'] == 'manager');
    $tab = $_GET['tab'] ?? 'dashboard';
    $branches = $pdo->query("SELECT * FROM branches")->fetchAll();

    // Handle Filter Clearing
    if (isset($_GET['clear_filters'])) {
        unset($_SESSION['f_branch'], $_SESSION['f_date'], $_SESSION['f_search'], $_SESSION['f_start'], $_SESSION['f_end'], $_SESSION['f_period'], $_SESSION['f_month']);
        header("Location: index.php?tab=" . ($tab ?? 'dashboard'));
        exit;
    }

    // Filters from GET or SESSION (Persistence)
    if (isset($_GET['f_branch'])) {
        $f_branch = (int)$_GET['f_branch'];
        $_SESSION['f_branch'] = $f_branch;
    } else {
        $f_branch = $_SESSION['f_branch'] ?? 0;
    }

    if (isset($_GET['f_date'])) {
        $f_date = preg_replace('/[^0-9-]/', '', $_GET['f_date']);
        $_SESSION['f_date'] = $f_date;
    } else {
        $f_date = $_SESSION['f_date'] ?? '';
    }

    if (isset($_GET['f_search'])) {
        $f_search = htmlspecialchars($_GET['f_search']);
        $_SESSION['f_search'] = $f_search;
    } else {
        $f_search = $_SESSION['f_search'] ?? '';
    }

    if (isset($_GET['f_start'])) {
        $f_start = $_GET['f_start'];
        $_SESSION['f_start'] = $f_start;
    } else {
        $f_start = $_SESSION['f_start'] ?? '';
    }

    if (isset($_GET['f_end'])) {
        $f_end = $_GET['f_end'];
        $_SESSION['f_end'] = $f_end;
    } else {
        $f_end = $_SESSION['f_end'] ?? '';
    }

    if (isset($_GET['f_period'])) {
        $f_period = $_GET['f_period'];
        $_SESSION['f_period'] = $f_period;
    } else {
        $f_period = $_SESSION['f_period'] ?? '';
    }

    if (isset($_GET['f_month'])) {
        $f_month = preg_replace('/[^0-9-]/', '', $_GET['f_month']);
        $_SESSION['f_month'] = $f_month;
    } else {
        $f_month = $_SESSION['f_month'] ?? '';
    }

    // Now proceed with data fetching using consolidated filters
    // Shared Data Fetching (for Admins and Managers)
    if ($isAdmin || $isManager) {
        // Fetch Warnings
        $warnings = [];
        try {
            $warnings = $pdo->query("SELECT w.*, e.full_name, b.name as branch_name 
                                     FROM lateness_warnings w 
                                     JOIN employees e ON w.employee_id = e.id 
                                     LEFT JOIN branches b ON e.branch_id = b.id 
                                     ORDER BY w.timestamp DESC")->fetchAll();
        } catch(Exception $e) {}

        // Fetch Expense Categories
        try {
            $expenseCategories = $pdo->query("SELECT * FROM expense_categories ORDER BY name ASC")->fetchAll();
        } catch(Exception $e) { $expenseCategories = []; }
        
        // Fetch Tasks (Superadmin Only)
        $tasks = [];
        try {
            if ($user['role'] == 'superadmin') {
                $tasks = $pdo->query("SELECT t.*, e.full_name as employee_name, b.name as branch_name 
                                    FROM tasks t 
                                    JOIN employees e ON t.employee_id = e.id 
                                    LEFT JOIN branches b ON e.branch_id = b.id 
                                    ORDER BY t.created_at DESC")->fetchAll();
            }
        } catch(Exception $e) {}
        
        $sql_exp = "SELECT e.*, ec.name as category_name, b.name as branch_name, em.full_name as creator_name 
            FROM expenses e 
            JOIN expense_categories ec ON e.category_id = ec.id 
            LEFT JOIN branches b ON e.branch_id = b.id 
            LEFT JOIN employees em ON e.created_by = em.id 
            WHERE 1=1";
        $exp_params = [];

        if ($f_period == 'this_month') {
            $sql_exp .= " AND e.date >= '" . date('Y-m-01') . "'";
        } elseif ($f_period == 'last_7_days') {
            $sql_exp .= " AND e.date >= '" . date('Y-m-d', strtotime('-7 days')) . "'";
        } elseif (!empty($f_month)) {
            $sql_exp .= " AND DATE_FORMAT(e.date, '%Y-%m') = ?";
            $exp_params[] = $f_month;
        } elseif (!empty($f_start) && !empty($f_end)) {
            $sql_exp .= " AND e.date BETWEEN ? AND ?";
            $exp_params[] = $f_start;
            $exp_params[] = $f_end;
        }

        $sql_exp .= " ORDER BY e.date DESC, e.id DESC";
        $stmt_exp = $pdo->prepare($sql_exp);
        $stmt_exp->execute($exp_params);
        $expensesLog = $stmt_exp->fetchAll();

        try {
            // MERGE PAYMENTS (ADVANCES) into Expenses Log as requested
            $sql_pay = "SELECT p.*, e.full_name as employee_name, em.full_name as creator_name 
                FROM payments p 
                JOIN employees e ON p.employee_id = e.id 
                LEFT JOIN employees em ON p.created_by = em.id 
                WHERE p.is_archived = 0";
            $pay_params = [];
            if ($f_period == 'this_month') {
                $sql_pay .= " AND p.created_at >= '" . date('Y-m-01') . " 00:00:00'";
            } elseif ($f_period == 'last_7_days') {
                $sql_pay .= " AND p.created_at >= '" . date('Y-m-d', strtotime('-7 days')) . " 00:00:00'";
            } elseif (!empty($f_month)) {
                $sql_pay .= " AND DATE_FORMAT(p.created_at, '%Y-%m') = ?";
                $pay_params[] = $f_month;
            } elseif (!empty($f_start) && !empty($f_end)) {
                $sql_pay .= " AND p.created_at BETWEEN ? AND ?";
                $pay_params[] = $f_start . " 00:00:00";
                $pay_params[] = $f_end . " 23:59:59";
            }
            $stmt_pay = $pdo->prepare($sql_pay);
            $stmt_pay->execute($pay_params);
            $payments_exp = $stmt_pay->fetchAll();

            foreach($payments_exp as $p) {
                $expensesLog[] = [
                    'id' => 'pay_' . $p['id'],
                    'category_name' => 'Xodimlarga to\'lov',
                    'amount' => $p['amount'],
                    'description' => 'Avans: ' . $p['employee_name'] . ($p['comment'] ? ' (' . $p['comment'] . ')' : ''),
                    'date' => date('Y-m-d', strtotime($p['created_at'])),
                    'branch_name' => 'Barchasi',
                    'creator_name' => $p['creator_name'],
                    'is_payment' => true
                ];
            }
        } catch(Exception $e) {}

        // Re-sort combined list by date DESC
        usort($expensesLog, fn($a, $b) => strcmp($b['date'], $a['date']));
    }

    // Role-specific Data Fetching
    if ($isAdmin) {
        $employees = $pdo->query("SELECT e.*, b.name as branch_name, r.name as custom_role_name FROM employees e LEFT JOIN branches b ON e.branch_id = b.id LEFT JOIN roles r ON e.role_id = r.id")->fetchAll();
        
        $sql_records = "SELECT a.id, a.employee_id, a.type, a.timestamp, a.latitude, a.longitude, a.branch_id, a.hourly_rate,
            CASE
                WHEN a.image_url LIKE 'data:image%' AND LENGTH(a.image_url) > 50000 THEN LEFT(a.image_url, 100)
                ELSE a.image_url
            END as image_url,
            e.full_name, b.name as branch_name
            FROM attendance a 
            JOIN employees e ON a.employee_id = e.id 
            LEFT JOIN branches b ON a.branch_id = b.id 
            WHERE a.is_archived = 0";
        $records_params = [];

        if ($f_branch) {
            $sql_records .= " AND a.branch_id = ?";
            $records_params[] = $f_branch;
        }

        // Apply Time Filters to Attendance Records (Crucial for reporting accuracy)
        if ($f_date) {
            $sql_records .= " AND DATE(a.timestamp) = ?";
            $records_params[] = $f_date;
        } elseif ($f_period == 'this_month') {
            $sql_records .= " AND a.timestamp >= '" . date('Y-m-01') . " 00:00:00'";
        } elseif (!empty($f_month)) {
            $sql_records .= " AND DATE_FORMAT(a.timestamp, '%Y-%m') = ?";
            $records_params[] = $f_month;
        } elseif (!empty($f_start) && !empty($f_end)) {
            $sql_records .= " AND a.timestamp BETWEEN ? AND ?";
            $records_params[] = $f_start . " 00:00:00";
            $records_params[] = $f_end . " 23:59:59";
        }

        $sql_records .= " ORDER BY a.timestamp DESC";
        if (empty($f_date) && empty($f_month) && empty($f_start)) {
            $sql_records .= " LIMIT 2000"; // Increased limit for better coverage when no filter
        }

        $stmt_records = $pdo->prepare($sql_records);
        $stmt_records->execute($records_params);
        $records = $stmt_records->fetchAll();

        $absences = $pdo->query("SELECT ab.*, e.full_name, b.name as branch_name FROM absences ab JOIN employees e ON ab.employee_id = e.id LEFT JOIN branches b ON ab.branch_id = b.id WHERE ab.is_archived = 0 ORDER BY status='pending' DESC, start_time DESC")->fetchAll();
        $fines = $pdo->query("SELECT f.*, e.full_name, b.name as branch_name FROM fines f LEFT JOIN employees e ON f.employee_id = e.id LEFT JOIN branches b ON e.branch_id = b.id WHERE f.is_archived = 0 ORDER BY FIELD(f.status, 'pending') DESC, f.date DESC")->fetchAll();
        $fineTypes = $pdo->query("SELECT * FROM fine_types ORDER BY id DESC")->fetchAll();
    } elseif ($isManager) {
        $brId = $user['branch_id'];
        $employees = $pdo->prepare("SELECT e.*, b.name as branch_name, r.name as custom_role_name FROM employees e LEFT JOIN branches b ON e.branch_id = b.id LEFT JOIN roles r ON e.role_id = r.id WHERE e.branch_id = ? OR e.id = ?");
        $employees->execute([$brId, $user['id']]);
        $employees = $employees->fetchAll();

        $sql_rec_m = "SELECT a.id, a.employee_id, a.type, a.timestamp, a.branch_id, a.image_url, a.hourly_rate, e.full_name, b.name as branch_name FROM attendance a JOIN employees e ON a.employee_id = e.id JOIN branches b ON a.branch_id = b.id WHERE a.is_archived = 0 AND (e.branch_id = ? OR a.employee_id = ?)";
        $rec_m_params = [$brId, $user['id']];
        
        if ($f_date) {
            $sql_rec_m .= " AND DATE(a.timestamp) = ?";
            $rec_m_params[] = $f_date;
        } elseif ($f_period == 'this_month') {
            $sql_rec_m .= " AND a.timestamp >= '" . date('Y-m-01') . " 00:00:00'";
        } elseif (!empty($f_month)) {
            $sql_rec_m .= " AND DATE_FORMAT(a.timestamp, '%Y-%m') = ?";
            $rec_m_params[] = $f_month;
        } elseif (!empty($f_start) && !empty($f_end)) {
            $sql_rec_m .= " AND a.timestamp BETWEEN ? AND ?";
            $rec_m_params[] = $f_start . " 00:00:00";
            $rec_m_params[] = $f_end . " 23:59:59";
        }
        $sql_rec_m .= " ORDER BY a.timestamp DESC";
        $records = $pdo->prepare($sql_rec_m);
        $records->execute($rec_m_params);
        $records = $records->fetchAll();

        $absences = $pdo->prepare("SELECT ab.*, e.full_name, b.name as branch_name FROM absences ab JOIN employees e ON ab.employee_id = e.id LEFT JOIN branches b ON ab.branch_id = b.id WHERE ab.is_archived = 0 AND (e.branch_id = ? OR ab.employee_id = ?) ORDER BY start_time DESC");
        $absences->execute([$brId, $user['id']]);
        $absences = $absences->fetchAll();
    } else {
        $employees = [$user];
        $sql_rec_e = "SELECT a.id, a.employee_id, a.type, a.timestamp, a.branch_id, a.image_url, a.hourly_rate, e.full_name, b.name as branch_name FROM attendance a JOIN employees e ON a.employee_id = e.id JOIN branches b ON a.branch_id = b.id WHERE a.is_archived = 0 AND a.employee_id = ?";
        $rec_e_params = [$user['id']];

        if ($f_date) {
            $sql_rec_e .= " AND DATE(a.timestamp) = ?";
            $rec_e_params[] = $f_date;
        } elseif ($f_period == 'this_month') {
            $sql_rec_e .= " AND a.timestamp >= '" . date('Y-m-01') . " 00:00:00'";
        } elseif (!empty($f_month)) {
            $sql_rec_e .= " AND DATE_FORMAT(a.timestamp, '%Y-%m') = ?";
            $rec_e_params[] = $f_month;
        } elseif (!empty($f_start) && !empty($f_end)) {
            $sql_rec_e .= " AND a.timestamp BETWEEN ? AND ?";
            $rec_e_params[] = $f_start . " 00:00:00";
            $rec_e_params[] = $f_end . " 23:59:59";
        }
        $sql_rec_e .= " ORDER BY timestamp DESC";
        $records = $pdo->prepare($sql_rec_e);
        $records->execute($rec_e_params);
        $records = $records->fetchAll();

        $absences = $pdo->prepare("SELECT ab.*, e.full_name, b.name as branch_name FROM absences ab JOIN employees e ON ab.employee_id = e.id LEFT JOIN branches b ON ab.branch_id = b.id WHERE ab.is_archived = 0 AND ab.employee_id = ? ORDER BY start_time DESC");
        $absences->execute([$user['id']]);
        $absences = $absences->fetchAll();
    }

    $last_rec = null;
    foreach ($records as $r) {
        if ($r['employee_id'] == $user['id']) {
            $last_rec = $r;
            break;
        }
    }

    // Forgot to checkout yesterday protection (allow night shifts up to 16 hours)
    if ($last_rec && $last_rec['type'] == 'check-in') {
        $last_ts = strtotime($last_rec['timestamp']);
        $diff_hours = (time() - $last_ts) / 3600;
        if ($diff_hours > 16) {
            // Ignore stale open session
            $last_rec = null; 
        }
    }

    $status = ($last_rec && $last_rec['type'] == 'check-in') ? 'checked-in' : 'checked-out';
    // Save Payment (Advance)
    if (isset($_POST['save_payment']) && ($user['role'] == 'superadmin' || $user['role'] == 'admin')) {
        $emp_id = $_POST['employee_id'];
        $amount = $_POST['amount'];
        $type = $_POST['type'];
        $comment = $_POST['comment'] ?? '';

        $stmt = $pdo->prepare("INSERT INTO payments (employee_id, amount, type, comment, created_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$emp_id, $amount, $type, $comment, $user['id']]);
        
        // Update balance
        $pdo->prepare("UPDATE employees SET balance = balance - ? WHERE id = ?")->execute([$amount, $emp_id]);

        header("Location: index.php?tab=payments");
        exit;
    }

    // Delete Payment
    if (isset($_GET['del_payment']) && ($user['role'] == 'superadmin' || $user['role'] == 'admin')) {
        $id = $_GET['del_payment'];
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$id]);
        $p = $stmt->fetch();
        if ($p) {
            $pdo->prepare("UPDATE employees SET balance = balance + ? WHERE id = ?")->execute([$p['amount'], $p['employee_id']]);
            $pdo->prepare("DELETE FROM payments WHERE id = ?")->execute([$id]);
            header("Location: index.php?tab=payments&success=O'chirildi");
            exit;
        }
    }

    // Edit Payment
    if (isset($_POST['edit_payment']) && ($user['role'] == 'superadmin' || $user['role'] == 'admin')) {
        $id = $_POST['payment_id'];
        $new_amount = (float)$_POST['amount'];
        $new_comment = $_POST['comment'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
        $stmt->execute([$id]);
        $old_p = $stmt->fetch();
        
        if ($old_p) {
            $diff = $new_amount - $old_p['amount'];
            $pdo->prepare("UPDATE employees SET balance = balance - ? WHERE id = ?")->execute([$diff, $old_p['employee_id']]);
            $stmt = $pdo->prepare("UPDATE payments SET amount = ?, comment = ? WHERE id = ?");
            $stmt->execute([$new_amount, $new_comment, $id]);
            header("Location: index.php?tab=payments&success=O'zgartirildi");
            exit;
        }
    }

    // Save Expense Category
    if (isset($_POST['save_expense_category']) && $isAdmin) {
        $name = $_POST['name'];
        $stmt = $pdo->prepare("INSERT INTO expense_categories (name) VALUES (?)");
        $stmt->execute([$name]);
        header("Location: index.php?tab=expense_categories");
        exit;
    }

    // Save Expense
    if (isset($_POST['save_expense']) && $isAdmin) {
        $cat_id = $_POST['category_id'];
        $amount = $_POST['amount'];
        $desc = $_POST['description'] ?? '';
        $date = $_POST['date'] ?? date('Y-m-d');
        $br_id = $_POST['branch_id'] ?: null;
        
        $stmt = $pdo->prepare("INSERT INTO expenses (category_id, amount, description, date, branch_id, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$cat_id, $amount, $desc, $date, $br_id, $user['id']]);
        header("Location: index.php?tab=expenses");
        exit;
    }

    if (isset($_POST['edit_expense']) && $isAdmin) {
        $id = $_POST['id'];
        $cat_id = $_POST['category_id'];
        $amount = $_POST['amount'];
        $desc = $_POST['description'] ?? '';
        $date = $_POST['date'] ?? date('Y-m-d');
        $br_id = $_POST['branch_id'] ?: null;

        $stmt = $pdo->prepare("UPDATE expenses SET category_id=?, amount=?, description=?, date=?, branch_id=? WHERE id=?");
        $stmt->execute([$cat_id, $amount, $desc, $date, $br_id, $id]);
        header("Location: index.php?tab=expenses&success=edit");
        exit;
    }

    // Delete Expense
    if (isset($_GET['del_expense']) && $isAdmin) {
        $pdo->prepare("DELETE FROM expenses WHERE id = ?")->execute([$_GET['del_expense']]);
        header("Location: index.php?tab=expenses");
        exit;
    }

    // Delete Expense Category
    if (isset($_GET['del_expense_cat']) && $isAdmin) {
        $pdo->prepare("DELETE FROM expense_categories WHERE id = ?")->execute([$_GET['del_expense_cat']]);
        header("Location: index.php?tab=expense_categories");
        exit;
    }

    // --- TASK ACTIONS (Superadmin Only) ---
    if ($user['role'] == 'superadmin') {
        if (isset($_POST['save_task'])) {
            $empId = $_POST['employee_id'];
            $title = $_POST['title'];
            $desc = $_POST['description'];
            $stmt = $pdo->prepare("INSERT INTO tasks (employee_id, title, description) VALUES (?, ?, ?)");
            $stmt->execute([$empId, $title, $desc]);
            header("Location: index.php?tab=tasks&success=1");
            exit;
        }
        if (isset($_POST['edit_task'])) {
            $id = $_POST['task_id'];
            $empId = $_POST['employee_id'];
            $title = $_POST['title'];
            $desc = $_POST['description'];
            $status = $_POST['status'];
            $stmt = $pdo->prepare("UPDATE tasks SET employee_id=?, title=?, description=?, status=? WHERE id=?");
            $stmt->execute([$empId, $title, $desc, $status, $id]);
            header("Location: index.php?tab=tasks&success=1");
            exit;
        }
        if (isset($_GET['del_task'])) {
            $pdo->prepare("DELETE FROM tasks WHERE id = ?")->execute([$_GET['del_task']]);
            header("Location: index.php?tab=tasks&success=1");
            exit;
        }
    }

    ?>
    <!DOCTYPE html>
    <html lang="uz">

    <head>
        <meta charset="UTF-8">
        <title>Admin Panel - WorkPay.uz</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
        <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
        <style>
            .glass {
                background: rgba(255, 255, 255, 0.7);
                backdrop-filter: blur(10px);
            }

            /* Preloader Styles */
            #preloader {
                position: fixed;
                inset: 0;
                background: #f8fafc;
                z-index: 99999;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                transition: opacity 0.5s ease, visibility 0.5s ease;
            }

            .loader-logo {
                width: 120px;
                height: 120px;
                border-radius: 20px;
                animation: pulse 1.5s infinite ease-in-out;
                box-shadow: 0 10px 25px rgba(225, 10, 20, 0.2);
            }

            @keyframes pulse {
                0% {
                    transform: scale(0.9);
                    opacity: 0.9;
                }

                50% {
                    transform: scale(1.1);
                    opacity: 1;
                }

                100% {
                    transform: scale(0.9);
                    opacity: 0.9;
                }
            }

            .custom-scrollbar::-webkit-scrollbar {
                width: 4px;
            }
            .custom-scrollbar::-webkit-scrollbar-track {
                background: #f1f5f9;
            }
            .custom-scrollbar::-webkit-scrollbar-thumb {
                background: #e2e8f0;
                border-radius: 10px;
            }
            .custom-scrollbar::-webkit-scrollbar-thumb:hover {
                background: #cbd5e1;
            }
        </style>
        <script>
            window.addEventListener('load', function () {
                var preloader = document.getElementById('preloader');
                preloader.style.opacity = '0';
                preloader.style.visibility = 'hidden';
            });
        </script>
    </head>

    <body class="bg-slate-50 font-sans text-slate-900">

        <nav class="bg-blue-600 text-white p-4 shadow-xl flex justify-between items-center sticky top-0 z-40 px-8">
            <h1 class="text-2xl font-black tracking-tighter">DAVOMAT<span class="text-blue-200">APP</span></h1>
            <div class="flex items-center gap-6 text-sm">
                <span class="opacity-90 font-medium">Salom, <b class="text-white"><?= $user['full_name'] ?></b> <span
                        class="bg-blue-500 px-2 py-0.5 rounded text-[10px] uppercase ml-1"><?= $user['role'] ?></span></span>
                <a href="?logout=1"
                    class="bg-rose-500 hover:bg-rose-600 px-5 py-2 rounded-xl font-bold transition-all shadow-lg shadow-rose-900/20">Chiqish</a>
            </div>
        </nav>

        <div class="flex">
            <!-- Sidebar -->
            <div class="w-72 bg-white min-h-screen border-r border-slate-100 p-6 sticky top-[72px] h-[calc(100vh-72px)] overflow-y-auto custom-scrollbar">
                <nav class="space-y-2">
                    <?php if (hasPermission($user, 'dashboard')): ?>
                        <a href="?tab=dashboard"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'dashboard' ? 'bg-blue-50 text-blue-600 shadow-sm shadow-blue-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-dashboard-fill mr-4 text-xl"></i> Dashboard
                        </a>
                    <?php endif; ?>

                    <?php if ($isManager || $user['role'] == 'employee'): ?>
                        <a href="?tab=attendance"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'attendance' ? 'bg-emerald-50 text-emerald-600 shadow-sm shadow-emerald-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-calendar-check-fill mr-4 text-xl"></i> Davomat
                        </a>
                    <?php endif; ?>

                    <a href="?tab=reporting"
                        class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'reporting' ? 'bg-blue-50 text-blue-600 shadow-sm shadow-blue-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                        <i class="ri-file-chart-fill mr-4 text-xl"></i> Hisobotlar
                    </a>

                    <?php if (hasPermission($user, 'calendar')): ?>
                        <a href="?tab=calendar"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'calendar' ? 'bg-blue-50 text-blue-600 shadow-sm shadow-blue-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-calendar-todo-fill mr-4 text-xl"></i> Kalendar
                        </a>
                    <?php endif; ?>

                    <?php if (hasPermission($user, 'employees')): ?>
                        <a href="?tab=employees"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'employees' ? 'bg-blue-50 text-blue-600 shadow-sm shadow-blue-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-group-fill mr-4 text-xl"></i> Xodimlar
                        </a>
                    <?php endif; ?>

                    <?php if (hasPermission($user, 'branches')): ?>
                        <a href="?tab=branches"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'branches' ? 'bg-blue-50 text-blue-600 shadow-sm shadow-blue-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-map-pin-2-fill mr-4 text-xl"></i> Filiallar
                        </a>
                    <?php endif; ?>

                    <?php if (hasPermission($user, 'absences')): ?>
                        <a href="?tab=absences"
                            class="flex items-center justify-between p-3.5 rounded-2xl font-bold <?= $tab == 'absences' ? 'bg-orange-50 text-orange-600 shadow-sm shadow-orange-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <div class="flex items-center">
                                <i class="ri-alarm-warning-fill mr-4 text-xl"></i> Uzoqlashishlar
                            </div>
                            <?php if ($pending_abs > 0 && $isAdmin): ?>
                                <span class="bg-orange-500 text-white text-[10px] px-2 py-0.5 rounded-full"><?= $pending_abs ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPermission($user, 'offdays') || $user['role'] == 'employee'): ?>
                        <a href="?tab=offdays"
                            class="flex items-center justify-between p-3.5 rounded-2xl font-bold <?= $tab == 'offdays' ? 'bg-purple-50 text-purple-600 shadow-sm shadow-purple-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <div class="flex items-center">
                                <i class="ri-calendar-event-fill mr-4 text-xl"></i> Dam olish so'rovlari
                            </div>
                            <?php if ($pending_off > 0 && $isAdmin): ?>
                                <span class="bg-purple-500 text-white text-[10px] px-2 py-0.5 rounded-full"><?= $pending_off ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPermission($user, 'payments')): ?>
                        <a href="?tab=payments"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'payments' ? 'bg-indigo-50 text-indigo-600 shadow-sm shadow-indigo-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-bank-card-fill mr-4 text-xl"></i> To'lovlar (Avans)
                        </a>
                    <?php endif; ?>

                    <?php if (hasPermission($user, 'fines')): ?>
                        <a href="?tab=fines"
                            class="flex items-center justify-between p-3.5 rounded-2xl font-bold <?= $tab == 'fines' ? 'bg-rose-50 text-rose-600 shadow-sm shadow-rose-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <div class="flex items-center">
                                <i class="ri-error-warning-fill mr-4 text-xl"></i> Jarimalar
                            </div>
                            <?php if ($pending_fines > 0 && $isAdmin): ?>
                                <span class="bg-rose-500 text-white text-[10px] px-2 py-0.5 rounded-full"><?= $pending_fines ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if (hasPermission($user, 'fine_types')): ?>
                        <a href="?tab=fine_types"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'fine_types' ? 'bg-orange-50 text-orange-600 shadow-sm shadow-orange-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-settings-4-fill mr-4 text-xl"></i> Jarima turlari
                        </a>
                    <?php endif; ?>

                    <?php if ($isAdmin): ?>
                        <a href="?tab=notifications"
                            class="flex items-center justify-between p-3.5 rounded-2xl font-bold <?= $tab == 'notifications' ? 'bg-amber-50 text-amber-600 shadow-sm shadow-amber-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <div class="flex items-center">
                                <i class="ri-notification-4-fill mr-4 text-xl"></i> Bildirishnomalar
                            </div>
                            <?php 
                                try {
                                    $unread_notifs = $pdo->query("SELECT COUNT(*) FROM notifications WHERE is_read = 0")->fetchColumn();
                                } catch(Exception $e) { $unread_notifs = 0; }
                                if ($unread_notifs > 0): 
                            ?>
                                <span class="bg-amber-500 text-white text-[10px] px-2 py-0.5 rounded-full"><?= $unread_notifs ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>

                    <?php if ($isAdmin): ?>
                        <a href="?tab=warnings"
                            class="flex items-center justify-between p-3.5 rounded-2xl font-bold <?= $tab == 'warnings' ? 'bg-amber-50 text-amber-600 shadow-sm shadow-amber-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <div class="flex items-center">
                                <i class="ri-error-warning-line mr-4 text-xl"></i> Kechikish xabarlari
                            </div>
                            <?php if ($pending_warnings > 0): ?>
                                <span class="bg-amber-500 text-white text-[10px] px-2 py-0.5 rounded-full"><?= $pending_warnings ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="?tab=tasks"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'tasks' ? 'bg-blue-600 text-white shadow-lg' : 'text-slate-400 hover:bg-slate-50' ?> transition-all mb-1">
                            <i class="ri-task-fill mr-4 text-xl"></i> Vazifalar
                        </a>
                        <div class="h-px bg-slate-100 my-4 mx-2"></div>
                        <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-4 ml-4">Moliya & Xarajatlar</p>
                        
                        <a href="?tab=expenses"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'expenses' ? 'bg-rose-50 text-rose-600 shadow-sm shadow-rose-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-money-dollar-circle-fill mr-4 text-xl"></i> Xarajatlar
                        </a>
                        
                        <a href="?tab=expense_categories"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'expense_categories' ? 'bg-slate-100 text-slate-800 shadow-sm' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-list-settings-line mr-4 text-xl"></i> Xarajat turlari
                        </a>
                    <?php endif; ?>

                    <?php if (hasPermission($user, 'faceid')): ?>
                        <a href="?tab=faceid"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'faceid' ? 'bg-indigo-50 text-indigo-600 shadow-sm shadow-indigo-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-face-recognition-fill mr-4 text-xl"></i> Face ID
                        </a>
                    <?php endif; ?>

                    <?php if ($user['role'] == 'superadmin'): ?>
                        <div class="pt-4 pb-2 border-t mt-4 mb-2">
                             <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest ml-4">Tizim boshqaruvi</p>
                        </div>
                        <a href="?tab=monthly_reports"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'monthly_reports' ? 'bg-slate-800 text-white shadow-lg' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-archive-fill mr-4 text-xl"></i> Oylar arxivi
                        </a>
                        
                        <?php 
                        $is_last_day = (date('t') == date('d'));
                        if ($is_last_day): ?>
                            <a href="?close_month=1" onclick="return confirm('Haqiqatan ham oyni yakunlamoqchimisiz? Barcha xodimlar balansi 0 bo\'lib qayta hisoblanadi va arxivlanadi!')"
                                class="flex items-center p-3.5 rounded-2xl font-bold text-rose-600 hover:bg-rose-50 transition-all border border-dashed border-rose-200 mt-2">
                                <i class="ri-close-circle-fill mr-4 text-xl"></i> OY YAKUNI
                            </a>
                        <?php endif; ?>

                        <a href="?tab=roles"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'roles' ? 'bg-blue-50 text-blue-600 shadow-sm shadow-blue-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-shield-keyhole-fill mr-4 text-xl"></i> Maxsus rollar
                        </a>
                        <a href="?tab=logs"
                            class="flex items-center p-3.5 rounded-2xl font-bold <?= $tab == 'logs' ? 'bg-blue-50 text-blue-600 shadow-sm shadow-blue-100' : 'text-slate-400 hover:bg-slate-50' ?> transition-all">
                            <i class="ri-history-line mr-4 text-xl"></i> O'zgartirishlar
                        </a>
                    <?php endif; ?>
                </nav>
            </div>

            <?php if ($user['role'] == 'superadmin'): ?>
                <!-- Edit Attendance Modal (Superadmin) -->
                <div id="editAttModal" class="hidden fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[100] flex items-center justify-center p-4">
                    <div class="bg-white rounded-[2.5rem] w-full max-w-lg shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
                        <div class="p-8 border-b flex justify-between items-center bg-slate-50/50">
                            <h3 class="text-xl font-black text-slate-800">Vaqtni o'zgartirish</h3>
                            <button onclick="document.getElementById('editAttModal').classList.add('hidden')" class="text-slate-400 hover:text-rose-500 transition-colors">
                                <i class="ri-close-line text-2xl"></i>
                            </button>
                        </div>
                        <form method="POST" class="p-8 space-y-6">
                            <input type="hidden" name="attendance_id" id="edit_att_id">
                            <div class="space-y-4">
                                <p class="text-sm font-bold text-slate-500">Turi: <span id="edit_att_type" class="text-blue-600"></span></p>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="space-y-2">
                                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Sana</label>
                                        <input type="date" name="new_date" id="edit_att_date" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                                    </div>
                                    <div class="space-y-2">
                                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Vaqt (HH:mm)</label>
                                        <input type="time" name="new_time" id="edit_att_time" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label class="text-[10px] font-black text-slate-400 uppercase ml-2">O'zgartirish sababi</label>
                                    <textarea name="reason" rows="3" placeholder="Masalan: Kechikib kelganini oqladi..." class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required></textarea>
                                </div>
                            </div>
                            <div class="flex gap-4 pt-4">
                                <button type="submit" name="update_attendance_time" class="flex-1 bg-indigo-600 text-white p-5 rounded-[2rem] font-black shadow-xl shadow-indigo-500/20 hover:bg-indigo-700 active:scale-95 transition-all">O'ZGARTIRISHNI SAQLASH</button>
                            </div>
                        </form>
                    </div>
                </div>

                <script>
                    function openEditModal(id, time, date, type, empId = null, inId = null) {
                        if (!id && !empId) return;
                        document.getElementById('editAttModal').classList.remove('hidden');
                        document.getElementById('edit_att_id').value = id || '';
                        document.getElementById('edit_att_time').value = (time && !time.includes('Ishda') && !time.includes('Kutilmoqda')) ? time : '';
                        document.getElementById('edit_att_date').value = date;
                        document.getElementById('edit_att_type').innerText = type;
                        
                        // Add manual fields to form if not exists
                        let form = document.querySelector('#editAttModal form');
                        if (!document.getElementById('man_emp_id')) {
                            let h1 = document.createElement('input'); h1.type='hidden'; h1.name='manual_employee_id'; h1.id='man_emp_id';
                            let h2 = document.createElement('input'); h2.type='hidden'; h2.name='manual_in_id'; h2.id='man_in_id';
                            form.appendChild(h1); form.appendChild(h2);
                        }
                        document.getElementById('man_emp_id').value = empId || '';
                        document.getElementById('man_in_id').value = inId || '';
                    }
                </script>
            <?php endif; ?>

            <!-- Main -->
            <div class="flex-1 p-10">

                <?php if ($isAdmin && $total_pending > 0): ?>
                    <div class="bg-indigo-600 p-8 rounded-[2rem] mb-10 flex items-center justify-between shadow-2xl shadow-indigo-500/30">
                        <div class="flex items-center gap-6">
                            <div class="w-16 h-16 bg-white/20 rounded-2xl flex items-center justify-center text-white text-3xl">
                                <i class="ri-notification-3-fill animate-bounce"></i>
                            </div>
                            <div>
                                <h3 class="text-xl font-black text-white">Diqqat, tasdiqlash uchun <?= $total_pending ?> ta harakat mavjud!</h3>
                                <p class="text-indigo-100 font-medium">Uzoqlashishlar, dam olishlar yoki jarimalarni ko'rib chiqing.</p>
                            </div>
                        </div>
                        <div class="flex gap-2">
                             <?php if ($pending_abs > 0): ?><a href="?tab=absences" class="bg-white/20 text-white px-4 py-2 rounded-xl text-[10px] font-black hover:bg-white/30 transition-all uppercase">Uzoqlashish (<?= $pending_abs ?>)</a><?php endif; ?>
                             <?php if ($pending_off > 0): ?><a href="?tab=offdays" class="bg-white/20 text-white px-4 py-2 rounded-xl text-[10px] font-black hover:bg-white/30 transition-all uppercase">Dam olish (<?= $pending_off ?>)</a><?php endif; ?>
                             <?php if ($pending_fines > 0): ?><a href="?tab=fines" class="bg-white/20 text-white px-4 py-2 rounded-xl text-[10px] font-black hover:bg-white/30 transition-all uppercase">Jarimalar (<?= $pending_fines ?>)</a><?php endif; ?>
                             <?php if ($pending_warnings > 0): ?><a href="?tab=warnings" class="bg-white/20 text-white px-4 py-2 rounded-xl text-[10px] font-black hover:bg-white/30 transition-all uppercase">Xabarlar (<?= $pending_warnings ?>)</a><?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($tab == 'dashboard' && $isAdmin): ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Dashboard</h2>
                    </div>

                    <!-- Web Filters -->
                    <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100 mb-8 flex gap-6 items-end">
                        <form method="GET" class="flex flex-1 gap-6 items-end">
                            <input type="hidden" name="tab" value="<?= $tab ?>">
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Filial bo'yicha</label>
                                <select name="f_branch" class="w-full p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                    <option value="">Barchasi</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= $b['id'] ?>" <?= $f_branch == $b['id'] ? 'selected' : '' ?>><?= $b['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <?php if ($tab == 'reporting' || $tab == 'absences'): ?>
                                <div class="flex-1 space-y-2">
                                    <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Sana bo'yicha</label>
                                    <input type="date" name="f_date" value="<?= $f_date ?>" class="w-full p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                </div>
                            <?php endif; ?>
                            <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-2xl font-black text-xs hover:bg-blue-700 transition-all">FILTRLASH</button>
                            <?php if ($f_branch || $f_date): ?>
                                <a href="?tab=<?= $tab ?>&clear_filters=1" class="bg-slate-100 text-slate-500 px-6 py-3 rounded-2xl font-black text-xs hover:bg-slate-200 transition-all">TOZALASH</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <!-- Active Employees Stats -->
                    <?php 
                    // Daily Metrics Calculation
                    $today_str = date('Y-m-d');
                    $today_start = $today_str . ' 00:00:00';
                    $today_end = $today_str . ' 23:59:59';
                    
                    // Base query helper
                    $branch_clause = $f_branch ? " AND e.branch_id = ?" : "";
                    
                    // 1. Total Employees (EXCLUDING ADMINS)
                    $q_total_list = "SELECT e.id, e.full_name, e.work_start_time, e.work_end_time, b.name as branch_name FROM employees e JOIN branches b ON e.branch_id = b.id WHERE e.role NOT IN ('admin', 'superadmin')" . $branch_clause;
                    $stmt_total = $pdo->prepare($q_total_list);
                    $f_branch ? $stmt_total->execute([$f_branch]) : $stmt_total->execute();
                    $all_emps_list = $stmt_total->fetchAll(PDO::FETCH_ASSOC);
                    $total_count = count($all_emps_list);

                    // 2. Kelganlar List
                    $q_kelgan_list = "SELECT e.id, e.full_name, e.work_start_time, e.work_end_time, b.name as branch_name, MIN(a.timestamp) as arrival_time 
                                     FROM attendance a JOIN employees e ON a.employee_id = e.id JOIN branches b ON e.branch_id = b.id 
                                     WHERE a.type = 'check-in' AND a.timestamp >= ? AND a.timestamp < ? AND e.role NOT IN ('admin', 'superadmin')" . $branch_clause . " GROUP BY e.id";
                    $stmt_k = $pdo->prepare($q_kelgan_list);
                    $f_branch ? $stmt_k->execute([$today_start, $today_end, $f_branch]) : $stmt_k->execute([$today_start, $today_end]);
                    $kelgan_list = array_map(function($e) { $e['status_icon'] = '✅'; return $e; }, $stmt_k->fetchAll(PDO::FETCH_ASSOC));
                    $kelgan_count = count($kelgan_list);
                    $kelgan_ids = array_column($kelgan_list, 'id');

                    // 3. Dam oladiganlar List
                    $q_dam_list = "SELECT e.id, e.full_name, e.work_start_time, e.work_end_time, b.name as branch_name 
                                  FROM off_day_requests o JOIN employees e ON o.employee_id = e.id JOIN branches b ON e.branch_id = b.id 
                                  WHERE o.status = 'approved' AND o.request_date = ? AND e.role NOT IN ('admin', 'superadmin')" . $branch_clause;
                    $stmt_d = $pdo->prepare($q_dam_list);
                    $f_branch ? $stmt_d->execute([$today_str, $f_branch]) : $stmt_d->execute([$today_str]);
                    $dam_list = array_map(function($e) { $e['status_icon'] = '🌴'; return $e; }, $stmt_d->fetchAll(PDO::FETCH_ASSOC));
                    $dam_count = count($dam_list);
                    $dam_ids = array_column($dam_list, 'id');

                    // 4. Kelmaganlar List (All - Arrived - Off-day)
                    $kelmagan_list = array_map(function($e) { $e['status_icon'] = '❌'; return $e; }, array_filter($all_emps_list, fn($e) => !in_array($e['id'], $kelgan_ids) && !in_array($e['id'], $dam_ids)));
                    $kelmagan_count = count($kelmagan_list);

                    // 5. Kechikkanlar List
                    $late_list = array_filter($kelgan_list, function($e) use ($pdo) {
                        $stmt = $pdo->prepare("SELECT work_start_time FROM employees WHERE id = ?");
                        $stmt->execute([$e['id']]);
                        $w_start = $stmt->fetchColumn();
                        return date('H:i:s', strtotime($e['arrival_time'])) > date('H:i:s', strtotime($w_start));
                    });
                    // Add fine info to each late employee
                    $late_list = array_map(function($e) use ($pdo, $today_str) {
                        try {
                            $stmt_fine = $pdo->prepare("SELECT amount, status FROM fines WHERE employee_id = ? AND DATE(date) = ? ORDER BY FIELD(status,'approved','pending') LIMIT 1");
                            $stmt_fine->execute([$e['id'], $today_str]);
                            $fine_row = $stmt_fine->fetch(PDO::FETCH_ASSOC);
                            if ($fine_row) {
                                $e['has_fine'] = true;
                                $e['fine_amount'] = (int)$fine_row['amount'];
                                $e['fine_status'] = $fine_row['status'];
                            } else {
                                $e['has_fine'] = false;
                            }
                        } catch(Exception $ex) {
                            $e['has_fine'] = false;
                        }
                        return $e;
                    }, $late_list);
                    $late_count = count($late_list);

                    // 6. Erta ketganlar List
                    $q_early_list = "SELECT e.id, e.full_name, e.work_start_time, e.work_end_time, b.name as branch_name, MAX(a.timestamp) as exit_time 
                                    FROM attendance a JOIN employees e ON a.employee_id = e.id JOIN branches b ON e.branch_id = b.id 
                                    WHERE a.type = 'check-out' AND a.timestamp >= ? AND a.timestamp < ? AND e.role NOT IN ('admin', 'superadmin')" . $branch_clause . " GROUP BY e.id";
                    $stmt_e = $pdo->prepare($q_early_list);
                    $f_branch ? $stmt_e->execute([$today_start, $today_end, $f_branch]) : $stmt_e->execute([$today_start, $today_end]);
                    $early_raw = $stmt_e->fetchAll(PDO::FETCH_ASSOC);
                    $early_list = array_filter($early_raw, function($e) use ($pdo) {
                        $stmt = $pdo->prepare("SELECT work_end_time FROM employees WHERE id = ?");
                        $stmt->execute([$e['id']]);
                        $w_end = $stmt->fetchColumn();
                        return date('H:i:s', strtotime($e['exit_time'])) < date('H:i:s', strtotime($w_end));
                    });
                    $early_count = count($early_list);

                    // 7. Ishga kelishi kerak bo'lganlar List (Expected Today = All - Dam oladiganlar)
                    $expected_today_list = array_map(function($emp) use ($kelgan_ids, $kelgan_list) {
                        $e = $emp;
                        $is_kelgan = in_array($e['id'], $kelgan_ids);
                        $e['status_icon'] = $is_kelgan ? '✅' : '❌';
                        if ($is_kelgan) {
                            foreach($kelgan_list as $k) {
                                if($k['id'] == $e['id']) {
                                    $e['arrival_time'] = $k['arrival_time'];
                                    break;
                                }
                            }
                        }
                        return $e;
                    }, array_filter($all_emps_list, fn($e) => !in_array($e['id'], $dam_ids)));
                    $expected_today_count = count($expected_today_list);

                    // Original Active Employees logic...
                    $stmt_active = $pdo->query("SELECT e.*, b.name as branch_name FROM attendance a1 JOIN employees e ON a1.employee_id = e.id JOIN branches b ON a1.branch_id = b.id JOIN (SELECT employee_id, MAX(timestamp) as max_ts FROM attendance GROUP BY employee_id) a2 ON a1.employee_id = a2.employee_id AND a1.timestamp = a2.max_ts WHERE a1.type = 'check-in' AND a1.timestamp > DATE_SUB(NOW(), INTERVAL 16 HOUR)");
                    $activeRaw = $stmt_active->fetchAll(PDO::FETCH_ASSOC);
                    $activeSnippetsByEmp = [];
                    if (!empty($activeRaw)) {
                        $activeIds = array_column($activeRaw, 'id');
                        $placeholders = implode(',', array_fill(0, count($activeIds), '?'));
                        $stmt_snips = $pdo->prepare("SELECT * FROM attendance WHERE employee_id IN ($placeholders) ORDER BY timestamp DESC LIMIT 300");
                        $stmt_snips->execute($activeIds);
                        $allSnipsraw = $stmt_snips->fetchAll(PDO::FETCH_ASSOC);
                        foreach($activeIds as $eid) {
                            $eSnips = array_filter($allSnipsraw, fn($s) => $s['employee_id'] == $eid);
                            $sessions_snippet = [];
                            $lastIn_snippet = null;
                            usort($eSnips, fn($a, $b) => strcmp($a['timestamp'], $b['timestamp']));
                            foreach($eSnips as $rs) {
                                if($rs['type'] == 'check-in') { $lastIn_snippet = $rs; }
                                elseif($rs['type'] == 'check-out' && $lastIn_snippet) { $sessions_snippet[] = ['check_in' => $lastIn_snippet['timestamp'], 'check_out' => $rs['timestamp']]; $lastIn_snippet = null; }
                            }
                            if($lastIn_snippet) { $sessions_snippet[] = ['check_in' => $lastIn_snippet['timestamp'], 'check_out' => null]; }
                            usort($sessions_snippet, fn($a, $b) => strcmp($b['check_in'], $a['check_in']));
                            $activeSnippetsByEmp[$eid] = array_slice($sessions_snippet, 0, 10);
                        }
                    }
                    $activeByBranch = [];
                    foreach($branches as $br) { $activeByBranch[$br['id']] = ['id' => $br['id'], 'name' => $br['name'], 'count' => 0, 'employees' => []]; }
                    foreach($activeRaw as $ar) { if(isset($activeByBranch[$ar['branch_id']])) { $ar['snippets'] = $activeSnippetsByEmp[$ar['id']] ?? []; $activeByBranch[$ar['branch_id']]['count']++; $activeByBranch[$ar['branch_id']]['employees'][] = $ar; } }
                    ?>

                    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-3 gap-6 mb-12">
                        <!-- Card 1: Ishga kelganlar -->
                        <div onclick="showSummaryModal('Ishga Kelganlar', <?= htmlspecialchars(json_encode(array_values($kelgan_list)), ENT_QUOTES, 'UTF-8') ?>)" 
                             class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-col gap-2 border-t-4 border-t-emerald-500 cursor-pointer hover:shadow-md transition-all">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Bugun ishga kelganlar</p>
                            <div class="flex items-baseline gap-2">
                                <p class="text-3xl font-black text-slate-800"><?= $kelgan_count ?></p>
                                <p class="text-slate-400 font-bold">/ <?= $total_count ?></p>
                            </div>
                            <div class="w-full bg-slate-100 h-1.5 rounded-full mt-2 overflow-hidden">
                                <div class="bg-emerald-500 h-full rounded-full" style="width: <?= $total_count > 0 ? ($kelgan_count / $total_count * 100) : 0 ?>%"></div>
                            </div>
                        </div>

                        <!-- Card 2: Kelmaganlar -->
                        <div onclick="showSummaryModal('Kelmganlar (Faqat ish kuni bo‘lganlar)', <?= htmlspecialchars(json_encode(array_values($kelmagan_list)), ENT_QUOTES, 'UTF-8') ?>)" 
                             class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-col gap-2 border-t-4 border-t-rose-500 cursor-pointer hover:shadow-md transition-all">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Bugun ishga kelmaganlar</p>
                            <div class="flex items-baseline gap-2">
                                <p class="text-3xl font-black text-slate-800"><?= $kelmagan_count ?></p>
                                <p class="text-slate-400 font-bold">/ <?= $total_count ?></p>
                            </div>
                            <div class="w-full bg-slate-100 h-1.5 rounded-full mt-2 overflow-hidden">
                                <div class="bg-rose-500 h-full rounded-full" style="width: <?= $total_count > 0 ? ($kelmagan_count / $total_count * 100) : 0 ?>%"></div>
                            </div>
                        </div>

                        <!-- Card 3: Ishga kelishi kerak bo'lganlar (NEW) -->
                        <div onclick="showSummaryModal('Ishga kelishi kerak bo\'lganlar', <?= htmlspecialchars(json_encode(array_values($expected_today_list)), ENT_QUOTES, 'UTF-8') ?>)" 
                             class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-col gap-2 border-t-4 border-t-amber-500 cursor-pointer hover:shadow-md transition-all">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Bugun ishga kelishi kerak bo'lganlar</p>
                            <div class="flex items-baseline gap-2">
                                <p class="text-3xl font-black text-slate-800"><?= $expected_today_count ?></p>
                                <p class="text-slate-400 font-bold">/ <?= $total_count ?></p>
                            </div>
                            <div class="w-full bg-slate-100 h-1.5 rounded-full mt-2 overflow-hidden">
                                <div class="bg-amber-500 h-full rounded-full" style="width: <?= $total_count > 0 ? ($expected_today_count / $total_count * 100) : 0 ?>%"></div>
                            </div>
                        </div>

                        <!-- Card 4: Dam oladiganlar -->
                        <div onclick="showSummaryModal('Dam Oladiganlar', <?= htmlspecialchars(json_encode(array_values($dam_list)), ENT_QUOTES, 'UTF-8') ?>)" 
                             class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-col gap-2 border-t-4 border-t-purple-500 cursor-pointer hover:shadow-md transition-all">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Bugun dam oladiganlar</p>
                            <div class="flex items-baseline gap-2">
                                <p class="text-3xl font-black text-slate-800"><?= $dam_count ?></p>
                                <p class="text-slate-400 font-bold">/ <?= $total_count ?></p>
                            </div>
                            <div class="w-full bg-slate-100 h-1.5 rounded-full mt-2 overflow-hidden">
                                <div class="bg-purple-500 h-full rounded-full" style="width: <?= $total_count > 0 ? ($dam_count / $total_count * 100) : 0 ?>%"></div>
                            </div>
                        </div>

                        <!-- Card 5: Kechikkanlar -->
                        <div onclick="showSummaryModal('Ishga Kechikib Kelganlar', <?= htmlspecialchars(json_encode(array_values($late_list)), ENT_QUOTES, 'UTF-8') ?>)" 
                             class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-col gap-2 border-t-4 border-t-orange-500 cursor-pointer hover:shadow-md transition-all">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Bugun kechikkanlar</p>
                            <div class="flex items-baseline gap-2">
                                <p class="text-3xl font-black text-slate-800"><?= $late_count ?></p>
                                <p class="text-slate-400 font-bold">/ <?= $kelgan_count ?: $total_count ?></p>
                            </div>
                            <div class="w-full bg-slate-100 h-1.5 rounded-full mt-2 overflow-hidden">
                                <div class="bg-orange-500 h-full rounded-full" style="width: <?= $kelgan_count > 0 ? ($late_count / $kelgan_count * 100) : 0 ?>%"></div>
                            </div>
                        </div>

                        <!-- Card 6: Erta ketganlar -->
                        <div onclick="showSummaryModal('Ishdan Erta Ketganlar', <?= htmlspecialchars(json_encode(array_values($early_list)), ENT_QUOTES, 'UTF-8') ?>)" 
                             class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex flex-col gap-2 border-t-4 border-t-sky-500 cursor-pointer hover:shadow-md transition-all">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Bugun erta ketganlar</p>
                            <div class="flex items-baseline gap-2">
                                <p class="text-3xl font-black text-slate-800"><?= $early_count ?></p>
                                <p class="text-slate-400 font-bold">/ <?= $kelgan_count ?: $total_count ?></p>
                            </div>
                            <div class="w-full bg-slate-100 h-1.5 rounded-full mt-2 overflow-hidden">
                                <div class="bg-sky-500 h-full rounded-full" style="width: <?= $kelgan_count > 0 ? ($early_count / $kelgan_count * 100) : 0 ?>%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="mb-6 flex items-center justify-between">
                        <h3 class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Filiallar bo'yicha faollik</h3>
                    </div>

                    <!-- Summary Detail Modal -->
                    <div id="summaryModal" class="hidden fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[60] flex items-center justify-center p-4">
                        <div class="bg-white w-full max-w-xl rounded-[2.5rem] shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
                            <div class="p-8 border-b flex justify-between items-center bg-slate-50">
                                <h3 id="sm_title" class="text-2xl font-black text-slate-800">Batafsil ma'lumot</h3>
                                <button onclick="document.getElementById('summaryModal').classList.add('hidden')" class="w-10 h-10 bg-white shadow-sm border rounded-xl flex items-center justify-center hover:bg-slate-50 transition-all">
                                    <i class="ri-close-line text-xl"></i>
                                </button>
                            </div>
                            <div id="sm_list" class="p-8 max-h-[60vh] overflow-y-auto custom-scrollbar space-y-4"></div>
                        </div>
                    </div>

                    <script>
                        function showSummaryModal(title, employees) {
                            const modal = document.getElementById('summaryModal');
                            const sm_title = document.getElementById('sm_title');
                            const sm_list = document.getElementById('sm_list');
                            
                            sm_title.innerText = title;
                            sm_list.innerHTML = '';
                            
                            if (!employees || employees.length === 0) {
                                sm_list.innerHTML = '<div class="p-10 text-center text-slate-300 italic font-medium">Hozirda ma\'lumot mavjud emas</div>';
                            } else {
                                employees.forEach(emp => {
                                    const item = document.createElement('div');
                                    item.className = 'flex items-center justify-between p-5 bg-slate-50 rounded-2xl border border-slate-100 hover:border-blue-200 transition-all';
                                    
                                    let extra = '';
                                    if(emp.arrival_time) extra = `<p class="text-[12px] font-black text-emerald-500 uppercase">Kelish: ${new Date(emp.arrival_time).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</p>`;
                                    if(emp.exit_time) extra = `<p class="text-[12px] font-black text-rose-500 uppercase">Ketish: ${new Date(emp.exit_time).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</p>`;

                                    // Show fine badge if applicable
                                    let fineBadge = '';
                                    if (emp.has_fine) {
                                        const isApproved = emp.fine_status === 'approved';
                                        const badgeColor = isApproved ? 'bg-rose-100 text-rose-600 border-rose-200' : 'bg-amber-100 text-amber-600 border-amber-200';
                                        const badgeLabel = isApproved ? 'Jarima berildi' : 'Jarima kutilmoqda';
                                        const fmtAmount = parseInt(emp.fine_amount).toLocaleString();
                                        fineBadge = `<span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[10px] font-black border ${badgeColor} mt-1"><i class="ri-error-warning-fill"></i>${badgeLabel}: ${fmtAmount} UZS</span>`;
                                    }

                                    const shift = (emp.work_start_time && emp.work_end_time) ? `<span class="text-[10px] text-slate-400 font-bold ml-1">(${emp.work_start_time.substring(0,5)}-${emp.work_end_time.substring(0,5)})</span>` : '';
                                    const statusIcon = emp.status_icon ? `<span class="ml-2">${emp.status_icon}</span>` : '';

                                    item.innerHTML = `
                                        <div class="flex items-center gap-4">
                                            <div class="w-12 h-12 bg-white rounded-xl flex items-center justify-center shadow-sm border border-slate-100">
                                                <i class="ri-user-smile-fill text-2xl text-blue-500"></i>
                                            </div>
                                            <div>
                                                <p class="font-black text-slate-800">${emp.full_name}${shift}${statusIcon}</p>
                                                <p class="text-[10px] font-bold text-slate-400 uppercase">${emp.branch_name}</p>
                                                ${fineBadge}
                                            </div>
                                        </div>
                                        <div class="text-right">
                                            ${extra}
                                        </div>
                                    `;
                                    sm_list.appendChild(item);
                                });
                            }
                            modal.classList.remove('hidden');
                        }
                    </script>
                    
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-10">
                        <?php foreach ($activeByBranch as $ac): ?>
                            <div onclick="showActiveGroup('<?= htmlspecialchars($ac['name'], ENT_QUOTES, 'UTF-8') ?>', <?= htmlspecialchars(json_encode($ac['employees']), ENT_QUOTES, 'UTF-8') ?>)" 
                                 class="bg-white p-6 rounded-[2rem] border border-slate-100 shadow-sm flex items-center gap-5 cursor-pointer hover:shadow-md transition-all border-l-4 <?= $ac['count'] > 0 ? 'border-l-emerald-500' : 'border-l-slate-200' ?>">
                                <div class="w-14 h-14 <?= $ac['count'] > 0 ? 'bg-emerald-50 text-emerald-600' : 'bg-slate-50 text-slate-300' ?> rounded-2xl flex items-center justify-center shadow-inner">
                                    <i class="ri-user-follow-fill text-2xl"></i>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-1 truncate"><?= $ac['name'] ?></p>
                                    <p class="text-2xl font-black text-slate-800"><?= $ac['count'] ?> <span class="text-xs text-slate-400 font-bold ml-1">xodim faol</span></p>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <script>
                        function showActiveGroup(branch, employees) {
                            const modal = document.getElementById('activeEmployeesModal');
                            const title = document.getElementById('ae_title');
                            const list = document.getElementById('ae_list');
                            
                            title.innerText = branch + " - Faol Xodimlar";
                            list.innerHTML = '';
                            
                            if (!employees || employees.length === 0) {
                                list.innerHTML = '<div class="p-10 text-center text-slate-300 italic font-medium">Hozirda faol xodimlar mavjud emas</div>';
                            } else {
                                employees.forEach(emp => {
                                    const item = document.createElement('div');
                                    item.className = 'flex items-center gap-4 p-4 bg-slate-50 rounded-2xl border border-slate-100 mb-3 cursor-pointer hover:bg-emerald-50 transition-all';
                                    item.onclick = () => {
                                        modal.classList.add('hidden');
                                        viewEmp(emp, emp.snippets || []);
                                    };
                                    item.innerHTML = `
                                        <div class="w-10 h-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center font-bold text-sm">
                                            ${emp.full_name.charAt(0)}
                                        </div>
                                        <div class="flex-1">
                                            <div class="font-black text-slate-700">${emp.full_name}</div>
                                            <div class="text-[10px] text-slate-400 font-bold uppercase">${emp.position}</div>
                                        </div>
                                        <div class="bg-emerald-100 text-emerald-600 px-3 py-1 rounded-lg text-[10px] font-black uppercase">Ishda</div>
                                    `;
                                    list.appendChild(item);
                                });
                            }
                            modal.classList.remove('hidden');
                        }
                    </script>

                    <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="p-6 bg-slate-50/50 border-b font-black text-slate-700">So'nggi harakatlar</div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <thead class="bg-slate-50 text-[11px] font-black text-slate-400 uppercase">
                                    <tr>
                                        <th class="p-5">Xodim</th>
                                        <th class="p-5">Tur</th>
                                        <th class="p-5">Vaqt</th>
                                        <th class="p-5">Filial</th>
                                        <th class="p-5">Foto</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 italic">
                                    <?php foreach (array_slice($records, 0, 15) as $r): ?>
                                        <tr class="border-t hover:bg-slate-50/50 transition-colors">
                                            <td class="p-5 font-bold text-slate-700"><?= $r['full_name'] ?></td>
                                            <td class="p-5"><span
                                                    class="px-3 py-1 rounded-lg text-[10px] font-black <?= $r['type'] == 'check-in' ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700' ?> uppercase"><?= $r['type'] == 'check-in' ? 'Kirish' : 'Chiqish' ?></span>
                                            </td>
                                            <td class="p-5 text-slate-500"><?= date('H:i (d.m.Y)', strtotime($r['timestamp'])) ?>
                                            </td>
                                            <td class="p-5 font-bold text-slate-600"><?= $r['branch_name'] ?></td>
                                             <td class="p-5">
                                                <?php if ($r['image_url']): ?>
                                                    <img src="<?= $r['image_url'] ?>"
                                                         onclick="document.getElementById('imgModal').classList.remove('hidden'); document.getElementById('imgModalSrc').src=this.src;"
                                                         style="width:48px;height:48px;object-fit:cover;border-radius:10px;cursor:pointer;border:2px solid #e2e8f0;"
                                                         title="Rasmni ko'rish uchun bosing">
                                                <?php else: ?>
                                                    <span class="text-slate-300">—</span>
                                                <?php endif; ?>
                                             </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                <?php elseif ($tab == 'calendar'): 
                    $cal_month = isset($_GET['cal_month']) ? (int)$_GET['cal_month'] : (int)date('m');
                    $cal_year = isset($_GET['cal_year']) ? (int)$_GET['cal_year'] : (int)date('Y');
                    
                    $days_in_month = cal_days_in_month(CAL_GREGORIAN, $cal_month, $cal_year);
                    
                    // Fetch all employees (apply visibility and branch filters)
                    $cal_emps_query = "SELECT id, full_name, role, branch_id, off_day_type FROM employees WHERE role IN ('manager', 'employee')";
                    $cal_params = [];
                    if (!$isAdmin) {
                        if ($isManager) {
                            $cal_emps_query .= " AND branch_id = ?";
                            $cal_params[] = $user['branch_id'];
                        } else {
                            $cal_emps_query .= " AND id = ?";
                            $cal_params[] = $user['id'];
                        }
                    }
                    if ($f_branch) {
                        $cal_emps_query .= " AND branch_id = ?";
                        $cal_params[] = $f_branch;
                    }
                    $cal_emps_query .= " ORDER BY full_name ASC";
                    $cal_emps = $pdo->prepare($cal_emps_query);
                    $cal_emps->execute($cal_params);
                    $cal_employees = $cal_emps->fetchAll();

                    // Fetch approved off-days for this month
                    $cal_off_query = "SELECT employee_id, request_date FROM off_day_requests WHERE status = 'approved' AND request_date LIKE ?";
                    $cal_off = $pdo->prepare($cal_off_query);
                    $cal_off->execute([$cal_year . '-' . sprintf('%02d', $cal_month) . '-%']);
                    $approved_offs_raw = $cal_off->fetchAll();
                    $approved_offs = [];
                    foreach ($approved_offs_raw as $off) {
                        $approved_offs[$off['employee_id']][] = $off['request_date'];
                    }

                    // Fetch attendance check-ins for this month
                    $cal_att_query = "SELECT employee_id, DATE(timestamp) as att_date FROM attendance WHERE type = 'check-in' AND timestamp LIKE ?";
                    $cal_att = $pdo->prepare($cal_att_query);
                    $cal_att->execute([$cal_year . '-' . sprintf('%02d', $cal_month) . '-%']);
                    $att_dates_raw = $cal_att->fetchAll();
                    $att_dates = [];
                    foreach ($att_dates_raw as $att) {
                        $att_dates[$att['employee_id']][] = $att['att_date'];
                    }
                ?>
                    <div class="mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Ish grafigi va Kalendar</h2>
                    </div>

                    <!-- Calendar Filters -->
                    <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100 mb-8 flex gap-6 items-end flex-wrap">
                        <form method="GET" class="flex flex-1 gap-6 items-end">
                            <input type="hidden" name="tab" value="calendar">
                            <div class="flex-1 space-y-2 min-w-[150px]">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Oy</label>
                                <select name="cal_month" class="w-full p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                    <?php 
                                    $uz_months = [1=>"Yanvar", 2=>"Fevral", 3=>"Mart", 4=>"Aprel", 5=>"May", 6=>"Iyun", 7=>"Iyul", 8=>"Avgust", 9=>"Sentabr", 10=>"Oktabr", 11=>"Noyabr", 12=>"Dekabr"];
                                    foreach ($uz_months as $m_num => $m_name): ?>
                                        <option value="<?= $m_num ?>" <?= $cal_month == $m_num ? 'selected' : '' ?>><?= $m_name ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex-1 space-y-2 min-w-[120px]">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Yil</label>
                                <select name="cal_year" class="w-full p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                    <?php for($y = date('Y')-1; $y <= date('Y')+1; $y++): ?>
                                        <option value="<?= $y ?>" <?= $cal_year == $y ? 'selected' : '' ?>><?= $y ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>
                            <div class="flex-1 space-y-2 min-w-[180px]">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Filial</label>
                                <select name="f_branch" class="w-full p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                    <option value="">Barcha filiallar</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= $b['id'] ?>" <?= $f_branch == $b['id'] ? 'selected' : '' ?>><?= $b['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="bg-blue-600 text-white px-8 py-3.5 rounded-2xl font-black text-xs hover:bg-blue-700 transition-all shadow-lg shadow-blue-500/20">KO'RISH</button>
                        </form>
                    </div>

                    <!-- Calendar Grid -->
                    <div class="bg-white rounded-[2.5rem] shadow-xl border border-slate-100 overflow-hidden mb-12">
                        <div class="overflow-x-auto custom-scrollbar">
                            <table class="w-full border-collapse">
                                <thead>
                                    <tr class="bg-slate-50 border-b">
                                        <th class="p-6 text-left border-r sticky left-0 bg-slate-50 z-10 w-64 shadow-[2px_0_5px_rgba(0,0,0,0.05)]">
                                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Xodim Ismi</p>
                                        </th>
                                        <?php for ($d = 1; $d <= $days_in_month; $d++): 
                                            $date_str = "$cal_year-$cal_month-$d";
                                            $day_name = date('D', strtotime($date_str));
                                            $is_today = (date('Y-m-d') == date('Y-m-d', strtotime($date_str)));
                                            $is_weekend = ($day_name == 'Sun');
                                        ?>
                                            <th class="p-3 text-center border-r min-w-[45px] <?= $is_today ? 'bg-blue-50' : ($is_weekend ? 'bg-rose-50' : '') ?>">
                                                <p class="text-[9px] font-black <?= $is_weekend ? 'text-rose-400' : 'text-slate-400' ?> uppercase"><?= $day_name ?></p>
                                                <p class="text-xs font-black <?= $is_today ? 'text-blue-600' : 'text-slate-700' ?>"><?= $d ?></p>
                                            </th>
                                        <?php endfor; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cal_employees as $emp): ?>
                                        <tr class="border-b hover:bg-slate-50/50 transition-colors">
                                            <td class="p-4 border-r sticky left-0 bg-white z-10 shadow-[2px_0_5px_rgba(0,0,0,0.02)]">
                                                <p class="font-bold text-slate-800 text-sm truncate"><?= $emp['full_name'] ?></p>
                                                <p class="text-[9px] text-slate-400 font-black uppercase tracking-tighter italic"><?= $emp['role'] ?></p>
                                            </td>
                                            <?php for ($d = 1; $d <= $days_in_month; $d++): 
                                                $current_date = sprintf('%04d-%02d-%02d', $cal_year, $cal_month, $d);
                                                $status = 'normal';
                                                
                                                if (isset($att_dates[$emp['id']]) && in_array($current_date, $att_dates[$emp['id']])) {
                                                    $status = 'worked';
                                                } elseif (isset($approved_offs[$emp['id']]) && in_array($current_date, $approved_offs[$emp['id']])) {
                                                    $status = 'off-day';
                                                } elseif ($emp['off_day_type'] == 'sunday' && date('D', strtotime($current_date)) == 'Sun') {
                                                    $status = 'off-day'; 
                                                } elseif ($current_date < date('Y-m-d')) {
                                                    $status = 'absent';
                                                } else {
                                                    $status = 'scheduled';
                                                }
                                            ?>
                                                <td class="p-2 border-r text-center align-middle">
                                                    <?php if ($status == 'worked'): ?>
                                                        <div class="w-7 h-7 bg-emerald-100 text-emerald-600 rounded-lg flex items-center justify-center mx-auto" title="Ish kunida kelgan">
                                                            <i class="ri-checkbox-circle-fill text-lg"></i>
                                                        </div>
                                                    <?php elseif ($status == 'off-day'): ?>
                                                        <div class="w-7 h-7 bg-purple-100 text-purple-600 rounded-lg flex items-center justify-center mx-auto" title="Dam olish kuni (Tasdiqlangan)">
                                                            <i class="ri-moon-clear-fill text-lg"></i>
                                                        </div>
                                                    <?php elseif ($status == 'absent'): ?>
                                                        <div class="w-7 h-7 bg-rose-50 text-rose-300 rounded-lg flex items-center justify-center mx-auto" title="Kelmagan / Ish kuni">
                                                            <i class="ri-close-circle-line text-lg"></i>
                                                        </div>
                                                    <?php else: ?>
                                                        <div class="w-7 h-7 bg-slate-50 text-slate-300 rounded-lg flex items-center justify-center mx-auto" title="Ish kuni kutilmoqda">
                                                            <i class="ri-time-line text-lg"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endfor; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Legend -->
                        <div class="p-8 bg-slate-50 border-t flex gap-8 items-center justify-center">
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 bg-emerald-100 text-emerald-600 rounded flex items-center justify-center"><i class="ri-checkbox-circle-fill text-[10px]"></i></div>
                                <span class="text-[10px] font-black text-slate-500 uppercase">Ishda</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 bg-purple-100 text-purple-600 rounded flex items-center justify-center"><i class="ri-moon-clear-fill text-[10px]"></i></div>
                                <span class="text-[10px] font-black text-slate-500 uppercase">Dam olgan</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 bg-rose-50 text-rose-300 rounded flex items-center justify-center"><i class="ri-close-circle-line text-[10px]"></i></div>
                                <span class="text-[10px] font-black text-slate-500 uppercase">Kelmagan</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="w-4 h-4 bg-slate-50 text-slate-300 rounded flex items-center justify-center"><i class="ri-time-line text-[10px]"></i></div>
                                <span class="text-[10px] font-black text-slate-500 uppercase">Ish kuni</span>
                            </div>
                        </div>
                    </div>

                <?php elseif ($tab == 'attendance'): ?>
                    <div class="max-w-2xl mx-auto">
                        <h2 class="text-3xl font-black mb-10 text-slate-800">Ish vaqtini qayd etish</h2>
                        <?php if (isset($_GET['error']) || isset($error)): ?>
                            <div
                                class="bg-rose-100 text-rose-700 p-4 rounded-2xl mb-6 font-bold text-center border border-rose-200">
                                <?= htmlspecialchars($_GET['error'] ?? $error) ?>
                            </div>
                        <?php endif; ?>
                        <div
                            class="bg-white p-12 rounded-[3.5rem] shadow-2xl shadow-blue-900/5 border border-slate-100 text-center relative overflow-hidden">
                            <div
                                class="absolute top-0 left-0 w-full h-2 <?= $status == 'checked-in' ? 'bg-emerald-500' : 'bg-slate-200' ?>">
                            </div>
                            <div
                                class="w-32 h-32 <?= $status == 'checked-in' ? 'bg-emerald-100 text-emerald-600' : 'bg-slate-100 text-slate-400' ?> rounded-[2.5rem] flex items-center justify-center mx-auto mb-8 rotate-3 transition-transform hover:rotate-0">
                                <i
                                    class="<?= $status == 'checked-in' ? 'ri-user-smile-fill' : 'ri-user-received-2-line' ?> text-5xl"></i>
                            </div>
                            <h3 class="text-2xl font-black mb-2 text-slate-800">
                                <?= $status == 'checked-in' ? 'Siz hozir ishdasiz' : 'Siz hozir ishda emassiz' ?>
                            </h3>
                            <p class="text-slate-400 mb-10 font-medium">
                                <?= $last_rec ? 'So\'nggi harakat: ' . date('H:i (d.m.Y)', strtotime($last_rec['timestamp'])) : 'Bugun hali harakat qayd etilmadi' ?>
                            </p>

                            <form id="attendance_form" method="POST" class="space-y-4">
                                <input type="hidden" name="web_attendance" value="1">
                                <input type="hidden" name="lat" id="web_lat" value="0">
                                <input type="hidden" name="lon" id="web_lon" value="0">
                                <input type="hidden" name="image" id="web_image" value="">

                                <!-- Camera Capture Area -->
                                <div class="bg-slate-100 rounded-3xl overflow-hidden relative aspect-square mb-6">
                                    <video id="web_video" autoplay playsinline class="w-full h-full object-cover"></video>
                                    <canvas id="web_canvas" class="hidden"></canvas>
                                    <img id="web_preview" class="hidden w-full h-full object-cover">
                                    
                                    <div id="camera_overlay" class="absolute inset-0 flex flex-col items-center justify-center bg-slate-900/50 text-white p-4 text-center">
                                        <i class="ri-camera-line text-4xl mb-2"></i>
                                        <p class="text-xs font-bold uppercase">Suratga tushish shart</p>
                                        <button type="button" onclick="startCamera()" class="mt-4 bg-white text-slate-900 px-6 py-2 rounded-full font-bold text-sm">KAMERANI YOQISH</button>
                                    </div>

                                    <button id="capture_btn" type="button" onclick="takeSnapshot()" class="hidden absolute bottom-6 left-1/2 -translate-x-1/2 bg-white text-slate-900 p-4 rounded-full shadow-2xl active:scale-90 transition-transform">
                                        <i class="ri-camera-fill text-2xl"></i>
                                    </button>

                                    <button id="retake_btn" type="button" onclick="startCamera()" class="hidden absolute top-6 right-6 bg-rose-500 text-white p-2 rounded-full shadow-lg">
                                        <i class="ri-refresh-line text-xl"></i>
                                    </button>
                                </div>

                                <p id="camera_error" class="text-rose-500 text-xs font-bold text-center mb-4 hidden"></p>

                                <?php if ($status == 'checked-out'): ?>
                                    <input type="hidden" name="type" value="check-in">
                                    <button id="submit_attendance" type="submit" disabled
                                        class="w-full bg-emerald-500 text-white p-6 rounded-3xl font-black text-xl shadow-xl shadow-emerald-500/20 active:scale-[0.98] transition-all hover:bg-emerald-600 disabled:opacity-50 disabled:cursor-not-allowed">ISHNI
                                        BOSHLASH</button>
                                <?php else: ?>
                                    <input type="hidden" name="type" value="check-out">
                                    <button id="submit_attendance" type="submit" disabled
                                        class="w-full bg-rose-500 text-white p-6 rounded-3xl font-black text-xl shadow-xl shadow-rose-500/20 active:scale-[0.98] transition-all hover:bg-rose-600 disabled:opacity-50 disabled:cursor-not-allowed">ISHNI
                                        YAKUNLASH</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                    <script>
                        const v = document.getElementById('web_video');
                        const c = document.getElementById('web_canvas');
                        const i = document.getElementById('web_image');
                        const preview = document.getElementById('web_preview');
                        const overlay = document.getElementById('camera_overlay');
                        const captureBtn = document.getElementById('capture_btn');
                        const retakeBtn = document.getElementById('retake_btn');
                        const submitBtn = document.getElementById('submit_attendance');
                        const errorP = document.getElementById('camera_error');

                        navigator.geolocation.getCurrentPosition(p => {
                            document.getElementById('web_lat').value = p.coords.latitude;
                            document.getElementById('web_lon').value = p.coords.longitude;
                        });

                        async function startCamera() {
                            try {
                                const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "user" } });
                                v.srcObject = stream;
                                v.classList.remove('hidden');
                                preview.classList.add('hidden');
                                overlay.classList.add('hidden');
                                captureBtn.classList.remove('hidden');
                                retakeBtn.classList.add('hidden');
                                submitBtn.disabled = true;
                                errorP.classList.add('hidden');
                            } catch (err) {
                                console.error(err);
                                errorP.innerText = "Kameraga ruxsat berilmadi yoki kamera topilmadi.";
                                errorP.classList.remove('hidden');
                            }
                        }

                        function takeSnapshot() {
                            c.width = v.videoWidth;
                            c.height = v.videoHeight;
                            c.getContext('2d').drawImage(v, 0, 0);
                            const dataUrl = c.toDataURL('image/jpeg', 0.6);
                            i.value = dataUrl;
                            
                            preview.src = dataUrl;
                            preview.classList.remove('hidden');
                            v.classList.add('hidden');
                            
                            captureBtn.classList.add('hidden');
                            retakeBtn.classList.remove('hidden');
                            submitBtn.disabled = false;

                            // Stop camera stream
                            if (v.srcObject) {
                                v.srcObject.getTracks().forEach(track => track.stop());
                            }
                        }
                    </script>

                <?php elseif ($tab == 'tasks' && $isAdmin): ?>
                    <div class="flex justify-between items-center mb-10">
                        <div>
                            <h2 class="text-3xl font-black text-slate-800">Vazifalar boshqaruvi</h2>
                            <p class="text-slate-400 font-bold uppercase tracking-widest text-[10px] mt-1">Xodimlarga shaxsiy vazifalar tayinlash</p>
                        </div>
                        <button onclick="openTaskModal()" class="bg-blue-600 text-white px-8 py-4 rounded-[1.5rem] font-black shadow-xl shadow-blue-500/20 hover:scale-105 transition-all flex items-center gap-3">
                            <i class="ri-add-line text-xl"></i> Vazifa qo'shish
                        </button>
                    </div>

                    <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 border-b">
                                <tr>
                                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Vazifa</th>
                                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Xodim</th>
                                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest">Status</th>
                                    <th class="p-6 text-[10px] font-black text-slate-400 uppercase tracking-widest text-right">Amallar</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php if (empty($tasks)): ?>
                                    <tr>
                                        <td colspan="5" class="p-10 text-center text-slate-400 italic">Hali vazifalar qo'shilmagan</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($tasks as $t): ?>
                                        <tr class="hover:bg-slate-50/50 transition-colors">
                                            <td class="p-6">
                                                <p class="font-bold text-slate-800"><?= htmlspecialchars($t['title']) ?></p>
                                                <p class="text-xs text-slate-400 line-clamp-1"><?= htmlspecialchars($t['description']) ?></p>
                                            </td>
                                            <td class="p-6">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-8 h-8 bg-blue-50 text-blue-600 rounded-lg flex items-center justify-center text-xs font-black">
                                                        <?= strtoupper(substr($t['employee_name'], 0, 1)) ?>
                                                    </div>
                                                    <div>
                                                        <p class="text-sm font-bold text-slate-700"><?= $t['employee_name'] ?></p>
                                                        <p class="text-[9px] text-slate-400 font-bold uppercase tracking-tighter"><?= $t['branch_name'] ?></p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td class="p-6">
                                                <?php 
                                                $statusColors = [
                                                    'pending' => 'bg-amber-100 text-amber-600',
                                                    'in_progress' => 'bg-blue-100 text-blue-600',
                                                    'completed' => 'bg-emerald-100 text-emerald-600',
                                                    'cancelled' => 'bg-slate-100 text-slate-400'
                                                ];
                                                $statusLabels = [
                                                    'pending' => 'Kutilmoqda',
                                                    'in_progress' => 'Jarayonda',
                                                    'completed' => 'Bajarildi',
                                                    'cancelled' => 'Bekor qilindi'
                                                ];
                                                ?>
                                                <span class="px-3 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-widest <?= $statusColors[$t['status']] ?>">
                                                    <?= $statusLabels[$t['status']] ?>
                                                </span>
                                            </td>
                                            <td class="p-6 text-right">
                                                <div class="flex justify-end gap-2">
                                                    <button onclick='openTaskModal(<?= json_encode($t) ?>)' class="w-10 h-10 bg-blue-50 text-blue-600 rounded-xl hover:bg-blue-100 transition-all">
                                                        <i class="ri-edit-line text-lg"></i>
                                                    </button>
                                                    <a href="?del_task=<?= $t['id'] ?>" onclick="return confirm('Rostdan ham o\'chirmoqchimisiz?')" class="w-10 h-10 bg-rose-50 text-rose-600 rounded-xl hover:bg-rose-100 transition-all flex items-center justify-center">
                                                        <i class="ri-delete-bin-line text-lg"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab == 'reporting'): ?>
                    <?php
                        $totalBalanceSum = $pdo->query("SELECT SUM(balance) FROM employees")->fetchColumn();
                        $totalMonthlySalarySum = $pdo->query("SELECT SUM(monthly_salary) FROM employees")->fetchColumn();
                    ?>
                    <div class="flex justify-between items-center mb-10 overflow-x-auto gap-10">
                        <h2 class="text-3xl font-black text-slate-800 shrink-0">
                            <?= $isAdmin ? 'Umumiy hisobot' : 'Mening hisobotlarim' ?>
                        </h2>
                        
                        <?php if ($isAdmin): ?>
                        <div class="flex gap-6 items-center flex-nowrap">
                            <div class="bg-blue-50 px-6 py-4 rounded-3xl border border-blue-100 flex items-center gap-4 shadow-sm min-w-[280px]">
                                <div class="w-12 h-12 bg-white text-blue-600 rounded-2xl flex items-center justify-center shadow-sm">
                                    <i class="ri-wallet-3-line text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-[10px] font-black text-blue-400 uppercase tracking-widest leading-none mb-2">Bu oyda to'lanadigan summa</p>
                                    <p class="text-xl font-black text-blue-600"><?= number_format($totalBalanceSum ?? 0, 0, '.', ' ') ?> <span class="text-xs opacity-60">UZS</span></p>
                                </div>
                            </div>
                            
                            <div class="bg-emerald-50 px-6 py-4 rounded-3xl border border-emerald-100 flex items-center gap-4 shadow-sm min-w-[280px]">
                                <div class="w-12 h-12 bg-white text-emerald-500 rounded-2xl flex items-center justify-center shadow-sm">
                                    <i class="ri-bank-card-line text-2xl"></i>
                                </div>
                                <div>
                                    <p class="text-[10px] font-black text-emerald-400 uppercase tracking-widest leading-none mb-2">Belgilangan oylik maoshlar</p>
                                    <p class="text-xl font-black text-emerald-600"><?= number_format($totalMonthlySalarySum ?? 0, 0, '.', ' ') ?> <span class="text-xs opacity-60">UZS</span></p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Web Filters (Reporting) -->
                    <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100 mb-8 flex gap-6 items-end">
                        <form method="GET" class="flex flex-1 gap-6 items-end flex-wrap">
                            <input type="hidden" name="tab" value="<?= $tab ?>">
                            <div class="flex-1 min-w-[200px] space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Xodim ismi bo'yicha</label>
                                <div class="relative">
                                    <i class="ri-search-line absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                    <input type="text" name="f_search" value="<?= $f_search ?>" placeholder="Ismni kiriting..." class="w-full pl-11 p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                </div>
                            </div>
                            <div class="flex-1 min-w-[150px] space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Filial bo'yicha</label>
                                <select name="f_branch" class="w-full p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                    <option value="">Barchasi</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= $b['id'] ?>" <?= $f_branch == $b['id'] ? 'selected' : '' ?>><?= $b['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Sana bo'yicha</label>
                                <input type="date" name="f_date" value="<?= $f_date ?>" class="w-full p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                            </div>
                            <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-2xl font-black text-xs hover:bg-blue-700 transition-all">FILTRLASH</button>
                            <?php if ($f_branch || $f_date || $f_search): ?>
                                <a href="?tab=<?= $tab ?>&clear_filters=1" class="bg-slate-100 text-slate-500 px-6 py-3 rounded-2xl font-black text-xs hover:bg-slate-200 transition-all">TOZALASH</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <?php
                    // Calculation Logic per Employee
                    $displayEmps = $isAdmin ? $employees : [$user];
                    if ($f_branch) {
                        $displayEmps = array_filter($displayEmps, fn($e) => $e['branch_id'] == $f_branch);
                    }
                    if ($f_search) {
                        $displayEmps = array_filter($displayEmps, fn($e) => stripos($e['full_name'], $f_search) !== false);
                    }
                    if ($isManager) {
                        // Managers see self, but we also want a section for branch employees if requested
                    }

                    foreach ($displayEmps as $e):
                        $eRecs = array_filter($records, fn($r) => $r['employee_id'] == $e['id']);
                        // Sort them oldest to newest manually to be 100% sure
                        usort($eRecs, fn($ra, $rb) => strcmp($ra['timestamp'], $rb['timestamp']));
                        $eAbs = array_filter($absences, fn($a) => $a['employee_id'] == $e['id']);

                        $total_mins = 0;
                        $gross_salary = 0;
                        $paid_away_mins = 0;
                        $unpaid_away_mins = 0;
                        $days = array(); // Filtered days
                        $days_total = array(); // Total unarchived days
                        $sorted = $eRecs; // Records are already oldest-to-newest due to line 400
                        $lastIn = null;
                        $sessions = array();

                        foreach ($sorted as $r) {
                            $r_ts = strtotime($r['timestamp']);
                            $r_date = date('Y-m-d', $r_ts);

                            if ($r['type'] == 'check-in') {
                                $lastIn = $r;
                            } else if ($r['type'] == 'check-out' && $lastIn) {
                                $in_ts = strtotime($lastIn['timestamp']);
                                $dur = ($r_ts - $in_ts) / 60;
                                
                                // Protection against "1470 hour" bug:
                                // Don't pair if checkout is before checkin or more than 48 hours later
                                if ($dur < 0 || $dur > 2880) {
                                    continue;
                                }

                                $in_date = date('Y-m-d', $in_ts);
                                $days_total[$in_date] = 1; // Count all unarchived days
                                if ($f_date && $in_date !== $f_date) continue;
                                $days[$in_date] = 1;

                                // Lateness and Departure calculation
                                $work_start = $e['work_start_time'] ?? '09:00:00';
                                $work_end = $e['work_end_time'] ?? '18:00:00';

                                $check_in_full = strtotime($lastIn['timestamp']);
                                $check_out_full = strtotime($r['timestamp']);
                                
                                // Normalize start/end times based on check-in date
                                $base_date = date('Y-m-d', $check_in_full);
                                $work_start_full = strtotime($base_date . ' ' . $work_start);
                                $work_end_full = strtotime($base_date . ' ' . $work_end);
                                
                                // Adjust work_end if it's a night shift
                                if ($work_end_full <= $work_start_full) {
                                    $work_end_full += 86400;
                                }

                                $diff_start = ($check_in_full - $work_start_full) / 60;
                                $diff_end = ($check_out_full - $work_end_full) / 60;

                                $in_status = "";
                                if ($diff_start > 0) {
                                    $in_status = formatMins($diff_start) . " kechikdi";
                                } elseif ($diff_start < 0) {
                                    $in_status = formatMins(abs($diff_start)) . " vaqtli keldi";
                                } else {
                                    $in_status = "O'z vaqtida keldi";
                                }

                                $out_status = "";
                                if ($diff_end > 0) {
                                    $out_status = formatMins($diff_end) . " kech ketdi";
                                } elseif ($diff_end < 0) {
                                    $out_status = formatMins(abs($diff_end)) . " vaqtli ketdi";
                                } else {
                                    $out_status = "O'z vaqtida ketdi";
                                }

                                // Find absences during this session (Precisely calculate overlap)
                                $s_paid_away = 0;
                                $s_unpaid_away = 0;
                                $now = time();
                                foreach ($eAbs as $sa) {
                                    $overlapStart = max($in_ts, strtotime($sa['start_time']));
                                    $overlapEnd = min($r_ts, strtotime($sa['end_time'] ?: $now));
                                    
                                    if ($overlapStart < $overlapEnd) {
                                        $overlapDur = max(0, ($overlapEnd - $overlapStart) / 60);
                                        if ($sa['status'] == 'approved') {
                                            $s_paid_away += $overlapDur;
                                        } else {
                                            $s_unpaid_away += $overlapDur;
                                        }
                                    }
                                }
                                 $sessions[] = [
                                    'date' => date('d.m.Y', $in_ts),
                                    'in' => date('H:i', $in_ts),
                                    'out' => date('H:i', $r_ts),
                                    'raw_date' => $in_date,
                                    'raw_in' => $lastIn['timestamp'],
                                    'raw_out' => $r['timestamp'],
                                    'in_id' => $lastIn['id'],
                                    'out_id' => $r['id'],
                                    'employee_id' => $e['id'],
                                    'in_status' => $in_status,
                                    'out_status' => $out_status,
                                    'dur' => round($dur),
                                    'paid_away' => $s_paid_away,
                                    'unpaid_away' => $s_unpaid_away,
                                    'status' => $s_paid_away > 0 ? 'Approved' : ($s_unpaid_away > 0 ? 'Deducted' : 'Normal'),
                                    'image_in' => $lastIn['image_url'] ?? null,
                                    'image_out' => $r['image_url'] ?? null
                                ];
                                
                                $sessionRate = $lastIn['hourly_rate'] ?: ($r['hourly_rate'] ?: $e['hourly_rate']);
                                $gross_salary += (($dur - $s_paid_away) / 60) * $sessionRate;

                                $total_mins += ($dur - $s_paid_away);
                                $paid_away_mins += $s_paid_away;
                                // $unpaid_away_mins is no longer subtracted from gross time, 
                                // because rejected absences now become explicit fines
                                $lastIn = null;
                            }
                        }

                        // Handle active session (check-in without check-out)
                        if ($lastIn) {
                            $check_in_full_active = strtotime($lastIn['timestamp']);
                            $deadline_active = strtotime(date('Y-m-d', $check_in_full_active) . ' +1 day 12:00:00');
                            $is_abandoned = (time() > $deadline_active);
                            
                            $in_date_active = date('Y-m-d', $check_in_full_active);
                            if (!$f_date || $in_date_active === $f_date) {
                                $days[$in_date_active] = 1;
                                $now_time = time();
                                if ($is_abandoned) {
                                    $out_display = '<span class="bg-rose-100 text-rose-600 px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest border border-rose-200">Kutilmoqda...</span>';
                                    $dur_active = 0; // Not calculated for abandoned unless fixed
                                } else {
                                    $out_display = '<span class="bg-emerald-100 text-emerald-600 px-3 py-1 rounded-lg text-[10px] font-black uppercase tracking-widest animate-pulse">Ishda</span>';
                                    $dur_active = ($now_time - $check_in_full_active) / 60;
                                }

                                // Lateness logic
                                $w_start_active = $e['work_start_time'] ?? '09:00:00';
                                $b_date_active = date('Y-m-d', $check_in_full_active);
                                $w_start_full_active = strtotime($b_date_active . ' ' . $w_start_active);
                                $d_start_active = ($check_in_full_active - $w_start_full_active) / 60;
                                                                // Lateness logic
                                $w_start_active = $e['work_start_time'] ?? '09:00:00';
                                $b_date_active = date('Y-m-d', $check_in_full_active);
                                $w_start_full_active = strtotime($b_date_active . ' ' . $w_start_active);
                                $d_start_active = ($check_in_full_active - $w_start_full_active) / 60;
                                
                                $in_status_active = "";
                                if ($d_start_active > 0) { $in_status_active = formatMins($d_start_active) . " kechikdi"; }
                                elseif ($d_start_active < 0) { $in_status_active = formatMins(abs($d_start_active)) . " vaqtli keldi"; }
                                else { $in_status_active = "O'z vaqtida keldi"; }

                                // Active session absences
                                $s_paid_away_active = 0;
                                $s_unpaid_away_active = 0;
                                foreach ($eAbs as $sa) {
                                    $aStartTs = strtotime($sa['start_time']);
                                    $aEndTs = $sa['end_time'] ? strtotime($sa['end_time']) : time();
                                    
                                    $overlapStart = max($check_in_full_active, $aStartTs);
                                    $overlapEnd = min($now_time, $aEndTs);
                                    
                                    if ($overlapStart < $overlapEnd) {
                                        $overlapDur = ($overlapEnd - $overlapStart) / 60;
                                        if ($sa['status'] == 'approved') {
                                            $s_paid_away_active += $overlapDur;
                                        } else {
                                            $s_unpaid_away_active += $overlapDur;
                                        }
                                    }
                                }

                                $sessions[] = [
                                    'date' => date('d.m.Y', $check_in_full_active),
                                    'in' => date('H:i', $check_in_full_active),
                                    'out' => $out_display,
                                    'raw_date' => $b_date_active,
                                    'raw_in' => $lastIn['timestamp'],
                                    'raw_out' => null,
                                    'in_id' => $lastIn['id'],
                                    'out_id' => null,
                                    'is_abandoned' => $is_abandoned,
                                    'employee_id' => $e['id'],
                                    'in_status' => $in_status_active,
                                    'out_status' => $is_abandoned ? 'Tugallanmagan' : 'Davom etmoqda...',
                                    'dur' => round($dur_active - $s_paid_away_active),
                                    'paid_away' => $s_paid_away_active,
                                    'unpaid_away' => $s_unpaid_away_active,
                                    'image_in' => $lastIn['image_url'] ?? null,
                                    'image_out' => null
                                ];                                 
                                $total_mins += ($dur_active - $s_paid_away_active);
                                $paid_away_mins += $s_paid_away_active;
                                $sessionRateActive = $lastIn['hourly_rate'] ?: $e['hourly_rate'];
                                $gross_salary += (($dur_active - $s_paid_away_active) / 60) * $sessionRateActive;
                            }
                        }
                        $final_paid_hours = $total_mins / 60;
                        // $gross_salary calculation is now done per session above];

                        // Fines logic (Only unarchived)
                        $total_fines = 0;
                        try {
                            $stmt_f = $pdo->prepare("SELECT SUM(amount) as total FROM fines WHERE employee_id = ? AND status = 'approved' AND is_archived = 0");
                            $stmt_f->execute([$e['id']]);
                            $total_fines = $stmt_f->fetch()['total'] ?? 0;
                        } catch(Exception $ex) { $total_fines = 0; }

                        // Advances logic (Only unarchived)
                        $stmt_p = $pdo->prepare("SELECT SUM(amount) as total FROM payments WHERE employee_id = ? AND is_archived = 0");
                        $stmt_p->execute([$e['id']]);
                        $advances = $stmt_p->fetch()['total'] ?? 0;

                        // Off days logic
                        $off_days_count = 0;
                        try {
                            $stmt_off = $pdo->prepare("SELECT COUNT(*) FROM off_day_requests WHERE employee_id = ? AND status = 'approved' AND is_archived = 0");
                            $stmt_off->execute([$e['id']]);
                            $off_days_count = $stmt_off->fetchColumn() ?: 0;
                        } catch(Exception $ex) { $off_days_count = 0; }

                        $net_salary = $gross_salary - $advances - $total_fines;

                        // Calculate month plan
                        $days_in_month = (int)date('t');
                        $plan_off = (int)($e['off_days_per_month'] ?? 4);
                        $plan_work = max(0, 30 - $plan_off);
                        ?>
                        <div class="mb-12 bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden">
                            <div class="p-8 bg-slate-50 border-b flex justify-between items-center">
                                <div>
                                    <h3 class="text-xl font-black text-slate-800"><?= $e['full_name'] ?></h3>
                                    <p class="text-slate-400 text-xs font-bold uppercase tracking-wider"><?= $e['position'] ?></p>
                                </div>
                                <div class="flex gap-8 items-center">
                                    <div class="text-right">
                                        <p class="text-[10px] font-black text-blue-400 uppercase mb-1">Belgilangan maosh</p>
                                        <p class="text-lg font-black text-blue-600"><?= number_format($e['monthly_salary']) ?> UZS</p>
                                    </div>
                                    <div class="text-right border-l pl-8">
                                        <p class="text-[10px] font-black text-slate-400 uppercase mb-1">Umumiy balans</p>
                                        <p class="text-lg font-black text-slate-800"><?= number_format($gross_salary) ?> UZS</p>
                                    </div>
                                    <div class="text-right border-l pl-8">
                                        <p class="text-[10px] font-black text-rose-300 uppercase mb-1">Jarimalar</p>
                                        <p class="text-lg font-black text-rose-400">- <?= number_format($total_fines) ?> UZS</p>
                                    </div>
                                    <div class="text-right border-l pl-8">
                                        <p class="text-[10px] font-black text-rose-400 uppercase mb-1">Berilgan avans</p>
                                        <p class="text-lg font-black text-rose-500">- <?= number_format($advances) ?> UZS</p>
                                    </div>
                                    <div class="text-right border-l pl-8">
                                        <p class="text-[10px] font-black text-slate-400 uppercase mb-1">Qolgan summa</p>
                                        <p class="text-2xl font-black text-indigo-600"><?= number_format($net_salary) ?> UZS</p>
                                    </div>
                                </div>
                            </div>
                            <div class="p-8">
                                <div class="grid grid-cols-4 gap-6 mb-8">
                                    <div class="space-y-1">
                                        <p class="text-slate-400 text-[10px] font-black uppercase">Ishlagan vaqti</p>
                                        <p class="text-lg font-black"><?= round($total_mins / 60, 1) ?> soat</p>
                                    </div>
                                    <div class="space-y-1">
                                        <p class="text-slate-400 text-[10px] font-black uppercase">Tasdiqlangan uzoqlashish</p>
                                        <p class="text-lg font-black text-emerald-600"><?= $paid_away_mins ?> daq</p>
                                    </div>
                                    <div class="space-y-1">
                                        <p class="text-slate-400 text-[10px] font-black uppercase tracking-widest">Ish / Dam</p>
                                        <p class="text-lg font-black"><?= count($days_total) ?> / <?= $off_days_count ?> kun</p>
                                        <p class="text-[9px] text-slate-300 font-bold uppercase tracking-tighter">Reja: <?= $plan_work ?> / <?= $plan_off ?></p>
                                    </div>
                                </div>
                                <table class="w-full text-left">
                                    <thead class="text-[10px] font-black text-slate-400 uppercase border-b pb-4">
                                        <tr>
                                            <th class="pb-4">Sana</th>
                                            <th class="pb-4">Kirish</th>
                                            <th class="pb-4">Chiqish</th>
                                            <th class="pb-4">Tanaffus (Tasdiq)</th>
                                            <th class="pb-4">Jarima</th>
                                             <th class="pb-4">Sof ish vaqti</th>
                                             <th class="pb-4 text-center">Foto (K-Ch)</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y">
                                        <?php foreach (array_reverse($sessions) as $s): ?>
                                            <tr>
                                                <td class="py-4 font-bold text-slate-700"><?= $s['date'] ?></td>
                                                <td class="py-4 font-medium text-slate-500">
                                                    <div class="flex items-center gap-2">
                                                        <span><?= $s['in'] ?></span>
                                                        <?php if ($isAdmin): ?>
                                                            <button onclick="openEditModal(<?= $s['in_id'] ?: 0 ?>, '<?= $s['in'] ?>', '<?= $s['raw_date'] ?>', 'Kirish', <?= $s['employee_id'] ?: 0 ?>)" class="text-blue-500 hover:text-blue-700">
                                                                <i class="ri-edit-line"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-[10px] <?= strpos($s['in_status'], 'kechikdi') !== false ? 'text-rose-500' : 'text-emerald-500' ?> font-black uppercase"><?= $s['in_status'] ?></div>
                                                </td>
                                                <td class="py-4 font-medium text-slate-500">
                                                    <div class="flex items-center gap-2">
                                                        <span><?= $s['out'] ?></span>
                                                        <?php if ($isAdmin): ?>
                                                            <button onclick="openEditModal(<?= $s['out_id'] ?: 0 ?>, '<?= strip_tags($s['out']) ?>', '<?= $s['raw_date'] ?>', 'Chiqish', <?= $s['employee_id'] ?: 0 ?>, <?= $s['in_id'] ?: 0 ?>)" class="text-blue-500 hover:text-blue-700">
                                                                <i class="ri-edit-line"></i>
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-[10px] <?= strpos($s['out_status'], 'vaqtli ketdi') !== false ? 'text-rose-500' : 'text-emerald-500' ?> font-black uppercase"><?= $s['out_status'] ?></div>
                                                </td>
                                                <td class="py-4">
                                                    <?php if ($s['paid_away'] > 0): ?>
                                                        <span class="text-emerald-600 font-bold text-xs"><i
                                                                class="ri-check-double-line"></i> <?= $s['paid_away'] ?>m</span>
                                                    <?php elseif ($s['unpaid_away'] > 0): ?>
                                                        <span class="text-rose-500 font-bold text-xs"><i class="ri-error-warning-line"></i>
                                                            -<?= $s['unpaid_away'] ?>m</span>
                                                    <?php else: ?>-<?php endif; ?>
                                                </td>                                                <td class="py-4">
                                                    <?php 
                                                    // Find fine for this date (if any)
                                                    $day_fine = null;
                                                    try {
                                                        $stmt_df = $pdo->prepare("SELECT amount, status FROM fines WHERE employee_id = ? AND DATE(date) = ? LIMIT 1");
                                                        $stmt_df->execute([$e['id'], $s['raw_date']]);
                                                        $day_fine = $stmt_df->fetch();
                                                    } catch(Exception $ex) {}
                                                    
                                                    if ($day_fine): 
                                                    ?>
                                                        <span class="<?= $day_fine['status'] == 'approved' ? 'text-rose-600' : 'text-slate-300' ?> font-black text-xs">
                                                            <?= number_format($day_fine['amount']) ?> so'm
                                                            <i class="ri-error-warning-fill ml-1"></i>
                                                        </span>
                                                    <?php else: ?>-<?php endif; ?>
                                                </td>
                                                <td class="py-4 font-black text-blue-600"><?= formatDurationUz($s['dur']) ?>
                                                 </td>
                                                 <td class="py-4 text-center">
                                                      <div class="flex items-center justify-center gap-2">
                                                          <?php if ($s['image_in']): ?>
                                                              <div class="inline-block relative group" onclick="document.getElementById('imgModal').classList.remove('hidden'); document.getElementById('imgModalSrc').src='<?= $s['image_in'] ?>';">
                                                                  <img src="<?= $s['image_in'] ?>" class="w-10 h-10 rounded-xl object-cover ring-2 ring-slate-100 hover:ring-blue-400 transition-all cursor-zoom-in">
                                                                  <div class="absolute bottom-0 right-0 bg-emerald-500 w-3 h-3 rounded-full border-2 border-white" title="Kirish"></div>
                                                              </div>
                                                          <?php endif; ?>
                                                          
                                                          <?php if ($s['image_out']): ?>
                                                              <div class="inline-block relative group" onclick="document.getElementById('imgModal').classList.remove('hidden'); document.getElementById('imgModalSrc').src='<?= $s['image_out'] ?>';">
                                                                  <img src="<?= $s['image_out'] ?>" class="w-10 h-10 rounded-xl object-cover ring-2 ring-slate-100 hover:ring-rose-400 transition-all cursor-zoom-in">
                                                                  <div class="absolute bottom-0 right-0 bg-rose-500 w-3 h-3 rounded-full border-2 border-white" title="Chiqish"></div>
                                                              </div>
                                                          <?php elseif ($s['image_in']): ?>
                                                              <div class="w-10 h-10 rounded-xl bg-slate-50 border-2 border-dashed border-slate-100 flex items-center justify-center" title="Chiqish rasmi yo'q">
                                                                  <i class="ri-camera-off-line text-slate-200 text-xs"></i>
                                                              </div>
                                                          <?php else: ?>
                                                              <span class="text-slate-200">—</span>
                                                          <?php endif; ?>
                                                      </div>
                                                  </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php elseif ($tab == 'monthly_reports' && $user['role'] == 'superadmin'): 
                    $f_m_year = $_GET['f_m_year'] ?? '';
                    $sql_m = "SELECT r.*, e.full_name, e.position FROM monthly_reports r JOIN employees e ON r.employee_id = e.id";
                    if ($f_m_year) $sql_m .= " WHERE r.month_year = '" . $f_m_year . "'";
                    $sql_m .= " ORDER BY r.month_year DESC, e.full_name ASC";
                    $m_reports = $pdo->query($sql_m)->fetchAll();
                ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Oylar arxivi</h2>
                         <div class="flex gap-4 items-center">
                            <form method="GET" class="flex gap-4">
                                <input type="hidden" name="tab" value="monthly_reports">
                                <select name="f_m_year" class="p-3 bg-white border border-slate-200 rounded-2xl text-sm font-bold outline-none ring-2 ring-transparent focus:ring-blue-500/20">
                                    <option value="">Barcha oylar</option>
                                    <?php 
                                    $months = $pdo->query("SELECT DISTINCT month_year FROM monthly_reports ORDER BY month_year DESC")->fetchAll(PDO::FETCH_COLUMN);
                                    foreach($months as $m): ?>
                                        <option value="<?= $m ?>" <?= $f_m_year == $m ? 'selected' : '' ?>><?= $m ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="bg-slate-800 text-white px-6 py-3 rounded-2xl font-black text-xs hover:bg-slate-900 transition-all">FILTER</button>
                            </form>
                         </div>
                    </div>

                    <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 border-b text-[10px] font-black text-slate-400 uppercase tracking-widest">
                                <tr>
                                    <th class="p-8">Oy</th>
                                    <th class="p-8">Xodim</th>
                                    <th class="p-8">Ish vaqti</th>
                                    <th class="p-8">Yalpi ish haqi</th>
                                    <th class="p-8">Jarima</th>
                                    <th class="p-8">Avans/To'lov</th>
                                    <th class="p-8">Dam olish</th>
                                    <th class="p-8">Sof oylik</th>
                                    <th class="p-8">Sana</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach($m_reports as $mr): ?>
                                    <tr class="hover:bg-slate-50/50 transition-all">
                                        <td class="p-8 font-black text-blue-600"><?= $mr['month_year'] ?></td>
                                        <td class="p-8">
                                            <p class="font-bold text-slate-800"><?= $mr['full_name'] ?></p>
                                            <p class="text-[10px] text-slate-400 font-bold uppercase"><?= $mr['position'] ?></p>
                                        </td>
                                        <td class="p-8 font-bold text-slate-600"><?= round($mr['total_hours'], 1) ?> soat</td>
                                        <td class="p-8 font-bold text-slate-700"><?= number_format($mr['gross_salary']) ?> UZS</td>
                                        <td class="p-8 font-bold text-rose-500">- <?= number_format($mr['total_fines']) ?> UZS</td>
                                        <td class="p-8 font-bold text-orange-500">- <?= number_format($mr['total_advances']) ?> UZS</td>
                                        <td class="p-8 text-slate-500 font-black"><?= $mr['off_days'] ?? 0 ?> kun</td>
                                        <td class="p-8">
                                            <span class="bg-emerald-50 text-emerald-600 px-4 py-2 rounded-2xl font-black text-sm">
                                                <?= number_format($mr['net_salary']) ?> UZS
                                            </span>
                                        </td>
                                        <td class="p-8 text-slate-400 text-xs font-medium italic"><?= date('d.m.Y H:i', strtotime($mr['created_at'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if(empty($m_reports)): ?>
                                    <tr><td colspan="8" class="p-20 text-center text-slate-300 italic font-medium">Hozircha arxivlangan ma'lumotlar mavjud emas</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab == 'logs' && $user['role'] == 'superadmin'): 
                    $log_type = $_GET['log_type'] ?? 'attendance';
                ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">O'zgartirishlar tarixi</h2>
                    </div>

                    <!-- Log Segments -->
                    <div class="flex flex-wrap gap-4 mb-10 bg-slate-100/30 p-2 rounded-[2.5rem] w-fit border border-slate-50">
                        <a href="?tab=logs&log_type=attendance" 
                           class="px-8 py-3.5 rounded-[2rem] font-black text-[11px] uppercase tracking-widest transition-all <?= $log_type == 'attendance' ? 'bg-white text-slate-800 shadow-xl shadow-slate-200 ring-1 ring-slate-100' : 'text-slate-400 hover:text-slate-600' ?>">
                           1. Davomat o'zgarishi
                        </a>
                        <a href="?tab=logs&log_type=fines" 
                           class="px-8 py-3.5 rounded-[2rem] font-black text-[11px] uppercase tracking-widest transition-all <?= $log_type == 'fines' ? 'bg-white text-slate-800 shadow-xl shadow-slate-200 ring-1 ring-slate-100' : 'text-slate-400 hover:text-slate-600' ?>">
                           2. Jarimalar nazorati
                        </a>
                        <a href="?tab=logs&log_type=absences" 
                           class="px-8 py-3.5 rounded-[2rem] font-black text-[11px] uppercase tracking-widest transition-all <?= $log_type == 'absences' ? 'bg-white text-slate-800 shadow-xl shadow-slate-200 ring-1 ring-slate-100' : 'text-slate-400 hover:text-slate-600' ?>">
                           3. Uzoqlashishlar nazorati
                        </a>
                        <a href="?tab=logs&log_type=logins" 
                           class="px-8 py-3.5 rounded-[2rem] font-black text-[11px] uppercase tracking-widest transition-all <?= $log_type == 'logins' ? 'bg-white text-slate-800 shadow-xl shadow-slate-200 ring-1 ring-slate-100' : 'text-slate-400 hover:text-slate-600' ?>">
                           4. Kirishlar tarixi
                        </a>
                    </div>

                    <div class="bg-white rounded-[3rem] shadow-sm border border-slate-100 overflow-hidden">
                        <div class="overflow-x-auto">
                            <table class="w-full text-left">
                                <?php if ($log_type == 'attendance'): ?>
                                    <thead class="bg-slate-50/50 text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                        <tr>
                                            <th class="p-8">Mas'ul (Admin)</th>
                                            <th class="p-8">Xodim</th>
                                            <th class="p-8">Harakat sanasi</th>
                                            <th class="p-8">O'zgarish</th>
                                            <th class="p-8">Sabab</th>
                                            <th class="p-8 text-right">Tahrir vaqti</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-50">
                                        <?php 
                                        $logs = $pdo->query("SELECT ae.*, e_admin.full_name as admin_name, e_emp.full_name as employee_name FROM attendance_edits ae JOIN employees e_admin ON ae.changed_by = e_admin.id JOIN attendance a ON ae.attendance_id = a.id JOIN employees e_emp ON a.employee_id = e_emp.id ORDER BY ae.created_at DESC")->fetchAll();
                                        foreach ($logs as $l): ?>
                                            <tr class="hover:bg-slate-50/30 transition-colors">
                                                <td class="p-8">
                                                    <p class="font-black text-slate-700"><?= $l['admin_name'] ?></p>
                                                    <p class="text-[9px] text-slate-400 font-black uppercase tracking-tighter">Tahrirlovchi Admin</p>
                                                </td>
                                                <td class="p-8">
                                                    <p class="font-black text-blue-600"><?= $l['employee_name'] ?></p>
                                                    <p class="text-[9px] text-slate-400 font-black uppercase tracking-tighter">Xodim</p>
                                                </td>
                                                <td class="p-8">
                                                    <p class="text-slate-500 font-bold"><?= date('d.m.Y', strtotime($l['old_timestamp'] && $l['old_timestamp'] != '0000-00-00 00:00:00' ? $l['old_timestamp'] : $l['new_timestamp'])) ?></p>
                                                    <p class="text-[9px] text-slate-300 font-black uppercase tracking-tighter mt-1">Davomat kuni</p>
                                                </td>
                                                <td class="p-8">
                                                    <div class="flex items-center gap-4">
                                                        <span class="text-rose-400 font-black text-xs line-through opacity-50"><?= date('H:i', strtotime($l['old_timestamp'])) ?></span>
                                                        <i class="ri-arrow-right-line text-slate-300"></i>
                                                        <span class="text-emerald-600 font-black text-sm"><?= date('H:i', strtotime($l['new_timestamp'])) ?></span>
                                                    </div>
                                                </td>
                                                <td class="p-8 text-slate-500 text-sm italic font-medium max-w-xs truncate">"<?= $l['reason'] ?: '—' ?>"</td>
                                                <td class="p-8 text-right">
                                                    <p class="text-slate-800 font-black text-[11px]"><?= date('d.m (H:i)', strtotime($l['created_at'])) ?></p>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                <?php elseif ($log_type == 'fines' || $log_type == 'absences'): ?>
                                    <thead class="bg-slate-50/50 text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                        <tr>
                                            <th class="p-8">Mas'ul (Admin)</th>
                                            <th class="p-8">Xodim</th>
                                            <th class="p-8">Harakat / Holat</th>
                                            <th class="p-8">Batafsil</th>
                                            <th class="p-8 text-right">Qarar vaqti</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-50">
                                        <?php 
                                        $atype = ($log_type == 'fines' ? 'fine' : 'absence');
                                        $stmt_logs = $pdo->prepare("SELECT al.*, e_admin.full_name as admin_name, e_emp.full_name as employee_name FROM admin_logs al JOIN employees e_admin ON al.admin_id = e_admin.id JOIN employees e_emp ON al.employee_id = e_emp.id WHERE al.action_type = ? ORDER BY al.created_at DESC");
                                        $stmt_logs->execute([$atype]);
                                        $logs = $stmt_logs->fetchAll();
                                        foreach ($logs as $l): ?>
                                            <tr class="hover:bg-slate-50/30 transition-colors">
                                                <td class="p-8">
                                                    <p class="font-black text-slate-700"><?= $l['admin_name'] ?></p>
                                                    <p class="text-[9px] text-slate-400 font-black uppercase tracking-tighter">Mas'ul Admin</p>
                                                </td>
                                                <td class="p-8">
                                                    <p class="font-black text-indigo-600"><?= $l['employee_name'] ?></p>
                                                    <p class="text-[9px] text-slate-400 font-black uppercase tracking-tighter">Xodim</p>
                                                </td>
                                                <td class="p-8">
                                                    <span class="px-4 py-1.5 rounded-xl text-[9px] font-black uppercase tracking-widest <?= $l['new_status'] == 'approved' ? 'bg-emerald-50 text-emerald-600 border border-emerald-100' : 'bg-rose-50 text-rose-500 border border-rose-100' ?>">
                                                        <?= $l['new_status'] == 'approved' ? 'TASDIQLADI' : 'RAD ETDI' ?>
                                                    </span>
                                                </td>
                                                <td class="p-8">
                                                    <?php 
                                                        if($l['action_type'] == 'fine') {
                                                            $st_f = $pdo->prepare("SELECT amount, reason FROM fines WHERE id = ?");
                                                            $st_f->execute([$l['target_id']]);
                                                            $fd = $st_f->fetch();
                                                            echo "<p class='font-bold text-slate-700 text-xs'>".number_format($fd['amount'] ?? 0)." UZS Jarima</p>";
                                                            echo "<p class='text-[10px] text-slate-400 italic mt-1'>\"".($fd['reason'] ?? '---')."\"</p>";
                                                        } else {
                                                            $st_a = $pdo->prepare("SELECT duration_minutes, start_time FROM absences WHERE id = ?");
                                                            $st_a->execute([$l['target_id']]);
                                                            $ad = $st_a->fetch();
                                                            echo "<p class='font-bold text-slate-700 text-xs'>".formatDurationUz($ad['duration_minutes'] ?? 0)." uzoqlashish</p>";
                                                            echo "<p class='text-[10px] text-slate-400 font-medium mt-1 uppercase'>".date('d.m.Y', strtotime($ad['start_time'] ?? 'now'))."</p>";
                                                        }
                                                    ?>
                                                </td>
                                                <td class="p-8 text-right">
                                                    <p class="text-slate-800 font-black text-[11px]"><?= date('d.m (H:i)', strtotime($l['created_at'])) ?></p>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                <?php elseif ($log_type == 'logins'): ?>
                                    <thead class="bg-slate-50/50 text-[10px] font-black text-slate-400 uppercase tracking-widest border-b border-slate-100">
                                        <tr>
                                            <th class="p-8">Xodim</th>
                                            <th class="p-8">Qurilma</th>
                                            <th class="p-8">IP Manzil</th>
                                            <th class="p-8 text-right">Kirish vaqti</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-50">
                                        <?php 
                                        $logs = $pdo->query("SELECT l.*, e.full_name FROM login_logs l JOIN employees e ON l.employee_id = e.id ORDER BY l.created_at DESC LIMIT 500")->fetchAll();
                                        foreach ($logs as $l): ?>
                                            <tr class="hover:bg-slate-50/30 transition-colors">
                                                <td class="p-8">
                                                    <p class="font-black text-slate-700"><?= $l['full_name'] ?></p>
                                                    <p class="text-[9px] text-slate-400 font-black uppercase tracking-tighter">Foydalanuvchi</p>
                                                </td>
                                                <td class="p-8">
                                                    <div class="flex items-center gap-3">
                                                        <div class="w-8 h-8 bg-slate-100 rounded-lg flex items-center justify-center text-slate-500">
                                                            <?php if(strpos($l['device_info'], 'iPhone') !== false || strpos($l['device_info'], 'Android') !== false): ?>
                                                                <i class="ri-smartphone-line"></i>
                                                            <?php else: ?>
                                                                <i class="ri-computer-line"></i>
                                                            <?php endif; ?>
                                                        </div>
                                                        <p class="text-xs text-slate-500 max-w-xs truncate font-medium" title="<?= htmlspecialchars($l['device_info']) ?>">
                                                            <?= htmlspecialchars($l['device_info']) ?>
                                                        </p>
                                                    </div>
                                                </td>
                                                <td class="p-8">
                                                    <span class="text-slate-400 font-bold text-xs"><?= $l['ip_address'] ?></span>
                                                </td>
                                                <td class="p-8 text-right">
                                                    <p class="text-slate-800 font-black text-[11px]"><?= date('d.m.Y', strtotime($l['created_at'])) ?></p>
                                                    <p class="text-slate-400 text-[10px]"><?= date('H:i', strtotime($l['created_at'])) ?></p>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                <?php endif; ?>
                            </table>
                            <?php if (empty($logs)): ?>
                                <div class="p-24 text-center">
                                    <div class="w-20 h-20 bg-slate-50 text-slate-200 rounded-[2rem] flex items-center justify-center mx-auto mb-6 text-4xl">
                                        <i class="ri-history-line"></i>
                                    </div>
                                    <p class="text-slate-300 font-black uppercase tracking-widest italic text-sm">Ushbu bo'limda hozircha o'garishlar mavjud emas</p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php elseif ($tab == 'absences' && (hasPermission($user, 'absences') || $user['role'] == 'employee')): ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Uzoqlashishlar nazorati</h2>
                    </div>

                    <!-- Web Filters (Absences) -->
                    <?php if ($isAdmin || $isManager): ?>
                    <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100 mb-8 flex gap-6 items-end">
                        <form method="GET" class="flex flex-1 gap-6 items-end">
                            <input type="hidden" name="tab" value="<?= $tab ?>">
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Xodim ismi bo'yicha</label>
                                <div class="relative">
                                    <i class="ri-search-line absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                    <input type="text" name="f_search" value="<?= $f_search ?>" placeholder="Ismni kiriting..." class="w-full pl-11 p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                </div>
                            </div>
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Filial bo'yicha</label>
                                <select name="f_branch" class="w-full p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                    <option value="">Barchasi</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= $b['id'] ?>" <?= $f_branch == $b['id'] ? 'selected' : '' ?>><?= $b['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Sana bo'yicha</label>
                                <input type="date" name="f_date" value="<?= $f_date ?>" class="w-full p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                            </div>
                            <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-2xl font-black text-xs hover:bg-blue-700 transition-all">FILTRLASH</button>
                            <?php if ($f_branch || $f_date || $f_search): ?>
                                <a href="?tab=<?= $tab ?>&clear_filters=1" class="bg-slate-100 text-slate-500 px-6 py-3 rounded-2xl font-black text-xs hover:bg-slate-200 transition-all">TOZALASH</a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div class="space-y-4">
                        <?php foreach ($absences as $a): 
                             if ($f_branch && $a['branch_id'] != $f_branch) continue;
                             if ($f_date && date('Y-m-d', strtotime($a['start_time'])) != $f_date) continue;
                             if ($f_search && stripos($a['full_name'], $f_search) === false) continue;
                        ?>
                            <div
                                class="bg-white p-6 rounded-3xl border <?= $a['status'] == 'pending' ? 'border-orange-200 bg-orange-50/20 shadow-orange-900/5' : 'border-slate-100 shadow-slate-900/5' ?> shadow-sm flex items-center justify-between">
                                <div class="flex items-center gap-6">
                                    <div
                                        class="w-14 h-14 bg-white rounded-2xl flex items-center justify-center text-slate-400 border border-slate-100 shadow-sm">
                                        <i class="ri-timer-flash-line text-2xl"></i>
                                    </div>
                                    <div>
                                        <h4 class="font-black text-lg text-slate-800"><?= $a['full_name'] ?> <span
                                                class="bg-blue-100 text-blue-600 px-2 py-0.5 rounded text-[10px] ml-2"><?= $a['branch_name'] ?></span>
                                        </h4>
                                        <p class="text-slate-400 font-medium italic">
                                            Boshlandi: <?= date('H:i (d.m.Y)', strtotime($a['start_time'])) ?>
                                        </p>
                                        <div class="mt-1">
                                            <?php if ($a['end_time']): ?>
                                                <span class="text-slate-800 font-bold"><?= formatDurationUz($a['duration_minutes']) ?></span> uzoqlashish
                                            <?php else: ?>
                                                <span class="bg-rose-100 text-rose-600 px-3 py-1 rounded-full text-[10px] font-black animate-pulse">HOZIR UZOQLASHGAN</span>
                                                <span class="text-xs text-slate-500 ml-2">(<?= formatDurationUz((time() - strtotime($a['start_time'])) / 60) ?>dan beri)</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <?php if ($a['status'] != 'pending'): ?>
                                        <span class="px-6 py-2 rounded-xl text-[10px] font-black uppercase tracking-widest <?= $a['status'] == 'approved' ? 'bg-emerald-100 text-emerald-600' : 'bg-rose-100 text-rose-500' ?>"><?= $a['status'] == 'approved' ? 'TASDIQLANGAN' : 'RAD ETILGAN' ?></span>
                                    <?php endif; ?>
                                    
                                    <div class="flex gap-2">
                                        <?php if ($user['role'] == 'superadmin'): ?>
                                            <button onclick='openEditAbsModal(<?= htmlspecialchars(json_encode($a), ENT_QUOTES) ?>)' 
                                                    class="bg-blue-50 text-blue-600 p-2.5 rounded-xl hover:bg-blue-100 transition-all" title="Tahrirlash">
                                                <i class="ri-edit-2-line"></i>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ($a['status'] != 'approved'): ?>
                                            <a href="?abs_id=<?= $a['id'] ?>&status=approved"
                                                class="bg-emerald-500 text-white px-5 py-2 rounded-xl font-black text-[10px] shadow-lg shadow-emerald-500/10 hover:bg-emerald-600 transition-all uppercase"><?= $a['status'] == 'pending' ? 'TASDIQLASH' : 'TASDIQQA O\'TKAZISH' ?></a>
                                        <?php endif; ?>
                                        <?php if ($a['status'] != 'rejected'): ?>
                                            <a href="?abs_id=<?= $a['id'] ?>&status=rejected"
                                                class="bg-rose-500 text-white px-5 py-2 rounded-xl font-black text-[10px] shadow-lg shadow-rose-500/10 hover:bg-rose-600 transition-all uppercase"><?= $a['status'] == 'pending' ? 'RAD ETISH' : 'RAD ETISHGA O\'TKAZISH' ?></a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($tab == 'offdays' && (hasPermission($user, 'offdays') || $user['role'] == 'employee')): ?>
                    <div class="bg-white p-10 rounded-[2.5rem] shadow-sm border border-slate-100">
                        <div class="flex justify-between items-center mb-10">
                            <h2 class="text-3xl font-black text-slate-800">Dam olish kuni so'rovlari</h2>
                            <?php if ($user['role'] == 'employee'): ?>
                                <button onclick="document.getElementById('requestOffdayModal').classList.remove('hidden')" 
                                    class="bg-blue-600 text-white px-8 py-3.5 rounded-2xl font-black shadow-xl shadow-blue-500/20 transition-all hover:bg-blue-700 active:scale-95 text-sm">
                                    + YANGI SO'ROV
                                </button>
                            <?php endif; ?>
                        </div>

                    <?php if ($isAdmin || $isManager): ?>
                    <!-- Web Filters (Offdays - Admin Only) -->
                    <div class="bg-slate-50 p-6 rounded-3xl mb-8 flex gap-6 items-end">
                        <form method="GET" class="flex flex-1 gap-6 items-end">
                            <input type="hidden" name="tab" value="<?= $tab ?>">
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Xodim ismi bo'yicha</label>
                                <div class="relative">
                                    <i class="ri-search-line absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                    <input type="text" name="f_search" value="<?= $f_search ?>" placeholder="Ismni kiriting..." class="w-full pl-11 p-3 bg-white border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                </div>
                            </div>
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Filial bo'yicha</label>
                                <select name="f_branch" class="w-full p-3 bg-white border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                    <option value="">Barchasi</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= $b['id'] ?>" <?= $f_branch == $b['id'] ? 'selected' : '' ?>><?= $b['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="bg-blue-600 text-white px-8 py-3 rounded-2xl font-black shadow-lg shadow-blue-500/20 hover:bg-blue-700 transition-all text-xs">FILTRLASH</button>
                            <?php if ($f_branch || $f_search): ?>
                                <a href="?tab=<?= $tab ?>&clear_filters=1" class="bg-slate-100 text-slate-500 px-6 py-3 rounded-2xl font-black text-xs hover:bg-slate-200 transition-all ml-2" style="padding: 12px 24px;">TOZALASH</a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['success'])): ?>
                        <div class="bg-emerald-50 text-emerald-600 p-4 rounded-2xl mb-6 font-bold text-sm flex items-center">
                            <i class="ri-checkbox-circle-fill mr-3 text-xl"></i> So'rovingiz muvaffaqiyatli yuborildi!
                        </div>
                    <?php endif; ?>

                    <?php if (isset($_GET['error'])): ?>
                        <div class="bg-rose-50 text-rose-600 p-4 rounded-2xl mb-6 font-bold text-sm flex items-center border border-rose-100 italic">
                            <i class="ri-error-warning-fill mr-3 text-xl"></i> <?= htmlspecialchars($_GET['error']) ?>
                        </div>
                    <?php endif; ?>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead>
                                <tr class="text-[10px] font-black text-slate-400 uppercase tracking-widest text-left">
                                    <?php if ($isAdmin): ?><th class="px-6 py-5">Xodim</th><?php endif; ?>
                                    <th class="px-6 py-5">Sana</th>
                                    <th class="px-6 py-5">Izoh</th>
                                    <th class="px-6 py-5">Holat</th>
                                    <?php if ($isAdmin): ?><th class="px-6 py-5">Amallar</th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <?php
                                if ($isAdmin || $isManager) {
                                    $offdays_sql = "SELECT o.*, e.full_name, e.branch_id FROM off_day_requests o JOIN employees e ON o.employee_id = e.id WHERE 1=1";
                                    if ($isManager) {
                                        $offdays_sql .= " AND e.branch_id = " . (int)$brId;
                                    }
                                    if ($f_branch) {
                                        $offdays_sql .= " AND e.branch_id = " . (int)$f_branch;
                                    }
                                    if ($f_search) {
                                        $offdays_sql .= " AND e.full_name LIKE " . $pdo->quote('%' . $f_search . '%');
                                    }
                                } else {
                                    $offdays_sql = "SELECT o.* FROM off_day_requests o WHERE o.employee_id = " . (int)$user['id'];
                                }
                                $offdays_sql .= " ORDER BY o.created_at DESC";
                                $offdays = $pdo->query($offdays_sql)->fetchAll();
                                foreach ($offdays as $o):
                                ?>
                                    <tr class="hover:bg-gray-50">
                                        <?php if ($isAdmin): ?><td class="px-6 py-4 font-bold text-slate-700"><?= $o['full_name'] ?></td><?php endif; ?>
                                        <td class="px-6 py-4 text-slate-600 font-medium"><?= $o['request_date'] ?></td>
                                        <td class="px-6 py-4 text-slate-500"><?= $o['reason'] ?></td>
                                        <td class="px-6 py-4">
                                            <?php if ($o['status'] == 'pending'): ?>
                                                <span class="bg-amber-50 text-amber-600 px-4 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider">KUTILMOQDA</span>
                                            <?php elseif ($o['status'] == 'approved'): ?>
                                                <span class="bg-emerald-50 text-emerald-600 px-4 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider">TASDIQLANDI</span>
                                            <?php else: ?>
                                                <span class="bg-rose-50 text-rose-500 px-4 py-1.5 rounded-xl text-[10px] font-black uppercase tracking-wider">RAD ETILDI</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($isAdmin): ?>
                                        <td class="px-6 py-4">
                                            <div class="flex gap-2">
                                                <?php if ($user['role'] == 'superadmin'): ?>
                                                    <button onclick='openEditOffModal(<?= htmlspecialchars(json_encode($o), ENT_QUOTES) ?>)' 
                                                            class="p-2 bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100 transition-all" title="Tahrirlash">
                                                        <i class="ri-edit-2-line"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <?php if ($o['status'] != 'approved'): ?>
                                                    <a href="?offday_id=<?= $o['id'] ?>&status=approved" class="p-2 bg-emerald-50 text-emerald-600 rounded-lg hover:bg-emerald-100 transition-all" title="Tasdiqlash"><i class="ri-check-line font-bold"></i></a>
                                                <?php endif; ?>
                                                <?php if ($o['status'] != 'rejected'): ?>
                                                    <a href="?offday_id=<?= $o['id'] ?>&status=rejected" class="p-2 bg-rose-50 text-rose-500 rounded-lg hover:bg-rose-100 transition-all" title="Rad etish"><i class="ri-close-line font-bold"></i></a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php elseif ($tab == 'warnings' && $isAdmin): ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Kechikish va erta ketish xabarlari</h2>
                    </div>

                    <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 border-b text-[10px] font-black text-slate-400 uppercase tracking-widest">
                                <tr>
                                    <th class="p-8">Xodim</th>
                                    <th class="p-8">Filial</th>
                                    <th class="p-8">Sabab / Izoh</th>
                                    <th class="p-8">Vaqt</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach($warnings as $w): ?>
                                    <tr class="hover:bg-slate-50/50 transition-all">
                                        <td class="p-8 font-bold text-slate-800"><?= htmlspecialchars($w['full_name']) ?></td>
                                        <td class="p-8 font-medium text-slate-500"><?= htmlspecialchars($w['branch_name'] ?? '---') ?></td>
                                        <td class="p-8 text-slate-600 italic">"<?= htmlspecialchars($w['reason']) ?>"</td>
                                        <td class="p-8 text-slate-400 text-sm font-medium"><?= date('H:i (d.m.Y)', strtotime($w['timestamp'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if(empty($warnings)): ?>
                                    <tr><td colspan="4" class="p-20 text-center text-slate-300 italic font-medium">Hozircha xabarlar mavjud emas</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab == 'payments' && hasPermission($user, 'payments')): ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">To'lovlar va Avanslar</h2>
                    </div>

                    <?php if ($isAdmin || $isManager): ?>
                    <!-- Web Filters (Payments) -->
                    <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100 mb-8 flex gap-6 items-end">
                        <form method="GET" class="flex flex-1 gap-6 items-end">
                            <input type="hidden" name="tab" value="<?= $tab ?>">
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Xodim ismi bo'yicha</label>
                                <div class="relative">
                                    <i class="ri-search-line absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                    <input type="text" name="f_search" value="<?= $f_search ?>" placeholder="Ismni kiriting..." class="w-full pl-11 p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                </div>
                            </div>
                            <button type="submit" class="bg-blue-600 text-white px-8 py-3 rounded-2xl font-black shadow-lg shadow-blue-500/20 hover:bg-blue-700 transition-all text-xs">QIDIRISH</button>
                            <?php if ($f_search): ?>
                                <a href="?tab=<?= $tab ?>&clear_filters=1" class="bg-slate-100 text-slate-500 px-6 py-3 rounded-2xl font-black text-xs hover:bg-slate-200 transition-all">TOZALASH</a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <?php endif; ?>

                    <div class="grid grid-cols-3 gap-10">
                        <!-- Add Payment Form -->
                        <div class="col-span-1">
                            <form method="POST"
                                class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 space-y-6">
                                <h3 class="text-lg font-black text-slate-800 mb-4">To'lovni qayd etish</h3>

                                <div class="space-y-2">
                                    <label class="text-xs font-black text-slate-400 uppercase ml-2">Xodim</label>
                                    <select name="employee_id"
                                        class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                                        required>
                                        <?php foreach ($employees as $e): ?>
                                            <option value="<?= $e['id'] ?>"><?= $e['full_name'] ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-xs font-black text-slate-400 uppercase ml-2">Summa (UZS)</label>
                                    <input type="number" name="amount"
                                        class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                                        required>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-xs font-black text-slate-400 uppercase ml-2">Turi</label>
                                    <select name="type"
                                        class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner">
                                        <option value="advance">Avans</option>
                                        <option value="salary">Oylik to'lovi</option>
                                    </select>
                                </div>

                                <div class="space-y-2">
                                    <label class="text-xs font-black text-slate-400 uppercase ml-2">Izoh</label>
                                    <textarea name="comment"
                                        class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                                        rows="3"></textarea>
                                </div>

                                <button type="submit" name="save_payment"
                                    class="w-full p-5 bg-indigo-600 text-white font-black rounded-[2rem] shadow-xl shadow-indigo-500/20 hover:bg-indigo-700 transition-all">SAQLASH</button>
                            </form>
                        </div>

                        <!-- Payment History -->
                        <div class="col-span-2">
                            <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden">
                                <div class="p-8 border-b bg-slate-50/50">
                                    <h3 class="font-black text-slate-800">To'lovlar tarixi</h3>
                                </div>
                                <div class="p-8">
                                    <table class="w-full text-left">
                                        <thead class="text-[10px] font-black text-slate-400 uppercase border-b pb-4">
                                            <tr>
                                                <th class="pb-4">Xodim</th>
                                                <th class="pb-4">Summa</th>
                                                <th class="pb-4">Turi</th>
                                                <th class="pb-4">Sana</th>
                                                <th class="pb-4 text-right">Amal</th>
                                            </tr>
                                        </thead>
                                        <tbody class="divide-y">
                                            <?php
                                            $payments_sql = "SELECT p.*, e.full_name, e.branch_id FROM payments p JOIN employees e ON p.employee_id = e.id";
                                            $where_pay = [];
                                            if ($isManager) {
                                                $where_pay[] = "e.branch_id = " . (int)$brId;
                                            }
                                            if ($f_search) {
                                                $where_pay[] = "e.full_name LIKE " . $pdo->quote('%' . $f_search . '%');
                                            }
                                            if ($where_pay) {
                                                $payments_sql .= " WHERE " . implode(" AND ", $where_pay);
                                            }
                                            $payments_sql .= " ORDER BY created_at DESC LIMIT 100";
                                            $all_payments = $pdo->query($payments_sql)->fetchAll();
                                            foreach ($all_payments as $p): ?>
                                                <tr class="group hover:bg-slate-50 transition-colors">
                                                    <td class="py-4 font-bold text-slate-700"><?= $p['full_name'] ?></td>
                                                    <td
                                                        class="py-4 font-black <?= $p['type'] == 'advance' ? 'text-rose-600' : 'text-emerald-600' ?>">
                                                        <?= number_format($p['amount']) ?> UZS
                                                    </td>
                                                    <td class="py-4">
                                                        <span
                                                            class="px-3 py-1 rounded-full text-[10px] font-black uppercase tracking-widest <?= $p['type'] == 'advance' ? 'bg-rose-100 text-rose-600' : 'bg-emerald-100 text-emerald-600' ?>">
                                                            <?= $p['type'] == 'advance' ? 'Avans' : 'Oylik' ?>
                                                        </span>
                                                    </td>
                                                    <td class="py-4 text-slate-400 text-sm italic">
                                                        <?= date('d.m.Y H:i', strtotime($p['created_at'])) ?>
                                                    </td>
                                                    <td class="py-4 text-right">
                                                        <div class="flex justify-end gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                                            <button onclick="openPaymentEditModal(<?= $p['id'] ?>, <?= $p['amount'] ?>, '<?= addslashes($p['comment']) ?>')" 
                                                                    class="w-8 h-8 flex items-center justify-center bg-blue-50 text-blue-600 rounded-lg hover:bg-blue-100" title="Tahrirlash">
                                                                <i class="ri-edit-2-line"></i>
                                                            </button>
                                                            <a href="?tab=payments&del_payment=<?= $p['id'] ?>" 
                                                               onclick="return confirm('Haqiqatan ham ushbu to\'lovni o\'chirmoqchimisiz? Bu xodim balansiga ta\'sir qiladi.')"
                                                               class="w-8 h-8 flex items-center justify-center bg-rose-50 text-rose-500 rounded-lg hover:bg-rose-100" title="O'chirish">
                                                                <i class="ri-delete-bin-line"></i>
                                                            </a>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>

                <?php elseif ($tab == 'employees' && $isAdmin): ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Xodimlar bazasi</h2>
                        <div class="flex gap-4">
                            <?php if($user['role'] == 'superadmin'): ?>
                                <a href="?sync_all=1" onclick="return confirm('Barcha xodimlar balansini hisobotga asosan qayta hisoblab chiqishni istaysizmi?')"
                                    class="bg-slate-100 text-slate-600 px-6 py-3.5 rounded-2xl font-black flex items-center gap-2 hover:bg-slate-200 transition-all text-sm">
                                    <i class="ri-refresh-line"></i> SYNC ALL
                                </a>
                            <?php endif; ?>
                            <button onclick="openEmpModal()"
                                class="bg-blue-600 text-white px-8 py-3.5 rounded-2xl font-black shadow-xl shadow-blue-500/20 transition-all hover:bg-blue-700 active:scale-95 text-sm">+
                                YANGI QO'SHISH</button>
                        </div>
                    </div>
                    <?php $rolesList = $pdo->query("SELECT * FROM roles")->fetchAll(); ?>

                    <!-- Web Filters (Employees) -->
                    <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100 mb-8 flex gap-6 items-end">
                        <form method="GET" class="flex flex-1 gap-6 items-end">
                            <input type="hidden" name="tab" value="<?= $tab ?>">
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Xodim ismi bo'yicha</label>
                                <div class="relative">
                                    <i class="ri-search-line absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                    <input type="text" name="f_search" value="<?= $f_search ?>" placeholder="Ismni kiriting..." class="w-full pl-11 p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                </div>
                            </div>
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Filial bo'yicha</label>
                                <select name="f_branch" class="w-full p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                    <option value="">Barchasi</option>
                                    <?php foreach ($branches as $b): ?>
                                        <option value="<?= $b['id'] ?>" <?= $f_branch == $b['id'] ? 'selected' : '' ?>><?= $b['name'] ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" class="bg-blue-600 text-white px-6 py-3 rounded-2xl font-black text-xs hover:bg-blue-700 transition-all">FILTRLASH</button>
                            <?php if ($f_branch || $f_search): ?>
                                <a href="?tab=<?= $tab ?>&clear_filters=1" class="bg-slate-100 text-slate-500 px-6 py-3 rounded-2xl font-black text-xs hover:bg-slate-200 transition-all">TOZALASH</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden">
                        <table class="w-full text-left">
                            <thead class="bg-slate-50 border-b text-[11px] font-black text-slate-400 uppercase tracking-widest">
                                <tr>
                                    <th class="p-8">Xodim F.I.SH</th>
                                    <th class="p-8">Lavozim / Rol</th>
                                    <th class="p-8">Oylik</th>
                                    <th class="p-8">Filial</th>
                                    <th class="p-8">Dam olish</th>
                                    <th class="p-8">Balans</th>
                                    <th class="p-8 text-right">Amal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach ($employees as $e): 
                                    if ($f_branch && $e['branch_id'] != $f_branch) continue;
                                    if ($f_search && stripos($e['full_name'], $f_search) === false) continue;
                                    
                                    // Fetch last attendance records for snippets (greedy pairing for night shifts)
                                    $stmtLog = $pdo->prepare("SELECT * FROM attendance WHERE employee_id = ? ORDER BY timestamp DESC LIMIT 10");
                                    $stmtLog->execute([$e['id']]);
                                    $eLogs = $stmtLog->fetchAll();
                                    
                                    $snippets = [];
                                    $tempOutRecord = null;
                                    foreach ($eLogs as $el) {
                                        if ($el['type'] == 'check-out') {
                                            $tempOutRecord = $el;
                                        } elseif ($el['type'] == 'check-in') {
                                            $isValidOut = ($tempOutRecord && (strtotime($tempOutRecord['timestamp']) - strtotime($el['timestamp'])) / 60 < 2880 && strtotime($tempOutRecord['timestamp']) > strtotime($el['timestamp']));
                                            $snippets[] = [
                                                'in_id' => $el['id'],
                                                'in_time' => $el['timestamp'],
                                                'out_id' => $isValidOut ? $tempOutRecord['id'] : null,
                                                'out_time' => $isValidOut ? $tempOutRecord['timestamp'] : null
                                            ];
                                            $tempOutRecord = null;
                                            if (count($snippets) >= 3) break;
                                        }
                                    }
                                ?>
                                    <tr class="hover:bg-slate-50/50 transition-all cursor-pointer group" onclick='viewEmp(<?= htmlspecialchars(json_encode($e), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($snippets), ENT_QUOTES) ?>)'>
                                        <td class="p-8">
                                            <div class="flex items-center gap-4">
                                                <div
                                                    class="w-10 h-10 bg-slate-100 rounded-xl flex items-center justify-center font-black text-slate-400 text-xs group-hover:bg-blue-100 group-hover:text-blue-500 transition-colors">
                                                    <?= substr($e['full_name'], 0, 1) ?>
                                                </div>
                                                <div>
                                                    <p class="font-bold text-slate-800"><?= $e['full_name'] ?></p>
                                                    <p class="text-xs text-slate-400"><?= $e['email'] ?></p>
                                                </div>
                                            </div>
                                            <!-- Web Snippet -->
                                            <?php if($snippets): ?>
                                                <div class="mt-4 flex gap-2">
                                                    <?php foreach($snippets as $s): ?>
                                                        <div class="bg-slate-50 p-2 rounded-lg border border-slate-100 text-[9px] font-bold text-slate-500">
                                                            <?= date('d.m', strtotime($s['in_time'])) ?>: <?= date('H:i', strtotime($s['in_time'])) ?>-<?= $s['out_time'] ? date('H:i', strtotime($s['out_time'])) : '...' ?>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-8">
                                            <p class="font-bold text-slate-600"><?= $e['position'] ?></p>
                                            <div class="flex gap-2 items-center mt-1">
                                                <span class="text-[10px] uppercase font-black text-blue-500 bg-blue-50 px-1.5 rounded"><?= $e['role'] ?></span>
                                                <?php if($e['custom_role_name']): ?>
                                                    <span class="text-[10px] uppercase font-black text-indigo-500 bg-indigo-50 px-1.5 rounded"><?= $e['custom_role_name'] ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="p-8 font-black text-emerald-600"><?= number_format($e['monthly_salary']) ?> <span
                                                class="text-[10px] text-slate-400 font-bold">UZS/oy</span>
                                            <p class="text-[9px] text-slate-400 font-medium"><?= number_format($e['hourly_rate'], 1) ?> UZS/soat (<?= $e['work_days_per_month'] ?> kun)</p>
                                        </td>
                                         <td class="p-8 font-bold text-slate-500"><?= $e['branch_name'] ?></td>
                                         <td class="p-8">
                                              <div class="text-xs font-black text-slate-500">
                                                  <?= $e['off_days_per_month'] ?? 4 ?> kun
                                              </div>
                                         </td>
                                         <td class="p-8">
                                             <div class="px-4 py-2 bg-blue-50 rounded-2xl inline-block border border-blue-100">
                                                 <span class="text-blue-600 font-black text-sm"><?= number_format($e['balance'] ?? 0, 0, '.', ' ') ?></span>
                                                 <span class="text-[9px] text-blue-400 font-bold uppercase ml-1">UZS</span>
                                             </div>
                                         </td>
                                        <td class="p-8 text-right">
                                            <div class="flex justify-end gap-3 text-slate-300">
                                                <?php if($user['role'] == 'superadmin'): ?>
                                                    <a href="?tab=employees&toggle_block=<?= $e['id'] ?>"
                                                       onclick="event.stopPropagation(); return confirm('<?= $e['is_blocked'] ? 'Blokdan chiqarilsinmi?' : 'Bloklansinmi?' ?>')"
                                                       class="<?= $e['is_blocked'] ? 'text-rose-500' : 'hover:text-amber-500' ?> transition-colors p-2" title="<?= $e['is_blocked'] ? 'Bloklangan (Ochish)' : 'Bloklash' ?>">
                                                        <i class="<?= $e['is_blocked'] ? 'ri-lock-fill' : 'ri-lock-unlock-line' ?> text-xl"></i>
                                                     </a>
                                                <?php endif; ?>
                                                <button onclick='event.stopPropagation(); openEmpModal(<?= htmlspecialchars(json_encode($e), ENT_QUOTES) ?>)'
                                                    class="hover:text-blue-500 transition-colors p-2"><i
                                                        class="ri-edit-2-line text-xl"></i></button>
                                                <a href="?tab=employees&del_emp=<?= $e['id'] ?>"
                                                    onclick="event.stopPropagation(); return confirm('O\'chirmoqchimisiz?')"
                                                    class="hover:text-rose-500 transition-colors p-2"><i
                                                        class="ri-delete-bin-6-line text-xl"></i></a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab == 'branches' && $isAdmin): ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Filiallar</h2>
                        <button onclick="openBranchModal()"
                            class="bg-blue-600 text-white px-8 py-3.5 rounded-2xl font-black shadow-xl shadow-blue-500/20 hover:bg-blue-700 active:scale-95 text-sm">+
                            YANGI FILIAL</button>
                    </div>

                    <!-- Web Filters (Branches) -->
                    <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100 mb-8 flex gap-6 items-end">
                        <form method="GET" class="flex flex-1 gap-6 items-end">
                            <input type="hidden" name="tab" value="<?= $tab ?>">
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Filial nomi bo'yicha</label>
                                <div class="relative">
                                    <i class="ri-search-line absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                    <input type="text" name="f_search" value="<?= $f_search ?>" placeholder="Filial nomini kiriting..." class="w-full pl-11 p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                </div>
                            </div>
                            <button type="submit" class="bg-blue-600 text-white px-8 py-3 rounded-2xl font-black shadow-lg shadow-blue-500/20 hover:bg-blue-700 transition-all text-xs">QIDIRISH</button>
                            <?php if ($f_search): ?>
                                <a href="?tab=<?= $tab ?>&clear_filters=1" class="bg-slate-100 text-slate-500 px-6 py-3 rounded-2xl font-black text-xs hover:bg-slate-200 transition-all">TOZALASH</a>
                            <?php endif; ?>
                        </form>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                        <?php foreach ($branches as $b): 
                            if ($f_search && stripos($b['name'], $f_search) === false) continue;
                        ?>
                            <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 relative group">
                                <div class="absolute top-6 right-6 flex gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <button onclick='openBranchModal(<?= htmlspecialchars(json_encode($b), ENT_QUOTES) ?>)'
                                        class="w-8 h-8 bg-blue-50 text-blue-500 rounded-lg flex items-center justify-center hover:bg-blue-100"><i
                                            class="ri-edit-2-line"></i></button>
                                    <a href="?tab=branches&del_br=<?= $b['id'] ?>" onclick="return confirm('O\'chirilsinmi?')"
                                        class="w-8 h-8 bg-rose-50 text-rose-500 rounded-lg flex items-center justify-center hover:bg-rose-100"><i
                                            class="ri-delete-bin-line"></i></a>
                                </div>
                                <div
                                    class="w-16 h-16 bg-blue-50 text-blue-600 rounded-3xl flex items-center justify-center mb-6 text-2xl shadow-inner shadow-blue-100">
                                    <i class="ri-map-pin-2-fill"></i>
                                </div>
                                <h4 class="text-2xl font-black text-slate-800 mb-2"><?= $b['name'] ?></h4>
                                <p class="text-slate-400 font-bold text-sm mb-6">Radius: <span
                                        class="text-blue-600"><?= $b['radius'] ?> m</span></p>
                                <div
                                    class="bg-slate-50 p-4 rounded-2xl flex justify-between text-[11px] font-black text-slate-400 uppercase tracking-tighter">
                                    <span>LAT: <?= round($b['latitude'], 5) ?></span><span>LON:
                                        <?= round($b['longitude'], 5) ?></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($tab == 'roles' && $user['role'] == 'superadmin'): ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Maxsus rollar boshqaruvi</h2>
                        <button onclick="openRoleModal()"
                            class="bg-blue-600 text-white px-8 py-3.5 rounded-2xl font-black shadow-xl shadow-blue-500/20 transition-all hover:bg-blue-700 active:scale-95 text-sm">+
                            YANGI QO'SHISH</button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                        <?php 
                        $roles = $pdo->query("SELECT * FROM roles")->fetchAll();
                        foreach($roles as $r): ?>
                            <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 flex flex-col justify-between">
                                <div>
                                    <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mb-6">
                                        <i class="ri-shield-user-line text-3xl"></i>
                                    </div>
                                    <h4 class="text-2xl font-black text-slate-800 mb-2"><?= $r['name'] ?></h4>
                                    <div class="flex flex-wrap gap-2 mb-8">
                                        <?php
                                        $perms = json_decode($r['permissions'], true) ?? [];
                                        foreach($perms as $p): ?>
                                            <span class="bg-slate-100 text-slate-500 px-3 py-1 rounded-lg text-[10px] font-black uppercase"><?= $p ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <div class="flex gap-4 border-t pt-6">
                                    <button onclick='openRoleModal(<?= htmlspecialchars(json_encode($r), ENT_QUOTES) ?>)' class="flex-1 bg-slate-50 text-slate-600 p-4 rounded-2xl font-black text-xs hover:bg-slate-100 transition-all">TAHRIRLASH</button>
                                    <a href="?del_role=<?= $r['id'] ?>" onclick="return confirm('Siz rostdan ham ushbu rolni o\'chirmoqchimisiz?')" class="p-4 bg-rose-50 text-rose-500 rounded-2xl hover:bg-rose-100 transition-all">
                                        <i class="ri-delete-bin-line text-lg"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($tab == 'fines' && $isAdmin): ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Jarimalar bo'limi</h2>
                        <div class="flex gap-4">
                            <button onclick="openFineModal()"
                                class="bg-blue-600 text-white px-8 py-3.5 rounded-2xl font-black shadow-xl shadow-blue-500/20 transition-all hover:bg-blue-700 active:scale-95 text-sm">+ YANGI JARIMA</button>
                            <div class="text-slate-400 font-bold text-sm bg-white px-6 py-3 rounded-2xl border border-slate-100 italic">
                                Jami: <?= count($fines) ?> ta jarima
                            </div>
                        </div>
                    </div>

                    <!-- Web Filters (Fines) -->
                    <div class="bg-white p-6 rounded-3xl shadow-sm border border-slate-100 mb-8 flex gap-6 items-end">
                        <form method="GET" class="flex flex-1 gap-6 items-end">
                            <input type="hidden" name="tab" value="<?= $tab ?>">
                            <div class="flex-1 space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Xodim ismi bo'yicha</label>
                                <div class="relative">
                                    <i class="ri-search-line absolute left-4 top-1/2 -translate-y-1/2 text-slate-400"></i>
                                    <input type="text" name="f_search" value="<?= $f_search ?>" placeholder="Ismni kiriting..." class="w-full pl-11 p-3 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                </div>
                            </div>
                            <button type="submit" class="bg-blue-600 text-white px-8 py-3 rounded-2xl font-black shadow-lg shadow-blue-500/20 hover:bg-blue-700 transition-all text-xs">QIDIRISH</button>
                            <?php if ($f_search): ?>
                                <a href="?tab=<?= $tab ?>&clear_filters=1" class="bg-slate-100 text-slate-500 px-6 py-3 rounded-2xl font-black text-xs hover:bg-slate-200 transition-all">TOZALASH</a>
                            <?php endif; ?>
                        </form>
                    </div>

                    <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-slate-50/50 border-b border-slate-100 uppercase text-[10px] font-black text-slate-400 tracking-widest">
                                    <th class="p-8">Xodim</th>
                                    <th class="p-8">Filial</th>
                                    <th class="p-8">Miqdor</th>
                                    <th class="p-8">Sana</th>
                                    <th class="p-8">Sabab</th>
                                    <th class="p-8">Holat</th>
                                    <th class="p-8 text-right">Amal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach ($fines as $f): 
                                    if ($f_search && stripos($f['full_name'] ?? '', $f_search) === false) continue;
                                ?>
                                    <tr class="hover:bg-slate-50/30 transition-colors group">
                                        <td class="p-8 font-black text-slate-700"><?= $f['full_name'] ?></td>
                                        <td class="p-8 text-slate-500 font-bold"><?= $f['branch_name'] ?></td>
                                        <td class="p-8">
                                            <span class="bg-rose-50 text-rose-600 px-4 py-1.5 rounded-xl font-black text-sm">
                                                <?= number_format($f['amount'], 0, '.', ' ') ?> so'm
                                            </span>
                                        </td>
                                        <td class="p-8 text-slate-400 font-bold text-sm"><?= date('d.m.Y H:i', strtotime($f['date'])) ?></td>
                                        <td class="p-8 text-slate-500 text-sm italic font-medium max-w-xs truncate"><?= $f['reason'] ?></td>
                                        <td class="p-8">
                                            <?php if ($f['status'] == 'pending'): ?>
                                                <span class="bg-amber-50 text-amber-600 px-4 py-1.5 rounded-xl font-black text-[10px] uppercase">KUTILMOQDA</span>
                                            <?php elseif ($f['status'] == 'approved'): ?>
                                                <span class="bg-rose-50 text-rose-600 px-4 py-1.5 rounded-xl font-black text-[10px] uppercase">JARIMA</span>
                                            <?php else: ?>
                                                <span class="bg-emerald-50 text-emerald-600 px-4 py-1.5 rounded-xl font-black text-[10px] uppercase">JARIMASIZ</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="p-8 text-right">
                                            <div class="flex justify-end gap-3 opacity-0 group-hover:opacity-100 transition-opacity">
                                                <?php if ($f['status'] == 'pending'): ?>
                                                    <a href="?fine_id=<?= $f['id'] ?>&status=rejected" 
                                                       class="bg-emerald-500 text-white px-4 py-2 rounded-xl text-[10px] font-black hover:bg-emerald-600 transition-all shadow-sm flex items-center gap-1">
                                                        <i class="ri-check-line"></i> JARIMASIZ
                                                    </a>
                                                    <a href="?fine_id=<?= $f['id'] ?>&status=approved" 
                                                       class="bg-rose-500 text-white px-4 py-2 rounded-xl text-[10px] font-black hover:bg-rose-600 transition-all shadow-sm flex items-center gap-1">
                                                        <i class="ri-close-line"></i> JARIMA
                                                    </a>
                                                <?php elseif ($f['status'] == 'approved'): ?>
                                                    <a href="?fine_id=<?= $f['id'] ?>&status=rejected" 
                                                       class="bg-emerald-100 text-emerald-600 px-4 py-2 rounded-xl text-[10px] font-black hover:bg-emerald-200 transition-all shadow-sm flex items-center gap-1">
                                                        <i class="ri-edit-2-line"></i> JARIMASIZGA O'TKAZISH
                                                    </a>
                                                <?php else: ?>
                                                    <a href="?fine_id=<?= $f['id'] ?>&status=approved" 
                                                       class="bg-rose-100 text-rose-600 px-4 py-2 rounded-xl text-[10px] font-black hover:bg-rose-200 transition-all shadow-sm flex items-center gap-1">
                                                        <i class="ri-edit-2-line"></i> JARIMAGA O'TKAZISH
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($fines)): ?>
                                    <tr>
                                        <td colspan="7" class="p-20 text-center">
                                            <div class="flex flex-col items-center gap-4 text-slate-300">
                                                <i class="ri-error-warning-line text-6xl"></i>
                                                <p class="font-black uppercase tracking-widest">Hozircha jarimalar yo'q</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab == 'fine_types' && $isAdmin): ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Jarima turlari</h2>
                        <button onclick="openFineTypeModal()"
                            class="bg-blue-600 text-white px-8 py-3.5 rounded-2xl font-black shadow-xl shadow-blue-500/20 transition-all hover:bg-blue-700 active:scale-95 text-sm">
                            + YANGI QO'SHISH
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
                        <?php foreach ($fineTypes as $ft): ?>
                            <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 flex flex-col justify-between group">
                                <div class="relative">
                                    <div class="w-16 h-16 bg-orange-50 text-orange-600 rounded-[1.5rem] flex items-center justify-center mb-6 shadow-inner shadow-orange-100">
                                        <i class="ri-settings-4-line text-3xl"></i>
                                    </div>
                                    <h4 class="text-2xl font-black text-slate-800 mb-2"><?= $ft['name'] ?></h4>
                                    <p class="text-rose-600 font-black text-lg mb-4"><?= number_format($ft['amount'], 0, '.', ' ') ?> so'm</p>
                                    <p class="text-slate-400 font-bold text-sm italic mb-6 leading-relaxed"><?= $ft['description'] ?: 'Tavsif yo\'q' ?></p>
                                </div>
                                <div class="flex gap-4 border-t border-slate-50 pt-6">
                                    <button onclick='openFineTypeModal(<?= htmlspecialchars(json_encode($ft)) ?>)' 
                                            class="flex-1 bg-slate-50 text-slate-600 p-4 rounded-2xl font-black text-xs hover:bg-slate-100 transition-all">
                                        TAHRIRLASH
                                    </button>
                                    <a href="?tab=fine_types&del_fine_type=<?= $ft['id'] ?>" 
                                       onclick="return confirm('Siz rostdan ham ushbu jarima turini o\'chirmoqchimisiz?')" 
                                       class="p-4 bg-rose-50 text-rose-500 rounded-2xl hover:bg-rose-100 transition-all">
                                        <i class="ri-delete-bin-line text-lg"></i>
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($fineTypes)): ?>
                            <div class="col-span-full bg-white p-20 rounded-[3rem] text-center border-2 border-dashed border-slate-100 items-center justify-center flex flex-col gap-4">
                                <i class="ri-list-settings-line text-6xl text-slate-200"></i>
                                <p class="text-slate-400 font-black uppercase tracking-widest text-sm">Jarima turlari mavjud emas</p>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php elseif ($tab == 'expenses' && $isAdmin): ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Xarajatlar bo'limi</h2>
                        <button onclick="document.getElementById('expenseModal').classList.remove('hidden')"
                            class="bg-blue-600 text-white px-8 py-3.5 rounded-2xl font-black shadow-xl shadow-blue-500/20 transition-all hover:bg-blue-700 active:scale-95 text-sm">
                            + YANGI XARAJAT
                        </button>
                    </div>

                    <!-- Modern Filter Section -->
                    <div class="mb-10 flex flex-wrap items-center justify-between gap-6">
                        <div class="flex flex-wrap items-center gap-3 bg-white p-2 rounded-[2rem] shadow-sm border border-slate-100">
                            <a href="?tab=expenses" 
                               class="px-6 py-3 rounded-full font-black text-[10px] uppercase tracking-widest transition-all <?= (empty($f_period) && empty($f_month)) ? 'bg-blue-600 text-white shadow-lg shadow-blue-200' : 'text-slate-400 hover:bg-slate-50 hover:text-slate-600' ?>">
                                Barcha vaqtlar
                            </a>
                            <a href="?tab=expenses&f_period=this_month" 
                               class="px-6 py-3 rounded-full font-black text-[10px] uppercase tracking-widest transition-all <?= $f_period == 'this_month' ? 'bg-blue-600 text-white shadow-lg shadow-blue-200' : 'text-slate-400 hover:bg-slate-50 hover:text-slate-600' ?>">
                                Ushbu oy
                            </a>
                            <a href="?tab=expenses&f_period=last_7_days" 
                               class="px-6 py-3 rounded-full font-black text-[10px] uppercase tracking-widest transition-all <?= $f_period == 'last_7_days' ? 'bg-blue-600 text-white shadow-lg shadow-blue-200' : 'text-slate-400 hover:bg-slate-50 hover:text-slate-600' ?>">
                                So'nggi 7 kun
                            </a>
                            <a href="?tab=expenses&f_period=custom" 
                               class="px-6 py-3 rounded-full font-black text-[10px] uppercase tracking-widest transition-all <?= $f_period == 'custom' ? 'bg-blue-600 text-white shadow-lg shadow-blue-200' : 'text-slate-400 hover:bg-slate-50 hover:text-slate-600' ?>">
                                Oraliqni tanlash
                            </a>
                            <!-- Custom Premium Monthly Dropdown -->
                            <?php 
                                $uzMonths = [
                                    'January' => 'Yanvar', 'February' => 'Fevral', 'March' => 'Mart', 
                                    'April' => 'Aprel', 'May' => 'May', 'June' => 'Iyun', 
                                    'July' => 'Iyul', 'August' => 'Avgust', 'September' => 'Sentabr', 
                                    'October' => 'Oktabr', 'November' => 'Noyabr', 'December' => 'Dekabr'
                                ];
                                $selectedMonthText = "Oy tanlash";
                                if (!empty($f_month)) {
                                    $m_name_s = date('F', strtotime($f_month."-01"));
                                    $m_year_s = date('Y', strtotime($f_month."-01"));
                                    $selectedMonthText = ($uzMonths[$m_name_s] ?? $m_name_s) . " " . $m_year_s;
                                }
                            ?>
                            <div class="relative group ml-2 border-l-2 border-slate-50 pl-4">
                                <button class="flex items-center gap-3 bg-slate-50 px-6 py-2.5 rounded-full border border-slate-100 font-black text-[10px] uppercase tracking-widest text-slate-700 hover:bg-slate-100 transition-all">
                                    <i class="ri-calendar-2-line text-blue-500 text-sm"></i>
                                    <span><?= $selectedMonthText ?></span>
                                    <i class="ri-arrow-down-s-line ml-1 opacity-40 group-hover:rotate-180 transition-transform duration-300"></i>
                                </button>
                                
                                <div class="absolute top-[calc(100%+8px)] left-0 w-64 bg-white rounded-[2rem] shadow-[0_20px_50px_rgba(0,0,0,0.1)] border border-slate-50 opacity-0 invisible group-hover:opacity-100 group-hover:visible translate-y-2 group-hover:translate-y-0 transition-all duration-300 z-[60] p-3 max-h-[350px] overflow-y-auto scrollbar-hide">
                                    <p class="text-[9px] font-black text-slate-400 uppercase tracking-widest px-4 py-2 mb-1">O'tgan oillar</p>
                                    <div class="grid grid-cols-1 gap-1">
                                        <?php for($i=0; $i<12; $i++): 
                                            $m_val = date('Y-m', strtotime("-$i months"));
                                            $m_name = date('F', strtotime("-$i months"));
                                            $m_year = date('Y', strtotime("-$i months"));
                                            $m_text = ($uzMonths[$m_name] ?? $m_name) . " " . $m_year;
                                            $isActive = ($f_month == $m_val);
                                        ?>
                                            <a href="?tab=expenses&f_month=<?= $m_val ?>" 
                                               class="px-5 py-3 rounded-2xl flex items-center justify-between group/item transition-all <?= $isActive ? 'bg-blue-600 text-white shadow-lg shadow-blue-200' : 'hover:bg-slate-50 text-slate-600 hover:text-blue-600' ?>">
                                                <span class="font-bold text-xs uppercase tracking-tight"><?= $m_text ?></span>
                                                <?php if($isActive): ?>
                                                    <i class="ri-checkbox-circle-fill text-lg"></i>
                                                <?php else: ?>
                                                    <i class="ri-arrow-right-s-line opacity-0 group-hover/item:opacity-30 transition-opacity"></i>
                                                <?php endif; ?>
                                            </a>
                                        <?php endfor; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <?php if ($f_period == 'custom'): ?>
                            <div class="bg-white p-2 rounded-[2rem] shadow-sm border border-slate-100 flex items-center gap-3 animate-in slide-in-from-right-10 duration-500">
                                <form method="GET" class="flex items-center gap-3">
                                    <input type="hidden" name="tab" value="expenses">
                                    <input type="hidden" name="f_period" value="custom">
                                    <input type="date" name="f_start" value="<?= $f_start ?>" class="bg-slate-50 px-5 py-2.5 rounded-full border-none font-bold text-[10px] outline-none text-slate-600 ring-2 ring-transparent focus:ring-blue-100 transition-all">
                                    <span class="text-slate-300 font-black">-</span>
                                    <input type="date" name="f_end" value="<?= $f_end ?>" class="bg-slate-50 px-5 py-2.5 rounded-full border-none font-bold text-[10px] outline-none text-slate-600 ring-2 ring-transparent focus:ring-blue-100 transition-all">
                                    <button type="submit" class="bg-slate-900 text-white px-6 py-2.5 rounded-full font-black text-[10px] hover:bg-black transition-all shadow-xl shadow-slate-200 uppercase tracking-widest">Qidirish</button>
                                </form>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($f_month) || !empty($f_period) || !empty($f_start)): ?>
                            <a href="?tab=expenses&clear_filters=1" class="text-rose-500 font-black text-[10px] uppercase hover:underline flex items-center gap-1 group">
                                <i class="ri-filter-off-line group-hover:rotate-12 transition-transform"></i> Tozalash
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-10">
                        <!-- Stats Card -->
                        <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 flex flex-col justify-center">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-2">
                                <?= empty($f_period) ? "Umumiy xarajat" : "Filtrlangan xarajat" ?>
                            </p>
                            <?php 
                                $totalSum = 0;
                                foreach($expensesLog as $ex) {
                                    $totalSum += floatval($ex['amount']);
                                }
                            ?>
                            <h2 class="text-4xl font-black text-rose-600"><?= number_format($totalSum, 0, '.', ' ') ?> <span class="text-sm text-slate-400 font-bold uppercase">UZS</span></h2>
                            <p class="text-xs text-slate-400 mt-4 font-medium italic">Tanlangan davr bo'yicha</p>
                        </div>
                        
                        <!-- Chart 1: Categories -->
                        <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 flex flex-col items-center">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-6">Turlar bo'yicha taqsimot</p>
                            <div class="w-full relative h-[180px]">
                                <canvas id="expenseCategoryChart"></canvas>
                            </div>
                        </div>

                        <!-- Chart 2: Daily Trend -->
                        <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 flex flex-col items-center">
                            <p class="text-[10px] font-black text-slate-400 uppercase tracking-widest mb-6">Kunlik xarajatlar dinamikasi</p>
                            <div class="w-full relative h-[180px]">
                                <canvas id="expenseTrendChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <?php
                        // Prepare Chart Data
                        $catData = [];
                        foreach($expensesLog as $ex) {
                            $c = $ex['category_name'];
                            if(!isset($catData[$c])) $catData[$c] = 0;
                            $catData[$c] += floatval($ex['amount']);
                        }

                        // Last 7 days trend
                        $trendData = [];
                        for($i=6; $i>=0; $i--) {
                            $d = date('Y-m-d', strtotime("-$i days"));
                            $trendData[$d] = 0;
                        }
                        foreach($expensesLog as $ex) {
                            $ed = date('Y-m-d', strtotime($ex['date']));
                            if(isset($trendData[$ed])) {
                                $trendData[$ed] += floatval($ex['amount']);
                            }
                        }
                    ?>

                    <script>
                        document.addEventListener('DOMContentLoaded', () => {
                            // Category Chart
                            new Chart(document.getElementById('expenseCategoryChart'), {
                                type: 'doughnut',
                                data: {
                                    labels: <?= json_encode(array_keys($catData)) ?>,
                                    datasets: [{
                                        data: <?= json_encode(array_values($catData)) ?>,
                                        backgroundColor: ['#ef4444', '#3b82f6', '#10b981', '#f59e0b', '#6366f1', '#ec4899'],
                                        borderWidth: 0,
                                        hoverOffset: 12
                                    }]
                                },
                                options: {
                                    cutout: '75%',
                                    plugins: {
                                        legend: { 
                                            position: 'bottom', 
                                            labels: { boxWidth: 6, font: { weight: '800', size: 9, family: 'Outfit' }, padding: 10 } 
                                        }
                                    },
                                    maintainAspectRatio: false
                                }
                            });

                            // Trend Chart
                            new Chart(document.getElementById('expenseTrendChart'), {
                                type: 'bar',
                                data: {
                                    labels: <?= json_encode(array_map(fn($d) => date('d.m', strtotime($d)), array_keys($trendData))) ?>,
                                    datasets: [{
                                        label: 'Xarajatlar',
                                        data: <?= json_encode(array_values($trendData)) ?>,
                                        backgroundColor: '#e2e8f0',
                                        hoverBackgroundColor: '#ef4444',
                                        borderRadius: 12
                                    }]
                                },
                                options: {
                                    scales: {
                                        y: { display: false },
                                        x: { grid: { display: false }, border: { display: false }, ticks: { font: { weight: '800', size: 9, family: 'Outfit' } } }
                                    },
                                    plugins: { legend: { display: false } },
                                    maintainAspectRatio: false
                                }
                            });
                        });
                    </script>

                    <div class="bg-white rounded-[2.5rem] shadow-sm border border-slate-100 overflow-hidden">
                        <table class="w-full text-left">
                            <thead>
                                <tr class="bg-slate-50/50 border-b border-slate-100 uppercase text-[10px] font-black text-slate-400 tracking-widest">
                                    <th class="p-8">Turi</th>
                                    <th class="p-8">Sana</th>
                                    <th class="p-8">Miqdor</th>
                                    <th class="p-8 text-slate-300">Izoh</th>
                                    <th class="p-8">Filial</th>
                                    <th class="p-8">Mas'ul</th>
                                    <th class="p-8 text-right">Amal</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-50">
                                <?php foreach ($expensesLog as $ex): ?>
                                    <tr class="hover:bg-slate-50/30 transition-colors">
                                        <td class="p-8 font-black text-slate-700 underline decoration-slate-200"><?= $ex['category_name'] ?></td>
                                        <td class="p-8 text-slate-400 font-bold"><?= date('d.m.Y', strtotime($ex['date'])) ?></td>
                                        <td class="p-8 text-rose-600 font-black">-<?= number_format($ex['amount'], 0, '.', ' ') ?> UZS</td>
                                        <td class="p-8 text-slate-500 text-sm max-w-xs truncate italic font-medium">"<?= $ex['description'] ?>"</td>
                                        <td class="p-8 text-slate-500 font-bold"><?= $ex['branch_name'] ?? '---' ?></td>
                                        <td class="p-8 text-slate-500 font-bold"><?= $ex['creator_name'] ?></td>
                                        <td class="p-8 text-right flex justify-end gap-2">
                                            <?php if(empty($ex['is_payment'])): ?>
                                                <button onclick='openExpenseModal(<?= json_encode($ex) ?>)' class="p-3 bg-blue-50 text-blue-500 rounded-xl hover:bg-blue-100 transition-all">
                                                    <i class="ri-edit-line"></i>
                                                </button>
                                                <a href="?del_expense=<?= $ex['id'] ?>" onclick="return confirm('O\'chirilsinmi?')" class="p-3 bg-rose-50 text-rose-500 rounded-xl hover:bg-rose-100 transition-all">
                                                    <i class="ri-delete-bin-line"></i>
                                                </a>
                                            <?php else: ?>
                                                <span class="text-[8px] font-black text-slate-300 uppercase italic tracking-tighter bg-slate-50 px-3 py-1 rounded-lg">To'lovlar bo'limidan boshqariladi</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($expensesLog)): ?>
                                    <tr><td colspan="7" class="p-20 text-center text-slate-300 font-black uppercase tracking-widest italic">Hozircha xarajatlar mavjud emas</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                <?php elseif ($tab == 'expense_categories' && $isAdmin): ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Xarajat turlari</h2>
                        <button onclick="document.getElementById('expenseCatModal').classList.remove('hidden')"
                            class="bg-blue-600 text-white px-8 py-3.5 rounded-2xl font-black shadow-xl shadow-blue-500/20 transition-all hover:bg-blue-700 active:scale-95 text-sm">
                            + YANGI TUR QO'SHISH
                        </button>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
                        <?php foreach ($expenseCategories as $ec): ?>
                            <div class="bg-white p-8 rounded-[2.5rem] shadow-sm border border-slate-100 flex justify-between items-center group">
                                <span class="text-lg font-black text-slate-800"><?= $ec['name'] ?></span>
                                <a href="?del_expense_cat=<?= $ec['id'] ?>" onclick="return confirm('O\'chirilsinmi?')" class="w-10 h-10 bg-rose-50 text-rose-500 rounded-xl flex items-center justify-center hover:bg-rose-100 opacity-0 group-hover:opacity-100 transition-opacity">
                                    <i class="ri-delete-bin-line"></i>
                                </a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($tab == 'notifications' && $isAdmin): ?>
                    <div class="flex justify-between items-center mb-10">
                        <h2 class="text-3xl font-black text-slate-800">Bildirishnomalar</h2>
                        <a href="?mark_notifs_read=1" class="text-blue-600 font-bold text-sm hover:underline">Hammasini o'qilgan deb belgilash</a>
                    </div>

                    <div class="space-y-4">
                        <?php 
                        $notifsList = $pdo->query("SELECT * FROM notifications ORDER BY created_at DESC LIMIT 100")->fetchAll();
                        foreach ($notifsList as $n): ?>
                            <div class="bg-white p-6 rounded-3xl border <?= $n['is_read'] ? 'border-slate-100' : 'border-amber-200 bg-amber-50/10' ?> shadow-sm flex items-start gap-6">
                                <div class="w-12 h-12 <?= $n['is_read'] ? 'bg-slate-100 text-slate-400' : 'bg-amber-100 text-amber-600' ?> rounded-2xl flex items-center justify-center shrink-0">
                                    <i class="ri-notification-line text-xl"></i>
                                </div>
                                <div class="flex-1">
                                    <div class="flex justify-between items-start mb-1">
                                        <h4 class="font-black text-slate-800"><?= htmlspecialchars($n['title']) ?></h4>
                                        <span class="text-[10px] font-bold text-slate-400"><?= date('H:i (d.m.Y)', strtotime($n['created_at'])) ?></span>
                                    </div>
                                    <p class="text-slate-600 text-sm leading-relaxed"><?= htmlspecialchars($n['message']) ?></p>
                                </div>
                            </div>
                        <?php endforeach; 
                        if (empty($notifsList)): ?>
                            <div class="p-20 text-center text-slate-300 italic font-medium">Bildirishnomalar mavjud emas</div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($tab == 'faceid' && hasPermission($user, 'faceid')): ?>
                    <?php include 'pages_recognizer.php'; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Modals -->
        <div id="branchModal"
            class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-6">
            <div class="bg-white rounded-[3rem] w-full max-w-xl p-12 shadow-2xl relative">
                <button onclick="closeM('branchModal')"
                    class="absolute top-8 right-8 text-slate-400 hover:text-slate-600"><i
                        class="ri-close-line text-3xl"></i></button>
                <h3 id="brTitle" class="text-3xl font-black mb-10 text-slate-800">Filial ma'lumotlari</h3>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="id" id="brId">
                    <div class="space-y-2"><label class="text-xs font-black text-slate-400 uppercase ml-2">Filial
                            nomi</label>
                        <input type="text" name="name" id="brName"
                            class="w-full p-5 bg-slate-50 border-none rounded-3xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                            placeholder="Filial nomi"
                            required>
                    </div>
                    <div class="grid grid-cols-2 gap-6">
                        <div class="space-y-2"><label
                                class="text-xs font-black text-slate-400 uppercase ml-2">Latitude</label><input type="text"
                                name="lat" id="brLat"
                                class="w-full p-5 bg-slate-50 border-none rounded-3xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                                required></div>
                        <div class="space-y-2"><label
                                class="text-xs font-black text-slate-400 uppercase ml-2">Longitude</label><input type="text"
                                name="lon" id="brLon"
                                class="w-full p-5 bg-slate-50 border-none rounded-3xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                                required></div>
                    </div>
                    <div class="space-y-2"><label class="text-xs font-black text-slate-400 uppercase ml-2">Ruxsat etilgan
                            radius (metrda)</label>
                        <input type="number" name="radius" id="brRadius"
                            class="w-full p-5 bg-slate-50 border-none rounded-3xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                            placeholder="200"
                            required>
                    </div>
                    <button type="submit" name="add_branch" id="brAddBtn"
                        class="w-full p-6 bg-blue-600 text-white font-black rounded-3xl shadow-xl shadow-blue-500/20 hover:bg-blue-700 transition-all">FILIALNI
                        SAQLASH</button>
                    <button type="submit" name="edit_branch" id="brEditBtn"
                        class="w-full p-6 bg-emerald-500 text-white font-black rounded-3xl shadow-xl shadow-emerald-500/20 hover:bg-emerald-600 transition-all hidden">O'ZGARTIRISHLARNI
                        SAQLASH</button>
                </form>
            </div>
        </div>

        <div id="empModal"
            class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-6">
            <div class="bg-white rounded-[3rem] w-full max-w-3xl p-12 shadow-2xl relative max-h-[90vh] overflow-y-auto">
                <button onclick="closeM('empModal')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600"><i
                        class="ri-close-line text-3xl"></i></button>
                <h3 id="empTitle" class="text-3xl font-black mb-10 text-slate-800">Xodim ma'lumotlari</h3>
                <form id="empForm" method="POST" enctype="multipart/form-data" class="grid grid-cols-2 gap-6">
                    <input type="hidden" name="id" id="emp_id">
                    <div class="col-span-2 space-y-2"><label class="text-xs font-black text-slate-400 uppercase ml-2">To'liq
                            F.I.SH</label>
                        <input type="text" name="name" id="emp_name"
                            class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                            placeholder="Xodim F.I.SH"
                            required>
                    </div>
                    <div class="space-y-2"><label
                            class="text-xs font-black text-slate-400 uppercase ml-2">Email</label><input type="email"
                            name="email" id="emp_email"
                            class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                            required></div>
                    <div class="space-y-2"><label
                            class="text-xs font-black text-slate-400 uppercase ml-2">Telefon</label><input type="text"
                            name="phone" id="emp_phone"
                            class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                            required></div>
                    <div class="space-y-2"><label
                            class="text-xs font-black text-slate-400 uppercase ml-2">Lavozim</label><input type="text"
                            name="pos" id="emp_pos"
                            class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                            required></div>                    <div class="space-y-2"><label class="text-xs font-black text-slate-400 uppercase ml-2">Oylik maosh (UZS)</label><input type="number" name="salary" id="emp_salary" oninput="calculatePreview()"
                             class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                             placeholder="5000000"
                             required></div>
                    <div class="space-y-2"><label class="text-xs font-black text-slate-400 uppercase ml-2">Dam olish turi</label>
                        <select name="off_day_type" id="emp_off_day_type" onchange="toggleOffDaysInput(); calculatePreview()"
                            class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner">
                            <option value="custom">Kunbay (tanlangan miqdorda)</option>
                            <option value="sunday">Har haftaning Yakshanba kuni</option>
                        </select>
                    </div>
                    <div class="space-y-2" id="offDaysContainer">
                        <label class="text-xs font-black text-slate-400 uppercase ml-2">Dam olish kunlari (oyiga)</label>
                        <input type="number" name="off_days" id="emp_off_days" value="4" oninput="calculatePreview()"
                             class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                             required>
                    </div>
                    <div class="grid grid-cols-2 gap-6 col-span-2">
                        <div class="flex-1 space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Asosiy Rol</label>
                            <select name="role" id="emp_role" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm" required>
                                <option value="employee">Xodim</option>
                                <option value="manager">Menejer</option>
                                <option value="admin">Admin</option>
                                <option value="superadmin">Superadmin</option>
                            </select>
                        </div>
                        <div class="flex-1 space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Maxsus Rol</label>
                            <select name="role_id" id="emp_role_id" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner text-sm">
                                <option value="">Standart</option>
                                <?php foreach ($rolesList as $r): ?>
                                    <option value="<?= $r['id'] ?>"><?= $r['name'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="space-y-2"><label class="text-xs font-black text-slate-400 uppercase ml-2">Ish boshlanish vaqti</label>
                        <input type="time" name="start_time" id="emp_start" value="09:00" oninput="calculatePreview()"
                            class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                            required>
                    </div>
                    <div class="space-y-2"><label class="text-xs font-black text-slate-400 uppercase ml-2">Ish tugash vaqti</label>
                        <input type="time" name="end_time" id="emp_end" value="18:00" oninput="calculatePreview()"
                            class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                            required>
                    </div>
                    <div class="space-y-2"><label class="text-xs font-black text-slate-400 uppercase ml-2">Joriy Balans (UZS)</label>
                        <input type="number" name="balance" id="emp_balance" value="0"
                            class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"
                            required>
                    </div>
                    <div class="space-y-2"><label class="text-xs font-black text-slate-400 uppercase ml-2">Biriktirilgan
                            filial</label>
                        <select name="branch_id" id="emp_branch"
                            class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner">
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= $b['name'] ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-span-2 space-y-2"><label class="text-xs font-black text-slate-400 uppercase ml-2">Parol
                            (Faqat o'zgartirish uchun)</label>
                        <input type="text" name="pass" id="emp_pass" value="12345678"
                            class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner">
                    </div>
                    <div class="col-span-2 space-y-4">
                        <label class="text-xs font-black text-slate-400 uppercase ml-2 block">Xodim rasmi (Face ID uchun)</label>
                        <input type="hidden" name="captured_image" id="empCapturedImage">
                        
                        <div class="flex gap-4 p-1 bg-slate-100 rounded-2xl">
                            <button type="button" onclick="setImgMode('file')" id="mode_btn_file" class="flex-1 p-3 rounded-xl font-black text-[10px] tracking-widest transition-all bg-white shadow-sm text-slate-800">FILY YUKLASH</button>
                            <button type="button" onclick="setImgMode('cam')" id="mode_btn_cam" class="flex-1 p-3 rounded-xl font-black text-[10px] tracking-widest transition-all text-slate-400 hover:text-slate-600">KAMERADAN OLISH</button>
                        </div>

                        <!-- Mode: File -->
                        <div id="mode_sec_file" class="block animate-in fade-in slide-in-from-top-2">
                            <input type="file" name="profile_image" accept="image/*"
                                class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner">
                        </div>

                        <!-- Mode: Camera -->
                        <div id="mode_sec_cam" class="hidden animate-in fade-in slide-in-from-top-2 space-y-4">
                            <div class="relative w-full aspect-video bg-black rounded-3xl overflow-hidden shadow-2xl border-4 border-slate-100">
                                <video id="empCamPreview" autoplay playsinline class="w-full h-full object-cover"></video>
                                <canvas id="empCapCanvas" class="hidden"></canvas>
                                <img id="empCapPreview" class="hidden w-full h-full object-cover">
                                
                                <div class="absolute bottom-6 left-0 right-0 flex justify-center gap-4">
                                    <button type="button" id="empSnapBtn" onclick="empCapture()" class="w-16 h-16 bg-white rounded-full flex items-center justify-center text-blue-600 shadow-2xl hover:scale-110 active:scale-95 transition-all">
                                        <i class="ri-camera-fill text-2xl"></i>
                                    </button>
                                    <button type="button" id="empRetakeBtn" onclick="empRetake()" class="hidden px-6 py-3 bg-white/90 backdrop-blur rounded-2xl text-slate-800 font-black text-[10px] shadow-xl hover:bg-white transition-all uppercase tracking-widest">
                                        <i class="ri-restart-line mr-2"></i> Qayta olish
                                    </button>
                                </div>
                            </div>
                            <p class="text-[10px] text-slate-400 text-center font-bold italic tracking-wide">* Yuz markazda va tiniq ko'rining</p>
                        </div>
                    </div>

                    <div id="salarySummary" class="col-span-2 hidden bg-emerald-50 p-6 rounded-3xl border border-emerald-100 mt-4">
                        <h4 class="text-emerald-800 font-black text-sm mb-3 uppercase tracking-widest">Hisoblangan summary</h4>
                        <div class="space-y-2 text-sm">
                            <div class="flex justify-between text-emerald-600"><span>Oy kunlari:</span> <span id="sumMonthDays" class="font-black">0</span></div>
                            <div class="flex justify-between text-emerald-600"><span>Ish kunlari:</span> <span id="sumWorkDays" class="font-black">0</span></div>
                            <div class="flex justify-between text-emerald-700 pt-2 border-t border-emerald-200 mt-2 font-black text-lg">
                                <span>Soatlik maosh:</span> <span id="sumHourlyRate">0 UZS</span>
                            </div>
                        </div>
                    </div>

                    <div class="col-span-2 pt-6">
                        <button type="submit" name="add_employee" id="empAddBtn"
                            class="w-full p-5 bg-blue-600 text-white font-black rounded-[2rem] shadow-xl shadow-blue-500/20 hover:bg-blue-700">XODIMNI
                            SAQLASH</button>
                        <button type="submit" name="edit_employee" id="empEditBtn"
                            class="w-full p-5 bg-emerald-500 text-white font-black rounded-[2rem] shadow-xl shadow-emerald-500/20 hover:bg-emerald-600 hidden">O'ZGARISHNI
                            SAQLASH</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Fine Type Modal -->
        <div id="fineTypeModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-6">
            <div class="bg-white rounded-[3rem] w-full max-w-xl p-12 shadow-2xl relative">
                <button onclick="closeM('fineTypeModal')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600">
                    <i class="ri-close-line text-3xl"></i>
                </button>
                <h3 id="ftTitle" class="text-3xl font-black mb-10 text-slate-800">Jarima turi</h3>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="id" id="ftId">
                    <div class="space-y-2">
                        <label class="text-xs font-black text-slate-400 uppercase ml-2">Jarima nomi</label>
                        <input type="text" name="name" id="ftName" class="w-full p-5 bg-slate-50 border-none rounded-3xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" placeholder="Masalan: Kechikish (10-30 daqiqa)" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-black text-slate-400 uppercase ml-2">Miqdori (UZS)</label>
                        <input type="number" name="amount" id="ftAmount" class="w-full p-5 bg-slate-50 border-none rounded-3xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" placeholder="50000" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-black text-slate-400 uppercase ml-2">Tavsif (Ixtiyoriy)</label>
                        <textarea name="description" id="ftDesc" rows="3" class="w-full p-5 bg-slate-50 border-none rounded-3xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" placeholder="Jarima sababi haqida batafsil..."></textarea>
                    </div>
                    <div class="pt-4">
                        <button type="submit" name="save_fine_type" id="ftAddBtn" class="w-full p-6 bg-blue-600 text-white font-black rounded-3xl shadow-xl shadow-blue-500/20 hover:bg-blue-700 transition-all">SAQLASH</button>
                        <button type="submit" name="edit_fine_type" id="ftEditBtn" class="w-full p-6 bg-emerald-500 text-white font-black rounded-3xl shadow-xl shadow-emerald-500/20 hover:bg-emerald-600 transition-all hidden">O'ZGARTIRISHNI SAQLASH</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- View Employee Modal -->
        <div id="viewEmpModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-6">
            <div class="bg-white rounded-[3rem] w-full max-w-2xl p-10 shadow-2xl relative max-h-[90vh] overflow-y-auto">
                <button onclick="closeM('viewEmpModal')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600"><i class="ri-close-line text-3xl"></i></button>
                
                <div class="flex items-center gap-6 mb-10">
                    <div class="w-20 h-20 bg-blue-50 text-blue-600 rounded-[2rem] flex items-center justify-center text-3xl shadow-inner shadow-blue-100">
                        <i class="ri-user-fill"></i>
                    </div>
                    <div>
                        <h3 id="view_name" class="text-3xl font-black text-slate-800">Xodim Ismi</h3>
                        <p id="view_pos" class="text-slate-400 font-bold uppercase tracking-widest text-sm">Lavozim</p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-8 mb-10">
                    <div class="space-y-1">
                        <p class="text-[10px] font-black text-slate-400 uppercase">Email</p>
                        <p id="view_email" class="font-bold text-slate-700">email@example.com</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-black text-slate-400 uppercase">Telefon</p>
                        <p id="view_phone" class="font-bold text-slate-700">+998 00 000 00 00</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-black text-slate-400 uppercase">Filial</p>
                        <p id="view_branch" class="font-bold text-slate-700">Filial nomi</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-black text-slate-400 uppercase">Ish vaqti</p>
                        <p id="view_worktime" class="font-bold text-blue-600">09:00 - 18:00</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-black text-slate-400 uppercase">Oylik maosh</p>
                        <p id="view_salary" class="font-black text-emerald-600">0 UZS</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-black text-slate-400 uppercase">Soatbay stavka</p>
                        <p id="view_hourly" class="font-black text-indigo-600">0 UZS</p>
                    </div>
                    <div class="space-y-1">
                        <p class="text-[10px] font-black text-slate-400 uppercase">Joriy Balans</p>
                        <p id="view_balance" class="font-black text-blue-600 text-lg">0 UZS</p>
                    </div>
                </div>

                <div class="mb-10 bg-blue-50 p-6 rounded-[2rem] border border-blue-100 flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-12 h-12 bg-blue-600 rounded-2xl flex items-center justify-center text-white text-xl">
                            <i class="ri-notification-3-line"></i>
                        </div>
                        <div>
                            <p class="text-sm font-black text-blue-900">Push xabarnoma</p>
                            <p class="text-xs text-blue-600 font-bold">Qurilmani tekshirish</p>
                        </div>
                    </div>
                    <form method="POST" class="m-0">
                        <input type="hidden" name="employee_id" id="push_emp_id">
                        <button type="submit" name="send_test_push" class="px-6 py-3 bg-blue-600 text-white font-black rounded-2xl text-xs hover:bg-blue-700 active:scale-95 transition-all shadow-lg shadow-blue-500/20">TEST YUBORISH</button>
                    </form>
                </div>

                <div class="border-t pt-8">
                    <h4 class="text-xs font-black text-slate-400 uppercase mb-4 tracking-widest">Oxirgi 10 kunlik harakat</h4>
                    <div id="view_snippets" class="space-y-3">
                        <!-- Snippets will be injected here -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Assign Fine Modal -->
        <div id="assignFineModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[110] flex items-center justify-center p-6">
            <div class="bg-white rounded-[3rem] w-full max-w-xl p-12 shadow-2xl relative max-h-[90vh] overflow-y-auto">
                <button onclick="closeM('assignFineModal')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600">
                    <i class="ri-close-line text-3xl"></i>
                </button>
                <h3 class="text-3xl font-black mb-10 text-slate-800">Jarima qo'shish</h3>
                <form method="POST" class="space-y-6">
                    <div class="space-y-2">
                        <label class="text-xs font-black text-slate-400 uppercase ml-2">Xodimni tanlang</label>
                        <select name="employee_id" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                            <option value="">Tanlang...</option>
                            <?php foreach ($employees as $e): ?>
                                <option value="<?= $e['id'] ?>"><?= $e['full_name'] ?> (<?= $e['branch_name'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-black text-slate-400 uppercase ml-2">Jarima turi (Tayyor turlar)</label>
                        <select id="fine_type_select_assign" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" onchange="updateFineAmountAssign(this)">
                            <option value="">Maxsus miqdor...</option>
                            <?php foreach ($fineTypes as $ft): ?>
                                <option value="<?= $ft['amount'] ?>" data-name="<?= $ft['name'] ?>"><?= $ft['name'] ?> (<?= number_format($ft['amount']) ?> UZS)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-black text-slate-400 uppercase ml-2">Miqdori (UZS)</label>
                        <input type="number" name="amount" id="fine_amount_input_assign" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" placeholder="Masalan: 50000" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-xs font-black text-slate-400 uppercase ml-2">Sabab</label>
                        <textarea name="reason" id="fine_reason_input_assign" rows="3" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" placeholder="Jarima sababini batafsil yozing..." required></textarea>
                    </div>
                    <div class="pt-4">
                        <button type="submit" name="add_manual_fine" class="w-full p-6 bg-blue-600 text-white font-black rounded-3xl shadow-xl shadow-blue-500/20 hover:bg-blue-700 transition-all">JARIMANI TASDIQLASH</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Image Viewer Modal -->
        <div id="imgModal" class="hidden fixed inset-0 bg-slate-900/90 backdrop-blur-xl z-[9999] flex items-center justify-center p-4 sm:p-10"
             onclick="this.classList.add('hidden')">
            <div class="relative max-w-4xl w-full h-full flex flex-col items-center justify-center" onclick="event.stopPropagation()">
                <button onclick="document.getElementById('imgModal').classList.add('hidden')"
                    class="absolute top-0 right-0 sm:-top-12 sm:-right-12 text-white/70 hover:text-white text-5xl font-thin transition-all">&times;</button>
                <img id="imgModalSrc" src="" class="max-w-full max-h-[85vh] rounded-2xl shadow-2xl border-4 border-white/10 object-contain bg-slate-800">
                <p class="text-center text-white/50 text-xs mt-6 font-black uppercase tracking-widest animate-pulse">Yopish uchun bosing</p>
            </div>
        </div>

        <script>
            let empStream = null;
            function setImgMode(m) {
                const bf = document.getElementById('mode_btn_file');
                const bc = document.getElementById('mode_btn_cam');
                const sf = document.getElementById('mode_sec_file');
                const sc = document.getElementById('mode_sec_cam');

                if (m === 'cam') {
                    bf.className = 'flex-1 p-3 rounded-xl font-black text-[10px] tracking-widest transition-all text-slate-400 hover:text-slate-600';
                    bc.className = 'flex-1 p-3 rounded-xl font-black text-[10px] tracking-widest transition-all bg-white shadow-sm text-slate-800';
                    sf.classList.add('hidden');
                    sc.classList.remove('hidden');
                    startEmpCam();
                } else {
                    bc.className = 'flex-1 p-3 rounded-xl font-black text-[10px] tracking-widest transition-all text-slate-400 hover:text-slate-600';
                    bf.className = 'flex-1 p-3 rounded-xl font-black text-[10px] tracking-widest transition-all bg-white shadow-sm text-slate-800';
                    sc.classList.add('hidden');
                    sf.classList.remove('hidden');
                    stopEmpCam();
                }
            }

            async function startEmpCam() {
                try {
                    empStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' } });
                    document.getElementById('empCamPreview').srcObject = empStream;
                } catch (e) {
                    alert("Kameraga ruxsat berilmadi: " + e.message);
                    setImgMode('file');
                }
            }
            function stopEmpCam() {
                if (empStream) {
                    empStream.getTracks().forEach(t => t.stop());
                    empStream = null;
                }
            }

            function empCapture() {
                const v = document.getElementById('empCamPreview');
                const c = document.getElementById('empCapCanvas');
                const p = document.getElementById('empCapPreview');
                const input = document.getElementById('empCapturedImage');
                
                c.width = v.videoWidth;
                c.height = v.videoHeight;
                c.getContext('2d').drawImage(v, 0, 0);
                const data = c.toDataURL('image/jpeg', 0.8);
                
                input.value = data;
                v.classList.add('hidden');
                p.src = data;
                p.classList.remove('hidden');
                document.getElementById('empSnapBtn').classList.add('hidden');
                document.getElementById('empRetakeBtn').classList.remove('hidden');
            }

            function empRetake() {
                document.getElementById('empCapturedImage').value = '';
                document.getElementById('empCamPreview').classList.remove('hidden');
                document.getElementById('empCapPreview').classList.add('hidden');
                document.getElementById('empSnapBtn').classList.remove('hidden');
                document.getElementById('empRetakeBtn').classList.add('hidden');
            }

            // Patch closeM and openEmpModal
            const originalCloseM = closeM;
            window.closeM = function(id) {
                if (id === 'empModal') stopEmpCam();
                originalCloseM(id);
            }
        </script>
        
        <script>
            function closeM(id) { document.getElementById(id).classList.add('hidden'); }
            function openM(id) {
                const m = document.getElementById(id);
                if (m) {
                    m.classList.remove('hidden');
                }
            }

            function updateFineAmountAssign(select) {
                if (select.value) {
                    document.getElementById('fine_amount_input_assign').value = select.value;
                    const selectedOption = select.options[select.selectedIndex];
                    document.getElementById('fine_reason_input_assign').value = selectedOption.getAttribute('data-name');
                }
            }
            function openFineModal() {
                openM('assignFineModal');
            }

            function openFineTypeModal(data = null) {
                const title = document.getElementById('ftTitle');
                const id = document.getElementById('ftId');
                const name = document.getElementById('ftName');
                const amount = document.getElementById('ftAmount');
                const desc = document.getElementById('ftDesc');
                const addBtn = document.getElementById('ftAddBtn');
                const editBtn = document.getElementById('ftEditBtn');

                if (data) {
                    title.innerText = "Jarima turini tahrirlash";
                    id.value = data.id;
                    name.value = data.name;
                    amount.value = data.amount;
                    desc.value = data.description || '';
                    addBtn.classList.add('hidden');
                    editBtn.classList.remove('hidden');
                } else {
                    title.innerText = "Yangi jarima turi";
                    id.value = "";
                    name.value = "";
                    amount.value = "";
                    desc.value = "";
                    addBtn.classList.remove('hidden');
                    editBtn.classList.add('hidden');
                }
                openM('fineTypeModal');
            }

            function openBranchModal(data = null) {
                document.getElementById('branchModal').classList.remove('hidden');
                if (data) {
                    document.getElementById('brId').value = data.id;
                    document.getElementById('brName').value = data.name;
                    document.getElementById('brLat').value = data.latitude;
                    document.getElementById('brLon').value = data.longitude;
                    document.getElementById('brRadius').value = data.radius;
                    document.getElementById('brAddBtn').classList.add('hidden');
                    document.getElementById('brEditBtn').classList.remove('hidden');
                    document.getElementById('brTitle').innerText = "Filialni tahrirlash";
                } else {
                    document.getElementById('brId').value = "";
                    document.getElementById('brAddBtn').classList.remove('hidden');
                    document.getElementById('brEditBtn').classList.add('hidden');
                    document.getElementById('brTitle').innerText = "Yangi filial qo'shish";
                }
            }
        
        function viewEmp(data, snippets) {
            document.getElementById('view_name').innerText = data.full_name;
            document.getElementById('view_pos').innerText = data.position;
            document.getElementById('view_email').innerText = data.email;
            document.getElementById('view_phone').innerText = data.phone;
            document.getElementById('view_branch').innerText = data.branch_name;
            document.getElementById('view_salary').innerText = parseInt(data.monthly_salary).toLocaleString() + " UZS";
            document.getElementById('view_hourly').innerText = Math.round(data.hourly_rate).toLocaleString() + " UZS";
            document.getElementById('view_worktime').innerText = data.work_start_time.substring(0,5) + " - " + data.work_end_time.substring(0,5);
            document.getElementById('view_balance').innerText = (parseInt(data.balance) || 0).toLocaleString() + " UZS";
            document.getElementById('push_emp_id').value = data.id;
            
            let snipHtml = '';
            if(snippets && snippets.length > 0) {
                snippets.forEach(s => {
                    const date = s.in_time.split(' ')[0];
                    const inTime = s.in_time.split(' ')[1].substring(0,5);
                    const outTime = s.out_time ? s.out_time.split(' ')[1].substring(0,5) : '...';
                    
                    snipHtml += `
                        <div class="flex justify-between items-center p-4 bg-slate-50 rounded-2xl border border-slate-100 group/snip">
                            <div class="flex-1">
                                <span class="font-black text-slate-400 text-[10px] uppercase block tracking-widest mb-1">${date}</span>
                                <div class="flex items-center gap-4">
                                    <div class="flex items-center gap-1.5 cursor-pointer hover:text-blue-600 transition-colors" onclick="openEditModal('${s.in_id}', '${inTime}', '${date}', 'KIRISH')">
                                        <span class="text-slate-700 font-bold">${inTime}</span>
                                        <i class="ri-edit-line text-[10px] opacity-0 group-hover/snip:opacity-100"></i>
                                    </div>
                                    <span class="text-slate-300">/</span>
                                    <div class="flex items-center gap-1.5 cursor-pointer hover:text-blue-600 transition-colors" onclick="openEditModal('${s.out_id || ''}', '${outTime}', '${date}', 'CHIQISH', '${data.id}', '${s.in_id}')">
                                        <span class="${s.out_time ? 'text-slate-700 font-bold' : 'text-slate-400 italic font-medium'}">${outTime}</span>
                                        <i class="ri-edit-line text-[10px] opacity-0 group-hover/snip:opacity-100"></i>
                                    </div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="px-3 py-1 bg-white rounded-lg border border-slate-200 text-[10px] font-black text-slate-400">FAOL</span>
                            </div>
                        </div>
                    `;
                });
            } else {
                snipHtml = '<p class="text-slate-400 text-sm italic text-center py-4">Harakatlar topilmadi</p>';
            }
            document.getElementById('view_snippets').innerHTML = snipHtml;
            document.getElementById('viewEmpModal').classList.remove('hidden');
        }

        function openEmpModal(data = null) {
            const f = document.getElementById('empForm');
            f.reset();
            document.getElementById('emp_id').value = '';
            document.getElementById('empTitle').innerText = "Yangi xodim qo'shish";
            if(data) {
                document.getElementById('emp_id').value = data.id;
                document.getElementById('emp_name').value = data.full_name;
                document.getElementById('emp_phone').value = data.phone;
                document.getElementById('emp_email').value = data.email;
                document.getElementById('emp_role').value = data.role;
                document.getElementById('emp_role_id').value = data.role_id || '';
                document.getElementById('emp_branch').value = data.branch_id;
                document.getElementById('emp_pos').value = data.position;
                document.getElementById('emp_salary').value = data.monthly_salary;
                document.getElementById('emp_start').value = data.work_start_time.substring(0,5);
                document.getElementById('emp_end').value = data.work_end_time.substring(0,5);
                document.getElementById('emp_balance').value = data.balance || 0;
                document.getElementById('emp_pass').required = false; 
                document.getElementById('empTitle').innerText = "Xodimni tahrirlash";
                document.getElementById('empAddBtn').classList.add('hidden');
                document.getElementById('empEditBtn').classList.remove('hidden');
                
                document.getElementById('emp_off_day_type').value = data.off_day_type || 'custom';
                document.getElementById('emp_off_days').value = data.off_days_per_month || 4;
                toggleOffDaysInput();
                calculatePreview();
            } else {
                document.getElementById('emp_pass').required = true;
                document.getElementById('empAddBtn').classList.remove('hidden');
                document.getElementById('empEditBtn').classList.add('hidden');
                document.getElementById('salarySummary').classList.add('hidden');
            }
            document.getElementById('empModal').classList.remove('hidden');
        }
        function openRoleModal(data = null) {
             const f = document.getElementById('roleForm');
             f.reset();
             document.getElementById('role_id').value = '';
             document.getElementById('role_title').innerText = "Yangi rol qo'shish";
             if(data) {
                 document.getElementById('role_id').value = data.id;
                 document.getElementById('role_name').value = data.name;
                 const perms = JSON.parse(data.permissions || '[]');
                 document.querySelectorAll('input[name="permissions[]"]').forEach(cb => {
                     cb.checked = perms.includes(cb.value);
                 });
                 document.getElementById('role_title').innerText = "Rolni tahrirlash";
             }
             document.getElementById('roleModal').classList.remove('hidden');
        }
            function countSundaysInCurrentMonth() {
                const now = new Date();
                const month = now.getMonth();
                const year = now.getFullYear();
                let count = 0;
                let date = new Date(year, month, 1);
                while (date.getMonth() === month) {
                    if (date.getDay() === 0) count++;
                    date.setDate(date.getDate() + 1);
                }
                return count;
            }

            function toggleOffDaysInput() {
                const type = document.getElementById('emp_off_day_type').value;
                const container = document.getElementById('offDaysContainer');
                if (type === 'sunday') {
                    container.classList.add('opacity-50', 'pointer-events-none');
                    document.getElementById('emp_off_days').value = countSundaysInCurrentMonth();
                } else {
                    container.classList.remove('opacity-50', 'pointer-events-none');
                }
            }

            function calculatePreview() {
                const name = document.getElementById('emp_name').value;
                const phone = document.getElementById('emp_phone').value;
                const email = document.getElementById('emp_email').value;
                const branch = document.getElementById('emp_branch').value;
                const salary = parseFloat(document.getElementById('emp_salary').value) || 0;
                const offType = document.getElementById('emp_off_day_type').value;
                
                if (offType === 'sunday') {
                    document.getElementById('emp_off_days').value = countSundaysInCurrentMonth();
                }
                
                const offDays = parseInt(document.getElementById('emp_off_days').value) || 0;
                const start = document.getElementById('emp_start').value;
                const end = document.getElementById('emp_end').value;

                if (salary > 0 && offDays >= 0 && name && phone && email && branch) {
                    const daysInMonth = 30; // Standard 30 day month for fixed settings
                    const workDays = daysInMonth - offDays;
                    
                    if (workDays <= 0) {
                        document.getElementById('salarySummary').classList.add('hidden');
                        return;
                    }

                    const [h1, m1] = start.split(':').map(Number);
                    const [h2, m2] = end.split(':').map(Number);
                    if (!isNaN(h1) && !isNaN(h2)) {
                        let dailyHours = (h2 + m2/60) - (h1 + m1/60);
                        if (dailyHours <= 0) dailyHours += 24; // Handle night shifts
                        
                        if (dailyHours > 0) {
                            const hourlyRate = salary / (workDays * dailyHours);
                            document.getElementById('sumMonthDays').innerText = "30 kun";
                            document.getElementById('sumWorkDays').innerText = workDays + " kun";
                            document.getElementById('sumHourlyRate').innerText = Math.round(hourlyRate).toLocaleString() + " UZS";
                            document.getElementById('salarySummary').classList.remove('hidden');
                            return;
                        }
                    }
                }
                document.getElementById('salarySummary').classList.add('hidden');
            }
        </script>

        <script>
            // Switch save_role to edit_role if ID is present
            if (document.getElementById('roleForm')) {
                document.getElementById('roleForm').onsubmit = function() {
                    if (document.getElementById('role_id').value) {
                         const btn = document.getElementById('role_save_btn');
                         btn.name = 'edit_role';
                    }
                };
            }
        <!-- Modals Section -->
        <?php if($user['role'] == 'superadmin'): ?>
        <div id="roleModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-md z-50 flex items-center justify-center p-6">
            <div class="bg-white rounded-[3rem] w-full max-w-xl shadow-2xl relative overflow-hidden">
                <div class="p-8 border-b flex justify-between items-center bg-slate-50/50">
                    <h3 id="role_title" class="text-xl font-black text-slate-800">Yangi rol qo'shish</h3>
                    <button onclick="document.getElementById('roleModal').classList.add('hidden')" class="text-slate-400 hover:text-rose-500 transition-colors">
                        <i class="ri-close-line text-2xl"></i>
                    </button>
                </div>
                <form id="roleForm" method="POST" class="p-8 space-y-6">
                    <input type="hidden" name="id" id="role_id">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Rol nomi</label>
                        <input type="text" name="name" id="role_name" placeholder="Masalan: Sotuvchi" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                    </div>
                    <div class="space-y-4">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Imkoniyatlar</label>
                        <div class="grid grid-cols-2 gap-4">
                            <?php 
                            $perms = [
                                ['id' => 'reporting', 'label' => 'Hisobotlar'],
                                ['id' => 'absences', 'label' => 'Uzoqlashishlar'],
                                ['id' => 'employees', 'label' => 'Xodimlar'],
                                ['id' => 'branches', 'label' => 'Filiallar'],
                                ['id' => 'payments', 'label' => 'To\'lovlar'],
                                ['id' => 'offdays', 'label' => 'Dam olish'],
                                ['id' => 'fines', 'label' => 'Jarimalar'],
                                ['id' => 'fine_types', 'label' => 'Jarima turlari'],
                                ['id' => 'expenses', 'label' => 'Xarajatlar']
                            ];
                            foreach($perms as $p): ?>
                                <label class="flex items-center p-3 bg-slate-50 rounded-xl cursor-pointer hover:bg-slate-100 transition-colors">
                                    <input type="checkbox" name="permissions[]" value="<?= $p['id'] ?>" class="w-4 h-4 text-blue-600 rounded">
                                    <span class="ml-3 text-sm font-bold text-slate-700"><?= $p['label'] ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="flex gap-4 pt-4">
                        <button type="submit" name="save_role" id="role_save_btn" class="flex-1 bg-blue-600 text-white p-5 rounded-[2rem] font-black shadow-xl shadow-blue-500/20 hover:bg-blue-700 active:scale-95 transition-all">SAQLASH</button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php 
        // Calculate off-day quota for current user
        $daysInMonth_f = date('t');
        $workDaysN_f = ($user['work_days_per_month'] ?? 26) ?: 26;
        $allowedOffDays_f = $daysInMonth_f - $workDaysN_f;
        $stmt_t_f = $pdo->prepare("SELECT COUNT(*) FROM off_day_requests WHERE employee_id = ? AND status = 'approved' AND MONTH(request_date) = MONTH(CURRENT_DATE) AND YEAR(request_date) = YEAR(CURRENT_DATE)");
        $stmt_t_f->execute([$user['id'] ?? 0]);
        $takenOffDays_f = $stmt_t_f->fetchColumn() ?: 0;
        $remainingOffDays_f = max(0, $allowedOffDays_f - $takenOffDays_f);
        ?>

        <!-- Request Offday Modal -->
        <div id="requestOffdayModal" class="hidden fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[100] flex items-center justify-center p-4">
            <div class="bg-white rounded-[3rem] w-full max-w-lg shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
                <div class="p-8 border-b flex justify-between items-center bg-slate-50/50">
                    <h3 class="text-xl font-black text-slate-800">Dam olish so'rovi</h3>
                    <button onclick="document.getElementById('requestOffdayModal').classList.add('hidden')" class="text-slate-400 hover:text-rose-500 transition-colors">
                        <i class="ri-close-line text-2xl"></i>
                    </button>
                </div>
                <form method="POST" class="p-8 space-y-6">
                    <div class="p-5 rounded-[2rem] border block mb-8 <?php echo ($remainingOffDays_f > 0) ? "bg-blue-50 border-blue-200" : "bg-rose-50 border-rose-200 shadow-sm"; ?>">
                        <?php if ($remainingOffDays_f > 0): ?>
                            <div class="flex gap-4">
                                <div class="w-10 h-10 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 shrink-0">
                                    <i class="ri-information-line text-xl"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-black text-blue-900 uppercase tracking-widest mb-1">Mavjud limit</p>
                                    <p class="text-sm text-blue-700 font-bold leading-relaxed">
                                        Siz ushbu oyda yana <span class="text-blue-900 font-black px-2 py-0.5 bg-blue-100 rounded-lg"><?= $remainingOffDays_f ?> kun</span> bepul dam olishingiz mumkin.
                                    </p>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="flex gap-4">
                                <div class="w-10 h-10 bg-rose-100 rounded-full flex items-center justify-center text-rose-600 shrink-0">
                                    <i class="ri-error-warning-line text-xl"></i>
                                </div>
                                <div>
                                    <p class="text-xs font-black text-rose-900 uppercase tracking-widest mb-1">Limit tugagan</p>
                                    <p class="text-sm text-rose-700 font-bold leading-relaxed">
                                        Sizning bepul limitiz tugagan. Yangi so'rovlar <span class="text-rose-900 font-black">o'z hisobingizdan</span> bo'ladi va oylikdan chegiriladi.
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Sana</label>
                        <input type="date" name="request_date" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Sabab (Izoh)</label>
                        <textarea name="reason" rows="4" placeholder="Masalan: Uyda to'y, shifoxonaga borishim kerak..." class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required></textarea>
                    </div>
                    <div class="flex gap-4 pt-4">
                        <button type="submit" name="submit_offday" class="flex-1 bg-blue-600 text-white p-5 rounded-[2rem] font-black shadow-xl shadow-blue-500/20 hover:bg-blue-700 active:scale-95 transition-all">SO'ROVNI YUBORISH</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            // Switch save_role to edit_role if ID is present
            if (document.getElementById('roleForm')) {
                document.getElementById('roleForm').onsubmit = function() {
                    if (document.getElementById('role_id').value) {
                         const btn = document.getElementById('role_save_btn');
                         btn.name = 'edit_role';
                    }
                };
            }
        </script>
        <!-- Expense Modal -->
        <div id="expenseModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[100] flex items-center justify-center p-6">
            <div class="bg-white rounded-[3.5rem] w-full max-w-xl p-10 shadow-2xl relative">
                <button onclick="closeM('expenseModal')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600">
                    <i class="ri-close-line text-3xl"></i>
                </button>
                <h3 id="expense_modal_title" class="text-3xl font-black mb-10 text-slate-800">Xarajatni qayd etish</h3>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="id" id="expense_id">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 tracking-widest">Xarajat turi</label>
                        <select name="category_id" id="expense_cat_id" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                            <?php foreach ($expenseCategories as $ec): ?>
                                <option value="<?= $ec['id'] ?>"><?= $ec['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 tracking-widest">Sana</label>
                            <input type="date" name="date" id="expense_date" value="<?= date('Y-m-d') ?>" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2 tracking-widest">Miqdor (so'm)</label>
                            <input type="number" name="amount" id="expense_amount" placeholder="50000" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 tracking-widest">Filial</label>
                        <select name="branch_id" id="expense_branch_id" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner">
                            <option value="">Umumiy xarajat</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= $b['id'] ?>"><?= $b['name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 tracking-widest">Izoh</label>
                        <textarea name="description" id="expense_desc" rows="3" placeholder="Xarajat haqida batafsil..." class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"></textarea>
                    </div>
                    <div class="pt-4">
                        <button type="submit" name="save_expense" id="expense_submit_btn" class="w-full p-5 bg-blue-600 text-white font-black rounded-3xl shadow-xl shadow-blue-500/20 hover:bg-blue-700 transition-all">SAQLASH</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Expense Category Modal -->
        <div id="expenseCatModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[100] flex items-center justify-center p-6">
            <div class="bg-white rounded-[3.5rem] w-full max-w-lg p-12 shadow-2xl relative">
                <button onclick="closeM('expenseCatModal')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600">
                    <i class="ri-close-line text-3xl"></i>
                </button>
                <h3 class="text-3xl font-black mb-10 text-slate-800">Yangi xarajat turi</h3>
                <form method="POST" class="space-y-6">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 tracking-widest">Tur nomi</label>
                        <input type="text" name="name" placeholder="Masalan: Uy arendasi" class="w-full p-5 bg-slate-50 border-none rounded-3xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                    </div>
                    <div class="pt-4">
                        <button type="submit" name="save_expense_category" class="w-full p-6 bg-emerald-500 text-white font-black rounded-3xl shadow-xl shadow-emerald-500/20 hover:bg-emerald-600 transition-all">QO'SHISH</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Active Employees Modal -->
        <div id="activeEmployeesModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[110] flex items-center justify-center p-6">
            <div class="bg-white rounded-[3.5rem] w-full max-w-lg p-10 shadow-2xl relative overflow-hidden">
                <button onclick="closeM('activeEmployeesModal')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600">
                    <i class="ri-close-line text-3xl"></i>
                </button>
                <div class="flex items-center gap-5 mb-8">
                    <div class="w-14 h-14 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center shadow-inner">
                        <i class="ri-team-fill text-2xl"></i>
                    </div>
                    <h3 id="ae_title" class="text-2xl font-black text-slate-800">Filial nomi</h3>
                </div>
                <div id="ae_list" class="max-h-[400px] overflow-y-auto pr-2 custom-scrollbar">
                    <!-- Names will be injected here -->
                </div>
                <div class="mt-8">
                    <button onclick="closeM('activeEmployeesModal')" class="w-full p-5 bg-slate-100 text-slate-500 font-black rounded-3xl hover:bg-slate-200 transition-all text-sm uppercase tracking-widest">Yopish</button>
                </div>
            </div>
        </div>
        <!-- Payment Edit Modal -->
        <div id="paymentEditModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[120] flex items-center justify-center p-6">
            <div class="bg-white rounded-[3.5rem] w-full max-w-lg p-12 shadow-2xl relative">
                <button onclick="closeM('paymentEditModal')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600">
                    <i class="ri-close-line text-3xl"></i>
                </button>
                <div class="flex items-center gap-5 mb-10">
                    <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-3xl flex items-center justify-center shadow-inner">
                        <i class="ri-bank-card-line text-3xl"></i>
                    </div>
                    <div>
                        <h3 class="text-3xl font-black text-slate-800">To'lovni tahrirlash</h3>
                        <p class="text-slate-400 font-bold text-sm">Summani o'zgartirish balansga ta'sir qiladi</p>
                    </div>
                </div>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="payment_id" id="edit_payment_id">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 tracking-widest">Yangi Summa (UZS)</label>
                        <input type="number" name="amount" id="edit_payment_amount" class="w-full p-5 bg-slate-50 border-none rounded-3xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 tracking-widest">Izoh</label>
                        <textarea name="comment" id="edit_payment_comment" rows="3" class="w-full p-5 bg-slate-50 border-none rounded-3xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"></textarea>
                    </div>
                    <div class="pt-4 flex gap-4">
                        <button type="button" onclick="closeM('paymentEditModal')" class="flex-1 p-5 bg-slate-100 text-slate-500 font-black rounded-3xl hover:bg-slate-200 transition-all">BEKOR QILISH</button>
                        <button type="submit" name="edit_payment" class="flex-1 p-5 bg-blue-600 text-white font-black rounded-3xl shadow-xl shadow-blue-500/20 hover:bg-blue-700 transition-all">SAQLASH</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function openPaymentEditModal(id, amount, comment) {
                document.getElementById('paymentEditModal').classList.remove('hidden');
                document.getElementById('edit_payment_id').value = id;
                document.getElementById('edit_payment_amount').value = amount;
                document.getElementById('edit_payment_comment').value = comment;
            }

            function openEditAbsModal(abs) {
                document.getElementById('editAbsModal').classList.remove('hidden');
                document.getElementById('ea_id').value = abs.id;
                document.getElementById('ea_start').value = abs.start_time.replace(' ', 'T').substring(0, 16);
                document.getElementById('ea_end').value = abs.end_time ? abs.end_time.replace(' ', 'T').substring(0, 16) : '';
                document.getElementById('ea_status').value = abs.status;
            }
            function openEditOffModal(off) {
                document.getElementById('editOffModal').classList.remove('hidden');
                document.getElementById('eo_id').value = off.id;
                document.getElementById('eo_date').value = off.request_date;
                document.getElementById('eo_reason').value = off.reason;
                document.getElementById('eo_status').value = off.status;
            }
        </script>
        <!-- Edit Absence Modal -->
        <div id="editAbsModal" class="hidden fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[150] flex items-center justify-center p-4">
            <div class="bg-white rounded-[2.5rem] w-full max-w-lg shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
                <div class="p-8 border-b flex justify-between items-center bg-slate-50/50">
                    <h3 class="text-xl font-black text-slate-800">Uzoqlashishni tahrirlash</h3>
                    <button onclick="document.getElementById('editAbsModal').classList.add('hidden')" class="text-slate-400 hover:text-rose-500 transition-colors">
                        <i class="ri-close-line text-2xl"></i>
                    </button>
                </div>
                <form method="POST" class="p-8 space-y-6">
                    <input type="hidden" name="abs_id" id="ea_id">
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 gap-4">
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Boshlanish vaqti</label>
                                <input type="datetime-local" name="start_time" id="ea_start" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                            </div>
                            <div class="space-y-2">
                                <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Tugash vaqti (Ixtiyoriy)</label>
                                <input type="datetime-local" name="end_time" id="ea_end" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner">
                            </div>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Holati</label>
                            <select name="status" id="ea_status" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner">
                                <option value="pending">Kutilmoqda</option>
                                <option value="approved">Tasdiqlangan</option>
                                <option value="rejected">Rad etilgan</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex gap-4 pt-4">
                        <button type="submit" name="edit_absence" class="flex-1 bg-indigo-600 text-white p-5 rounded-[2rem] font-black shadow-xl shadow-indigo-500/20 hover:bg-indigo-700 active:scale-95 transition-all">SAQLASH</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Edit Offday Modal -->
        <div id="editOffModal" class="hidden fixed inset-0 bg-slate-900/40 backdrop-blur-sm z-[150] flex items-center justify-center p-4">
            <div class="bg-white rounded-[2.5rem] w-full max-w-lg shadow-2xl overflow-hidden animate-in fade-in zoom-in duration-200">
                <div class="p-8 border-b flex justify-between items-center bg-slate-50/50">
                    <h3 class="text-xl font-black text-slate-800">Dam olishni tahrirlash</h3>
                    <button onclick="document.getElementById('editOffModal').classList.add('hidden')" class="text-slate-400 hover:text-rose-500 transition-colors">
                        <i class="ri-close-line text-2xl"></i>
                    </button>
                </div>
                <form method="POST" class="p-8 space-y-6">
                    <input type="hidden" name="offday_id" id="eo_id">
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Sana</label>
                            <input type="date" name="request_date" id="eo_date" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Sabab</label>
                            <textarea name="reason" id="eo_reason" rows="3" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"></textarea>
                        </div>
                        <div class="space-y-2">
                            <label class="text-[10px] font-black text-slate-400 uppercase ml-2">Holati</label>
                            <select name="status" id="eo_status" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner">
                                <option value="pending">Kutilmoqda</option>
                                <option value="approved">Tasdiqlangan</option>
                                <option value="rejected">Rad etilgan</option>
                            </select>
                        </div>
                    </div>
                    <div class="flex gap-4 pt-4">
                        <button type="submit" name="edit_offday" class="flex-1 bg-indigo-600 text-white p-5 rounded-[2rem] font-black shadow-xl shadow-indigo-500/20 hover:bg-indigo-700 active:scale-95 transition-all">SAQLASH</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Task Modal -->
        <div id="taskModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-md z-[200] flex items-center justify-center p-6">
            <div class="bg-white rounded-[3.5rem] w-full max-w-xl p-10 shadow-2xl relative">
                <button onclick="closeM('taskModal')" class="absolute top-8 right-8 text-slate-400 hover:text-slate-600">
                    <i class="ri-close-line text-3xl"></i>
                </button>
                <h3 id="taskModalTitle" class="text-3xl font-black mb-10 text-slate-800">Yangi vazifa</h3>
                <form method="POST" class="space-y-6">
                    <input type="hidden" name="task_id" id="task_id">
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 tracking-widest">Xodim</label>
                        <select name="employee_id" id="task_emp_id" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                            <option value="">Tanlang...</option>
                            <?php foreach ($employees as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= $emp['full_name'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 tracking-widest">Vazifa nomi</label>
                        <input type="text" name="title" id="task_title" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner" required>
                    </div>
                    <div class="space-y-2">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 tracking-widest">Tavsif</label>
                        <textarea name="description" id="task_desc" rows="3" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner"></textarea>
                    </div>
                    <div id="task_status_div" class="space-y-2 hidden">
                        <label class="text-[10px] font-black text-slate-400 uppercase ml-2 tracking-widest">Status</label>
                        <select name="status" id="task_status" class="w-full p-4 bg-slate-50 border-none rounded-2xl outline-none ring-2 ring-transparent focus:ring-blue-500/20 shadow-inner">
                            <option value="pending">Kutilmoqda</option>
                            <option value="in_progress">Jarayonda</option>
                            <option value="completed">Bajarildi</option>
                            <option value="cancelled">Bekor qilindi</option>
                        </select>
                    </div>
                    <div class="pt-4">
                        <button type="submit" name="save_task" id="taskSaveBtn" class="w-full p-5 bg-blue-600 text-white font-black rounded-3xl shadow-xl shadow-blue-500/20 hover:bg-blue-700 transition-all">SAQLASH</button>
                    </div>
                </form>
            </div>
        </div>

        <script>
            function openTaskModal(data = null) {
                const modal = document.getElementById('taskModal');
                const title = document.getElementById('taskModalTitle');
                const btn = document.getElementById('taskSaveBtn');
                const statusDiv = document.getElementById('task_status_div');
                
                if (data) {
                    title.innerText = "Vazifani tahrirlash";
                    btn.name = "edit_task";
                    document.getElementById('task_id').value = data.id;
                    document.getElementById('task_emp_id').value = data.employee_id;
                    document.getElementById('task_title').value = data.title;
                    document.getElementById('task_desc').value = data.description || '';
                    document.getElementById('task_status').value = data.status;
                    statusDiv.classList.remove('hidden');
                } else {
                    title.innerText = "Yangi vazifa";
                    btn.name = "save_task";
                    document.getElementById('task_id').value = "";
                    document.getElementById('task_emp_id').value = "";
                    document.getElementById('task_title').value = "";
                    document.getElementById('task_desc').value = "";
                    statusDiv.classList.add('hidden');
                }
                modal.classList.remove('hidden');
            }

            function openExpenseModal(data = null) {
                const modal = document.getElementById('expenseModal');
                const title = document.getElementById('expense_modal_title');
                const btn = document.getElementById('expense_submit_btn');
                
                if (data) {
                    title.innerText = "Xarajatni tahrirlash";
                    btn.name = "edit_expense";
                    document.getElementById('expense_id').value = data.id;
                    document.getElementById('expense_cat_id').value = data.category_id;
                    document.getElementById('expense_date').value = data.date;
                    document.getElementById('expense_amount').value = data.amount;
                    document.getElementById('expense_branch_id').value = data.branch_id || '';
                    document.getElementById('expense_desc').value = data.description || '';
                } else {
                    title.innerText = "Xarajatni qayd etish";
                    btn.name = "save_expense";
                    document.getElementById('expense_id').value = "";
                    document.getElementById('expense_cat_id').value = "";
                    document.getElementById('expense_date').value = "<?= date('Y-m-d') ?>";
                    document.getElementById('expense_amount').value = "";
                    document.getElementById('expense_branch_id').value = "";
                    document.getElementById('expense_desc').value = "";
                }
                modal.classList.remove('hidden');
            }
        </script>
    </body>
</html>
<?php endif; ?>