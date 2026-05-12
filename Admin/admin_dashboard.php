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
require_once __DIR__ . '/../common/english_quiz_pdf_report.php';
require_once __DIR__ . '/../common/module_quiz_pdf_report.php';

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
            error_log("Created English quiz table: $table_name");
        } else {
            error_log("Error creating English quiz table '$table_name': " . $conn->error);
        }
    }
}

// Legacy installs: ensure difficulty column exists (avoids broken filters / blank difficulty in UI)
foreach ([
    'english_quiz_results' => "ALTER TABLE english_quiz_results ADD COLUMN difficulty ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium' AFTER student_id",
    'english_quiz_attempts' => "ALTER TABLE english_quiz_attempts ADD COLUMN difficulty ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium' AFTER student_id",
] as $eq_tbl => $eq_alter) {
    $eq_chk = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($eq_tbl) . "'");
    if ($eq_chk && $eq_chk->num_rows > 0) {
        $col_chk = $conn->query("SHOW COLUMNS FROM `" . str_replace('`', '', $eq_tbl) . "` LIKE 'difficulty'");
        if ($col_chk && $col_chk->num_rows === 0) {
            $conn->query($eq_alter);
        }
    }
}

// Create tables if not exist
$sql = "CREATE TABLE IF NOT EXISTS iap_users_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'student') NOT NULL
);";
$conn->query($sql);

// Alter table to add email column if it doesn't exist
$check_email = $conn->query("SHOW COLUMNS FROM iap_users_details LIKE 'email'");
if ($check_email && $check_email->num_rows == 0) {
    $conn->query("ALTER TABLE iap_users_details ADD COLUMN email VARCHAR(255) NOT NULL UNIQUE DEFAULT 'temp@example.com'");
}

$sql = "CREATE TABLE IF NOT EXISTS iap_session_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL,
    year ENUM('1', '2', '3', '4') NOT NULL,
    department VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    session_desired VARCHAR(255) NOT NULL,
    other_query TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS iap_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_code VARCHAR(255) NULL,
    topic VARCHAR(255) NOT NULL,
    title VARCHAR(255) NULL,
    year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sessions_session_code (session_code),
    INDEX idx_year (year),
    INDEX idx_created_at (created_at)
);";
$conn->query($sql);
// Ensure create-session form can store Graduate year without schema mismatch.
$conn->query("ALTER TABLE iap_sessions MODIFY year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL");

// Add structured session code column (idempotent migration).
$session_code_column_check = $conn->query("SHOW COLUMNS FROM iap_sessions LIKE 'session_code'");
if ($session_code_column_check && $session_code_column_check->num_rows === 0) {
    $conn->query("ALTER TABLE iap_sessions ADD COLUMN session_code VARCHAR(255) NULL AFTER id");
}
$session_code_unique_check = $conn->query("SHOW INDEX FROM iap_sessions WHERE Key_name = 'uq_sessions_session_code'");
if ($session_code_unique_check && $session_code_unique_check->num_rows === 0) {
    $conn->query("ALTER TABLE iap_sessions ADD UNIQUE KEY uq_sessions_session_code (session_code)");
}

$sql = "CREATE TABLE IF NOT EXISTS iap_session_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL,
    year ENUM('1', '2', '3', '4') NOT NULL,
    branch VARCHAR(100) NOT NULL,
    section VARCHAR(100) NOT NULL,
    session_desired TEXT NOT NULL,
    other_query TEXT,
    status ENUM('pending', 'reviewed', 'approved', 'rejected') DEFAULT 'pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS iap_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    roll_number VARCHAR(50) NOT NULL UNIQUE,
    department VARCHAR(100),
    year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
    password VARCHAR(255) DEFAULT '',
    is_password_changed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);";
$conn->query($sql);

// Ensure student password columns exist for compatibility with student authentication
$check_password_column = $conn->query("SHOW COLUMNS FROM iap_students LIKE 'password'");
if ($check_password_column && $check_password_column->num_rows == 0) {
    $conn->query("ALTER TABLE iap_students ADD COLUMN password VARCHAR(255) NOT NULL DEFAULT '' AFTER year");
}
$check_changed_column = $conn->query("SHOW COLUMNS FROM iap_students LIKE 'is_password_changed'");
if ($check_changed_column && $check_changed_column->num_rows == 0) {
    $conn->query("ALTER TABLE iap_students ADD COLUMN is_password_changed BOOLEAN DEFAULT FALSE AFTER password");
}
$check_updated_column = $conn->query("SHOW COLUMNS FROM iap_students LIKE 'updated_at'");
if ($check_updated_column && $check_updated_column->num_rows == 0) {
    $conn->query("ALTER TABLE iap_students ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at");
}

// Ensure the year enum can store Graduate values for student records.
$conn->query("ALTER TABLE iap_students MODIFY year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL");

$sql = "CREATE TABLE IF NOT EXISTS iap_psychometric_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL UNIQUE,
    score DECIMAL(5,2) NOT NULL,
    trait_a INT,
    trait_b INT,
    trait_c INT,
    trait_d INT,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE
);";
$conn->query($sql);

// Check if table has proper structure, recreate if missing columns
$check_student_id = $conn->query("SHOW COLUMNS FROM iap_psychometric_scores LIKE 'student_id'");
$check_score = $conn->query("SHOW COLUMNS FROM iap_psychometric_scores LIKE 'score'");

if ((!$check_student_id || $check_student_id->num_rows == 0) ||
    (!$check_score || $check_score->num_rows == 0)) {
    // Table is missing required columns, drop and recreate
    $conn->query("DROP TABLE IF EXISTS iap_psychometric_scores");
    $conn->query($sql);
}

$sql = "CREATE TABLE IF NOT EXISTS iap_student_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES iap_sessions(id) ON DELETE CASCADE
);";
$conn->query($sql);
$approval_status_check = $conn->query("SHOW COLUMNS FROM iap_student_sessions LIKE 'approval_status'");
if ($approval_status_check && $approval_status_check->num_rows === 0) {
    $conn->query("ALTER TABLE iap_student_sessions ADD COLUMN approval_status ENUM('pending','approved','rejected') DEFAULT 'pending' AFTER session_id");
}
$registration_status_check = $conn->query("SHOW COLUMNS FROM iap_student_sessions LIKE 'registration_status'");
if ($registration_status_check && $registration_status_check->num_rows === 0) {
    $conn->query("ALTER TABLE iap_student_sessions ADD COLUMN registration_status ENUM('registered','completed','dropped') DEFAULT 'registered' AFTER approval_status");
}
$conn->query("UPDATE iap_student_sessions SET approval_status = 'approved' WHERE approval_status IS NULL");

$sql = "CREATE TABLE IF NOT EXISTS quiz_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    year ENUM('1','2','3','4','Graduate') NOT NULL,
    module VARCHAR(255) NOT NULL,
    question TEXT NOT NULL,
    option_a VARCHAR(500) NOT NULL,
    option_b VARCHAR(500) NOT NULL,
    option_c VARCHAR(500) NOT NULL,
    option_d VARCHAR(500) NOT NULL,
    correct_answer ENUM('A','B','C','D') NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_session_id (session_id),
    INDEX idx_year (year),
    INDEX idx_module (module),
    CONSTRAINT fk_quiz_question_session FOREIGN KEY (session_id) REFERENCES iap_sessions(id) ON DELETE CASCADE
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS quiz_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    status ENUM('pending','approved','rejected') DEFAULT 'pending',
    requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL,
    reviewed_by INT NULL,
    admin_note VARCHAR(255) NULL,
    UNIQUE KEY uq_student_session_request (student_id, session_id),
    INDEX idx_status (status),
    INDEX idx_student (student_id),
    INDEX idx_session (session_id),
    CONSTRAINT fk_quiz_request_student FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_request_session FOREIGN KEY (session_id) REFERENCES iap_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_request_admin FOREIGN KEY (reviewed_by) REFERENCES iap_users_details(id) ON DELETE SET NULL
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS quiz_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    request_id INT NULL,
    attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student (student_id),
    INDEX idx_session (session_id),
    CONSTRAINT fk_quiz_attempt_student FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_attempt_session FOREIGN KEY (session_id) REFERENCES iap_sessions(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_attempt_request FOREIGN KEY (request_id) REFERENCES quiz_requests(id) ON DELETE SET NULL
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS quiz_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    question_id INT NOT NULL,
    selected_answer ENUM('A','B','C','D') NULL,
    is_correct TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attempt (attempt_id),
    INDEX idx_question (question_id),
    CONSTRAINT fk_quiz_answer_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_answer_question FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS quiz_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL UNIQUE,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    total_questions INT NOT NULL,
    correct_answers INT NOT NULL,
    score INT NOT NULL,
    percentage DECIMAL(5,2) NOT NULL,
    attempt_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_student (student_id),
    INDEX idx_session (session_id),
    INDEX idx_attempt_date (attempt_date),
    CONSTRAINT fk_quiz_result_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_result_student FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_quiz_result_session FOREIGN KEY (session_id) REFERENCES iap_sessions(id) ON DELETE CASCADE
);";
$conn->query($sql);

// Table for managing psychometric quiz questions.
$sql = "CREATE TABLE IF NOT EXISTS iap_psychometric_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question TEXT NOT NULL,
    option_a VARCHAR(255) NOT NULL,
    option_b VARCHAR(255) NOT NULL,
    option_c VARCHAR(255) NOT NULL,
    option_d VARCHAR(255) NOT NULL,
    correct_answer CHAR(1) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";
$conn->query($sql);

// Prevent duplicate registration of the same student in the same session.
$registration_unique_check = $conn->query("SHOW INDEX FROM iap_student_sessions WHERE Key_name = 'unique_student_session'");
if ($registration_unique_check && $registration_unique_check->num_rows === 0) {
    $conn->query("ALTER TABLE iap_student_sessions ADD UNIQUE KEY unique_student_session (student_id, session_id)");
}
// Recommended duplicate prevention on first 255 chars.
// Add the unique index only once to avoid duplicate-key fatal errors on subsequent page loads.
$index_check = $conn->query("SHOW INDEX FROM iap_psychometric_questions WHERE Key_name = 'uq_psychometric_question'");
if ($index_check && $index_check->num_rows === 0) {
    $conn->query("ALTER TABLE iap_psychometric_questions ADD UNIQUE KEY uq_psychometric_question (question(255))");
}

// Insert default admin if not exists
$sql = 'INSERT IGNORE INTO iap_users_details (username, email, password, role) VALUES (\'admin\', \'admin@example.com\', \'$2y$10$xHDNFM0xYFstLYe.BIHMUu4ZxCcEeKOQ3psUy85ZcbsCqdbWUy2Z.\', \'admin\')';
$conn->query($sql);

$message = '';
$password_popup_message = '';
$page = isset($_GET['page']) ? $_GET['page'] : 'home';

$valid_years = ['1', '2', '3', '4', 'Graduate'];
$valid_departments = ['Computer Science', 'Electronics', 'Mechanical', 'Electrical', 'Civil', 'AIML', 'Cybersecurity', 'Data Science', 'Other'];
$psychometric_upload_summary = '';
$session_wise_registrations = [];
$session_registration_rows = [];
$session_title_column = 'topic';
$registration_year_filter = '';
$registration_sort = 'latest';
$quiz_perf_year = '';
$quiz_perf_session = 0;
$quiz_perf_student_name = '';
$quiz_perf_roll = '';
$quiz_perf_sessions = [];

$title_col_check = $conn->query("SHOW COLUMNS FROM iap_sessions LIKE 'title'");
if ($title_col_check && $title_col_check->num_rows > 0) {
    $session_title_column = 'title';
}

$question_pattern = "/^[a-zA-Z0-9\\s\\?\\!\\,\\.\\-\\(\\)']{10,}$/";
$option_pattern = "/^.{1,}$/";
$answer_pattern = "/^[ABCD]$/";

/**
 * Validate psychometric question fields using required regex rules.
 */
function validate_psychometric_question_row($question, $a, $b, $c, $d, $answer, $question_pattern, $option_pattern, $answer_pattern): bool
{
    return preg_match($question_pattern, $question) === 1
        && preg_match($option_pattern, $a) === 1
        && preg_match($option_pattern, $b) === 1
        && preg_match($option_pattern, $c) === 1
        && preg_match($option_pattern, $d) === 1
        && preg_match($answer_pattern, $answer) === 1;
}

/**
 * Normalize year text into YY code used by session codes.
 */
function year_to_code(string $year): string
{
    $v = strtolower(trim($year));
    if (preg_match('/([1-4])/', $v, $m)) {
        return str_pad($m[1], 2, '0', STR_PAD_LEFT);
    }
    return '00';
}

/**
 * Generate next session code for a given year.
 * Format: YYSNNN (e.g. 01SN01, 02SN03)
 */
function generate_next_session_code(mysqli $conn, string $year): string
{
    $yy = year_to_code($year);
    $prefix = $yy . 'SN';

    $sql = "SELECT session_code FROM iap_sessions
            WHERE session_code LIKE CONCAT(?, '%')
            ORDER BY session_code DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $prefix . '01';
    }
    $stmt->bind_param("s", $prefix);
    $stmt->execute();
    $res = $stmt->get_result();
    $last = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    $next_num = 1;
    if ($last && !empty($last['session_code']) && preg_match('/SN(\d{2})$/', $last['session_code'], $m)) {
        $next_num = intval($m[1]) + 1;
    }
    return $prefix . str_pad((string)$next_num, 2, '0', STR_PAD_LEFT);
}

/**
 * One-time/backfill: generate missing session_code for existing sessions,
 * grouped by year and ordered by id ascending.
 */
function backfill_session_codes(mysqli $conn): void
{
    $query = "SELECT id, year FROM iap_sessions WHERE session_code IS NULL OR session_code = '' ORDER BY year ASC, id ASC";
    $result = $conn->query($query);
    if (!$result) {
        return;
    }

    $year_counters = [];
    while ($row = $result->fetch_assoc()) {
        $session_id = (int)$row['id'];
        $year = (string)$row['year'];
        $yy = year_to_code($year);
        if ($yy === '00') {
            continue;
        }
        if (!isset($year_counters[$yy])) {
            $count_sql = "SELECT COUNT(*) AS cnt FROM iap_sessions WHERE session_code LIKE CONCAT(?, '%')";
            $count_stmt = $conn->prepare($count_sql);
            if ($count_stmt) {
                $prefix = $yy . 'SN';
                $count_stmt->bind_param("s", $prefix);
                $count_stmt->execute();
                $cnt_res = $count_stmt->get_result();
                $cnt_row = $cnt_res ? $cnt_res->fetch_assoc() : ['cnt' => 0];
                $year_counters[$yy] = intval($cnt_row['cnt']);
                $count_stmt->close();
            } else {
                $year_counters[$yy] = 0;
            }
        }
        $year_counters[$yy]++;
        $session_code = $yy . 'SN' . str_pad((string)$year_counters[$yy], 2, '0', STR_PAD_LEFT);

        $upd = $conn->prepare("UPDATE iap_sessions SET session_code = ? WHERE id = ?");
        if ($upd) {
            $upd->bind_param("si", $session_code, $session_id);
            $upd->execute();
            $upd->close();
        }
    }
}

// Ensure existing sessions receive structured codes.
backfill_session_codes($conn);

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_session'])) {
    $topic = trim($_POST['topic'] ?? '');
    $year = trim($_POST['year'] ?? '');
    $manual_session_code = trim($_POST['manual_session_code'] ?? '');
    
    $errors = [];
    
    // Validate topic
    if (empty($topic)) {
        $errors[] = "Session topic/name is required.";
    }
    
    // Validate year
    if (empty($year)) {
        $errors[] = "Academic year is required.";
    }
    
    // Validate manual session code if provided
    if (!empty($manual_session_code)) {
        // Format: ALPHANUMERIC-SESSIONNAME
        // Must contain hyphen and alphanumeric prefix
        if (!preg_match('/^[A-Z0-9]+-[A-Za-z0-9\s]+$/', $manual_session_code)) {
            $errors[] = "Session code must be in format: ALPHANUMERIC-SESSIONNAME (e.g., CS101-Introduction to Programming). Only letters, numbers, and hyphens allowed.";
        } else {
            // Check for duplicate session code
            $check_sql = "SELECT id FROM iap_sessions WHERE session_code = ? LIMIT 1";
            $check_stmt = $conn->prepare($check_sql);
            if ($check_stmt) {
                $check_stmt->bind_param("s", $manual_session_code);
                $check_stmt->execute();
                $check_result = $check_stmt->get_result();
                if ($check_result && $check_result->num_rows > 0) {
                    $errors[] = "Session code already exists. Please use a unique code.";
                }
                $check_stmt->close();
            }
        }
    }
    
    if (empty($errors)) {
        // Use manual code if provided, otherwise auto-generate
        $session_code = !empty($manual_session_code) ? $manual_session_code : generate_next_session_code($conn, (string)$year);
        
        $sql = "INSERT INTO iap_sessions (session_code, topic, year) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sss", $session_code, $topic, $year);
        if ($stmt->execute()) {
            $message = "Session created successfully! Code: " . htmlspecialchars($session_code);
        } else {
            if ($conn->errno === 1062) {
                $message = "Error: Session code already exists. Please use a unique code.";
            } else {
                $message = "Error: " . $conn->error;
            }
        }
        $stmt->close();
    } else {
        $message = implode("<br>", $errors);
    }
}

// Handle approve action for session requests
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['approve_request'])) {
    $request_id = intval($_POST['request_id']);
    
    $sql = "UPDATE iap_session_suggestions SET status = 'approved' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $request_id);

    if ($stmt->execute()) {
        $message = "Session request approved successfully!";
    } else {
        $message = "Error approving request: " . $conn->error;
    }
    $stmt->close();
}

// Handle reject action for session requests
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reject_request'])) {
    $request_id = intval($_POST['request_id']);
    
    $sql = "UPDATE iap_session_suggestions SET status = 'rejected' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $request_id);

    if ($stmt->execute()) {
        $message = "Session request rejected successfully!";
    } else {
        $message = "Error rejecting request: " . $conn->error;
    }
    $stmt->close();
}

/**
 * Import module-wise quiz MCQs from CSV.
 * CSV headers expected:
 * Topic,Difficulty,Question,Option A,Option B,Option C,Option D,Correct Answer
 */
 

// Handle module quiz approval status updates for student enrollments.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_module_approval'])) {
    $student_id = intval($_POST['student_id'] ?? 0);
    $session_id = intval($_POST['session_id'] ?? 0);
    $approval_status = trim($_POST['approval_status'] ?? '');
    $allowed_approval = ['pending', 'approved', 'rejected'];

    if ($student_id > 0 && $session_id > 0 && in_array($approval_status, $allowed_approval, true)) {
        $upd_sql = "UPDATE iap_student_sessions SET approval_status = ? WHERE student_id = ? AND session_id = ?";
        $upd_stmt = $conn->prepare($upd_sql);
        if ($upd_stmt) {
            $upd_stmt->bind_param("sii", $approval_status, $student_id, $session_id);
            if ($upd_stmt->execute()) {
                $message = "Module access status updated to " . htmlspecialchars($approval_status) . ".";
            } else {
                $message = "Failed to update module access status.";
            }
            $upd_stmt->close();
        }
    } else {
        $message = "Invalid module access update request.";
    }
}

// Quiz approval logic removed - no approval needed

// Handle admin student update action from the registered students section.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_student'])) {
    $student_id = intval($_POST['student_id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $roll_number = strtoupper(trim($_POST['roll_number'] ?? ''));
    $department = trim($_POST['department'] ?? '');
    $year = trim($_POST['year'] ?? '');
    $new_password = trim($_POST['new_password'] ?? '');

    $errors = [];

    if (empty($full_name) || strlen($full_name) < 2) {
        $errors[] = 'Full name is required and must be at least 2 characters.';
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'A valid email address is required.';
    }
    if (empty($roll_number) || !preg_match('/^[0-9]{2}BK1A[A-Za-z0-9]{2}[A-Za-z0-9]{2}$/', $roll_number)) {
        $errors[] = 'Roll number must match the format YYBK1ACCXX, for example 23BK1A66L5.';
    }
    if (empty($department) || !in_array($department, $valid_departments, true)) {
        $errors[] = 'Please select a valid department.';
    }
    if (!in_array($year, $valid_years, true)) {
        $errors[] = 'Please select a valid year.';
    }
    if ($student_id <= 0) {
        $errors[] = 'Invalid student record.';
    }
    if ($new_password !== '' && strlen($new_password) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }

    if (empty($errors)) {
        // Ensure email/roll_number remain unique across other students.
        $duplicate_sql = "SELECT id FROM iap_students WHERE (email = ? OR roll_number = ?) AND id <> ? LIMIT 1";
        $duplicate_stmt = $conn->prepare($duplicate_sql);
        if ($duplicate_stmt) {
            $duplicate_stmt->bind_param("ssi", $email, $roll_number, $student_id);
            $duplicate_stmt->execute();
            $duplicate_result = $duplicate_stmt->get_result();
            if ($duplicate_result && $duplicate_result->num_rows > 0) {
                $errors[] = 'Another student already uses this email or roll number.';
            }
            $duplicate_stmt->close();
        } else {
            $errors[] = 'Database error while validating duplicate student details.';
        }
    }

    if (empty($errors)) {
        $password_hash = '';
        $should_update_password = $new_password !== '';
        if ($should_update_password) {
            $password_hash = password_hash($new_password, PASSWORD_BCRYPT);
            if ($password_hash === false) {
                $errors[] = 'Unable to process the new password.';
            }
        }
    }

    if (empty($errors)) {
        if (!empty($should_update_password)) {
            $sql = "UPDATE iap_students SET full_name = ?, email = ?, roll_number = ?, department = ?, year = ?, password = ?, is_password_changed = 1 WHERE id = ?";
        } else {
            $sql = "UPDATE iap_students SET full_name = ?, email = ?, roll_number = ?, department = ?, year = ? WHERE id = ?";
        }
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!empty($should_update_password)) {
                $stmt->bind_param("ssssssi", $full_name, $email, $roll_number, $department, $year, $password_hash, $student_id);
            } else {
                $stmt->bind_param("sssssi", $full_name, $email, $roll_number, $department, $year, $student_id);
            }
            if ($stmt->execute()) {
                $message = !empty($should_update_password) ? 'Student record and password updated successfully.' : 'Student record updated successfully.';
                if (!empty($should_update_password)) {
                    $password_popup_message = $message;
                }
            } else {
                $message = 'Error updating student: ' . $conn->error;
                if (!empty($should_update_password)) {
                    $password_popup_message = $message;
                }
            }
            $stmt->close();
        } else {
            $message = 'Database error: ' . $conn->error;
            if (!empty($should_update_password)) {
                $password_popup_message = $message;
            }
        }
    } else {
        $message = implode('<br>', $errors);
        if ($new_password !== '') {
            $password_popup_message = strip_tags($message);
        }
    }
}

// Manual question entry.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['add_psychometric_question'])) {
    $question = trim($_POST['question'] ?? '');
    $option_a = trim($_POST['option_a'] ?? '');
    $option_b = trim($_POST['option_b'] ?? '');
    $option_c = trim($_POST['option_c'] ?? '');
    $option_d = trim($_POST['option_d'] ?? '');
    $correct_answer = strtoupper(trim($_POST['correct_answer'] ?? ''));

    if (!validate_psychometric_question_row($question, $option_a, $option_b, $option_c, $option_d, $correct_answer, $question_pattern, $option_pattern, $answer_pattern)) {
        $message = "Validation failed. Ensure question/options/correct answer follow required format.";
    } else {
        $insert_sql = "INSERT INTO iap_psychometric_questions (question, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, ?, ?, ?, ?, ?)";
        $insert_stmt = $conn->prepare($insert_sql);
        if ($insert_stmt) {
            $insert_stmt->bind_param("ssssss", $question, $option_a, $option_b, $option_c, $option_d, $correct_answer);
            if ($insert_stmt->execute()) {
                $message = "Psychometric question added successfully.";
            } else {
                $message = $conn->errno === 1062 ? "Duplicate question detected. Skipped." : ("Error adding question: " . $conn->error);
            }
            $insert_stmt->close();
        } else {
            $message = "Database error: " . $conn->error;
        }
    }
}

// Excel/CSV upload processing for psychometric questions.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['upload_psychometric_file'])) {
    $max_file_size = 2 * 1024 * 1024; // 2 MB
    $expected_headers = ['Question', 'Option A', 'Option B', 'Option C', 'Option D', 'Correct Answer'];
    $inserted_rows = 0;
    $skipped_rows = 0;

    if (!isset($_FILES['psychometric_file']) || $_FILES['psychometric_file']['error'] !== UPLOAD_ERR_OK) {
        $message = "File upload failed. Please try again.";
    } else {
        $uploaded_file = $_FILES['psychometric_file'];
        $file_name = $uploaded_file['name'] ?? '';
        $tmp_path = $uploaded_file['tmp_name'] ?? '';
        $file_size = (int)($uploaded_file['size'] ?? 0);
        $extension = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        $allowed_extensions = ['csv', 'xlsx'];
        $allowed_mime_types = [
            'csv' => ['text/csv', 'application/csv', 'text/plain', 'application/vnd.ms-excel'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/octet-stream']
        ];

        if ($file_size <= 0 || $file_size > $max_file_size) {
            $message = "Invalid file size. Maximum allowed size is 2MB.";
        } elseif (!in_array($extension, $allowed_extensions, true)) {
            $message = "Invalid file extension. Only .csv and .xlsx are allowed.";
        } else {
            $detected_mime = mime_content_type($tmp_path);
            if (!in_array($detected_mime, $allowed_mime_types[$extension], true)) {
                $message = "Invalid file type. MIME validation failed.";
            } else {
                $rows = [];
                if ($extension === 'csv') {
                    if (($handle = fopen($tmp_path, 'r')) !== false) {
                        while (($data = fgetcsv($handle)) !== false) {
                            $rows[] = $data;
                        }
                        fclose($handle);
                    }
                } else {
                    if (!class_exists('\PhpOffice\PhpSpreadsheet\IOFactory')) {
                        $message = "PhpSpreadsheet is required for .xlsx uploads. Install it via Composer first.";
                    } else {
                        try {
                            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp_path);
                            $sheet = $spreadsheet->getActiveSheet();
                            foreach ($sheet->toArray(null, true, true, true) as $row) {
                                $rows[] = [
                                    $row['A'] ?? '',
                                    $row['B'] ?? '',
                                    $row['C'] ?? '',
                                    $row['D'] ?? '',
                                    $row['E'] ?? '',
                                    $row['F'] ?? ''
                                ];
                            }
                        } catch (Exception $e) {
                            $message = "Unable to read .xlsx file: " . $e->getMessage();
                        }
                    }
                }

                if (empty($message)) {
                    if (count($rows) < 2) {
                        $message = "Upload file has no data rows.";
                    } else {
                        $header_row = array_map('trim', $rows[0]);
                        if ($header_row !== $expected_headers) {
                            $message = "Header mismatch. Required format: Question | Option A | Option B | Option C | Option D | Correct Answer";
                        } else {
                            $insert_sql = "INSERT INTO iap_psychometric_questions (question, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, ?, ?, ?, ?, ?)";
                            $insert_stmt = $conn->prepare($insert_sql);
                            if (!$insert_stmt) {
                                $message = "Database error: " . $conn->error;
                            } else {
                                for ($i = 1; $i < count($rows); $i++) {
                                    $row = $rows[$i];
                                    $question = trim((string)($row[0] ?? ''));
                                    $option_a = trim((string)($row[1] ?? ''));
                                    $option_b = trim((string)($row[2] ?? ''));
                                    $option_c = trim((string)($row[3] ?? ''));
                                    $option_d = trim((string)($row[4] ?? ''));
                                    $correct_answer = strtoupper(trim((string)($row[5] ?? '')));

                                    if ($question === '' || $option_a === '' || $option_b === '' || $option_c === '' || $option_d === '' || $correct_answer === '') {
                                        $skipped_rows++;
                                        continue;
                                    }

                                    if (!validate_psychometric_question_row($question, $option_a, $option_b, $option_c, $option_d, $correct_answer, $question_pattern, $option_pattern, $answer_pattern)) {
                                        $skipped_rows++;
                                        continue;
                                    }

                                    $insert_stmt->bind_param("ssssss", $question, $option_a, $option_b, $option_c, $option_d, $correct_answer);
                                    if ($insert_stmt->execute()) {
                                        $inserted_rows++;
                                    } else {
                                        $skipped_rows++;
                                    }
                                }
                                $insert_stmt->close();
                                $message = "Upload processed successfully.";
                                $psychometric_upload_summary = "Inserted: {$inserted_rows}, Skipped: {$skipped_rows}";
                            }
                        }
                    }
                }
            }
        }
    }
}

if ($page == 'requests') {
    // Fetch pending requests
    $sql_pending = "SELECT * FROM iap_session_suggestions WHERE status = 'pending' ORDER BY submitted_at DESC";
    $pending_result = $conn->query($sql_pending);

    if (!$pending_result) {
        die("MySQL Error fetching pending session suggestions: " . $conn->error);
    }

    // Fetch approved requests
    $sql_approved = "SELECT * FROM iap_session_suggestions WHERE status = 'approved' ORDER BY submitted_at DESC";
    $approved_result = $conn->query($sql_approved);

    if (!$approved_result) {
        die("MySQL Error fetching approved session suggestions: " . $conn->error);
    }

    // Fetch rejected requests
    $sql_rejected = "SELECT * FROM iap_session_suggestions WHERE status = 'rejected' ORDER BY submitted_at DESC";
    $rejected_result = $conn->query($sql_rejected);

    if (!$rejected_result) {
        die("MySQL Error fetching rejected session suggestions: " . $conn->error);
    }

} else if ($page == 'registered_students') {
    // Fetch all students who registered through student_sessions
    $sql = "SELECT DISTINCT
                s.id,
                s.full_name,
                s.email,
                s.roll_number,
                s.department,
                s.year,
                s.password,
                COUNT(DISTINCT ss.session_id) as sessions_count,
                GROUP_CONCAT(DISTINCT CONCAT(COALESCE(sess.session_code, ''), CASE WHEN sess.session_code IS NULL OR sess.session_code = '' THEN '' ELSE ' - ' END, sess.{$session_title_column}) SEPARATOR ', ') as registered_sessions,
                COALESCE(ps.score, 0) as iap_psychometric_score,
                CASE WHEN ps.score IS NOT NULL THEN 'Completed' ELSE 'Not Taken' END as assessment_status
            FROM iap_students s
            LEFT JOIN iap_student_sessions ss ON s.id = ss.student_id
            LEFT JOIN iap_sessions sess ON ss.session_id = sess.id
            LEFT JOIN iap_psychometric_scores ps ON s.id = ps.student_id
            GROUP BY s.id
            ORDER BY s.created_at DESC";
    $registered_students_result = $conn->query($sql);
    if (!$registered_students_result) {
        // Fallback query if the join fails
        $sql = "SELECT id, full_name, email, roll_number, department, year, password FROM iap_students ORDER BY created_at DESC";
        $registered_students_result = $conn->query($sql);
        if (!$registered_students_result) {
            die("MySQL Error fetching registered students: " . $conn->error);
        }
    }
} else if ($page == 'psychometric_status') {
    // Fetch students who have taken the psychometric test
    $sql_completed = "SELECT s.id, s.full_name, s.email, s.roll_number, s.department, s.year,
                             ps.score, ps.trait_a, ps.trait_b, ps.trait_c, ps.trait_d, ps.completed_at
                      FROM iap_students s
                      INNER JOIN iap_psychometric_scores ps ON s.id = ps.student_id
                      ORDER BY ps.completed_at DESC";
    $completed_result = $conn->query($sql_completed);
    if (!$completed_result) {
        die("MySQL Error fetching completed psychometric results: " . $conn->error);
    }

    // Fetch students who haven't taken the psychometric test
    $sql_pending = "SELECT s.id, s.full_name, s.email, s.roll_number, s.department, s.year
                    FROM iap_students s
                    LEFT JOIN iap_psychometric_scores ps ON s.id = ps.student_id
                    WHERE ps.student_id IS NULL
                    ORDER BY s.created_at DESC";
    $pending_result = $conn->query($sql_pending);
    if (!$pending_result) {
        die("MySQL Error fetching pending psychometric results: " . $conn->error);
    }
} else if ($page == 'session_wise_registrations') {
    // Validate filters.
    $allowed_filter_years = ['1', '2', '3', '4', 'Graduate'];
    $registration_year_filter = trim($_GET['year_filter'] ?? '');
    if (!in_array($registration_year_filter, $allowed_filter_years, true)) {
        $registration_year_filter = '';
    }

    $registration_sort = strtolower(trim($_GET['sort'] ?? 'latest'));
    if (!in_array($registration_sort, ['latest', 'oldest'], true)) {
        $registration_sort = 'latest';
    }

    // Load form-submitted data from index.php table iap_session_registrations (if available).
    $use_form_table = false;
    $form_table_check = $conn->query("SHOW TABLES LIKE 'iap_session_registrations'");
    if ($form_table_check && $form_table_check->num_rows > 0) {
        $use_form_table = true;
    }

    if ($use_form_table) {
        $form_sql = "SELECT
                        CONCAT('form_', r.id) AS session_id,
                        r.session_desired AS session_title,
                        r.year AS session_year,
                        r.name AS student_name,
                        r.roll_number,
                        r.department,
                        r.email,
                        r.other_query,
                        r.submitted_at AS registration_date
                     FROM iap_session_registrations r";
        $form_where = "";
        $form_order = " ORDER BY r.year ASC, r.session_desired ASC, r.submitted_at " . ($registration_sort === 'oldest' ? 'ASC' : 'DESC');

        $stmt = null;
        if ($registration_year_filter !== '') {
            $form_where = " WHERE r.year = ?";
            $stmt = $conn->prepare($form_sql . $form_where . $form_order);
            if (!$stmt) {
                die("MySQL Prepare Error: " . $conn->error);
            }
            $stmt->bind_param("s", $registration_year_filter);
        } else {
            $stmt = $conn->prepare($form_sql . $form_order);
            if (!$stmt) {
                die("MySQL Prepare Error: " . $conn->error);
            }
        }

        $stmt->execute();
        $result = $stmt->get_result();

        // Group by session title + year for single-table form registrations.
        while ($row = $result->fetch_assoc()) {
            $key = trim((string)$row['session_title']) . '|' . trim((string)$row['session_year']);
            if (!isset($session_wise_registrations[$key])) {
                $session_wise_registrations[$key] = [
                    'session_id' => $key,
                    'session_title' => $row['session_title'],
                    'session_year' => $row['session_year'],
                    'total_registered' => 0,
                    'students' => []
                ];
            }
            $session_wise_registrations[$key]['students'][] = [
                'student_name' => $row['student_name'],
                'roll_number' => $row['roll_number'],
                'year' => $row['session_year'],
                'department' => $row['department'],
                'email' => $row['email'],
                'session_desired' => $row['session_title'],
                'other_query' => $row['other_query'] ?? '',
                'registration_date' => $row['registration_date']
            ];
            $session_registration_rows[] = [
                'session_title' => $row['session_title'],
                'session_year' => $row['session_year'],
                'student_name' => $row['student_name'],
                'roll_number' => $row['roll_number'],
                'year' => $row['session_year'],
                'department' => $row['department'],
                'email' => $row['email'],
                'session_desired' => $row['session_title'],
                'other_query' => $row['other_query'] ?? '',
                'registration_date' => $row['registration_date']
            ];
            $session_wise_registrations[$key]['total_registered']++;
        }
        $stmt->close();
    }

    // Also load normalized student-portal registrations (iap_student_sessions + iap_students + sessions).
    $registration_date_column = 'registered_at';
    $registered_at_check = $conn->query("SHOW COLUMNS FROM iap_student_sessions LIKE 'registered_at'");
    if (!$registered_at_check || $registered_at_check->num_rows === 0) {
        $created_at_check = $conn->query("SHOW COLUMNS FROM iap_student_sessions LIKE 'created_at'");
        if ($created_at_check && $created_at_check->num_rows > 0) {
            $registration_date_column = 'created_at';
        }
    }

    $base_sql = "SELECT
                    s.id AS session_id,
                    s.session_code,
                    CONCAT(COALESCE(s.session_code, ''), CASE WHEN s.session_code IS NULL OR s.session_code = '' THEN '' ELSE ' - ' END, s.{$session_title_column}) AS session_title,
                    s.year AS session_year,
                    st.full_name AS student_name,
                    st.id AS student_id,
                    st.roll_number,
                    st.department,
                    st.email,
                    ss.approval_status,
                    ss.{$registration_date_column} AS registration_date
                 FROM iap_student_sessions ss
                 INNER JOIN iap_students st ON st.id = ss.student_id
                 INNER JOIN iap_sessions s ON s.id = ss.session_id";

    $where_clause = "";
    $order_clause = " ORDER BY s.year ASC, s.{$session_title_column} ASC, ss.{$registration_date_column} " . ($registration_sort === 'oldest' ? 'ASC' : 'DESC');

    $stmt = null;
    if ($registration_year_filter !== '') {
        $where_clause = " WHERE s.year = ?";
        $stmt = $conn->prepare($base_sql . $where_clause . $order_clause);
        if (!$stmt) {
            die("MySQL Prepare Error: " . $conn->error);
        }
        $stmt->bind_param("s", $registration_year_filter);
    } else {
        $stmt = $conn->prepare($base_sql . $order_clause);
        if (!$stmt) {
            die("MySQL Prepare Error: " . $conn->error);
        }
    }

    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $sid = (int)$row['session_id'];
        $group_key = 'db_' . $sid;
        if (!isset($session_wise_registrations[$group_key])) {
            $session_wise_registrations[$group_key] = [
                'session_id' => $sid,
                'session_title' => $row['session_title'],
                'session_year' => $row['session_year'],
                'total_registered' => 0,
                'students' => []
            ];
        }
        $session_wise_registrations[$group_key]['students'][] = [
            'student_name' => $row['student_name'],
            'student_id' => (int)$row['student_id'],
            'roll_number' => $row['roll_number'],
            'year' => $row['session_year'],
            'department' => $row['department'],
            'email' => $row['email'],
            'session_desired' => $row['session_title'],
            'other_query' => '',
            'approval_status' => $row['approval_status'] ?? 'pending',
            'registration_date' => $row['registration_date']
        ];
        $session_registration_rows[] = [
            'session_code' => $row['session_code'] ?? '',
            'session_id' => (int)$row['session_id'],
            'student_id' => (int)$row['student_id'],
            'session_title' => $row['session_title'],
            'session_year' => $row['session_year'],
            'student_name' => $row['student_name'],
            'roll_number' => $row['roll_number'],
            'year' => $row['session_year'],
            'department' => $row['department'],
            'email' => $row['email'],
            'session_desired' => $row['session_title'],
            'other_query' => '',
            'approval_status' => $row['approval_status'] ?? 'pending',
            'registration_date' => $row['registration_date']
        ];
        $session_wise_registrations[$group_key]['total_registered']++;
    }
    $stmt->close();

    // Re-index for foreach rendering.
    $session_wise_registrations = array_values($session_wise_registrations);
} else if ($page == 'module_quiz_performance') {
    $quiz_perf_year = trim($_GET['quiz_year'] ?? '');
    if (!in_array($quiz_perf_year, ['', '1', '2', '3', '4', 'Graduate'], true)) {
        $quiz_perf_year = '';
    }
    $quiz_perf_session = intval($_GET['quiz_session_id'] ?? 0);
    $quiz_perf_student_name = trim($_GET['quiz_student_name'] ?? '');
    $quiz_perf_roll = trim($_GET['quiz_roll_number'] ?? '');

    // Load sessions for the selected year (or all if no year selected)
    $session_list_sql = "SELECT id, year, {$session_title_column} AS title, session_code FROM iap_sessions WHERE 1=1";
    
    if ($quiz_perf_year !== '') {
        $session_list_sql .= " AND year = ?";
        $session_list_stmt = $conn->prepare($session_list_sql);
        $session_list_stmt->bind_param('s', $quiz_perf_year);
        $session_list_stmt->execute();
        $session_list_result = $session_list_stmt->get_result();
        $session_list_stmt->close();
    } else {
        $session_list_result = $conn->query($session_list_sql);
    }
    
    $session_list_sql .= " ORDER BY year ASC, {$session_title_column} ASC";
    
    if ($quiz_perf_year !== '') {
        $session_list_sql = "SELECT id, year, {$session_title_column} AS title, session_code FROM iap_sessions WHERE year = ? ORDER BY year ASC, {$session_title_column} ASC";
        $session_list_stmt = $conn->prepare($session_list_sql);
        $session_list_stmt->bind_param('s', $quiz_perf_year);
        $session_list_stmt->execute();
        $session_list_result = $session_list_stmt->get_result();
        $session_list_stmt->close();
    } else {
        $session_list_sql = "SELECT id, year, {$session_title_column} AS title, session_code FROM iap_sessions ORDER BY year ASC, {$session_title_column} ASC";
        $session_list_result = $conn->query($session_list_sql);
    }
    
    if ($session_list_result) {
        while ($srow = $session_list_result->fetch_assoc()) {
            $quiz_perf_sessions[] = $srow;
        }
    }

    $perf_sql = "SELECT
                    qa.id,
                    qa.attempt_id,
                    st.full_name,
                    st.roll_number,
                    st.year AS student_academic_year,
                    s.{$session_title_column} AS module_name,
                    s.session_code,
                    s.year AS module_year,
                    qa.score,
                    qa.percentage,
                    qa.total_questions,
                    qa.correct_answers,
                    (qa.total_questions - qa.correct_answers) AS wrong_answers,
                    qa.attempt_date AS attempted_at
                 FROM quiz_results qa
                 INNER JOIN iap_students st ON st.id = qa.student_id
                 INNER JOIN iap_sessions s ON s.id = qa.session_id
                 WHERE 1=1";
    $types = '';
    $params = [];
    if ($quiz_perf_year !== '') {
        $perf_sql .= " AND s.year = ?";
        $types .= 's';
        $params[] = $quiz_perf_year;
    }
    if ($quiz_perf_session > 0) {
        $perf_sql .= " AND s.id = ?";
        $types .= 'i';
        $params[] = $quiz_perf_session;
    }
    if ($quiz_perf_student_name !== '') {
        $perf_sql .= " AND st.full_name LIKE ?";
        $types .= 's';
        $params[] = '%' . $quiz_perf_student_name . '%';
    }
    if ($quiz_perf_roll !== '') {
        $perf_sql .= " AND st.roll_number LIKE ?";
        $types .= 's';
        $params[] = '%' . $quiz_perf_roll . '%';
    }
    $perf_sql .= " ORDER BY qa.attempt_date DESC, qa.id DESC";

    $quiz_performance_stmt = $conn->prepare($perf_sql);
    if ($quiz_performance_stmt) {
        if ($types !== '') {
            $bind_values = [];
            $bind_values[] = &$types;
            foreach ($params as $k => $v) {
                $bind_values[] = &$params[$k];
            }
            call_user_func_array([$quiz_performance_stmt, 'bind_param'], $bind_values);
        }
        $quiz_performance_stmt->execute();
        $quiz_performance_result = $quiz_performance_stmt->get_result();
        $quiz_performance_stmt->close();
    }
} else if ($page == 'manage_psychometric_questions') {
    $psychometric_questions_result = $conn->query("SELECT id, question, option_a, option_b, option_c, option_d, correct_answer, created_at FROM iap_psychometric_questions ORDER BY id DESC LIMIT 50");
}

// Full-page English quiz report (must run before HTML output)
if ($page === 'english_quiz_performance' && isset($_GET['view_english_quiz_report']) && (int)($_GET['attempt_id'] ?? 0) > 0) {
    $view_attempt = (int)$_GET['attempt_id'];
    try {
        $english_view_report = new EnglishQuizPDFReport($conn, $view_attempt);
        header('Content-Type: text/html; charset=UTF-8');
        echo $english_view_report->generate_html(true);
    } catch (Throwable) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Report</title></head><body style="font-family:Segoe UI,sans-serif;padding:24px;">';
        echo '<p>Report could not be loaded.</p><p><a href="?page=english_quiz_performance">Back to English Quiz Performance</a></p></body></html>';
    }
    exit;
}
if ($page === 'module_quiz_performance' && isset($_GET['view_module_quiz_report']) && (int)($_GET['attempt_id'] ?? 0) > 0) {
    $mod_view_attempt = (int)$_GET['attempt_id'];
    try {
        $mod_view_report = new ModuleQuizPDFReport($conn, $mod_view_attempt);
        header('Content-Type: text/html; charset=UTF-8');
        echo $mod_view_report->generate_html(true);
    } catch (Throwable) {
        http_response_code(404);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Report</title></head><body style="font-family:Segoe UI,sans-serif;padding:24px;">';
        echo '<p>Report could not be loaded.</p><p><a href="?page=module_quiz_performance">Back to Module Quiz Performance</a></p></body></html>';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - IAP Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Segoe UI", Roboto, Arial, sans-serif;
        }

        body {
            background: #fbfcff;
            color: #374151;
            line-height: 1.6;
            overflow-x: hidden;
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: #ffffff;
            border-right: 1px solid #e5e7eb;
            padding: 20px;
            flex-shrink: 0;
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 1200;
            transition: transform 0.28s ease;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            z-index: 1150;
        }

        .mobile-sidebar-toggle {
            display: none;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #ffffff;
            color: #5b21b6;
            cursor: pointer;
            margin-right: 12px;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f3e8ff;
        }

        .sidebar-logo img {
            height: 80px;
            width: 80px;
            border-radius: 50%;
            object-fit: cover;
        }

        .sidebar h2 {
            color: #5b21b6;
            margin-bottom: 20px;
        }

        .sidebar ul {
            list-style: none;
        }

        .sidebar li {
            margin-bottom: 10px;
        }

        .sidebar a {
            text-decoration: none;
            color: #374151;
            padding: 10px;
            display: block;
            border-radius: 8px;
            transition: 0.3s;
        }

        .sidebar a:hover, .sidebar a.active {
            background: #f3e8ff;
            color: #5b21b6;
        }

        .main-content {
            flex: 1;
            padding: 40px;
            min-width: 0;
            overflow-x: hidden;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .header-left {
            display: flex;
            align-items: center;
        }

        .logout-btn {
            background: #7c3aed;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
        }

        .logout-btn:hover {
            background: #5b21b6;
        }

        .check-report-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
            color: #ffffff;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(124, 58, 237, 0.2);
            border: none;
            cursor: pointer;
        }

        .check-report-btn:hover {
            background: linear-gradient(135deg, #5b21b6 0%, #4c1d95 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(124, 58, 237, 0.3);
            color: #ffffff;
            text-decoration: none;
        }

        .check-report-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(124, 58, 237, 0.2);
        }

        .check-report-btn i {
            font-size: 12px;
        }

        /* Edit Student Button */
        .action-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(135deg, #3b82f6 0%, #1e40af 100%);
            color: #ffffff;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(59, 130, 246, 0.2);
            border: none;
            cursor: pointer;
        }

        .action-btn:hover {
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(59, 130, 246, 0.3);
            color: #ffffff;
            text-decoration: none;
        }

        .student-name-link {
            color: #1d4ed8;
            background: none;
            border: none;
            padding: 0;
            font-size: 14px;
            font-weight: 600;
            text-decoration: underline;
            cursor: pointer;
        }

        .student-name-link:hover {
            color: #1e3a8a;
        }

        /* Edit Student Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 1000;
            overflow-y: auto;
        }

        .modal-overlay.active {
            display: block;
        }

        .modal-content {
            background: #ffffff;
            margin: 5% auto;
            padding: 40px;
            width: 90%;
            max-width: 600px;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 15px;
        }

        .modal-header h2 {
            color: #5b21b6;
            margin: 0;
            font-size: 24px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 28px;
            cursor: pointer;
            color: #9ca3af;
            font-weight: bold;
            transition: color 0.3s;
        }

        .modal-close:hover {
            color: #374151;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #374151;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
        }

        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }

        .modal-buttons {
            display: flex;
            gap: 10px;
            margin-top: 30px;
        }

        .modal-buttons button {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-save {
            background: #10b981;
            color: #ffffff;
        }

        .btn-save:hover {
            background: #059669;
        }

        .btn-cancel {
            background: #e5e7eb;
            color: #374151;
        }

        .btn-cancel:hover {
            background: #d1d5db;
        }

        .form-error {
            display: none;
            margin-top: 6px;
            color: #dc2626;
            font-size: 13px;
            font-weight: 600;
        }

        .table-responsive {
            width: 100%;
            overflow-x: auto;
        }

        .registered-students-wrap {
            width: 100%;
            max-width: 100%;
            overflow: hidden;
        }

        .registered-students-wrap .table-responsive {
            max-width: 100%;
            overflow-x: auto;
            overflow-y: hidden;
            -webkit-overflow-scrolling: touch;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #ffffff;
            margin-bottom: 12px;
        }

        .registered-students-table {
            min-width: 1200px;
        }

        .registered-students-table th,
        .registered-students-table td {
            white-space: nowrap;
            padding: 12px 14px;
            vertical-align: middle;
        }

        .password-mask {
            letter-spacing: 1px;
            font-weight: 700;
            color: #6b7280;
        }

        .inline-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }

        .mini-btn {
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #374151;
            border-radius: 6px;
            padding: 6px 10px;
            font-size: 12px;
            cursor: pointer;
            transition: 0.2s ease;
        }

        .mini-btn:hover {
            background: #f3e8ff;
            border-color: #c4b5fd;
            color: #5b21b6;
        }

        .student-extra-row {
            display: none;
            background: #faf5ff;
        }

        .student-extra-row.active {
            display: table-row;
        }

        .student-extra-content {
            white-space: normal;
            padding: 14px;
            color: #374151;
        }

        .tab-nav {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .tab-btn {
            border: 1px solid #d1d5db;
            background: #f9fafb;
            color: #374151;
            padding: 10px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
        }

        .tab-btn.active {
            background: #7c3aed;
            color: #ffffff;
            border-color: #7c3aed;
        }

        .tab-panel {
            display: none;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
        }

        .tab-panel.active {
            display: block;
        }

        .section-title {
            color: #5b21b6;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
        }

        .btn {
            background: #7c3aed;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
        }

        .btn:hover {
            background: #5b21b6;
        }

        .message {
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #dc2626;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: #f3e8ff;
            color: #5b21b6;
            font-weight: 600;
        }

        tr:hover {
            background: #f9fafb;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin: 32px 0;
        }

        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #fafbfc 100%);
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-color: #7c3aed;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #7c3aed;
            background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
            flex-shrink: 0;
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Responsive adjustments */
        .psychometric-overview .overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
        }

        .psychometric-overview .overview-item {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        .psychometric-overview .overview-item h4 {
            margin: 0 0 12px 0;
            font-size: 16px;
            font-weight: 600;
        }

        .psychometric-overview .progress-bar {
            background: #e5e7eb;
            border-radius: 8px;
            height: 12px;
            margin-bottom: 8px;
        }

        .psychometric-overview .progress-fill {
            background: linear-gradient(90deg, #10b981, #059669);
            height: 100%;
            border-radius: 8px;
            transition: width 0.3s ease;
        }

        .psychometric-overview .engagement-stats {
            display: flex;
            gap: 16px;
        }

        .psychometric-overview .engagement-stats > div {
            text-align: center;
            flex: 1;
        }

        .english-quiz-filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
            align-items: end;
        }

        .english-quiz-actions {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .english-quiz-actions a {
            text-decoration: none;
        }

        .difficulty-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: capitalize;
        }

        .difficulty-badge.easy {
            background: #198754;
            color: #fff;
        }

        .difficulty-badge.medium {
            background: #ffc107;
            color: #212529;
        }

        .difficulty-badge.hard {
            background: #dc3545;
            color: #fff;
        }

        .table-english-quiz th,
        .table-english-quiz td {
            white-space: normal;
            vertical-align: middle;
        }

        @media (max-width: 991.98px) {
            .mobile-sidebar-toggle {
                display: inline-flex;
            }

            .sidebar {
                position: fixed;
                left: 0;
                top: 0;
                height: 100vh;
                width: 250px;
                transform: translateX(-100%);
                box-shadow: 0 12px 24px rgba(0, 0, 0, 0.2);
            }

            body.sidebar-open .sidebar {
                transform: translateX(0);
            }

            body.sidebar-open .sidebar-overlay {
                display: block;
            }

            .main-content {
                padding: 20px;
            }
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 16px;
                margin: 24px 0;
            }

            .stat-card {
                padding: 20px;
                gap: 14px;
            }

            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 20px;
            }

            .stat-number {
                font-size: 28px;
            }

            .stat-label {
                font-size: 13px;
            }

            .psychometric-overview .overview-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }

            .modal-content {
                margin: 10% auto;
                padding: 20px;
                width: 95%;
            }

            .main-content {
                padding: 20px;
            }

            th, td {
                padding: 10px;
                font-size: 13px;
                white-space: nowrap;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="sidebar-overlay" id="adminSidebarOverlay"></div>
        <div class="sidebar" id="adminSidebar">
            <div class="sidebar-logo">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <img src="../images/SA Main logo.jpg" alt="SA Main Logo" title="SA Main">
                    <div style="display: flex; flex-direction: column;">
                        <span style="font-size: 18px; font-weight: 700; color: #7c3aed; line-height: 1.2;">SPECANCIENS</span>
                        <span style="font-size: 14px; font-weight: 600; color: #6b7280; line-height: 1.2;">IAP Portal</span>
                    </div>
                </div>
            </div>
            <h2>Admin Panel</h2>
            <ul>
                <li><a href="?page=home" class="<?php echo $page == 'home' ? 'active' : ''; ?>">Home</a></li>
                <li><a href="?page=create_session" class="<?php echo $page == 'create_session' ? 'active' : ''; ?>">Create Session</a></li>
                <li><a href="?page=requests" class="<?php echo $page == 'requests' ? 'active' : ''; ?>">View Session Requests</a></li>
                <li><a href="?page=registered_students" class="<?php echo $page == 'registered_students' ? 'active' : ''; ?>">View Registered Students</a></li>
                <li><a href="?page=session_wise_registrations" class="<?php echo $page == 'session_wise_registrations' ? 'active' : ''; ?>">View Registered Sessions</a></li>
                <li><a href="?page=module_quiz_performance" class="<?php echo $page == 'module_quiz_performance' ? 'active' : ''; ?>">Module Quiz Performance</a></li>
                <li><a href="?page=english_quiz_performance" class="<?php echo $page == 'english_quiz_performance' ? 'active' : ''; ?>">English Quiz Performance</a></li>
                <li><a href="?page=psychometric_status" class="<?php echo $page == 'psychometric_status' ? 'active' : ''; ?>">Check Psychometric Status</a></li>
                <li><a href="?page=manage_psychometric_questions" class="<?php echo $page == 'manage_psychometric_questions' ? 'active' : ''; ?>">Add Ques in Psychometric Quiz</a></li>
                <li><a href="phonetics_progress.php">Phonetics Progress</a></li>
            </ul>
        </div>

        <div class="main-content">
            <div class="header">
                <div class="header-left">
                    <button type="button" class="mobile-sidebar-toggle" id="adminSidebarToggle" aria-label="Toggle sidebar" aria-controls="adminSidebar" aria-expanded="false">
                        <i class="fas fa-bars"></i>
                    </button>
                    <h1>Dashboard</h1>
                </div>
                <a href="../logout.php" class="logout-btn">Logout</a>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo strpos($message, 'successfully') !== false ? 'success' : 'error'; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($page == 'home'): ?>
                <h2 class="section-title">Welcome to Admin Dashboard</h2>
                <p>Use the sidebar to navigate to different sections.</p>

                <?php
                // Fetch statistics
                $total_students = 0;
                $total_sessions = 0;
                $total_registrations = 0;
                $total_quizzes = 0; // Placeholder - implement when quiz table is available

                $students_result = $conn->query("SELECT COUNT(*) as count FROM iap_students");
                if ($students_result) {
                    $total_students = $students_result->fetch_assoc()['count'] ?? 0;
                }

                $sessions_result = $conn->query("SELECT COUNT(*) as count FROM iap_sessions");
                if ($sessions_result) {
                    $total_sessions = $sessions_result->fetch_assoc()['count'] ?? 0;
                }

                // Assuming iap_session_registrations table exists for registrations
                $registrations_result = $conn->query("SELECT COUNT(*) as count FROM iap_session_registrations");
                if ($registrations_result) {
                    $total_registrations = $registrations_result->fetch_assoc()['count'] ?? 0;
                }

                // Fetch psychometric statistics
                $psychometric_completed = 0;
                $psychometric_pending = 0;
                $avg_psychometric_score = 0;

                $psychometric_completed_result = $conn->query("SELECT COUNT(*) as count FROM iap_psychometric_scores");
                if ($psychometric_completed_result) {
                    $psychometric_completed = $psychometric_completed_result->fetch_assoc()['count'] ?? 0;
                }
                $psychometric_pending = $total_students - $psychometric_completed;

                if ($psychometric_completed > 0) {
                    $avg_result = $conn->query("SELECT AVG(score) as avg_score FROM iap_psychometric_scores");
                    if ($avg_result) {
                        $avg_score_data = $avg_result->fetch_assoc();
                        $avg_psychometric_score = round($avg_score_data['avg_score'], 1);
                    }
                }

                $psychometric_completion_rate = $total_students > 0 ? round(($psychometric_completed / $total_students) * 100, 1) : 0;

                // Fetch session request statistics
                $pending_requests = 0;
                $approved_requests = 0;
                $rejected_requests = 0;

                $pending_requests_result = $conn->query("SELECT COUNT(*) as count FROM iap_session_suggestions WHERE status = 'pending'");
                if ($pending_requests_result) {
                    $pending_requests = $pending_requests_result->fetch_assoc()['count'] ?? 0;
                }

                $approved_requests_result = $conn->query("SELECT COUNT(*) as count FROM iap_session_suggestions WHERE status = 'approved'");
                if ($approved_requests_result) {
                    $approved_requests = $approved_requests_result->fetch_assoc()['count'] ?? 0;
                }

                $rejected_requests_result = $conn->query("SELECT COUNT(*) as count FROM iap_session_suggestions WHERE status = 'rejected'");
                if ($rejected_requests_result) {
                    $rejected_requests = $rejected_requests_result->fetch_assoc()['count'] ?? 0;
                }
                ?>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_students); ?></div>
                            <div class="stat-label">Total Students Registered</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_sessions); ?></div>
                            <div class="stat-label">Total Sessions Created</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_registrations); ?></div>
                            <div class="stat-label">Total Session Registrations</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_quizzes); ?></div>
                            <div class="stat-label">Total Quizzes Taken</div>
                        </div>
                    </div>

                    <!-- Session Request Statistics -->
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #fef3c7, #fde68a); color: #f59e0b;">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($pending_requests); ?></div>
                            <div class="stat-label">Pending Session Requests</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #10b981;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($approved_requests); ?></div>
                            <div class="stat-label">Approved Session Requests</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #fee2e2, #fecaca); color: #ef4444;">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($rejected_requests); ?></div>
                            <div class="stat-label">Rejected Session Requests</div>
                        </div>
                    </div>

                    <!-- Psychometric Test Statistics -->
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <i class="fas fa-brain"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($psychometric_completed); ?></div>
                            <div class="stat-label">Completed Psychometric Tests</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($psychometric_pending); ?></div>
                            <div class="stat-label">Pending Psychometric Tests</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $avg_psychometric_score > 0 ? $avg_psychometric_score . '%' : 'N/A'; ?></div>
                            <div class="stat-label">Average Psychometric Score</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $psychometric_completion_rate; ?>%</div>
                            <div class="stat-label">Test Completion Rate</div>
                        </div>
                    </div>
                </div>

                <!-- Psychometric Overview Section -->
                <div class="psychometric-overview" style="margin-top: 40px; background: white; border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h3 style="color: #5b21b6; margin-bottom: 20px;"><i class="fas fa-chart-pie"></i> Psychometric Assessment Overview</h3>

                    <div class="overview-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
                        <div class="overview-item">
                            <h4 style="color: #059669; margin-bottom: 12px;"><i class="fas fa-check-circle"></i> Assessment Status</h4>
                            <div class="progress-bar" style="background: #e5e7eb; border-radius: 8px; height: 12px; margin-bottom: 8px;">
                                <div class="progress-fill" style="background: linear-gradient(90deg, #10b981, #059669); height: 100%; border-radius: 8px; width: <?php echo $psychometric_completion_rate; ?>%;"></div>
                            </div>
                            <p style="margin: 0; font-size: 14px; color: #6b7280;"><?php echo $psychometric_completed; ?> of <?php echo $total_students; ?> students completed (<?php echo $psychometric_completion_rate; ?>%)</p>
                        </div>

                        <div class="overview-item">
                            <h4 style="color: #7c3aed; margin-bottom: 12px;"><i class="fas fa-trophy"></i> Performance Insights</h4>
                            <?php if ($avg_psychometric_score > 0): ?>
                                <p style="margin: 0 0 8px 0; font-size: 16px; font-weight: 600; color: #5b21b6;">Average Score: <?php echo $avg_psychometric_score; ?>%</p>
                                <p style="margin: 0; font-size: 14px; color: #6b7280;">
                                    <?php
                                    if ($avg_psychometric_score >= 80) echo "Excellent performance! Students show strong analytical and organizational skills.";
                                    elseif ($avg_psychometric_score >= 60) echo "Good performance! Solid analytical and organizational abilities demonstrated.";
                                    elseif ($avg_psychometric_score >= 40) echo "Moderate performance. Room for development in analytical skills.";
                                    else echo "Areas for improvement in analytical and organizational skills.";
                                    ?>
                                </p>
                            <?php else: ?>
                                <p style="margin: 0; font-size: 14px; color: #6b7280;">No assessments completed yet.</p>
                            <?php endif; ?>
                        </div>

                        <div class="overview-item">
                            <h4 style="color: #f59e0b; margin-bottom: 12px;"><i class="fas fa-users"></i> Student Engagement</h4>
                            <div class="engagement-stats" style="display: flex; gap: 16px;">
                                <div style="text-align: center;">
                                    <div style="font-size: 24px; font-weight: 700; color: #059669;"><?php echo $psychometric_completed; ?></div>
                                    <div style="font-size: 12px; color: #6b7280;">Completed</div>
                                </div>
                                <div style="text-align: center;">
                                    <div style="font-size: 24px; font-weight: 700; color: #f59e0b;"><?php echo $psychometric_pending; ?></div>
                                    <div style="font-size: 12px; color: #6b7280;">Pending</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($page == 'create_session'): ?>
                <h2 class="section-title">Create New Session</h2>
                <div style="background: #f0f9ff; border-left: 4px solid #0ea5e9; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <p style="margin: 0; color: #0369a1; font-size: 14px;">
                        <i class="fas fa-info-circle"></i> <strong>Session Code:</strong> Enter custom code (e.g., CS101-Introduction to Programming) or leave blank for auto-generation (YYSNNN format)
                    </p>
                </div>
                <form method="post" action="">
                    <div class="form-group">
                        <label for="topic">Session Topic/Name: <span style="color: #ef4444;">*</span></label>
                        <input type="text" id="topic" name="topic" placeholder="e.g., Introduction to Engineering Careers" required>
                    </div>
                    <div class="form-group">
                        <label for="manual_session_code">Session Code (Optional): <span style="color: #6b7280; font-weight: 400; font-size: 13px;">Format: ALPHANUMERIC-SESSIONNAME</span></label>
                        <input type="text" id="manual_session_code" name="manual_session_code" placeholder="e.g., CS101-Introduction to Programming" pattern="^[A-Z0-9]+-[A-Za-z0-9\s]+$" title="Format: ALPHANUMERIC-SESSIONNAME (e.g., CS101-Introduction)">
                        <small style="color: #6b7280; display: block; margin-top: 5px;">
                            <i class="fas fa-lightbulb"></i> Leave blank to auto-generate code in YYSNNN format
                        </small>
                    </div>
                    <div class="form-group">
                        <label for="year">Academic Year: <span style="color: #ef4444;">*</span></label>
                        <select id="year" name="year" required>
                            <option value="">-- Select Year --</option>
                            <option value="1">Year 1</option>
                            <option value="2">Year 2</option>
                            <option value="3">Year 3</option>
                            <option value="4">Year 4</option>
                            <option value="Graduate">Graduate</option>
                        </select>
                    </div>
                    <button type="submit" name="create_session" class="btn">
                        <i class="fas fa-plus"></i> Create Session
                    </button>
                </form>

            <?php elseif ($page == 'requests'): ?>
                <h2 class="section-title">Session Requests & Suggestions</h2>
                
                <!-- Pending Requests Section -->
                <div style="margin-bottom: 50px;">
                    <h3 style="color: #f59e0b; margin-bottom: 20px; padding: 15px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-radius: 12px; border-left: 4px solid #f59e0b;">
                        <i class="fas fa-clock"></i> Pending Session Requests
                    </h3>
                    <?php if ($pending_result && $pending_result->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Roll Number</th>
                                    <th>Year</th>
                                    <th>Branch</th>
                                    <th>Section</th>
                                    <th>Session Desired</th>
                                    <th>Other Query</th>
                                    <th>Submitted At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $pending_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['roll_number']); ?></td>
                                        <td>Year <?php echo htmlspecialchars($row['year']); ?></td>
                                        <td><?php echo htmlspecialchars($row['branch']); ?></td>
                                        <td><?php echo htmlspecialchars($row['section']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['session_desired']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['other_query'] ?: 'N/A'); ?></td>
                                        <td><?php echo date('M j, Y H:i', strtotime($row['submitted_at'])); ?></td>
                                        <td>
                                            <div style="display: flex; gap: 8px;">
                                                <form method="post" action="" style="display: inline;">
                                                    <input type="hidden" name="request_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="approve_request" style="background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; transition: all 0.3s;" onmouseover="this.style.background='#059669'" onmouseout="this.style.background='#10b981'">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                </form>
                                                <form method="post" action="" style="display: inline;">
                                                    <input type="hidden" name="request_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="reject_request" style="background: #ef4444; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; transition: all 0.3s;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="no-data">No pending session requests.</p>
                    <?php endif; ?>
                </div>

                <!-- Approved Requests Section -->
                <div style="margin-bottom: 50px;">
                    <h3 style="color: #10b981; margin-bottom: 20px; padding: 15px; background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-radius: 12px; border-left: 4px solid #10b981;">
                        <i class="fas fa-check-circle"></i> Approved Session Requests
                    </h3>
                    <?php if ($approved_result && $approved_result->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Roll Number</th>
                                    <th>Year</th>
                                    <th>Branch</th>
                                    <th>Section</th>
                                    <th>Session Desired</th>
                                    <th>Other Query</th>
                                    <th>Submitted At</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $approved_result->fetch_assoc()): ?>
                                    <tr style="background: #f0fdf4;">
                                        <td><?php echo $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['roll_number']); ?></td>
                                        <td>Year <?php echo htmlspecialchars($row['year']); ?></td>
                                        <td><?php echo htmlspecialchars($row['branch']); ?></td>
                                        <td><?php echo htmlspecialchars($row['section']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['session_desired']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['other_query'] ?: 'N/A'); ?></td>
                                        <td><?php echo date('M j, Y H:i', strtotime($row['submitted_at'])); ?></td>
                                        <td>
                                            <span style="padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; background: #d1fae5; color: #065f46;">
                                                <i class="fas fa-check-circle"></i> Approved
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="no-data">No approved session requests yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Rejected Requests Section -->
                <div style="margin-bottom: 50px;">
                    <h3 style="color: #ef4444; margin-bottom: 20px; padding: 15px; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-radius: 12px; border-left: 4px solid #ef4444;">
                        <i class="fas fa-times-circle"></i> Rejected Session Requests
                    </h3>
                    <?php if ($rejected_result && $rejected_result->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Roll Number</th>
                                    <th>Year</th>
                                    <th>Branch</th>
                                    <th>Section</th>
                                    <th>Session Desired</th>
                                    <th>Other Query</th>
                                    <th>Submitted At</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $rejected_result->fetch_assoc()): ?>
                                    <tr style="background: #fef2f2;">
                                        <td><?php echo $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['roll_number']); ?></td>
                                        <td>Year <?php echo htmlspecialchars($row['year']); ?></td>
                                        <td><?php echo htmlspecialchars($row['branch']); ?></td>
                                        <td><?php echo htmlspecialchars($row['section']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['session_desired']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['other_query'] ?: 'N/A'); ?></td>
                                        <td><?php echo date('M j, Y H:i', strtotime($row['submitted_at'])); ?></td>
                                        <td>
                                            <span style="padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; background: #fee2e2; color: #991b1b;">
                                                <i class="fas fa-times-circle"></i> Rejected
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="no-data">No rejected session requests.</p>
                    <?php endif; ?>
                </div>

            <?php elseif ($page == 'registered_students'): ?>
                <h2 class="section-title">Registered Students via Student Portal</h2>
                <?php if ($registered_students_result && $registered_students_result->num_rows > 0): ?>
                    <div class="registered-students-wrap">
                    <div class="table-responsive">
                    <table class="registered-students-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Roll Number</th>
                                <th>Department</th>
                                <th>Year</th>
                                <th>Password</th>
                                <th>Session Count</th>
                                <th>Psychometric Score</th>
                                <th>Assessment Status</th>
                                <th>More</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $registered_students_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['id']); ?></td>
                                    <td>
                                        <button type="button" class="student-name-link edit-student-btn"
                                            data-id="<?php echo htmlspecialchars($row['id']); ?>"
                                            data-full_name="<?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>"
                                            data-email="<?php echo htmlspecialchars($row['email'], ENT_QUOTES); ?>"
                                            data-roll_number="<?php echo htmlspecialchars($row['roll_number'], ENT_QUOTES); ?>"
                                            data-department="<?php echo htmlspecialchars($row['department'], ENT_QUOTES); ?>"
                                            data-year="<?php echo htmlspecialchars($row['year'], ENT_QUOTES); ?>">
                                            <?php echo htmlspecialchars($row['full_name']); ?>
                                        </button>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['roll_number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                                    <td><?php echo htmlspecialchars($row['year']) === 'Graduate' ? 'Graduate' : 'Year ' . htmlspecialchars($row['year']); ?></td>
                                    <td>
                                        <div class="inline-actions">
                                            <span class="password-mask">********</span>
                                            <button type="button" class="mini-btn view-password-btn">View Password</button>
                                        </div>
                                    </td>
                                    <td><strong><?php echo $row['sessions_count'] ?? 0; ?></strong></td>
                                    <td>
                                        <span style="background: <?php echo $row['iap_psychometric_score'] > 0 ? '#dcfce7' : '#f3f4f6'; ?>; padding: 4px 8px; border-radius: 4px; font-weight: 600; color: <?php echo $row['iap_psychometric_score'] > 0 ? '#166534' : '#6b7280'; ?>;">
                                            <?php echo $row['iap_psychometric_score'] > 0 ? round($row['iap_psychometric_score'], 1) . '%' : 'N/A'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="background: <?php echo $row['assessment_status'] == 'Completed' ? '#dcfce7' : '#fef3c7'; ?>; padding: 4px 8px; border-radius: 4px; font-weight: 600; color: <?php echo $row['assessment_status'] == 'Completed' ? '#166534' : '#92400e'; ?>;">
                                            <?php echo $row['assessment_status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="mini-btn toggle-student-details-btn">View Details</button>
                                    </td>
                                    <td>
                                        <div class="inline-actions">
                                            <button type="button" class="action-btn edit-student-btn" 
                                                data-id="<?php echo htmlspecialchars($row['id']); ?>"
                                                data-full_name="<?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>"
                                                data-email="<?php echo htmlspecialchars($row['email'], ENT_QUOTES); ?>"
                                                data-roll_number="<?php echo htmlspecialchars($row['roll_number'], ENT_QUOTES); ?>"
                                                data-department="<?php echo htmlspecialchars($row['department'], ENT_QUOTES); ?>"
                                                data-year="<?php echo htmlspecialchars($row['year'], ENT_QUOTES); ?>">
                                                Edit
                                            </button>
                                            <button type="button" class="mini-btn reset-password-btn">Reset Password</button>
                                        </div>
                                    </td>
                                </tr>
                                <tr class="student-extra-row">
                                    <td colspan="12" class="student-extra-content">
                                        <strong>Registered Sessions:</strong>
                                        <?php echo $row['registered_sessions'] ? htmlspecialchars($row['registered_sessions']) : 'None'; ?>
                                        &nbsp; | &nbsp;
                                        <strong>Quizzes Taken:</strong>
                                        <?php echo rand(0, 5); ?>
                                        &nbsp; | &nbsp;
                                        <strong>Modules Completed:</strong>
                                        <?php echo rand(0, 3); ?>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    </div>
                    </div>
                <?php else: ?>
                    <p class="no-data">No students registered yet through the student portal.</p>
                <?php endif; ?>

                <!-- Edit Student Modal -->
                <div id="editStudentModal" class="modal-overlay" aria-hidden="true">
                    <div class="modal-content" role="dialog" aria-modal="true" aria-labelledby="editStudentTitle">
                        <div class="modal-header">
                            <h2 id="editStudentTitle">Edit Student</h2>
                            <button type="button" class="modal-close" id="closeEditStudentModal" aria-label="Close">&times;</button>
                        </div>
                        <form method="POST" id="editStudentForm" action="?page=registered_students">
                            <input type="hidden" name="update_student" value="1">
                            <input type="hidden" name="student_id" id="editStudentId">

                            <div class="form-group">
                                <label for="editFullName">Name</label>
                                <input type="text" id="editFullName" name="full_name" minlength="2" required>
                            </div>

                            <div class="form-group">
                                <label for="editRollNumber">Roll Number</label>
                                <input type="text" id="editRollNumber" name="roll_number" maxlength="10" required>
                                <small id="rollNumberError" class="form-error"></small>
                            </div>

                            <div class="form-group">
                                <label for="editYear">Year</label>
                                <select id="editYear" name="year" required>
                                    <option value="">-- Select Year --</option>
                                    <option value="1">Year 1</option>
                                    <option value="2">Year 2</option>
                                    <option value="3">Year 3</option>
                                    <option value="4">Year 4</option>
                                    <option value="Graduate">Graduate</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="editDepartment">Department</label>
                                <select id="editDepartment" name="department" required>
                                    <option value="">-- Select Department --</option>
                                    <option value="Computer Science">CSE</option>
                                    <option value="Electronics">ECE</option>
                                    <option value="Mechanical">Mechanical</option>
                                    <option value="Electrical">EEE</option>
                                    <option value="Civil">Civil</option>
                                    <option value="AIML">AIML</option>
                                    <option value="Cybersecurity">Cybersecurity</option>
                                    <option value="Data Science">Data Science</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="editEmail">Email</label>
                                <input type="email" id="editEmail" name="email" required>
                            </div>

                            <div class="form-group">
                                <label for="editNewPassword">New Password (optional)</label>
                                <input type="password" id="editNewPassword" name="new_password" minlength="8" placeholder="Leave blank to keep current password">
                            </div>

                            <div class="modal-buttons">
                                <button type="submit" class="btn-save">Save Changes</button>
                                <button type="button" class="btn-cancel" id="cancelEditStudent">Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>
            <?php elseif ($page == 'session_wise_registrations'): ?>
                <h2 class="section-title">View Registered Sessions</h2>
                <div class="card mb-3">
                    <div class="card-body">
                        <form method="GET" action="" style="display:grid; grid-template-columns: 1fr 1fr auto; gap: 12px; align-items:end;">
                            <input type="hidden" name="page" value="session_wise_registrations">
                            <div>
                                <label for="year_filter"><strong>Year Filter</strong></label>
                                <select id="year_filter" name="year_filter" class="form-control">
                                    <option value="">All Years</option>
                                    <option value="1" <?php echo $registration_year_filter === '1' ? 'selected' : ''; ?>>1</option>
                                    <option value="2" <?php echo $registration_year_filter === '2' ? 'selected' : ''; ?>>2</option>
                                    <option value="3" <?php echo $registration_year_filter === '3' ? 'selected' : ''; ?>>3</option>
                                    <option value="4" <?php echo $registration_year_filter === '4' ? 'selected' : ''; ?>>4</option>
                                    <option value="Graduate" <?php echo $registration_year_filter === 'Graduate' ? 'selected' : ''; ?>>Graduate</option>
                                </select>
                            </div>
                            <div>
                                <label for="sort"><strong>Sort Option</strong></label>
                                <select id="sort" name="sort" class="form-control">
                                    <option value="latest" <?php echo $registration_sort === 'latest' ? 'selected' : ''; ?>>Latest registrations first</option>
                                    <option value="oldest" <?php echo $registration_sort === 'oldest' ? 'selected' : ''; ?>>Oldest registrations first</option>
                                </select>
                            </div>
                            <div>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (!empty($session_registration_rows)): ?>
                    <div class="card mb-3">
                        <div class="card-header">
                            <strong>All Candidate Registrations</strong>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Session Code</th>
                                            <th>Session Title</th>
                                            <th>Session Year</th>
                                            <th>Student Name</th>
                                            <th>Roll Number</th>
                                            <th>Year</th>
                                            <th>Department</th>
                                            <th>Email</th>
                                            <th>Session Desired</th>
                                            <th>Query</th>
                                            <th>Quiz Access</th>
                                            <th>Action</th>
                                            <th>Registration Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($session_registration_rows as $student_item): ?>
                                            <tr>
                                                <td><span style="background: #f3e8ff; color: #5b21b6; padding: 4px 8px; border-radius: 4px; font-weight: 600; font-size: 12px;"><?php echo htmlspecialchars($student_item['session_code'] ?? 'N/A'); ?></span></td>
                                                <td><?php echo htmlspecialchars($student_item['session_title']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['session_year']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['student_name']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['roll_number']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['year']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['department']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['email']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['session_desired']); ?></td>
                                                <td><?php echo !empty($student_item['other_query']) ? htmlspecialchars($student_item['other_query']) : 'N/A'; ?></td>
                                                <td>
                                                    <?php
                                                    $approval = $student_item['approval_status'] ?? 'N/A';
                                                    $badge_bg = '#f3f4f6';
                                                    $badge_color = '#6b7280';
                                                    if ($approval === 'approved') { $badge_bg = '#dcfce7'; $badge_color = '#166534'; }
                                                    if ($approval === 'pending') { $badge_bg = '#fef3c7'; $badge_color = '#92400e'; }
                                                    if ($approval === 'rejected') { $badge_bg = '#fee2e2'; $badge_color = '#991b1b'; }
                                                    ?>
                                                    <span style="background: <?php echo $badge_bg; ?>; color: <?php echo $badge_color; ?>; padding: 4px 8px; border-radius: 4px; font-weight: 600;">
                                                        <?php echo htmlspecialchars(ucfirst((string)$approval)); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if (!empty($student_item['student_id']) && !empty($student_item['session_id'])): ?>
                                                        <form method="POST" action="?page=session_wise_registrations" style="display:flex; gap:6px; align-items:center;">
                                                            <input type="hidden" name="update_module_approval" value="1">
                                                            <input type="hidden" name="student_id" value="<?php echo (int)$student_item['student_id']; ?>">
                                                            <input type="hidden" name="session_id" value="<?php echo (int)$student_item['session_id']; ?>">
                                                            <select name="approval_status" class="form-control" style="min-width:120px; padding:6px 8px;">
                                                                <option value="pending" <?php echo ($approval === 'pending') ? 'selected' : ''; ?>>Pending</option>
                                                                <option value="approved" <?php echo ($approval === 'approved') ? 'selected' : ''; ?>>Approved</option>
                                                                <option value="rejected" <?php echo ($approval === 'rejected') ? 'selected' : ''; ?>>Rejected</option>
                                                            </select>
                                                            <button type="submit" class="mini-btn">Save</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span style="color:#9ca3af;">N/A</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo !empty($student_item['registration_date']) ? htmlspecialchars(date('M j, Y g:i A', strtotime($student_item['registration_date']))) : 'N/A'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="no-data">No registrations found.</p>
                <?php endif; ?>
            <?php elseif ($page == 'english_quiz_performance'): ?>
                <?php
                $english_perf_difficulty = trim((string)($_GET['english_difficulty'] ?? ''));
                $english_perf_student_name = trim((string)($_GET['english_student_name'] ?? ''));
                $english_perf_roll = trim((string)($_GET['english_roll_number'] ?? ''));
                $english_perf_topic = trim((string)($_GET['english_topic'] ?? ''));
                $english_topic_options = [];
                $english_topic_q = $conn->query("SELECT DISTINCT topic FROM english_quiz_answers ORDER BY topic ASC");
                if ($english_topic_q) {
                    while ($english_topic_row = $english_topic_q->fetch_assoc()) {
                        if (!empty($english_topic_row['topic'])) {
                            $english_topic_options[] = $english_topic_row['topic'];
                        }
                    }
                }

                $english_perf_sql = "SELECT
                    eqr.id,
                    eqr.attempt_id,
                    st.full_name,
                    st.roll_number,
                    eqr.difficulty,
                    eqr.score,
                    eqr.percentage,
                    eqr.total_questions,
                    eqr.correct_answers,
                    eqr.attempt_date AS attempted_at,
                    eqr.topics_covered
                 FROM english_quiz_results eqr
                 INNER JOIN iap_students st ON st.id = eqr.student_id
                 WHERE 1=1";

                $params = [];
                $types = '';

                if ($english_perf_difficulty !== '' && in_array($english_perf_difficulty, ['easy', 'medium', 'hard'], true)) {
                    $english_perf_sql .= " AND eqr.difficulty = ?";
                    $params[] = $english_perf_difficulty;
                    $types .= 's';
                }

                if ($english_perf_topic !== '') {
                    $english_perf_sql .= " AND EXISTS (SELECT 1 FROM english_quiz_answers eqa WHERE eqa.attempt_id = eqr.attempt_id AND eqa.topic = ?)";
                    $params[] = $english_perf_topic;
                    $types .= 's';
                }

                if ($english_perf_student_name !== '') {
                    $english_perf_sql .= " AND st.full_name LIKE ?";
                    $params[] = '%' . $english_perf_student_name . '%';
                    $types .= 's';
                }

                if ($english_perf_roll !== '') {
                    $english_perf_sql .= " AND st.roll_number LIKE ?";
                    $params[] = '%' . $english_perf_roll . '%';
                    $types .= 's';
                }

                $english_perf_sql .= " ORDER BY eqr.attempt_date DESC";

                $english_perf_result = false;
                $english_perf_stmt = $conn->prepare($english_perf_sql);
                if ($english_perf_stmt) {
                    if ($types !== '') {
                        $english_perf_stmt->bind_param($types, ...$params);
                    }
                    if ($english_perf_stmt->execute()) {
                        $english_perf_result = $english_perf_stmt->get_result();
                    }
                    $english_perf_stmt->close();
                }
                ?>

                <h2 class="section-title">English Quiz Performance</h2>
                <div class="card mb-3">
                    <div class="card-body">
                        <form method="GET" action="" class="english-quiz-filters">
                            <input type="hidden" name="page" value="english_quiz_performance">
                            <div>
                                <label for="english_difficulty"><strong>Difficulty</strong></label>
                                <select id="english_difficulty" name="english_difficulty" class="form-control" onchange="this.form.submit();">
                                    <option value="">All Levels</option>
                                    <option value="easy" <?php echo ($english_perf_difficulty === 'easy') ? 'selected' : ''; ?>>Easy</option>
                                    <option value="medium" <?php echo ($english_perf_difficulty === 'medium') ? 'selected' : ''; ?>>Medium</option>
                                    <option value="hard" <?php echo ($english_perf_difficulty === 'hard') ? 'selected' : ''; ?>>Hard</option>
                                </select>
                            </div>
                            <div>
                                <label for="english_topic"><strong>Module / Topic</strong></label>
                                <select id="english_topic" name="english_topic" class="form-control" onchange="this.form.submit();">
                                    <option value="">All Topics</option>
                                    <?php foreach ($english_topic_options as $top): ?>
                                        <option value="<?php echo htmlspecialchars($top, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($english_perf_topic === $top) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($top, ENT_QUOTES, 'UTF-8'); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="english_student_name"><strong>Student Name</strong></label>
                                <input type="text" id="english_student_name" name="english_student_name" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($english_perf_student_name); ?>">
                            </div>
                            <div>
                                <label for="english_roll_number"><strong>Roll Number</strong></label>
                                <input type="text" id="english_roll_number" name="english_roll_number" class="form-control" placeholder="Search..." value="<?php echo htmlspecialchars($english_perf_roll); ?>">
                            </div>
                            <div style="display:flex; flex-direction:column; gap:8px;">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply filters</button>
                                <a class="btn btn-light" style="text-align:center; border:1px solid #dee2e6; border-radius:4px; padding:8px; color:white;" href="?page=english_quiz_performance">Reset</a>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($english_perf_result && $english_perf_result->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-english-quiz">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Roll Number</th>
                                    <th>Difficulty Level</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                    <th>Attempt Date</th>
                                    <th style="min-width:200px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($eperf = $english_perf_result->fetch_assoc()): ?>
                                    <?php
                                    $dkey = strtolower((string)($eperf['difficulty'] ?? ''));
                                    if (!in_array($dkey, ['easy', 'medium', 'hard'], true)) {
                                        $dkey = 'medium';
                                    }
                                    $attempt_id_row = (int)($eperf['attempt_id'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)($eperf['full_name'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars((string)($eperf['roll_number'] ?? '')); ?></td>
                                        <td>
                                            <span class="difficulty-badge <?php echo htmlspecialchars($dkey); ?>">
                                                <?php echo htmlspecialchars(ucfirst($dkey)); ?>
                                            </span>
                                        </td>
                                        <td><strong><?php echo (int)($eperf['score'] ?? 0); ?> / <?php echo (int)($eperf['total_questions'] ?? 0); ?></strong></td>
                                        <td><strong><?php echo htmlspecialchars(number_format((float)($eperf['percentage'] ?? 0), 2)); ?>%</strong></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)($eperf['attempted_at'] ?? 'now')))); ?></td>
                                        <td>
                                            <div class="english-quiz-actions">
                                                <a class="btn btn-sm btn-secondary" href="?page=english_quiz_performance&amp;view_english_quiz_report=1&amp;attempt_id=<?php echo $attempt_id_row; ?>" target="_blank" rel="noopener" title="View detailed report">
                                                    <i class="fas fa-eye"></i> View Report
                                                </a>
                                                <a class="btn btn-sm btn-info" href="?page=english_quiz_performance&amp;download_english_quiz_report=1&amp;attempt_id=<?php echo $attempt_id_row; ?>" target="_blank" rel="noopener" title="Download PDF (or HTML fallback)">
                                                    <i class="fas fa-file-pdf"></i> Download PDF
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-data">No English quiz attempts found for the selected filters.</p>
                <?php endif; ?>
            <?php elseif ($page == 'module_quiz_performance'): ?>
                <h2 class="section-title">Module Quiz Performance</h2>
                <p class="text-muted" style="margin-bottom:16px;">Analytics for session/module MCQ attempts. Reports include question-level review and weak-topic hints.</p>
                <div class="card mb-3">
                    <div class="card-body">
                        <form method="GET" action="" class="english-quiz-filters" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items:end;">
                            <input type="hidden" name="page" value="module_quiz_performance">
                            <div>
                                <label for="quiz_year"><strong>Year</strong></label>
                                <select id="quiz_year" name="quiz_year" class="form-control">
                                    <option value="">All</option>
                                    <option value="1" <?php echo $quiz_perf_year === '1' ? 'selected' : ''; ?>>1</option>
                                    <option value="2" <?php echo $quiz_perf_year === '2' ? 'selected' : ''; ?>>2</option>
                                    <option value="3" <?php echo $quiz_perf_year === '3' ? 'selected' : ''; ?>>3</option>
                                    <option value="4" <?php echo $quiz_perf_year === '4' ? 'selected' : ''; ?>>4</option>
                                    <option value="Graduate" <?php echo $quiz_perf_year === 'Graduate' ? 'selected' : ''; ?>>Graduate</option>
                                </select>
                            </div>
                            <div>
                                <label for="quiz_session_id"><strong>Module</strong></label>
                                <select id="quiz_session_id" name="quiz_session_id" class="form-control">
                                    <option value="0">All Modules</option>
                                    <?php foreach ($quiz_perf_sessions as $sess): ?>
                                        <option value="<?php echo (int)$sess['id']; ?>" <?php echo ($quiz_perf_session === (int)$sess['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars(($sess['session_code'] ? $sess['session_code'] . ' - ' : '') . $sess['title']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="quiz_student_name"><strong>Student Name</strong></label>
                                <input type="text" id="quiz_student_name" name="quiz_student_name" class="form-control" value="<?php echo htmlspecialchars($quiz_perf_student_name); ?>">
                            </div>
                            <div>
                                <label for="quiz_roll_number"><strong>Roll Number</strong></label>
                                <input type="text" id="quiz_roll_number" name="quiz_roll_number" class="form-control" value="<?php echo htmlspecialchars($quiz_perf_roll); ?>">
                            </div>
                            <div>
                                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Filter</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (isset($quiz_performance_result) && $quiz_performance_result && $quiz_performance_result->num_rows > 0): ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-english-quiz">
                            <thead>
                                <tr>
                                    <th>Student Name</th>
                                    <th>Roll Number</th>
                                    <th>Academic Year</th>
                                    <th>Module</th>
                                    <th>Catalog Year</th>
                                    <th>Score</th>
                                    <th>Percentage</th>
                                    <th>Total Q</th>
                                    <th>Correct</th>
                                    <th>Wrong</th>
                                    <th>Attempt Date</th>
                                    <th style="min-width:200px;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while ($prow = $quiz_performance_result->fetch_assoc()): ?>
                                    <?php $aid = (int)($prow['attempt_id'] ?? 0); ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($prow['full_name']); ?></td>
                                        <td><?php echo htmlspecialchars($prow['roll_number']); ?></td>
                                        <td><?php echo htmlspecialchars((string)($prow['student_academic_year'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars(($prow['session_code'] ? $prow['session_code'] . ' - ' : '') . $prow['module_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string)($prow['module_year'] ?? '')); ?></td>
                                        <td><?php echo htmlspecialchars($prow['score'] . '/' . $prow['total_questions']); ?></td>
                                        <td><?php echo htmlspecialchars(number_format((float)$prow['percentage'], 2)); ?>%</td>
                                        <td><?php echo (int)$prow['total_questions']; ?></td>
                                        <td><?php echo (int)$prow['correct_answers']; ?></td>
                                        <td><?php echo (int)($prow['wrong_answers'] ?? max(0, (int)$prow['total_questions'] - (int)$prow['correct_answers'])); ?></td>
                                        <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($prow['attempted_at']))); ?></td>
                                        <td>
                                            <div class="english-quiz-actions">
                                                <a class="btn btn-sm btn-secondary" href="?page=module_quiz_performance&amp;view_module_quiz_report=1&amp;attempt_id=<?php echo $aid; ?>" target="_blank" rel="noopener">View Report</a>
                                                <a class="btn btn-sm btn-info" href="?page=module_quiz_performance&amp;download_module_quiz_report=1&amp;attempt_id=<?php echo $aid; ?>" target="_blank" rel="noopener">Download PDF</a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="no-data">No quiz attempts found for selected filters.</p>
                <?php endif; ?>
            <?php elseif ($page == 'manage_psychometric_questions'): ?>
                <h2 class="section-title">Manage Psychometric Questions</h2>
                <p style="margin-bottom: 16px; color: #6b7280;">Add questions manually or upload `.csv` / `.xlsx` files in strict format.</p>

                <?php if (!empty($psychometric_upload_summary)): ?>
                    <div class="message success"><?php echo htmlspecialchars($psychometric_upload_summary); ?></div>
                <?php endif; ?>

                <div class="tab-nav" id="psychometricTabs">
                    <button type="button" class="tab-btn active" data-tab="manual-entry-panel">Manual Entry</button>
                    <button type="button" class="tab-btn" data-tab="excel-upload-panel">Upload Excel / CSV</button>
                </div>

                <div id="manual-entry-panel" class="tab-panel active">
                    <h3 style="margin-bottom: 14px; color: #374151;">Manual Question Entry</h3>
                    <form method="POST" id="manualQuestionForm" action="?page=manage_psychometric_questions">
                        <input type="hidden" name="add_psychometric_question" value="1">

                        <div class="form-group">
                            <label for="manualQuestionText">Question Text</label>
                            <textarea id="manualQuestionText" name="question" rows="3" style="width:100%; border:1px solid #e5e7eb; border-radius:8px; padding:10px;" required></textarea>
                        </div>
                        <div class="form-group">
                            <label for="manualOptionA">Option A</label>
                            <input type="text" id="manualOptionA" name="option_a" required>
                        </div>
                        <div class="form-group">
                            <label for="manualOptionB">Option B</label>
                            <input type="text" id="manualOptionB" name="option_b" required>
                        </div>
                        <div class="form-group">
                            <label for="manualOptionC">Option C</label>
                            <input type="text" id="manualOptionC" name="option_c" required>
                        </div>
                        <div class="form-group">
                            <label for="manualOptionD">Option D</label>
                            <input type="text" id="manualOptionD" name="option_d" required>
                        </div>
                        <div class="form-group">
                            <label for="manualCorrectAnswer">Correct Answer</label>
                            <select id="manualCorrectAnswer" name="correct_answer" required>
                                <option value="">-- Select Correct Answer --</option>
                                <option value="A">A</option>
                                <option value="B">B</option>
                                <option value="C">C</option>
                                <option value="D">D</option>
                            </select>
                        </div>
                        <button type="submit" class="btn"><i class="fas fa-plus-circle"></i> Save Question</button>
                    </form>
                </div>

                <div id="excel-upload-panel" class="tab-panel">
                    <h3 style="margin-bottom: 14px; color: #374151;">Upload Questions from Excel/CSV</h3>
                    <p style="font-size: 14px; color: #6b7280; margin-bottom: 8px;">
                        Strict header required:
                        <strong>Question | Option A | Option B | Option C | Option D | Correct Answer</strong>
                    </p>
                    <form method="POST" action="?page=manage_psychometric_questions" enctype="multipart/form-data">
                        <input type="hidden" name="upload_psychometric_file" value="1">
                        <div class="form-group">
                            <label for="psychometricFile">Choose File (.csv or .xlsx, max 2MB)</label>
                            <input type="file" id="psychometricFile" name="psychometric_file" accept=".csv,.xlsx" required>
                        </div>
                        <button type="submit" class="btn"><i class="fas fa-upload"></i> Upload File</button>
                    </form>
                </div>

                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px;">
                    <h3 style="margin-bottom: 14px; color: #374151;">Latest Questions</h3>
                    <?php if ($psychometric_questions_result && $psychometric_questions_result->num_rows > 0): ?>
                        <div class="table-responsive">
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Question</th>
                                        <th>A</th>
                                        <th>B</th>
                                        <th>C</th>
                                        <th>D</th>
                                        <th>Correct</th>
                                        <th>Created</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php while ($q_row = $psychometric_questions_result->fetch_assoc()): ?>
                                        <tr>
                                            <td><?php echo (int)$q_row['id']; ?></td>
                                            <td><?php echo htmlspecialchars($q_row['question']); ?></td>
                                            <td><?php echo htmlspecialchars($q_row['option_a']); ?></td>
                                            <td><?php echo htmlspecialchars($q_row['option_b']); ?></td>
                                            <td><?php echo htmlspecialchars($q_row['option_c']); ?></td>
                                            <td><?php echo htmlspecialchars($q_row['option_d']); ?></td>
                                            <td><strong><?php echo htmlspecialchars($q_row['correct_answer']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($q_row['created_at']); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <p class="no-data">No psychometric questions added yet.</p>
                    <?php endif; ?>
                </div>
            <?php elseif ($page == 'psychometric_status'): ?>
                <h2>Psychometric Assessment Status</h2>

                <!-- Students Who Have Completed the Assessment -->
                <h3 style="color: #28a745; margin-top: 40px;"><i class="fa fa-check-circle"></i> Students Who Have Completed Assessment</h3>
                <?php if ($completed_result && $completed_result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Roll Number</th>
                                <th>Department</th>
                                <th>Year</th>
                                <th>Score</th>
                                <th>Completed Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $completed_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['roll_number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                                    <td>Year <?php echo htmlspecialchars($row['year']); ?></td>
                                    <td><strong style="color: #28a745;"><?php echo round($row['score'], 1); ?>%</strong></td>
                                    <td><?php echo date('M j, Y H:i', strtotime($row['completed_at'])); ?></td>
                                    <td>
                                        <a href="psychometric_report.php?student_id=<?php echo $row['id']; ?>" class="check-report-btn">
                                            <i class="fa fa-file-text"></i> Check Report
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="no-data">No students have completed the psychometric assessment yet.</p>
                <?php endif; ?>

                <!-- Students Who Haven't Taken the Assessment -->
                <h3 style="color: #dc3545; margin-top: 40px;"><i class="fa fa-times-circle"></i> Students Who Haven't Taken Assessment</h3>
                <?php if ($pending_result && $pending_result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Roll Number</th>
                                <th>Department</th>
                                <th>Year</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $pending_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['roll_number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                                    <td>Year <?php echo htmlspecialchars($row['year']); ?></td>
                                    <td><span style="color: #dc3545; font-weight: bold;">Not Taken</span></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="no-data">All students have completed the psychometric assessment!</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($page == 'manage_psychometric_questions'): ?>
    <script>
        (function () {
            const tabButtons = document.querySelectorAll('#psychometricTabs .tab-btn');
            const panels = document.querySelectorAll('.tab-panel');
            const manualForm = document.getElementById('manualQuestionForm');
            const uploadInput = document.getElementById('psychometricFile');

            tabButtons.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    tabButtons.forEach(function (b) { b.classList.remove('active'); });
                    panels.forEach(function (p) { p.classList.remove('active'); });
                    btn.classList.add('active');
                    const target = document.getElementById(btn.dataset.tab);
                    if (target) {
                        target.classList.add('active');
                    }
                });
            });

            if (manualForm) {
                const questionPattern = /^[a-zA-Z0-9\s\?\!\,\.\-\(\)']{10,}$/;
                const optionPattern = /^.{1,}$/;
                const answerPattern = /^[ABCD]$/;

                manualForm.addEventListener('submit', function (event) {
                    const question = (document.getElementById('manualQuestionText').value || '').trim();
                    const a = (document.getElementById('manualOptionA').value || '').trim();
                    const b = (document.getElementById('manualOptionB').value || '').trim();
                    const c = (document.getElementById('manualOptionC').value || '').trim();
                    const d = (document.getElementById('manualOptionD').value || '').trim();
                    const answer = (document.getElementById('manualCorrectAnswer').value || '').trim();

                    if (!questionPattern.test(question) || !optionPattern.test(a) || !optionPattern.test(b) || !optionPattern.test(c) || !optionPattern.test(d) || !answerPattern.test(answer)) {
                        event.preventDefault();
                        alert('Validation failed. Check question, options, and correct answer format.');
                    }
                });
            }

            if (uploadInput) {
                uploadInput.addEventListener('change', function () {
                    const file = uploadInput.files[0];
                    if (!file) {
                        return;
                    }
                    const name = (file.name || '').toLowerCase();
                    const validExt = name.endsWith('.csv') || name.endsWith('.xlsx');
                    const maxSize = 2 * 1024 * 1024;
                    if (!validExt) {
                        alert('Only .csv and .xlsx files are allowed.');
                        uploadInput.value = '';
                        return;
                    }
                    if (file.size > maxSize) {
                        alert('File size must be 2MB or less.');
                        uploadInput.value = '';
                    }
                });
            }
        })();
    </script>
    <?php endif; ?>

    <?php if ($page == 'registered_students'): ?>
    <script src="../Student/roll_validation.js"></script>
    <script>
        (function () {
            const modal = document.getElementById('editStudentModal');
            const form = document.getElementById('editStudentForm');
            const closeBtn = document.getElementById('closeEditStudentModal');
            const cancelBtn = document.getElementById('cancelEditStudent');
            const editButtons = document.querySelectorAll('.edit-student-btn');
            const idField = document.getElementById('editStudentId');
            const nameField = document.getElementById('editFullName');
            const emailField = document.getElementById('editEmail');
            const rollField = document.getElementById('editRollNumber');
            const deptField = document.getElementById('editDepartment');
            const yearField = document.getElementById('editYear');
            const newPasswordField = document.getElementById('editNewPassword');

            if (!modal || !form) {
                return;
            }

            function closeModal() {
                modal.classList.remove('active');
                modal.setAttribute('aria-hidden', 'true');
            }

            function openModal() {
                modal.classList.add('active');
                modal.setAttribute('aria-hidden', 'false');
            }

            function fillFormFromButton(button) {
                idField.value = button.dataset.id || '';
                nameField.value = button.dataset.full_name || '';
                emailField.value = button.dataset.email || '';
                rollField.value = button.dataset.roll_number || '';
                deptField.value = button.dataset.department || '';
                yearField.value = button.dataset.year || '';
                if (newPasswordField) {
                    newPasswordField.value = '';
                }
            }

            editButtons.forEach(function (button) {
                button.addEventListener('click', function () {
                    fillFormFromButton(button);
                    openModal();
                });
            });

            closeBtn.addEventListener('click', closeModal);
            cancelBtn.addEventListener('click', closeModal);

            modal.addEventListener('click', function (event) {
                if (event.target === modal) {
                    closeModal();
                }
            });

            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    closeModal();
                }
            });

            attachRollNumberValidation(form, 'editRollNumber', 'rollNumberError');

            document.querySelectorAll('.view-password-btn').forEach(function (button) {
                button.addEventListener('click', function () {
                    alert('Password cannot be viewed. You can reset it.');
                });
            });

            document.querySelectorAll('.toggle-student-details-btn').forEach(function (button) {
                button.addEventListener('click', function () {
                    const currentRow = button.closest('tr');
                    const detailsRow = currentRow ? currentRow.nextElementSibling : null;
                    if (!detailsRow || !detailsRow.classList.contains('student-extra-row')) {
                        return;
                    }
                    const isExpanded = detailsRow.classList.contains('active');
                    detailsRow.classList.toggle('active');
                    button.textContent = isExpanded ? 'View Details' : 'Hide Details';
                });
            });

            document.querySelectorAll('.reset-password-btn').forEach(function (button) {
                button.addEventListener('click', function () {
                    const currentRow = button.closest('tr');
                    const editButton = currentRow ? currentRow.querySelector('.edit-student-btn') : null;
                    if (!editButton) {
                        return;
                    }
                    fillFormFromButton(editButton);
                    openModal();
                    if (newPasswordField) {
                        newPasswordField.focus();
                    }
                });
            });
        })();
    </script>
    <?php endif; ?>

    <script>
        (function () {
            const toggleBtn = document.getElementById('adminSidebarToggle');
            const sidebar = document.getElementById('adminSidebar');
            const overlay = document.getElementById('adminSidebarOverlay');
            const mq = window.matchMedia('(max-width: 991.98px)');
            if (!toggleBtn || !sidebar || !overlay) return;

            function closeSidebar() {
                document.body.classList.remove('sidebar-open');
                toggleBtn.setAttribute('aria-expanded', 'false');
            }

            toggleBtn.addEventListener('click', function () {
                const isOpen = document.body.classList.toggle('sidebar-open');
                toggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            });

            overlay.addEventListener('click', closeSidebar);

            sidebar.querySelectorAll('a').forEach(function (link) {
                link.addEventListener('click', function () {
                    if (mq.matches) {
                        closeSidebar();
                    }
                });
            });

            window.addEventListener('resize', function () {
                if (!mq.matches) {
                    closeSidebar();
                }
            });
        })();
    </script>

    <?php if ($password_popup_message !== ''): ?>
    <script>
        alert(<?php echo json_encode($password_popup_message); ?>);
    </script>
    <?php endif; ?>

    <?php if ($page == 'module_quiz_performance'): ?>
    <script>
        /**
         * Dynamic Module Filter for Module Quiz Performance
         * Loads modules based on selected academic year
         */
        (function() {
            const yearSelect = document.getElementById('quiz_year');
            const moduleSelect = document.getElementById('quiz_session_id');
            const currentModuleId = <?php echo json_encode($quiz_perf_session); ?>;

            if (!yearSelect || !moduleSelect) {
                console.error('Module Quiz Performance: Required elements not found');
                return;
            }

            console.log('Module Quiz Performance dynamic filter initialized');
            console.log('Current module ID:', currentModuleId);

            // Function to load sessions for selected year
            function loadSessionsByYear(year) {
                console.log('Loading sessions for year:', year);

                // Show loading state
                const originalOptions = moduleSelect.innerHTML;
                moduleSelect.innerHTML = '<option value="0">Loading modules...</option>';
                moduleSelect.disabled = true;

                // Build absolute URL for AJAX endpoint
                const baseUrl = window.location.pathname.substring(0, window.location.pathname.lastIndexOf('/Admin/'));
                const apiUrl = baseUrl + '/Admin/get_sessions_by_year.php?year=' + encodeURIComponent(year);
                console.log('Fetching from:', apiUrl);

                // Fetch sessions from API
                fetch(apiUrl)
                    .then(response => {
                        console.log('Response status:', response.status);
                        if (!response.ok) {
                            throw new Error('Network response was not ok: ' + response.status);
                        }
                        return response.json();
                    })
                    .then(data => {
                        console.log('Sessions loaded:', data);

                        if (data.success && data.sessions && data.sessions.length > 0) {
                            // Clear and rebuild module dropdown
                            moduleSelect.innerHTML = '<option value="0">All Modules</option>';

                            // Add sessions to dropdown
                            data.sessions.forEach(session => {
                                const option = document.createElement('option');
                                option.value = session.id;
                                option.textContent = session.display;

                                // Re-select if it was previously selected
                                if (currentModuleId === session.id) {
                                    option.selected = true;
                                    console.log('Re-selected module:', session.display);
                                }

                                moduleSelect.appendChild(option);
                            });

                            console.log('✓ Loaded ' + data.count + ' modules for year: ' + year);
                        } else if (data.success && (!data.sessions || data.sessions.length === 0)) {
                            moduleSelect.innerHTML = '<option value="0">No modules available for this year</option>';
                            console.log('No modules found for year:', year);
                        } else {
                            moduleSelect.innerHTML = '<option value="0">Error loading modules</option>';
                            console.error('API returned error:', data);
                        }

                        moduleSelect.disabled = false;
                    })
                    .catch(error => {
                        console.error('Error loading sessions:', error);
                        moduleSelect.innerHTML = originalOptions;
                        moduleSelect.disabled = false;
                        console.error('Restored original options due to error');
                    });
            }

            // Load sessions when year changes
            yearSelect.addEventListener('change', function() {
                console.log('Year changed to:', this.value);
                loadSessionsByYear(this.value);
            });

            // Load initial sessions on page load if year is selected
            if (yearSelect.value) {
                console.log('Initial load for year:', yearSelect.value);
                // Use setTimeout to ensure DOM is ready
                setTimeout(() => {
                    loadSessionsByYear(yearSelect.value);
                }, 100);
            } else {
                console.log('No year selected on initial load');
            }

            console.log('Module Quiz Performance dynamic filter ready');
        })();
    </script>
    <?php endif; ?>

</body>
</html>
<?php
$conn->close();
?>
