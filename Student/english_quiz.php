<?php
require_once 'student_session_check.php';
require_once __DIR__ . '/../common/english_quiz_loader.php';

$error_message = '';
$success_message = '';

// Get available topics and statistics
$available_topics = english_get_available_topics();
$quiz_stats = english_get_quiz_statistics();

// Handle difficulty selection - directly redirect to quiz attempt
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['difficulty'])) {
    $difficulty = english_normalize_difficulty($_POST['difficulty']);
    header("Location: english_quiz_attempt.php?difficulty=$difficulty");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>English Module Quiz - IAP Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../common/theme.css">
    <style>
        body {
            background: #f8f9fa;
        }
        
        .navbar-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }

        .dashboard-sidebar {
            position: fixed;
            left: 0;
            top: 64px;
            width: 250px;
            height: calc(100vh - 64px);
            background: white;
            border-right: 1px solid #e5e7eb;
            overflow-y: auto;
            z-index: 999;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
        }

        .dashboard-sidebar.show {
            transform: translateX(0);
        }

        .sidebar-logo {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .sidebar-logo img {
            width: 40px;
            height: 40px;
            object-fit: contain;
        }

        .sidebar-nav {
            padding: 20px 0;
        }

        .sidebar-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 20px;
            color: #6b7280;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .sidebar-link:hover {
            background: #f3f4f6;
            color: #667eea;
            border-left-color: #667eea;
        }

        .sidebar-link.active {
            background: #f3f4f6;
            color: #667eea;
            border-left-color: #667eea;
            font-weight: 600;
        }

        .main-dashboard-content {
            margin-left: 0;
            margin-top: 64px;
            padding: 30px 20px;
            min-height: calc(100vh - 64px);
        }

        .mobile-sidebar-toggle {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 24px;
            cursor: pointer;
            margin-right: 15px;
        }

        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 998;
        }

        body.sidebar-open .dashboard-sidebar {
            transform: translateX(0);
        }

        body.sidebar-open .sidebar-overlay {
            display: block;
        }

        body.sidebar-open .main-dashboard-content {
            margin-left: 250px;
        }

        .difficulty-card {
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border: none;
            overflow: hidden;
        }
        .difficulty-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .difficulty-easy {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        .difficulty-medium {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            color: white;
        }
        .difficulty-hard {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
            color: white;
        }
        .stats-card {
            background: #f8f9fa;
            border-left: 4px solid #007bff;
        }
        .topic-badge {
            background: #e9ecef;
            color: #495057;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
        }

        @media (max-width: 768px) {
            .mobile-sidebar-toggle {
                display: block;
            }

            .main-dashboard-content {
                margin-left: 0;
                padding: 20px 15px;
            }

            .dashboard-sidebar {
                width: 100%;
                max-width: 250px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
        <div class="container-lg">
            <button type="button" class="mobile-sidebar-toggle" id="mobileSidebarToggle" aria-label="Toggle sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <a class="navbar-brand" href="student_dashboard.php">
                <i class="fas fa-language"></i> English Quiz
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <div class="user-info" style="display: flex; align-items: center; gap: 10px; color: white;">
                            <i class="fas fa-user-circle"></i> 
                            <div>
                                <div style="font-weight: 600;"><?php echo htmlspecialchars($_SESSION['full_name']); ?></div>
                                <small><?php echo htmlspecialchars($_SESSION['email']); ?></small>
                            </div>
                        </div>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="student_dashboard.php">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Sidebar -->
    <div class="dashboard-sidebar" id="studentSidebar">
        <div class="sidebar-logo">
            <div style="display: flex; align-items: center; gap: 12px;">
                <img src="../images/SA%20Main%20logo.jpg" alt="SA Main Logo" title="SA Main">
                <div style="display: flex; flex-direction: column;">
                    <span style="font-size: 14px; font-weight: 700; color: #7c3aed; line-height: 1.2;">SPECANCIENS</span>
                    <span style="font-size: 12px; font-weight: 700; color: #6b7280; line-height: 1.2;">IAP Portal</span>
                </div>
            </div>
        </div>

        <!-- Navigation Menu -->
        <div class="sidebar-nav">
            <a href="student_dashboard.php" class="sidebar-link">
                <i class="fas fa-home"></i> Dashboard
            </a>
            <a href="english_quiz.php" class="sidebar-link active">
                <i class="fas fa-language"></i> English Quiz
            </a>
            <a href="student_dashboard.php?view=view_all_sessions" class="sidebar-link">
                <i class="fas fa-list"></i> View All Sessions
            </a>
            <a href="student_dashboard.php?view=view_registered_sessions" class="sidebar-link">
                <i class="fas fa-check-circle"></i> View Registered Sessions
            </a>
            <a href="student_dashboard.php?view=view_progress" class="sidebar-link">
                <i class="fas fa-chart-line"></i> View Progress
            </a>
            <a href="student_dashboard.php?view=edit_profile" class="sidebar-link">
                <i class="fas fa-user-edit"></i> Edit Profile
            </a>
            <a href="pronunciation.php" class="sidebar-link">
                <i class="fas fa-microphone-alt"></i> Phonetics Practice
            </a>
            <a href="student_dashboard.php?view=reset_password" class="sidebar-link">
                <i class="fas fa-key"></i> Reset Password
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-dashboard-content">
        <!-- Difficulty Selection -->
        <div class="row mb-4">
            <div class="col-12">
                <h3 class="mb-3"><i class="fas fa-layer-group"></i> Select Difficulty Level</h3>
            </div>
        </div>

        <div class="row g-4 mb-4">
            <!-- Easy Level -->
            <div class="col-md-4 col-sm-6">
                <div class="card difficulty-card difficulty-easy h-100">
                    <div class="card-body text-center">
                        <div class="mb-3">
                            <i class="fas fa-smile fa-3x"></i>
                        </div>
                        <h4 class="card-title text-white mb-3">Easy Questions</h4>
                        <p class="text-white mb-3">
                            Perfect for beginners. Test your basic vocabulary and grammar skills.
                        </p>
                        <div class="mb-3">
                            <span class="badge bg-light text-dark">
                                <?php echo isset($quiz_stats['easy']['total_questions']) ? $quiz_stats['easy']['total_questions'] : 0; ?> Questions
                            </span>
                            <span class="badge bg-light text-dark ms-2">
                                <?php echo isset($quiz_stats['easy']['topics_count']) ? $quiz_stats['easy']['topics_count'] : 0; ?> Topics
                            </span>
                        </div>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="difficulty" value="easy">
                            <button type="submit" class="btn btn-light btn-lg">
                                <i class="fas fa-play"></i> Start Easy Quiz
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Medium Level -->
            <div class="col-md-4 col-sm-6">
                <div class="card difficulty-card difficulty-medium h-100">
                    <div class="card-body text-center">
                        <div class="mb-3">
                            <i class="fas fa-meh fa-3x"></i>
                        </div>
                        <h4 class="card-title text-white mb-3">Medium Level Questions</h4>
                        <p class="text-white mb-3">
                            Intermediate level. Challenge yourself with complex grammar and vocabulary.
                        </p>
                        <div class="mb-3">
                            <span class="badge bg-light text-dark">
                                <?php echo isset($quiz_stats['medium']['total_questions']) ? $quiz_stats['medium']['total_questions'] : 0; ?> Questions
                            </span>
                            <span class="badge bg-light text-dark ms-2">
                                <?php echo isset($quiz_stats['medium']['topics_count']) ? $quiz_stats['medium']['topics_count'] : 0; ?> Topics
                            </span>
                        </div>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="difficulty" value="medium">
                            <button type="submit" class="btn btn-light btn-lg">
                                <i class="fas fa-play"></i> Start Medium Quiz
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Hard Level -->
            <div class="col-md-4 col-sm-6">
                <div class="card difficulty-card difficulty-hard h-100">
                    <div class="card-body text-center">
                        <div class="mb-3">
                            <i class="fas fa-fire fa-3x"></i>
                        </div>
                        <h4 class="card-title text-white mb-3">Hard Level Questions</h4>
                        <p class="text-white mb-3">
                            Advanced level. Test your mastery of English with challenging questions.
                        </p>
                        <div class="mb-3">
                            <span class="badge bg-light text-dark">
                                <?php echo isset($quiz_stats['hard']['total_questions']) ? $quiz_stats['hard']['total_questions'] : 0; ?> Questions
                            </span>
                            <span class="badge bg-light text-dark ms-2">
                                <?php echo isset($quiz_stats['hard']['topics_count']) ? $quiz_stats['hard']['topics_count'] : 0; ?> Topics
                            </span>
                        </div>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="difficulty" value="hard">
                            <button type="submit" class="btn btn-light btn-lg">
                                <i class="fas fa-play"></i> Start Hard Quiz
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Available Topics -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-book"></i> Available Topics</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($available_topics as $topic): ?>
                                <div class="col-md-6 col-lg-4 mb-2">
                                    <span class="topic-badge">
                                        <i class="fas fa-tag"></i>
                                        <?php echo htmlspecialchars($topic); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile sidebar toggle
        const mobileSidebarToggle = document.getElementById('mobileSidebarToggle');
        const sidebarOverlay = document.getElementById('sidebarOverlay');
        const studentSidebar = document.getElementById('studentSidebar');

        if (mobileSidebarToggle) {
            mobileSidebarToggle.addEventListener('click', function() {
                document.body.classList.toggle('sidebar-open');
            });
        }

        if (sidebarOverlay) {
            sidebarOverlay.addEventListener('click', function() {
                document.body.classList.remove('sidebar-open');
            });
        }
    </script>
</body>
</html>
