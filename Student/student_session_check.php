<?php
/**
 * Student Session Protection
 * This file should be included at the top of all student-only pages
 * It validates that a student is logged in before allowing access
 */

session_start();

// Check if student is logged in
if (!isset($_SESSION['student_id']) || !isset($_SESSION['roll_number'])) {
    // Redirect to student login if not authenticated
    header("Location: student_login.php");
    exit();
}

// Database connection credentials
$servername = "localhost";
$db_username = "root";
$db_password = ""; // XAMPP default root password is usually empty
$database = "iap_portal";

// Create connection
$conn = new mysqli($servername, $db_username, $db_password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Set charset to utf8
$conn->set_charset("utf8");

// Verify that the student still exists in the database (security check)
$sql = "SELECT id, roll_number, full_name FROM IAP_students WHERE id = ? AND roll_number = ?";
$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Database error: " . $conn->error);
}

$stmt->bind_param("is", $_SESSION['student_id'], $_SESSION['roll_number']);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    // Student record not found, invalidate session
    session_destroy();
    header("Location: student_login.php?error=Session expired");
    exit();
}

$stmt->close();
// Connection $conn is available for use in the calling page
?>
