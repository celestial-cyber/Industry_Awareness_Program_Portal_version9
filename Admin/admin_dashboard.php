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

require_once __DIR__ . '/../config/db.php';

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

$sql = "CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic VARCHAR(255) NOT NULL,
    year ENUM('1', '2', '3', '4') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";
$conn->query($sql);

// Add structured session code column (idempotent migration).
$session_code_column_check = $conn->query("SHOW COLUMNS FROM sessions LIKE 'session_code'");
if ($session_code_column_check && $session_code_column_check->num_rows === 0) {
    $conn->query("ALTER TABLE sessions ADD COLUMN session_code VARCHAR(20) NULL AFTER id");
}
$session_code_unique_check = $conn->query("SHOW INDEX FROM sessions WHERE Key_name = 'uq_sessions_session_code'");
if ($session_code_unique_check && $session_code_unique_check->num_rows === 0) {
    $conn->query("ALTER TABLE sessions ADD UNIQUE KEY uq_sessions_session_code (session_code)");
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
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
);";
$conn->query($sql);

// Table for managing psychometric quiz questions.
$sql = "CREATE TABLE IF NOT EXISTS psychometric_questions (
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
$index_check = $conn->query("SHOW INDEX FROM psychometric_questions WHERE Key_name = 'uq_psychometric_question'");
if ($index_check && $index_check->num_rows === 0) {
    $conn->query("ALTER TABLE psychometric_questions ADD UNIQUE KEY uq_psychometric_question (question(255))");
}

// Insert default admin if not exists
$sql = 'INSERT IGNORE INTO iap_users_details (username, email, password, role) VALUES (\'admin\', \'admin@example.com\', \'$2y$10$xHDNFM0xYFstLYe.BIHMUu4ZxCcEeKOQ3psUy85ZcbsCqdbWUy2Z.\', \'admin\')';
$conn->query($sql);

$message = '';
$page = isset($_GET['page']) ? $_GET['page'] : 'home';
$valid_years = ['1', '2', '3', '4', 'Graduate'];
$valid_departments = ['Computer Science', 'Electronics', 'Mechanical', 'Electrical', 'Civil', 'AIML', 'Cybersecurity', 'Data Science', 'Other'];
$psychometric_upload_summary = '';
$session_wise_registrations = [];
$session_registration_rows = [];
$session_title_column = 'topic';
$registration_year_filter = '';
$registration_sort = 'latest';

$title_col_check = $conn->query("SHOW COLUMNS FROM sessions LIKE 'title'");
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

    $sql = "SELECT session_code FROM sessions
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
    $query = "SELECT id, year FROM sessions WHERE session_code IS NULL OR session_code = '' ORDER BY year ASC, id ASC";
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
            $count_sql = "SELECT COUNT(*) AS cnt FROM sessions WHERE session_code LIKE CONCAT(?, '%')";
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

        $upd = $conn->prepare("UPDATE sessions SET session_code = ? WHERE id = ?");
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
    $topic = $_POST['topic'];
    $year = $_POST['year'];

    $new_session_code = generate_next_session_code($conn, (string)$year);
    $sql = "INSERT INTO sessions (session_code, topic, year) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $new_session_code, $topic, $year);
    if ($stmt->execute()) {
        $message = "Session created successfully! Code: " . htmlspecialchars($new_session_code);
    } else {
        $message = "Error: " . $conn->error;
    }
    $stmt->close();
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

// Handle admin student update action from the registered students section.
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['update_student'])) {
    $student_id = intval($_POST['student_id'] ?? 0);
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $roll_number = strtoupper(trim($_POST['roll_number'] ?? ''));
    $department = trim($_POST['department'] ?? '');
    $year = trim($_POST['year'] ?? '');

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
        $sql = "UPDATE iap_students SET full_name = ?, email = ?, roll_number = ?, department = ?, year = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("sssssi", $full_name, $email, $roll_number, $department, $year, $student_id);
            if ($stmt->execute()) {
                $message = 'Student record updated successfully.';
            } else {
                $message = 'Error updating student: ' . $conn->error;
            }
            $stmt->close();
        } else {
            $message = 'Database error: ' . $conn->error;
        }
    } else {
        $message = implode('<br>', $errors);
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
        $insert_sql = "INSERT INTO psychometric_questions (question, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, ?, ?, ?, ?, ?)";
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
                            $insert_sql = "INSERT INTO psychometric_questions (question, option_a, option_b, option_c, option_d, correct_answer) VALUES (?, ?, ?, ?, ?, ?)";
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
                COUNT(DISTINCT ss.session_id) as sessions_count,
                GROUP_CONCAT(DISTINCT CONCAT(COALESCE(sess.session_code, ''), CASE WHEN sess.session_code IS NULL OR sess.session_code = '' THEN '' ELSE ' - ' END, sess.{$session_title_column}) SEPARATOR ', ') as registered_sessions,
                COALESCE(ps.score, 0) as iap_psychometric_score,
                CASE WHEN ps.score IS NOT NULL THEN 'Completed' ELSE 'Not Taken' END as assessment_status
            FROM iap_students s
            LEFT JOIN iap_student_sessions ss ON s.id = ss.student_id
            LEFT JOIN sessions sess ON ss.session_id = sess.id
            LEFT JOIN iap_psychometric_scores ps ON s.id = ps.student_id
            GROUP BY s.id
            ORDER BY s.created_at DESC";
    $registered_students_result = $conn->query($sql);
    if (!$registered_students_result) {
        // Fallback query if the join fails
        $sql = "SELECT id, full_name, email, roll_number, department, year FROM iap_students ORDER BY created_at DESC";
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
                    CONCAT(COALESCE(s.session_code, ''), CASE WHEN s.session_code IS NULL OR s.session_code = '' THEN '' ELSE ' - ' END, s.{$session_title_column}) AS session_title,
                    s.year AS session_year,
                    st.full_name AS student_name,
                    st.roll_number,
                    st.department,
                    st.email,
                    ss.{$registration_date_column} AS registration_date
                 FROM iap_student_sessions ss
                 INNER JOIN iap_students st ON st.id = ss.student_id
                 INNER JOIN sessions s ON s.id = ss.session_id";

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
            'roll_number' => $row['roll_number'],
            'year' => $row['session_year'],
            'department' => $row['department'],
            'email' => $row['email'],
            'session_desired' => $row['session_title'],
            'other_query' => '',
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
            'other_query' => '',
            'registration_date' => $row['registration_date']
        ];
        $session_wise_registrations[$group_key]['total_registered']++;
    }
    $stmt->close();

    // Re-index for foreach rendering.
    $session_wise_registrations = array_values($session_wise_registrations);
} else if ($page == 'manage_psychometric_questions') {
    $psychometric_questions_result = $conn->query("SELECT id, question, option_a, option_b, option_c, option_d, correct_answer, created_at FROM psychometric_questions ORDER BY id DESC LIMIT 50");
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
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
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
        <div class="sidebar">
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
                <li><a href="?page=psychometric_status" class="<?php echo $page == 'psychometric_status' ? 'active' : ''; ?>">Check Psychometric Status</a></li>
                <li><a href="?page=manage_psychometric_questions" class="<?php echo $page == 'manage_psychometric_questions' ? 'active' : ''; ?>">Add Ques in Psychometric Quiz</a></li>
            </ul>
        </div>

        <div class="main-content">
            <div class="header">
                <h1>Dashboard</h1>
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

                $sessions_result = $conn->query("SELECT COUNT(*) as count FROM sessions");
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
                <form method="post" action="">
                    <div class="form-group">
                        <label for="topic">Topic:</label>
                        <input type="text" id="topic" name="topic" required>
                    </div>
                    <div class="form-group">
                        <label for="year">Year:</label>
                        <select id="year" name="year" required>
                            <option value="1">Year 1</option>
                            <option value="2">Year 2</option>
                            <option value="3">Year 3</option>
                            <option value="4">Year 4</option>
                        </select>
                    </div>
                    <button type="submit" name="create_session" class="btn">Create Session</button>
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
                    <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Roll Number</th>
                                <th>Department</th>
                                <th>Year</th>
                                <th>Session Count</th>
                                <th>Registered Sessions</th>
                                <th>Psychometric Score</th>
                                <th>Assessment Status</th>
                                <th>Quizzes Taken</th>
                                <th>Modules Completed</th>
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
                                    <td><strong><?php echo $row['sessions_count'] ?? 0; ?></strong></td>
                                    <td>
                                        <small><?php
                                            echo $row['registered_sessions'] ? htmlspecialchars($row['registered_sessions']) : '<em>None</em>';
                                        ?></small>
                                    </td>
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
                                        <!-- Dummy value: Random quiz count between 0-5 -->
                                        <span style="background: #e0f7e0; padding: 4px 8px; border-radius: 4px; font-weight: 600; color: #15803d;">
                                            <?php echo rand(0, 5); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <!-- Dummy value: Random module count between 0-3 -->
                                        <span style="background: #e0e7ff; padding: 4px 8px; border-radius: 4px; font-weight: 600; color: #1e3a8a;">
                                            <?php echo rand(0, 3); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <button type="button" class="action-btn edit-student-btn" 
                                            data-id="<?php echo htmlspecialchars($row['id']); ?>"
                                            data-full_name="<?php echo htmlspecialchars($row['full_name'], ENT_QUOTES); ?>"
                                            data-email="<?php echo htmlspecialchars($row['email'], ENT_QUOTES); ?>"
                                            data-roll_number="<?php echo htmlspecialchars($row['roll_number'], ENT_QUOTES); ?>"
                                            data-department="<?php echo htmlspecialchars($row['department'], ENT_QUOTES); ?>"
                                            data-year="<?php echo htmlspecialchars($row['year'], ENT_QUOTES); ?>">
                                            Edit
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
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
                                            <th>Session Title</th>
                                            <th>Session Year</th>
                                            <th>Student Name</th>
                                            <th>Roll Number</th>
                                            <th>Year</th>
                                            <th>Department</th>
                                            <th>Email</th>
                                            <th>Session Desired</th>
                                            <th>Query</th>
                                            <th>Registration Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($session_registration_rows as $student_item): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($student_item['session_title']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['session_year']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['student_name']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['roll_number']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['year']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['department']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['email']); ?></td>
                                                <td><?php echo htmlspecialchars($student_item['session_desired']); ?></td>
                                                <td><?php echo !empty($student_item['other_query']) ? htmlspecialchars($student_item['other_query']) : 'N/A'; ?></td>
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
        })();
    </script>
    <?php endif; ?>
</body>
</html>
<?php
$conn->close();
?>
