<?php
/**
 * English Module Quiz System with Difficulty Levels
 * Loads questions from English quiz CSV files based on difficulty
 */

function english_normalize_difficulty(string $difficulty): string
{
    $difficulty = strtolower(trim($difficulty));
    $validDifficulties = ['easy', 'medium', 'hard'];
    
    return in_array($difficulty, $validDifficulties) ? $difficulty : 'easy';
}

function english_get_csv_file_for_difficulty(string $difficulty, ?string $quizDataDir = null): ?string
{
    $quizDataDir = $quizDataDir ?: (__DIR__ . '/../quiz_data/English_Module');
    $difficulty = english_normalize_difficulty($difficulty);
    
    if (!is_dir($quizDataDir)) {
        error_log("English Quiz: Quiz data directory not found: $quizDataDir");
        return null;
    }
    
    // Map difficulties to CSV files
    $fileMap = [
        'easy' => 'easy.csv',
        'medium' => 'medium.csv', 
        'hard' => 'hard.csv'
    ];
    
    if (isset($fileMap[$difficulty])) {
        $filePath = $quizDataDir . '/' . $fileMap[$difficulty];
        if (file_exists($filePath)) {
            return $filePath;
        }
    }
    
    error_log("English Quiz: No CSV file found for difficulty: $difficulty");
    return null;
}

function english_load_questions_from_csv(string $difficulty, ?string $quizDataDir = null): array
{
    $questions = [];
    $difficulty = english_normalize_difficulty($difficulty);
    
    $csvFile = english_get_csv_file_for_difficulty($difficulty, $quizDataDir);
    if (!$csvFile) {
        error_log("English Quiz: CSV file not found for difficulty: $difficulty");
        return $questions;
    }

    error_log("English Quiz: Loading questions for difficulty '$difficulty' from file: " . basename($csvFile));

    $fh = fopen($csvFile, 'r');
    if ($fh === false) {
        error_log("English Quiz: Failed to open CSV file: $csvFile");
        return $questions;
    }

    $header = fgetcsv($fh);
    if (!$header) {
        error_log("English Quiz: Failed to read CSV header from: $csvFile");
        fclose($fh);
        return $questions;
    }

    $totalRows = 0;
    $validRows = 0;
    $skippedRows = 0;

    while (($row = fgetcsv($fh)) !== false) {
        $totalRows++;
        
        if (count($row) < 7) {
            $skippedRows++;
            continue;
        }
        
        $topic = trim((string)$row[0]);
        $question = trim((string)$row[1]);
        $a = trim((string)$row[2]);
        $b = trim((string)$row[3]);
        $c = trim((string)$row[4]);
        $d = trim((string)$row[5]);
        $ans = strtoupper(trim((string)$row[6]));
        $difficulty_field = isset($row[7]) ? trim((string)$row[7]) : $difficulty;

        if ($question === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($ans, ['A', 'B', 'C', 'D'], true)) {
            $skippedRows++;
            continue;
        }

        // Skip header row
        if (strtolower($topic) === 'topic' && strtolower($question) === 'question') {
            continue;
        }

        $validRows++;
        
        $questions[] = [
            'topic' => $topic,
            'question' => $question,
            'option_a' => $a,
            'option_b' => $b,
            'option_c' => $c,
            'option_d' => $d,
            'correct_answer' => $ans,
            'difficulty' => $difficulty_field
        ];
    }

    fclose($fh);
    
    error_log("English Quiz: Difficulty '$difficulty' - Total rows: $totalRows, Valid: $validRows, Skipped: $skippedRows, Questions loaded: " . count($questions));
    
    return $questions;
}

function english_load_questions_for_topic(string $difficulty, string $topic, ?string $quizDataDir = null): array
{
    $allQuestions = english_load_questions_from_csv($difficulty, $quizDataDir);
    $topicQuestions = [];
    
    foreach ($allQuestions as $question) {
        if (strcasecmp($question['topic'], $topic) === 0) {
            $topicQuestions[] = $question;
        }
    }
    
    error_log("English Quiz: Topic '$topic' in difficulty '$difficulty' - Found " . count($topicQuestions) . " questions");
    
    return $topicQuestions;
}

function english_get_available_topics(?string $quizDataDir = null): array
{
    $topics = [];
    $quizDataDir = $quizDataDir ?: (__DIR__ . '/../quiz_data/English_Module');
    
    if (!is_dir($quizDataDir)) {
        return $topics;
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
        
        while (($row = fgetcsv($fh)) !== false) {
            if (count($row) < 2) continue;
            
            $topic = trim((string)$row[0]);
            $question = trim((string)$row[1]);
            
            if (strtolower($topic) === 'topic' && strtolower($question) === 'question') {
                continue;
            }
            
            if ($topic !== '' && !in_array($topic, $topics)) {
                $topics[] = $topic;
            }
        }
        
        fclose($fh);
    }
    
    return $topics;
}

function english_get_quiz_statistics(?string $quizDataDir = null): array
{
    $stats = [];
    $difficulties = ['easy', 'medium', 'hard'];
    
    foreach ($difficulties as $difficulty) {
        $questions = english_load_questions_from_csv($difficulty, $quizDataDir);
        $topics = [];
        
        foreach ($questions as $question) {
            $topic = $question['topic'];
            if (!isset($topics[$topic])) {
                $topics[$topic] = 0;
            }
            $topics[$topic]++;
        }
        
        $stats[$difficulty] = [
            'total_questions' => count($questions),
            'topics' => $topics,
            'topics_count' => count($topics)
        ];
    }
    
    return $stats;
}
?>
