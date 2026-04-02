-- 1. Filiallar jadvali
CREATE TABLE IF NOT EXISTS branches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    latitude DECIMAL(10, 8) NOT NULL,
    longitude DECIMAL(11, 8) NOT NULL,
    radius INT DEFAULT 200,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 1.1 Rollar jadvali
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    permissions JSON NOT NULL, -- Format: ["reports", "employees", "branches", "absences", "offdays", "payments"]
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Xodimlar jadvali
CREATE TABLE IF NOT EXISTS employees (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL DEFAULT '12345678',
    role ENUM('employee', 'manager', 'admin', 'superadmin') DEFAULT 'employee',
    role_id INT DEFAULT NULL,
    branch_id INT,
    position VARCHAR(100),
    hourly_rate DECIMAL(15, 2) DEFAULT 0,
    monthly_salary DECIMAL(15, 2) DEFAULT 0,
    work_days_per_month INT DEFAULT 26,
    work_start_time TIME DEFAULT '09:00:00',
    work_end_time TIME DEFAULT '18:00:00',
    push_token VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE SET NULL
);

-- 3. Davomat jadvali (Kirish/Chiqish)
CREATE TABLE IF NOT EXISTS attendance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    type ENUM('check-in', 'check-out') NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    branch_id INT,
    image_url LONGTEXT,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

-- 4. Uzoqlashishlar jadvali
CREATE TABLE IF NOT EXISTS absences (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    branch_id INT NOT NULL,
    start_time DATETIME NOT NULL,
    end_time DATETIME NULL,
    duration_minutes INT DEFAULT 0,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- 5. To'lovlar (Avans va Oylik) jadvali
CREATE TABLE IF NOT EXISTS payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    type ENUM('salary', 'advance') DEFAULT 'advance',
    payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    comment TEXT,
    created_by INT,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- 6. Dam olish kunlari so'rovlari jadvali
CREATE TABLE IF NOT EXISTS off_day_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    request_date DATE NOT NULL,
    reason TEXT,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);

-- Indices for performance
CREATE INDEX idx_attendance_emp_time ON attendance(employee_id, timestamp);
CREATE INDEX idx_attendance_branch ON attendance(branch_id);
CREATE INDEX idx_absences_emp_status ON absences(employee_id, status);
CREATE INDEX idx_payments_emp_date ON payments(employee_id, payment_date);
CREATE INDEX idx_off_day_requests_emp_date ON off_day_requests(employee_id, request_date);

-- 7. Jarimalar jadvali
CREATE TABLE IF NOT EXISTS fines (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    amount DECIMAL(15, 2) NOT NULL,
    reason TEXT,
    date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'applied', 'cancelled') DEFAULT 'pending',
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);
CREATE INDEX idx_fines_emp_date ON fines(employee_id, date);

-- 8. Kechikish haqida ogohlantirishlar jadvali
CREATE TABLE IF NOT EXISTS lateness_warnings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    reason TEXT NOT NULL,
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);
CREATE INDEX idx_warnings_emp_time ON lateness_warnings(employee_id, timestamp);

-- 9. Xarajat turlari
CREATE TABLE IF NOT EXISTS expense_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 10. Xarajatlar jadvali
CREATE TABLE IF NOT EXISTS expenses (
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
);

-- 11. Bildirishnomalar jadvali
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    type VARCHAR(50) DEFAULT 'admin_alert',
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 12. Vazifalar jadvali
CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
);
