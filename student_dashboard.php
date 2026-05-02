<?php
/**
 * Student Dashboard
 * Displays personalized dashboard with registered sessions
 * Shows only sessions the student is registered for
 * Includes "Take Quiz" button for each session
 */

// Include session protection - must be at the top
require_once 'Student/student_session_check.php';
require_once 'Student/validation_helpers.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$show_disclaimer = false;
if (empty($_SESSION['disclaimer_shown'])) {
    $show_disclaimer = true;
    $_SESSION['disclaimer_shown'] = true;
}

$error_message = '';
$registered_sessions = [];
$student_year = (string)($_SESSION['year'] ?? '');
$session_title_column = 'topic';
$session_description_exists = false;
$session_code_exists = false;

// Resolve sessions schema differences across environments (title/topic, optional description).
$title_col_check = $conn->query("SHOW COLUMNS FROM sessions LIKE 'title'");
if ($title_col_check && $title_col_check->num_rows > 0) {
    $session_title_column = 'title';
}
$description_col_check = $conn->query("SHOW COLUMNS FROM sessions LIKE 'description'");
$session_description_exists = ($description_col_check && $description_col_check->num_rows > 0);
$session_code_col_check = $conn->query("SHOW COLUMNS FROM sessions LIKE 'session_code'");
$session_code_exists = ($session_code_col_check && $session_code_col_check->num_rows > 0);

/**
 * Student year authorization for session registration.
 * Students are allowed only for their own session year.
 */
function can_register_for_session_year(string $student_year, string $session_year): bool
{
    return normalize_year_key($student_year) === normalize_year_key($session_year);
}

/**
 * Normalize academic year label (e.g. "3", "Year 3", "3rd Year", "Graduate") into a comparable key.
 */
function normalize_year_key(string $value): string
{
    $value = trim(strtolower($value));
    $value = str_replace(['year', '-', '_'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    if (preg_match('/\b([1-4])\b/', $value, $m)) {
        return $m[1];
    }
    if (strpos($value, 'graduate') !== false) {
        return 'graduate';
    }
    return $value;
}

/**
 * Build display label as "session_code - title" when code exists.
 */
function session_display_label(array $session): string
{
    $code = trim((string)($session['session_code'] ?? ''));
    $title = trim((string)($session['title'] ?? ''));
    return $code !== '' ? ($code . ' - ' . $title) : $title;
}

/**
 * Generate next structured session code for a given year.
 */
function generate_next_session_code_for_year(mysqli $conn, string $year): string
{
    $yy = year_to_code_for_session($year);
    $prefix = $yy . 'SN';
    $sql = "SELECT session_code FROM sessions WHERE session_code LIKE CONCAT(?, '%') ORDER BY session_code DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $prefix . '01';
    }
    $stmt->bind_param("s", $prefix);
    $stmt->execute();
    $res = $stmt->get_result();
    $last = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    $n = 1;
    if ($last && !empty($last['session_code']) && preg_match('/SN(\d{2})$/', $last['session_code'], $m)) {
        $n = intval($m[1]) + 1;
    }
    return $prefix . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
}

function year_to_code_for_session(string $year): string
{
    $k = normalize_year_key($year);
    if (in_array($k, ['1', '2', '3', '4'], true)) {
        return str_pad($k, 2, '0', STR_PAD_LEFT);
    }
    return '00';
}

try {
    // Fetch student's registered sessions using MySQLi prepared statement
    $description_select = $session_description_exists ? "s.description" : "''";
    $session_code_select = $session_code_exists ? "s.session_code" : "'' AS session_code";
    $sql = "SELECT 
                s.id,
                {$session_code_select},
                s.{$session_title_column} as title,
                s.year,
                {$description_select} as description,
                ss.registration_status,
                ss.registered_at
            FROM sessions s
            JOIN iap_student_sessions ss ON s.id = ss.session_id
            WHERE ss.student_id = ?
            ORDER BY s.year ASC, s.{$session_title_column} ASC";
    
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

// Handle session registration
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['register_session'])) {
    $session_id = intval($_POST['session_id'] ?? 0);
    $catalog_title = trim((string)($_POST['catalog_title'] ?? ''));

    if ($session_id > 0 || $catalog_title !== '') {
        // For catalog-only rows, create/find a real session first so registration can be persisted.
        if ($session_id <= 0 && $catalog_title !== '') {
            $find_session_sql = "SELECT id, year FROM sessions WHERE {$session_title_column} = ? AND year = ? LIMIT 1";
            $find_stmt = $conn->prepare($find_session_sql);
            if ($find_stmt) {
                $find_stmt->bind_param("ss", $catalog_title, $student_year);
                $find_stmt->execute();
                $find_result = $find_stmt->get_result();
                $found = $find_result ? $find_result->fetch_assoc() : null;
                $find_stmt->close();

                if ($found) {
                    $session_id = (int)$found['id'];
                } else {
                    if ($session_code_exists) {
                        $new_code = generate_next_session_code_for_year($conn, $student_year);
                        $insert_session_sql = "INSERT INTO sessions (session_code, {$session_title_column}, year) VALUES (?, ?, ?)";
                        $insert_session_stmt = $conn->prepare($insert_session_sql);
                        if ($insert_session_stmt) {
                            $insert_session_stmt->bind_param("sss", $new_code, $catalog_title, $student_year);
                            if ($insert_session_stmt->execute()) {
                                $session_id = (int)$conn->insert_id;
                            }
                            $insert_session_stmt->close();
                        }
                    } else {
                        $insert_session_sql = "INSERT INTO sessions ({$session_title_column}, year) VALUES (?, ?)";
                        $insert_session_stmt = $conn->prepare($insert_session_sql);
                        if ($insert_session_stmt) {
                            $insert_session_stmt->bind_param("ss", $catalog_title, $student_year);
                            if ($insert_session_stmt->execute()) {
                                $session_id = (int)$conn->insert_id;
                            }
                            $insert_session_stmt->close();
                        }
                    }
                }
            }
        }

        if ($session_id <= 0) {
            $error_message = "Unable to register this session right now.";
        }

        // Fetch session year + name for server-side validation and storage.
        if ($session_id > 0) {
            $session_sql = "SELECT id, {$session_title_column} AS session_title, year FROM sessions WHERE id = ?";
            $session_stmt = $conn->prepare($session_sql);
            $session_stmt->bind_param("i", $session_id);
            $session_stmt->execute();
            $session_result = $session_stmt->get_result();
            $session_row = $session_result->fetch_assoc();
            $session_stmt->close();

            if (!$session_row) {
                $error_message = "Session not found.";
            } elseif (!can_register_for_session_year($student_year, (string)$session_row['year'])) {
                $error_message = "You can only register for sessions of your academic year";
            } else {
                // Ensure duplicate prevention index exists.
                $unique_check = $conn->query("SHOW INDEX FROM iap_student_sessions WHERE Key_name = 'unique_student_session'");
                if ($unique_check && $unique_check->num_rows === 0) {
                    $conn->query("ALTER TABLE iap_student_sessions ADD UNIQUE KEY unique_student_session (student_id, session_id)");
                }

                // Register for session if not duplicate.
                $register_sql = "INSERT INTO iap_student_sessions (student_id, session_id) VALUES (?, ?)";
                $register_stmt = $conn->prepare($register_sql);
                $register_stmt->bind_param("ii", $_SESSION['student_id'], $session_id);

                if ($register_stmt->execute()) {
                    header("Location: ?view=view_all_sessions&success=1");
                    exit();
                }
                $error_message = "Already registered for this session or unable to register.";
                $register_stmt->close();
            }
        }
    }
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
    } elseif (!validate_roll_number($roll_number)) {
        $profile_message = 'Roll number must be exactly 10 characters in format 23BK1A66L5.';
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
            height: calc(100vh - 70px - 64px); /* Subtract header and footer height */
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
            position: sticky;
            top: 0;
            z-index: 1100;
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

        /* Quick register context menu */
        .quick-context-menu {
            position: fixed;
            z-index: 2000;
            display: none;
            min-width: 220px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
            padding: 6px;
        }

        .quick-context-menu button {
            width: 100%;
            border: none;
            background: transparent;
            text-align: left;
            padding: 10px 12px;
            border-radius: 8px;
            color: #374151;
            font-size: 14px;
            cursor: pointer;
        }

        .quick-context-menu button:hover {
            background: #f3e8ff;
            color: #5b21b6;
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
            padding: 14px 20px;
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
            font-size: 13px;
            font-weight: 500;
        }

        /* Adjust main content to account for fixed footer */
        .main-dashboard-content {
            margin-left: 260px;
            margin-bottom: 64px; /* Space for footer */
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

        /* Footer fine-tuning (do not override fixed layout) */
        .dashboard-footer {
            border-top: 1px solid rgba(255, 255, 255, 0.15);
            margin-top: 0;
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

            <a href="?view=view_all_sessions" class="sidebar-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'view_all_sessions') ? 'active' : ''; ?>">
                <i class="fas fa-list"></i> View All Sessions
            </a>

            <a href="?view=view_registered_sessions" class="sidebar-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'view_registered_sessions') ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i> View Registered Sessions
            </a>

            <a href="?view=suggest_session" class="sidebar-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'suggest_session') ? 'active' : ''; ?>">
                <i class="fas fa-lightbulb"></i> Suggest a Session
            </a>

            <a href="?view=view_progress" class="sidebar-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'view_progress') ? 'active' : ''; ?>">
                <i class="fas fa-chart-line"></i> View Progress
            </a>

            <a href="?view=edit_profile" class="sidebar-link <?php echo (isset($_GET['view']) && $_GET['view'] == 'edit_profile') ? 'active' : ''; ?>">
                <i class="fas fa-user-edit"></i> Edit Profile
            </a>

            <?php
            // Ensure psychometric scores table exists for student dashboard access
            $create_psychometric_scores_sql = "CREATE TABLE IF NOT EXISTS iap_psychometric_scores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL UNIQUE,
                score DECIMAL(5,2) NOT NULL,
                trait_a INT,
                trait_b INT,
                trait_c INT,
                trait_d INT,
                completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (student_id) REFERENCES IAP_students(id) ON DELETE CASCADE
            )";
            $conn->query($create_psychometric_scores_sql);

            // Check if table has proper structure, recreate if missing columns
            $check_student_id = $conn->query("SHOW COLUMNS FROM iap_psychometric_scores LIKE 'student_id'");
            $check_score = $conn->query("SHOW COLUMNS FROM iap_psychometric_scores LIKE 'score'");

            if ((!$check_student_id || $check_student_id->num_rows == 0) ||
                (!$check_score || $check_score->num_rows == 0)) {
                // Table is missing required columns, drop and recreate
                $conn->query("DROP TABLE IF EXISTS iap_psychometric_scores");
                $conn->query($create_psychometric_scores_sql);
            }

            // Check if student has taken psychometric test
            $psychometric_check_sql = "SELECT score FROM iap_psychometric_scores WHERE student_id = ?";
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
            ?>

            <?php if ($show_disclaimer && $view === 'dashboard'): ?>
                <div class="alert alert-warning alert-dismissible fade show rounded-3 shadow-sm mt-4" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    Please ensure all details are filled accurately. The information provided will be kept strictly confidential.
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <?php
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
                // Fetch sessions only for logged-in student's academic year.
                $description_select = $session_description_exists ? "s.description" : "''";
                $session_code_select = $session_code_exists ? "s.session_code" : "'' AS session_code";
                $all_sessions_sql = "SELECT s.id, {$session_code_select}, s.{$session_title_column} AS title, s.year, {$description_select} AS description, COUNT(ss.student_id) as registered_count FROM sessions s
                                   LEFT JOIN iap_student_sessions ss ON s.id = ss.session_id
                                   GROUP BY s.id ORDER BY s.{$session_title_column} ASC";
                $all_sessions_stmt = $conn->prepare($all_sessions_sql);
                $all_sessions_stmt->execute();
                $all_sessions_result = $all_sessions_stmt->get_result();

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
                                            <button onclick="viewSessionDetail(<?php echo $session['id']; ?>, '<?php echo htmlspecialchars($session['title']); ?>', '<?php echo htmlspecialchars((string)$session['year'], ENT_QUOTES); ?>')" class="btn btn-outline-primary btn-sm" style="flex: 1; padding: 8px; border: 1px solid #7c3aed; color: #7c3aed; border-radius: 6px; background: transparent; cursor: pointer;">
                                                <i class="fas fa-info-circle"></i> View Details
                                            </button>

                                            <?php if ($is_registered): ?>
                                                <a href="quiz.php?session_id=<?php echo $session['id']; ?>" class="btn btn-primary btn-sm" style="flex: 1; padding: 8px; background: #7c3aed; color: white; border-radius: 6px; text-decoration: none; text-align: center;">
                                                    <i class="fas fa-play"></i> Take Quiz
                                                </a>
                                            <?php else: ?>
                                                <button onclick="registerForSession(<?php echo $session['id']; ?>, '<?php echo htmlspecialchars($session['title']); ?>', '<?php echo htmlspecialchars((string)$session['year'], ENT_QUOTES); ?>')" class="btn btn-success btn-sm" style="flex: 1; padding: 8px; background: #10b981; color: white; border-radius: 6px; border: none; cursor: pointer;">
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
                                <div id="profileRollNumberError" class="invalid-feedback" style="display: none;">
                                    Roll number must be exactly 10 characters in format 23BK1A66L5.
                                </div>
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
            } elseif ($view == 'view_all_sessions') {
                // View All Sessions - Show all sessions with registration option
                ?>
                <div class="welcome-header">
                    <h1><i class="fas fa-list"></i> Available Sessions</h1>
                    <p>Browse sessions available for your academic year. Click "Get Registered" to register for a session.</p>
                    <div class="mt-2">
                        <span class="badge bg-light text-dark">Your Year: <?php echo htmlspecialchars($student_year ?: 'N/A'); ?></span>
                    </div>
                </div>

                <?php if (isset($_GET['success'])): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> Successfully registered for the session! You can now view it in "View Registered Sessions".
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <?php
                // Fetch sessions only for logged-in student's academic year.
                $title_select = ($session_title_column === 'title') ? "COALESCE(NULLIF(title, ''), topic)" : "topic";
                $description_select = $session_description_exists ? "description" : "''";
                $session_code_select = $session_code_exists ? "session_code" : "'' AS session_code";
                $all_sessions_sql = "SELECT id, {$session_code_select}, {$title_select} AS title, year, {$description_select} AS description
                                     FROM sessions
                                     ORDER BY {$session_title_column} ASC";
                $all_sessions_stmt = $conn->prepare($all_sessions_sql);
                $all_sessions_stmt->execute();
                $all_sessions_result_raw = $all_sessions_stmt->get_result();
                $sessions_filtered = [];
                $student_year_key = normalize_year_key($student_year);
                while ($session_row = $all_sessions_result_raw->fetch_assoc()) {
                    if (normalize_year_key((string)$session_row['year']) === $student_year_key) {
                        $sessions_filtered[] = $session_row;
                    }
                }
                $all_sessions_result = null;
                if (!empty($sessions_filtered)) {
                    $all_sessions_result = new ArrayObject($sessions_filtered);
                }

                // Merge index.php year-wise catalog so all sessions stay visible even if only few are in DB.
                $index_year_catalog = [
                    '1' => [
                        'Introduction to Engineering Careers',
                        'How to Ace Ideathons',
                        'What is Problem-Solving?',
                        'Emerging Technologies Overview',
                        'Soft Skills: Communication & Teamwork',
                        'College to Career Transition',
                        'Resume Building Basics',
                        'Industry Standards, Ethics & Workplace Communication',
                        'Roles, Responsibilities & Career Pathways in Industry',
                        'LinkedIn Profile Basics'
                    ],
                    '2' => [
                        'Resume Building and Career Positioning',
                        'LinkedIn Mastery for Students',
                        'Interview Preparation Fundamentals',
                        'Presentation & Public Skills',
                        'Internship Success Strategy',
                        'Wokrplace Communication & Etiquette',
                        'Building your Personal Brand',
                        'Aptitude & Reasoning for Placements',
                        'Hackathon Success & Learning',
                        'Time Management, Company Opportunities & Certifications'
                    ],
                    '3' => [
                        'Career Paths Beyond Campus Placements',
                        'Confidence Building in High-Pressure Situations',
                        'Project Presentation & Demo Skills',
                        'Internship to Full-Time Conversion',
                        'Salary Negotiation & Career Economics',
                        'Advanced Job Search Strategy & Placement Mastery',
                        'Core vs Non-Core Career Paths & Specialization',
                        'Advanced Interview Essentials & Preparation Strategy',
                        'GitHub Portfolio & Open Source Contribution',
                        'Managing Academics, Placements & Growth'
                    ],
                    '4' => [
                        'Advanced System Design & Scalability',
                        'Specialization Deep Dive',
                        'Startup Ecosystem & Entrepreneurship',
                        'Research & Innovation in Engineering',
                        'Advanced Leadership & Management',
                        'Industry Certifications & Strategic Learning Roadmap',
                        'Global Opportunities & Remote Work',
                        'Real-World Project Development',
                        'Personal Branding & Personal Development',
                        'Alternative Paths & Contingency Planning'
                    ]
                ];
                $year_key = normalize_year_key($student_year);
                $merged_rows = [];
                $seen_titles = [];
                if ($all_sessions_result && count($all_sessions_result) > 0) {
                    foreach ($all_sessions_result as $row) {
                        $key = strtolower(trim((string)$row['title']));
                        $seen_titles[$key] = true;
                        $merged_rows[] = $row;
                    }
                }
                if (isset($index_year_catalog[$year_key])) {
                    foreach ($index_year_catalog[$year_key] as $topic) {
                        $key = strtolower(trim($topic));
                        if (!isset($seen_titles[$key])) {
                            $merged_rows[] = [
                                'id' => 0,
                                'title' => $topic,
                                'year' => $student_year,
                                'description' => ''
                            ];
                        }
                    }
                }
                if (!empty($merged_rows)) {
                    $all_sessions_result = new ArrayObject($merged_rows);
                }

                if ($all_sessions_result && count($all_sessions_result) > 0):
                    // Group sessions by year
                    $sessions_by_year_all = [];
                    foreach ($all_sessions_result as $session) {
                        $sessions_by_year_all[$session['year']][] = $session;
                    }

                    foreach ($sessions_by_year_all as $year => $year_sessions):
                        ?>
                        <div class="year-section" style="margin-bottom: 40px;">
                            <h3 class="year-title" style="color: #7c3aed; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 3px solid #f0f4ff; font-size: 22px; font-weight: 700;">
                                <i class="fas fa-graduation-cap"></i> Year <?php echo htmlspecialchars((string)$year); ?> Sessions
                            </h3>
                            <div class="table-responsive" style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;">
                                <table class="table mb-0" style="width:100%;">
                                    <thead style="background: #f8fafc;">
                                        <tr>
                                            <th style="padding: 12px 16px;">Session Title</th>
                                            <th style="padding: 12px 16px;">Description</th>
                                            <th style="padding: 12px 16px;">Status</th>
                                            <th style="padding: 12px 16px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($year_sessions as $session):
                                        $is_registered = false;
                                        foreach ($registered_sessions as $registered) {
                                            if ($registered['id'] == $session['id']) {
                                                $is_registered = true;
                                                break;
                                            }
                                        }
                                    ?>
                                        <tr>
                                            <td style="padding: 12px 16px; font-weight: 600;"><?php echo htmlspecialchars(session_display_label($session)); ?></td>
                                            <td style="padding: 12px 16px; color: #6b7280;"><?php echo !empty($session['description']) ? htmlspecialchars($session['description']) : 'N/A'; ?></td>
                                            <td style="padding: 12px 16px;">
                                                <?php if ($is_registered): ?>
                                                    <span style="background: #d1fae5; color: #065f46; padding: 5px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">Registered</span>
                                                <?php else: ?>
                                                    <span style="background: #e5e7eb; color: #374151; padding: 5px 10px; border-radius: 999px; font-size: 12px; font-weight: 600;">Not Registered</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="padding: 12px 16px;">
                                                <?php if ($is_registered): ?>
                                                    <a href="quiz.php?session_id=<?php echo (int)$session['id']; ?>" class="btn btn-sm" style="background:#7c3aed; color:white; border:none; border-radius:6px; padding:8px 12px;">
                                                        <i class="fas fa-play"></i> Take Quiz
                                                    </a>
                                                <?php elseif ((int)$session['id'] > 0): ?>
                                                    <form method="POST" action="?view=view_all_sessions" style="margin:0;">
                                                        <input type="hidden" name="session_id" value="<?php echo (int)$session['id']; ?>">
                                                        <button type="submit" name="register_session" class="btn btn-success btn-sm" style="border:none; border-radius:6px; padding:8px 12px;">
                                                            <i class="fas fa-user-plus"></i> Get Registered
                                                        </button>
                                                    </form>
                                                <?php else: ?>
                                                    <form method="POST" action="?view=view_all_sessions" style="margin:0;">
                                                        <input type="hidden" name="session_id" value="0">
                                                        <input type="hidden" name="catalog_title" value="<?php echo htmlspecialchars($session['title'], ENT_QUOTES); ?>">
                                                        <button type="submit" name="register_session" class="btn btn-success btn-sm" style="border:none; border-radius:6px; padding:8px 12px;">
                                                            <i class="fas fa-user-plus"></i> Register
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state" style="text-align: center; padding: 60px 24px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); border: 1px solid #e5e7eb;">
                        <div class="empty-state-icon" style="font-size: 64px; color: #9ca3af; margin-bottom: 24px; opacity: 0.6;">
                            <i class="fas fa-inbox"></i>
                        </div>
                        <h3 style="font-size: 24px; color: #1f2937; margin-bottom: 12px; font-weight: 600;">No Sessions Available</h3>
                        <p style="color: #6b7280; font-size: 16px; margin: 0; line-height: 1.6;">
                            There are no sessions available at the moment. Please check back later!
                        </p>
                    </div>
                <?php endif; ?>

                <?php
            } elseif ($view == 'view_registered_sessions') {
                // View Registered Sessions - Show only sessions student is registered for
                ?>
                <div class="welcome-header">
                    <h1><i class="fas fa-check-circle"></i> Your Registered Sessions</h1>
                    <p>Here are all the sessions you have registered for. Click "Take Quiz" to participate.</p>

                    <div class="student-info-grid">
                        <div class="info-badge">
                            <strong>Total Registered:</strong> <?php echo count($registered_sessions); ?>
                        </div>
                        <div class="info-badge">
                            <strong>Completed:</strong> <?php echo count(array_filter($registered_sessions, function($s) { return $s['registration_status'] === 'completed'; })); ?>
                        </div>
                        <div class="info-badge">
                            <strong>In Progress:</strong> <?php echo count(array_filter($registered_sessions, function($s) { return $s['registration_status'] === 'registered'; })); ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($sessions_by_year)): ?>
                    <?php foreach ($sessions_by_year as $year => $year_sessions): ?>
                        <div class="year-section" style="margin-bottom: 40px;">
                            <h3 class="year-title" style="color: #7c3aed; margin-bottom: 20px; padding-bottom: 12px; border-bottom: 3px solid #f0f4ff; font-size: 22px; font-weight: 700;">
                                <i class="fas fa-graduation-cap"></i> Year <?php echo $year; ?> Sessions
                            </h3>
                            <div class="table-responsive" style="background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; overflow: hidden;">
                                <table class="table mb-0" style="width:100%;">
                                    <thead style="background: #f8fafc;">
                                        <tr>
                                            <th style="padding: 12px 16px;">Session Title</th>
                                            <th style="padding: 12px 16px;">Year</th>
                                            <th style="padding: 12px 16px;">Status</th>
                                            <th style="padding: 12px 16px;">Registered On</th>
                                            <th style="padding: 12px 16px;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($year_sessions as $session): ?>
                                        <tr>
                                            <td style="padding: 12px 16px; font-weight: 600;"><?php echo htmlspecialchars(session_display_label($session)); ?></td>
                                            <td style="padding: 12px 16px;"><?php echo htmlspecialchars($session['year']); ?></td>
                                            <td style="padding: 12px 16px;">
                                                <span style="padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;
                                                    <?php
                                                    switch($session['registration_status']) {
                                                        case 'completed': echo 'background: #d1fae5; color: #065f46;'; break;
                                                        case 'registered': echo 'background: #dbeafe; color: #1e40af;'; break;
                                                        default: echo 'background: #f3f4f6; color: #374151;';
                                                    }
                                                    ?>">
                                                    <?php echo ucfirst($session['registration_status']); ?>
                                                </span>
                                            </td>
                                            <td style="padding: 12px 16px;"><?php echo date('M j, Y', strtotime($session['registered_at'])); ?></td>
                                            <td style="padding: 12px 16px;">
                                                <a href="quiz.php?session_id=<?php echo (int)$session['id']; ?>" class="btn btn-sm" style="background:#7c3aed; color:white; border:none; border-radius:6px; padding:8px 12px;">
                                                    <i class="fas fa-play"></i> Take Quiz
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="alert alert-info" style="padding: 24px; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border: 1px solid #bfdbfe; border-radius: 12px; color: #1e40af; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                        <i class="fas fa-info-circle"></i> You haven't registered for any sessions yet. Visit "View All Sessions" to register for sessions!
                    </div>
                <?php endif; ?>

            <?php
            } elseif ($view == 'suggest_session') {
                // Suggest a Session - Same form as main website
                $suggest_message = '';
                $suggest_message_type = '';

                if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['suggest_session_submit'])) {
                    $name = trim($_POST['name'] ?? '');
                    $roll_number = trim($_POST['roll_number'] ?? '');
                    $year = $_POST['year'] ?? '';
                    $branch = trim($_POST['branch'] ?? '');
                    $section = trim($_POST['section'] ?? '');
                    $session_desired = trim($_POST['session_desired'] ?? '');
                    $other_query = trim($_POST['other_query'] ?? '');

                    // Validation
                    if (empty($name) || empty($roll_number) || empty($year) || empty($branch) || empty($section) || empty($session_desired)) {
                        $suggest_message = 'All required fields must be filled.';
                        $suggest_message_type = 'danger';
                    } else {
                        // Insert into database
                        $insert_sql = "INSERT INTO iap_session_suggestions (name, roll_number, year, branch, section, session_desired, other_query, status) 
                                      VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')";
                        $insert_stmt = $conn->prepare($insert_sql);
                        
                        if ($insert_stmt) {
                            $insert_stmt->bind_param("sssssss", $name, $roll_number, $year, $branch, $section, $session_desired, $other_query);
                            
                            if ($insert_stmt->execute()) {
                                $suggest_message = 'Thank you for your session suggestion! We\'ll review it and get back to you soon.';
                                $suggest_message_type = 'success';
                                // Clear form
                                $_POST = [];
                            } else {
                                $suggest_message = 'Error submitting suggestion. Please try again.';
                                $suggest_message_type = 'danger';
                            }
                            $insert_stmt->close();
                        } else {
                            $suggest_message = 'Database error. Please try again.';
                            $suggest_message_type = 'danger';
                        }
                    }
                }
                ?>

                <div class="welcome-header">
                    <h1><i class="fas fa-lightbulb"></i> Suggest a Session</h1>
                    <p>Have an idea for a session that would help you and your peers? Share your suggestion with us!</p>
                </div>

                <div class="suggest-session-container" style="background: white; border-radius: 16px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); padding: 32px; margin-bottom: 32px;">
                    <?php if (!empty($suggest_message)): ?>
                        <div class="alert alert-<?php echo $suggest_message_type; ?> alert-dismissible fade show" role="alert">
                            <i class="fas fa-<?php echo $suggest_message_type === 'success' ? 'check-circle' : 'exclamation-circle'; ?>"></i>
                            <?php echo htmlspecialchars($suggest_message); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="?view=suggest_session" id="suggestSessionForm">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="suggest_name" class="form-label">Name <span style="color: #ef4444;">*</span></label>
                                <input type="text" class="form-control" id="suggest_name" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? $_SESSION['full_name']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="suggest_roll_number" class="form-label">Roll Number <span style="color: #ef4444;">*</span></label>
                                <input type="text" class="form-control" id="suggest_roll_number" name="roll_number" value="<?php echo htmlspecialchars($_POST['roll_number'] ?? $_SESSION['roll_number']); ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="suggest_year" class="form-label">Year <span style="color: #ef4444;">*</span></label>
                                <select class="form-control" id="suggest_year" name="year" required>
                                    <option value="">-- Select Year --</option>
                                    <option value="1" <?php echo ($_POST['year'] ?? $_SESSION['year']) == '1' ? 'selected' : ''; ?>>Year 1</option>
                                    <option value="2" <?php echo ($_POST['year'] ?? $_SESSION['year']) == '2' ? 'selected' : ''; ?>>Year 2</option>
                                    <option value="3" <?php echo ($_POST['year'] ?? $_SESSION['year']) == '3' ? 'selected' : ''; ?>>Year 3</option>
                                    <option value="4" <?php echo ($_POST['year'] ?? $_SESSION['year']) == '4' ? 'selected' : ''; ?>>Year 4</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="suggest_branch" class="form-label">Branch/Department <span style="color: #ef4444;">*</span></label>
                                <input type="text" class="form-control" id="suggest_branch" name="branch" value="<?php echo htmlspecialchars($_POST['branch'] ?? $_SESSION['department']); ?>" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="suggest_section" class="form-label">Section <span style="color: #ef4444;">*</span></label>
                                <input type="text" class="form-control" id="suggest_section" name="section" placeholder="e.g., A, B, C" value="<?php echo htmlspecialchars($_POST['section'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="suggest_session_desired" class="form-label">Session You Want <span style="color: #ef4444;">*</span></label>
                                <input type="text" class="form-control" id="suggest_session_desired" name="session_desired" placeholder="e.g., Advanced Machine Learning, Web Development Workshop" value="<?php echo htmlspecialchars($_POST['session_desired'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="suggest_other_query" class="form-label">Any Other Query/Suggestion</label>
                            <textarea class="form-control" id="suggest_other_query" name="other_query" rows="4" placeholder="Tell us more about your session idea..."><?php echo htmlspecialchars($_POST['other_query'] ?? ''); ?></textarea>
                            <div class="form-text">Optional - Provide additional details about your suggestion</div>
                        </div>

                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> <strong>Note:</strong> Your suggestion will be reviewed by our team. We appreciate your input in helping us improve the IAP program!
                        </div>

                        <div class="d-flex gap-3">
                            <button type="submit" name="suggest_session_submit" class="btn btn-primary" style="background: #7c3aed; border: none; padding: 12px 24px;">
                                <i class="fas fa-paper-plane"></i> Submit Suggestion
                            </button>
                            <button type="reset" class="btn btn-secondary" style="padding: 12px 24px;">
                                <i class="fas fa-redo"></i> Clear Form
                            </button>
                        </div>
                    </form>
                </div>

                <?php
            }
            ?>

        <!-- Footer -->
        <div class="dashboard-footer">
            <p>&copy; <?php echo date('Y'); ?> IAP Portal - SPECANCIENS. All rights reserved.</p>
        </div>
    </div>

    <div id="sessionContextMenu" class="quick-context-menu">
        <button type="button" id="contextRegisterBtn"><i class="fas fa-user-plus"></i> Register for this Session</button>
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
    <script src="Student/roll_validation.js"></script>

    <script>
    // Function to view session details
    function viewSessionDetail(sessionId, sessionTitle, sessionYear) {
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
            <p><strong>Session Year:</strong> ${sessionYear || 'N/A'}</p>
            <p><strong>Description:</strong> This session covers important topics related to Industry Awareness Program. Please register to access detailed content and quizzes.</p>
            <div class="alert alert-info">
                <i class="fas fa-lightbulb"></i> <strong>Tip:</strong> Register for this session to unlock quizzes and track your progress!
            </div>
        `;

        registerBtn.style.display = 'inline-block';
        registerBtn.onclick = function() {
            registerForSession(sessionId, sessionTitle, sessionYear);
        };

        const modal = new bootstrap.Modal(document.getElementById('sessionModal'));
        modal.show();
    }

    const loggedInStudentYear = <?php echo json_encode((string)$student_year); ?>;

    function canStudentRegisterForYear(sessionYear) {
        return String(loggedInStudentYear) === String(sessionYear);
    }

    // Function to register for a session
    function registerForSession(sessionId, sessionTitle, sessionYear) {
        if (sessionYear && !canStudentRegisterForYear(sessionYear)) {
            alert('You can only register for sessions of your academic year');
            return;
        }

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
        // Quick register from visible card buttons.
        const quickRegisterButtons = document.querySelectorAll('.quick-register-btn');
        quickRegisterButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const sessionId = parseInt(btn.dataset.sessionId || '0', 10);
                const sessionTitle = btn.dataset.sessionTitle || 'Session';
                const sessionYear = btn.dataset.sessionYear || '';
                registerForSession(sessionId, sessionTitle, sessionYear);
            });
        });

        // Right-click custom context menu for desktop users.
        const contextMenu = document.getElementById('sessionContextMenu');
        const contextRegisterBtn = document.getElementById('contextRegisterBtn');
        let contextSession = null;

        document.querySelectorAll('.quick-register-card').forEach(function (card) {
            card.addEventListener('contextmenu', function (event) {
                const registerBtn = card.querySelector('.quick-register-btn');
                if (!registerBtn) {
                    return;
                }
                event.preventDefault();
                contextSession = {
                    id: parseInt(card.dataset.sessionId || '0', 10),
                    title: card.dataset.sessionTitle || 'Session',
                    year: card.dataset.sessionYear || ''
                };
                if (contextMenu) {
                    contextMenu.style.display = 'block';
                    contextMenu.style.left = `${event.clientX}px`;
                    contextMenu.style.top = `${event.clientY}px`;
                }
            });
        });

        if (contextRegisterBtn) {
            contextRegisterBtn.addEventListener('click', function () {
                if (contextSession) {
                    registerForSession(contextSession.id, contextSession.title, contextSession.year);
                }
                if (contextMenu) {
                    contextMenu.style.display = 'none';
                }
            });
        }

        document.addEventListener('click', function () {
            if (contextMenu) {
                contextMenu.style.display = 'none';
            }
        });

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
            attachRollNumberValidation(profileForm, 'roll_number', 'profileRollNumberError');
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

                if (!isValidRollNumber(rollNumber)) {
                    e.preventDefault();
                    alert('Invalid roll number format. It must be 10 characters long and follow 23BK1A66L5.');
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



