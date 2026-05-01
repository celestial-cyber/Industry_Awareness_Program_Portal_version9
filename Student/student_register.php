<?php
/**
 * Student Registration Page
 * Allows IAP_students to register using roll number and email
 * Automatically assigns default password "student@IAP" hashed with password_hash()
 * Sets is_password_changed = 0 (false) to force password reset on first login
 * Uses MySQLi prepared statements for security
 */

session_start();
require_once 'validation_helpers.php';

// If already logged in as student, redirect to dashboard
if (isset($_SESSION['student_id']) && isset($_SESSION['roll_number'])) {
    header("Location: ../student_dashboard.php");
    exit();
}

$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $roll_number = trim($_POST['roll_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $department = trim($_POST['department'] ?? '');
    $year = trim($_POST['year'] ?? '');
    
    // Input validation
    $validation_errors = [];
    
    if (empty($roll_number)) {
        $validation_errors[] = "Roll number is required";
    } elseif (!validate_roll_number($roll_number)) {
        $validation_errors[] = "Roll number must be exactly 10 characters in format YYBK1ACCXX, for example 23BK1A66L5.";
    }
    
    if (empty($email)) {
        $validation_errors[] = "Email is required";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $validation_errors[] = "Please enter a valid email address";
    }
    
    if (empty($full_name)) {
        $validation_errors[] = "Full name is required";
    } elseif (strlen($full_name) < 2 || strlen($full_name) > 255) {
        $validation_errors[] = "Full name must be between 2 and 255 characters";
    }
    
    if (empty($department)) {
        $validation_errors[] = "Department is required";
    }
    
    if (empty($year) || !in_array($year, ['1', '2', '3', '4', 'Graduate'])) {
        $validation_errors[] = "Please select a valid year ";
    }

    $disclaimer_accepted = trim($_POST['disclaimer_accepted'] ?? '0');
    if ($disclaimer_accepted !== '1') {
        $validation_errors[] = "You must agree to the disclaimer before registering.";
    }
    
    if (!empty($validation_errors)) {
        $error_message = implode("<br>", $validation_errors);
    } else {
        // Database connection
        $servername = "localhost";
        $db_username = "root";
        $db_password = ""; // XAMPP default root password is empty
        
        $conn = new mysqli($servername, $db_username, $db_password);
        
        if ($conn->connect_error) {
            $error_message = "Database connection failed. Please try again later.";
        } else {
            // Create database if not exists
            $sql = "CREATE DATABASE IF NOT EXISTS iap_portal";
            $conn->query($sql);
            $conn->select_db("iap_portal");
            $conn->set_charset("utf8");
            
            // Create IAP_students table if not exists
            $create_table_sql = "CREATE TABLE IF NOT EXISTS IAP_students (
                id INT AUTO_INCREMENT PRIMARY KEY,
                roll_number VARCHAR(50) NOT NULL UNIQUE,
                full_name VARCHAR(255) NOT NULL,
                email VARCHAR(255) NOT NULL UNIQUE,
                department VARCHAR(100),
                year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
                password VARCHAR(255) NOT NULL,
                is_password_changed BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            if (!$conn->query($create_table_sql)) {
                $error_message = "Error creating table: " . $conn->error;
            } else {
                // Create sessions table if not exists
                $create_sessions_table_sql = "CREATE TABLE IF NOT EXISTS sessions (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    title VARCHAR(255) NOT NULL,
                    year ENUM('1', '2', '3', '4') NOT NULL,
                    description TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )";
                
                if (!$conn->query($create_sessions_table_sql)) {
                    $error_message = "Error creating sessions table: " . $conn->error;
                } else {
                    // Create iap_student_sessions table if not exists
                    $create_iap_student_sessions_sql = "CREATE TABLE IF NOT EXISTS iap_student_sessions (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        student_id INT NOT NULL,
                        session_id INT NOT NULL,
                        registration_status ENUM('registered', 'completed', 'dropped') DEFAULT 'registered',
                        registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (student_id) REFERENCES IAP_students(id) ON DELETE CASCADE,
                        FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
                        UNIQUE KEY unique_student_session (student_id, session_id)
                    )";
                    
                    if (!$conn->query($create_iap_student_sessions_sql)) {
                        $error_message = "Error creating iap_student_sessions table: " . $conn->error;
                    } else {
                        // Check if student already exists
                        $check_sql = "SELECT id FROM IAP_students WHERE roll_number = ? OR email = ?";
                        $check_stmt = $conn->prepare($check_sql);
                        
                        if (!$check_stmt) {
                            $error_message = "Database error: " . $conn->error;
                        } else {
                            $check_stmt->bind_param("ss", $roll_number, $email);
                            $check_stmt->execute();
                            $check_result = $check_stmt->get_result();
                            
                            if ($check_result->num_rows > 0) {
                                $error_message = "A student with this roll number or email already exists. Please use different credentials or log in if you already have an account.";
                            } else {
                                // Hash default password: "student@IAP"
                                $default_password = "student@IAP";
                                $password_hash = password_hash($default_password, PASSWORD_BCRYPT);
                                
                                // Insert new student with default password and is_password_changed = 0
                                $insert_sql = "INSERT INTO IAP_students (roll_number, full_name, email, department, year, password, is_password_changed) 
                                              VALUES (?, ?, ?, ?, ?, ?, 0)";
                                
                                $insert_stmt = $conn->prepare($insert_sql);
                                
                                if (!$insert_stmt) {
                                    $error_message = "Database error: " . $conn->error;
                                } else {
                                    $insert_stmt->bind_param("ssssss", $roll_number, $full_name, $email, $department, $year, $password_hash);
                                    
                                    if ($insert_stmt->execute()) {
                                        $new_student_id = $insert_stmt->insert_id;
                                        
                                        // Check if there's a session to register for
                                        if (isset($_GET['session'])) {
                                            $session_id = intval($_GET['session']);
                                            
                                            // Register student for the selected session
                                            $register_sql = "INSERT IGNORE INTO iap_student_sessions (student_id, session_id, registration_status) VALUES (?, ?, 'registered')";
                                            $reg_stmt = $conn->prepare($register_sql);
                                            $reg_stmt->bind_param("ii", $new_student_id, $session_id);
                                            $reg_stmt->execute();
                                            $reg_stmt->close();
                                            
                                            // Auto-login and redirect to password reset then quiz
                                            $_SESSION['student_id'] = $new_student_id;
                                            $_SESSION['roll_number'] = $roll_number;
                                            $_SESSION['full_name'] = $full_name;
                                            $_SESSION['email'] = $email;
                                            $_SESSION['department'] = $department;
                                            $_SESSION['year'] = $year;
                                            $_SESSION['is_password_changed'] = 0;
                                            $_SESSION['selected_session_id'] = $session_id;
                                            
                                            header("Location: ../reset_password.php?first_login=1&session=" . $session_id);
                                            exit();
                                        }
                                        
                                        $success_message = "Registration successful! Your account has been created with the default password: <strong>student@IAP</strong><br>You will be required to change your password on first login.";
                                        // Clear form fields
                                        $roll_number = '';
                                        $email = '';
                                        $full_name = '';
                                        $department = '';
                                        $year = '';
                                    } else {
                                        $error_message = "Registration failed. Please try again. Error: " . $insert_stmt->error;
                                    }
                                    
                                    $insert_stmt->close();
                                }
                            }
                            
                            $check_stmt->close();
                        }
                    }
                }
            }
            
            $conn->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registration - IAP Portal</title>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Bootstrap CSS for modal support -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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

        .container {
            width: 90%;
            max-width: 1200px;
            margin: auto;
        }

        .hero {
            background: linear-gradient(135deg, #eef2ff, #fdf2f8);
            padding: 90px 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
        }

        .hero-content {
            display: flex;
            align-items: center;
            gap: 50px;
        }

        .hero-text h2 {
            font-size: 40px;
            color: #7a1fa2;
            margin-bottom: 15px;
        }

        .hero-text p {
            max-width: 540px;
            font-size: 16px;
            color: #4b5563;
        }

        .hero-image img {
            width: 100%;
            max-width: 420px;
            border-radius: 16px;
        }

        .registration-form {
            background: #ffffff;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            max-width: 400px;
            margin: 0 auto;
        }

        .registration-form h3 {
            text-align: center;
            color: #7a1fa2;
            margin-bottom: 20px;
            font-size: 22px;
            font-weight: 600;
        }

        .registration-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #374151;
            font-size: 14px;
        }

        .registration-form input,
        .registration-form select {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }

        .registration-form input:focus,
        .registration-form select:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }

        .register-btn {
            background: #7c3aed;
            color: #fff;
            border: none;
            padding: 12px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            width: 100%;
            transition: all 0.3s;
        }

        .register-btn:hover {
            background: #6d28d9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(124, 58, 237, 0.4);
        }

        .register-btn:active {
            transform: translateY(0);
        }

        .login-link {
            text-align: center;
            margin-top: 15px;
            font-size: 14px;
            color: #666;
        }

        .login-link a {
            color: #7c3aed;
            text-decoration: none;
            font-weight: 600;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .message {
            text-align: center;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .message-error {
            color: #dc2626;
            background-color: #fee2e2;
        }

        .message-success {
            color: #15803d;
            background-color: #f0fdf4;
        }

        .info-box {
            background: #f3f0ff;
            border-left: 4px solid #7c3aed;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #5b21b6;
        }

        .info-box strong {
            color: #5b21b6;
        }

        .required {
            color: #dc2626;
        }

        @media (max-width: 900px) {
            .hero-content {
                flex-direction: column;
                text-align: center;
            }

            .hero-text h2 {
                font-size: 32px;
            }

            .registration-form {
                max-width: 100%;
            }
        }

        @media (max-width: 600px) {
            .hero {
                padding: 40px 0;
                min-height: auto;
            }

            .hero-text h2 {
                font-size: 28px;
            }

            .hero-text p {
                font-size: 14px;
            }

            .registration-form {
                padding: 30px 20px;
            }

            .registration-form h3 {
                font-size: 20px;
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <!-- First-time registration disclaimer modal -->
    <div class="modal fade" id="firstTimeDisclaimerModal" tabindex="-1" aria-labelledby="firstTimeDisclaimerModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title" id="firstTimeDisclaimerModalLabel">Important Registration Notice</h5>
                </div>
                <div class="modal-body">
                    <p>Please enter accurate information on the registration form.</p>
                    <p>Your data will be stored securely and treated confidentially. We use it only for portal access and student verification.</p>
                    <p>You must agree to this notice before using the registration form.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" id="disclaimerDeclineBtn">I Do Not Agree</button>
                    <button type="button" class="btn btn-primary" id="disclaimerAgreeBtn">I Agree</button>
                </div>
            </div>
        </div>
    </div>

    <section class="hero">
        <div class="container hero-content">
            <div class="hero-text">
                <h2>Student Registration</h2>
                <p>Join the IAP Portal and get access to industry-focused sessions, quizzes, and career development opportunities. Create your account today and start your learning journey.</p>
                <div class="registration-form">
                    <h3><i class="fas fa-user-plus"></i> Register</h3>

                    <!-- Info Box -->
                    <div class="info-box">
                        <strong><i class="fas fa-info-circle"></i> Note:</strong> You will be assigned a default password <strong>student@IAP</strong>. Change it on your first login.
                    </div>

                    <!-- Error Message -->
                    <?php if (!empty($error_message)): ?>
                        <div class="message message-error">
                            <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                        </div>
                    <?php endif; ?>

                    <!-- Success Message -->
                    <?php if (!empty($success_message)): ?>
                        <div class="message message-success">
                            <i class="fas fa-check-circle"></i> <?php echo $success_message; ?><br><br>
                            <a href="student_login.php" style="color: #15803d; font-weight: bold; text-decoration: none;">
                                <i class="fas fa-sign-in-alt"></i> Go to Login
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Blocked message shown until disclaimer is accepted -->
                    <div id="disclaimerBlockedMessage" class="alert alert-danger" style="display: none;">
                        You must agree to the disclaimer before registering.
                    </div>

                    <!-- Registration Form -->
                    <?php if (empty($success_message)): ?>
                        <div id="registrationFormWrapper" style="display: none;">
                            <form id="registrationForm" method="POST" action="">
                                <input type="hidden" id="disclaimerAccepted" name="disclaimer_accepted" value="0">
                            <!-- Full Name -->
                            <label>Full Name <span class="required">*</span></label>
                            <input type="text" name="full_name" placeholder="Enter your full name" value="<?php echo htmlspecialchars($full_name ?? ''); ?>" required>

                            <!-- Roll Number -->
                            <label>Roll Number <span class="required">*</span></label>
                            <input type="text" id="roll_number" name="roll_number" placeholder="e.g., 20BK1A66L1" value="<?php echo htmlspecialchars($roll_number ?? ''); ?>" required>
                            <div id="rollNumberError" class="message message-error" style="display: none; margin-top: 10px;"></div>

                            <!-- Email -->
                            <label>Email Address <span class="required">*</span></label>
                            <input type="email" name="email" placeholder="example@college.edu" value="<?php echo htmlspecialchars($email ?? ''); ?>" required>

                            <!-- Department -->
                            <label>Department <span class="required">*</span></label>
                            <select name="department" required>
                                <option value="">-- Select Department --</option>
                                <option value="Computer Science" <?php echo ($department ?? '') === 'Computer Science' ? 'selected' : ''; ?>>CSE</option>
                                <option value="Electronics" <?php echo ($department ?? '') === 'Electronics' ? 'selected' : ''; ?>>ECE</option>
                                <option value="Mechanical" <?php echo ($department ?? '') === 'Mechanical' ? 'selected' : ''; ?>>Mechanical</option>
                                <option value="Electrical" <?php echo ($department ?? '') === 'Electrical' ? 'selected' : ''; ?>>EEE</option>
                                <option value="Civil" <?php echo ($department ?? '') === 'Civil' ? 'selected' : ''; ?>>Civil</option>
                                <option value="AIML" <?php echo ($department ?? '') === 'AIML' ? 'selected' : ''; ?>>AIML</option>
                                <option value="Cybersecurity" <?php echo ($department ?? '') === 'Cybersecurity' ? 'selected' : ''; ?>>Cybersecurity</option>
                                <option value="Data Science" <?php echo ($department ?? '') === 'Data Science' ? 'selected' : ''; ?>>Data Science</option>
                                <option value="Other" <?php echo ($department ?? '') === 'Other' ? 'selected' : ''; ?>>Other</option>
                            </select>

                            <!-- Year -->
                            <label>Year <span class="required">*</span></label>
                            <select name="year" required>
                                <option value="">-- Select Year --</option>
                                <option value="1" <?php echo ($year ?? '') === '1' ? 'selected' : ''; ?>>Year 1</option>
                                <option value="2" <?php echo ($year ?? '') === '2' ? 'selected' : ''; ?>>Year 2</option>
                                <option value="3" <?php echo ($year ?? '') === '3' ? 'selected' : ''; ?>>Year 3</option>
                                <option value="4" <?php echo ($year ?? '') === '4' ? 'selected' : ''; ?>>Year 4</option>
                                <option value="Graduate" <?php echo ($year ?? '') === 'Graduate' ? 'selected' : ''; ?>>Graduate</option>
                            </select>

                            <!-- Submit Button -->
                            <button type="submit" class="register-btn">
                                <i class="fas fa-user-plus"></i> Register
                            </button>
                        </form>
                        </div>
                    <?php endif; ?>

                    <!-- Login Link -->
                    <div class="login-link">
                        Already have an account? <a href="student_login.php"><i class="fas fa-sign-in-alt"></i> Log in here</a>
                    </div>
                </div>
            </div>

            <div class="hero-image">
                <img src="../images/industry_awareness.jpg" alt="Industrial Awareness">
            </div>
        </div>
    </section>

    <!-- Bootstrap JS bundle -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="roll_validation.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Elements used to control the registration flow.
            const registrationForm = document.getElementById('registrationForm');
            const disclaimerHiddenField = document.getElementById('disclaimerAccepted');
            const formWrapper = document.getElementById('registrationFormWrapper');
            const blockedMessage = document.getElementById('disclaimerBlockedMessage');
            const agreeBtn = document.getElementById('disclaimerAgreeBtn');
            const declineBtn = document.getElementById('disclaimerDeclineBtn');
            const disclaimerModalEl = document.getElementById('firstTimeDisclaimerModal');

            // Create the Bootstrap modal instance.
            const disclaimerModal = (window.bootstrap && disclaimerModalEl)
                ? new bootstrap.Modal(disclaimerModalEl, {
                    backdrop: 'static',
                    keyboard: false
                })
                : null;

            // Show the registration form and hide any blocked message.
            function showForm() {
                if (formWrapper) {
                    formWrapper.style.display = 'block';
                }
                if (blockedMessage) {
                    blockedMessage.style.display = 'none';
                }
                if (disclaimerHiddenField) {
                    disclaimerHiddenField.value = '1';
                }
            }

            // Hide the registration form and keep the disclaimer state unaccepted.
            function hideForm() {
                if (formWrapper) {
                    formWrapper.style.display = 'none';
                }
                if (disclaimerHiddenField) {
                    disclaimerHiddenField.value = '0';
                }
            }

            // Always require the disclaimer modal on every visit to this page.
            hideForm();
            if (disclaimerModal) {
                disclaimerModal.show();
            } else if (blockedMessage) {
                blockedMessage.style.display = 'block';
                blockedMessage.textContent = 'Please enable JavaScript and accept the disclaimer before registering.';
            }

            if (registrationForm) {
                // Attach roll number validation to the registration form.
                attachRollNumberValidation(registrationForm, 'roll_number', 'rollNumberError');

                // Prevent form submit if disclaimer is not accepted.
                registrationForm.addEventListener('submit', function (event) {
                    const currentAccepted = disclaimerHiddenField && disclaimerHiddenField.value === '1';
                    if (!currentAccepted) {
                        event.preventDefault();
                        if (blockedMessage) {
                            blockedMessage.style.display = 'block';
                        }
                        if (disclaimerModal) {
                            disclaimerModal.show();
                        }
                        return false;
                    }
                    return true;
                });
            }

            if (agreeBtn) {
                agreeBtn.addEventListener('click', function () {
                    if (disclaimerHiddenField) {
                        disclaimerHiddenField.value = '1';
                    }
                    if (disclaimerModal) {
                        disclaimerModal.hide();
                    }
                    showForm();
                });
            }

            if (declineBtn) {
                declineBtn.addEventListener('click', function () {
                    if (disclaimerModal) {
                        disclaimerModal.hide();
                    }
                    hideForm();
                    if (blockedMessage) {
                        blockedMessage.style.display = 'block';
                        blockedMessage.textContent = 'You must agree to the disclaimer before registering.';
                    }
                });
            }
        });
    </script>
</body>
</html>
