<?php
require_once 'student_session_check.php';
require_once __DIR__ . '/../common/english_quiz_loader.php';

$error_message = '';
$success_message = '';
$quiz_questions = [];
$attempt_data = null;

$difficulty = isset($_GET['difficulty']) ? english_normalize_difficulty($_GET['difficulty']) : '';

if ($difficulty === '') {
    header('Location: english_quiz.php');
    exit;
}

// Check if english_quiz_requests table exists
$table_check = $conn->query("SHOW TABLES LIKE 'english_quiz_requests'");
if (!$table_check || $table_check->num_rows == 0) {
    die("English quiz tables not found. Please run create_english_quiz_tables_final.php first.");
}

// Verify student has approved request for this difficulty
$approval_check = $conn->prepare("SELECT id FROM english_quiz_requests WHERE student_id = ? AND difficulty = ? AND status = 'approved' ORDER BY created_at DESC LIMIT 1");
if ($approval_check) {
    $approval_check->bind_param("is", $_SESSION['student_id'], $difficulty);
    $approval_check->execute();
    $approved_request = $approval_check->get_result()->fetch_assoc();
    $approval_check->close();
    
    if (!$approved_request) {
        $error_message = "You don't have approval to take the $difficulty quiz. Please request approval first.";
    }
}

// Handle quiz submission
if ($_SERVER["REQUEST_METHOD"] === "POST" && !empty($error_message)) {
    $questions = english_load_questions_from_csv($difficulty);
    $total = count($questions);
    $correct = 0;
    $answers = $_POST['answers'] ?? [];
    
    if ($total > 0) {
        $conn->begin_transaction();
        try {
            // Create attempt record
            $attempt_stmt = $conn->prepare("INSERT INTO english_quiz_attempts (student_id, difficulty, request_id) VALUES (?, ?, ?)");
            $attempt_stmt->bind_param("isi", $_SESSION['student_id'], $difficulty, $approved_request['id']);
            $attempt_stmt->execute();
            $attempt_id = (int)$conn->insert_id;
            $attempt_stmt->close();
            
            // Process answers
            $answer_stmt = $conn->prepare("INSERT INTO english_quiz_answers (attempt_id, topic, question, option_a, option_b, option_c, option_d, selected_answer, correct_answer, is_correct) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            
            foreach ($questions as $index => $question) {
                $selected = strtoupper(trim((string)($answers[$index] ?? '')));
                $correct_answer = strtoupper($question['correct_answer']);
                $is_correct = ($selected === $correct_answer) ? 1 : 0;
                
                if ($is_correct) {
                    $correct++;
                }
                
                $answer_stmt->bind_param("isssssssi", 
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
if (!empty($error_message)) {
    $attempts_stmt = $conn->prepare("SELECT score, total_questions, percentage, attempt_date FROM english_quiz_results WHERE student_id = ? AND difficulty = ? ORDER BY attempt_date DESC LIMIT 5");
    if ($attempts_stmt) {
        $attempts_stmt->bind_param("is", $_SESSION['student_id'], $difficulty);
        $attempts_stmt->execute();
        $previous_attempts = $attempts_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $attempts_stmt->close();
    }
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
        .timer {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            padding: 10px 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            z-index: 1000;
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
    </style>
</head>
<body style="background: #f8f9fa;">
    <nav class="navbar navbar-dark" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div class="container-lg">
            <span class="navbar-brand">
                <i class="fas fa-language"></i>
                <?php echo ucfirst($difficulty); ?> English Quiz
            </span>
            <a href="english_quiz.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left"></i> Back to Levels
            </a>
        </div>
    </nav>

    <div class="container-lg py-4">
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

    <script>
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
        document.getElementById('quizForm').addEventListener('submit', function() {
            clearInterval(timerInterval);
        });
    </script>
</body>
</html>
