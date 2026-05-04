<?php
// Test password verification
$stored_hash = '$2y$10$ACnHZm1VvA1MkO8OmoKv4uOtl4jfdX9F1qFcP4e..e6yugwmvVtxm';
$input_password = 'admin123';

echo "Testing password verification:\n";
echo "Stored hash: " . $stored_hash . "\n";
echo "Input password: " . $input_password . "\n";
echo "Hash length: " . strlen($stored_hash) . "\n";

$result = password_verify($input_password, $stored_hash);
echo "Password verify result: " . ($result ? "TRUE" : "FALSE") . "\n";

// Test database connection and retrieval
require_once __DIR__ . '/db.php';

// Get the stored hash from database
$result = $conn->query("SELECT password FROM iap_users_details WHERE username='admin@sa.com'");
if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    $db_hash = $row['password'];
    echo "Database hash: " . $db_hash . "\n";
    echo "Database hash length: " . strlen($db_hash) . "\n";

    $db_result = password_verify($input_password, $db_hash);
    echo "Database password verify result: " . ($db_result ? "TRUE" : "FALSE") . "\n";
} else {
    echo "No admin user found in database\n";
}

$conn->close();
?>