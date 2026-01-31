<?php
/**
 * Student Registration Page
 * Allows IAP_students to register using roll number and email
 * Automatically assigns default password "student@IAP" hashed with password_hash()
 * Sets is_password_changed = 0 (false) to force password reset on first login
 * Uses MySQLi prepared statements for security
 */

session_start();

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
    } elseif (!preg_match('/^[A-Za-z0-9]{3,20}$/', $roll_number)) {
        $validation_errors[] = "Roll number must be 3-20 alphanumeric characters";
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
    
    if (empty($year) || !in_array($year, ['1', '2', '3', '4'])) {
        $validation_errors[] = "Please select a valid year (1-4)";
    }
    
    if (!empty($validation_errors)) {
        $error_message = implode("<br>", $validation_errors);
    } else {
        // Database connection
        $servername = "localhost";
        $db_username = "root";
        $db_password = "root@123";
        
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
                year ENUM('1', '2', '3', '4') NOT NULL,
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
                    $create_iap_student_sessions_sql = "CREATE TABLE IF NOT EXISTS iap_iap_student_sessions (
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
</head>
<body>
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

                    <!-- Registration Form -->
                    <?php if (empty($success_message)): ?>
                        <form method="POST" action="">
                            <!-- Full Name -->
                            <label>Full Name <span class="required">*</span></label>
                            <input type="text" name="full_name" placeholder="Enter your full name" value="<?php echo htmlspecialchars($full_name ?? ''); ?>" required>

                            <!-- Roll Number -->
                            <label>Roll Number <span class="required">*</span></label>
                            <input type="text" name="roll_number" placeholder="e.g., 2021001" value="<?php echo htmlspecialchars($roll_number ?? ''); ?>" required>

                            <!-- Email -->
                            <label>Email Address <span class="required">*</span></label>
                            <input type="email" name="email" placeholder="example@college.edu" value="<?php echo htmlspecialchars($email ?? ''); ?>" required>

                            <!-- Department -->
                            <label>Department <span class="required">*</span></label>
                            <select name="department" required>
                                <option value="">-- Select Department --</option>
                                <option value="Computer Science" <?php echo ($department ?? '') === 'Computer Science' ? 'selected' : ''; ?>>Computer Science</option>
                                <option value="Electronics" <?php echo ($department ?? '') === 'Electronics' ? 'selected' : ''; ?>>Electronics</option>
                                <option value="Mechanical" <?php echo ($department ?? '') === 'Mechanical' ? 'selected' : ''; ?>>Mechanical</option>
                                <option value="Electrical" <?php echo ($department ?? '') === 'Electrical' ? 'selected' : ''; ?>>Electrical</option>
                                <option value="Civil" <?php echo ($department ?? '') === 'Civil' ? 'selected' : ''; ?>>Civil</option>
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
                            </select>

                            <!-- Submit Button -->
                            <button type="submit" class="register-btn">
                                <i class="fas fa-user-plus"></i> Register
                            </button>
                        </form>
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
</body>
</html>
