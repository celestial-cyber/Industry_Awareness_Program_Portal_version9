<?php
/**
 * Password Reset Confirmation
 * Handles the actual password update when student clicks email link
 */

// Start session to check if user is logged in
session_start();

// Include database connection
require_once 'Student/student_session_check.php';

$message = '';
$message_type = 'info';
$show_form = false;
$token_valid = false;

if (isset($_GET['token'])) {
    $token = $_GET['token'];

    try {
        // Verify token and check expiry
        $sql = "SELECT id, full_name, email, reset_token_expiry FROM IAP_students WHERE reset_token = ? AND reset_token_expiry > NOW()";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $student = $result->fetch_assoc();
            $token_valid = true;
            $show_form = true;
            $student_id = $student['id'];
            $student_name = $student['full_name'];
        } else {
            $message = 'Invalid or expired reset token. Please request a new password reset.';
            $message_type = 'danger';
        }

        $stmt->close();
    } catch (Exception $e) {
        $message = 'An error occurred while verifying the token.';
        $message_type = 'danger';
    }
}

// Handle password update
if (isset($_POST['update_password']) && $token_valid) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password) || empty($confirm_password)) {
        $message = 'All fields are required.';
        $message_type = 'danger';
    } elseif ($new_password !== $confirm_password) {
        $message = 'Passwords do not match.';
        $message_type = 'danger';
    } elseif (strlen($new_password) < 8) {
        $message = 'Password must be at least 8 characters long.';
        $message_type = 'danger';
    } else {
        try {
            // Hash the new password
            $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

            // Update password and clear reset token
            $update_sql = "UPDATE IAP_students SET password = ?, reset_token = NULL, reset_token_expiry = NULL WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bind_param("si", $hashed_password, $student_id);

            if ($update_stmt->execute()) {
                $message = 'Password updated successfully! You can now log in with your new password.';
                $message_type = 'success';
                $show_form = false;

                // Log the student out if they were logged in
                if (isset($_SESSION['student_id'])) {
                    session_destroy();
                }
            } else {
                $message = 'Failed to update password. Please try again.';
                $message_type = 'danger';
            }

            $update_stmt->close();
        } catch (Exception $e) {
            $message = 'An error occurred while updating the password.';
            $message_type = 'danger';
        }
    }
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
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --primary-color: #7c3aed;
            --primary-light: #f3e8ff;
            --primary-dark: #5b21b6;
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
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .reset-container {
            max-width: 500px;
            width: 100%;
            background: white;
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
            padding: 40px;
            margin: 20px;
        }

        .reset-header {
            text-align: center;
            margin-bottom: 32px;
        }

        .reset-header h1 {
            color: var(--primary-color);
            font-size: 28px;
            margin-bottom: 8px;
            font-weight: 700;
        }

        .reset-header p {
            color: var(--text-secondary);
            margin: 0;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
            padding: 12px 24px;
            font-weight: 600;
            width: 100%;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .alert {
            border-radius: 8px;
            border: none;
        }

        .password-strength {
            margin-top: 8px;
        }

        .progress {
            height: 8px;
        }
    </style>
</head>
<body>
    <div class="reset-container">
        <div class="reset-header">
            <i class="fas fa-key fa-3x text-primary mb-3"></i>
            <h1>Reset Your Password</h1>
            <p>Enter your new password below</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $message_type; ?>" role="alert">
                <i class="fas fa-<?php echo $message_type === 'success' ? 'check-circle' : ($message_type === 'danger' ? 'exclamation-circle' : 'info-circle'); ?>"></i>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <?php if ($show_form): ?>
            <form method="POST" action="" id="updatePasswordForm">
                <div class="mb-3">
                    <label for="new_password" class="form-label">New Password</label>
                    <input type="password" class="form-control" id="new_password" name="new_password" required minlength="8">
                    <div class="form-text">Minimum 8 characters required.</div>
                </div>

                <div class="mb-3">
                    <label for="confirm_password" class="form-label">Confirm New Password</label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required minlength="8">
                    <div class="form-text">Re-enter your new password.</div>
                </div>

                <div class="password-strength mb-3" id="passwordStrength" style="display: none;">
                    <small class="text-muted">Password strength: <span id="strengthText">Weak</span></small>
                    <div class="progress mt-1">
                        <div class="progress-bar" id="strengthBar" role="progressbar" style="width: 0%"></div>
                    </div>
                </div>

                <button type="submit" name="update_password" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Password
                </button>
            </form>

            <div class="text-center mt-3">
                <a href="Student/student_login.php" class="text-decoration-none">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>
        <?php else: ?>
            <div class="text-center">
                <a href="Student/student_login.php" class="btn btn-primary">
                    <i class="fas fa-sign-in-alt"></i> Go to Login
                </a>
            </div>
        <?php endif; ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
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

        // Show password strength on input
        document.getElementById('new_password').addEventListener('input', function() {
            const password = this.value;
            const strengthDiv = document.getElementById('passwordStrength');

            if (password.length > 0) {
                strengthDiv.style.display = 'block';
                checkPasswordStrength(password);
            } else {
                strengthDiv.style.display = 'none';
            }
        });

        // Form validation
        document.getElementById('updatePasswordForm').addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Passwords do not match!');
                return false;
            }

            if (newPassword.length < 8) {
                e.preventDefault();
                alert('Password must be at least 8 characters long!');
                return false;
            }
        });
    </script>
</body>
</html>
