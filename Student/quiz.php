<?php
/**
 * Quiz Page
 * Displays quiz for a registered session
 * Server-side validation ensures student can only access quizzes for sessions they're registered for
 * Prevents unauthorized access using MySQLi prepared statements
 */

// Include session protection - must be at the top
require_once 'student_session_check.php';

$error_message = '';
$session_data = null;
$quiz_questions = [];
$is_authorized = false;

// Get session_id from URL
$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;

if ($session_id <= 0) {
    $error_message = "Invalid session ID";
} else {
    try {
        // Server-side validation: Check if student is registered for this session
        // Using prepared statement to prevent SQL injection
        $validation_sql = "SELECT ss.id, s.id as session_id, s.title, s.year, s.description, ss.registration_status
                          FROM iap_student_sessions ss
                          JOIN iap_sessions s ON ss.session_id = s.id
                          WHERE ss.student_id = ? AND s.id = ?";
        
        $validation_stmt = $conn->prepare($validation_sql);
        
        if (!$validation_stmt) {
            $error_message = "Database error: " . $conn->error;
        } else {
            $validation_stmt->bind_param("ii", $_SESSION['student_id'], $session_id);
            $validation_stmt->execute();
            $validation_result = $validation_stmt->get_result();
            
            if ($validation_result->num_rows === 0) {
                // Student is NOT authorized to access this quiz
                $error_message = "You are not registered for this session or it does not exist.";
                $is_authorized = false;
            } else {
                // Student IS authorized - fetch session data
                $session_data = $validation_result->fetch_assoc();
                $is_authorized = true;
                
                // Only students with 'registered' status can take the quiz
                if ($session_data['registration_status'] !== 'registered' && $session_data['registration_status'] !== 'completed') {
                    $error_message = "You cannot take this quiz. Your registration status is: " . htmlspecialchars($session_data['registration_status']);
                    $is_authorized = false;
                }
            }
            
            $validation_stmt->close();
        }
        
        // If authorized, fetch sample quiz questions (you can customize this)
        if ($is_authorized && !empty($error_message) === false) {
            $questions_sql = "SELECT q.id, q.question, q.question_type, q.options, q.correct_answer
                             FROM quiz_questions q
                             WHERE q.session_id = ?
                             ORDER BY q.id ASC";
            
            $questions_stmt = $conn->prepare($questions_sql);
            
            if ($questions_stmt) {
                $questions_stmt->bind_param("i", $session_id);
                $questions_stmt->execute();
                $questions_result = $questions_stmt->get_result();
                
                while ($question = $questions_result->fetch_assoc()) {
                    // Decode JSON options if stored as JSON
                    if (!empty($question['options']) && is_string($question['options'])) {
                        $question['options'] = json_decode($question['options'], true);
                    }
                    $quiz_questions[] = $question;
                }
                
                $questions_stmt->close();
            }
            
            // If no questions exist, show sample questions
            if (empty($quiz_questions)) {
                $quiz_questions = [
                    [
                        'id' => 1,
                        'question' => 'What is the main focus of this session?',
                        'question_type' => 'multiple_choice',
                        'options' => ['Option A', 'Option B', 'Option C', 'Option D']
                    ],
                    [
                        'id' => 2,
                        'question' => 'Rate the relevance of this session (1-5)',
                        'question_type' => 'rating',
                        'options' => ['1 - Not Relevant', '2 - Somewhat Relevant', '3 - Relevant', '4 - Very Relevant', '5 - Extremely Relevant']
                    ],
                    [
                        'id' => 3,
                        'question' => 'What did you learn from this session?',
                        'question_type' => 'short_text',
                        'options' => []
                    ]
                ];
            }
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Handle quiz submission
if ($_SERVER["REQUEST_METHOD"] == "POST" && $is_authorized) {
    $answers = $_POST['answers'] ?? [];
    
    // Process quiz submission
    // You can store responses in a quiz_responses table
    $success_message = "Thank you for completing the quiz! Your responses have been recorded.";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz - IAP Portal</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="../common/theme.css">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        /* Navigation */
        .navbar-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .navbar-custom .navbar-brand {
            font-weight: 700;
            color: white !important;
        }

        .navbar-custom .nav-link {
            color: rgba(255, 255, 255, 0.9) !important;
        }

        /* Main Content */
        .quiz-container {
            padding: 30px 20px;
        }

        .quiz-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
        }

        .quiz-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: 700;
        }

        .quiz-header p {
            margin: 5px 0;
            font-size: 14px;
            opacity: 0.95;
        }

        .quiz-content {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            max-width: 800px;
            margin: 0 auto;
        }

        .question-block {
            margin-bottom: 40px;
            padding-bottom: 30px;
            border-bottom: 1px solid #e5e7eb;
        }

        .question-block:last-child {
            border-bottom: none;
            padding-bottom: 0;
            margin-bottom: 0;
        }

        .question-number {
            color: #667eea;
            font-weight: 700;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .question-text {
            font-size: 18px;
            font-weight: 600;
            color: #333;
            margin: 10px 0 20px 0;
            line-height: 1.5;
        }

        .form-check {
            margin-bottom: 12px;
        }

        .form-check-input {
            border-color: #e5e7eb;
        }

        .form-check-input:checked {
            background-color: #667eea;
            border-color: #667eea;
        }

        .form-check-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.25);
        }

        .form-check-label {
            margin-left: 8px;
            cursor: pointer;
            color: #555;
            font-size: 15px;
        }

        .form-control {
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px 15px;
        }

        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.25rem rgba(102, 126, 234, 0.25);
        }

        .rating-options {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rating-btn {
            padding: 10px 15px;
            border: 2px solid #e5e7eb;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
            font-weight: 600;
            color: #666;
        }

        .rating-btn:hover {
            border-color: #667eea;
            color: #667eea;
        }

        .rating-btn.selected {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }

        /* Buttons */
        .button-group {
            display: flex;
            gap: 10px;
            margin-top: 40px;
            justify-content: center;
        }

        .btn-submit {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 40px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.4);
        }

        .btn-back {
            background: #e5e7eb;
            color: #374151;
            border: none;
            padding: 12px 40px;
            border-radius: 8px;
            font-weight: 700;
            font-size: 15px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
        }

        .btn-back:hover {
            background: #d1d5db;
            color: #374151;
            text-decoration: none;
        }

        /* Alert */
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

        /* Access Denied */
        .access-denied {
            text-align: center;
            padding: 60px 20px;
        }

        .access-denied-icon {
            font-size: 60px;
            color: #ef4444;
            margin-bottom: 20px;
        }

        .access-denied h2 {
            color: #333;
            margin-bottom: 10px;
            font-weight: 600;
        }

        .access-denied p {
            color: #666;
            font-size: 16px;
            margin-bottom: 30px;
        }

        @media (max-width: 768px) {
            .quiz-content {
                padding: 25px;
            }

            .quiz-header {
                padding: 20px;
            }

            .button-group {
                flex-direction: column;
            }

            .btn-submit, .btn-back {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom">
        <div class="container-lg">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-graduation-cap"></i> IAP Portal
            </a>
            <a href="student_dashboard.php" class="btn btn-outline-light btn-sm">
                <i class="fas fa-arrow-left"></i> Back to Dashboard
            </a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="quiz-container">
        <div class="container-lg">
            <?php if (!empty($error_message)): ?>
                <!-- Access Denied or Error -->
                <div class="quiz-content access-denied">
                    <div class="access-denied-icon">
                        <i class="fas fa-lock"></i>
                    </div>
                    <h2>Access Denied</h2>
                    <p><?php echo htmlspecialchars($error_message); ?></p>
                    <a href="student_dashboard.php" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Go Back to Dashboard
                    </a>
                </div>
            <?php elseif ($is_authorized && $session_data): ?>
                <!-- Quiz Header -->
                <div class="quiz-header">
                    <h1><i class="fas fa-pen-fancy"></i> <?php echo htmlspecialchars($session_data['title']); ?></h1>
                    <p><i class="fas fa-graduation-cap"></i> Year <?php echo htmlspecialchars($session_data['year']); ?> Session</p>
                    <?php if (!empty($session_data['description'])): ?>
                        <p><i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($session_data['description']); ?></p>
                    <?php endif; ?>
                </div>

                <!-- Quiz Content -->
                <div class="quiz-content">
                    <?php if (!empty($quiz_questions)): ?>
                        <form method="POST" action="" id="quizForm">
                            <?php foreach ($quiz_questions as $index => $question): ?>
                                <div class="question-block">
                                    <div class="question-number">Question <?php echo $index + 1; ?> of <?php echo count($quiz_questions); ?></div>
                                    <div class="question-text"><?php echo htmlspecialchars($question['question']); ?></div>

                                    <?php if ($question['question_type'] === 'multiple_choice'): ?>
                                        <!-- Multiple Choice -->
                                        <?php foreach ($question['options'] as $option_index => $option): ?>
                                            <div class="form-check">
                                                <input 
                                                    class="form-check-input" 
                                                    type="radio" 
                                                    name="answers[<?php echo htmlspecialchars($question['id']); ?>]" 
                                                    id="q<?php echo htmlspecialchars($question['id']); ?>_<?php echo $option_index; ?>" 
                                                    value="<?php echo htmlspecialchars($option); ?>"
                                                    required
                                                >
                                                <label class="form-check-label" for="q<?php echo htmlspecialchars($question['id']); ?>_<?php echo $option_index; ?>">
                                                    <?php echo htmlspecialchars($option); ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>

                                    <?php elseif ($question['question_type'] === 'rating'): ?>
                                        <!-- Rating -->
                                        <div class="rating-options">
                                            <?php foreach ($question['options'] as $rating_option): ?>
                                                <button 
                                                    type="button" 
                                                    class="rating-btn" 
                                                    data-question="<?php echo htmlspecialchars($question['id']); ?>" 
                                                    data-value="<?php echo htmlspecialchars($rating_option); ?>"
                                                    onclick="selectRating(this, '<?php echo htmlspecialchars($question['id']); ?>', '<?php echo htmlspecialchars($rating_option); ?>')">
                                                    <?php echo htmlspecialchars($rating_option); ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                        <input type="hidden" name="answers[<?php echo htmlspecialchars($question['id']); ?>]" id="rating_<?php echo htmlspecialchars($question['id']); ?>" value="">

                                    <?php elseif ($question['question_type'] === 'short_text'): ?>
                                        <!-- Short Text -->
                                        <textarea 
                                            class="form-control" 
                                            name="answers[<?php echo htmlspecialchars($question['id']); ?>]" 
                                            placeholder="Enter your answer here"
                                            rows="4"
                                            required
                                        ></textarea>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <!-- Submit Button -->
                            <div class="button-group">
                                <a href="student_dashboard.php" class="btn-back">
                                    <i class="fas fa-arrow-left"></i> Cancel
                                </a>
                                <button type="submit" class="btn-submit">
                                    <i class="fas fa-check"></i> Submit Quiz
                                </button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle"></i> 
                            <strong>Quiz not available:</strong> The quiz for this session is not yet available. Please check back later.
                        </div>
                        <a href="student_dashboard.php" class="btn-back">
                            <i class="fas fa-arrow-left"></i> Back to Dashboard
                        </a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <!-- Invalid Session -->
                <div class="quiz-content access-denied">
                    <div class="access-denied-icon">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <h2>Invalid Session</h2>
                    <p>The session you're looking for could not be found.</p>
                    <a href="student_dashboard.php" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Go Back to Dashboard
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Rating button selection
        function selectRating(btn, questionId, value) {
            // Remove selection from other buttons in the same group
            const group = btn.parentElement;
            group.querySelectorAll('.rating-btn').forEach(b => {
                b.classList.remove('selected');
            });
            
            // Add selection to clicked button
            btn.classList.add('selected');
            
            // Set hidden input value
            document.getElementById('rating_' + questionId).value = value;
        }

        // Form validation
        document.getElementById('quizForm').addEventListener('submit', function(e) {
            let isValid = true;
            const form = this;
            
            // Check if all questions are answered
            <?php foreach ($quiz_questions as $question): ?>
                const q<?php echo $question['id']; ?>Response = form.querySelector('input[name="answers[<?php echo htmlspecialchars($question['id']); ?>]"]:checked') || 
                                                               form.querySelector('textarea[name="answers[<?php echo htmlspecialchars($question['id']); ?>]"]') ||
                                                               form.querySelector('input[name="answers[<?php echo htmlspecialchars($question['id']); ?>]"][id="rating_<?php echo htmlspecialchars($question['id']); ?>"]');
                
                if (!q<?php echo $question['id']; ?>Response || !q<?php echo $question['id']; ?>Response.value) {
                    isValid = false;
                }
            <?php endforeach; ?>
            
            if (!isValid) {
                e.preventDefault();
                alert('Please answer all questions before submitting.');
            }
        });
    </script>
</body>
</html>
