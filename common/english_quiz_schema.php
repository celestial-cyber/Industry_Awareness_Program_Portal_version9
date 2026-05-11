<?php
/**
 * Database Schema for English Module Quiz System with Difficulty Levels
 * Run this file to create/update the necessary tables
 */

require_once __DIR__ . '/db.php';

echo "<h1>English Quiz Database Schema Setup</h1>";

// Create English Quiz Requests Table
$sql1 = "CREATE TABLE IF NOT EXISTS english_quiz_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    difficulty ENUM('easy', 'medium', 'hard') NOT NULL,
    status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    reviewed_by INT NULL,
    admin_note VARCHAR(255) NULL,
    UNIQUE KEY uq_student_difficulty_request (student_id, difficulty),
    INDEX idx_status (status),
    INDEX idx_student (student_id),
    INDEX idx_difficulty (difficulty),
    CONSTRAINT fk_english_quiz_request_student FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_english_quiz_request_admin FOREIGN KEY (reviewed_by) REFERENCES iap_users_details(id) ON DELETE SET NULL
)";

// Create English Quiz Attempts Table
$sql2 = "CREATE TABLE IF NOT EXISTS english_quiz_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    difficulty ENUM('easy', 'medium', 'hard') NOT NULL,
    request_id INT NULL,
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP NULL,
    time_taken_seconds INT NULL,
    INDEX idx_student (student_id),
    INDEX idx_difficulty (difficulty),
    INDEX idx_request (request_id),
    CONSTRAINT fk_english_attempt_student FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_english_attempt_request FOREIGN KEY (request_id) REFERENCES english_quiz_requests(id) ON DELETE SET NULL
)";

// Create English Quiz Answers Table
$sql3 = "CREATE TABLE IF NOT EXISTS english_quiz_answers (
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
    INDEX idx_attempt (attempt_id),
    CONSTRAINT fk_english_answer_attempt FOREIGN KEY (attempt_id) REFERENCES english_quiz_attempts(id) ON DELETE CASCADE
)";

// Create English Quiz Results Table
$sql4 = "CREATE TABLE IF NOT EXISTS english_quiz_results (
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
    INDEX idx_attempt_date (attempt_date),
    CONSTRAINT fk_english_result_attempt FOREIGN KEY (attempt_id) REFERENCES english_quiz_attempts(id) ON DELETE CASCADE,
    CONSTRAINT fk_english_result_student FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE
)";

// Execute table creation
$tables = [
    'english_quiz_requests' => $sql1,
    'english_quiz_attempts' => $sql2,
    'english_quiz_answers' => $sql3,
    'english_quiz_results' => $sql4
];

echo "<div class='alert alert-info'>Creating English Quiz Tables...</div>";

foreach ($tables as $tableName => $sql) {
    echo "<h3>Creating table: $tableName</h3>";
    if ($conn->query($sql)) {
        echo "<div class='alert alert-success'>✓ Table '$tableName' created successfully</div>";
    } else {
        echo "<div class='alert alert-danger'>✗ Error creating table '$tableName': " . $conn->error . "</div>";
    }
}

// Add English Quiz link to student dashboard navigation
echo "<h3>Navigation Integration</h3>";
echo "<div class='alert alert-info'>";
echo "Add this link to your student dashboard navigation:<br>";
echo "<code>&lt;a href='english_quiz.php' class='btn btn-primary'&gt;&lt;i class='fas fa-language'&gt;&lt;/i&gt; English Quiz&lt;/a&gt;</code>";
echo "</div>";

echo "<div class='alert alert-success'>";
echo "<strong>English Quiz Database Schema Setup Complete!</strong><br>";
echo "Tables created: english_quiz_requests, english_quiz_attempts, english_quiz_answers, english_quiz_results<br>";
echo "Ready for English Module Quiz System with Difficulty Levels!";
echo "</div>";

$conn->close();
?>
