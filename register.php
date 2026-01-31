<?php
// register.php
$servername = "localhost";
$username = "root"; // adjust as needed
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

// Create tables if not exist
$sql = "CREATE TABLE IF NOT EXISTS iap_session_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL,
    year ENUM('1', '2', '3', '4') NOT NULL,
    department VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    session_desired VARCHAR(255) NOT NULL,
    other_query TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
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
    $department = $_POST['department'];
    $email = $_POST['email'];
    $session_desired = $_POST['session_desired'];
    $other_query = $_POST['other_query'];

    $sql = "INSERT INTO iap_session_registrations (name, roll_number, year, department, email, session_desired, other_query) VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssssss", $name, $roll_number, $year, $department, $email, $session_desired, $other_query);

    if ($stmt->execute()) {
        header("Location: index.php?success=1");
        exit();
    } else {
        echo "Error: " . $sql . "<br>" . $conn->error;
    }

    $stmt->close();
}

$conn->close();
?>