<?php
/**
 * Main Login Router
 * Redirects to appropriate login page based on user type
 */

session_start();

// If already logged in, redirect to appropriate dashboard
if (isset($_SESSION['student_id'])) {
    header("Location: Student/student_dashboard.php");
    exit();
}

if (isset($_SESSION['admin_id'])) {
    header("Location: Admin/admin_dashboard.php");
    exit();
}

// Check if user is trying to access a specific login type
$login_type = $_GET['type'] ?? 'student';

if ($login_type === 'admin') {
    header("Location: Admin/admin_login.php");
    exit();
} else {
    header("Location: Student/student_login.php");
    exit();
}
?>
