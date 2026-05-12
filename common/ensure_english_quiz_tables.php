<?php
/**
 * Ensure English Quiz Tables Exist
 * Creates all required tables for the English Quiz system
 */

require_once __DIR__ . '/db.php';

function ensure_english_quiz_tables($conn) {
    $tables_created = [];
    $errors = [];

    // Create english_quiz_attempts table
    $sql = "CREATE TABLE IF NOT EXISTS english_quiz_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        difficulty ENUM('easy', 'medium', 'hard') NOT NULL,
        started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL,
        INDEX idx_student (student_id),
        INDEX idx_difficulty (difficulty),
        CONSTRAINT fk_english_attempt_student FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE
    )";
    
    if ($conn->query($sql)) {
        $tables_created[] = 'english_quiz_attempts';
    } else {
        $errors[] = "Error creating english_quiz_attempts: " . $conn->error;
    }

    // Create english_quiz_answers table
    $sql = "CREATE TABLE IF NOT EXISTS english_quiz_answers (
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
    
    if ($conn->query($sql)) {
        $tables_created[] = 'english_quiz_answers';
    } else {
        $errors[] = "Error creating english_quiz_answers: " . $conn->error;
    }

    // Create english_quiz_results table
    $sql = "CREATE TABLE IF NOT EXISTS english_quiz_results (
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
    
    if ($conn->query($sql)) {
        $tables_created[] = 'english_quiz_results';
    } else {
        $errors[] = "Error creating english_quiz_results: " . $conn->error;
    }

    // Create performance indexes
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_english_attempts_student_difficulty ON english_quiz_attempts(student_id, difficulty)",
        "CREATE INDEX IF NOT EXISTS idx_english_results_student_difficulty ON english_quiz_results(student_id, difficulty)",
        "CREATE INDEX IF NOT EXISTS idx_english_results_attempt_date ON english_quiz_results(attempt_date DESC)"
    ];

    foreach ($indexes as $index_sql) {
        if (!$conn->query($index_sql)) {
            $errors[] = "Error creating index: " . $conn->error;
        }
    }

    return [
        'success' => empty($errors),
        'tables_created' => $tables_created,
        'errors' => $errors
    ];
}

// Only run if called directly
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    $result = ensure_english_quiz_tables($conn);
    echo "<h2>English Quiz Tables Setup</h2>";
    echo "<p><strong>Status:</strong> " . ($result['success'] ? 'SUCCESS' : 'ERRORS') . "</p>";
    
    if (!empty($result['tables_created'])) {
        echo "<p><strong>Tables Created/Verified:</strong></p>";
        echo "<ul>";
        foreach ($result['tables_created'] as $table) {
            echo "<li>$table</li>";
        }
        echo "</ul>";
    }
    
    if (!empty($result['errors'])) {
        echo "<p><strong>Errors:</strong></p>";
        echo "<ul>";
        foreach ($result['errors'] as $error) {
            echo "<li style='color: red;'>$error</li>";
        }
        echo "</ul>";
    }
}
?>
