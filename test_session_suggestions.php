<?php
/**
 * Test file to verify session suggestions functionality
 * This file checks if the table exists and displays current data
 */

$servername = "localhost";
$username = "root";
$password = "";

$conn = new mysqli($servername, $username, $password);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Select database
$conn->select_db("iap_portal");

echo "<h2>Session Suggestions Table Test</h2>";

// Check if table exists
$table_check = $conn->query("SHOW TABLES LIKE 'iap_session_suggestions'");
if ($table_check->num_rows > 0) {
    echo "<p style='color: green;'>✓ Table 'iap_session_suggestions' exists!</p>";
    
    // Get table structure
    echo "<h3>Table Structure:</h3>";
    $structure = $conn->query("DESCRIBE iap_session_suggestions");
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    while ($row = $structure->fetch_assoc()) {
        echo "<tr>";
        echo "<td>{$row['Field']}</td>";
        echo "<td>{$row['Type']}</td>";
        echo "<td>{$row['Null']}</td>";
        echo "<td>{$row['Key']}</td>";
        echo "<td>{$row['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Get current data
    echo "<h3>Current Data:</h3>";
    $data = $conn->query("SELECT * FROM iap_session_suggestions ORDER BY submitted_at DESC");
    
    if ($data->num_rows > 0) {
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Name</th><th>Roll Number</th><th>Year</th><th>Branch</th><th>Section</th><th>Session Desired</th><th>Status</th><th>Submitted At</th></tr>";
        while ($row = $data->fetch_assoc()) {
            echo "<tr>";
            echo "<td>{$row['id']}</td>";
            echo "<td>{$row['name']}</td>";
            echo "<td>{$row['roll_number']}</td>";
            echo "<td>{$row['year']}</td>";
            echo "<td>{$row['branch']}</td>";
            echo "<td>{$row['section']}</td>";
            echo "<td>{$row['session_desired']}</td>";
            echo "<td><strong>{$row['status']}</strong></td>";
            echo "<td>{$row['submitted_at']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No data in table yet.</p>";
    }
    
    // Count by status
    echo "<h3>Statistics:</h3>";
    $stats = $conn->query("SELECT status, COUNT(*) as count FROM iap_session_suggestions GROUP BY status");
    echo "<ul>";
    while ($row = $stats->fetch_assoc()) {
        echo "<li><strong>{$row['status']}</strong>: {$row['count']}</li>";
    }
    echo "</ul>";
    
} else {
    echo "<p style='color: red;'>✗ Table 'iap_session_suggestions' does NOT exist!</p>";
    echo "<p>Creating table now...</p>";
    
    $create_sql = "CREATE TABLE IF NOT EXISTS iap_session_suggestions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        roll_number VARCHAR(50) NOT NULL,
        year ENUM('1', '2', '3', '4') NOT NULL,
        branch VARCHAR(100) NOT NULL,
        section VARCHAR(50) NOT NULL,
        session_desired VARCHAR(255) NOT NULL,
        other_query TEXT,
        submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        status ENUM('pending', 'reviewed', 'approved', 'rejected') DEFAULT 'pending'
    )";
    
    if ($conn->query($create_sql)) {
        echo "<p style='color: green;'>✓ Table created successfully!</p>";
    } else {
        echo "<p style='color: red;'>✗ Error creating table: " . $conn->error . "</p>";
    }
}

$conn->close();
?>

<style>
    body {
        font-family: Arial, sans-serif;
        padding: 20px;
        background: #f5f5f5;
    }
    table {
        background: white;
        border-collapse: collapse;
        margin: 20px 0;
    }
    th {
        background: #7c3aed;
        color: white;
        padding: 10px;
    }
    td {
        padding: 8px;
    }
    h2 {
        color: #7c3aed;
    }
</style>
