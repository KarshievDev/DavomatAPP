<?php
require_once 'config.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS tasks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        title VARCHAR(255) NOT NULL,
        description TEXT,
        status ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
        due_date DATETIME,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE
    )");
    
    // Check if tasks already exists in roles permissions
    $stmt = $pdo->query("SELECT id, permissions FROM roles");
    $roles = $stmt->fetchAll();
    foreach ($roles as $r) {
        $perms = json_decode($r['permissions'], true);
        if (!in_array('tasks', $perms)) {
            $perms[] = 'tasks';
            $pdo->prepare("UPDATE roles SET permissions = ? WHERE id = ?")->execute([json_encode($perms), $r['id']]);
        }
    }

    echo "Tasks table created successfully!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
