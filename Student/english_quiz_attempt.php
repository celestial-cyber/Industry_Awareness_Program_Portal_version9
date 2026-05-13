<?php
require_once 'student_session_check.php';
require_once __DIR__ . '/../common/english_quiz_loader.php';
require_once __DIR__ . '/../common/ensure_english_quiz_tables.php';

// Ensure all required tables exist
ensure_english_quiz_tables($conn);

$error_message = '';
$success_message = '';
$quiz_questions = [];
$attempt_data = null;

$difficulty = isset($_GET['difficulty']) ? english_normalize_difficulty($_GET['difficulty']) : '';

if ($difficulty === '') {
    header('Location: english_quiz.php');
    exit;
}

// Handle quiz submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $questions = english_load_questions_from_csv($difficulty);
    $total = count($questions);
    $correct = 0;
    $answers = $_POST['answers'] ?? [];
    
    if ($total > 0) {
        $conn->begin_transaction();
        try {
            // Create attempt record (no request_id needed anymore)
            $attempt_stmt = $conn->prepare("INSERT INTO english_quiz_attempts (student_id, difficulty) VALUES (?, ?)");
            $attempt_stmt->bind_param("is", $_SESSION['student_id'], $difficulty);
            $attempt_stmt->execute();
            $attempt_id = (int)$conn->insert_id;
            $attempt_stmt->close();
            
            // Process answers
            $answer_stmt = $conn->prepare("INSERT INTO english_quiz_answers (attempt_id, topic, question, option_a, option_b, option_c, option_d, selected_answer, correct_answer, is_correct) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($questions as $index => $question) {
                $raw = strtoupper(trim((string)($answers[$index] ?? '')));
                $selected = in_array($raw, ['A', 'B', 'C', 'D'], true) ? $raw : null;
                $correct_answer = strtoupper($question['correct_answer']);
                $is_correct = ($selected !== null && $selected === $correct_answer) ? 1 : 0;
                
                if ($is_correct) {
                    $correct++;
                }
                
                $answer_stmt->bind_param("issssssssi", 
                    $attempt_id, 
                    $question['topic'], 
                    $question['question'], 
                    $question['option_a'], 
                    $question['option_b'], 
                    $question['option_c'], 
                    $question['option_d'], 
                    $selected, 
                    $correct_answer, 
                    $is_correct
                );
                $answer_stmt->execute();
            }
            $answer_stmt->close();
            
            // Calculate final results
            $score = $correct;
            $percentage = round(($correct / $total) * 100, 2);
            $topics_covered = implode(', ', array_unique(array_column($questions, 'topic')));
            
            // Save results
            $result_stmt = $conn->prepare("INSERT INTO english_quiz_results (attempt_id, student_id, difficulty, total_questions, correct_answers, score, percentage, topics_covered) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            $result_stmt->bind_param("iiiiiids", 
                $attempt_id, 
                $_SESSION['student_id'], 
                $difficulty, 
                $total, 
                $correct, 
                $score, 
                $percentage, 
                $topics_covered
            );
            $result_stmt->execute();
            $result_stmt->close();
            
            // Update attempt completion time
            $complete_stmt = $conn->prepare("UPDATE english_quiz_attempts SET completed_at = NOW() WHERE id = ?");
            $complete_stmt->bind_param("i", $attempt_id);
            $complete_stmt->execute();
            $complete_stmt->close();
            
            $conn->commit();
            $success_message = "Quiz submitted successfully! Score: {$score}/{$total} ({$percentage}%)";
            
            // Load attempt data for display
            $attempt_data = [
                'score' => $score,
                'total' => $total,
                'percentage' => $percentage,
                'difficulty' => $difficulty,
                'topics_covered' => $topics_covered
            ];
            
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = "Unable to submit quiz. Please try again.";
        }
    } else {
        $error_message = "No questions available for $difficulty difficulty.";
    }
}

// Load questions for display
if (empty($error_message) && empty($attempt_data)) {
    $quiz_questions = english_load_questions_from_csv($difficulty);
    
    if (empty($quiz_questions)) {
        $error_message = "No questions available for $difficulty difficulty level.";
    }
}

// Get previous attempts
$previous_attempts = [];
$attempts_stmt = $conn->prepare("SELECT score, total_questions, percentage, attempt_date FROM english_quiz_results WHERE student_id = ? AND difficulty = ? ORDER BY attempt_date DESC LIMIT 5");
if ($attempts_stmt) {
    $attempts_stmt->bind_param("is", $_SESSION['student_id'], $difficulty);
    $attempts_stmt->execute();
    $previous_attempts = $attempts_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $attempts_stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo ucfirst($difficulty); ?> English Quiz - IAP Portal</title>
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

        .quiz-container {
            max-width: 800px;
            margin: 0 auto;
        }
        .question-card {
            background: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #007bff;
        }
        .difficulty-badge {
            font-size: 0.8rem;
            padding: 4px 12px;
            border-radius: 20px;
        }
        .difficulty-easy { background: #28a745; }
        .difficulty-medium { background: #ffc107; color: #000; }
        .difficulty-hard { background: #dc3545; }
        .option-label {
            font-weight: 500;
            margin-left: 5px;
        }
        .result-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 30px;
            text-align: center;
        }
        .previous-attempts {
            max-height: 300px;
            overflow-y: auto;
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
            <a class="navbar-brand" href="english_quiz.php">
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
                        <a class="nav-link" href="english_quiz.php">
                            <i class="fas fa-arrow-left"></i> Back to Levels
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
        <!-- Messages -->
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($attempt_data)): ?>
            <!-- Results Display -->
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="result-card">
                        <h2 class="mb-4">
                            <i class="fas fa-trophy"></i>
                            Quiz Complete!
                        </h2>
                        <div class="row">
                            <div class="col-6">
                                <h4>Score</h4>
                                <h1><?php echo $attempt_data['score']; ?>/<?php echo $attempt_data['total']; ?></h1>
                            </div>
                            <div class="col-6">
                                <h4>Percentage</h4>
                                <h1><?php echo $attempt_data['percentage']; ?>%</h1>
                            </div>
                        </div>
                        <div class="mt-4">
                            <h5>Difficulty: <span class="badge difficulty-badge difficulty-<?php echo $attempt_data['difficulty']; ?>"><?php echo ucfirst($attempt_data['difficulty']); ?></span></h5>
                            <h5 class="mt-2">Topics Covered:</h5>
                            <p><?php echo htmlspecialchars($attempt_data['topics_covered']); ?></p>
                        </div>
                        <div class="mt-4">
                            <a href="english_quiz.php" class="btn btn-light btn-lg">
                                <i class="fas fa-redo"></i> Try Another Level
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif (!empty($quiz_questions)): ?>
            <!-- Quiz Questions -->
            <div class="quiz-container">
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 class="mb-0">
                                <i class="fas fa-question-circle"></i>
                                <?php echo ucfirst($difficulty); ?> English Quiz
                            </h3>
                            <span class="badge difficulty-badge difficulty-<?php echo $difficulty; ?>">
                                <?php echo count($quiz_questions); ?> Questions
                            </span>
                        </div>
                        
                        <?php if (!empty($previous_attempts)): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-history"></i>
                                <strong>Previous Attempts:</strong>
                                <div class="previous-attempts mt-2">
                                    <?php foreach ($previous_attempts as $prev): ?>
                                        <div class="small">
                                            <?php echo date('M j, Y H:i', strtotime($prev['attempt_date'])); ?> - 
                                            Score: <?php echo $prev['score']; ?>/<?php echo $prev['total_questions']; ?> 
                                            (<?php echo $prev['percentage']; ?>%)
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <form method="POST" id="quizForm">
                    <?php foreach ($quiz_questions as $index => $question): ?>
                        <div class="question-card">
                            <div class="d-flex justify-content-between align-items-start mb-3">
                                <div class="flex-grow-1">
                                    <h5 class="text-primary">Question <?php echo $index + 1; ?></h5>
                                    <div class="mb-2">
                                        <small class="text-muted">
                                            <i class="fas fa-tag"></i>
                                            Topic: <?php echo htmlspecialchars($question['topic']); ?>
                                        </small>
                                    </div>
                                    <p class="mb-3 fw-semibold"><?php echo htmlspecialchars($question['question']); ?></p>
                                </div>
                            </div>
                            
                            <div class="options-container">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="answers[<?php echo $index; ?>]" value="A" required id="opt_<?php echo $index; ?>_a">
                                    <label class="form-check-label" for="opt_<?php echo $index; ?>_a">
                                        <span class="option-label">A.</span> <?php echo htmlspecialchars($question['option_a']); ?>
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="answers[<?php echo $index; ?>]" value="B" required id="opt_<?php echo $index; ?>_b">
                                    <label class="form-check-label" for="opt_<?php echo $index; ?>_b">
                                        <span class="option-label">B.</span> <?php echo htmlspecialchars($question['option_b']); ?>
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="answers[<?php echo $index; ?>]" value="C" required id="opt_<?php echo $index; ?>_c">
                                    <label class="form-check-label" for="opt_<?php echo $index; ?>_c">
                                        <span class="option-label">C.</span> <?php echo htmlspecialchars($question['option_c']); ?>
                                    </label>
                                </div>
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="radio" name="answers[<?php echo $index; ?>]" value="D" required id="opt_<?php echo $index; ?>_d">
                                    <label class="form-check-label" for="opt_<?php echo $index; ?>_d">
                                        <span class="option-label">D.</span> <?php echo htmlspecialchars($question['option_d']); ?>
                                    </label>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <div class="text-center mt-4">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fas fa-check"></i>
                            Submit Quiz
                        </button>
                    </div>
                </form>
            </div>
        <?php endif; ?>
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

        // Timer functionality (optional)
        let startTime = Date.now();
        let timerInterval = setInterval(function() {
            let elapsed = Math.floor((Date.now() - startTime) / 1000);
            let minutes = Math.floor(elapsed / 60);
            let seconds = elapsed % 60;
            
            // Update timer display if element exists
            let timerElement = document.getElementById('timer');
            if (timerElement) {
                timerElement.innerHTML = `Time: ${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
            }
        }, 1000);

        // Stop timer when form is submitted
        let quizForm = document.getElementById('quizForm');
        if (quizForm) {
            quizForm.addEventListener('submit', function() {
                clearInterval(timerInterval);
            });
        }
    </script>
</body>
</html>
