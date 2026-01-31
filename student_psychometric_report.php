<?php
/**
 * Student Psychometric Report Page
 * Allows students to view their detailed psychometric assessment results and download PDF
 */

// Include session protection - must be at the top
require_once 'Student/student_session_check.php';

$message = '';
$report_data = null;
$student_id = $_SESSION['student_id'];

// Fetch student's psychometric data
$sql = "SELECT s.full_name, s.email, s.roll_number, s.department, s.year,
               ps.score, ps.trait_a, ps.trait_b, ps.trait_c, ps.trait_d, ps.completed_at
        FROM IAP_students s
        LEFT JOIN iap_psychometric_scores ps ON s.id = ps.student_id
        WHERE s.id = ?";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $student_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $report_data = $result->fetch_assoc();
    if (!$report_data['score']) {
        $message = "You haven't taken the psychometric assessment yet. Please complete the assessment first.";
        $report_data = null;
    }
} else {
    $message = "Unable to load your assessment data.";
}
$stmt->close();

// Handle PDF download
if (isset($_POST['download_pdf']) && $report_data) {
    // For now, create a simple HTML-to-PDF using basic HTML/CSS
    // In production, you'd use a proper PDF library like TCPDF or DomPDF

    $html_content = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Psychometric Assessment Report - ' . htmlspecialchars($report_data['full_name']) . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; }
            .header { text-align: center; border-bottom: 2px solid #007bff; padding-bottom: 20px; margin-bottom: 30px; }
            .student-info { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
            .score { text-align: center; font-size: 36px; font-weight: bold; color: #007bff; margin: 20px 0; }
            .traits { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin: 20px 0; }
            .trait { background: white; padding: 15px; border: 1px solid #dee2e6; border-radius: 8px; text-align: center; }
            .interpretation { background: #e7f3ff; padding: 20px; border-radius: 8px; border-left: 4px solid #007bff; }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>IAP Portal - Psychometric Assessment Report</h1>
            <p>Personality Trait Analysis</p>
        </div>

        <div class="student-info">
            <h3>Student Information</h3>
            <p><strong>Name:</strong> ' . htmlspecialchars($report_data['full_name']) . '</p>
            <p><strong>Email:</strong> ' . htmlspecialchars($report_data['email']) . '</p>
            <p><strong>Roll Number:</strong> ' . htmlspecialchars($report_data['roll_number']) . '</p>
            <p><strong>Department:</strong> ' . htmlspecialchars($report_data['department']) . '</p>
            <p><strong>Year:</strong> Year ' . htmlspecialchars($report_data['year']) . '</p>
            <p><strong>Assessment Date:</strong> ' . date('F j, Y \a\t g:i A', strtotime($report_data['completed_at'])) . '</p>
        </div>

        <h2>Assessment Results</h2>
        <div class="score">' . round($report_data['score'], 1) . '%</div>
        <p style="text-align: center;">Overall Psychometric Score</p>
        <p style="text-align: center; font-size: 14px; color: #666;">Based on Analytical and Organized traits (A+D) out of 20 questions</p>

        <h3>Trait Analysis</h3>
        <p>The psychometric assessment evaluates four personality traits:</p>
        <ul>
            <li><strong>A = Analytical:</strong> Problem-solving, logical thinking, data-driven approach</li>
            <li><strong>B = Creative:</strong> Innovation, artistic thinking, outside-the-box ideas</li>
            <li><strong>C = Empathetic:</strong> People-oriented, caring, relationship-focused</li>
            <li><strong>D = Organized:</strong> Structured, detail-oriented, systematic approach</li>
        </ul>

        <div class="traits">
            <div class="trait">
                <h4>Analytical (A)</h4>
                <div style="font-size: 24px; color: #28a745; font-weight: bold;">' . $report_data['trait_a'] . '/20</div>
            </div>
            <div class="trait">
                <h4>Creative (B)</h4>
                <div style="font-size: 24px; color: #ffc107; font-weight: bold;">' . $report_data['trait_b'] . '/20</div>
            </div>
            <div class="trait">
                <h4>Empathetic (C)</h4>
                <div style="font-size: 24px; color: #17a2b8; font-weight: bold;">' . $report_data['trait_c'] . '/20</div>
            </div>
            <div class="trait">
                <h4>Organized (D)</h4>
                <div style="font-size: 24px; color: #6f42c1; font-weight: bold;">' . $report_data['trait_d'] . '/20</div>
            </div>
        </div>

        <div class="interpretation">
            <h3>Score Interpretation</h3>';

    $score = round($report_data['score'], 1);
    if ($score >= 80) {
        $html_content .= '<p><strong>Excellent Performance!</strong> You show strong analytical and organizational skills. You are well-suited for roles requiring logical thinking, planning, and systematic approaches.</p>';
    } elseif ($score >= 60) {
        $html_content .= '<p><strong>Good Performance!</strong> You demonstrate solid analytical and organizational abilities. Consider roles that balance structured thinking with other skills.</p>';
    } elseif ($score >= 40) {
        $html_content .= '<p><strong>Moderate Performance.</strong> You have some analytical and organizational tendencies but may benefit from developing these skills further.</p>';
    } else {
        $html_content .= '<p><strong>Different Strengths.</strong> Your results suggest you may be more oriented towards creative or empathetic approaches rather than analytical/organizational ones.</p>';
    }

    $html_content .= '
        </div>

        <div style="margin-top: 40px; text-align: center; font-size: 12px; color: #666;">
            <p>Generated by IAP Portal on ' . date('F j, Y \a\t g:i A') . '</p>
        </div>
    </body>
    </html>';

    // Set headers for file download
    header('Content-Type: text/html');
    header('Content-Disposition: attachment; filename="psychometric_report_' . $report_data['roll_number'] . '_' . date('Y-m-d') . '.html"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo $html_content;
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Psychometric Report - IAP Portal</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Theme CSS -->
    <link rel="stylesheet" href="theme.css">
    <style>
        :root {
            --primary-color: #7c3aed;
            --primary-light: #f3e8ff;
            --primary-dark: #5b21b6;
            --text-primary: #1e293b;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }

        body {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-primary);
            line-height: 1.6;
            margin: 0;
            padding: 0;
        }

        .report-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 20px;
        }

        .report-header {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            color: var(--text-primary);
            padding: 32px 24px;
            border-radius: 16px;
            margin-bottom: 32px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            text-align: center;
        }

        .report-header h1 {
            font-size: 28px;
            margin-bottom: 8px;
            font-weight: 700;
            color: var(--primary-color);
        }

        .student-info {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            margin-bottom: 24px;
            border: 1px solid var(--border-color);
        }

        .score-display {
            text-align: center;
            background: linear-gradient(135deg, var(--primary-light) 0%, rgba(255,255,255,0.8) 100%);
            padding: 40px 20px;
            border-radius: 16px;
            box-shadow: var(--shadow);
            margin: 30px 0;
            border: 2px solid var(--primary-light);
        }

        .score-number {
            font-size: 48px;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 10px;
        }

        .score-label {
            font-size: 18px;
            color: var(--text-secondary);
            margin-bottom: 15px;
        }

        .trait-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin: 30px 0;
        }

        .trait-card {
            background: white;
            padding: 24px;
            border-radius: 12px;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            text-align: center;
            transition: transform 0.3s ease;
        }

        .trait-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-lg);
        }

        .trait-score {
            font-size: 32px;
            font-weight: 700;
            margin: 15px 0;
        }

        .trait-a { color: #28a745; }
        .trait-b { color: #ffc107; }
        .trait-c { color: #17a2b8; }
        .trait-d { color: #6f42c1; }

        .interpretation {
            background: linear-gradient(135deg, #e7f3ff 0%, #dbeafe 100%);
            padding: 32px;
            border-radius: 12px;
            margin: 30px 0;
            border-left: 4px solid var(--primary-color);
        }

        .download-section {
            text-align: center;
            margin: 40px 0;
        }

        .btn-download {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--primary-dark) 100%);
            color: white;
            border: none;
            padding: 16px 32px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }

        .btn-download:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(99, 102, 241, 0.4);
            color: white;
        }

        .back-button {
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1000;
        }

        .back-button a {
            background: white;
            color: var(--primary-color);
            padding: 12px 16px;
            border-radius: 8px;
            text-decoration: none;
            box-shadow: var(--shadow);
            border: 1px solid var(--border-color);
            transition: all 0.3s;
        }

        .back-button a:hover {
            background: var(--primary-light);
            color: var(--primary-dark);
        }

        @media (max-width: 768px) {
            .report-container {
                padding: 10px;
            }

            .trait-grid {
                grid-template-columns: 1fr;
            }

            .score-number {
                font-size: 36px;
            }
        }
    </style>
</head>
<body>
    <!-- Back Button -->
    <div class="back-button">
        <a href="student_dashboard.php"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
    </div>

    <div class="report-container">
        <?php if (!empty($message)): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>

            <div class="text-center" style="margin: 50px 0;">
                <a href="psychometric_quiz.php" class="btn-download">
                    <i class="fas fa-brain"></i> Take Psychometric Assessment
                </a>
            </div>
        <?php elseif ($report_data): ?>

            <div class="report-header">
                <h1><i class="fas fa-file-alt"></i> My Psychometric Report</h1>
                <p>Detailed analysis of your personality traits and assessment results</p>
            </div>

            <div class="student-info">
                <h3><i class="fas fa-user"></i> Assessment Information</h3>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($report_data['full_name']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($report_data['email']); ?></p>
                        <p><strong>Roll Number:</strong> <?php echo htmlspecialchars($report_data['roll_number']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Department:</strong> <?php echo htmlspecialchars($report_data['department']); ?></p>
                        <p><strong>Year:</strong> Year <?php echo htmlspecialchars($report_data['year']); ?></p>
                        <p><strong>Completed:</strong> <?php echo date('F j, Y \a\t g:i A', strtotime($report_data['completed_at'])); ?></p>
                    </div>
                </div>
            </div>

            <div class="download-section">
                <form method="POST" style="display: inline;">
                    <button type="submit" name="download_pdf" class="btn-download">
                        <i class="fas fa-download"></i> Download Report
                    </button>
                </form>
            </div>

            <div class="score-display">
                <div class="score-number"><?php echo round($report_data['score'], 1); ?>%</div>
                <div class="score-label">Overall Psychometric Score</div>
                <small>Based on Analytical and Organized traits (A+D) out of 20 questions</small>
            </div>

            <h3 style="text-align: center; margin-bottom: 20px;"><i class="fas fa-chart-bar"></i> Trait Analysis</h3>
            <p style="text-align: center; color: var(--text-secondary); margin-bottom: 30px;">
                Your assessment evaluates four personality traits:
            </p>

            <div class="trait-grid">
                <div class="trait-card">
                    <h4>Analytical (A)</h4>
                    <div class="trait-score trait-a"><?php echo $report_data['trait_a']; ?>/20</div>
                    <p>Problem-solving, logical thinking, data-driven approach</p>
                </div>
                <div class="trait-card">
                    <h4>Creative (B)</h4>
                    <div class="trait-score trait-b"><?php echo $report_data['trait_b']; ?>/20</div>
                    <p>Innovation, artistic thinking, outside-the-box ideas</p>
                </div>
                <div class="trait-card">
                    <h4>Empathetic (C)</h4>
                    <div class="trait-score trait-c"><?php echo $report_data['trait_c']; ?>/20</div>
                    <p>People-oriented, caring, relationship-focused</p>
                </div>
                <div class="trait-card">
                    <h4>Organized (D)</h4>
                    <div class="trait-score trait-d"><?php echo $report_data['trait_d']; ?>/20</div>
                    <p>Structured, detail-oriented, systematic approach</p>
                </div>
            </div>

            <div class="interpretation">
                <h3><i class="fas fa-lightbulb"></i> Score Interpretation</h3>
                <?php
                $score = round($report_data['score'], 1);
                if ($score >= 80) {
                    echo "<p><strong>Excellent Performance! 🎉</strong></p>
                    <p>You show strong analytical and organizational skills. You are well-suited for roles requiring logical thinking, planning, and systematic approaches. Consider careers in project management, data analysis, engineering, research, or any field that values structured problem-solving.</p>";
                } elseif ($score >= 60) {
                    echo "<p><strong>Good Performance! 👍</strong></p>
                    <p>You demonstrate solid analytical and organizational abilities. Consider roles that balance structured thinking with other skills. This profile suggests you would excel in technical roles, quality assurance, operational management, or analytical positions.</p>";
                } elseif ($score >= 40) {
                    echo "<p><strong>Moderate Performance 🤔</strong></p>
                    <p>You have some analytical and organizational tendencies but may benefit from developing these skills further. You might be more suited to roles that combine creativity with structure or focus on interpersonal relationships. Consider exploring careers in project coordination, business analysis, or technical support.</p>";
                } else {
                    echo "<p><strong>Different Strengths 🌟</strong></p>
                    <p>Your results suggest you may be more oriented towards creative or empathetic approaches rather than analytical/organizational ones. This is perfectly fine! Consider roles in creative fields, counseling, teaching, customer service, marketing, or any position where your interpersonal and innovative skills can shine. Your unique perspective is valuable in many areas.</p>";
                }
                ?>
            </div>

            <div class="text-center" style="margin-top: 40px;">
                <a href="student_dashboard.php" class="btn-download" style="background: #6c757d;">
                    <i class="fas fa-home"></i> Back to Dashboard
                </a>
            </div>

        <?php endif; ?>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
