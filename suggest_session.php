<?php
// suggest_session.php
$servername = "localhost";
$username = "root";
$password = "root@123";

// Create connection
$conn = new mysqli($servername, $username, $password);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Create database if not exists
$sql = "CREATE DATABASE IF NOT EXISTS iap_portal";
$conn->query($sql);

// Select the database
$conn->select_db("iap_portal");

// Create session_suggestions table
$sql = "CREATE TABLE IF NOT EXISTS iap_session_suggestions (
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
);";

$conn->query($sql);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $name = $_POST['name'];
    $roll_number = $_POST['roll_number'];
    $year = $_POST['year'];
    $branch = $_POST['branch'];
    $section = $_POST['section'];
    $session_desired = $_POST['session_desired'];
    $other_query = $_POST['other_query'];

    $sql = "INSERT INTO session_suggestions (name, roll_number, year, branch, section, session_desired, other_query)
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssss", $name, $roll_number, $year, $branch, $section, $session_desired, $other_query);

    if ($stmt->execute()) {
        // Redirect back to home page with success message
        header("Location: index.php?suggestion_success=1");
        exit();
    } else {
        echo "Error: " . $sql . "<br>" . $conn->error;
    }

    $stmt->close();
}

$conn->close();
?>
