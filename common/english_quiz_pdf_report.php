<?php
/**
 * English Quiz PDF Report Generator
 * Generates individual student quiz performance reports in PDF format
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/db.php';
}

$__fpdf_path = __DIR__ . '/fpdf_init.php';
if (is_readable($__fpdf_path)) {
    require_once $__fpdf_path;
}

class EnglishQuizPDFReport {
    private $conn;
    private $attempt_id;
    private $attempt_data;
    private $answers_data;
    
    public function __construct($conn, $attempt_id) {
        $this->conn = $conn;
        $this->attempt_id = (int)$attempt_id;
        $this->load_attempt_data();
    }
    
    private function load_attempt_data() {
        // Get attempt and result data
        $sql = "SELECT 
                    eqr.id,
                    eqr.attempt_id,
                    eqr.student_id,
                    eqr.difficulty,
                    eqr.total_questions,
                    eqr.correct_answers,
                    eqr.score,
                    eqr.percentage,
                    eqr.topics_covered,
                    eqr.attempt_date,
                    st.full_name,
                    st.roll_number,
                    st.email,
                    st.department,
                    st.year
                FROM english_quiz_results eqr
                INNER JOIN iap_students st ON st.id = eqr.student_id
                WHERE eqr.attempt_id = ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new Exception('Database error loading quiz result');
        }
        $stmt->bind_param("i", $this->attempt_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $this->attempt_data = $result->fetch_assoc();
        $stmt->close();
        
        if (!$this->attempt_data) {
            throw new Exception("Attempt not found");
        }
        
        // Get answers data
        $sql = "SELECT 
                    question,
                    topic,
                    option_a,
                    option_b,
                    option_c,
                    option_d,
                    selected_answer,
                    correct_answer,
                    is_correct
                FROM english_quiz_answers
                WHERE attempt_id = ?
                ORDER BY id ASC";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            $this->answers_data = [];
            return;
        }
        $stmt->bind_param("i", $this->attempt_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $this->answers_data = $result->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    }

    /**
     * @return array{wrong:int,unanswered:int,wrong_answered:int}
     */
    public function get_answer_counts(): array
    {
        $wrong_answered = 0;
        $unanswered = 0;
        foreach ($this->answers_data as $row) {
            $sel = strtoupper((string)($row['selected_answer'] ?? ''));
            $valid = in_array($sel, ['A', 'B', 'C', 'D'], true);
            if (!$valid) {
                $unanswered++;
            } elseif ((int)($row['is_correct'] ?? 0) !== 1) {
                $wrong_answered++;
            }
        }
        return [
            'wrong' => $wrong_answered + $unanswered,
            'unanswered' => $unanswered,
            'wrong_answered' => $wrong_answered,
        ];
    }

    public static function pass_fail_status(float $percentage): string
    {
        return $percentage >= 40.0 ? 'PASS' : 'FAIL';
    }
    
    public function generate_html(bool $adminToolbar = false) {
        $data = $this->attempt_data;
        $answers = $this->answers_data;
        $counts = $this->get_answer_counts();
        $pct = (float)($data['percentage'] ?? 0);
        $status = self::pass_fail_status($pct);
        
        $html = '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>English Quiz Report - ' . htmlspecialchars($data['full_name']) . '</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            color: #333;
        }
        .header {
            text-align: center;
            border-bottom: 3px solid #667eea;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .header h1 {
            margin: 0;
            color: #667eea;
        }
        .header p {
            margin: 5px 0;
            color: #666;
        }
        .student-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
        }
        .student-info table {
            width: 100%;
            border-collapse: collapse;
        }
        .student-info td {
            padding: 8px;
            border-bottom: 1px solid #ddd;
        }
        .student-info td:first-child {
            font-weight: bold;
            width: 30%;
            color: #667eea;
        }
        .admin-report-toolbar {
            background: #1e293b;
            color: #f8fafc;
            padding: 12px 16px;
            margin: -20px -20px 20px -20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: space-between;
        }
        .admin-report-toolbar a {
            color: #fff;
            font-weight: 600;
            text-decoration: none;
        }
        .admin-report-toolbar a:hover { text-decoration: underline; }
        .score-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        .score-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        .score-box.correct {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        .score-box.wrong {
            background: linear-gradient(135deg, #dc3545 0%, #c82333 100%);
        }
        .score-box.percentage {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
        }
        .score-box.unanswered {
            background: linear-gradient(135deg, #64748b 0%, #475569 100%);
        }
        .score-box.status {
            background: linear-gradient(135deg, #0ea5e9 0%, #0369a1 100%);
        }
        .score-box h3 {
            margin: 0 0 10px 0;
            font-size: 14px;
            opacity: 0.9;
        }
        .score-box .value {
            font-size: 32px;
            font-weight: bold;
        }
        .questions-section {
            margin-top: 30px;
        }
        .questions-section h2 {
            color: #667eea;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        .question-item {
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
            page-break-inside: avoid;
        }
        .question-item.correct {
            border-left: 4px solid #28a745;
        }
        .question-item.wrong {
            border-left: 4px solid #dc3545;
        }
        .question-number {
            font-weight: bold;
            color: #667eea;
            margin-bottom: 8px;
        }
        .question-text {
            margin-bottom: 12px;
            font-weight: 500;
        }
        .topic-badge {
            display: inline-block;
            background: #e9ecef;
            color: #495057;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            margin-bottom: 10px;
        }
        .options {
            margin: 10px 0;
        }
        .option {
            padding: 8px;
            margin: 5px 0;
            border-radius: 3px;
            background: #f8f9fa;
        }
        .option.selected {
            background: #e7f3ff;
            border-left: 3px solid #0066cc;
            padding-left: 5px;
        }
        .option.correct {
            background: #d4edda;
            border-left: 3px solid #28a745;
            padding-left: 5px;
        }
        .option.wrong {
            background: #f8d7da;
            border-left: 3px solid #dc3545;
            padding-left: 5px;
        }
        .result-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
        }
        .result-badge.correct {
            background: #d4edda;
            color: #155724;
        }
        .result-badge.wrong {
            background: #f8d7da;
            color: #721c24;
        }
        .result-badge.unanswered {
            background: #e2e8f0;
            color: #334155;
        }
        .answer-line {
            margin: 10px 0 6px 0;
            padding: 10px;
            background: #f1f5f9;
            border-radius: 6px;
            font-size: 14px;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            color: #999;
            font-size: 12px;
        }
        @media print {
            body {
                margin: 0;
            }
            .page-break {
                page-break-after: always;
            }
        }
    </style>
</head>
<body>';
        
        if ($adminToolbar) {
            $html .= '<div class="admin-report-toolbar"><a href="?page=english_quiz_performance">&larr; Back to English Quiz Performance</a><span style="opacity:.85;font-size:13px;">Admin view &mdash; use browser Print to save as PDF if needed</span></div>';
        }
        
        // Header
        $html .= '<div class="header">
            <h1>English Quiz Performance Report</h1>
            <p>IAP Portal - Industry Awareness Program</p>
            <p>Generated on ' . date('F j, Y \a\t g:i A') . '</p>
        </div>';
        
        // Student Info
        $html .= '<div class="student-info">
            <table>
                <tr>
                    <td>Student Name:</td>
                    <td>' . htmlspecialchars($data['full_name']) . '</td>
                </tr>
                <tr>
                    <td>Roll Number:</td>
                    <td>' . htmlspecialchars($data['roll_number']) . '</td>
                </tr>
                <tr>
                    <td>Quiz Module / Topics:</td>
                    <td>' . htmlspecialchars(!empty($data['topics_covered']) ? (string)$data['topics_covered'] : 'General English') . '</td>
                </tr>
                <tr>
                    <td>Email:</td>
                    <td>' . htmlspecialchars($data['email']) . '</td>
                </tr>
                <tr>
                    <td>Department:</td>
                    <td>' . htmlspecialchars($data['department']) . '</td>
                </tr>
                <tr>
                    <td>Year:</td>
                    <td>' . htmlspecialchars($data['year']) . '</td>
                </tr>
                <tr>
                    <td>Difficulty Level:</td>
                    <td><strong>' . ucfirst(htmlspecialchars($data['difficulty'])) . '</strong></td>
                </tr>
                <tr>
                    <td>Attempt Date:</td>
                    <td>' . date('F j, Y \a\t g:i A', strtotime($data['attempt_date'])) . '</td>
                </tr>
            </table>
        </div>';
        
        // Score Summary (wrong = incorrect selections; unanswered tracked separately)
        $html .= '<div class="score-summary">
            <div class="score-box">
                <h3>Total Questions</h3>
                <div class="value">' . (int)$data['total_questions'] . '</div>
            </div>
            <div class="score-box correct">
                <h3>Correct Answers</h3>
                <div class="value">' . (int)$data['correct_answers'] . '</div>
            </div>
            <div class="score-box wrong">
                <h3>Wrong Answers</h3>
                <div class="value">' . (int)$counts['wrong_answered'] . '</div>
            </div>
            <div class="score-box unanswered">
                <h3>Unanswered</h3>
                <div class="value">' . (int)$counts['unanswered'] . '</div>
            </div>
            <div class="score-box percentage">
                <h3>Percentage Score</h3>
                <div class="value">' . number_format($pct, 2) . '%</div>
            </div>
            <div class="score-box status">
                <h3>Final Result</h3>
                <div class="value" style="font-size:22px;">' . htmlspecialchars($status) . '</div>
            </div>
        </div>';
        
        // Questions and Answers
        $html .= '<div class="questions-section">
            <h2>Detailed Question Review</h2>';
        
        foreach ($answers as $index => $answer) {
            $sel = strtoupper((string)($answer['selected_answer'] ?? ''));
            $hasAnswer = in_array($sel, ['A', 'B', 'C', 'D'], true);
            $is_correct = (int)($answer['is_correct'] ?? 0) === 1;
            $corr = strtoupper((string)($answer['correct_answer'] ?? ''));
            if ($is_correct) {
                $class = 'correct';
                $badge = '<span class="result-badge correct">Correct</span>';
            } elseif (!$hasAnswer) {
                $class = 'wrong';
                $badge = '<span class="result-badge unanswered">Unanswered</span>';
            } else {
                $class = 'wrong';
                $badge = '<span class="result-badge wrong">Wrong</span>';
            }
            $selDisp = $hasAnswer ? htmlspecialchars($sel) : '— (Unanswered)';
            $html .= '<div class="question-item ' . $class . '">
                <div class="question-number">Question ' . ($index + 1) . ' ' . $badge . '</div>
                <div class="topic-badge">' . htmlspecialchars($answer['topic']) . '</div>
                <div class="question-text">' . htmlspecialchars($answer['question']) . '</div>
                <div class="answer-line"><strong>Student selected:</strong> ' . $selDisp . '
                    &nbsp;|&nbsp; <strong>Correct answer:</strong> ' . htmlspecialchars($corr) . '
                    &nbsp;|&nbsp; <strong>Status:</strong> ' . ($is_correct ? 'Correct' : (!$hasAnswer ? 'Unanswered' : 'Wrong')) . '</div>
                <div class="options">
                    <div class="option' . ($sel === 'A' ? ' selected' : '') . ($corr === 'A' ? ' correct' : '') . '">
                        <strong>A.</strong> ' . htmlspecialchars($answer['option_a']) . '
                    </div>
                    <div class="option' . ($sel === 'B' ? ' selected' : '') . ($corr === 'B' ? ' correct' : '') . '">
                        <strong>B.</strong> ' . htmlspecialchars($answer['option_b']) . '
                    </div>
                    <div class="option' . ($sel === 'C' ? ' selected' : '') . ($corr === 'C' ? ' correct' : '') . '">
                        <strong>C.</strong> ' . htmlspecialchars($answer['option_c']) . '
                    </div>
                    <div class="option' . ($sel === 'D' ? ' selected' : '') . ($corr === 'D' ? ' correct' : '') . '">
                        <strong>D.</strong> ' . htmlspecialchars($answer['option_d']) . '
                    </div>
                </div>';
            
            if (!$is_correct) {
                $html .= '<div style="margin-top: 10px; padding: 10px; background: #fff3cd; border-radius: 3px;">
                    <strong>Correct answer (key):</strong> ' . htmlspecialchars($corr) . '
                </div>';
            }
            
            $html .= '</div>';
        }
        
        $html .= '</div>';
        
        // Footer
        $html .= '<div class="footer">
            <p>This is an official report generated by the IAP Portal system.</p>
            <p>For more information, please contact the administration.</p>
        </div>';
        
        $html .= '</body></html>';
        
        return $html;
    }
    
    public function get_filename() {
        $data = $this->attempt_data;
        $date = date('Y-m-d_H-i-s', strtotime($data['attempt_date']));
        return 'English_Quiz_Report_' . str_replace(' ', '_', $data['full_name']) . '_' . $data['difficulty'] . '_' . $date;
    }

    public function get_attempt_data() {
        return $this->attempt_data;
    }

    public function get_answers_data() {
        return $this->answers_data;
    }
}

function english_quiz_report_render_pdf(EnglishQuizPDFReport $report): void
{
    $base_name = $report->get_filename();
    $ascii_base = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base_name);
    if ($ascii_base === '' || $ascii_base === '_') {
        $ascii_base = 'English_Quiz_Report';
    }
    $attempt = $report->get_attempt_data();
    $answers = $report->get_answers_data();

    if (class_exists('\Mpdf\Mpdf')) {
        $mpdf = new \Mpdf\Mpdf(['tempDir' => sys_get_temp_dir()]);
        $mpdf->WriteHTML($report->generate_html(false));
        $mpdf->Output($ascii_base . '.pdf', 'D');
        exit;
    }

    if (class_exists('TCPDF')) {
        $pdf = new TCPDF();
        $pdf->SetCreator('IAP Portal');
        $pdf->SetAuthor('IAP Admin');
        $pdf->SetTitle('English Quiz Report');
        $pdf->SetMargins(12, 12, 12);
        $pdf->AddPage();
        $pdf->writeHTML($report->generate_html(false), true, false, true, false, '');
        $pdf->Output($ascii_base . '.pdf', 'D');
        exit;
    }

    if (class_exists('FPDF') && function_exists('iap_fpdf_runtime_ready') && iap_fpdf_runtime_ready()) {
        $counts = $report->get_answer_counts();
        $status = EnglishQuizPDFReport::pass_fail_status((float)($attempt['percentage'] ?? 0));

        $latin = static function (string $s): string {
            if ($s === '') {
                return '';
            }
            if (function_exists('iconv')) {
                $o = @iconv('UTF-8', 'windows-1252//TRANSLIT', $s);
                if ($o !== false && $o !== '') {
                    return $o;
                }
            }
            return preg_replace('/[^\x20-\x7E]/', '?', $s) ?? $s;
        };

        $pdf = new FPDF();
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 16);
        $pdf->Cell(0, 10, $latin('English Quiz Performance Report'), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, $latin('Generated on ' . date('F j, Y g:i A')), 0, 1, 'C');
        $pdf->Ln(4);

        $rows = [
            ['Student Name', $attempt['full_name'] ?? 'N/A'],
            ['Roll Number', $attempt['roll_number'] ?? 'N/A'],
            ['Quiz Module/Topic', $attempt['topics_covered'] ?: 'General English'],
            ['Difficulty Level', ucfirst((string)($attempt['difficulty'] ?? 'N/A'))],
            ['Attempt Date & Time', date('F j, Y g:i A', strtotime((string)($attempt['attempt_date'] ?? 'now')))],
            ['Total Questions', (string)((int)($attempt['total_questions'] ?? 0))],
            ['Correct Answers', (string)((int)($attempt['correct_answers'] ?? 0))],
            ['Wrong Answers (incorrect)', (string)(int)$counts['wrong_answered']],
            ['Unanswered Questions', (string)(int)$counts['unanswered']],
            ['Percentage Score', number_format((float)($attempt['percentage'] ?? 0), 2) . '%'],
            ['Final Result/Status', $status],
        ];
        $pdf->SetFont('Arial', '', 11);
        foreach ($rows as $row) {
            $k = $row[0];
            $v = $row[1];
            $pdf->SetFont('Arial', 'B', 11);
            $pdf->Cell(60, 8, $latin($k . ':'), 0, 0);
            $pdf->SetFont('Arial', '', 11);
            $pdf->MultiCell(0, 8, $latin((string)$v), 0, 'L');
        }

        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 13);
        $pdf->Cell(0, 9, $latin('Question-wise Breakdown'), 0, 1);
        $pdf->SetFont('Arial', '', 10);
        foreach ($answers as $i => $a) {
            $sel = strtoupper((string)($a['selected_answer'] ?? ''));
            $has = in_array($sel, ['A', 'B', 'C', 'D'], true);
            $selected = $has ? $sel : 'Unanswered';
            $correct = (string)($a['correct_answer'] ?? 'N/A');
            if ((int)($a['is_correct'] ?? 0) === 1) {
                $qStatus = 'Correct';
            } elseif (!$has) {
                $qStatus = 'Unanswered';
            } else {
                $qStatus = 'Wrong';
            }
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->MultiCell(0, 6, $latin('Q' . ($i + 1) . ': ' . (string)($a['question'] ?? 'N/A')));
            $pdf->SetFont('Arial', '', 10);
            $pdf->MultiCell(0, 6, $latin('Selected: ' . $selected . ' | Correct: ' . $correct . ' | Status: ' . $qStatus));
            $pdf->Ln(1);
        }
        $pdf->Output($ascii_base . '.pdf', 'D');
        exit;
    }

    $html = $report->generate_html(false);
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $ascii_base . '.html"');
    header('Cache-Control: private, max-age=0');
    echo $html;
    exit;
}

// Handle PDF download request (included from admin dashboard after auth)
if (isset($_GET['download_english_quiz_report'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Access denied.';
        exit;
    }

    $attempt_id = (int)($_GET['attempt_id'] ?? 0);

    if ($attempt_id > 0) {
        try {
            $report = new EnglishQuizPDFReport($conn, $attempt_id);
            english_quiz_report_render_pdf($report);
        } catch (Throwable $e) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'Error generating report: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            exit;
        }
    } else {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid attempt.';
        exit;
    }
}
?>
