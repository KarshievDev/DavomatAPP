<?php
require_once 'config.php';

try {
    echo "Starting full migration...<br>";
    
    // List of columns to add to employees table
    $columns = [
        'work_start_time' => "TIME DEFAULT '09:00:00'",
        'work_end_time' => "TIME DEFAULT '18:00:00'",
        'monthly_salary' => "DECIMAL(15, 2) DEFAULT 0",
        'work_days_per_month' => "INT DEFAULT 26"
    ];

    foreach ($columns as $col => $definition) {
        try {
            $pdo->exec("ALTER TABLE employees ADD COLUMN $col $definition");
            echo "Added column: $col <br>";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "Column $col already exists. <br>";
            } else {
                throw $e;
            }
        }
    }
    
    // Recalculate hourly_rate for all employees
    $stmt = $pdo->query("SELECT id, monthly_salary, work_days_per_month, work_start_time, work_end_time FROM employees");
    $emps = $stmt->fetchAll();
    
    foreach ($emps as $e) {
        $salary = $e['monthly_salary'] ?: 0;
        $days = $e['work_days_per_month'] ?: 26;
        $start = $e['work_start_time'] ?: '09:00:00';
        $end = $e['work_end_time'] ?: '18:00:00';
        
        $start_ts = strtotime($start);
        $end_ts = strtotime($end);
        if ($end_ts <= $start_ts) {
            $end_ts += 86400; // Add 24 hours if end time is before or same as start
        }
        $daily_hours = ($end_ts - $start_ts) / 3600;
        $hourly_rate = ($daily_hours > 0 && $days > 0) ? ($salary / ($daily_hours * $days)) : 0;
        
        $pdo->prepare("UPDATE employees SET hourly_rate = ? WHERE id = ?")->execute([$hourly_rate, $e['id']]);
    }

    echo "<b>Migration completed successfully!</b>";
} catch (Exception $e) {
    echo "<b>Critical Error:</b> " . $e->getMessage();
}
