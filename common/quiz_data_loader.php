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

/**
 * Maps student/session academic year to quiz_data subfolder (Year1 … Year4).
 * Graduate maps to Year4 when no Graduate folder exists.
 */
function quiz_academic_year_folder(string $year): ?string
{
    $n = quiz_normalize_year($year);
    if ($n === 'Graduate') {
        return 'Year4';
    }
    if ($n === '' || !preg_match('/^[1-4]$/', $n)) {
        return null;
    }
    return 'Year' . $n;
}

/**
 * Normalizes a module/session title to a CSV filename stem (snake_case).
 */
function quiz_module_slug_from_title(string $title): string
{
    $t = strtolower(trim($title));
    $t = str_replace(['&'], [' and '], $t);
    $t = preg_replace('/[^a-z0-9]+/', '_', $t);
    return trim(preg_replace('/_+/', '_', $t), '_');
}

/**
 * Resolves exactly one file: quiz_data/{YearN}/{slug}.csv — no fuzzy scan, no other files.
 *
 * @return array{path:string,year_dir:string,slug:string,match:string}|null
 */
function quiz_resolve_module_csv(string $academicYearForFolder, string $sessionTitle, ?string $quizDataDir = null): ?array
{
    $quizDataDir = $quizDataDir ?: (__DIR__ . '/../quiz_data');
    $yearDirName = quiz_academic_year_folder($academicYearForFolder);

    error_log('Quiz STRICT resolve: academic_year_for_folder="' . $academicYearForFolder . '" → directory ' . ($yearDirName ?? 'NULL'));
    error_log('Quiz STRICT resolve: enrolled_module_title="' . $sessionTitle . '"');

    if ($yearDirName === null) {
        error_log('Quiz STRICT resolve: ABORT — unsupported year (need 1–4 or Graduate).');
        return null;
    }

    $yearPath = $quizDataDir . DIRECTORY_SEPARATOR . $yearDirName;
    if (!is_dir($yearPath)) {
        error_log('Quiz STRICT resolve: ABORT — missing folder: ' . $yearPath);
        return null;
    }

    $slug = quiz_module_slug_from_title($sessionTitle);
    if ($slug === '') {
        error_log('Quiz STRICT resolve: ABORT — empty slug after normalizing module title.');
        return null;
    }

    $expectedName = $slug . '.csv';
    $primary = $yearPath . DIRECTORY_SEPARATOR . $expectedName;

    error_log('Quiz STRICT resolve: expected_filename="' . $expectedName . '" full_path="' . $primary . '" readable=' . (is_readable($primary) ? 'yes' : 'no'));

    if (!is_readable($primary)) {
        error_log('Quiz STRICT resolve: FAIL — exact file not found. No alternate filenames are tried.');
        return null;
    }

    return ['path' => $primary, 'year_dir' => $yearDirName, 'slug' => $slug, 'match' => 'exact_slug_file'];
}

/**
 * Lists .csv files in a directory (non-recursive). Used by debug helpers only.
 *
 * @return string[]
 */
function quiz_list_csv_in_dir(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    foreach (scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        if (preg_match('/\.csv$/i', $f)) {
            $out[] = $dir . DIRECTORY_SEPARATOR . $f;
        }
    }
    natsort($out);

    return array_values($out);
}

/**
 * Legacy helper: consolidated flat CSV (if present). Prefer quiz_resolve_module_csv.
 */
function quiz_get_csv_file_for_year(string $year, ?string $quizDataDir = null): ?string
{
    $quizDataDir = $quizDataDir ?: (__DIR__ . '/../quiz_data');
    $yearNormalized = quiz_normalize_year($year);
    if ($yearNormalized === '') {
        return null;
    }
    $fileMap = [
        '1' => 'Consolidated_1st_Year_Modules_Final_v3.csv',
        '2' => 'Consolidated_2nd_Year_Modules_Final.csv',
        '3' => 'Consolidated_3rd_Year_Modules.csv',
        '4' => 'Consolidated_4th_Year_Modules_V2.csv',
    ];
    if (isset($fileMap[$yearNormalized])) {
        $filePath = $quizDataDir . '/' . $fileMap[$yearNormalized];
        if (file_exists($filePath)) {
            return $filePath;
        }
    }
    $files = glob($quizDataDir . '/*' . $yearNormalized . '*.csv');
    return $files ? $files[0] : null;
}

/**
 * Reads MCQ rows from a strictly resolved CSV path.
 * Every row must: (1) normalize to the same academic year as $academicYearForRows,
 * (2) have module slug equal to enrolled session title slug (no fuzzy match).
 *
 * @param array{path:string,match:string,year_dir:string,slug:string}|null $resolved
 * @return list<array{module:string,question:string,option_a:string,option_b:string,option_c:string,option_d:string,correct_answer:string}>
 */
function quiz_read_question_rows_from_resolved(?array $resolved, string $academicYearForRows, string $sessionTitle): array
{
    $out = [];
    if ($resolved === null || empty($resolved['path'])) {
        return $out;
    }

    $path = $resolved['path'];
    $yearNorm = quiz_normalize_year($academicYearForRows);
    if ($yearNorm === '') {
        error_log('Quiz CSV read: invalid academic year for row filter: ' . $academicYearForRows);
        return $out;
    }

    $expectedModuleSlug = quiz_module_slug_from_title($sessionTitle);

    $fh = fopen($path, 'r');
    if ($fh === false) {
        error_log('Quiz CSV read: cannot open ' . $path);
        return $out;
    }

    $header = fgetcsv($fh);
    if ($header === false) {
        fclose($fh);
        error_log('Quiz CSV read: empty file ' . $path);
        return $out;
    }

    $totalRows = 0;
    $skippedInvalid = 0;
    $skippedYear = 0;
    $skippedModule = 0;
    $included = 0;

    while (($row = fgetcsv($fh)) !== false) {
        $totalRows++;
        if (count($row) < 8) {
            $skippedInvalid++;
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

        if (strtolower($yearRaw) === 'year' && strtolower($module) === 'module') {
            continue;
        }

        if ($question === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($ans, ['A', 'B', 'C', 'D'], true)) {
            $skippedInvalid++;
            continue;
        }

        $rowYear = quiz_normalize_year($yearRaw);
        $yearOk = ($rowYear !== '' && $rowYear === $yearNorm);
        if (!$yearOk && $yearNorm === 'Graduate') {
            $yearOk = ($rowYear === '4' || stripos($yearRaw, 'graduate') !== false);
        }
        if (!$yearOk) {
            $skippedYear++;
            continue;
        }

        $rowModuleSlug = quiz_module_slug_from_title($module);
        if ($rowModuleSlug !== $expectedModuleSlug) {
            $skippedModule++;
            continue;
        }

        $out[] = [
            'module' => $module,
            'question' => $question,
            'option_a' => $a,
            'option_b' => $b,
            'option_c' => $c,
            'option_d' => $d,
            'correct_answer' => $ans,
        ];
        $included++;
    }

    fclose($fh);
    error_log("Quiz CSV STRICT read: path=" . $path . " totalLines=" . $totalRows . " included=" . $included . " skippedInvalid=" . $skippedInvalid . " skippedWrongYear=" . $skippedYear . " skippedWrongModuleSlug=" . $skippedModule . " expectedModuleSlug=" . $expectedModuleSlug);

    return $out;
}

/**
 * Loads questions for one target session from the strictly matched CSV file.
 *
 * @param string      $sessionYearForDb        Stored on quiz_questions.year (session catalog year from DB).
 * @param string|null $academicYearForQuizFiles Folder Year1–4 + CSV row year filter; defaults to $sessionYearForDb.
 */
function quiz_load_questions_for_session(mysqli $conn, int $sessionId, string $sessionYearForDb, string $sessionTitle, ?string $quizDataDir = null, ?string $academicYearForQuizFiles = null): int
{
    if ($sessionId <= 0 || $sessionYearForDb === '' || $sessionTitle === '') {
        error_log("Quiz loader: Invalid parameters - session_id: $sessionId, year: $sessionYearForDb, title: $sessionTitle");
        return 0;
    }

    $quizDataDir = $quizDataDir ?: (__DIR__ . '/../quiz_data');
    if (!is_dir($quizDataDir)) {
        error_log("Quiz loader: Quiz data directory not found: $quizDataDir");
        return 0;
    }

    $fileYear = $academicYearForQuizFiles !== null && $academicYearForQuizFiles !== '' ? $academicYearForQuizFiles : $sessionYearForDb;

    error_log('Quiz loader: DB session year=' . $sessionYearForDb . ' | CSV folder/row year=' . $fileYear . ' | module=' . $sessionTitle);

    $resolved = quiz_resolve_module_csv($fileYear, $sessionTitle, $quizDataDir);
    $rows = quiz_read_question_rows_from_resolved($resolved, $fileYear, $sessionTitle);

    if (empty($rows)) {
        error_log('Quiz loader: no rows after strict resolve/read — not loading legacy consolidated CSV.');
        return 0;
    }

    $checkStmt = $conn->prepare('SELECT id FROM quiz_questions WHERE session_id = ? AND question = ? LIMIT 1');
    $insertStmt = $conn->prepare('INSERT INTO quiz_questions
        (session_id, year, module, question, option_a, option_b, option_c, option_d, correct_answer, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');

    if (!$checkStmt || !$insertStmt) {
        error_log('Quiz loader: Failed to prepare database statements');
        return 0;
    }

    $inserted = 0;
    foreach ($rows as $r) {
        $question = $r['question'];
        $checkStmt->bind_param('is', $sessionId, $question);
        $checkStmt->execute();
        $existsResult = $checkStmt->get_result();
        if ($existsResult && $existsResult->num_rows > 0) {
            continue;
        }

        $module = $r['module'];
        $insertStmt->bind_param(
            'issssssss',
            $sessionId,
            $sessionYearForDb,
            $module,
            $question,
            $r['option_a'],
            $r['option_b'],
            $r['option_c'],
            $r['option_d'],
            $r['correct_answer']
        );
        if ($insertStmt->execute()) {
            $inserted++;
        } else {
            error_log('Quiz loader: Failed to insert question: ' . $insertStmt->error);
        }
    }

    $checkStmt->close();
    $insertStmt->close();

    error_log("Quiz loader: strict mode inserted $inserted row(s) for session '$sessionTitle'");

    return $inserted;
}

/**
 * Legacy consolidated CSV import (single large file per year).
 */
function quiz_load_questions_for_session_legacy_file(mysqli $conn, int $sessionId, string $sessionYear, string $sessionTitle, string $csvFile): int
{
    $yearNormalized = quiz_normalize_year($sessionYear);
    $inserted = 0;

    $checkStmt = $conn->prepare('SELECT id FROM quiz_questions WHERE session_id = ? AND question = ? LIMIT 1');
    $insertStmt = $conn->prepare('INSERT INTO quiz_questions
        (session_id, year, module, question, option_a, option_b, option_c, option_d, correct_answer, is_active)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');

    if (!$checkStmt || !$insertStmt) {
        return 0;
    }

    $fh = fopen($csvFile, 'r');
    if ($fh === false) {
        return 0;
    }
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        return 0;
    }

    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 8) {
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

        if (strtolower($yearRaw) === 'year' && strtolower($module) === 'module') {
            continue;
        }
        if ($question === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($ans, ['A', 'B', 'C', 'D'], true)) {
            continue;
        }
        $rowYear = quiz_normalize_year($yearRaw);
        if ($rowYear !== $yearNormalized) {
            continue;
        }
        if (!quiz_module_matches_session($module, $sessionTitle)) {
            continue;
        }

        $checkStmt->bind_param('is', $sessionId, $question);
        $checkStmt->execute();
        $existsResult = $checkStmt->get_result();
        if ($existsResult && $existsResult->num_rows > 0) {
            continue;
        }
        $insertStmt->bind_param('issssssss', $sessionId, $sessionYear, $module, $question, $a, $b, $c, $d, $ans);
        if ($insertStmt->execute()) {
            $inserted++;
        }
    }
    fclose($fh);
    $checkStmt->close();
    $insertStmt->close();

    return $inserted;
}

/**
 * Loads questions directly from CSV (no DB). Strict same rules as DB sync.
 *
 * @return list<array{question:string,option_a:string,option_b:string,option_c:string,option_d:string,correct_answer:string,module:string}>
 */
function quiz_load_questions_direct_from_csv(string $sessionYear, string $sessionTitle, ?string $quizDataDir = null, ?string $academicYearForQuizFiles = null): array
{
    $quizDataDir = $quizDataDir ?: (__DIR__ . '/../quiz_data');
    $fileYear = $academicYearForQuizFiles !== null && $academicYearForQuizFiles !== '' ? $academicYearForQuizFiles : $sessionYear;
    $resolved = quiz_resolve_module_csv($fileYear, $sessionTitle, $quizDataDir);
    $rows = quiz_read_question_rows_from_resolved($resolved, $fileYear, $sessionTitle);
    $questions = [];
    foreach ($rows as $r) {
        $questions[] = [
            'question' => $r['question'],
            'option_a' => $r['option_a'],
            'option_b' => $r['option_b'],
            'option_c' => $r['option_c'],
            'option_d' => $r['option_d'],
            'correct_answer' => $r['correct_answer'],
            'module' => $r['module'],
        ];
    }

    return $questions;
}

/**
 * @return list<array{question:string,option_a:string,option_b:string,option_c:string,option_d:string,correct_answer:string,module:string}>
 */
function quiz_load_questions_direct_from_legacy_csv(string $sessionYear, string $sessionTitle, string $csvFile): array
{
    $questions = [];
    $yearNormalized = quiz_normalize_year($sessionYear);
    $fh = fopen($csvFile, 'r');
    if ($fh === false) {
        return $questions;
    }
    $header = fgetcsv($fh);
    if (!$header) {
        fclose($fh);
        return $questions;
    }
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) < 8) {
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
        if (strtolower($yearRaw) === 'year' && strtolower($module) === 'module') {
            continue;
        }
        if ($question === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($ans, ['A', 'B', 'C', 'D'], true)) {
            continue;
        }
        $rowYear = quiz_normalize_year($yearRaw);
        if ($rowYear !== $yearNormalized) {
            continue;
        }
        if (!quiz_module_matches_session($module, $sessionTitle)) {
            continue;
        }
        $questions[] = [
            'question' => $question,
            'option_a' => $a,
            'option_b' => $b,
            'option_c' => $c,
            'option_d' => $d,
            'correct_answer' => $ans,
            'module' => $module,
        ];
    }
    fclose($fh);

    return $questions;
}

/**
 * Returns whether a quiz CSV exists and yields at least one valid question row.
 * Use $academicYearForQuizFiles when the CSV folder should follow student academic year
 * while $sessionYearForDbMatch is only used for row year column validation.
 */
function quiz_module_quiz_is_available(string $sessionYearForDbMatch, string $sessionTitle, ?string $quizDataDir = null, ?string $academicYearForQuizFiles = null): bool
{
    $fileYear = $academicYearForQuizFiles !== null && $academicYearForQuizFiles !== '' ? $academicYearForQuizFiles : $sessionYearForDbMatch;
    $rows = quiz_read_question_rows_from_resolved(
        quiz_resolve_module_csv($fileYear, $sessionTitle, $quizDataDir),
        $fileYear,
        $sessionTitle
    );

    return $rows !== [];
}

/**
 * Debug function to list all available modules in CSV files (Year1–Year4 folders + legacy root).
 */
function quiz_debug_list_modules(?string $quizDataDir = null): array
{
    $modules = [];
    $quizDataDir = $quizDataDir ?: (__DIR__ . '/../quiz_data');

    if (!is_dir($quizDataDir)) {
        return $modules;
    }

    $collect = function (string $file) use (&$modules): void {
        $fh = fopen($file, 'r');
        if ($fh === false) {
            return;
        }
        $header = fgetcsv($fh);
        if (!$header) {
            fclose($fh);
            return;
        }
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 2) {
                continue;
            }
            $yearRaw = trim((string)$row[0]);
            $module = trim((string)$row[1]);
            if (strtolower($yearRaw) === 'year' && strtolower($module) === 'module') {
                continue;
            }
            $year = quiz_normalize_year($yearRaw);
            if ($year === '') {
                continue;
            }
            if (!isset($modules[$year])) {
                $modules[$year] = [];
            }
            foreach (quiz_split_merged_modules($module) as $mod) {
                $cleanMod = trim($mod);
                if ($cleanMod !== '' && !in_array($cleanMod, $modules[$year], true)) {
                    $modules[$year][] = $cleanMod;
                }
            }
        }
        fclose($fh);
    };

    foreach (['Year1', 'Year2', 'Year3', 'Year4'] as $yd) {
        $dir = $quizDataDir . DIRECTORY_SEPARATOR . $yd;
        foreach (quiz_list_csv_in_dir($dir) as $fp) {
            $collect($fp);
        }
    }

    foreach (glob($quizDataDir . '/*.csv') ?: [] as $file) {
        $collect($file);
    }

    return $modules;
}
