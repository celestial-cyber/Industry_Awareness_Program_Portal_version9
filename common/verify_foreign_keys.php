<?php
require_once __DIR__ . '/db.php';

echo "Checking all foreign key constraints in iap_portal database...\n\n";

$fk_query = "SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME 
              FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
              WHERE TABLE_SCHEMA = 'iap_portal' AND REFERENCED_TABLE_NAME IS NOT NULL
              ORDER BY TABLE_NAME, CONSTRAINT_NAME";

$result = $conn->query($fk_query);

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo "Table: " . $row['TABLE_NAME'] . "\n";
        echo "  Constraint: " . $row['CONSTRAINT_NAME'] . "\n";
        echo "  Column: " . $row['COLUMN_NAME'] . " -> " . $row['REFERENCED_TABLE_NAME'] . "(" . $row['REFERENCED_COLUMN_NAME'] . ")\n";
        echo "\n";
    }
} else {
    echo "No foreign key constraints found.\n";
}

echo "\n✓ Verification Complete\n";
?>
