<?php
/**
 * Session Registration Handler
 * Handles student registration for individual sessions
 * Provides context menu functionality for session enrollment
 */

header('Content-Type: application/json');

// Check if session_id is provided
if (!isset($_POST['session_id'])) {
    echo json_encode(['status' => 'error', 'message' => 'Session ID not provided']);
    exit();
}

$session_id = intval($_POST['session_id']);

$servername = "localhost";
$username = "root";
$password = "";

$conn = new mysqli($servername, $username, $password);

if ($conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Database connection failed']);
    exit();
}

$conn->select_db("iap_portal");

// Verify session exists and get topic
$sql = "SELECT id, topic, year FROM sessions WHERE id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $session_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['status' => 'error', 'message' => 'Session not found']);
    $stmt->close();
    $conn->close();
    exit();
}

$session = $result->fetch_assoc();
$stmt->close();

// Store session ID in temporary session variable for later use in login/register
session_start();
$_SESSION['selected_session_id'] = $session_id;
$_SESSION['selected_session_topic'] = $session['topic'];

echo json_encode([
    'status' => 'success',
    'message' => 'Session selected',
    'session_id' => $session_id,
    'session_topic' => $session['topic'],
    'redirect_url' => 'Student/student_login.php?session=' . $session_id
]);

$conn->close();
?>
