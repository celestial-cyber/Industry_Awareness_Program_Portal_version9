<?php
/**
 * Quiz Helper Functions
 * Handles quiz validation, retake prevention, and session management
 */

/**
 * Check if a student has already completed a quiz for a session
 * Returns: true if completed, false if not completed
 */
function has_student_completed_quiz($conn, $student_id, $session_id) {
    $sql = "SELECT COUNT(*) as count FROM quiz_results 
            WHERE student_id = ? AND session_id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param("ii", $student_id, $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    
    return (int)($row['count'] ?? 0) > 0;
}

/**
 * Get the latest quiz attempt for a student on a session
 * Returns: array with attempt details or null
 */
function get_latest_quiz_attempt($conn, $student_id, $session_id) {
    $sql = "SELECT id, score, total_questions, percentage, attempt_date 
            FROM quiz_results 
            WHERE student_id = ? AND session_id = ? 
            ORDER BY attempt_date DESC LIMIT 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("ii", $student_id, $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $attempt = $result->fetch_assoc();
    $stmt->close();
    
    return $attempt;
}

/**
 * Validate that a session exists in the database
 * Returns: session data array or null if not found
 */
function validate_session_exists($conn, $session_id) {
    $sql = "SELECT id, title, topic, year FROM iap_sessions WHERE id = ?";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param("i", $session_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $session = $result->fetch_assoc();
    $stmt->close();
    
    return $session;
}

/**
 * Safely register a student for a session with validation
 * Returns: array with 'success' (bool) and 'message' (string)
 */
function safe_register_student_for_session($conn, $student_id, $session_id) {
    // Validate session exists
    $session = validate_session_exists($conn, $session_id);
    if (!$session) {
        return [
            'success' => false,
            'message' => 'Session does not exist in the database.'
        ];
    }
    
    // Check if already registered
    $check_sql = "SELECT id FROM iap_student_sessions 
                  WHERE student_id = ? AND session_id = ?";
    $check_stmt = $conn->prepare($check_sql);
    if (!$check_stmt) {
        return [
            'success' => false,
            'message' => 'Database error during validation.'
        ];
    }
    $check_stmt->bind_param("ii", $student_id, $session_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $already_registered = $check_result->num_rows > 0;
    $check_stmt->close();
    
    if ($already_registered) {
        return [
            'success' => false,
            'message' => 'Already registered for this session.'
        ];
    }
    
    // Register student
    $register_sql = "INSERT INTO iap_student_sessions (student_id, session_id, registration_status) 
                     VALUES (?, ?, 'registered')";
    $register_stmt = $conn->prepare($register_sql);
    if (!$register_stmt) {
        return [
            'success' => false,
            'message' => 'Failed to register for session.'
        ];
    }
    $register_stmt->bind_param("ii", $student_id, $session_id);
    
    if ($register_stmt->execute()) {
        $register_stmt->close();
        return [
            'success' => true,
            'message' => 'Successfully registered for session.'
        ];
    } else {
        $error = $register_stmt->error;
        $register_stmt->close();
        return [
            'success' => false,
            'message' => 'Database error: ' . $error
        ];
    }
}

/**
 * Get quiz button status and label
 * Returns: array with 'status' (string) and 'label' (string)
 */
function get_quiz_button_status($conn, $student_id, $session_id) {
    if (has_student_completed_quiz($conn, $student_id, $session_id)) {
        $attempt = get_latest_quiz_attempt($conn, $student_id, $session_id);
        return [
            'status' => 'completed',
            'label' => 'Quiz Completed',
            'score' => $attempt ? $attempt['score'] . '/' . $attempt['total_questions'] : 'N/A',
            'percentage' => $attempt ? $attempt['percentage'] . '%' : 'N/A',
            'disabled' => true
        ];
    }
    
    return [
        'status' => 'available',
        'label' => 'Take Quiz',
        'disabled' => false
    ];
}
?>
