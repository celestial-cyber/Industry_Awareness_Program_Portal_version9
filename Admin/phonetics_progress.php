<?php
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header("Location: admin_login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phonetics Progress - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body {
            background: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .wrap {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .placeholder-card {
            max-width: 720px;
            width: 100%;
            border: 0;
            border-radius: 14px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
        }

        .placeholder-icon {
            font-size: 2.2rem;
            color: #7c3aed;
        }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card placeholder-card">
            <div class="card-body p-4 p-md-5">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <i class="fas fa-chart-line placeholder-icon"></i>
                    <h1 class="h3 mb-0">Phonetics Progress</h1>
                </div>
                <p class="text-muted mb-4">
                    This section is reserved for upcoming student pronunciation activity reports.
                </p>
                <a href="admin_dashboard.php" class="btn btn-primary">
                    <i class="fas fa-arrow-left me-1"></i> Back to Admin Dashboard
                </a>
            </div>
        </div>
    </div>
</body>
</html>
