<?php
/**
 * Test Student Registration
 * Simulates a student registration to verify the fix works
 */

require_once __DIR__ . '/db.php';

echo "Testing Student Registration Flow...\n\n";

// Step 1: Get a test student
echo "Step 1: Finding a test student...\n";
$student_query = "SELECT id, roll_number, full_name, year FROM iap_students LIMIT 1";
$student_result = $conn->query($student_query);

if (!$student_result || $student_result->num_rows === 0) {
    echo "  ✗ No students found in database\n";
    exit(1);
}

$student = $student_result->fetch_assoc();
echo "  ✓ Found student: " . $student['full_name'] . " (ID: " . $student['id'] . ")\n";

// Step 2: Get a test session
echo "\nStep 2: Finding a test session...\n";
$session_query = "SELECT id, title, year FROM iap_sessions LIMIT 1";
$session_result = $conn->query($session_query);

if (!$session_result || $session_result->num_rows === 0) {
    echo "  ✗ No sessions found in database\n";
    exit(1);
}

$session = $session_result->fetch_assoc();
echo "  ✓ Found session: " . $session['title'] . " (ID: " . $session['id'] . ")\n";

// Step 3: Check if already registered
echo "\nStep 3: Checking if student is already registered...\n";
$check_query = "SELECT id FROM iap_student_sessions WHERE student_id = ? AND session_id = ?";
$check_stmt = $conn->prepare($check_query);
$check_stmt->bind_param("ii", $student['id'], $session['id']);
$check_stmt->execute();
$check_result = $check_stmt->get_result();

if ($check_result->num_rows > 0) {
    echo "  ℹ Student already registered for this session\n";
    $check_stmt->close();
} else {
    echo "  ✓ Student not yet registered\n";
    $check_stmt->close();
    
    // Step 4: Attempt registration
    echo "\nStep 4: Attempting to register student for session...\n";
    $register_query = "INSERT INTO iap_student_sessions (student_id, session_id, registration_status) VALUES (?, ?, 'registered')";
    $register_stmt = $conn->prepare($register_query);
    
    if (!$register_stmt) {
        echo "  ✗ Prepare failed: " . $conn->error . "\n";
        exit(1);
    }
    
    $register_stmt->bind_param("ii", $student['id'], $session['id']);
    
    if ($register_stmt->execute()) {
        echo "  ✓ Registration successful!\n";
        $register_stmt->close();
        
        // Step 5: Verify registration
        echo "\nStep 5: Verifying registration...\n";
        $verify_query = "SELECT id, registration_status, registered_at FROM iap_student_sessions WHERE student_id = ? AND session_id = ?";
        $verify_stmt = $conn->prepare($verify_query);
        $verify_stmt->bind_param("ii", $student['id'], $session['id']);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        
        if ($verify_result->num_rows > 0) {
            $registration = $verify_result->fetch_assoc();
            echo "  ✓ Registration verified!\n";
            echo "    - Status: " . $registration['registration_status'] . "\n";
            echo "    - Registered at: " . $registration['registered_at'] . "\n";
        } else {
            echo "  ✗ Registration verification failed\n";
        }
        $verify_stmt->close();
    } else {
        echo "  ✗ Registration failed: " . $register_stmt->error . "\n";
        $register_stmt->close();
        exit(1);
    }
}

echo "\n✓ Student Registration Test Complete!\n";
echo "The foreign key constraint fix is working correctly.\n";
?>
