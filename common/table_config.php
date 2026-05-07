<?php
/**
 * Database Table Configuration
 * Centralized table name constants to prevent future inconsistencies
 * 
 * All table names follow the iap_ prefix convention
 * Use these constants throughout the project instead of hardcoding table names
 */

// Define all table names as constants
define('TABLE_USERS_DETAILS', 'iap_users_details');
define('TABLE_STUDENTS', 'iap_students');
define('TABLE_SESSIONS', 'iap_sessions');
define('TABLE_STUDENT_SESSIONS', 'iap_student_sessions');
define('TABLE_SESSION_REGISTRATIONS', 'iap_session_registrations');
define('TABLE_SESSION_SUGGESTIONS', 'iap_session_suggestions');
define('TABLE_PSYCHOMETRIC_SCORES', 'iap_psychometric_scores');
define('TABLE_PSYCHOMETRIC_QUESTIONS', 'iap_psychometric_questions');

/**
 * Helper function to safely reference table names in queries
 * Usage: $sql = "SELECT * FROM " . get_table('students') . " WHERE id = ?";
 */
function get_table($table_alias) {
    $tables = [
        'users_details' => TABLE_USERS_DETAILS,
        'students' => TABLE_STUDENTS,
        'sessions' => TABLE_SESSIONS,
        'student_sessions' => TABLE_STUDENT_SESSIONS,
        'session_registrations' => TABLE_SESSION_REGISTRATIONS,
        'session_suggestions' => TABLE_SESSION_SUGGESTIONS,
        'psychometric_scores' => TABLE_PSYCHOMETRIC_SCORES,
        'psychometric_questions' => TABLE_PSYCHOMETRIC_QUESTIONS,
    ];
    
    return $tables[$table_alias] ?? null;
}

/**
 * Validation function to ensure table exists
 * Returns true if table exists in database
 */
function table_exists($conn, $table_name) {
    $result = $conn->query("SHOW TABLES LIKE '$table_name'");
    return $result && $result->num_rows > 0;
}

/**
 * Get all table names as array
 * Useful for verification and debugging
 */
function get_all_tables() {
    return [
        'iap_users_details',
        'iap_students',
        'iap_sessions',
        'iap_student_sessions',
        'iap_session_registrations',
        'iap_session_suggestions',
        'iap_psychometric_scores',
        'iap_psychometric_questions',
    ];
}
?>
