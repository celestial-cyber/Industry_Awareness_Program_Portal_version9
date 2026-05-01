<?php
/**
 * Reusable student validation helpers.
 * Add new validation functions here for project-wide reuse.
 */

/**
 * Validate student roll number format.
 *
 * Required format:
 * - Total length: 10 characters
 * - First 2 chars: digits (year)
 * - Next 4 chars: BK1A
 * - Next 2 chars: course code (letters or digits)
 * - Last 2 chars: roll number (letters or digits)
 *
 * Example: 21BK1A66L5
 *
 * @param string $rollNumber
 * @return bool
 */
function validate_roll_number(string $rollNumber): bool
{
    $pattern = '/^[0-9]{2}BK1A[A-Za-z0-9]{2}[A-Za-z0-9]{2}$/';
    return preg_match($pattern, trim($rollNumber)) === 1;
}

/**
 * Get the roll number validation regex pattern.
 *
 * @return string
 */
function get_roll_number_pattern(): string
{
    return '/^[0-9]{2}BK1A[A-Za-z0-9]{2}[A-Za-z0-9]{2}$/';
}
