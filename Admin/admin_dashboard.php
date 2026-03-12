<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$servername = "localhost";
$username = "root";
$password = "root@123";

$conn = new mysqli($servername, $username, $password);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS iap_portal";
$conn->query($sql);

// Select the database
$conn->select_db("iap_portal");

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
if ($check_email && $check_email->num_rows == 0) {
    $conn->query("ALTER TABLE iap_users_details ADD COLUMN email VARCHAR(255) NOT NULL UNIQUE DEFAULT 'temp@example.com'");
}

$sql = "CREATE TABLE IF NOT EXISTS iap_session_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL,
    year ENUM('1', '2', '3', '4') NOT NULL,
    department VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    session_desired VARCHAR(255) NOT NULL,
    other_query TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic VARCHAR(255) NOT NULL,
    year ENUM('1', '2', '3', '4') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS iap_session_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL,
    year ENUM('1', '2', '3', '4') NOT NULL,
    branch VARCHAR(100) NOT NULL,
    section VARCHAR(100) NOT NULL,
    session_desired TEXT NOT NULL,
    other_query TEXT,
    status ENUM('pending', 'reviewed', 'approved', 'rejected') DEFAULT 'pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS iap_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    roll_number VARCHAR(50) NOT NULL UNIQUE,
    department VARCHAR(100),
    year ENUM('1', '2', '3', '4'),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS iap_psychometric_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL UNIQUE,
    score DECIMAL(5,2) NOT NULL,
    trait_a INT,
    trait_b INT,
    trait_c INT,
    trait_d INT,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS iap_student_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
);";
$conn->query($sql);

// Insert default admin if not exists
$sql = 'INSERT IGNORE INTO iap_users_details (username, email, password, role) VALUES (\'admin\', \'admin@example.com\', \'$2y$10$xHDNFM0xYFstLYe.BIHMUu4ZxCcEeKOQ3psUy85ZcbsCqdbWUy2Z.\', \'admin\')';
$conn->query($sql);

$message = '';
$page = isset($_GET['page']) ? $_GET['page'] : 'home';

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['create_session'])) {
    $topic = $_POST['topic'];
    $year = $_POST['year'];

    $sql = "INSERT INTO sessions (topic, year) VALUES (?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $topic, $year);
    if ($stmt->execute()) {
        $message = "Session created successfully!";
    } else {
        $message = "Error: " . $conn->error;
    }
    $stmt->close();
}

// Handle approve action for session requests
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['approve_request'])) {
    $request_id = intval($_POST['request_id']);
    
    $sql = "UPDATE iap_session_suggestions SET status = 'approved' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $request_id);

    if ($stmt->execute()) {
        $message = "Session request approved successfully!";
    } else {
        $message = "Error approving request: " . $conn->error;
    }
    $stmt->close();
}

// Handle reject action for session requests
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['reject_request'])) {
    $request_id = intval($_POST['request_id']);
    
    $sql = "UPDATE iap_session_suggestions SET status = 'rejected' WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $request_id);

    if ($stmt->execute()) {
        $message = "Session request rejected successfully!";
    } else {
        $message = "Error rejecting request: " . $conn->error;
    }
    $stmt->close();
}

if ($page == 'requests') {
    // Fetch pending requests
    $sql_pending = "SELECT * FROM iap_session_suggestions WHERE status = 'pending' ORDER BY submitted_at DESC";
    $pending_result = $conn->query($sql_pending);

    if (!$pending_result) {
        die("MySQL Error fetching pending session suggestions: " . $conn->error);
    }

    // Fetch approved requests
    $sql_approved = "SELECT * FROM iap_session_suggestions WHERE status = 'approved' ORDER BY submitted_at DESC";
    $approved_result = $conn->query($sql_approved);

    if (!$approved_result) {
        die("MySQL Error fetching approved session suggestions: " . $conn->error);
    }

    // Fetch rejected requests
    $sql_rejected = "SELECT * FROM iap_session_suggestions WHERE status = 'rejected' ORDER BY submitted_at DESC";
    $rejected_result = $conn->query($sql_rejected);

    if (!$rejected_result) {
        die("MySQL Error fetching rejected session suggestions: " . $conn->error);
    }

} else if ($page == 'registered_students') {
    // Fetch all students who registered through student_sessions
    $sql = "SELECT DISTINCT
                s.id,
                s.full_name,
                s.email,
                s.roll_number,
                s.department,
                s.year,
                COUNT(DISTINCT ss.session_id) as sessions_count,
                GROUP_CONCAT(DISTINCT sess.topic SEPARATOR ', ') as registered_sessions,
                COALESCE(ps.score, 0) as iap_psychometric_score,
                CASE WHEN ps.score IS NOT NULL THEN 'Completed' ELSE 'Not Taken' END as assessment_status
            FROM iap_students s
            LEFT JOIN iap_student_sessions ss ON s.id = ss.student_id
            LEFT JOIN sessions sess ON ss.session_id = sess.id
            LEFT JOIN iap_psychometric_scores ps ON s.id = ps.student_id
            GROUP BY s.id
            ORDER BY s.created_at DESC";
    $registered_students_result = $conn->query($sql);
    if (!$registered_students_result) {
        // Fallback query if the join fails
        $sql = "SELECT id, full_name, email, roll_number, department, year FROM iap_students ORDER BY created_at DESC";
        $registered_students_result = $conn->query($sql);
        if (!$registered_students_result) {
            die("MySQL Error fetching registered students: " . $conn->error);
        }
    }
} else if ($page == 'psychometric_status') {
    // Fetch students who have taken the psychometric test
    $sql_completed = "SELECT s.id, s.full_name, s.email, s.roll_number, s.department, s.year,
                             ps.score, ps.trait_a, ps.trait_b, ps.trait_c, ps.trait_d, ps.completed_at
                      FROM iap_students s
                      INNER JOIN iap_psychometric_scores ps ON s.id = ps.student_id
                      ORDER BY ps.completed_at DESC";
    $completed_result = $conn->query($sql_completed);
    if (!$completed_result) {
        die("MySQL Error fetching completed psychometric results: " . $conn->error);
    }

    // Fetch students who haven't taken the psychometric test
    $sql_pending = "SELECT s.id, s.full_name, s.email, s.roll_number, s.department, s.year
                    FROM iap_students s
                    LEFT JOIN iap_psychometric_scores ps ON s.id = ps.student_id
                    WHERE ps.student_id IS NULL
                    ORDER BY s.created_at DESC";
    $pending_result = $conn->query($sql_pending);
    if (!$pending_result) {
        die("MySQL Error fetching pending psychometric results: " . $conn->error);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Dashboard - IAP Portal</title>
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
        }

        .dashboard {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: #ffffff;
            border-right: 1px solid #e5e7eb;
            padding: 20px;
        }

        .sidebar-logo {
            display: flex;
            align-items: center;
            justify-content: flex-start;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f3e8ff;
        }

        .sidebar-logo img {
            height: 80px;
            width: 80px;
            border-radius: 50%;
            object-fit: cover;
        }

        .sidebar h2 {
            color: #5b21b6;
            margin-bottom: 20px;
        }

        .sidebar ul {
            list-style: none;
        }

        .sidebar li {
            margin-bottom: 10px;
        }

        .sidebar a {
            text-decoration: none;
            color: #374151;
            padding: 10px;
            display: block;
            border-radius: 8px;
            transition: 0.3s;
        }

        .sidebar a:hover, .sidebar a.active {
            background: #f3e8ff;
            color: #5b21b6;
        }

        .main-content {
            flex: 1;
            padding: 40px;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }

        .logout-btn {
            background: #7c3aed;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
        }

        .logout-btn:hover {
            background: #5b21b6;
        }

        .check-report-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: linear-gradient(135deg, #7c3aed 0%, #5b21b6 100%);
            color: #ffffff;
            text-decoration: none;
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 2px 4px rgba(124, 58, 237, 0.2);
            border: none;
            cursor: pointer;
        }

        .check-report-btn:hover {
            background: linear-gradient(135deg, #5b21b6 0%, #4c1d95 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(124, 58, 237, 0.3);
            color: #ffffff;
            text-decoration: none;
        }

        .check-report-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 4px rgba(124, 58, 237, 0.2);
        }

        .check-report-btn i {
            font-size: 12px;
        }

        .section-title {
            color: #5b21b6;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
        }

        .form-group input, .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 16px;
        }

        .btn {
            background: #7c3aed;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
        }

        .btn:hover {
            background: #5b21b6;
        }

        .message {
            padding: 10px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #dc2626;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            background: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        th {
            background: #f3e8ff;
            color: #5b21b6;
            font-weight: 600;
        }

        tr:hover {
            background: #f9fafb;
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
            margin: 32px 0;
        }

        .stat-card {
            background: linear-gradient(135deg, #ffffff 0%, #fafbfc 100%);
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.1);
            border-color: #7c3aed;
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #7c3aed;
            background: linear-gradient(135deg, #f3e8ff 0%, #e9d5ff 100%);
            flex-shrink: 0;
        }

        .stat-content {
            flex: 1;
        }

        .stat-number {
            font-size: 32px;
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 4px;
            line-height: 1.2;
        }

        .stat-label {
            font-size: 14px;
            color: #6b7280;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Responsive adjustments */
        .psychometric-overview .overview-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
        }

        .psychometric-overview .overview-item {
            background: #f9fafb;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }

        .psychometric-overview .overview-item h4 {
            margin: 0 0 12px 0;
            font-size: 16px;
            font-weight: 600;
        }

        .psychometric-overview .progress-bar {
            background: #e5e7eb;
            border-radius: 8px;
            height: 12px;
            margin-bottom: 8px;
        }

        .psychometric-overview .progress-fill {
            background: linear-gradient(90deg, #10b981, #059669);
            height: 100%;
            border-radius: 8px;
            transition: width 0.3s ease;
        }

        .psychometric-overview .engagement-stats {
            display: flex;
            gap: 16px;
        }

        .psychometric-overview .engagement-stats > div {
            text-align: center;
            flex: 1;
        }

        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
                gap: 16px;
                margin: 24px 0;
            }

            .stat-card {
                padding: 20px;
                gap: 14px;
            }

            .stat-icon {
                width: 50px;
                height: 50px;
                font-size: 20px;
            }

            .stat-number {
                font-size: 28px;
            }

            .stat-label {
                font-size: 13px;
            }

            .psychometric-overview .overview-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="dashboard">
        <div class="sidebar">
            <div class="sidebar-logo">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <img src="../images/SA Main logo.jpg" alt="SA Main Logo" title="SA Main">
                    <div style="display: flex; flex-direction: column;">
                        <span style="font-size: 18px; font-weight: 700; color: #7c3aed; line-height: 1.2;">SPECANCIENS</span>
                        <span style="font-size: 14px; font-weight: 600; color: #6b7280; line-height: 1.2;">IAP Portal</span>
                    </div>
                </div>
            </div>
            <h2>Admin Panel</h2>
            <ul>
                <li><a href="?page=home" class="<?php echo $page == 'home' ? 'active' : ''; ?>">Home</a></li>
                <li><a href="?page=create_session" class="<?php echo $page == 'create_session' ? 'active' : ''; ?>">Create Session</a></li>
                <li><a href="?page=requests" class="<?php echo $page == 'requests' ? 'active' : ''; ?>">View Session Requests</a></li>
                <li><a href="?page=registered_students" class="<?php echo $page == 'registered_students' ? 'active' : ''; ?>">View Registered Students</a></li>
                <li><a href="?page=psychometric_status" class="<?php echo $page == 'psychometric_status' ? 'active' : ''; ?>">Check Psychometric Status</a></li>
            </ul>
        </div>

        <div class="main-content">
            <div class="header">
                <h1>Dashboard</h1>
                <a href="../logout.php" class="logout-btn">Logout</a>
            </div>

            <?php if ($message): ?>
                <div class="message <?php echo strpos($message, 'successfully') !== false ? 'success' : 'error'; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($page == 'home'): ?>
                <h2 class="section-title">Welcome to Admin Dashboard</h2>
                <p>Use the sidebar to navigate to different sections.</p>

                <?php
                // Fetch statistics
                $total_students = 0;
                $total_sessions = 0;
                $total_registrations = 0;
                $total_quizzes = 0; // Placeholder - implement when quiz table is available

                $students_result = $conn->query("SELECT COUNT(*) as count FROM iap_students");
                if ($students_result) {
                    $total_students = $students_result->fetch_assoc()['count'] ?? 0;
                }

                $sessions_result = $conn->query("SELECT COUNT(*) as count FROM sessions");
                if ($sessions_result) {
                    $total_sessions = $sessions_result->fetch_assoc()['count'] ?? 0;
                }

                // Assuming iap_session_registrations table exists for registrations
                $registrations_result = $conn->query("SELECT COUNT(*) as count FROM iap_session_registrations");
                if ($registrations_result) {
                    $total_registrations = $registrations_result->fetch_assoc()['count'] ?? 0;
                }

                // Fetch psychometric statistics
                $psychometric_completed = 0;
                $psychometric_pending = 0;
                $avg_psychometric_score = 0;

                $psychometric_completed_result = $conn->query("SELECT COUNT(*) as count FROM iap_psychometric_scores");
                if ($psychometric_completed_result) {
                    $psychometric_completed = $psychometric_completed_result->fetch_assoc()['count'] ?? 0;
                }
                $psychometric_pending = $total_students - $psychometric_completed;

                if ($psychometric_completed > 0) {
                    $avg_result = $conn->query("SELECT AVG(score) as avg_score FROM iap_psychometric_scores");
                    if ($avg_result) {
                        $avg_score_data = $avg_result->fetch_assoc();
                        $avg_psychometric_score = round($avg_score_data['avg_score'], 1);
                    }
                }

                $psychometric_completion_rate = $total_students > 0 ? round(($psychometric_completed / $total_students) * 100, 1) : 0;

                // Fetch session request statistics
                $pending_requests = 0;
                $approved_requests = 0;
                $rejected_requests = 0;

                $pending_requests_result = $conn->query("SELECT COUNT(*) as count FROM iap_session_suggestions WHERE status = 'pending'");
                if ($pending_requests_result) {
                    $pending_requests = $pending_requests_result->fetch_assoc()['count'] ?? 0;
                }

                $approved_requests_result = $conn->query("SELECT COUNT(*) as count FROM iap_session_suggestions WHERE status = 'approved'");
                if ($approved_requests_result) {
                    $approved_requests = $approved_requests_result->fetch_assoc()['count'] ?? 0;
                }

                $rejected_requests_result = $conn->query("SELECT COUNT(*) as count FROM iap_session_suggestions WHERE status = 'rejected'");
                if ($rejected_requests_result) {
                    $rejected_requests = $rejected_requests_result->fetch_assoc()['count'] ?? 0;
                }
                ?>

                <!-- Statistics Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_students); ?></div>
                            <div class="stat-label">Total Students Registered</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-calendar-alt"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_sessions); ?></div>
                            <div class="stat-label">Total Sessions Created</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-clipboard-list"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_registrations); ?></div>
                            <div class="stat-label">Total Session Registrations</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon">
                            <i class="fas fa-question-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($total_quizzes); ?></div>
                            <div class="stat-label">Total Quizzes Taken</div>
                        </div>
                    </div>

                    <!-- Session Request Statistics -->
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #fef3c7, #fde68a); color: #f59e0b;">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($pending_requests); ?></div>
                            <div class="stat-label">Pending Session Requests</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #d1fae5, #a7f3d0); color: #10b981;">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($approved_requests); ?></div>
                            <div class="stat-label">Approved Session Requests</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #fee2e2, #fecaca); color: #ef4444;">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($rejected_requests); ?></div>
                            <div class="stat-label">Rejected Session Requests</div>
                        </div>
                    </div>

                    <!-- Psychometric Test Statistics -->
                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                            <i class="fas fa-brain"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($psychometric_completed); ?></div>
                            <div class="stat-label">Completed Psychometric Tests</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo number_format($psychometric_pending); ?></div>
                            <div class="stat-label">Pending Psychometric Tests</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #2563eb);">
                            <i class="fas fa-chart-line"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $avg_psychometric_score > 0 ? $avg_psychometric_score . '%' : 'N/A'; ?></div>
                            <div class="stat-label">Average Psychometric Score</div>
                        </div>
                    </div>

                    <div class="stat-card">
                        <div class="stat-icon" style="background: linear-gradient(135deg, #8b5cf6, #7c3aed);">
                            <i class="fas fa-percentage"></i>
                        </div>
                        <div class="stat-content">
                            <div class="stat-number"><?php echo $psychometric_completion_rate; ?>%</div>
                            <div class="stat-label">Test Completion Rate</div>
                        </div>
                    </div>
                </div>

                <!-- Psychometric Overview Section -->
                <div class="psychometric-overview" style="margin-top: 40px; background: white; border-radius: 12px; padding: 24px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
                    <h3 style="color: #5b21b6; margin-bottom: 20px;"><i class="fas fa-chart-pie"></i> Psychometric Assessment Overview</h3>

                    <div class="overview-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px;">
                        <div class="overview-item">
                            <h4 style="color: #059669; margin-bottom: 12px;"><i class="fas fa-check-circle"></i> Assessment Status</h4>
                            <div class="progress-bar" style="background: #e5e7eb; border-radius: 8px; height: 12px; margin-bottom: 8px;">
                                <div class="progress-fill" style="background: linear-gradient(90deg, #10b981, #059669); height: 100%; border-radius: 8px; width: <?php echo $psychometric_completion_rate; ?>%;"></div>
                            </div>
                            <p style="margin: 0; font-size: 14px; color: #6b7280;"><?php echo $psychometric_completed; ?> of <?php echo $total_students; ?> students completed (<?php echo $psychometric_completion_rate; ?>%)</p>
                        </div>

                        <div class="overview-item">
                            <h4 style="color: #7c3aed; margin-bottom: 12px;"><i class="fas fa-trophy"></i> Performance Insights</h4>
                            <?php if ($avg_psychometric_score > 0): ?>
                                <p style="margin: 0 0 8px 0; font-size: 16px; font-weight: 600; color: #5b21b6;">Average Score: <?php echo $avg_psychometric_score; ?>%</p>
                                <p style="margin: 0; font-size: 14px; color: #6b7280;">
                                    <?php
                                    if ($avg_psychometric_score >= 80) echo "Excellent performance! Students show strong analytical and organizational skills.";
                                    elseif ($avg_psychometric_score >= 60) echo "Good performance! Solid analytical and organizational abilities demonstrated.";
                                    elseif ($avg_psychometric_score >= 40) echo "Moderate performance. Room for development in analytical skills.";
                                    else echo "Areas for improvement in analytical and organizational skills.";
                                    ?>
                                </p>
                            <?php else: ?>
                                <p style="margin: 0; font-size: 14px; color: #6b7280;">No assessments completed yet.</p>
                            <?php endif; ?>
                        </div>

                        <div class="overview-item">
                            <h4 style="color: #f59e0b; margin-bottom: 12px;"><i class="fas fa-users"></i> Student Engagement</h4>
                            <div class="engagement-stats" style="display: flex; gap: 16px;">
                                <div style="text-align: center;">
                                    <div style="font-size: 24px; font-weight: 700; color: #059669;"><?php echo $psychometric_completed; ?></div>
                                    <div style="font-size: 12px; color: #6b7280;">Completed</div>
                                </div>
                                <div style="text-align: center;">
                                    <div style="font-size: 24px; font-weight: 700; color: #f59e0b;"><?php echo $psychometric_pending; ?></div>
                                    <div style="font-size: 12px; color: #6b7280;">Pending</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php elseif ($page == 'create_session'): ?>
                <h2 class="section-title">Create New Session</h2>
                <form method="post" action="">
                    <div class="form-group">
                        <label for="topic">Topic:</label>
                        <input type="text" id="topic" name="topic" required>
                    </div>
                    <div class="form-group">
                        <label for="year">Year:</label>
                        <select id="year" name="year" required>
                            <option value="1">Year 1</option>
                            <option value="2">Year 2</option>
                            <option value="3">Year 3</option>
                            <option value="4">Year 4</option>
                        </select>
                    </div>
                    <button type="submit" name="create_session" class="btn">Create Session</button>
                </form>

            <?php elseif ($page == 'requests'): ?>
                <h2 class="section-title">Session Requests & Suggestions</h2>
                
                <!-- Pending Requests Section -->
                <div style="margin-bottom: 50px;">
                    <h3 style="color: #f59e0b; margin-bottom: 20px; padding: 15px; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); border-radius: 12px; border-left: 4px solid #f59e0b;">
                        <i class="fas fa-clock"></i> Pending Session Requests
                    </h3>
                    <?php if ($pending_result && $pending_result->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Roll Number</th>
                                    <th>Year</th>
                                    <th>Branch</th>
                                    <th>Section</th>
                                    <th>Session Desired</th>
                                    <th>Other Query</th>
                                    <th>Submitted At</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $pending_result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['roll_number']); ?></td>
                                        <td>Year <?php echo htmlspecialchars($row['year']); ?></td>
                                        <td><?php echo htmlspecialchars($row['branch']); ?></td>
                                        <td><?php echo htmlspecialchars($row['section']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['session_desired']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['other_query'] ?: 'N/A'); ?></td>
                                        <td><?php echo date('M j, Y H:i', strtotime($row['submitted_at'])); ?></td>
                                        <td>
                                            <div style="display: flex; gap: 8px;">
                                                <form method="post" action="" style="display: inline;">
                                                    <input type="hidden" name="request_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="approve_request" style="background: #10b981; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; transition: all 0.3s;" onmouseover="this.style.background='#059669'" onmouseout="this.style.background='#10b981'">
                                                        <i class="fas fa-check"></i> Approve
                                                    </button>
                                                </form>
                                                <form method="post" action="" style="display: inline;">
                                                    <input type="hidden" name="request_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="reject_request" style="background: #ef4444; color: white; border: none; padding: 8px 16px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 13px; transition: all 0.3s;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#ef4444'">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="no-data">No pending session requests.</p>
                    <?php endif; ?>
                </div>

                <!-- Approved Requests Section -->
                <div style="margin-bottom: 50px;">
                    <h3 style="color: #10b981; margin-bottom: 20px; padding: 15px; background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); border-radius: 12px; border-left: 4px solid #10b981;">
                        <i class="fas fa-check-circle"></i> Approved Session Requests
                    </h3>
                    <?php if ($approved_result && $approved_result->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Roll Number</th>
                                    <th>Year</th>
                                    <th>Branch</th>
                                    <th>Section</th>
                                    <th>Session Desired</th>
                                    <th>Other Query</th>
                                    <th>Submitted At</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $approved_result->fetch_assoc()): ?>
                                    <tr style="background: #f0fdf4;">
                                        <td><?php echo $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['roll_number']); ?></td>
                                        <td>Year <?php echo htmlspecialchars($row['year']); ?></td>
                                        <td><?php echo htmlspecialchars($row['branch']); ?></td>
                                        <td><?php echo htmlspecialchars($row['section']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['session_desired']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['other_query'] ?: 'N/A'); ?></td>
                                        <td><?php echo date('M j, Y H:i', strtotime($row['submitted_at'])); ?></td>
                                        <td>
                                            <span style="padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; background: #d1fae5; color: #065f46;">
                                                <i class="fas fa-check-circle"></i> Approved
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="no-data">No approved session requests yet.</p>
                    <?php endif; ?>
                </div>

                <!-- Rejected Requests Section -->
                <div style="margin-bottom: 50px;">
                    <h3 style="color: #ef4444; margin-bottom: 20px; padding: 15px; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); border-radius: 12px; border-left: 4px solid #ef4444;">
                        <i class="fas fa-times-circle"></i> Rejected Session Requests
                    </h3>
                    <?php if ($rejected_result && $rejected_result->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Name</th>
                                    <th>Roll Number</th>
                                    <th>Year</th>
                                    <th>Branch</th>
                                    <th>Section</th>
                                    <th>Session Desired</th>
                                    <th>Other Query</th>
                                    <th>Submitted At</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php while($row = $rejected_result->fetch_assoc()): ?>
                                    <tr style="background: #fef2f2;">
                                        <td><?php echo $row['id']; ?></td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['roll_number']); ?></td>
                                        <td>Year <?php echo htmlspecialchars($row['year']); ?></td>
                                        <td><?php echo htmlspecialchars($row['branch']); ?></td>
                                        <td><?php echo htmlspecialchars($row['section']); ?></td>
                                        <td><strong><?php echo htmlspecialchars($row['session_desired']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['other_query'] ?: 'N/A'); ?></td>
                                        <td><?php echo date('M j, Y H:i', strtotime($row['submitted_at'])); ?></td>
                                        <td>
                                            <span style="padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: bold; background: #fee2e2; color: #991b1b;">
                                                <i class="fas fa-times-circle"></i> Rejected
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <p class="no-data">No rejected session requests.</p>
                    <?php endif; ?>
                </div>

            <?php elseif ($page == 'registered_students'): ?>
                <h2 class="section-title">Registered Students via Student Portal</h2>
                <?php if ($registered_students_result && $registered_students_result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Roll Number</th>
                                <th>Department</th>
                                <th>Year</th>
                                <th>Session Count</th>
                                <th>Registered Sessions</th>
                                <th>Psychometric Score</th>
                                <th>Assessment Status</th>
                                <th>Quizzes Taken</th>
                                <th>Modules Completed</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $registered_students_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['roll_number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                                    <td>Year <?php echo htmlspecialchars($row['year']); ?></td>
                                    <td><strong><?php echo $row['sessions_count'] ?? 0; ?></strong></td>
                                    <td>
                                        <small><?php
                                            echo $row['registered_sessions'] ? htmlspecialchars($row['registered_sessions']) : '<em>None</em>';
                                        ?></small>
                                    </td>
                                    <td>
                                        <span style="background: <?php echo $row['iap_psychometric_score'] > 0 ? '#dcfce7' : '#f3f4f6'; ?>; padding: 4px 8px; border-radius: 4px; font-weight: 600; color: <?php echo $row['iap_psychometric_score'] > 0 ? '#166534' : '#6b7280'; ?>;">
                                            <?php echo $row['iap_psychometric_score'] > 0 ? round($row['iap_psychometric_score'], 1) . '%' : 'N/A'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span style="background: <?php echo $row['assessment_status'] == 'Completed' ? '#dcfce7' : '#fef3c7'; ?>; padding: 4px 8px; border-radius: 4px; font-weight: 600; color: <?php echo $row['assessment_status'] == 'Completed' ? '#166534' : '#92400e'; ?>;">
                                            <?php echo $row['assessment_status']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <!-- Dummy value: Random quiz count between 0-5 -->
                                        <span style="background: #e0f7e0; padding: 4px 8px; border-radius: 4px; font-weight: 600; color: #15803d;">
                                            <?php echo rand(0, 5); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <!-- Dummy value: Random module count between 0-3 -->
                                        <span style="background: #e0e7ff; padding: 4px 8px; border-radius: 4px; font-weight: 600; color: #1e3a8a;">
                                            <?php echo rand(0, 3); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="no-data">No students registered yet through the student portal.</p>
                <?php endif; ?>
            <?php elseif ($page == 'psychometric_status'): ?>
                <h2>Psychometric Assessment Status</h2>

                <!-- Students Who Have Completed the Assessment -->
                <h3 style="color: #28a745; margin-top: 40px;"><i class="fa fa-check-circle"></i> Students Who Have Completed Assessment</h3>
                <?php if ($completed_result && $completed_result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Roll Number</th>
                                <th>Department</th>
                                <th>Year</th>
                                <th>Score</th>
                                <th>Completed Date</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $completed_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['roll_number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                                    <td>Year <?php echo htmlspecialchars($row['year']); ?></td>
                                    <td><strong style="color: #28a745;"><?php echo round($row['score'], 1); ?>%</strong></td>
                                    <td><?php echo date('M j, Y H:i', strtotime($row['completed_at'])); ?></td>
                                    <td>
                                        <a href="psychometric_report.php?student_id=<?php echo $row['id']; ?>" class="check-report-btn">
                                            <i class="fa fa-file-text"></i> Check Report
                                        </a>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="no-data">No students have completed the psychometric assessment yet.</p>
                <?php endif; ?>

                <!-- Students Who Haven't Taken the Assessment -->
                <h3 style="color: #dc3545; margin-top: 40px;"><i class="fa fa-times-circle"></i> Students Who Haven't Taken Assessment</h3>
                <?php if ($pending_result && $pending_result->num_rows > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Email</th>
                                <th>Roll Number</th>
                                <th>Department</th>
                                <th>Year</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $pending_result->fetch_assoc()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['full_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td><?php echo htmlspecialchars($row['roll_number']); ?></td>
                                    <td><?php echo htmlspecialchars($row['department']); ?></td>
                                    <td>Year <?php echo htmlspecialchars($row['year']); ?></td>
                                    <td><span style="color: #dc3545; font-weight: bold;">Not Taken</span></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p class="no-data">All students have completed the psychometric assessment!</p>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
<?php
$conn->close();
?>