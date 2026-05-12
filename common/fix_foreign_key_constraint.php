<?php
/**
 * Fix Foreign Key Constraint Issue
 * 
 * The iap_student_sessions table has a foreign key constraint pointing to 'sessions' table
 * instead of 'iap_sessions' table. This script fixes that issue.
 * 
 * Error: Cannot add or update a child row: a foreign key constraint fails 
 * (`iap_portal`.`iap_student_sessions`, CONSTRAINT `iap_student_sessions_ibfk_2` 
 * FOREIGN KEY (`session_id`) REFERENCES `sessions` (`id`) ON DELETE CASCADE)
 */

require_once __DIR__ . '/db.php';

echo "Starting Foreign Key Constraint Fix...\n\n";

// Step 1: Check current foreign key constraints
echo "Step 1: Checking current foreign key constraints...\n";
$fk_check = $conn->query("SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
                          FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                          WHERE TABLE_NAME = 'iap_student_sessions' AND COLUMN_NAME = 'session_id'");

if ($fk_check && $fk_check->num_rows > 0) {
    while ($row = $fk_check->fetch_assoc()) {
        echo "  Found: " . $row['CONSTRAINT_NAME'] . " -> " . $row['REFERENCED_TABLE_NAME'] . "\n";
    }
} else {
    echo "  No foreign key constraints found for session_id\n";
}

// Step 2: Check if 'sessions' table exists
echo "\nStep 2: Checking if 'sessions' table exists...\n";
$sessions_check = $conn->query("SHOW TABLES LIKE 'sessions'");
if ($sessions_check && $sessions_check->num_rows > 0) {
    echo "  'sessions' table EXISTS\n";
    
    // Check if it has data
    $count = $conn->query("SELECT COUNT(*) as cnt FROM sessions");
    $count_row = $count->fetch_assoc();
    echo "  'sessions' table has " . $count_row['cnt'] . " rows\n";
} else {
    echo "  'sessions' table DOES NOT EXIST\n";
}

// Step 3: Check if 'iap_sessions' table exists
echo "\nStep 3: Checking if 'iap_sessions' table exists...\n";
$iap_sessions_check = $conn->query("SHOW TABLES LIKE 'iap_sessions'");
if ($iap_sessions_check && $iap_sessions_check->num_rows > 0) {
    echo "  'iap_sessions' table EXISTS\n";
    
    // Check if it has data
    $count = $conn->query("SELECT COUNT(*) as cnt FROM iap_sessions");
    $count_row = $count->fetch_assoc();
    echo "  'iap_sessions' table has " . $count_row['cnt'] . " rows\n";
} else {
    echo "  'iap_sessions' table DOES NOT EXIST\n";
}

// Step 4: Drop the incorrect foreign key constraint
echo "\nStep 4: Dropping incorrect foreign key constraint...\n";
$drop_fk = $conn->query("ALTER TABLE iap_student_sessions DROP FOREIGN KEY iap_student_sessions_ibfk_2");
if ($drop_fk) {
    echo "  ✓ Successfully dropped foreign key constraint\n";
} else {
    echo "  ✗ Error dropping foreign key: " . $conn->error . "\n";
}

// Step 5: Add the correct foreign key constraint
echo "\nStep 5: Adding correct foreign key constraint...\n";
$add_fk = $conn->query("ALTER TABLE iap_student_sessions ADD CONSTRAINT fk_iap_student_sessions_session 
                        FOREIGN KEY (session_id) REFERENCES iap_sessions(id) ON DELETE CASCADE");
if ($add_fk) {
    echo "  ✓ Successfully added correct foreign key constraint\n";
} else {
    echo "  ✗ Error adding foreign key: " . $conn->error . "\n";
}

// Step 6: Verify the fix
echo "\nStep 6: Verifying the fix...\n";
$verify_fk = $conn->query("SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
                           FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                           WHERE TABLE_NAME = 'iap_student_sessions' AND COLUMN_NAME = 'session_id'");

if ($verify_fk && $verify_fk->num_rows > 0) {
    while ($row = $verify_fk->fetch_assoc()) {
        echo "  ✓ Constraint: " . $row['CONSTRAINT_NAME'] . " -> " . $row['REFERENCED_TABLE_NAME'] . "\n";
    }
} else {
    echo "  ✗ No foreign key constraints found\n";
}

echo "\n✓ Foreign Key Constraint Fix Complete!\n";
echo "Students should now be able to register for sessions without errors.\n";
?>
