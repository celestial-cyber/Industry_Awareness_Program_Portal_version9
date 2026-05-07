<?php
/**
 * Session Registration Handler
 * Registers logged-in students into sessions with year restrictions.
 */
header('Content-Type: application/json');
session_start();

if (!isset($_SESSION['student_id']) || !isset($_SESSION['year'])) {
    echo json_encode(['status' => 'error', 'message' => 'You must be logged in as a student.']);
    exit();
}

if (!isset($_POST['session_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Session ID not provided']);
    exit();
}

$session_id = intval($_POST['session_id']);
$student_id = intval($_SESSION['student_id']);
$student_year = (string)($_SESSION['year'] ?? '');

if ($session_id <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid session selected.']);
    exit();
}

require_once __DIR__ . '/db.php';

// Ensure tracking columns and uniqueness exist.
$col_session_year = $conn->query("SHOW COLUMNS FROM iap_student_sessions LIKE 'session_year'");
if ($col_session_year && $col_session_year->num_rows === 0) {
    $conn->query("ALTER TABLE iap_student_sessions ADD COLUMN session_year VARCHAR(20) NULL AFTER session_id");
}
$col_registration_status = $conn->query("SHOW COLUMNS FROM iap_student_sessions LIKE 'registration_status'");
if ($col_registration_status && $col_registration_status->num_rows === 0) {
    $conn->query("ALTER TABLE iap_student_sessions ADD COLUMN registration_status ENUM('registered','completed','dropped') DEFAULT 'registered' AFTER session_year");
}
$unique_check = $conn->query("SHOW INDEX FROM iap_student_sessions WHERE Key_name = 'unique_student_session'");
if ($unique_check && $unique_check->num_rows === 0) {
    $conn->query("ALTER TABLE iap_student_sessions ADD UNIQUE KEY unique_student_session (student_id, session_id)");
}

$session_title_column = 'topic';
$title_col_check = $conn->query("SHOW COLUMNS FROM iap_sessions LIKE 'title'");
if ($title_col_check && $title_col_check->num_rows > 0) {
    $session_title_column = 'title';
}

$session_sql = "SELECT id, {$session_title_column} AS session_title, year FROM iap_sessions WHERE id = ?";
$session_stmt = $conn->prepare($session_sql);
$session_stmt->bind_param("i", $session_id);
$session_stmt->execute();
$session_result = $session_stmt->get_result();

if ($session_result->num_rows === 0) {
    $session_stmt->close();
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Session not found']);
    exit();
}

$session = $session_result->fetch_assoc();
$session_stmt->close();

$session_year = (string)$session['year'];

// Year rule: Graduate can register to any year; others can only their own year.
$allowed = ($student_year === 'Graduate') || ($student_year === $session_year);
if (!$allowed) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'You can only register for sessions of your academic year']);
    exit();
}

$insert_sql = "INSERT INTO iap_student_sessions (student_id, session_id, session_year, registration_status) VALUES (?, ?, ?, 'registered')";
$insert_stmt = $conn->prepare($insert_sql);
if (!$insert_stmt) {
    $conn->close();
    echo json_encode(['status' => 'error', 'message' => 'Database error while preparing registration.']);
    exit();
}

$insert_stmt->bind_param("iis", $student_id, $session_id, $session_year);
if ($insert_stmt->execute()) {
    $insert_stmt->close();
    $conn->close();
    echo json_encode(['status' => 'success', 'message' => 'Successfully registered for the session.']);
    exit();
}

$insert_stmt->close();
$conn->close();
echo json_encode(['status' => 'error', 'message' => 'Already registered for this session or unable to register.']);
exit();
?>
