<?php
// index.php - Industrial Awareness Program Portal (SPECANCIENS)
if (isset($_GET['success'])) {
    echo "<script>alert('Registration successful!');</script>";
}

// Fetch sessions from database
require_once __DIR__ . '/config/db.php';

// Create tables if not exist
$sql = "CREATE TABLE IF NOT EXISTS iap_users_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'student') NOT NULL
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS iap_session_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL,
    year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
    department VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    session_desired VARCHAR(255) NOT NULL,
    other_query TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";
$conn->query($sql);

$sql = "CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    topic VARCHAR(255) NOT NULL,
    year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);";
$conn->query($sql);

// Insert default admin if not exists
$sql = 'INSERT IGNORE INTO iap_users_details (username, password, role) VALUES (\'admin@sa.com\', \'$2y$10$ACnHZm1VvA1MkO8OmoKv4uOtl4jfdX9F1qFcP4e..e6yugwmvVtxm\', \'admin\')';
$conn->query($sql);

$sessions = [];
$sessions_with_ids = [];
for ($year = 1; $year <= 5; $year++) {
    $sql = "SELECT id, topic FROM sessions WHERE year = ? ORDER BY created_at DESC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $sessions[$year] = [];
    $sessions_with_ids[$year] = [];
    while ($row = $result->fetch_assoc()) {
        $sessions[$year][] = $row['topic'];
        $sessions_with_ids[$year][] = $row;
    }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Industrial Awareness Program Portal | SPECANCIENS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Segoe UI", Roboto, Arial, sans-serif;
        }

        body {
            background: #fbfcff;
            color: #374151;
            line-height: 1.6;
        }

        html {
            scroll-behavior: smooth;
        }

        .container {
            width: 90%;
            max-width: 1200px;
            margin: auto;
        }

        /* Top Bar */
        .top-bar {
            background: #f3e8ff;
            color: #5b21b6;
            text-align: center;
            padding: 8px;
            font-size: 14px;
            font-weight: 600;
        }

        /* Header */
        .main-header {
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .header-content {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
        }

        .logo-block {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        /* 🔼 LOGO SIZE INCREASED */
        .assoc-logo {
            width: 150px;
            border-radius: 30px;
        }

        .assoc-name {
            font-size: 24px;
            font-weight: 700;
            color: #5b21b6;
            letter-spacing: 1px;
        }

        .assoc-tagline {
            font-size: 20px;
            font-weight: 700;
            color: #6b7280;
        }

        .nav-links a {
            margin-left: 22px;
            text-decoration: none;
            color: #374151;
            font-weight: 500;
        }

        .nav-links a:hover {
            color: #7c3aed;
            border-bottom: 2px solid #fca5a5;
        }

        /* Hero */
        .hero {
            background: linear-gradient(135deg, #eef2ff, #fdf2f8);
            padding: 90px 0;
        }

        .hero-content {
            display: flex;
            align-items: center;
            gap: 50px;
        }

        /* 🎨 COLOR CHANGED */
        .hero-text h2 {
            font-size: 40px;
            color: #7a1fa2; /* dark purple-pink */
            margin-bottom: 15px;
        }

        .hero-text p {
            max-width: 540px;
            font-size: 16px;
            color: #4b5563;
        }

        .hero-buttons {
            margin-top: 28px;
            display: flex;
            gap: 10px;
        }

        .primary-btn {
            background: #7c3aed;
            color: #fff;
            padding: 12px 26px;
            border-radius: 10px;
            font-weight: 700;
            font-size:18px; 
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .secondary-btn {
            background: #7c3aed;
            color: #fff;
            padding: 12px 26px;
            border-radius: 10px;
            font-weight: 700;
            font-size:18px; 
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }

        .hero-image img {
            width: 100%;
            max-width: 420px;
            border-radius: 16px;
        }
        .hero-desc{
        font-size:30px;
        font-weight:600;   /* increase size */
        line-height:1.7;  /* better readability */
            }


        /* Sections */
        .section {
            padding: 75px 0;
        }

        .section-alt {
            background: #f8fafc;
        }

        .section-title {
            text-align: center;
            font-size: 30px;
            color: #5b21b6;
            margin-bottom: 15px;
        }

        /* Year Cards */
        .year-grid {
            margin-top: 45px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 30px;
            align-items: start;
        }

        .year-card {
            background: #ffffff;
            border-radius: 16px;
            padding: 28px;
            border: 1px solid #e5e7eb;
            transition: 0.3s;
        }

        .year-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 12px 25px rgba(0,0,0,0.08);
        }

        .year-card h3 {
            color: #7c3aed;
            margin-bottom: 10px;
        }

        .year-card p {
            font-size: 14px;
            color: #4b5563;
            margin-bottom: 14px;
        }

        .pill {
            display: inline-block;
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 12px;
        }

        .pill-blue { background: #e0e7ff; color: #1e3a8a; }
        .pill-green { background: #dcfce7; color: #166534; }
        .pill-orange { background: #ffedd5; color: #9a3412; }
        .pill-purple { background: #f3e8ff; color: #6b21a8; }

        .outline-btn {
            background: #fdf2f8;
            border: 2px solid #7c3aed;
            color: #7c3aed;
            padding: 8px 18px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
        }

        .outline-btn:hover {
            background: #7c3aed;
            color: #fff;
        }

        .footer {
            background: #7c3aed;
            color: #fff;
            text-align: center;
            padding: 18px 0;
            margin-top: 50px;
            font-size: 14px;
        }

        @media (max-width: 900px) {
            .hero-content {
                flex-direction: column;
                text-align: center;
            }
            .nav-links {
                display: none;
            }

        }
       /* Contact description */
.contact-desc{
    font-size:20px;
    font-weight:600;
    color:#4b5563;
    text-align:center;
    margin-bottom:30px;
}

/* ICON ROW CONTAINER */
.contact-icons{
    display:flex;
    justify-content:center;
    align-items:center;
    gap:18px;
    flex-wrap:wrap;
}

/* INDIVIDUAL ICON STYLE */
.contact-icon{
    width:48px;
    height:48px;
    border-radius:50%;
    background:#ffffff;
    border:1px solid #e5e7eb;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#5b21b6;
    font-size:20px;
    text-decoration:none;
    transition:0.3s ease;
}

.contact-icon:hover{
    background:#7c3aed;
    color:#ffffff;
    transform:translateY(-3px);
}

/* Session Context Menu */
.session-item {
    position: relative;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    border-radius: 6px;
    transition: background-color 0.2s;
    cursor: pointer;
}

.session-item:hover {
    background-color: #f3e8ff;
}

.session-menu-icon {
    opacity: 0;
    cursor: pointer;
    font-weight: bold;
    color: #7c3aed;
    transition: opacity 0.2s;
}

.session-item:hover .session-menu-icon {
    opacity: 1;
}

/* Context Menu Styles */
.session-context-menu {
    position: fixed;
    background: white;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
    z-index: 10000;
    min-width: 200px;
    display: none;
}

.session-context-menu.show {
    display: block;
}

.session-context-menu-item {
    padding: 12px 16px;
    cursor: pointer;
    color: #374151;
    border-bottom: 1px solid #f3f4f6;
    transition: all 0.2s;
}

.session-context-menu-item:last-child {
    border-bottom: none;
}

.session-context-menu-item:hover {
    background-color: #f3e8ff;
    color: #7c3aed;
    font-weight: 600;
}

.session-context-menu-item i {
    margin-right: 8px;
}


/* Registration Form */
.registration-form {
    max-width: 600px;
    margin: 0 auto;
    display: flex;
    flex-direction: column;
    gap: 15px;
}

.registration-form label {
    font-weight: 600;
    color: #5b21b6;
}

.registration-form input, .registration-form select, .registration-form textarea {
    padding: 10px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 16px;
}

.registration-form textarea {
    height: 100px;
    resize: vertical;
}

.registration-form button {
    align-self: flex-start;
}


.toggle-sessions{
    background:#fdf2f8;
    border:2px solid #7c3aed;
    color:#7c3aed;
    padding:8px 18px;
    border-radius:8px;
    font-weight:600;
    cursor:pointer;
    transition:0.3s;
    margin-top:10px;
}

.toggle-sessions:hover{
    background:#7c3aed;
    color:#fff;
}

.session-list{
    list-style:none;
    padding-left:0;
    margin-top:10px;
    display:none; /* hidden by default */
}

.session-list li{
    background:#f8f4ff;
    padding:8px 12px;
    border-radius:8px;
    margin-bottom:6px;
    color:#4b5563;
    font-size:14px;
}





    /* Responsive modal adjustments */
    @media (max-width: 768px) {
        .modal-content {
            margin: 1% auto !important;
            width: 95% !important;
            max-height: 95vh !important;
        }

        .modal-body {
            min-height: 250px !important;
            padding: 15px !important;
        }
    }
    </style>
</head>

<body>

<div class="top-bar">
    
</div>

<header class="main-header">
    <div class="container header-content">
        <div class="logo-block">
            <img src="images/SA Main logo.jpg" class="assoc-logo" alt="SPECANCIENS Logo">
            <div>
                <h1 class="assoc-name">SPECANCIENS</h1>
                <p class="assoc-tagline">The Alumni Association of SPEC'HYD</p>
            </div>
        </div>

        <nav class="nav-links">
            <a href="#home">Home</a>
            <a href="https://www.specanciens.com">About</a>
            <a href="#years">Modules</a>
            <a href="#contact">Contact</a>
            <a href ="Admin/admin_login.php">Admin Login</a>
            <a href ="Student/student_login.php">Student Login</a>
        </nav>
    </div>
</header>

<section id="home" class="hero">
    <div class="container hero-content">
        <div class="hero-text">
            <h2>Industrial Awareness Program Portal</h2>
            <p class = "hero-desc">
                A structured alumni-driven roadmap helping students
                understand industry expectations and prepare confidently for careers.
            </p>
            <div class="hero-buttons">
                <!-- 🔗 REDIRECT FIXED -->
                <a href="#years" class="primary-btn">View Year-wise Plan</a>
                <a href="#about" class="secondary-btn">Session Registration</a>
            </div>
        </div>

        <div class="hero-image">
            <img src="images/industry_awareness.jpg" alt="Industrial Awareness">
        </div>
    </div>
</section>

<section id="years" class="section section-alt">
    <div class="container">
        <h2 class="section-title">Year-wise Modules</h2>

        <div class="year-grid">
            <div class="year-card">
                <h3>Year 1</h3>
                <p>Career awareness, fundamentals, ideathons, communication skills, resume & LinkedIn basics.</p>
                <span class="pill pill-blue">Foundation</span><br>
                <button class="outline-btn toggle-sessions">View Details</button>
                <ul class="session-list">
                    <li>Introduction to Engineering Careers</li>
                    <li>How to Ace Ideathons</li>
                    <li>What is Problem-Solving?</li>
                    <li>Emerging Technologies Overview</li>
                    <li>Soft Skills: Communication & Teamwork</li>
                    <li>College to Career Transition</li>
                    <li>Resume Building Basics</li>
                    <li>Industry Standards, Ethics & Workplace Communication</li>
                    <li>Roles, Responsibilities & Career Pathways in Industry</li>
                    <li>LinkedIn Profile Basics</li>
                    <?php foreach ($sessions_with_ids[1] as $session): ?>
                        <li class="session-item" data-session-id="<?php echo $session['id']; ?>" data-session-topic="<?php echo htmlspecialchars($session['topic']); ?>">
                            <?php echo htmlspecialchars($session['topic']); ?>
                            <span class="session-menu-icon" title="Right-click for options">⋮</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="year-card">
                <h3>Year 2</h3>
                <p>Professional skills, hackathons, coding platforms, certifications, time management.</p>
                <span class="pill pill-green">Skill Building</span><br>
                <button class="outline-btn toggle-sessions">View Details</button>
                <ul class="session-list">
                    <li>Resume Building and Career Positioning</li>
                    <li>LinkedIn Mastery for Students</li>
                    <li>Interview Preparation Fundamentals</li>
                    <li>Presentation & Public Skills</li>
                    <li>Internship Success Strategy</li>
                    <li>Wokrplace Communication & Etiquette</li>
                    <li>Building your Personal Brand</li>
                    <li>Aptitude & Reasoning for Placements</li>
                    <li>Hackathon Success & Learning</li>
                    <li>Time Management, Company Opportunities & Certifications</li>
                  
                    <?php foreach ($sessions_with_ids[2] as $session): ?>
                        <li class="session-item" data-session-id="<?php echo $session['id']; ?>" data-session-topic="<?php echo htmlspecialchars($session['topic']); ?>">
                            <?php echo htmlspecialchars($session['topic']); ?>
                            <span class="session-menu-icon" title="Right-click for options">⋮</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="year-card">
                <h3>Year 3</h3>
                <p>Internship readiness, interview mastery, GitHub portfolio, placement preparation.</p>
                <span class="pill pill-orange">Career Ready</span><br>
                <button class="outline-btn toggle-sessions">View Details</button>
                <ul class="session-list">
                    <li>Career Paths Beyond Campus Placements</li>
                    <li>Confidence Building in High-Pressure Situations</li>
                    <li>Project Presentation & Demo Skills</li>
                    <li>Internship to Full-Time Conversion</li>
                    <li>Salary Negotiation & Career Economics</li>
                    <li>Advanced Job Search Strategy & Placement Mastery</li>
                    <li>Core vs Non-Core Career Paths & Specialization</li>
                    <li>Advanced Interview Essentials & Preparation Strategy</li>
                    <li>GitHub Portfolio & Open Source Contribution</li>
                    <li>Managing Academics, Placements & Growth</li>
                    <?php foreach ($sessions_with_ids[3] as $session): ?>
                        <li class="session-item" data-session-id="<?php echo $session['id']; ?>" data-session-topic="<?php echo htmlspecialchars($session['topic']); ?>">
                            <?php echo htmlspecialchars($session['topic']); ?>
                            <span class="session-menu-icon" title="Right-click for options">⋮</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="year-card">
                <h3>Year 4</h3>
                <p>Leadership, system design, global opportunities, higher studies & contingency planning.</p>
                <span class="pill pill-purple">Mastery</span><br>
                <button class="outline-btn toggle-sessions">View Details</button>
                <ul class="session-list">
                    <li>Advanced System Design & Scalability</li>
                    <li>Specialization Deep Dive</li>
                    <li>Startup Ecosystem & Entrepreneurship</li>
                    <li>Research & Innovation in Engineering</li>
                    <li>Advanced Leadership & Management</li>
                    <li>Industry Certifications & Strategic Learning Roadmap</li>
                    <li>Global Opportunities & Remote Work</li>
                    <li>Real-World Project Development</li>
                    <li>Personal Branding & Personal Development</li>
                    <li>Alternative Paths & Contingency Planning</li>
                    <?php foreach ($sessions_with_ids[4] as $session): ?>
                        <li class="session-item" data-session-id="<?php echo $session['id']; ?>" data-session-topic="<?php echo htmlspecialchars($session['topic']); ?>">
                            <?php echo htmlspecialchars($session['topic']); ?>
                            <span class="session-menu-icon" title="Right-click for options">⋮</span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</section>
<section id="about" class="section section-alt">
    <div class="container">
        <h2 class="section-title">Session Registration</h2>
        <form action="register.php" method="post" class="registration-form">
            <label for="name">Name:</label>
            <input type="text" id="name" name="name" required>

            <label for="roll_number">Roll Number:</label>
            <input type="text" id="roll_number" name="roll_number" required>

            <label for="year">Year:</label>
            <select id="year" name="year" required>
                <option value="">-- Select Year --</option>
                <option value="1">Year 1</option>
                <option value="2">Year 2</option>
                <option value="3">Year 3</option>
                <option value="4">Year 4</option>
                <option value="Graduate">Graduate</option>
            </select>

            <label for="department">Department:</label>
            <input type="text" id="department" name="department" required>

            <label for="email">Email Address:</label>
            <input type="email" id="email" name="email" required>

            <label for="session_desired">Session Desired:</label>
            <select id="session_desired" name="session_desired" required>
                <option value="">-- Select a year first --</option>
            </select>

            <label for="other_query">Any Other Query:</label>
            <textarea id="other_query" name="other_query"></textarea>

            <button type="submit" class="primary-btn">Submit Registration</button>
        </form>

        <!-- Suggest a Session Button -->
        <div style="text-align: center; margin-top: 30px; padding-top: 30px; border-top: 1px solid #e0e0e0;">
            <button type="button" class="primary-btn" onclick="openSuggestionModal()" style="background: #7c3aed; margin-bottom: 10px;">Suggest a Session</button>
            <p style="color: #666; font-size: 14px;">Have a session idea? Let us know what you'd like to learn!</p>
        </div>
    </div>
</section>

<!-- Session Suggestion Modal -->
<div id="suggestionModal" class="modal" style="display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
    <div class="modal-content" style="background-color: #fefefe; margin: 2% auto; padding: 0; width: 90%; max-width: 600px; max-height: 90vh; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); display: flex; flex-direction: column;">
        <div class="modal-header" style="padding: 20px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0; color: #7c3aed;">Suggest a Session</h3>
            <span class="close" onclick="closeSuggestionModal()" style="cursor: pointer; font-size: 28px; font-weight: bold; color: #aaa;">&times;</span>
        </div>
        <div class="modal-body" style="padding: 20px; flex: 1; overflow-y: auto; min-height: 300px;">
            <form action="suggest_session.php" method="post" class="registration-form">
                <label for="suggestion_name">Name:</label>
                <input type="text" id="suggestion_name" name="name" required>

                <label for="suggestion_roll_number">Roll Number:</label>
                <input type="text" id="suggestion_roll_number" name="roll_number" required>

                <label for="suggestion_year">Class (Year):</label>
                <select id="suggestion_year" name="year" required>
                    <option value="">-- Select Year --</option>
                    <option value="1">Year 1</option>
                    <option value="2">Year 2</option>
                    <option value="3">Year 3</option>
                    <option value="4">Year 4</option>
                    <option value="Graduate">Graduate</option>
                </select>

                <label for="suggestion_branch">Branch/Department:</label>
                <input type="text" id="suggestion_branch" name="branch" required>

                <label for="suggestion_section">Section:</label>
                <input type="text" id="suggestion_section" name="section" required>

                <label for="suggestion_session_desired">Session You Want:</label>
                <input type="text" id="suggestion_session_desired" name="session_desired" placeholder="e.g., Advanced Machine Learning, Web Development Workshop" required>

                <label for="suggestion_other_query">Any Other Query/Suggestion:</label>
                <textarea id="suggestion_other_query" name="other_query" placeholder="Tell us more about your session idea..."></textarea>

                <button type="submit" class="primary-btn" style="width: 100%; margin-top: 20px;">Submit Suggestion</button>
            </form>
        </div>
    </div>
</div>
<section id = "contact" class ="section">
    <div class = "container">
        <h2 class="section-title">Contact US</h2>

       <p class="contact-desc">
    Stay connected with SPECANCIENS through our official platforms.
</p>

<div class="contact-icons">

    <a href="https://www.linkedin.com/in/specanciens" target="_blank" class="contact-icon">
        <i class="fa-brands fa-linkedin-in"></i>
    </a>

    <a href="https://www.instagram.com/specanciens" target="_blank" class="contact-icon">
        <i class="fa-brands fa-instagram"></i>
    </a>

    <a href="https://www.facebook.com/specanciens" target="_blank" class="contact-icon">
        <i class="fa-brands fa-facebook-f"></i>
    </a>

    <a href="https://www.youtube.com/channel/UC9UAHqxl6zzw5BGH3sobyTg" target="_blank" class="contact-icon">
        <i class="fa-brands fa-youtube"></i>
    </a>

    <a href="mailto:specanciens@stpetershyd.com" class="contact-icon">
        <i class="fa-solid fa-envelope"></i>
    </a>

    <a href="tel:+918977059315" class="contact-icon">
        <i class="fa-solid fa-phone"></i>
    </a>

</div>


</section>

<footer class="footer">
    &copy; <?php echo date('Y'); ?> SPECANCIENS • All rights reserved
</footer>

<!------javascript--------------->
<script>
// Toggle session details visibility
document.querySelectorAll('.toggle-sessions').forEach(button => {
    button.addEventListener('click', () => {
        const list = button.nextElementSibling;

        if(list.style.display === 'block'){
            list.style.display = 'none';
            button.textContent = "View Details";
        } else {
            list.style.display = 'block';
            button.textContent = "Hide Details";
        }
    });
});

// Context Menu for Session Registration
const contextMenu = document.createElement('div');
contextMenu.className = 'session-context-menu';
contextMenu.innerHTML = `
    <div class="session-context-menu-item register-for-session">
        <i class="fas fa-user-plus"></i> Register for this Session
    </div>
    <div class="session-context-menu-item view-session-info">
        <i class="fas fa-info-circle"></i> Session Info
    </div>
`;
document.body.appendChild(contextMenu);

let currentSessionId = null;
let currentSessionTopic = null;

// Handle right-click on session items
document.querySelectorAll('.session-item').forEach(item => {
    item.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        
        currentSessionId = item.dataset.sessionId;
        currentSessionTopic = item.dataset.sessionTopic;
        
        contextMenu.style.left = e.clientX + 'px';
        contextMenu.style.top = e.clientY + 'px';
        contextMenu.classList.add('show');
    });

    // Also allow clicking the menu icon
    item.querySelector('.session-menu-icon').addEventListener('click', (e) => {
        e.stopPropagation();
        
        currentSessionId = item.dataset.sessionId;
        currentSessionTopic = item.dataset.sessionTopic;
        
        const rect = item.getBoundingClientRect();
        contextMenu.style.left = rect.right + 'px';
        contextMenu.style.top = rect.top + 'px';
        contextMenu.classList.add('show');
    });
});

// Handle context menu click
document.querySelector('.register-for-session').addEventListener('click', () => {
    contextMenu.classList.remove('show');
    
    if (!currentSessionId) return;
    
    // Send registration request to server
    fetch('session_registration.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'session_id=' + currentSessionId
    })
    .then(response => response.json())
    .then(data => {
        if (data.status === 'success') {
            // Redirect to login page with session ID
            window.location.href = data.redirect_url;
        } else {
            alert('Error: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred. Please try again.');
    });
});

document.querySelector('.view-session-info').addEventListener('click', () => {
    contextMenu.classList.remove('show');
    alert('Session: ' + currentSessionTopic);
});

// Close context menu when clicking elsewhere
document.addEventListener('click', () => {
    contextMenu.classList.remove('show');
});

// Session data for dynamic dropdown population
const sessionsData = {
    '1': [
        {id: '1', title: 'Introduction to Engineering Careers'},
        {id: '2', title: 'How to Ace Ideathons'},
        {id: '3', title: 'What is Problem-Solving?'},
        {id: '4', title: 'Emerging Technologies Overview'},
        {id: '5', title: 'Soft Skills: Communication & Teamwork'},
        {id: '6', title: 'College to Career Transition'},
        {id: '7', title: 'Resume Building Basics'},
        {id: '8', title: 'Industry Standards, Ethics & Workplace Communication'},
        {id: '9', title: 'Roles, Responsibilities & Career Pathways in Industry'},
        {id: '10', title: 'LinkedIn Profile Basics'}
    ],
    '2': [
        {id: '11', title: 'Resume Building and Career Positioning'},
        {id: '12', title: 'LinkedIn Mastery for Students'},
        {id: '13', title: 'Interview Preparation Fundamentals'},
        {id: '14', title: 'Presentation & Public Skills'},
        {id: '15', title: 'Internship Success Strategy'},
        {id: '16', title: 'Workplace Communication & Etiquette'},
        {id: '17', title: 'Building your Personal Brand'},
        {id: '18', title: 'Aptitude & Reasoning for Placements'},
        {id: '19', title: 'Hackathon Success & Learning'},
        {id: '20', title: 'Time Management, Company Opportunities & Certifications'}
    ],
    '3': [
        {id: '21', title: 'Career Paths Beyond Campus Placements'},
        {id: '22', title: 'Confidence Building in High-Pressure Situations'},
        {id: '23', title: 'Project Presentation & Demo Skills'},
        {id: '24', title: 'Internship to Full-Time Conversion'},
        {id: '25', title: 'Salary Negotiation & Career Economics'},
        {id: '26', title: 'Advanced Job Search Strategy & Placement Mastery'},
        {id: '27', title: 'Core vs Non-Core Career Paths & Specialization'},
        {id: '28', title: 'Advanced Interview Essentials & Preparation Strategy'},
        {id: '29', title: 'GitHub Portfolio & Open Source Contribution'},
        {id: '30', title: 'Managing Academics, Placements & Growth'}
    ],
    '4': [
        {id: '31', title: 'Advanced System Design & Scalability'},
        {id: '32', title: 'Specialization Deep Dive'},
        {id: '33', title: 'Startup Ecosystem & Entrepreneurship'},
        {id: '34', title: 'Research & Innovation in Engineering'},
        {id: '35', title: 'Advanced Leadership & Management'},
        {id: '36', title: 'Industry Certifications & Strategic Learning Roadmap'},
        {id: '37', title: 'Global Opportunities & Remote Work'},
        {id: '38', title: 'Real-World Project Development'},
        {id: '39', title: 'Personal Branding & Personal Development'},
        {id: '40', title: 'Alternative Paths & Contingency Planning'}
    ]
};

// Function to populate session dropdown based on selected year
function populateSessions(year) {
    const sessionSelect = document.getElementById('session_desired');
    sessionSelect.innerHTML = '<option value="">-- Select a year first --</option>';

    if (year && sessionsData[year]) {
        sessionSelect.innerHTML = '<option value="">-- Select a session --</option>';
        sessionsData[year].forEach(session => {
            const option = document.createElement('option');
            option.value = session.id;
            option.textContent = session.title;
            sessionSelect.appendChild(option);
        });
    }
}

// Event listener for year selection
document.getElementById('year').addEventListener('change', function() {
    const selectedYear = this.value;
    populateSessions(selectedYear);
});

// Initialize with no sessions selected
populateSessions('');

// Modal functions for session suggestions
function openSuggestionModal() {
    document.getElementById('suggestionModal').style.display = 'block';
    document.body.style.overflow = 'hidden'; // Prevent background scrolling
}

function closeSuggestionModal() {
    document.getElementById('suggestionModal').style.display = 'none';
    document.body.style.overflow = 'auto'; // Restore scrolling
}

// Check for success message on page load
document.addEventListener('DOMContentLoaded', function() {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('suggestion_success') === '1') {
        alert('Thank you for your session suggestion! We\'ll review it and get back to you soon.');
        // Clean up the URL
        const newUrl = window.location.pathname;
        window.history.replaceState({}, document.title, newUrl);
    }
});

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('suggestionModal');
    if (event.target == modal) {
        closeSuggestionModal();
    }
}
</script>





</body>
</html> 
