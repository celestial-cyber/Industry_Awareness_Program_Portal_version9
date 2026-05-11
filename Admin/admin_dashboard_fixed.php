<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

// Load Composer dependencies when available (required for PhpSpreadsheet .xlsx support).
$composer_autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($composer_autoload)) {
    require_once $composer_autoload;
}

require_once __DIR__ . '/../common/db.php';

// Check and create English quiz tables if they don't exist
$english_tables = [
    'english_quiz_requests' => "CREATE TABLE IF NOT EXISTS english_quiz_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        difficulty ENUM('easy', 'medium', 'hard') NOT NULL,
        status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP NULL,
        reviewed_by INT NULL,
        admin_note VARCHAR(255) NULL,
        INDEX idx_status (status),
        INDEX idx_student (student_id),
        INDEX idx_difficulty (difficulty)
    )",
    'english_quiz_attempts' => "CREATE TABLE IF NOT EXISTS english_quiz_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        difficulty ENUM('easy', 'medium', 'hard') NOT NULL,
        request_id INT NULL,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        time_taken_seconds INT NULL,
        INDEX idx_student (student_id),
        INDEX idx_difficulty (difficulty),
        INDEX idx_request (request_id)
    )",
    'english_quiz_answers' => "CREATE TABLE IF NOT EXISTS english_quiz_answers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        attempt_id INT NOT NULL,
        topic VARCHAR(255) NOT NULL,
        question TEXT NOT NULL,
        option_a VARCHAR(500) NOT NULL,
        option_b VARCHAR(500) NOT NULL,
        option_c VARCHAR(500) NOT NULL,
        option_d VARCHAR(500) NOT NULL,
        selected_answer ENUM('A', 'B', 'C', 'D') NULL,
        correct_answer ENUM('A', 'B', 'C', 'D') NOT NULL,
        is_correct TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attempt (attempt_id)
    )",
    'english_quiz_results' => "CREATE TABLE IF NOT EXISTS english_quiz_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        attempt_id INT NOT NULL UNIQUE,
        student_id INT NOT NULL,
        difficulty ENUM('easy', 'medium', 'hard') NOT NULL,
        total_questions INT NOT NULL,
        correct_answers INT NOT NULL,
        score INT NOT NULL,
        percentage DECIMAL(5,2) NOT NULL,
        topics_covered TEXT NULL,
        attempt_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_student (student_id),
        INDEX idx_difficulty (difficulty),
        INDEX idx_attempt_date (attempt_date)
    )"
];

foreach ($english_tables as $table_name => $create_sql) {
    $table_check = $conn->query("SHOW TABLES LIKE '$table_name'");
    if (!$table_check || $table_check->num_rows == 0) {
        if ($conn->query($create_sql)) {
            echo "✓ Created English quiz table: $table_name\n";
        } else {
            echo "✗ Error creating table: $table_name - " . $conn->error . "\n";
        }
    }
}

echo "<h1>English Quiz Tables Status</h1>";

// Check if all tables exist
$tables_exist = true;
foreach (['english_quiz_requests', 'english_quiz_attempts', 'english_quiz_answers', 'english_quiz_results'] as $table) {
    $check = $conn->query("SHOW TABLES LIKE '$table'");
    if (!$check || $check->num_rows == 0) {
        $tables_exist = false;
        echo "<div style='color: red; padding: 10px; margin: 10px; border: 1px solid red; border-radius: 5px;'>✗ Missing: $table</div>";
    }
}

if ($tables_exist) {
    echo "<div style='color: green; padding: 10px; margin: 10px; border: 1px solid green; border-radius: 5px;'>✓ All English quiz tables are ready</div>";
} else {
    echo "<div style='color: orange; padding: 10px; margin: 10px; border: 1px solid orange; border-radius: 5px;'>⚠ Some tables may be missing</div>";
}

echo "<p><a href='admin_dashboard.php'>← Back to Admin Dashboard</a></p>";

$conn->close();
?>
