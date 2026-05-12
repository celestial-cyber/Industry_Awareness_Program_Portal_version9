<?php
/**
 * Module (session) quiz PDF/HTML report for admin.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/db.php';
}

$__fpdf_path = __DIR__ . '/fpdf_init.php';
if (is_readable($__fpdf_path)) {
    require_once $__fpdf_path;
}

class ModuleQuizPDFReport
{
    private $conn;
    private $attemptId;
    /** @var array|null */
    private $summary = null;
    /** @var array */
    private $details = [];

    public function __construct($conn, $attemptId)
    {
        $this->conn = $conn;
        $this->attemptId = max(0, $attemptId);
        $this->load();
    }

    private function load(): void
    {
        $sql = 'SELECT res.id AS result_id,
                       res.attempt_id,
                       res.student_id,
                       res.session_id,
                       res.total_questions,
                       res.correct_answers,
                       res.score,
                       res.percentage,
                       res.attempt_date,
                       qa.attempted_at,
                       st.full_name,
                       st.roll_number,
                       st.year AS student_academic_year,
                       st.email,
                       st.department,
                       s.year AS module_catalog_year,
                       s.session_code
                FROM quiz_results res
                INNER JOIN quiz_attempts qa ON qa.id = res.attempt_id
                INNER JOIN iap_students st ON st.id = res.student_id
                INNER JOIN iap_sessions s ON s.id = res.session_id
                WHERE res.attempt_id = ? LIMIT 1';

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Database error');
        }
        $stmt->bind_param('i', $this->attemptId);
        $stmt->execute();
        $res = $stmt->get_result();
        $this->summary = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$this->summary) {
            throw new RuntimeException('Attempt not found');
        }

        $titleCol = 'title';
        $tc = $this->conn->query("SHOW COLUMNS FROM iap_sessions LIKE 'title'");
        if (!$tc || $tc->num_rows === 0) {
            $titleCol = 'topic';
        }
        $sid = (int)$this->summary['session_id'];
        $q2 = $this->conn->prepare("SELECT session_code, year, `{$titleCol}` AS mod_title FROM iap_sessions WHERE id = ? LIMIT 1");
        if ($q2) {
            $q2->bind_param('i', $sid);
            $q2->execute();
            $r2 = $q2->get_result();
            if ($r2 && ($row2 = $r2->fetch_assoc())) {
                $code = trim((string)($row2['session_code'] ?? ''));
                $mt = trim((string)($row2['mod_title'] ?? ''));
                $this->summary['module_display'] = ($code !== '' ? $code . ' — ' : '') . $mt;
                $this->summary['module_catalog_year'] = $row2['year'];
            } else {
                $this->summary['module_display'] = 'Module #' . $sid;
            }
            $q2->close();
        } else {
            $this->summary['module_display'] = 'Module #' . $sid;
        }

        $sqlA = 'SELECT ans.selected_answer, ans.is_correct,
                        q.question, q.option_a, q.option_b, q.option_c, q.option_d, q.correct_answer, q.module
                 FROM quiz_answers ans
                 INNER JOIN quiz_questions q ON q.id = ans.question_id
                 WHERE ans.attempt_id = ?
                 ORDER BY ans.id ASC';
        $st2 = $this->conn->prepare($sqlA);
        if ($st2) {
            $st2->bind_param('i', $this->attemptId);
            $st2->execute();
            $r2 = $st2->get_result();
            if ($r2) {
                while ($row = $r2->fetch_assoc()) {
                    $this->details[] = $row;
                }
            }
            $st2->close();
        }
    }

    public function get_summary(): array
    {
        return $this->summary ?? [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function get_question_rows(): array
    {
        return $this->details;
    }

    public function get_weak_topics(): string
    {
        $wrongByTopic = [];
        foreach ($this->details as $d) {
            if ((int)($d['is_correct'] ?? 0) !== 1) {
                $t = trim((string)($d['module'] ?? 'General'));
                $wrongByTopic[$t] = ($wrongByTopic[$t] ?? 0) + 1;
            }
        }
        if ($wrongByTopic === []) {
            return 'No weak topics detected — strong performance across recorded items.';
        }
        arsort($wrongByTopic);
        $parts = [];
        foreach ($wrongByTopic as $t => $c) {
            $parts[] = $t . ' (' . $c . ' missed)';
        }

        return implode('; ', array_slice($parts, 0, 8));
    }

    public function get_filename(): string
    {
        $s = $this->summary ?? [];
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string)($s['full_name'] ?? 'student'));

        return 'Module_Quiz_' . $name . '_' . date('Y-m-d_His', strtotime((string)($s['attempt_date'] ?? 'now')));
    }

    public function generate_html(bool $adminToolbar = false): string
    {
        $d = $this->summary;
        $rows = $this->details;
        $total = (int)($d['total_questions'] ?? 0);
        $correct = (int)($d['correct_answers'] ?? 0);
        $wrong = max(0, $total - $correct);
        $pct = (float)($d['percentage'] ?? 0);
        $weak = htmlspecialchars($this->get_weak_topics(), ENT_QUOTES, 'UTF-8');
        $module = htmlspecialchars((string)($d['module_display'] ?? ''), ENT_QUOTES, 'UTF-8');
        $when = htmlspecialchars(date('F j, Y g:i A', strtotime((string)($d['attempt_date'] ?? 'now'))), ENT_QUOTES, 'UTF-8');

        $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Module Quiz Report</title>
        <style>
        body{font-family:Segoe UI,Arial,sans-serif;margin:24px;color:#1e293b;line-height:1.5;}
        .hdr{text-align:center;border-bottom:3px solid #7c3aed;padding-bottom:16px;margin-bottom:24px;}
        .hdr h1{margin:0;color:#5b21b6;font-size:22px;}
        .box{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px;margin-bottom:16px;}
        table.info{width:100%;border-collapse:collapse;}
        table.info td{padding:8px;border-bottom:1px solid #e5e7eb;}
        table.info td:first-child{font-weight:600;width:32%;color:#5b21b6;}
        .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin:16px 0;}
        .cell{background:linear-gradient(135deg,#7c3aed,#5b21b6);color:#fff;border-radius:10px;padding:14px;text-align:center;}
        .cell.alt{background:linear-gradient(135deg,#0ea5e9,#0369a1);}
        .cell.ok{background:linear-gradient(135deg,#16a34a,#15803d);}
        .cell.bad{background:linear-gradient(135deg,#dc2626,#991b1b);}
        .q{border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:12px;page-break-inside:avoid;}
        .q.ok{border-left:4px solid #16a34a;}
        .q.bad{border-left:4px solid #dc2626;}
        .admin-bar{background:#0f172a;color:#e2e8f0;padding:12px 16px;margin:-24px -24px 20px -24px;}
        .admin-bar a{color:#fff;font-weight:600;}
        </style></head><body>';
        if ($adminToolbar) {
            $html .= '<div class="admin-bar"><a href="?page=module_quiz_performance">&larr; Back to Module Quiz Performance</a></div>';
        }
        $html .= '<div class="hdr"><h1>IAP Portal — Module Quiz Performance Report</h1><p>Industry Awareness Program</p></div>';
        $html .= '<div class="box"><table class="info">';
        $html .= '<tr><td>Student</td><td>' . htmlspecialchars((string)$d['full_name'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $html .= '<tr><td>Roll Number</td><td>' . htmlspecialchars((string)$d['roll_number'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $html .= '<tr><td>Academic Year (student)</td><td>' . htmlspecialchars((string)$d['student_academic_year'], ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $html .= '<tr><td>Module</td><td>' . $module . '</td></tr>';
        $html .= '<tr><td>Module catalog year</td><td>' . htmlspecialchars((string)($d['module_catalog_year'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td></tr>';
        $html .= '<tr><td>Attempt date</td><td>' . $when . '</td></tr>';
        $html .= '</table></div>';

        $html .= '<div class="grid">';
        $html .= '<div class="cell"><div style="font-size:12px;opacity:.9">Total</div><div style="font-size:26px;font-weight:700">' . $total . '</div></div>';
        $html .= '<div class="cell ok"><div style="font-size:12px;opacity:.9">Correct</div><div style="font-size:26px;font-weight:700">' . $correct . '</div></div>';
        $html .= '<div class="cell bad"><div style="font-size:12px;opacity:.9">Wrong / missed</div><div style="font-size:26px;font-weight:700">' . $wrong . '</div></div>';
        $html .= '<div class="cell alt"><div style="font-size:12px;opacity:.9">Percentage</div><div style="font-size:26px;font-weight:700">' . htmlspecialchars(number_format($pct, 2), ENT_QUOTES, 'UTF-8') . '%</div></div>';
        $html .= '</div>';

        $html .= '<div class="box"><strong>Performance insights</strong><p>' . $weak . '</p></div>';

        $html .= '<h2 style="color:#5b21b6;">Question-wise analysis</h2>';
        $i = 0;
        foreach ($rows as $r) {
            $i++;
            $ok = (int)($r['is_correct'] ?? 0) === 1;
            $sel = strtoupper((string)($r['selected_answer'] ?? ''));
            $selDisp = in_array($sel, ['A', 'B', 'C', 'D'], true) ? $sel : '—';
            $corr = strtoupper((string)($r['correct_answer'] ?? ''));
            $cls = $ok ? 'ok' : 'bad';
            $html .= '<div class="q ' . $cls . '"><strong>Q' . $i . '</strong> <span style="color:#64748b;">(' . htmlspecialchars((string)($r['module'] ?? ''), ENT_QUOTES, 'UTF-8') . ')</span>';
            $html .= '<p>' . htmlspecialchars((string)$r['question'], ENT_QUOTES, 'UTF-8') . '</p>';
            $html .= '<p><small>Selected: <strong>' . htmlspecialchars($selDisp, ENT_QUOTES, 'UTF-8') . '</strong> &nbsp;|&nbsp; Correct: <strong>' . htmlspecialchars($corr, ENT_QUOTES, 'UTF-8') . '</strong> &nbsp;|&nbsp; ' . ($ok ? 'Correct' : 'Incorrect / unanswered') . '</small></p>';
            $html .= '</div>';
        }

        $html .= '<p style="margin-top:32px;color:#94a3b8;font-size:12px;text-align:center;">Generated by IAP Portal — Module Quiz System</p>';
        $html .= '</body></html>';

        return $html;
    }
}

function module_quiz_report_render_pdf(ModuleQuizPDFReport $report): void
{
    $base = $report->get_filename();
    $ascii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
    if ($ascii === '' || $ascii === '_') {
        $ascii = 'Module_Quiz_Report';
    }
    $sum = $report->get_summary();
    $rows = $report->get_question_rows();

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

    if (class_exists('TCPDF')) {
        $pdf = new TCPDF();
        $pdf->SetCreator('IAP Portal');
        $pdf->SetTitle('Module Quiz Report');
        $pdf->SetMargins(12, 12, 12);
        $pdf->AddPage();
        $pdf->writeHTML($report->generate_html(false), true, false, true, false, '');
        $pdf->Output($ascii . '.pdf', 'D');
        exit;
    }

    if (class_exists('FPDF') && function_exists('iap_fpdf_runtime_ready') && iap_fpdf_runtime_ready()) {
        $pdf = new FPDF();
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->AddPage();
        $pdf->SetFont('Arial', 'B', 15);
        $pdf->Cell(0, 9, $latin('IAP Portal — Module Quiz Report'), 0, 1, 'C');
        $pdf->SetFont('Arial', '', 10);
        $pdf->Cell(0, 6, $latin('Generated ' . date('Y-m-d H:i')), 0, 1, 'C');
        $pdf->Ln(3);

        $lines = [
            ['Student', (string)($sum['full_name'] ?? '')],
            ['Roll', (string)($sum['roll_number'] ?? '')],
            ['Student year', (string)($sum['student_academic_year'] ?? '')],
            ['Module', (string)($sum['module_display'] ?? '')],
            ['Catalog year', (string)($sum['module_catalog_year'] ?? '')],
            ['Score', (string)(($sum['score'] ?? 0) . '/' . ($sum['total_questions'] ?? 0))],
            ['Percentage', number_format((float)($sum['percentage'] ?? 0), 2) . '%'],
            ['Attempt', date('Y-m-d H:i', strtotime((string)($sum['attempt_date'] ?? 'now')))],
            ['Weak topics', $report->get_weak_topics()],
        ];
        foreach ($lines as $row) {
            $k = $row[0];
            $v = $row[1];
            $pdf->SetFont('Arial', 'B', 10);
            $pdf->Cell(52, 7, $latin($k . ':'), 0, 0);
            $pdf->SetFont('Arial', '', 10);
            $pdf->MultiCell(0, 7, $latin($v), 0, 'L');
        }
        $pdf->Ln(2);
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->Cell(0, 8, $latin('Question breakdown'), 0, 1);
        $pdf->SetFont('Arial', '', 9);
        $n = 0;
        foreach ($rows as $r) {
            $n++;
            $sel = strtoupper((string)($r['selected_answer'] ?? ''));
            $sel = in_array($sel, ['A', 'B', 'C', 'D'], true) ? $sel : '—';
            $corr = strtoupper((string)($r['correct_answer'] ?? ''));
            $st = (int)($r['is_correct'] ?? 0) === 1 ? 'OK' : 'Wrong';
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->MultiCell(0, 5, $latin('Q' . $n . ': ' . (string)($r['question'] ?? '')));
            $pdf->SetFont('Arial', '', 9);
            $pdf->MultiCell(0, 5, $latin('Selected ' . $sel . ' | Correct ' . $corr . ' | ' . $st));
            $pdf->Ln(1);
        }
        $pdf->Output($ascii . '.pdf', 'D');
        exit;
    }

    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $ascii . '.html"');
    echo $report->generate_html(false);
    exit;
}

if (isset($_GET['download_module_quiz_report'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (($_SESSION['role'] ?? '') !== 'admin') {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Access denied.';
        exit;
    }
    $aid = (int)($_GET['attempt_id'] ?? 0);
    if ($aid <= 0) {
        http_response_code(400);
        echo 'Invalid attempt.';
        exit;
    }
    try {
        $rep = new ModuleQuizPDFReport($conn, $aid);
        module_quiz_report_render_pdf($rep);
    } catch (Throwable $e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Error: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        exit;
    }
}
