<?php
// Bazaga bog'lanish (Ma'lumotlarni o'zingiznikiga almashtiring)
$db_host = 'localhost';
$db_name = 'vitafor2_dastavkabot'; // O'zgartiring
$db_user = 'vitafor2_dastavkabot'; // O'zgartiring
$db_pass = 'AbdumalikDev07$'; // O'zgartiring

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // AUTO-MIGRATION
    try {
        $existing_cols = $pdo->query("SHOW COLUMNS FROM employees")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('work_start_time', $existing_cols)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN work_start_time TIME DEFAULT '09:00:00'");
            $pdo->exec("ALTER TABLE employees ADD COLUMN work_end_time TIME DEFAULT '18:00:00'");
        }
        if (!in_array('monthly_salary', $existing_cols)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN monthly_salary DECIMAL(15, 2) DEFAULT 0");
            $pdo->exec("ALTER TABLE employees ADD COLUMN work_days_per_month INT DEFAULT 26");
        }
        if (!in_array('balance', $existing_cols)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN balance DECIMAL(15, 2) DEFAULT 0");
        }
        if (!in_array('push_token', $existing_cols)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN push_token VARCHAR(255) NULL");
        }
        if (!in_array('is_blocked', $existing_cols)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN is_blocked TINYINT(1) DEFAULT 0");
        }
        if (!in_array('off_day_type', $existing_cols)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN off_day_type ENUM('custom', 'sunday') DEFAULT 'custom'");
        }
        if (!in_array('off_days_per_month', $existing_cols)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN off_days_per_month INT DEFAULT 4");
            $pdo->exec("UPDATE employees SET off_days_per_month = 30 - work_days_per_month WHERE off_days_per_month IS NULL OR off_days_per_month = 4");
        }
        if (!in_array('role_id', $existing_cols)) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL,
                permissions JSON NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");
            $pdo->exec("ALTER TABLE employees ADD COLUMN role_id INT DEFAULT NULL");
            $pdo->exec("ALTER TABLE employees ADD FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL");
        }

        if (!in_array('profile_image', $existing_cols)) {
            $pdo->exec("ALTER TABLE employees ADD COLUMN profile_image VARCHAR(255) NULL");
            // If image_url exists and has data, copy it to profile_image
            if (in_array('image_url', $existing_cols)) {
                $pdo->exec("UPDATE employees SET profile_image = image_url WHERE profile_image IS NULL AND image_url IS NOT NULL AND image_url != ''");
            }
        }

        // Clean up broken/massive base64 images that slow down the API
        $pdo->exec("UPDATE employees SET image_url = NULL WHERE LENGTH(image_url) > 1000");
        $pdo->exec("UPDATE attendance SET image_url = NULL WHERE LENGTH(image_url) > 1000");

        // Add hourly_rate to attendance table for historical accuracy
        $att_cols = $pdo->query("SHOW COLUMNS FROM attendance")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('hourly_rate', $att_cols)) {
            $pdo->exec("ALTER TABLE attendance ADD COLUMN hourly_rate DECIMAL(15, 2) NULL");
            // Fill existing records with current employee rate as a starting point
            $pdo->exec("UPDATE attendance a JOIN employees e ON a.employee_id = e.id SET a.hourly_rate = e.hourly_rate WHERE a.hourly_rate IS NULL");
        }

        // Attendance edits history table
        $pdo->exec("CREATE TABLE IF NOT EXISTS attendance_edits (
            id INT AUTO_INCREMENT PRIMARY KEY,
            attendance_id INT NOT NULL,
            changed_by INT NOT NULL,
            old_timestamp DATETIME NOT NULL,
            new_timestamp DATETIME NOT NULL,
            reason TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (attendance_id) REFERENCES attendance(id) ON DELETE CASCADE,
            FOREIGN KEY (changed_by) REFERENCES employees(id) ON DELETE CASCADE
        )");
        
        // Ensure off_day_requests table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS off_day_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            request_date DATE NOT NULL,
            reason TEXT,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )");

        // Ensure absences table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS absences (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            branch_id INT NOT NULL,
            start_time DATETIME NOT NULL,
            end_time DATETIME NULL,
            duration_minutes INT DEFAULT 0,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )");

        // Ensure fine_types table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS fine_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            amount DECIMAL(15, 2) NOT NULL,
            description TEXT
        )");

        // Ensure fines table exists
        $pdo->exec("CREATE TABLE IF NOT EXISTS fines (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            amount DECIMAL(15, 2) NOT NULL,
            reason TEXT,
            date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )");

        $fine_cols = $pdo->query("SHOW COLUMNS FROM fines")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_read', $fine_cols)) {
            $pdo->exec("ALTER TABLE fines ADD COLUMN is_read TINYINT(1) DEFAULT 0");
        }
        if (!in_array('absence_id', $fine_cols)) {
            $pdo->exec("ALTER TABLE fines ADD COLUMN absence_id INT NULL");
        }

        $abs_cols = $pdo->query("SHOW COLUMNS FROM absences")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_read', $abs_cols)) {
            $pdo->exec("ALTER TABLE absences ADD COLUMN is_read TINYINT(1) DEFAULT 0");
        }

        $off_cols = $pdo->query("SHOW COLUMNS FROM off_day_requests")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_read', $off_cols)) {
            $pdo->exec("ALTER TABLE off_day_requests ADD COLUMN is_read TINYINT(1) DEFAULT 0");
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            amount DECIMAL(15, 2) NOT NULL,
            type ENUM('advance', 'salary', 'bonus') DEFAULT 'advance',
            comment TEXT,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE CASCADE
        )");

        // ---------------- SQL OPTIMIZATIONS (INDEXES) ----------------
        // These speed up queries significantly as the database grows
        try {
            $pdo->exec("CREATE INDEX idx_att_emp_arch ON attendance(employee_id, is_archived)");
            $pdo->exec("CREATE INDEX idx_att_time ON attendance(timestamp)");
            $pdo->exec("CREATE INDEX idx_fines_emp_arch ON fines(employee_id, is_archived)");
            $pdo->exec("CREATE INDEX idx_abs_emp_arch ON absences(employee_id, is_archived)");
            $pdo->exec("CREATE INDEX idx_pay_emp_arch ON payments(employee_id, is_archived)");
            $pdo->exec("CREATE INDEX idx_emp_blocked ON employees(is_blocked)");
        } catch (Exception $e) {
            // Indexes might already exist in newer MySQL versions that don't support IF NOT EXISTS for indexes
        }
        // -----------------------------------------------------------

        // Expense Categories and Expenses
        $pdo->exec("CREATE TABLE IF NOT EXISTS expense_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category_id INT NOT NULL,
            amount DECIMAL(15, 2) NOT NULL,
            description TEXT,
            date DATE NOT NULL,
            branch_id INT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES expense_categories(id) ON DELETE CASCADE,
            FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
            FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE SET NULL
        )");

        // Default expense categories
        $count = $pdo->query("SELECT COUNT(*) FROM expense_categories")->fetchColumn();
        if ($count == 0) {
            $pdo->exec("INSERT INTO expense_categories (name) VALUES ('Arenda'), ('Svet'), ('Ovqat'), ('Boshqa')");
        }

        // Migration for created_by column in expenses
        $expenses_cols = $pdo->query("SHOW COLUMNS FROM expenses")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('created_by', $expenses_cols)) {
            $pdo->exec("ALTER TABLE expenses ADD COLUMN created_by INT DEFAULT NULL");
            $pdo->exec("ALTER TABLE expenses ADD FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE SET NULL");
        }

        // Migration for created_by column in payments (if missing)
        $payments_cols = $pdo->query("SHOW COLUMNS FROM payments")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('created_by', $payments_cols)) {
            $pdo->exec("ALTER TABLE payments ADD COLUMN created_by INT DEFAULT NULL");
            $pdo->exec("ALTER TABLE payments ADD FOREIGN KEY (created_by) REFERENCES employees(id) ON DELETE SET NULL");
        }

        // Migration for attendance table (hourly_rate and is_archived)
        try {
            $att_cols = $pdo->query("SHOW COLUMNS FROM attendance")->fetchAll(PDO::FETCH_COLUMN);
            if (!in_array('hourly_rate', $att_cols)) {
                $pdo->exec("ALTER TABLE attendance ADD COLUMN hourly_rate DECIMAL(15, 2) DEFAULT 0");
            }
            if (!in_array('is_archived', $att_cols)) {
                $pdo->exec("ALTER TABLE attendance ADD COLUMN is_archived TINYINT(1) DEFAULT 0");
            }
        } catch(Exception $e) {}

        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            admin_id INT NOT NULL,
            employee_id INT NOT NULL,
            action_type ENUM('fine', 'absence') NOT NULL,
            target_id INT NOT NULL,
            new_status VARCHAR(20) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (admin_id) REFERENCES employees(id) ON DELETE CASCADE,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS login_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            device_info VARCHAR(255),
            ip_address VARCHAR(45),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS monthly_reports (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            month_year VARCHAR(7) NOT NULL, -- e.g. '2026-03'
            total_hours DECIMAL(10, 2) NOT NULL,
            gross_salary DECIMAL(15, 2) NOT NULL,
            total_fines DECIMAL(15, 2) NOT NULL,
            total_advances DECIMAL(15, 2) NOT NULL,
            net_salary DECIMAL(15, 2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )");

        $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            type VARCHAR(50) DEFAULT 'admin_alert',
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )");

        // Lateness Warnings Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS lateness_warnings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            reason TEXT NOT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )");

        // Tasks Table
        $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
        )");

        $warn_cols = $pdo->query("SHOW COLUMNS FROM lateness_warnings")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('is_read', $warn_cols)) {
            $pdo->exec("ALTER TABLE lateness_warnings ADD COLUMN is_read TINYINT(1) DEFAULT 0");
        }

        // Add off_days column to monthly_reports
        $mr_cols = $pdo->query("SHOW COLUMNS FROM monthly_reports")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('off_days', $mr_cols)) {
            $pdo->exec("ALTER TABLE monthly_reports ADD COLUMN off_days INT DEFAULT 0");
        }

        // Attendance edits history table Review (WorkPay.uz)
        $stmt_check = $pdo->prepare("SELECT id FROM employees WHERE email = ?");
        $stmt_check->execute(['test@gmail.com']);
        if (!$stmt_check->fetch()) {
            $pdo->prepare("INSERT INTO employees (full_name, phone, email, password, role, branch_id, position) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute(['Demo Employee', '+998901234567', 'test@gmail.com', '12345678', 'employee', 1, 'Reviewer']);
        }
        
        $stmt_check_admin = $pdo->prepare("SELECT id FROM employees WHERE email = ?");
        $stmt_check_admin->execute(['admin@gmail.com']);
        if (!$stmt_check_admin->fetch()) {
            $pdo->prepare("INSERT INTO employees (full_name, phone, email, password, role, branch_id, position) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute(['Demo Admin', '+998901112233', 'admin@gmail.com', 'admin123', 'superadmin', 1, 'Administrator']);
        }

        // --- Monthly Reset Archive Column Migrations ---
        $tables_to_archive = ['attendance', 'fines', 'payments', 'absences'];
        foreach ($tables_to_archive as $tbl) {
            try {
                $cols = $pdo->query("SHOW COLUMNS FROM `$tbl`")->fetchAll(PDO::FETCH_COLUMN);
                if (!in_array('is_archived', $cols)) {
                    $pdo->exec("ALTER TABLE `$tbl` ADD COLUMN is_archived TINYINT(1) DEFAULT 0");
                }
            } catch (Exception $ex) { /* Table might not exist yet */ }
        }
    } catch (Exception $e) { /* Ignore */ }

} catch (PDOException $e) {
    die("Baza bilan xatolik: " . $e->getMessage());
}

// Global sozlamalar
date_default_timezone_set('Asia/Tashkent');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit;
}

// Global masofa o'lchash funksiyasi (Haversine)
function getDistance($lat1, $lon1, $lat2, $lon2)
{
    if (!$lat1 || !$lon1 || !$lat2 || !$lon2)
        return 999999;
    $earth_radius = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * asin(sqrt($a));
    return $earth_radius * $c;
}

// Image saving helper
function saveImage($base64Data, $prefix = 'att') {
    if (!$base64Data || strlen($base64Data) < 100) return null;
    if (preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
        $data = substr($base64Data, strpos($base64Data, ',') + 1);
        $type = strtolower($type[1]);
        $data = base64_decode($data);
        if ($data === false) return null;
        
        $dir = 'uploads/';
        if (!is_dir($dir)) mkdir($dir, 0777, true);
        
        $fileName = $dir . $prefix . '_' . time() . '_' . rand(100, 999) . '.' . $type;
        if (file_put_contents($fileName, $data)) {
            return $fileName;
        }
    }
    return null;
}

// Helper to save uploaded files from $_FILES
function saveImageFile($file, $prefix = 'emp') {
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) return null;
    
    $dir = 'uploads/';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $fileName = $dir . $prefix . '_' . time() . '_' . rand(100, 999) . '.' . $ext;
    
    if (move_uploaded_file($file['tmp_name'], $fileName)) {
        return $fileName;
    }
    return null;
}
?>