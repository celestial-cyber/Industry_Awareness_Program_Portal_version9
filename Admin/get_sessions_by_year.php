<?php
/**
 * AJAX Endpoint: Get Sessions by Year
 * 
 * Returns all sessions for a given academic year
 * Used by Module Quiz Performance filter to dynamically populate Module dropdown
 */

session_start();

// Check admin access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../common/db.php';

// Get year parameter
$year = trim($_GET['year'] ?? '');

// Validate year
if (!in_array($year, ['', '1', '2', '3', '4', 'Graduate'], true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid year']);
    exit;
}

// Check for title column
$title_col_check = $conn->query("SHOW COLUMNS FROM iap_sessions LIKE 'title'");
$session_title_column = ($title_col_check && $title_col_check->num_rows > 0) ? 'title' : 'topic';

// Build query
$sql = "SELECT id, year, {$session_title_column} AS title, session_code 
        FROM iap_sessions 
        WHERE 1=1";

if ($year !== '') {
    $sql .= " AND year = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $year);
} else {
    $stmt = $conn->prepare($sql);
}

$sql .= " ORDER BY {$session_title_column} ASC";

// Re-prepare with ORDER BY
$sql = "SELECT id, year, {$session_title_column} AS title, session_code 
        FROM iap_sessions 
        WHERE 1=1";

if ($year !== '') {
    $sql .= " AND year = ?";
}

$sql .= " ORDER BY {$session_title_column} ASC";

$stmt = $conn->prepare($sql);
if ($year !== '') {
    $stmt->bind_param('s', $year);
}

$stmt->execute();
$result = $stmt->get_result();

$sessions = [];
while ($row = $result->fetch_assoc()) {
    $sessions[] = [
        'id' => (int)$row['id'],
        'year' => $row['year'],
        'title' => $row['title'],
        'session_code' => $row['session_code'],
        'display' => ($row['session_code'] ? $row['session_code'] . ' - ' : '') . $row['title']
    ];
}

$stmt->close();

// Return JSON
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'year' => $year,
    'count' => count($sessions),
    'sessions' => $sessions
]);
?>
