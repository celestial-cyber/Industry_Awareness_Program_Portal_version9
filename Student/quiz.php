<?php
require_once 'student_session_check.php';
require_once __DIR__ . '/../common/quiz_data_loader.php';
require_once 'quiz_helpers.php';

$error_message = '';
$success_message = '';
$info_message = '';
$session_data = null;
$quiz_questions = [];
$is_authorized = false;
$latest_attempt = null;
$quiz_already_completed = false;

$session_id = isset($_GET['session_id']) ? intval($_GET['session_id']) : 0;
if ($session_id <= 0) {
    $error_message = "Invalid session ID";
}

$session_title_column = 'topic';
$title_col_check_quiz = $conn->query("SHOW COLUMNS FROM iap_sessions LIKE 'title'");
if ($title_col_check_quiz && $title_col_check_quiz->num_rows > 0) {
    $session_title_column = 'title';
}
$session_description_exists_quiz = false;
$desc_col_check_quiz = $conn->query("SHOW COLUMNS FROM iap_sessions LIKE 'description'");
if ($desc_col_check_quiz && $desc_col_check_quiz->num_rows > 0) {
    $session_description_exists_quiz = true;
}
$quiz_sessions_description_select = $session_description_exists_quiz ? 's.description' : "''";

if (empty($error_message)) {
    $conn->query("CREATE TABLE IF NOT EXISTS quiz_questions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        session_id INT NOT NULL,
        year ENUM('1','2','3','4','Graduate') NOT NULL,
        module VARCHAR(255) NOT NULL,
        question TEXT NOT NULL,
        option_a VARCHAR(500) NOT NULL,
        option_b VARCHAR(500) NOT NULL,
        option_c VARCHAR(500) NOT NULL,
        option_d VARCHAR(500) NOT NULL,
        correct_answer ENUM('A','B','C','D') NOT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_session_id (session_id),
        INDEX idx_year (year),
        INDEX idx_module (module),
        CONSTRAINT fk_quiz_question_session FOREIGN KEY (session_id) REFERENCES iap_sessions(id) ON DELETE CASCADE
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS quiz_requests (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        status ENUM('pending','approved','rejected') DEFAULT 'pending',
        requested_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        reviewed_at TIMESTAMP NULL,
        reviewed_by INT NULL,
        admin_note VARCHAR(255) NULL,
        UNIQUE KEY uq_student_session_request (student_id, session_id),
        INDEX idx_status (status),
        CONSTRAINT fk_quiz_request_student FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
        CONSTRAINT fk_quiz_request_session FOREIGN KEY (session_id) REFERENCES iap_sessions(id) ON DELETE CASCADE,
        CONSTRAINT fk_quiz_request_admin FOREIGN KEY (reviewed_by) REFERENCES iap_users_details(id) ON DELETE SET NULL
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS quiz_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        request_id INT NULL,
        attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_student (student_id),
        INDEX idx_session (session_id),
        CONSTRAINT fk_quiz_attempt_student FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
        CONSTRAINT fk_quiz_attempt_session FOREIGN KEY (session_id) REFERENCES iap_sessions(id) ON DELETE CASCADE,
        CONSTRAINT fk_quiz_attempt_request FOREIGN KEY (request_id) REFERENCES quiz_requests(id) ON DELETE SET NULL
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS quiz_answers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        attempt_id INT NOT NULL,
        question_id INT NOT NULL,
        selected_answer ENUM('A','B','C','D') NULL,
        is_correct TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_attempt (attempt_id),
        INDEX idx_question (question_id),
        CONSTRAINT fk_quiz_answer_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
        CONSTRAINT fk_quiz_answer_question FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
    )");

    $conn->query("CREATE TABLE IF NOT EXISTS quiz_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        attempt_id INT NOT NULL UNIQUE,
        student_id INT NOT NULL,
        session_id INT NOT NULL,
        total_questions INT NOT NULL,
        correct_answers INT NOT NULL,
        score INT NOT NULL,
        percentage DECIMAL(5,2) NOT NULL,
        attempt_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_student (student_id),
        INDEX idx_session (session_id),
        CONSTRAINT fk_quiz_result_attempt FOREIGN KEY (attempt_id) REFERENCES quiz_attempts(id) ON DELETE CASCADE,
        CONSTRAINT fk_quiz_result_student FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
        CONSTRAINT fk_quiz_result_session FOREIGN KEY (session_id) REFERENCES iap_sessions(id) ON DELETE CASCADE
    )");

    $validation_sql = "SELECT s.id AS session_id, s.{$session_title_column} AS title, s.year, {$quiz_sessions_description_select} AS description, ss.registration_status
                       FROM iap_student_sessions ss
                       JOIN iap_sessions s ON ss.session_id = s.id
                       WHERE ss.student_id = ? AND ss.session_id = ?";
    $validation_stmt = $conn->prepare($validation_sql);
    if (!$validation_stmt) {
        $error_message = "Database error: " . $conn->error;
    } else {
        $validation_stmt->bind_param("ii", $_SESSION['student_id'], $session_id);
        $validation_stmt->execute();
        $validation_result = $validation_stmt->get_result();
        $session_data = $validation_result ? $validation_result->fetch_assoc() : null;
        $validation_stmt->close();

        if (!$session_data) {
            $error_message = "You are not registered for this module.";
        } elseif (!in_array($session_data['registration_status'], ['registered', 'completed'], true)) {
            $error_message = "You cannot take this quiz. Registration status: " . htmlspecialchars($session_data['registration_status']);
        } else {
            // Check if student has already completed this quiz (retake prevention)
            $quiz_already_completed = has_student_completed_quiz($conn, $_SESSION['student_id'], $session_id);
            
            if ($quiz_already_completed) {
                $latest_attempt = get_latest_quiz_attempt($conn, $_SESSION['student_id'], $session_id);
                $error_message = "You have already completed this quiz. Your score: " . 
                                htmlspecialchars($latest_attempt['score'] . '/' . $latest_attempt['total_questions']) . 
                                " (" . htmlspecialchars($latest_attempt['percentage']) . "%)";
            } else {
                // Student is authorized to take the quiz (no approval needed)
                $is_authorized = true;
            }
        }
    }
}

if ($_SERVER["REQUEST_METHOD"] === "POST" && $is_authorized) {
    $questions_stmt = $conn->prepare("SELECT id, correct_answer FROM quiz_questions WHERE session_id = ? AND is_active = 1 ORDER BY id ASC");
    if (!$questions_stmt) {
        $error_message = "Database error: " . $conn->error;
    } else {
        $questions_stmt->bind_param("i", $session_id);
        $questions_stmt->execute();
        $questions_result = $questions_stmt->get_result();
        $keys = [];
        $correct_map = [];
        while ($q = $questions_result->fetch_assoc()) {
            $qid = (int)$q['id'];
            $keys[] = $qid;
            $correct_map[$qid] = strtoupper((string)$q['correct_answer']);
        }
        $questions_stmt->close();

        if (empty($keys)) {
            $error_message = "No quiz questions are available for this module. If a quiz file exists, ask your coordinator to refresh or check the module name matches the CSV.";
        } else {
            $answers = $_POST['answers'] ?? [];
            $total = count($keys);
            $correct = 0;

            $conn->begin_transaction();
            try {
                // Create quiz attempt (no request_id needed - approval workflow removed)
                $attempt_stmt = $conn->prepare("INSERT INTO quiz_attempts (student_id, session_id, request_id) VALUES (?, ?, NULL)");
                $attempt_stmt->bind_param("ii", $_SESSION['student_id'], $session_id);
                $attempt_stmt->execute();
                $attempt_id = (int)$conn->insert_id;
                $attempt_stmt->close();

                $answer_stmt = $conn->prepare("INSERT INTO quiz_answers (attempt_id, question_id, selected_answer, is_correct) VALUES (?, ?, ?, ?)");
                foreach ($keys as $qid) {
                    $selected = strtoupper(trim((string)($answers[$qid] ?? '')));
                    $selected_value = in_array($selected, ['A', 'B', 'C', 'D'], true) ? $selected : null;
                    $is_correct = ($selected_value !== null && $selected_value === $correct_map[$qid]) ? 1 : 0;
                    if ($is_correct) {
                        $correct++;
                    }
                    $answer_stmt->bind_param("iisi", $attempt_id, $qid, $selected_value, $is_correct);
                    $answer_stmt->execute();
                }
                $answer_stmt->close();

                $score = $correct;
                $percentage = $total > 0 ? round(($correct / $total) * 100, 2) : 0.0;
                $result_stmt = $conn->prepare("INSERT INTO quiz_results (attempt_id, student_id, session_id, total_questions, correct_answers, score, percentage) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $result_stmt->bind_param("iiiiiid", $attempt_id, $_SESSION['student_id'], $session_id, $total, $correct, $score, $percentage);
                $result_stmt->execute();
                $result_stmt->close();

                $conn->commit();
                $success_message = "Quiz submitted successfully. Score: {$score}/{$total} ({$percentage}%).";
            } catch (Exception $e) {
                $conn->rollback();
                error_log('Quiz submit error: ' . $e->getMessage());
                $error_message = "Unable to submit quiz. Please try again.";
            }
        }
    }
}

if ($is_authorized && empty($error_message)) {
    $csv_folder_year = (string)(isset($_SESSION['year']) && $_SESSION['year'] !== '' ? $_SESSION['year'] : ($session_data['year'] ?? ''));
    error_log('Quiz page: student_session_year=' . (string)($_SESSION['year'] ?? '') . ' session_catalog_year=' . (string)($session_data['year'] ?? '') . ' csv_folder_year=' . $csv_folder_year . ' module_title=' . (string)($session_data['title'] ?? ''));

    $resolved = quiz_resolve_module_csv($csv_folder_year, (string)$session_data['title']);

    $load_stmt = $conn->prepare("SELECT COUNT(*) as count FROM quiz_questions WHERE session_id = ? AND is_active = 1");
    $load_stmt->bind_param("i", $session_id);
    $load_stmt->execute();
    $load_result = $load_stmt->get_result();
    $question_count = (int)($load_result ? $load_result->fetch_assoc()['count'] : 0);
    $load_stmt->close();

    if ($question_count === 0 && !empty($session_data)) {
        error_log("Quiz: syncing questions from CSV for session_id={$session_id} title='" . $session_data['title'] . "'");
        $inserted = quiz_load_questions_for_session($conn, $session_id, (string)$session_data['year'], (string)$session_data['title'], null, $csv_folder_year);
        error_log("Quiz: CSV sync inserted {$inserted} row(s) (csv_folder_year={$csv_folder_year})");
    }

    $load_stmt = $conn->prepare("SELECT id, question, option_a, option_b, option_c, option_d FROM quiz_questions WHERE session_id = ? AND is_active = 1 ORDER BY id ASC");
    $load_stmt->bind_param("i", $session_id);
    $load_stmt->execute();
    $load_result = $load_stmt->get_result();
    while ($row = $load_result->fetch_assoc()) {
        $quiz_questions[] = $row;
    }
    $load_stmt->close();

    if (empty($quiz_questions)) {
        $preview = quiz_read_question_rows_from_resolved($resolved, $csv_folder_year, (string)$session_data['title']);
        if ($resolved === null) {
            error_log('Quiz UI: no CSV resolved for csv_folder_year=' . $csv_folder_year . ' title=' . $session_data['title']);
            $error_message = 'No quiz CSV could be located for your module and academic year folder (quiz_data/Year1–Year4). This message appears only when the file is missing or unreadable.';
        } elseif ($preview === []) {
            error_log('Quiz UI: CSV resolved to ' . ($resolved['path'] ?? '') . ' but zero valid rows for csv_folder_year/module slug.');
            $error_message = 'A quiz file was found, but it has no valid questions for your year, or every row failed validation. Check the CSV format (year,module,question,options,correct_answer).';
        }
    }

    $latest_stmt = $conn->prepare("SELECT score, total_questions, percentage, attempt_date FROM quiz_results WHERE student_id = ? AND session_id = ? ORDER BY attempt_date DESC, id DESC LIMIT 1");
    if ($latest_stmt) {
        $latest_stmt->bind_param("ii", $_SESSION['student_id'], $session_id);
        $latest_stmt->execute();
        $latest_result = $latest_stmt->get_result();
        $latest_attempt = $latest_result ? $latest_result->fetch_assoc() : null;
        $latest_stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quiz - IAP Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="../common/theme.css">
</head>
<body style="background:#f8f9fa;">
    <nav class="navbar navbar-dark" style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);">
        <div class="container-lg">
            <span class="navbar-brand"><i class="fas fa-graduation-cap"></i> IAP Portal</span>
            <a href="student_dashboard.php?view=view_registered_sessions" class="btn btn-outline-light btn-sm"><i class="fas fa-arrow-left"></i> Back</a>
        </div>
    </nav>
    <div class="container-lg py-4">
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error_message); ?></div>
        <?php elseif (!empty($info_message)): ?>
            <div class="alert alert-info"><?php echo htmlspecialchars($info_message); ?></div>
        <?php elseif (!empty($success_message)): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($success_message); ?></div>
        <?php endif; ?>

        <?php if ($is_authorized && !empty($session_data)): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h5 class="mb-1"><?php echo htmlspecialchars($session_data['title']); ?></h5>
                    <small class="text-muted">Year <?php echo htmlspecialchars($session_data['year']); ?></small>
                    <?php if ($latest_attempt): ?>
                        <div class="mt-2 text-muted">Last attempt: <?php echo htmlspecialchars($latest_attempt['score']); ?>/<?php echo htmlspecialchars($latest_attempt['total_questions']); ?> (<?php echo htmlspecialchars($latest_attempt['percentage']); ?>%)</div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($quiz_questions)): ?>
                <form method="POST" class="card">
                    <div class="card-body">
                        <?php foreach ($quiz_questions as $idx => $q): ?>
                            <div class="mb-4 pb-3 border-bottom">
                                <div class="text-primary fw-bold small">Question <?php echo $idx + 1; ?></div>
                                <div class="fw-semibold mb-2"><?php echo htmlspecialchars($q['question']); ?></div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="answers[<?php echo htmlspecialchars((string)$q['id']); ?>]" value="A"><label class="form-check-label">A. <?php echo htmlspecialchars($q['option_a']); ?></label></div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="answers[<?php echo htmlspecialchars((string)$q['id']); ?>]" value="B"><label class="form-check-label">B. <?php echo htmlspecialchars($q['option_b']); ?></label></div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="answers[<?php echo htmlspecialchars((string)$q['id']); ?>]" value="C"><label class="form-check-label">C. <?php echo htmlspecialchars($q['option_c']); ?></label></div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="answers[<?php echo htmlspecialchars((string)$q['id']); ?>]" value="D"><label class="form-check-label">D. <?php echo htmlspecialchars($q['option_d']); ?></label></div>
                                <div class="form-check"><input class="form-check-input" type="radio" name="answers[<?php echo htmlspecialchars((string)$q['id']); ?>]" value="" checked><label class="form-check-label text-muted">Skip (unanswered)</label></div>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-check"></i> Submit Quiz</button>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-warning">
                    <?php echo !empty($error_message) ? htmlspecialchars($error_message) : 'Quiz questions are not available for this module yet.'; ?>
                    <?php if (!empty($session_data)): ?>
                        <br><small class="text-muted">
                            Module: <?php echo htmlspecialchars((string)$session_data['title']); ?> · Catalog year: <?php echo htmlspecialchars((string)$session_data['year']); ?>
                        </small>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
