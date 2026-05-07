<?php
/**
 * Centralized database connection.
 * Update these values for your environment.
 */
$db_host = "localhost";
$db_user = "root";
$db_pass = ""; // XAMPP default: no password. Change if your MySQL has a password.
$db_name = "iap_portal";

// Create connection with explicit parameters
$conn = @new mysqli($db_host, $db_user, $db_pass, $db_name);

// Check connection
if ($conn->connect_error) {
    // If connection fails, try creating database first
    $temp_conn = @new mysqli($db_host, $db_user, $db_pass);
    if ($temp_conn->connect_error) {
        die("Fatal Error: Cannot connect to MySQL server. " . $temp_conn->connect_error . 
            "\n\nPlease verify:\n1. MySQL is running\n2. Username is correct: " . $db_user . 
            "\n3. Password is correct (current: empty)\n4. Host is correct: " . $db_host);
    }
    
    // Create database if it doesn't exist
    $temp_conn->query("CREATE DATABASE IF NOT EXISTS `{$db_name}`");
    $temp_conn->close();
    
    // Try connecting again
    $conn = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
}

$conn->set_charset("utf8mb4");
?>
