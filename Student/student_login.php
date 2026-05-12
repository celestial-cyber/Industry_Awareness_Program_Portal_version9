<?php
/**
 * Student Login Page
 * Authenticates IAP_students using email and password
 * Uses MySQLi prepared statements for security
 */

session_start();

// If already logged in as student, redirect to dashboard
if (isset($_SESSION['student_id']) && isset($_SESSION['email'])) {
    header("Location: student_dashboard.php");
    exit();
}

$error_message = '';
$success_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    // Input validation
    if (empty($email)) {
        $error_message = "Email is required";
    } elseif (empty($password)) {
        $error_message = "Password is required";
    } else {
        require_once __DIR__ . '/../common/db.php';
        
        if ($conn->connect_error) {
            $error_message = "Database connection failed";
        } else {
            
            // Create iap_students table if not exists
            $create_table_sql = "CREATE TABLE IF NOT EXISTS iap_students (
                id INT AUTO_INCREMENT PRIMARY KEY,
                roll_number VARCHAR(50) NOT NULL UNIQUE,
                full_name VARCHAR(255) NOT NULL,
                email VARCHAR(255),
                department VARCHAR(100),
                year ENUM('1', '2', '3', '4') NOT NULL,
                password VARCHAR(255) NOT NULL,
                is_password_changed BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )";
            $conn->query($create_table_sql);
            
            // Create iap_sessions table if not exists
            $create_sessions_table_sql = "CREATE TABLE IF NOT EXISTS iap_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                session_code VARCHAR(255) NULL,
                topic VARCHAR(255) NOT NULL,
                title VARCHAR(255) NULL,
                year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
                description TEXT,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_sessions_session_code (session_code),
                INDEX idx_year (year),
                INDEX idx_created_at (created_at)
            )";
            $conn->query($create_sessions_table_sql);
            
            // Create student_sessions table if not exists
            $create_student_sessions_sql = "CREATE TABLE IF NOT EXISTS iap_student_sessions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                session_id INT NOT NULL,
                registration_status ENUM('registered', 'completed', 'dropped') DEFAULT 'registered',
                registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
                FOREIGN KEY (session_id) REFERENCES iap_sessions(id) ON DELETE CASCADE,
                UNIQUE KEY unique_student_session (student_id, session_id)
            )";
            $conn->query($create_student_sessions_sql);

            $create_psychometric_scores_sql = "CREATE TABLE IF NOT EXISTS iap_psychometric_scores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL UNIQUE,
                score DECIMAL(5,2) NOT NULL,
                trait_a INT,
                trait_b INT,
                trait_c INT,
                trait_d INT,
                completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE
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
            
            // Insert sample student if table is empty (for testing)
            $check_sql = "SELECT COUNT(*) as count FROM iap_students";
            $result = $conn->query($check_sql);
            $row = $result->fetch_assoc();
            
            if ($row['count'] == 0) {
                // Default password is "student@IAP"
                $default_password_hash = password_hash("student@IAP", PASSWORD_BCRYPT);
                $insert_sql = "INSERT IGNORE INTO iap_students (roll_number, full_name, email, department, year, password, is_password_changed) 
                              VALUES ('2021001', 'Test Student', 'test@example.com', 'Computer Science', '1', ?, FALSE)";
                $insert_stmt = $conn->prepare($insert_sql);
                $insert_stmt->bind_param("s", $default_password_hash);
                $insert_stmt->execute();
                $insert_stmt->close();
            }
            
            // Prepare authentication query using prepared statement
            $sql = "SELECT id, roll_number, full_name, email, department, year, password, is_password_changed FROM iap_students WHERE email = ?";
            $stmt = $conn->prepare($sql);
            
            if (!$stmt) {
                $error_message = "Database error: " . $conn->error;
            } else {
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $result = $stmt->get_result();
                
                if ($result->num_rows == 1) {
                    $student = $result->fetch_assoc();
                    
                    // Verify password using password_verify()
                    if (password_verify($password, $student['password'])) {
                        // Password is correct - set session variables
                        $_SESSION['student_id'] = $student['id'];
                        $_SESSION['roll_number'] = $student['roll_number'];
                        $_SESSION['full_name'] = $student['full_name'];
                        $_SESSION['email'] = $student['email'];
                        $_SESSION['department'] = $student['department'];
                        $_SESSION['year'] = $student['year'];
                        $_SESSION['is_password_changed'] = $student['is_password_changed'];
                        
                        // If password is still default (not changed), redirect to password reset
                        if (!$student['is_password_changed']) {
                            header("Location: reset_password.php?first_login=1");
                            exit();
                        } else {
                            // Check if there's a selected session to register for
                            if (isset($_GET['session'])) {
                                $session_id = intval($_GET['session']);
                                
                                // Register student for the selected session
                                $register_sql = "INSERT IGNORE INTO iap_student_sessions (student_id, session_id, registration_status) VALUES (?, ?, 'registered')";
                                $reg_stmt = $conn->prepare($register_sql);
                                $reg_stmt->bind_param("ii", $_SESSION['student_id'], $session_id);
                                $reg_stmt->execute();
                                $reg_stmt->close();
                                
                                // Redirect to session's quiz
                                header("Location: quiz.php?session_id=" . $session_id);
                                exit();
                            }
                            
                            // Password already changed, go to dashboard
                            header("Location: student_dashboard.php");
                            exit();
                        }
                    } else {
                        $error_message = "Invalid password. Please try again.";
                    }
                } else {
                    $error_message = "Email not found. Please check and try again.";
                }
                
                $stmt->close();
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
    <title>Student Login - IAP Portal</title>
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
            height: auto;
            display: block;
        }

        .login-form {
            background: #ffffff;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            max-width: 400px;
            margin: 0 auto;
        }

        .login-form h3 {
            text-align: center;
            color: #7a1fa2;
            margin-bottom: 20px;
            font-size: 22px;
            font-weight: 600;
        }

        .login-form label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #374151;
        }

        .login-form input {
            width: 100%;
            padding: 12px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }

        .login-form input:focus {
            outline: none;
            border-color: #7c3aed;
            box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
        }

        .login-btn {
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

        .login-btn:hover {
            background: #6d28d9;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(124, 58, 237, 0.4);
        }

        .login-btn:active {
            transform: translateY(0);
        }

        .message {
            text-align: center;
            color: #dc2626;
            margin-bottom: 15px;
            padding: 12px;
            background-color: #fee2e2;
            border-radius: 8px;
            font-size: 14px;
        }

        .message.success {
            color: #15803d;
            background-color: #f0fdf4;
        }

        .demo-credentials {
            background: #f3f0ff;
            border-left: 4px solid #7c3aed;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13px;
            color: #5b21b6;
        }

        .demo-credentials strong {
            color: #5b21b6;
        }

        .back-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
        }

        .back-link a {
            color: #7c3aed;
            text-decoration: none;
            font-weight: 600;
        }

        .back-link a:hover {
            text-decoration: underline;
        }

        .register-link {
            text-align: center;
            margin-top: 15px;
            font-size: 14px;
            color: #666;
        }

        .register-link a {
            color: #7c3aed;
            text-decoration: none;
            font-weight: 600;
        }

        .register-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 900px) {
            .hero-content {
                flex-direction: column;
                text-align: center;
                gap: 28px;
            }

            .hero-image {
                order: -1;
            }

            .hero-text h2 {
                font-size: 32px;
            }

            .login-form {
                max-width: 100%;
            }
        }

        @media (max-width: 600px) {
            .hero {
                padding: 40px 0;
                min-height: auto;
                align-items: flex-start;
            }

            .hero-text h2 {
                font-size: 28px;
            }

            .hero-text p {
                font-size: 14px;
            }

            .login-form {
                padding: 30px 20px;
            }

            .login-form h3 {
                font-size: 20px;
                margin-bottom: 15px;
            }
        }
    </style>
</head>
<body>
    <section class="hero">
        <div class="container hero-content">
            <div class="hero-text">
                <h2>Student Login</h2>
              
                <div class="login-form">
                    <h3><i class="fas fa-sign-in-alt"></i> Login</h3>
                    
                    <?php if (!empty($error_message)): ?>
                        <div class="message">
                            <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($success_message)): ?>
                        <div class="message success">
                            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                        </div>
                    <?php endif; ?>

                    <!-- Demo Credentials Box -->
                    <div class="demo-credentials">
                        <strong><i class="fas fa-info-circle"></i> Demo Credentials:</strong><br>
                        Email: <strong>test@example.com</strong><br>
                        Password: <strong>student@IAP</strong>
                    </div>

                    <form method="POST" action="" novalidate>
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email" placeholder="Enter your email" required>

                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>

                        <button type="submit" class="login-btn">
                            <i class="fas fa-sign-in-alt"></i> Login
                        </button>
                    </form>

                    <div class="register-link">
                        Don't have an account? <a href="student_register.php<?php echo isset($_GET['session']) ? '?session=' . intval($_GET['session']) : ''; ?>"><i class="fas fa-user-plus"></i> Register here</a>
                    </div>

                    <div class="back-link">
                        <a href="../index.php"><i class="fas fa-arrow-left"></i> Back to Home</a>
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
