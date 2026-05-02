<?php
/**
 * Centralized database connection.
 * Update these values for your environment.
 */
$db_host = "localhost";
$db_user = "root";
$db_pass = "";
$db_name = "iap_portal";

$conn = new mysqli($db_host, $db_user, $db_pass);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$conn->query("CREATE DATABASE IF NOT EXISTS `{$db_name}`");
$conn->select_db($db_name);
$conn->set_charset("utf8mb4");
?>
