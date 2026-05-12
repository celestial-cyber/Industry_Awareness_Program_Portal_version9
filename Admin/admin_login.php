<?php
session_start();
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header("Location: admin_dashboard.php");
    exit();
}

$message = '';
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'];
    $password = $_POST['password'];

    require_once __DIR__ . '/../common/db.php';

    // Create tables if not exist
    $sql = "CREATE TABLE IF NOT EXISTS iap_users_details (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(255) NOT NULL UNIQUE,
        email VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role ENUM('admin', 'student') NOT NULL
    );";
    $conn->query($sql);

    // Alter table to add email column if it doesn't exist
    $check_email = $conn->query("SHOW COLUMNS FROM iap_users_details LIKE 'email'");
    if ($check_email->num_rows == 0) {
        $conn->query("ALTER TABLE iap_users_details ADD COLUMN email VARCHAR(255) NOT NULL UNIQUE DEFAULT 'temp@example.com'");
    }

    // Insert default admin if not exists
    $sql = 'INSERT IGNORE INTO iap_users_details (username, email, password, role) VALUES (\'admin\', \'admin@example.com\', \'$2y$10$xHDNFM0xYFstLYe.BIHMUu4ZxCcEeKOQ3psUy85ZcbsCqdbWUy2Z.\', \'admin\')';
    $conn->query($sql);

    $sql = "SELECT * FROM iap_users_details WHERE email = ? AND role = 'admin'";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Error preparing statement: " . $conn->error);
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows == 1) {
        $user = $result->fetch_assoc();
        if (password_verify($password, $user['password'])) {
            $_SESSION['role'] = 'admin';
            $_SESSION['username'] = $user['username'];
            header("Location: admin_dashboard.php");
            exit();
        } else {
            $message = "Invalid password.";
        }
    } else {
        $message = "Invalid email.";
    }

    $stmt->close();
    $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login - IAP Portal</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
            color: #5b21b6;
            margin-bottom: 20px;
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
        }

        .login-btn:hover {
            background: #5b21b6;
        }

        .message {
            text-align: center;
            color: #dc2626;
            margin-bottom: 15px;
        }

        .back-home-wrap {
            margin-top: 14px;
            text-align: center;
        }

        .back-home-link {
            display: inline-block;
            color: #4c1d95;
            font-weight: 600;
            text-decoration: none;
            padding: 8px 12px;
            border-radius: 8px;
            transition: background 0.2s ease;
        }

        .back-home-link:hover {
            background: #ede9fe;
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

            .hero {
                padding: 40px 0;
                min-height: auto;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <section class="hero">
        <div class="container hero-content">
            <div class="hero-text">
                <h2>Admin Login</h2>
                
                <div class="login-form">
                    <h3>Login</h3>
                    <?php if ($message): ?>
                        <p class="message"><?php echo $message; ?></p>
                    <?php endif; ?>
                    <form method="post" action="">
                        <label for="email">Email:</label>
                        <input type="email" id="email" name="email" required>

                        <label for="password">Password:</label>
                        <input type="password" id="password" name="password" required>

                        <button type="submit" class="login-btn">Login</button>
                    </form>
                    <div class="back-home-wrap">
                        <a href="../index.php" class="back-home-link">
                            <i class="fas fa-arrow-left"></i> Back to Home
                        </a>
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
