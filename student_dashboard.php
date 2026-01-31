<?php
/**
 * Student Dashboard
 * Displays personalized dashboard with registered sessions
 * Shows only sessions the student is registered for
 * Includes "Take Quiz" button for each session
 */

// Include session protection - must be at the top
require_once 'Student/student_session_check.php';

$error_message = '';
$registered_sessions = [];

try {
    // Fetch student's registered sessions using MySQLi prepared statement
    $sql = "SELECT 
                s.id,
                s.topic as title,
                s.year,
                '' as description,
                ss.registration_status,
                ss.registered_at
            FROM sessions s
            JOIN iap_student_sessions ss ON s.id = ss.session_id
            WHERE ss.student_id = ?
            ORDER BY s.year ASC, s.topic ASC";
    
    $stmt = $conn->prepare($sql);
    
    if (!$stmt) {
        $error_message = "Database error: " . $conn->error;
    } else {
        $stmt->bind_param("i", $_SESSION['student_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $registered_sessions[] = $row;
        }
        
        $stmt->close();
    }
} catch (Exception $e) {
    $error_message = "Error fetching sessions: " . $e->getMessage();
}

// Group sessions by year
$sessions_by_year = [];
foreach ($registered_sessions as $session) {
    $year = $session['year'];
    if (!isset($sessions_by_year[$year])) {
        $sessions_by_year[$year] = [];
    }
    $sessions_by_year[$year][] = $session;
}

// Logout function
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: Student/student_login.php");
    exit();
}

// Handle profile update request
$profile_message = '';
$profile_message_type = '';

if (isset($_POST['update_profile'])) {
    $full_name = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $roll_number = trim($_POST['roll_number'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $year = $_POST['year'] ?? '';
    $phone = trim($_POST['phone'] ?? '');
    $current_password = $_POST['current_password'] ?? '';

    // Validate input
    if (empty($full_name) || empty($email) || empty($roll_number) || empty($department) || empty($year) || empty($current_password)) {
        $profile_message = 'All required fields must be filled.';
        $profile_message_type = 'danger';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $profile_message = 'Please enter a valid email address.';
        $profile_message_type = 'danger';
    } elseif (!in_array($year, ['1', '2', '3', '4'])) {
        $profile_message = 'Please select a valid year.';
        $profile_message_type = 'danger';
    } else {
        try {
            // Verify current password
            $sql = "SELECT password FROM IAP_students WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $_SESSION['student_id']);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $student = $result->fetch_assoc();

                if (password_verify($current_password, $student['password'])) {
                    // Check if roll number is already taken by another student
                    $roll_check_sql = "SELECT id FROM students WHERE roll_number = ? AND id != ?";
                    $roll_check_stmt = $conn->prepare($roll_check_sql);
                    $roll_check_stmt->bind_param("si", $roll_number, $_SESSION['student_id']);
                    $roll_check_stmt->execute();
                    $roll_check_result = $roll_check_stmt->get_result();

                    if ($roll_check_result->num_rows > 0) {
                        $profile_message = 'This roll number is already taken by another student.';
                        $profile_message_type = 'danger';
                    } else {
                        // Check if email is already taken by another student
                        $email_check_sql = "SELECT id FROM IAP_students WHERE email = ? AND id != ?";
                        $email_check_stmt = $conn->prepare($email_check_sql);
                        $email_check_stmt->bind_param("si", $email, $_SESSION['student_id']);
                        $email_check_stmt->execute();
                        $email_check_result = $email_check_stmt->get_result();

                        if ($email_check_result->num_rows > 0) {
                            $profile_message = 'This email address is already registered to another student.';
                            $profile_message_type = 'danger';
                        } else {
                            // Update student profile
                            $update_sql = "UPDATE IAP_students SET full_name = ?, email = ?, roll_number = ?, department = ?, year = ? WHERE id = ?";
                            $update_stmt = $conn->prepare($update_sql);
                            $update_stmt->bind_param("sssssi", $full_name, $email, $roll_number, $department, $year, $_SESSION['student_id']);

                            if ($update_stmt->execute()) {
                                // Update session variables to reflect changes immediately
                                $_SESSION['full_name'] = $full_name;
                                $_SESSION['email'] = $email;
                                $_SESSION['roll_number'] = $roll_number;
                                $_SESSION['department'] = $department;
                                $_SESSION['year'] = $year;

                                $profile_message = 'Profile updated successfully!';
                                $profile_message_type = 'success';
                            } else {
                                $profile_message = 'Failed to update profile. Please try again.';
                                $profile_message_type = 'danger';
                            }

                            $update_stmt->close();
                        }
                        $email_check_stmt->close();
                    }
                    $roll_check_stmt->close();
                } else {
                    $profile_message = 'Current password is incorrect.';
                    $profile_message_type = 'danger';
                }
            } else {
                $profile_message = 'Student record not found.';
                $profile_message_type = 'danger';
            }

            $stmt->close();
        } catch (Exception $e) {
            $profile_message = 'An error occurred: ' . $e->getMessage();
            $profile_message_type = 'danger';
        }
    }
}

// Handle password reset request
$reset_message = '';
$reset_message_type = '';

if (isset($_POST['reset_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate input
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $reset_message = 'All fields are required.';
        $reset_message_type = 'danger';
    } elseif ($new_password !== $confirm_password) {
        $reset_message = 'New passwords do not match.';
        $reset_message_type = 'danger';
    } elseif (strlen($new_password) < 8) {
        $reset_message = 'New password must be at least 8 characters long.';
        $reset_message_type = 'danger';
    } else {
        try {
            // Verify current password
            $sql = "SELECT password FROM IAP_students WHERE id = ?";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("i", $_SESSION['student_id']);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $student = $result->fetch_assoc();

                if (password_verify($current_password, $student['password'])) {
                    // Generate reset token
                    $reset_token = bin2hex(random_bytes(32));
                    $token_expiry = date('Y-m-d H:i:s', strtotime('+1 hour'));

                    // Store reset token in database
                    $update_sql = "UPDATE IAP_students SET reset_token = ?, reset_token_expiry = ? WHERE id = ?";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bind_param("ssi", $reset_token, $token_expiry, $_SESSION['student_id']);

                    if ($update_stmt->execute()) {
                        // Send reset email
                        $student_email = $_SESSION['email'];
                        $student_name = $_SESSION['full_name'];

                        $subject = "Password Reset Request - IAP Portal";
                        $reset_link = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/reset_password_confirm.php?token=" . $reset_token;

                        $message = "
                        <html>
                        <head>
                            <title>Password Reset - IAP Portal</title>
                        </head>
                        <body>
                            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                                <h2 style='color: #7c3aed;'>Password Reset Request</h2>
                                <p>Dear {$student_name},</p>
                                <p>You have requested to reset your password for your IAP Portal account.</p>
                                <p>Click the button below to confirm and set your new password:</p>
                                <div style='text-align: center; margin: 30px 0;'>
                                    <a href='{$reset_link}' style='background-color: #7c3aed; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;'>Reset Password</a>
                                </div>
                                <p><strong>Important:</strong> This link will expire in 1 hour for security reasons.</p>
                                <p>If you did not request this password reset, please ignore this email. Your password will remain unchanged.</p>
                                <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>
                                <p style='color: #666; font-size: 14px;'>
                                    This is an automated message from IAP Portal.<br>
                                    If you're having trouble clicking the button, copy and paste this URL into your browser:<br>
                                    <a href='{$reset_link}' style='color: #7c3aed;'>{$reset_link}</a>
                                </p>
                            </div>
                        </body>
                        </html>
                        ";

                        $headers = "MIME-Version: 1.0" . "\r\n";
                        $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
                        $headers .= "From: IAP Portal <noreply@iap-portal.com>" . "\r\n";

                        if (mail($student_email, $subject, $message, $headers)) {
                            $reset_message = 'Password reset email sent successfully! Please check your email and click the reset link to complete the process.';
                            $reset_message_type = 'success';
                        } else {
                            $reset_message = 'Failed to send reset email. Please try again later or contact support.';
                            $reset_message_type = 'danger';
                        }
                    } else {
                        $reset_message = 'Failed to process reset request. Please try again.';
                        $reset_message_type = 'danger';
                    }

                    $update_stmt->close();
                } else {
                    $reset_message = 'Current password is incorrect.';
                    $reset_message_type = 'danger';
                }
            } else {
                $reset_message = 'Student record not found.';
                $reset_message_type = 'danger';
            }

            $stmt->close();
        } catch (Exception $e) {
            $reset_message = 'An error occurred: ' . $e->getMessage();
            $reset_message_type = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - IAP Portal</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="theme.css">
    <style>
        :root {
            --primary-color: #7c3aed;
            --primary-light: #f3e8ff;
            --primary-dark: #5b21b6;
            --secondary-color: #f8fafc;
            --accent-color: #10b981;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        body {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-primary);
            line-height: 1.6;
            margin: 0;
            padding: 0;
        }

        /* Sidebar */
        .dashboard-sidebar {
            width: 260px;
            background: linear-gradient(180deg, #ffffff 0%, #fafbfc 100%);
            border-right: 1px solid var(--border-color);
            padding: 24px;
            position: fixed;
            left: 0;
            top: 70px;
            height: calc(100vh - 70px - 80px); /* Subtract footer height */
            overflow-y: auto;
            box-shadow: var(--shadow);
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            margin-bottom: 32px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
        }

        .sidebar-logo img {
            height: 80px;
            width: 80px;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-light);
            box-shadow: var(--shadow);
        }

        .sidebar-nav .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            margin-bottom: 8px;
            text-decoration: none;
            color: var(--text-secondary);
            border-radius: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            font-weight: 500;
            position: relative;
        }

        .sidebar-nav .sidebar-link:hover {
            background: var(--primary-light);
            color: var(--primary-color);
            transform: translateX(4px);
        }

        .sidebar-nav .sidebar-link.active {
            background: linear-gradient(135deg, var(--primary-light) 0%, rgba(255, 255, 255, 0.9) 100%);
            color: var(--primary-color);
            box-shadow: var(--shadow);
            transform: translateX(4px);
            border: 1px solid var(--primary-light);
        }

        .sidebar-nav .sidebar-link.active::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 4px;
            height: 60%;
            background: var(--primary-color);
            border-radius: 0 2px 2px 0;
        }

        .main-dashboard-content {
            margin-left: 260px;
            flex: 1;
            width: calc(100% - 260px);
            padding: 0;
            display: flex;
            flex-direction: column;
        }

        .container-lg {
            max-width: 1200px;
            margin: 0 auto;
            width: 100%;
            padding: 0 24px;
        }
        .navbar-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: var(--shadow-lg);
            backdrop-filter: blur(10px);
        }

        .navbar-custom .navbar-brand {
            font-weight: 700;
            font-size: 22px;
            color: white !important;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .navbar-brand img {
            height: 42px;
            width: auto;
            filter: brightness(1.1);
        }

        .navbar-custom .nav-link {
            color: rgba(255, 255, 255, 0.95) !important;
            font-weight: 500;
            transition: all 0.3s;
            white-space: nowrap;
            padding: 0.5rem 1rem !important;
        }

        .navbar-custom .nav-link:hover {
            color: white !important;
        }

        .navbar-nav {
            gap: 20px;
            align-items: center;
        }

        .navbar-nav .nav-item {
            display: flex;
            align-items: center;
        }

        .user-info {
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .user-info small {
            display: block;
            font-size: 12px;
            opacity: 0.9;
        }

        .user-name {
            font-weight: 600;
            color: white;
        }

        /* Main Content */
        .dashboard-container {
            padding: 30px 20px;
            flex: 1;
        }

        .welcome-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            color: var(--text-primary);
            padding: 32px 24px;
            border-radius: 16px;
            margin-bottom: 32px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            position: relative;
            overflow: hidden;
        }

        .welcome-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
        }

        .welcome-header h1 {
            font-size: 28px;
            margin-bottom: 8px;
            font-weight: 700;
            color: var(--primary-color);
        }

        .welcome-header p {
            font-size: 16px;
            margin: 0;
            color: var(--text-secondary);
            line-height: 1.6;
        }

        .student-info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-top: 24px;
        }

        .info-badge {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            padding: 16px 20px;
            border-radius: 12px;
            font-size: 14px;
            border: 1px solid var(--border-color);
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .info-badge:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .info-badge strong {
            color: var(--primary-color);
            font-weight: 600;
        }

        /* Section Title */
        .section-title {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 32px;
            padding-bottom: 16px;
            border-bottom: 3px solid var(--primary-color);
            position: relative;
        }

        .section-title::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            width: 60px;
            height: 3px;
            background: linear-gradient(90deg, var(--primary-color), var(--accent-color));
        }

        /* Year Section */
        .year-section {
            margin-bottom: 48px;
        }

        .year-header {
            background: linear-gradient(135deg, var(--primary-light) 0%, rgba(255,255,255,0.8) 100%);
            padding: 20px 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            border-left: 4px solid var(--primary-color);
            box-shadow: var(--shadow);
        }

        .year-header h2 {
            font-size: 22px;
            color: var(--primary-color);
            margin: 0;
            font-weight: 600;
            font-weight: 700;
        }

        /* Session Cards */
        .sessions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .session-card {
            background: linear-gradient(135deg, #ffffff 0%, #fafbfc 100%);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            display: flex;
            flex-direction: column;
            height: 100%;
            border: 1px solid var(--border-color);
        }

        .session-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-light);
        }

        .session-card-header {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 24px;
            position: relative;
        }

        .session-card-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 100%);
            pointer-events: none;
        }

        .session-title {
            font-size: 20px;
            font-weight: 700;
            margin: 0 0 12px 0;
            line-height: 1.4;
            color: var(--text-primary);
            position: relative;
            z-index: 1;
        }

        .session-year-badge {
            display: inline-block;
            background: rgba(255, 255, 255, 0.25);
            backdrop-filter: blur(10px);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: white;
            position: relative;
            z-index: 1;
        }

        .session-card-body {
            padding: 24px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }

        .session-description {
            color: var(--text-secondary);
            font-size: 14px;
            margin-bottom: 16px;
            flex-grow: 1;
            line-height: 1.6;
        }

        .session-meta {
            display: flex;
            gap: 16px;
            font-size: 13px;
            color: var(--text-secondary);
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 1px solid #e0e0e0;
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .status-badge {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 16px;
            box-shadow: var(--shadow);
        }

        .status-registered {
            background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%);
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .status-completed {
            background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
            color: #1e40af;
            border: 1px solid #bfdbfe;
        }

        .status-dropped {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .quiz-button {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            padding: 14px 24px;
            border-radius: 10px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            width: 100%;
        }

        .quiz-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4);
            color: white;
            text-decoration: none;
        }

        .quiz-button:disabled {
            background: #9ca3af;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 24px;
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-radius: 16px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
        }

        .empty-state-icon {
            font-size: 64px;
            color: var(--text-secondary);
            margin-bottom: 24px;
            opacity: 0.6;
        }

        .empty-state h3 {
            font-size: 24px;
            color: var(--text-primary);
            margin-bottom: 12px;
            font-weight: 600;
        }

        .empty-state p {
            color: var(--text-secondary);
            font-size: 16px;
            margin: 0;
            line-height: 1.6;
        }

        /* Alert */
        .alert {
            border-radius: 12px;
            border: none;
            margin-bottom: 24px;
            font-size: 14px;
            box-shadow: var(--shadow);
            padding: 16px 20px;
        }

        /* Footer */
        .dashboard-footer {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 0%);
            color: white;
            text-align: center;
            padding: 20px;
            position: fixed;
            bottom: 0;
            left: 260px;
            right: 0;
            width: calc(100% - 260px);
            z-index: 100;
            box-shadow: 0 -4px 6px -1px rgba(0, 0, 0, 0.1);
        }

        .dashboard-footer p {
            margin: 0;
            font-size: 14px;
            font-weight: 500;
        }

        /* Adjust main content to account for fixed footer */
        .main-dashboard-content {
            margin-left: 260px;
            margin-bottom: 80px; /* Space for footer */
            flex: 1;
            width: calc(100% - 260px);
            padding: 0;
            display: flex;
            flex-direction: column;
        }

        .alert-danger {
            background-color: #fff5f5;
            color: #c53030;
        }

        .alert-success {
            background-color: #f0fdf4;
            color: #15803d;
        }

        .alert-info {
            background-color: #f0f9ff;
            color: #0369a1;
        }

        /* Footer */
        .dashboard-footer {
            text-align: center;
            padding: 30px 20px;
            color: #fcfcfc;
            font-size: 14px;
            border-top: 1px solid #e5e7eb;
            margin-top: 50px;
        }

        @media (max-width: 768px) {
            .dashboard-sidebar {
                width: 100% !important;
                height: auto !important;
                position: relative !important;
                top: auto !important;
                padding: 16px !important;
                border-right: none !important;
                border-bottom: 1px solid var(--border-color) !important;
            }

            .main-dashboard-content {
                margin-left: 0 !important;
                width: 100% !important;
                margin-bottom: 120px !important; /* More space for mobile footer */
            }

            .dashboard-footer {
                left: 0 !important;
                width: 100% !important;
                position: relative !important;
                margin-top: 40px;
            }

            .welcome-header {
                padding: 25px 20px;
            }

            .welcome-header h1 {
                font-size: 24px;
            }

            .sessions-grid {
                grid-template-columns: 1fr;
            }

            .student-info-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
        <div class="container-lg">
            <a class="navbar-brand" href="index.php">
                Industry Awareness Program Portal
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <div class="user-info">
                            <i class="fas fa-user-circle"></i> 
                            <div>
                                <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                                <small><?php echo htmlspecialchars($_SESSION['email']); ?></small>
                            </div>
                        </div>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="?logout=1">
                            <i class="fas fa-sign-out-alt"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Sidebar -->
    <div class="dashboard-sidebar">
        <div class="sidebar-logo">
            <div style="display: flex; align-items: center; gap: 12px;">
                <img src="images/SA Main logo.jpg" alt="SA Main Logo" title="SA Main">
                <div style="display: flex; flex-direction: column;">
                        <span style="font-size: 18px; font-weight: 700; color: #7c3aed; line-height: 1.2;">SPECANCIENS</span>
                        <span style="font-size: 14px; font-weight: 700; color: #6b7280; line-height: 1.2;">IAP Portal</span>
                </div>
            </div>
        </div>

        <!-- Navigation Menu -->
        <div class="sidebar-nav" style="margin-top: 20px;">
            <a href="?view=dashboard" class="sidebar-link <?php echo (!isset($_GET['view']) || $_GET['view'] == 'dashboard') ? 'active' : ''; ?>">
                <i class="fas fa-home"></i> Dashboard
            </a>

            <a href="?view=register_session" class="sidebar-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'register_session') ? 'active' : ''; ?>">
                <i class="fas fa-plus-circle"></i> Register for Session
            </a>

            <a href="?view=view_progress" class="sidebar-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'view_progress') ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i> View Progress
            </a>

            <a href="?view=edit_profile" class="sidebar-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'edit_profile') ? 'active' : ''; ?>">
                <i class="fas fa-user-edit"></i> Edit Profile
            </a>

            <?php
            // Check if student has taken psychometric test
            $psychometric_check_sql = "SELECT score FROM psychometric_scores WHERE student_id = ?";
            $psychometric_check_stmt = $conn->prepare($psychometric_check_sql);
            $psychometric_check_stmt->bind_param("i", $_SESSION['student_id']);
            $psychometric_check_stmt->execute();
            $psychometric_check_result = $psychometric_check_stmt->get_result();
            $has_taken_test = $psychometric_check_result->num_rows > 0;
            $psychometric_check_stmt->close();
            ?>

            <?php if ($has_taken_test): ?>
                <a href="student_psychometric_report.php" class="sidebar-link" target="_blank">
                    <i class="fas fa-file-alt"></i> My Psychometric Report
                </a>
            <?php else: ?>
                <a href="?view=take_quiz" class="sidebar-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'take_quiz') ? 'active' : ''; ?>">
                    <i class="fas fa-brain"></i> Take Psychometric Quiz
                </a>
            <?php endif; ?>

            <a href="?view=reset_password" class="sidebar-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'reset_password') ? 'active' : ''; ?>">
                <i class="fas fa-key"></i> Reset Password
            </a>

            <a href="https://www.specanciens.com/projectpotal/index1.php" target="_blank" rel="noopener noreferrer" class="sidebar-link">
                <i class="fas fa-project-diagram"></i> Register for SA Projects
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-dashboard-content">
        <div class="container-lg">
            <?php
            $view = isset($_GET['view']) ? $_GET['view'] : 'dashboard';

            if ($view == 'dashboard') {
                // Default dashboard view
                ?>
                <!-- Welcome Header -->
                <div class="welcome-header">
                    <h1><i class="fas fa-chart-line"></i> Welcome, <?php echo htmlspecialchars(explode(' ', $_SESSION['full_name'])[0]); ?>!</h1>
                    <p>Here are the IAP sessions you have registered for. Click "Take Quiz" to participate in a session's quiz.</p>

                    <div class="student-info-grid">
                        <div class="info-badge">
                            <strong>Roll Number:</strong> <?php echo htmlspecialchars($_SESSION['roll_number']); ?>
                        </div>
                        <div class="info-badge">
                            <strong>Department:</strong> <?php echo htmlspecialchars($_SESSION['department']); ?>
                        </div>
                        <div class="info-badge">
                            <strong>Year:</strong> Year <?php echo htmlspecialchars($_SESSION['year']); ?>
                        </div>
                        <div class="info-badge">
                            <strong>Sessions Registered:</strong> <?php echo count($registered_sessions); ?>
                        </div>
                    </div>
                </div>

                <!-- Error Messages -->
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Sessions Content -->
                <?php if (!empty($sessions_by_year)): ?>
                    <h2 class="section-title"><i class="fas fa-list"></i> Your Registered Sessions</h2>
                <?php endif; ?>
            <?php
            } elseif ($view == 'register_session') {
                // Register for Session view
                ?>
                <div class="welcome-header">
                    <h1><i class="fas fa-plus-circle"></i> Register for Sessions</h1>
                    <p>Browse all available IAP sessions and register for the ones that interest you.</p>
                </div>
                <?php
                // Fetch all available sessions
                $all_sessions_sql = "SELECT s.*, COUNT(ss.student_id) as registered_count FROM sessions s
                                   LEFT JOIN IAP_student_sessions ss ON s.id = ss.session_id
                                   GROUP BY s.id ORDER BY s.year ASC, s.title ASC";
                $all_sessions_result = $conn->query($all_sessions_sql);

                if ($all_sessions_result && $all_sessions_result->num_rows > 0):
                    // Group sessions by year
                    $all_sessions_by_year = [];
                    while ($session = $all_sessions_result->fetch_assoc()) {
                        $all_sessions_by_year[$session['year']][] = $session;
                    }

                    foreach ($all_sessions_by_year as $year => $year_sessions):
                        ?>
                        <div class="year-section" style="margin-bottom: 30px;">
                            <h3 class="year-title" style="color: #7c3aed; margin-bottom: 15px; padding-bottom: 10px; border-bottom: 2px solid #f0f4ff;">
                                <i class="fas fa-graduation-cap"></i> Year <?php echo $year; ?> Sessions
                            </h3>

                            <div class="sessions-grid">
                                <?php foreach ($year_sessions as $session):
                                    // Check if student is already registered
                                    $is_registered = false;
                                    foreach ($registered_sessions as $registered) {
                                        if ($registered['id'] == $session['id']) {
                                            $is_registered = true;
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="session-card <?php echo $is_registered ? 'registered' : ''; ?>" style="background: <?php echo $is_registered ? '#f0fdf4' : '#ffffff'; ?>; border: 1px solid <?php echo $is_registered ? '#d1fae5' : '#e5e7eb'; ?>; border-radius: 12px; padding: 20px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                        <div class="session-card-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px;">
                                            <h4 style="margin: 0; color: #1f2937;"><?php echo htmlspecialchars($session['title']); ?></h4>
                                            <span class="session-year-badge" style="background: #7c3aed; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                                Year <?php echo htmlspecialchars($session['year']); ?>
                                            </span>
                                        </div>

                                        <?php if ($session['description']): ?>
                                            <p style="color: #6b7280; margin-bottom: 15px; font-size: 14px;"><?php echo htmlspecialchars($session['description']); ?></p>
                                        <?php endif; ?>

                                        <div class="session-stats" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                            <small style="color: #6b7280;">
                                                <i class="fas fa-users"></i> <?php echo $session['registered_count']; ?> registered
                                            </small>
                                            <?php if ($is_registered): ?>
                                                <span style="background: #10b981; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px; font-weight: 600;">
                                                    <i class="fas fa-check"></i> Registered
                                                </span>
                                            <?php endif; ?>
                                        </div>

                                        <div class="session-actions" style="display: flex; gap: 10px;">
                                            <button onclick="viewSessionDetail(<?php echo $session['id']; ?>, '<?php echo htmlspecialchars($session['title']); ?>')" class="btn btn-outline-primary btn-sm" style="flex: 1; padding: 8px; border: 1px solid #7c3aed; color: #7c3aed; border-radius: 6px; background: transparent; cursor: pointer;">
                                                <i class="fas fa-info-circle"></i> View Details
                                            </button>

                                            <?php if ($is_registered): ?>
                                                <a href="quiz.php?session_id=<?php echo $session['id']; ?>" class="btn btn-primary btn-sm" style="flex: 1; padding: 8px; background: #7c3aed; color: white; border-radius: 6px; text-decoration: none; text-align: center;">
                                                    <i class="fas fa-play"></i> Take Quiz
                                                </a>
                                            <?php else: ?>
                                                <button onclick="registerForSession(<?php echo $session['id']; ?>, '<?php echo htmlspecialchars($session['title']); ?>')" class="btn btn-success btn-sm" style="flex: 1; padding: 8px; background: #10b981; color: white; border-radius: 6px; border: none; cursor: pointer;">
                                                    <i class="fas fa-plus"></i> Register
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-info" style="padding: 20px; background: #eff6ff; border: 1px solid #dbeafe; border-radius: 8px; color: #1e40af;">
                        <i class="fas fa-info-circle"></i> No sessions are currently available.
                    </div>
                <?php endif; ?>

                <?php
            } elseif ($view == 'view_progress') {
                // View Progress
                ?>
                <div class="welcome-header">
                    <h1><i class="fas fa-chart-line"></i> Your Progress</h1>
                    <p>Track your performance across all registered IAP sessions.</p>
                </div>
                <?php
                if (!empty($registered_sessions)): ?>
                    <div class="progress-overview" style="background: #f8f9fa; padding: 20px; border-radius: 12px; margin-bottom: 30px;">
                        <h4 style="margin-bottom: 15px; color: #1f2937;">Progress Summary</h4>
                        <div class="progress-stats" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;">
                            <div class="stat-card" style="background: linear-gradient(135deg, #ffffff 0%, var(--primary-light) 100%); padding: 20px; border-radius: 12px; border-left: 4px solid var(--primary-color); box-shadow: var(--shadow); transition: all 0.3s ease;">
                                <div class="stat-number" style="font-size: 26px; font-weight: bold; color: var(--primary-color);"><?php echo count($registered_sessions); ?></div>
                                <div class="stat-label" style="color: var(--text-secondary); font-size: 14px; font-weight: 500;">Total Registered</div>
                            </div>
                            <div class="stat-card" style="background: linear-gradient(135deg, #ffffff 0%, #dcfce7 100%); padding: 20px; border-radius: 12px; border-left: 4px solid var(--accent-color); box-shadow: var(--shadow); transition: all 0.3s ease;">
                                <div class="stat-number" style="font-size: 26px; font-weight: bold; color: #059669;">
                                    <?php echo count(array_filter($registered_sessions, function($s) { return $s['registration_status'] === 'completed'; })); ?>
                                </div>
                                <div class="stat-label" style="color: var(--text-secondary); font-size: 14px; font-weight: 500;">Completed</div>
                            </div>
                            <div class="stat-card" style="background: linear-gradient(135deg, #ffffff 0%, #fef3c7 100%); padding: 20px; border-radius: 12px; border-left: 4px solid #f59e0b; box-shadow: var(--shadow);">
                                <div class="stat-number" style="font-size: 26px; font-weight: bold; color: #d97706;">
                                    <?php echo count(array_filter($registered_sessions, function($s) { return $s['registration_status'] === 'registered'; })); ?>
                                </div>
                                <div class="stat-label" style="color: var(--text-secondary); font-size: 14px; font-weight: 500;">In Progress</div>
                            </div>
                        </div>
                    </div>

                    <h3 style="margin-bottom: 24px; color: var(--text-primary); font-size: 24px; font-weight: 600;">Session Details</h3>

                    <?php foreach ($sessions_by_year as $year => $year_sessions): ?>
                        <div class="year-progress-section" style="margin-bottom: 30px;">
                            <h4 style="color: var(--primary-color); margin-bottom: 20px; padding-bottom: 12px; border-bottom: 2px solid var(--primary-light); font-size: 20px; font-weight: 600;">
                                <i class="fas fa-graduation-cap"></i> Year <?php echo $year; ?> Sessions
                            </h4>

                            <div class="progress-sessions">
                                <?php foreach ($year_sessions as $session): ?>
                                    <div class="progress-card" style="background: white; border: 1px solid #e5e7eb; border-radius: 12px; padding: 20px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                                        <div class="progress-header" style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px;">
                                            <h5 style="margin: 0; color: #1f2937;"><?php echo htmlspecialchars($session['title']); ?></h5>
                                            <span style="padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;
                                                <?php
                                                switch($session['registration_status']) {
                                                    case 'completed': echo 'background: #d1fae5; color: #065f46;'; break;
                                                    case 'registered': echo 'background: #dbeafe; color: #1e40af;'; break;
                                                    default: echo 'background: #f3f4f6; color: #374151;';
                                                }
                                                ?>">
                                                <?php echo ucfirst($session['registration_status']); ?>
                                            </span>
                                        </div>

                                        <div class="progress-details" style="margin-bottom: 15px;">
                                            <div class="detail-row" style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                                <span style="color: #6b7280; font-size: 14px;">Registered:</span>
                                                <span style="font-weight: 600;"><?php echo date('M j, Y', strtotime($session['registered_at'])); ?></span>
                                            </div>
                                        </div>

                                        <div class="progress-actions" style="display: flex; gap: 10px;">
                                            <a href="quiz.php?session_id=<?php echo $session['id']; ?>" class="btn btn-primary btn-sm" style="flex: 1; padding: 8px; background: #7c3aed; color: white; border-radius: 6px; text-decoration: none; text-align: center;">
                                                <i class="fas fa-play"></i> Take Quiz
                                            </a>
                                            <button onclick="viewSessionDetail(<?php echo $session['id']; ?>, '<?php echo htmlspecialchars($session['title']); ?>')" class="btn btn-outline-secondary btn-sm" style="flex: 1; padding: 8px; border: 1px solid #6b7280; color: #6b7280; border-radius: 6px; background: transparent; cursor: pointer;">
                                                <i class="fas fa-info-circle"></i> Details
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-info" style="padding: 24px; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1px solid var(--primary-light); border-radius: 12px; color: var(--primary-color); box-shadow: var(--shadow);">
                        <i class="fas fa-info-circle"></i> You haven't registered for any sessions yet. Visit the "Register for Session" section to get started!
                    </div>
                <?php endif; ?>
                <?php
            } elseif ($view == 'edit_profile') {
                // Edit Profile view
                ?>
                <div class="welcome-header">
                    <h1><i class="fas fa-user-edit"></i> Edit Profile</h1>
                    <p>Update your personal information and account details.</p>
                </div>

                <div class="profile-edit-container" style="background: white; border-radius: 16px; box-shadow: var(--shadow); padding: 32px; margin-bottom: 32px;">
                    <?php if (!empty($profile_message)): ?>
                        <div class="alert alert-<?php echo $profile_message_type; ?> alert-dismissible fade show" role="alert">
                            <i class="fas fa-<?php echo $profile_message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                            <?php echo htmlspecialchars($profile_message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="?view=edit_profile" id="profileForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="full_name" class="form-label">Full Name</label>
                                <input type="text" class="form-control" id="full_name" name="full_name" value="<?php echo htmlspecialchars($_SESSION['full_name']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="email" class="form-label">Email Address</label>
                                <input type="email" class="form-control" id="email" name="email" value="<?php echo htmlspecialchars($_SESSION['email']); ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="roll_number" class="form-label">Roll Number</label>
                                <input type="text" class="form-control" id="roll_number" name="roll_number" value="<?php echo htmlspecialchars($_SESSION['roll_number']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="department" class="form-label">Department</label>
                                <input type="text" class="form-control" id="department" name="department" value="<?php echo htmlspecialchars($_SESSION['department']); ?>" required>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="year" class="form-label">Year</label>
                                <select class="form-control" id="year" name="year" required>
                                    <option value="">Select Year</option>
                                    <option value="1" <?php echo $_SESSION['year'] == '1' ? 'selected' : ''; ?>>Year 1</option>
                                    <option value="2" <?php echo $_SESSION['year'] == '2' ? 'selected' : ''; ?>>Year 2</option>
                                    <option value="3" <?php echo $_SESSION['year'] == '3' ? 'selected' : ''; ?>>Year 3</option>
                                    <option value="4" <?php echo $_SESSION['year'] == '4' ? 'selected' : ''; ?>>Year 4</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="phone" class="form-label">Phone Number (Optional)</label>
                                <input type="tel" class="form-control" id="phone" name="phone" placeholder="Enter your phone number">
                            </div>
                        </div>
                        <div class="mb-4">
                            <label for="current_password" class="form-label">Current Password (Required to save changes)</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" required>
                        </div>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> <strong>Note:</strong> You need to enter your current password to update your profile information.
                        </div>
                        <div class="d-flex gap-3">
                            <button type="submit" name="update_profile" class="btn btn-primary" style="background: var(--primary-color); border: none; padding: 12px 24px;">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                            <button type="button" class="btn btn-secondary" onclick="location.reload()" style="padding: 12px 24px;">
                                <i class="fas fa-times"></i> Cancel
                            </button>
                        </div>
                    </form>
                </div>

                <?php
            } elseif ($view == 'take_quiz') {
                // Take Psychometric Quiz view
                ?>
                <div class="welcome-header">
                    <h1><i class="fas fa-brain"></i> Psychometric Assessment</h1>
                    <p>Take our comprehensive psychometric assessment to understand your personality traits and career preferences.</p>
                </div>

                <div class="quiz-container" style="background: white; border-radius: 16px; box-shadow: var(--shadow); padding: 32px; margin-bottom: 32px;">
                    <div class="quiz-intro text-center mb-4">
                        <i class="fas fa-clipboard-list fa-4x text-primary mb-3"></i>
                        <h3>Ready to Begin Your Assessment?</h3>
                        <p class="text-muted">This psychometric assessment consists of 20 questions designed to evaluate your:</p>
                        <div class="quiz-features" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
                            <div class="feature-item" style="padding: 20px; background: var(--primary-light); border-radius: 12px;">
                                <i class="fas fa-users text-primary mb-2" style="font-size: 24px;"></i>
                                <h5>Personality Traits</h5>
                                <p>Understand your behavioral patterns</p>
                            </div>
                            <div class="feature-item" style="padding: 20px; background: var(--primary-light); border-radius: 12px;">
                                <i class="fas fa-lightbulb text-primary mb-2" style="font-size: 24px;"></i>
                                <h5>Learning Style</h5>
                                <p>Discover how you learn best</p>
                            </div>
                            <div class="feature-item" style="padding: 20px; background: var(--primary-light); border-radius: 12px;">
                                <i class="fas fa-briefcase text-primary mb-2" style="font-size: 24px;"></i>
                                <h5>Career Interests</h5>
                                <p>Identify suitable career paths</p>
                            </div>
                        </div>
                    </div>

                    <div class="quiz-info" style="background: #f8fafc; padding: 24px; border-radius: 12px; margin-bottom: 30px;">
                        <h5><i class="fas fa-info-circle"></i> Assessment Information</h5>
                        <ul class="list-unstyled" style="margin: 0;">
                            <li style="margin-bottom: 8px;"><i class="fas fa-clock text-primary"></i> <strong>Duration:</strong> Approximately 15-20 minutes</li>
                            <li style="margin-bottom: 8px;"><i class="fas fa-question-circle text-primary"></i> <strong>Questions:</strong> 20 questions selected from our comprehensive bank</li>
                            <li style="margin-bottom: 8px;"><i class="fas fa-shield-alt text-primary"></i> <strong>Privacy:</strong> Your responses are confidential and used only for assessment</li>
                            <li style="margin-bottom: 8px;"><i class="fas fa-trophy text-primary"></i> <strong>Results:</strong> Detailed report with trait analysis and career insights</li>
                            <li style="margin-bottom: 8px;"><i class="fas fa-ban text-warning"></i> <strong>One-time:</strong> This assessment can only be taken once</li>
                        </ul>
                    </div>

                    <div class="alert alert-info">
                        <i class="fas fa-exclamation-triangle"></i> <strong>Important:</strong> This is a one-time assessment. Once completed, you cannot retake it. Make sure you answer honestly and thoughtfully.
                    </div>

                    <div class="text-center">
                        <button class="btn btn-primary btn-lg" style="background: var(--primary-color); border: none; padding: 16px 32px; font-size: 18px;" onclick="startPsychometricQuiz()">
                            <i class="fas fa-play"></i> Start Psychometric Assessment
                        </button>
                        <p class="text-muted mt-3">Make sure you have sufficient time to complete the assessment without interruptions.</p>
                    </div>
                </div>

                <?php
            } elseif ($view == 'reset_password') {
                // Reset Password view
                ?>
                <div class="welcome-header">
                    <h1><i class="fas fa-key"></i> Reset Password</h1>
                    <p>Change your account password securely. An email will be sent to your registered email address for verification.</p>
                </div>

                <div class="reset-password-container" style="background: white; border-radius: 16px; box-shadow: var(--shadow); padding: 32px; margin-bottom: 32px;">
                    <div class="reset-password-form">
                        <div class="alert alert-info mb-4">
                            <i class="fas fa-info-circle"></i> <strong>Password Reset Process:</strong>
                            <ol class="mb-0 mt-2">
                                <li>Enter your current password for verification</li>
                                <li>Enter your new desired password</li>
                                <li>An email will be sent to <?php echo htmlspecialchars($_SESSION['email']); ?> for confirmation</li>
                                <li>Click the link in the email to confirm the password change</li>
                            </ol>
                        </div>

                        <form method="POST" action="" id="resetPasswordForm">
                            <div class="mb-4">
                                <label for="current_password_reset" class="form-label">Current Password</label>
                                <input type="password" class="form-control" id="current_password_reset" name="current_password" required>
                                <div class="form-text">Enter your current password to verify your identity.</div>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="new_password" class="form-label">New Password</label>
                                    <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                                    <div class="form-text">Minimum 8 characters required.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                                    <div class="form-text">Re-enter your new password.</div>
                                </div>
                            </div>

                            <div class="password-strength mb-3" id="passwordStrength" style="display: none;">
                                <small class="text-muted">Password strength: <span id="strengthText">Weak</span></small>
                                <div class="progress mt-1" style="height: 8px;">
                                    <div class="progress-bar" id="strengthBar" role="progressbar" style="width: 0%"></div>
                                </div>
                            </div>

                            <div class="mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="confirmEmail" required>
                                    <label class="form-check-label" for="confirmEmail">
                                        I confirm that I want to receive a password reset email at <?php echo htmlspecialchars($_SESSION['email']); ?>
                                    </label>
                                </div>
                            </div>

                            <div class="d-flex gap-3">
                                <button type="submit" name="reset_password" class="btn btn-primary" style="background: var(--primary-color); border: none; padding: 12px 24px;">
                                    <i class="fas fa-envelope"></i> Send Reset Email
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="location.reload()" style="padding: 12px 24px;">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php
            }
            ?>

        <!-- Footer -->
        <div class="dashboard-footer">
            <p>&copy; <?php echo date('Y'); ?> IAP Portal - SPECANCIENS. All rights reserved.</p>
        </div>
    </div>

    <!-- Session Registration Modal -->
    <div id="sessionModal" class="modal fade" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sessionModalTitle">Session Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="sessionModalBody">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" id="registerBtn" style="display: none;">Register for Session</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // Function to view session details
    function viewSessionDetail(sessionId, sessionTitle) {
        // For now, show basic info. In a real app, this would fetch from server
        const modalBody = document.getElementById('sessionModalBody');
        const modalTitle = document.getElementById('sessionModalTitle');
        const registerBtn = document.getElementById('registerBtn');

        modalTitle.textContent = sessionTitle;
        modalBody.innerHTML = `
            <div class="text-center mb-4">
                <i class="fas fa-info-circle fa-3x text-primary mb-3"></i>
                <h4>${sessionTitle}</h4>
            </div>
            <p><strong>Session ID:</strong> ${sessionId}</p>
            <p><strong>Description:</strong> This session covers important topics related to Industry Awareness Program. Please register to access detailed content and quizzes.</p>
            <div class="alert alert-info">
                <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> Register for this session to unlock quizzes and track your progress!
            </div>
        `;

        registerBtn.style.display = 'inline-block';
        registerBtn.onclick = function() {
            registerForSession(sessionId, sessionTitle);
        };

        const modal = new bootstrap.Modal(document.getElementById('sessionModal'));
        modal.show();
    }

    // Function to register for a session
    function registerForSession(sessionId, sessionTitle) {
        if (confirm(`Are you sure you want to register for "${sessionTitle}"?`)) {
            // Send registration request
            fetch('session_registration.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'session_id=' + sessionId
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    alert('Successfully registered for the session!');
                    location.reload(); // Refresh to show updated status
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred. Please try again.');
            });
        }
    }

    // Function to start psychometric quiz
    function startPsychometricQuiz() {
        if (confirm('Are you ready to begin the psychometric assessment? This assessment consists of 20 questions and typically takes 15-20 minutes to complete.')) {
            window.location.href = 'psychometric_quiz.php';
        }
    }

    // Password strength checker
    function checkPasswordStrength(password) {
        let strength = 0;
        let feedback = [];

        if (password.length >= 8) strength += 1;
        if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength += 1;
        if (password.match(/\d/)) strength += 1;
        if (password.match(/[^a-zA-Z\d]/)) strength += 1;

        const strengthBar = document.getElementById('strengthBar');
        const strengthText = document.getElementById('strengthText');

        if (strengthBar && strengthText) {
            switch(strength) {
                case 0:
                case 1:
                    strengthBar.style.width = '25%';
                    strengthBar.className = 'progress-bar bg-danger';
                    strengthText.textContent = 'Weak';
                    break;
                case 2:
                    strengthBar.style.width = '50%';
                    strengthBar.className = 'progress-bar bg-warning';
                    strengthText.textContent = 'Fair';
                    break;
                case 3:
                    strengthBar.style.width = '75%';
                    strengthBar.className = 'progress-bar bg-info';
                    strengthText.textContent = 'Good';
                    break;
                case 4:
                    strengthBar.style.width = '100%';
                    strengthBar.className = 'progress-bar bg-success';
                    strengthText.textContent = 'Strong';
                    break;
            }
        }
    }

    // Initialize password strength checker for reset password form
    document.addEventListener('DOMContentLoaded', function() {
        const newPasswordInput = document.getElementById('new_password');
        if (newPasswordInput) {
            newPasswordInput.addEventListener('input', function() {
                const password = this.value;
                const strengthDiv = document.getElementById('passwordStrength');

                if (password.length > 0) {
                    strengthDiv.style.display = 'block';
                    checkPasswordStrength(password);
                } else {
                    strengthDiv.style.display = 'none';
                }
            });
        }

        // Form validation for reset password
        const resetForm = document.getElementById('resetPasswordForm');
        if (resetForm) {
            resetForm.addEventListener('submit', function(e) {
                const newPassword = document.getElementById('new_password').value;
                const confirmPassword = document.getElementById('confirm_password').value;
                const currentPassword = document.getElementById('current_password_reset').value;

                if (!currentPassword) {
                    e.preventDefault();
                    alert('Please enter your current password.');
                    return false;
                }

                if (newPassword !== confirmPassword) {
                    e.preventDefault();
                    alert('New passwords do not match!');
                    return false;
                }

                if (newPassword.length < 8) {
                    e.preventDefault();
                    alert('New password must be at least 8 characters long!');
                    return false;
                }

                // Check if email confirmation is checked
                const emailConfirm = document.getElementById('confirmEmail');
                if (emailConfirm && !emailConfirm.checked) {
                    e.preventDefault();
                    alert('Please confirm that you want to receive the reset email.');
                    return false;
                }

                return confirm('Are you sure you want to reset your password? An email will be sent to your registered email address.');
            });
        }

        // Form validation for profile update
        const profileForm = document.getElementById('profileForm');
        if (profileForm) {
            profileForm.addEventListener('submit', function(e) {
                const fullName = document.getElementById('full_name').value.trim();
                const email = document.getElementById('email').value.trim();
                const rollNumber = document.getElementById('roll_number').value.trim();
                const department = document.getElementById('department').value.trim();
                const year = document.getElementById('year').value;
                const currentPassword = document.getElementById('current_password').value;

                if (!fullName || !email || !rollNumber || !department || !year || !currentPassword) {
                    e.preventDefault();
                    alert('All required fields must be filled.');
                    return false;
                }

                const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailRegex.test(email)) {
                    e.preventDefault();
                    alert('Please enter a valid email address.');
                    return false;
                }

                return confirm('Are you sure you want to update your profile?');
            });
        }

        // Auto-hide success messages after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                if (alert.classList.contains('alert-success')) {
                    setTimeout(function() {
                        alert.style.display = 'none';
                    }, 5000);
                }
            });
        });


    });
    </script>
</body>
</html>
