<?php
/**
 * Test AJAX Endpoint for Module Filtering
 */

// Start session first
session_start();

// Simulate admin session
$_SESSION['role'] = 'admin';
$_GET['year'] = '3';

// Capture output
ob_start();

// Include the endpoint
require_once __DIR__ . '/../Admin/get_sessions_by_year.php';

// Get output
$output = ob_get_clean();

echo "=== AJAX Endpoint Test ===\n\n";
echo "Request: get_sessions_by_year.php?year=3\n\n";
echo "Response:\n";
echo $output . "\n";

// Parse and display
$data = json_decode($output, true);
if ($data) {
    echo "\n=== Parsed Response ===\n";
    echo "Success: " . ($data['success'] ? 'Yes' : 'No') . "\n";
    echo "Year: " . $data['year'] . "\n";
    echo "Count: " . $data['count'] . "\n";
    echo "\nModules:\n";
    foreach ($data['sessions'] as $session) {
        echo "  - " . $session['display'] . " (ID: " . $session['id'] . ")\n";
    }
}
?>
