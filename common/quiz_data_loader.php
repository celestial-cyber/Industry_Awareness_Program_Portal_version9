<?php
/**
 * Loads quiz questions from quiz_data CSV files and maps them to existing sessions.
 * Designed for on-demand loading when a student opens a module quiz.
 */

function quiz_normalize_year(string $raw): string
{
    $v = strtolower(trim($raw));
    if (strpos($v, 'graduate') !== false) {
        return 'Graduate';
    }
    if (preg_match('/([1-4])/', $v, $m)) {
        return $m[1];
    }
    return '';
}

function quiz_text_key(string $text): string
{
    $text = strtolower($text);
    // Remove common words that don't help with matching
    $text = preg_replace('/\b(and|the|of|for|to|in|with|on|at|by|how|what|when|where|why|which|who)\b/', '', $text);
    // Replace special characters with spaces and normalize
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    // Remove extra spaces and trim
    $text = preg_replace('/\s+/', ' ', trim($text));
    return $text;
}

function quiz_split_merged_modules(string $moduleField): array
{
    $modules = [];
    
    // Try to split merged module names using common patterns
    $patterns = [
        // Pattern: CapitalizedWord followed by another CapitalizedWord
        '/([a-z])([A-Z])/',
        // Pattern: Word followed by number and another word
        '/(\w+)(\d+)(\w+)/',
        // Pattern: Common conjunctions that might be missing
        '/(Career|Skills|Management|Leadership|Development|Training|Workshop|Session|Module)([A-Z])/'
    ];
    
    $candidate = $moduleField;
    
    // Apply patterns to split
    foreach ($patterns as $pattern) {
        $candidate = preg_replace($pattern, '$1 $2', $candidate);
    }
    
    // Additional manual splits for known problematic combinations
    $knownMerges = [
        'Introduction to Engineering CareersHow to Ace Ideathons' => ['Introduction to Engineering Careers', 'How to Ace Ideathons'],
        'Resume Building and Career PositioningInterview Preparation Fundamentals' => ['Resume Building and Career Positioning', 'Interview Preparation Fundamentals'],
        'Startup Ecosystem and EntrepreneurshipLeadership and Management Skills' => ['Startup Ecosystem and Entrepreneurship', 'Leadership and Management Skills'],
        'Career Paths Beyond Campus PlacementsConfidence Building in High-Pressure Situations' => ['Career Paths Beyond Campus Placements', 'Confidence Building in High-Pressure Situations'],
        'Advanced System Design and year 4-> Advanced System Design & Scalability' => ['Advanced System Design & Scalability'],
        'Advanced System Design & ScalabilitySpecialization Deep Dive' => ['Advanced System Design & Scalability', 'Specialization Deep Dive'],
        'Research & Innovation in EngineeringAdvanced Leadership & Management' => ['Research & Innovation in Engineering', 'Advanced Leadership & Management'],
        'Industry Certifications & Strategic Learning RoadmapGlobal Opportunities & Remote Work' => ['Industry Certifications & Strategic Learning Roadmap', 'Global Opportunities & Remote Work'],
        'Real-World Project DevelopmentPersonal Branding & Personal Development' => ['Real-World Project Development', 'Personal Branding & Personal Development'],
        'Alternative Paths & Contingency PlanningStartup Ecosystem and Entrepreneurship' => ['Alternative Paths & Contingency Planning', 'Startup Ecosystem and Entrepreneurship'],
        'Startup Ecosystem and EntrepreneurshipLeadership and Management Skills' => ['Startup Ecosystem and Entrepreneurship', 'Leadership and Management Skills'],
    ];
    
    if (isset($knownMerges[$moduleField])) {
        return $knownMerges[$moduleField];
    }
    
    // Try to detect splits by looking for capital letters that start new concepts
    $words = preg_split('/(?=[A-Z][a-z])/', $candidate, -1, PREG_SPLIT_NO_EMPTY);
    
    if (count($words) > 1) {
        // Try to group words into meaningful modules
        $currentModule = '';
        foreach ($words as $word) {
            $word = trim($word);
            if ($word === '') continue;
            
            if ($currentModule === '') {
                $currentModule = $word;
            } else {
                // Check if adding this word makes the module too long
                if (strlen($currentModule . ' ' . $word) > 100) {
                    $modules[] = trim($currentModule);
                    $currentModule = $word;
                } else {
                    $currentModule .= ' ' . $word;
                }
            }
        }
        if ($currentModule !== '') {
            $modules[] = trim($currentModule);
        }
    } else {
        $modules[] = $candidate;
    }
    
    return array_filter($modules);
}

function quiz_module_matches_session(string $moduleField, string $sessionTitle): bool
{
    // First try exact match
    $m = quiz_text_key($moduleField);
    $s = quiz_text_key($sessionTitle);
    if ($m === '' || $s === '') {
        return false;
    }
    
    // Exact match
    if ($m === $s) {
        return true;
    }
    
    // Contains match
    if (strpos($m, $s) !== false || strpos($s, $m) !== false) {
        return true;
    }
    
    // Try splitting merged modules and match each part
    $splitModules = quiz_split_merged_modules($moduleField);
    foreach ($splitModules as $module) {
        $moduleKey = quiz_text_key($module);
        if ($moduleKey === $s || strpos($moduleKey, $s) !== false || strpos($s, $moduleKey) !== false) {
            return true;
        }
    }
    
    // Fuzzy matching - check if most words match
    $mWords = explode(' ', $m);
    $sWords = explode(' ', $s);
    
    if (count($mWords) >= 3 && count($sWords) >= 2) {
        $matches = 0;
        foreach ($sWords as $sWord) {
            if (in_array($sWord, $mWords)) {
                $matches++;
            }
        }
        // If at least 60% of words match, consider it a match
        if ($matches / count($sWords) >= 0.6) {
            return true;
        }
    }
    
    return false;
}

function quiz_get_csv_file_for_year(string $year, ?string $quizDataDir = null): ?string
{
    $quizDataDir = $quizDataDir ?: (__DIR__ . '/../quiz_data');
    $yearNormalized = quiz_normalize_year($year);
    
    if ($yearNormalized === '') {
        return null;
    }
    
    // Map years to specific CSV files
    $fileMap = [
        '1' => 'Consolidated_1st_Year_Modules_Final_v3.csv',
        '2' => 'Consolidated_2nd_Year_Modules_Final.csv', 
        '3' => 'Consolidated_3rd_Year_Modules.csv',
        '4' => 'Consolidated_4th_Year_Modules_V2.csv'
    ];
    
    if (isset($fileMap[$yearNormalized])) {
        $filePath = $quizDataDir . '/' . $fileMap[$yearNormalized];
        if (file_exists($filePath)) {
            return $filePath;
        }
    }
    
    // Fallback: try to find any matching file
    $files = glob($quizDataDir . '/*' . $yearNormalized . '*.csv');
    return $files ? $files[0] : null;
}

/**
 * Loads questions for one target session from the appropriate CSV file.
 * Returns number of inserted rows.
 */
function quiz_load_questions_for_session(mysqli $conn, int $sessionId, string $sessionYear, string $sessionTitle, ?string $quizDataDir = null): int
{
    if ($sessionId <= 0 || $sessionYear === '' || $sessionTitle === '') {
        error_log("Quiz loader: Invalid parameters - session_id: $sessionId, year: $sessionYear, title: $sessionTitle");
        return 0;
    }

    $quizDataDir = $quizDataDir ?: (__DIR__ . '/../quiz_data');
    if (!is_dir($quizDataDir)) {
        error_log("Quiz loader: Quiz data directory not found: $quizDataDir");
        return 0;
    }

    $inserted = 0;
    $yearNormalized = quiz_normalize_year($sessionYear);
    if ($yearNormalized === '') {
        error_log("Quiz loader: Could not normalize year: $sessionYear");
        return 0;
    }

    // Get the specific CSV file for this year
    $csvFile = quiz_get_csv_file_for_year($sessionYear, $quizDataDir);
    if (!$csvFile) {
        error_log("Quiz loader: No CSV file found for year: $sessionYear");
        return 0;
    }

    error_log("Quiz loader: Loading questions for session '$sessionTitle' (Year: $sessionYear) from file: " . basename($csvFile));

    $checkStmt = $conn->prepare("SELECT id FROM quiz_questions WHERE session_id = ? AND question = ? LIMIT 1");
    $insertStmt = $conn->prepare("INSERT INTO quiz_questions
        (session_id, year, module, question, option_a, option_b, option_c, option_d, correct_answer, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)");

    if (!$checkStmt || !$insertStmt) {
        error_log("Quiz loader: Failed to prepare database statements");
        return 0;
    }

    $fh = fopen($csvFile, 'r');
    if ($fh === false) {
        error_log("Quiz loader: Failed to open CSV file: $csvFile");
        $checkStmt->close();
        $insertStmt->close();
        return 0;
    }

    $header = fgetcsv($fh);
    if (!$header) {
        error_log("Quiz loader: Failed to read CSV header from: $csvFile");
        fclose($fh);
        $checkStmt->close();
        $insertStmt->close();
        return 0;
    }

    $totalRows = 0;
    $matchedRows = 0;
    $skippedRows = 0;

    while (($row = fgetcsv($fh)) !== false) {
        $totalRows++;
        
        if (count($row) < 8) {
            $skippedRows++;
            continue;
        }
        
        $yearRaw = trim((string)$row[0]);
        $module = trim((string)$row[1]);
        $question = trim((string)$row[2]);
        $a = trim((string)$row[3]);
        $b = trim((string)$row[4]);
        $c = trim((string)$row[5]);
        $d = trim((string)$row[6]);
        $ans = strtoupper(trim((string)$row[7]));

        if ($question === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($ans, ['A', 'B', 'C', 'D'], true)) {
            $skippedRows++;
            continue;
        }

        // Skip header row
        if (strtolower($yearRaw) === 'year' && strtolower($module) === 'module') {
            continue;
        }

        $rowYear = quiz_normalize_year($yearRaw);
        if ($rowYear !== $yearNormalized) {
            continue;
        }
        
        // Check if module matches session
        if (!quiz_module_matches_session($module, $sessionTitle)) {
            continue;
        }
        
        $matchedRows++;

        // Check if question already exists
        $checkStmt->bind_param("is", $sessionId, $question);
        $checkStmt->execute();
        $existsResult = $checkStmt->get_result();
        if ($existsResult && $existsResult->num_rows > 0) {
            continue;
        }

        // Insert new question
        $insertStmt->bind_param("issssssss", $sessionId, $sessionYear, $module, $question, $a, $b, $c, $d, $ans);
        if ($insertStmt->execute()) {
            $inserted++;
        } else {
            error_log("Quiz loader: Failed to insert question: " . $insertStmt->error);
        }
    }

    fclose($fh);
    $checkStmt->close();
    $insertStmt->close();
    
    error_log("Quiz loader: Session '$sessionTitle' - Total rows: $totalRows, Matched: $matchedRows, Skipped: $skippedRows, Inserted: $inserted");
    
    return $inserted;
}

/**
 * Loads questions directly from CSV file (fallback when database fails)
 * Returns array of questions
 */
function quiz_load_questions_direct_from_csv(string $sessionYear, string $sessionTitle, ?string $quizDataDir = null): array
{
    $questions = [];
    
    $quizDataDir = $quizDataDir ?: (__DIR__ . '/../quiz_data');
    if (!is_dir($quizDataDir)) {
        error_log("Direct CSV loader: Quiz data directory not found: $quizDataDir");
        return $questions;
    }

    $yearNormalized = quiz_normalize_year($sessionYear);
    if ($yearNormalized === '') {
        error_log("Direct CSV loader: Could not normalize year: $sessionYear");
        return $questions;
    }

    $csvFile = quiz_get_csv_file_for_year($sessionYear, $quizDataDir);
    if (!$csvFile) {
        error_log("Direct CSV loader: No CSV file found for year: $sessionYear");
        return $questions;
    }

    error_log("Direct CSV loader: Loading questions for session '$sessionTitle' (Year: $sessionYear) from file: " . basename($csvFile));

    $fh = fopen($csvFile, 'r');
    if ($fh === false) {
        error_log("Direct CSV loader: Failed to open CSV file: $csvFile");
        return $questions;
    }

    $header = fgetcsv($fh);
    if (!$header) {
        error_log("Direct CSV loader: Failed to read CSV header from: $csvFile");
        fclose($fh);
        return $questions;
    }

    $totalRows = 0;
    $matchedRows = 0;
    $skippedRows = 0;

    while (($row = fgetcsv($fh)) !== false) {
        $totalRows++;
        
        if (count($row) < 8) {
            $skippedRows++;
            continue;
        }
        
        $yearRaw = trim((string)$row[0]);
        $module = trim((string)$row[1]);
        $question = trim((string)$row[2]);
        $a = trim((string)$row[3]);
        $b = trim((string)$row[4]);
        $c = trim((string)$row[5]);
        $d = trim((string)$row[6]);
        $ans = strtoupper(trim((string)$row[7]));

        if ($question === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($ans, ['A', 'B', 'C', 'D'], true)) {
            $skippedRows++;
            continue;
        }

        // Skip header row
        if (strtolower($yearRaw) === 'year' && strtolower($module) === 'module') {
            continue;
        }

        $rowYear = quiz_normalize_year($yearRaw);
        if ($rowYear !== $yearNormalized) {
            continue;
        }
        
        // Check if module matches session
        if (!quiz_module_matches_session($module, $sessionTitle)) {
            continue;
        }
        
        $matchedRows++;
        
        $questions[] = [
            'question' => $question,
            'option_a' => $a,
            'option_b' => $b,
            'option_c' => $c,
            'option_d' => $d,
            'correct_answer' => $ans,
            'module' => $module
        ];
    }

    fclose($fh);
    
    error_log("Direct CSV loader: Session '$sessionTitle' - Total rows: $totalRows, Matched: $matchedRows, Skipped: $skipped, Questions loaded: " . count($questions));
    
    return $questions;
}

/**
 * Debug function to list all available modules in CSV files
 */
function quiz_debug_list_modules(?string $quizDataDir = null): array
{
    $modules = [];
    $quizDataDir = $quizDataDir ?: (__DIR__ . '/../quiz_data');
    
    if (!is_dir($quizDataDir)) {
        return $modules;
    }
    
    $files = glob($quizDataDir . '/*.csv');
    foreach ($files as $file) {
        $fh = fopen($file, 'r');
        if ($fh === false) continue;
        
        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            continue;
        }
        
        $fileName = basename($file);
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 2) continue;
            
            $yearRaw = trim((string)$row[0]);
            $module = trim((string)$row[1]);
            
            if (strtolower($yearRaw) === 'year' && strtolower($module) === 'module') {
                continue;
            }
            
            $year = quiz_normalize_year($yearRaw);
            if ($year !== '') {
                if (!isset($modules[$year])) {
                    $modules[$year] = [];
                }
                
                // Split merged modules
                $splitModules = quiz_split_merged_modules($module);
                foreach ($splitModules as $mod) {
                    $cleanMod = trim($mod);
                    if ($cleanMod !== '' && !in_array($cleanMod, $modules[$year])) {
                        $modules[$year][] = $cleanMod;
                    }
                }
            }
        }
        
        fclose($fh);
    }
    
    return $modules;
}
