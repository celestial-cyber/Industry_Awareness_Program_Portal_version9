<?php
/**
 * Password Reset Page
 * Displays on first login if student is using default password
 * Allows student to set a new password (optional on first login)
 */

session_start();

// Check if student is logged in
if (!isset($_SESSION['student_id']) || !isset($_SESSION['roll_number'])) {
    header("Location: Student/student_login.php");
    exit();
}

$error_message = '';
$success_message = '';
$is_first_login = isset($_GET['first_login']) && $_GET['first_login'] == 1;

require_once __DIR__ . '/config/db.php';

// Handle password reset form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $action = $_POST['action'] ?? '';
    
    // If user clicked "Continue without changing" on first login
    if ($action === 'skip' && $is_first_login) {
        // Check if there's a selected session to redirect to quiz
        if (isset($_GET['session'])) {
            header("Location: quiz.php?session_id=" . intval($_GET['session']));
        } else {
            header("Location: student_dashboard.php");
        }
        exit();
    }
    
    // Validate new password
    if (empty($new_password)) {
        $error_message = "New password is required";
    } elseif (strlen($new_password) < 8) {
        $error_message = "Password must be at least 8 characters long";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "Passwords do not match";
    } elseif ($action === 'reset') {
        // Hash the new password
        $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
        
        // Update password in database using prepared statement
        $sql = "UPDATE IAP_students SET password = ?, is_password_changed = TRUE WHERE id = ?";
        $stmt = $conn->prepare($sql);
        
        if (!$stmt) {
            $error_message = "Database error: " . $conn->error;
        } else {
            $stmt->bind_param("si", $hashed_password, $_SESSION['student_id']);
            
            if ($stmt->execute()) {
                $success_message = "Password changed successfully! Redirecting...";
                $_SESSION['is_password_changed'] = TRUE;
                
                // Check if there's a selected session to redirect to quiz
                $redirect_url = 'student_dashboard.php';
                if (isset($_GET['session'])) {
                    $redirect_url = 'quiz.php?session_id=' . intval($_GET['session']);
                }
                
                // Redirect after 2 seconds
                echo "<script>
                    setTimeout(function() {
                        window.location.href = '" . $redirect_url . "';
                    }, 2000);
                </script>";
            } else {
                $error_message = "Failed to update password. Please try again.";
            }
            
            $stmt->close();
        }
    }
}

if ($conn) {
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - IAP Portal</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">    <!-- Theme CSS -->
    <link rel="stylesheet" href="theme.css">    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .reset-container {
            width: 100%;
            max-width: 500px;
        }

        .reset-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            overflow: hidden;
        }

        .reset-header {
            background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
            color: white;
            padding: 30px 20px;
            text-align: center;
        }

        .reset-header h1 {
            font-size: 26px;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .reset-header p {
            font-size: 14px;
            margin: 0;
            opacity: 0.9;
        }

        .reset-body {
            padding: 40px;
        }

        .info-box {
            background: #fef3c7;
            border-left: 4px solid #f59e0b;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 25px;
            font-size: 14px;
            color: #78350f;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            display: block;
            font-size: 14px;
        }

        .form-control {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 12px 15px;
            font-size: 14px;
            transition: all 0.3s;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }

        .password-strength {
            font-size: 12px;
            margin-top: 5px;
        }

        .strength-weak {
            color: #dc2626;
        }

        .strength-medium {
            color: #f59e0b;
        }

        .strength-strong {
            color: #16a34a;
        }

        .button-group {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-top: 25px;
        }

        .btn-reset {
            padding: 12px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            font-weight: 700;
            border-radius: 8px;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-reset:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-skip {
            padding: 12px;
            background: #e5e7eb;
            border: 1px solid #d1d5db;
            color: #374151;
            font-weight: 700;
            border-radius: 8px;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-skip:hover {
            background: #d1d5db;
        }

        .alert {
            border-radius: 8px;
            border: none;
            margin-bottom: 25px;
            font-size: 14px;
        }

        .alert-danger {
            background-color: #fff5f5;
            color: #c53030;
        }

        .alert-success {
            background-color: #f0fdf4;
            color: #15803d;
        }

        .input-icon {
            position: relative;
        }

        .input-icon i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            pointer-events: none;
        }

        .input-icon .form-control {
            padding-left: 45px;
        }

        @media (max-width: 500px) {
            .reset-body {
                padding: 25px;
            }

            .button-group {
                grid-template-columns: 1fr;
            }

            .reset-header {
                padding: 20px 15px;
            }

            .reset-header h1 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-card">
            <div class="reset-header">
                <h1><i class="fas fa-key"></i> Change Password</h1>
                <p><?php echo $is_first_login ? "First Login - Update Your Password" : "Reset Your Password"; ?></p>
            </div>

            <div class="reset-body">
                <!-- Info Box -->
                <div class="info-box">
                    <i class="fas fa-info-circle"></i> 
                    <?php 
                    if ($is_first_login) {
                        echo "You are currently using the default password. For security, we recommend changing it to a strong password. You can continue without changing, but updating your password is recommended.";
                    } else {
                        echo "Please enter a strong password to secure your account.";
                    }
                    ?>
                </div>

                <!-- Error Message Alert -->
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error_message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                <?php endif; ?>

                <!-- Success Message Alert -->
                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                    </div>
                <?php endif; ?>

                <!-- Password Reset Form -->
                <form method="POST" action="" novalidate id="passwordForm">
                    <div class="form-group">
                        <label for="new_password" class="form-label">New Password</label>
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                            <input 
                                type="password" 
                                class="form-control" 
                                id="new_password" 
                                name="new_password" 
                                placeholder="Enter new password (min 8 characters)"
                                required
                                autocomplete="new-password"
                                onkeyup="checkPasswordStrength(this.value)"
                            >
                        </div>
                        <div class="password-strength" id="strengthIndicator"></div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password" class="form-label">Confirm Password</label>
                        <div class="input-icon">
                            <i class="fas fa-lock"></i>
                            <input 
                                type="password" 
                                class="form-control" 
                                id="confirm_password" 
                                name="confirm_password" 
                                placeholder="Re-enter your password"
                                required
                                autocomplete="new-password"
                            >
                        </div>
                    </div>

                    <div class="button-group">
                        <button type="submit" class="btn-reset" name="action" value="reset">
                            <i class="fas fa-save"></i> Save Password
                        </button>
                        <?php if ($is_first_login): ?>
                            <button type="submit" class="btn-skip" name="action" value="skip" formnovalidate>
                                <i class="fas fa-arrow-right"></i> Continue
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Password strength indicator
        function checkPasswordStrength(password) {
            const indicator = document.getElementById('strengthIndicator');
            let strength = 'Weak';
            let className = 'strength-weak';
            
            if (password.length >= 8) {
                if (/[a-z]/.test(password) && /[A-Z]/.test(password) && /[0-9]/.test(password) && /[^a-zA-Z0-9]/.test(password)) {
                    strength = 'Strong';
                    className = 'strength-strong';
                } else if (/[a-z]/.test(password) && /[A-Z]/.test(password) && /[0-9]/.test(password)) {
                    strength = 'Medium';
                    className = 'strength-medium';
                } else {
                    strength = 'Weak';
                    className = 'strength-weak';
                }
                indicator.innerHTML = `Password Strength: <span class="${className}">${strength}</span>`;
            } else if (password.length > 0) {
                indicator.innerHTML = `<span class="strength-weak">Password too short (min 8 characters)</span>`;
            } else {
                indicator.innerHTML = '';
            }
        }
    </script>
</body>
</html>
