<?php
require_once 'student_session_check.php';
require_once __DIR__ . '/../common/english_quiz_loader.php';

$error_message = '';
$success_message = '';
$info_message = '';

// Get available topics and statistics
$available_topics = english_get_available_topics();
$quiz_stats = english_get_quiz_statistics();

// Check if english_quiz_requests table exists, create if not
$table_check = $conn->query("SHOW TABLES LIKE 'english_quiz_requests'");
if (!$table_check || $table_check->num_rows == 0) {
    die("English quiz tables not found. Please run create_english_quiz_tables_final.php first.");
}

// Handle difficulty selection
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['difficulty'])) {
    $difficulty = english_normalize_difficulty($_POST['difficulty']);

    // Verify student has approved request for this difficulty
    $approval_check = $conn->prepare("SELECT id FROM english_quiz_requests WHERE student_id = ? AND difficulty = ? AND status = 'approved' ORDER BY created_at DESC LIMIT 1");
    if ($approval_check) {
        $approval_check->bind_param("is", $_SESSION['student_id'], $difficulty);
        $approval_check->execute();
        $approved_request = $approval_check->get_result()->fetch_assoc();
        $approval_check->close();
        
        if (!$approved_request) {
            $error_message = "You don't have approval to take $difficulty quiz. Please request approval first.";
        }
    }

    // Check if student already has a pending/approved request for this difficulty
    $check_stmt = $conn->prepare("SELECT id, status FROM english_quiz_requests WHERE student_id = ? AND difficulty = ? ORDER BY created_at DESC LIMIT 1");
    if ($check_stmt) {
        $check_stmt->bind_param("is", $_SESSION['student_id'], $difficulty);
        $check_stmt->execute();
        $existing_request = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        
        if ($existing_request) {
            if ($existing_request['status'] === 'pending') {
                $info_message = "Your request for $difficulty quiz has been sent to admin for approval.";
            } elseif ($existing_request['status'] === 'approved') {
                // Redirect to quiz attempt page
                header("Location: english_quiz_attempt.php?difficulty=$difficulty");
                exit;
            } elseif ($existing_request['status'] === 'rejected') {
                $error_message = "Your request for $difficulty quiz was rejected by admin.";
            }
        } else {
            // Create new request
            $insert_stmt = $conn->prepare("INSERT INTO english_quiz_requests (student_id, difficulty, status, created_at) VALUES (?, ?, 'pending', NOW())");
            if ($insert_stmt) {
                $insert_stmt->bind_param("is", $_SESSION['student_id'], $difficulty);
                if ($insert_stmt->execute()) {
                    $info_message = "Your request for $difficulty quiz has been sent to admin for approval.";
                } else {
                    $error_message = "Failed to submit quiz request. Please try again.";
                }
                $insert_stmt->close();
            }
        }
    }
}

// Get student's current requests
$requests_stmt = $conn->prepare("SELECT difficulty, status, created_at FROM english_quiz_requests WHERE student_id = ? ORDER BY created_at DESC");
if ($requests_stmt) {
    $requests_stmt->bind_param("i", $_SESSION['student_id']);
    $requests_stmt->execute();
    $student_requests = $requests_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $requests_stmt->close();
} else {
    $student_requests = [];
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
        .request-status-pending {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
        }
        .request-status-approved {
            background-color: #d1e7dd;
            border: 1px solid #bee5eb;
        }
        .request-status-rejected {
            background-color: #f8d7da;
            border: 1px solid #f5c6cb;
        }
        .topic-badge {
            background: #e9ecef;
            color: #495057;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75rem;
        }
    </style>
</head>
<body style="background: #f8f9fa;">
    <nav class="navbar navbar-dark" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
        <div class="container-lg">
            <span class="navbar-brand"><i class="fas fa-language"></i> English Module Quiz</span>
            <a href="student_dashboard.php" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
    </nav>

    <div class="container-lg py-4">
        <!-- Messages -->
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($info_message)): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($info_message); ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h2 class="card-title text-center mb-0">
                            <i class="fas fa-graduation-cap text-primary"></i>
                            English Module Quiz System
                        </h2>
                        <p class="text-center text-muted mt-2 mb-0">
                            Choose your difficulty level and test your English skills
                        </p>
                    </div>
                </div>
            </div>
        </div>

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

        <!-- Your Quiz Requests -->
        <?php if (!empty($student_requests)): ?>
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-history"></i> Your Quiz Requests</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Difficulty</th>
                                            <th>Status</th>
                                            <th>Requested Date</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($student_requests as $request): ?>
                                            <tr>
                                                <td>
                                                    <span class="badge bg-<?php echo $request['difficulty'] === 'easy' ? 'success' : ($request['difficulty'] === 'medium' ? 'warning' : 'danger'); ?>">
                                                        <?php echo ucfirst($request['difficulty']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php
                                                    $statusClass = 'request-status-' . $request['status'];
                                                    $statusIcon = $request['status'] === 'pending' ? 'clock' : ($request['status'] === 'approved' ? 'check' : 'times');
                                                    $statusText = ucfirst($request['status']);
                                                    ?>
                                                    <span class="badge <?php echo $statusClass; ?>">
                                                        <i class="fas fa-<?php echo $statusIcon; ?>"></i>
                                                        <?php echo $statusText; ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y H:i', strtotime($request['created_at'])); ?></td>
                                                <td>
                                                    <?php if ($request['status'] === 'approved'): ?>
                                                        <a href="english_quiz_attempt.php?difficulty=<?php echo urlencode($request['difficulty']); ?>" class="btn btn-sm btn-success">
                                                            <i class="fas fa-play"></i> Take Quiz
                                                        </a>
                                                    <?php elseif ($request['status'] === 'pending'): ?>
                                                        <button class="btn btn-sm btn-warning" disabled>
                                                            <i class="fas fa-clock"></i> Pending
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-danger" disabled>
                                                            <i class="fas fa-times"></i> Rejected
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

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
</body>
</html>
