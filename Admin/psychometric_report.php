<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}

$servername = "localhost";
$username = "root";
$password = "root@123";
$database = "iap_portal";

$conn = new mysqli($servername, $username, $password, $database);
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$message = '';
$report_data = null;

if (isset($_GET['student_id'])) {
    $student_id = (int)$_GET['student_id'];

    // Fetch student and psychometric data
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
    } else {
        $message = "Student not found.";
    }
    $stmt->close();
}

// Handle PDF download
if (isset($_POST['download_pdf']) && $report_data) {
    require_once('../tcpdf/tcpdf.php'); // You'll need to install TCPDF library

    // Create new PDF document
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);

    // Set document information
    $pdf->SetCreator('IAP Portal');
    $pdf->SetAuthor('Admin');
    $pdf->SetTitle('Psychometric Assessment Report - ' . $report_data['full_name']);

    // Remove default header/footer
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);

    // Add a page
    $pdf->AddPage();

    // Set font
    $pdf->SetFont('helvetica', '', 12);

    // Title
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 15, 'IAP Portal - Psychometric Assessment Report', 0, 1, 'C');
    $pdf->Ln(10);

    // Student Information
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Student Information', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'Name: ' . $report_data['full_name'], 0, 1);
    $pdf->Cell(0, 8, 'Email: ' . $report_data['email'], 0, 1);
    $pdf->Cell(0, 8, 'Roll Number: ' . $report_data['roll_number'], 0, 1);
    $pdf->Cell(0, 8, 'Department: ' . $report_data['department'], 0, 1);
    $pdf->Cell(0, 8, 'Year: ' . $report_data['year'], 0, 1);
    $pdf->Cell(0, 8, 'Assessment Date: ' . date('F j, Y \a\t g:i A', strtotime($report_data['completed_at'])), 0, 1);
    $pdf->Ln(10);

    // Assessment Results
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 10, 'Assessment Results', 0, 1);
    $pdf->SetFont('helvetica', '', 12);

    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Overall Score: ' . round($report_data['score'], 1) . '%', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'This score is calculated based on Analytical (A) and Organized (D) traits.', 0, 1);
    $pdf->Ln(5);

    // Trait Analysis
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Trait Analysis:', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'A = Analytical (Problem-solving, logical thinking)', 0, 1);
    $pdf->Cell(0, 8, 'B = Creative (Innovation, artistic thinking)', 0, 1);
    $pdf->Cell(0, 8, 'C = Empathetic (People-oriented, caring)', 0, 1);
    $pdf->Cell(0, 8, 'D = Organized (Structured, detail-oriented)', 0, 1);
    $pdf->Ln(5);

    // Individual Scores
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Individual Trait Scores (out of 20 questions):', 0, 1);
    $pdf->SetFont('helvetica', '', 12);
    $pdf->Cell(0, 8, 'Analytical (A): ' . $report_data['trait_a'] . ' answers', 0, 1);
    $pdf->Cell(0, 8, 'Creative (B): ' . $report_data['trait_b'] . ' answers', 0, 1);
    $pdf->Cell(0, 8, 'Empathetic (C): ' . $report_data['trait_c'] . ' answers', 0, 1);
    $pdf->Cell(0, 8, 'Organized (D): ' . $report_data['trait_d'] . ' answers', 0, 1);
    $pdf->Ln(10);

    // Interpretation
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Score Interpretation:', 0, 1);
    $pdf->SetFont('helvetica', '', 12);

    $score = round($report_data['score'], 1);
    if ($score >= 80) {
        $interpretation = "Excellent! You show strong analytical and organizational skills. You are well-suited for roles requiring logical thinking, planning, and systematic approaches.";
    } elseif ($score >= 60) {
        $interpretation = "Good performance! You demonstrate solid analytical and organizational abilities. Consider roles that balance structured thinking with other skills.";
    } elseif ($score >= 40) {
        $interpretation = "Moderate performance. You have some analytical and organizational tendencies but may benefit from developing these skills further.";
    } else {
        $interpretation = "Your results suggest you may be more oriented towards creative or empathetic approaches rather than analytical/organizational ones.";
    }

    $pdf->MultiCell(0, 8, $interpretation, 0, 'L');
    $pdf->Ln(10);

    // Footer
    $pdf->SetFont('helvetica', 'I', 10);
    $pdf->Cell(0, 10, 'Generated by IAP Portal on ' . date('F j, Y \a\t g:i A'), 0, 1, 'C');

    // Output PDF
    $filename = 'psychometric_report_' . $report_data['roll_number'] . '_' . date('Y-m-d') . '.pdf';
    $pdf->Output($filename, 'D');
    exit();
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Psychometric Report - IAP Portal</title>
    <link href="//maxcdn.bootstrapcdn.com/bootstrap/3.3.0/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .report-container { max-width: 800px; margin: 0 auto; padding: 20px; }
        .report-header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #007bff; padding-bottom: 20px; }
        .student-info { background: #f8f9fa; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .trait-scores { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .trait-card { background: white; padding: 15px; border: 1px solid #dee2e6; border-radius: 8px; text-align: center; }
        .score-highlight { font-size: 24px; font-weight: bold; color: #007bff; }
        .interpretation { background: #e7f3ff; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #007bff; }
    </style>
</head>
<body>
    <div class="container report-container">
        <div class="report-header">
            <h1><i class="fa fa-file-pdf-o"></i> Psychometric Assessment Report</h1>
            <p class="text-muted">Detailed analysis of student's personality traits and assessment results</p>
        </div>

        <?php if (!empty($message)): ?>
            <div class="alert alert-danger"><?php echo $message; ?></div>
        <?php elseif ($report_data): ?>

            <div class="student-info">
                <h3><i class="fa fa-user"></i> Student Information</h3>
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($report_data['full_name']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($report_data['email']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Roll Number:</strong> <?php echo htmlspecialchars($report_data['roll_number']); ?></p>
                        <p><strong>Department:</strong> <?php echo htmlspecialchars($report_data['department']); ?></p>
                        <p><strong>Year:</strong> Year <?php echo htmlspecialchars($report_data['year']); ?></p>
                    </div>
                </div>
                <p><strong>Assessment Completed:</strong> <?php echo date('F j, Y \a\t g:i A', strtotime($report_data['completed_at'])); ?></p>
            </div>

            <div class="text-center" style="margin: 30px 0;">
                <form method="POST" style="display: inline;">
                    <button type="submit" name="download_pdf" class="btn btn-primary btn-lg">
                        <i class="fa fa-download"></i> Download PDF Report
                    </button>
                </form>
            </div>

            <h3><i class="fa fa-bar-chart"></i> Assessment Results</h3>

            <div class="text-center" style="margin: 20px 0;">
                <div class="score-highlight"><?php echo round($report_data['score'], 1); ?>%</div>
                <p class="text-muted">Overall Psychometric Score</p>
                <small class="text-muted">Based on Analytical and Organized traits (A+D) out of 20 questions</small>
            </div>

            <h4>Trait Analysis</h4>
            <p>The psychometric assessment evaluates four personality traits:</p>
            <ul>
                <li><strong>A = Analytical:</strong> Problem-solving, logical thinking, data-driven approach</li>
                <li><strong>B = Creative:</strong> Innovation, artistic thinking, outside-the-box ideas</li>
                <li><strong>C = Empathetic:</strong> People-oriented, caring, relationship-focused</li>
                <li><strong>D = Organized:</strong> Structured, detail-oriented, systematic approach</li>
            </ul>

            <div class="trait-scores">
                <div class="trait-card">
                    <h5>Analytical (A)</h5>
                    <div style="font-size: 24px; color: #28a745; font-weight: bold;"><?php echo $report_data['trait_a']; ?>/20</div>
                </div>
                <div class="trait-card">
                    <h5>Creative (B)</h5>
                    <div style="font-size: 24px; color: #ffc107; font-weight: bold;"><?php echo $report_data['trait_b']; ?>/20</div>
                </div>
                <div class="trait-card">
                    <h5>Empathetic (C)</h5>
                    <div style="font-size: 24px; color: #17a2b8; font-weight: bold;"><?php echo $report_data['trait_c']; ?>/20</div>
                </div>
                <div class="trait-card">
                    <h5>Organized (D)</h5>
                    <div style="font-size: 24px; color: #6f42c1; font-weight: bold;"><?php echo $report_data['trait_d']; ?>/20</div>
                </div>
            </div>

            <div class="interpretation">
                <h4><i class="fa fa-lightbulb-o"></i> Score Interpretation</h4>
                <?php
                $score = round($report_data['score'], 1);
                if ($score >= 80) {
                    echo "<p><strong>Excellent Performance!</strong> You show strong analytical and organizational skills. You are well-suited for roles requiring logical thinking, planning, and systematic approaches. Consider careers in project management, data analysis, engineering, or research.</p>";
                } elseif ($score >= 60) {
                    echo "<p><strong>Good Performance!</strong> You demonstrate solid analytical and organizational abilities. Consider roles that balance structured thinking with other skills. This profile suggests you would excel in technical roles, quality assurance, or operational management.</p>";
                } elseif ($score >= 40) {
                    echo "<p><strong>Moderate Performance.</strong> You have some analytical and organizational tendencies but may benefit from developing these skills further. You might be more suited to roles that combine creativity with structure or focus on interpersonal relationships.</p>";
                } else {
                    echo "<p><strong>Different Strengths.</strong> Your results suggest you may be more oriented towards creative or empathetic approaches rather than analytical/organizational ones. Consider roles in creative fields, counseling, teaching, or customer service where your interpersonal and innovative skills can shine.</p>";
                }
                ?>
            </div>

            <div class="text-center" style="margin-top: 30px;">
                <a href="admin_dashboard.php?page=psychometric_status" class="btn btn-secondary">
                    <i class="fa fa-arrow-left"></i> Back to Status Page
                </a>
            </div>

        <?php else: ?>
            <div class="alert alert-warning">
                <h4>No Report Data Found</h4>
                <p>The requested student report could not be found. Please check the student ID and try again.</p>
                <a href="admin_dashboard.php?page=psychometric_status" class="btn btn-primary">Back to Status Page</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
<?php
$conn->close();
?>
