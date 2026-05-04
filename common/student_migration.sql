-- Database Migration for IAP Portal Student System
-- This file creates/updates tables needed for student authentication and dashboard

-- Create students table (separate from admin users)
CREATE TABLE IF NOT EXISTS IAP_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    roll_number VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    department VARCHAR(100),
    year ENUM('1', '2', '3', '4') NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_password_changed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create sessions table with title (update existing table if needed)
CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    year ENUM('1', '2', '3', '4') NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create student_sessions junction table (many-to-many relationship)
CREATE TABLE IF NOT EXISTS iap_student_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    registration_status ENUM('registered', 'completed', 'dropped') DEFAULT 'registered',
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_session (student_id, session_id)
);

-- Insert sample students with default password (hash of "student@IAP")
-- Default password hash: password_hash("student@IAP", PASSWORD_BCRYPT) 
INSERT IGNORE INTO students (roll_number, full_name, email, department, year, password, is_password_changed) 
VALUES 
('2021001', 'John Doe', 'john.doe@example.com', 'Computer Science', '1', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm', FALSE),
('2021002', 'Jane Smith', 'jane.smith@example.com', 'Information Technology', '2', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm', FALSE),
('2021003', 'Bob Johnson', 'bob.johnson@example.com', 'Electronics', '3', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm', FALSE),
('2021004', 'Alice Brown', 'alice.brown@example.com', 'Mechanical', '4', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm', FALSE);

-- Insert sample sessions
INSERT IGNORE INTO sessions (id, title, year, description)
VALUES
(1, 'Introduction to Engineering Careers', '1', 'Career awareness and industry expectations'),
(2, 'How to Ace Ideathons', '1', 'Innovation and problem-solving skills'),
(3, 'What is Problem-Solving?', '1', 'Understanding problem-solving methodologies'),
(4, 'Emerging Technologies Overview', '1', 'Latest technology trends and their impact'),
(5, 'Soft Skills: Communication & Teamwork', '1', 'Essential soft skills for engineers'),
(6, 'College to Career Transition', '1', 'Smooth transition from academics to industry'),
(7, 'Resume Building Basics', '1', 'Creating effective resumes'),
(8, 'Industry Standards, Ethics & Workplace Communication', '1', 'Professional ethics and standards'),
(9, 'Roles, Responsibilities & Career Pathways in Industry', '1', 'Understanding industry roles'),
(10, 'LinkedIn Profile Basics', '1', 'Building professional online presence'),
(11, 'Resume Building and Career Positioning', '2', 'Professional skills development'),
(12, 'LinkedIn Mastery for Students', '2', 'Advanced LinkedIn strategies'),
(13, 'Interview Preparation Fundamentals', '2', 'Interview techniques and tips'),
(14, 'Presentation & Public Skills', '2', 'Effective presentation techniques'),
(15, 'Internship Success Strategy', '2', 'Making the most of internships'),
(16, 'Workplace Communication & Etiquette', '2', 'Professional communication skills'),
(17, 'Building your Personal Brand', '2', 'Personal branding strategies'),
(18, 'Aptitude & Reasoning for Placements', '2', 'Aptitude test preparation'),
(19, 'Hackathon Success & Learning', '2', 'Participating in hackathons effectively'),
(20, 'Time Management, Company Opportunities & Certifications', '2', 'Managing time and opportunities'),
(21, 'Career Paths Beyond Campus Placements', '3', 'Alternative career options'),
(22, 'Confidence Building in High-Pressure Situations', '3', 'Building confidence under pressure'),
(23, 'Project Presentation & Demo Skills', '3', 'Presenting projects effectively'),
(24, 'Internship to Full-Time Conversion', '3', 'Converting internships to jobs'),
(25, 'Salary Negotiation & Career Economics', '3', 'Salary negotiation strategies'),
(26, 'Advanced Job Search Strategy & Placement Mastery', '3', 'Advanced job search techniques'),
(27, 'Core vs Non-Core Career Paths & Specialization', '3', 'Choosing career specializations'),
(28, 'Advanced Interview Essentials & Preparation Strategy', '3', 'Advanced interview techniques'),
(29, 'GitHub Portfolio & Open Source Contribution', '3', 'Building developer portfolio'),
(30, 'Managing Academics, Placements & Growth', '3', 'Balancing academics and career'),
(31, 'Advanced System Design & Scalability', '3', 'Technical depth and scalability'),
(32, 'Specialization Deep Dive', '4', 'Deep dive into specializations'),
(33, 'Startup Ecosystem & Entrepreneurship', '4', 'Entrepreneurial pathways'),
(34, 'Research & Innovation in Engineering', '4', 'Research and innovation skills'),
(35, 'Advanced Leadership & Management', '4', 'Leadership development'),
(36, 'Industry Certifications & Strategic Learning Roadmap', '4', 'Certification strategies'),
(37, 'Global Opportunities & Remote Work', '4', 'International career opportunities'),
(38, 'Real-World Project Development', '4', 'Developing real projects'),
(39, 'Personal Branding & Personal Development', '4', 'Advanced personal development'),
(40, 'Alternative Paths & Contingency Planning', '4', 'Backup career plans');

-- Link sample students to sessions (registrations)
INSERT IGNORE INTO iap_student_sessions (student_id, session_id, registration_status) 
VALUES 
(1, 1, 'registered'),
(1, 2, 'registered'),
(2, 3, 'registered'),
(2, 4, 'completed'),
(3, 5, 'registered'),
(3, 6, 'registered'),
(4, 7, 'registered'),
(4, 8, 'registered');

-- Create session_suggestions table for student session requests
CREATE TABLE IF NOT EXISTS iap_session_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL,
    year ENUM('1', '2', '3', '4') NOT NULL,
    branch VARCHAR(100) NOT NULL,
    section VARCHAR(50) NOT NULL,
    session_desired VARCHAR(255) NOT NULL,
    other_query TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'reviewed', 'approved', 'rejected') DEFAULT 'pending'
);

-- Insert sample session suggestions
INSERT IGNORE INTO iap_session_suggestions (name, roll_number, year, branch, section, session_desired, other_query, status)
VALUES
('Sample Student', '2021001', '1', 'Computer Science', 'A', 'Advanced Python Programming', 'Would love to learn more about data structures and algorithms', 'pending'),
('Another Student', '2021002', '2', 'Information Technology', 'B', 'Machine Learning Workshop', 'Interested in AI and ML applications', 'reviewed');

-- Add password reset columns to students table
ALTER TABLE IAP_students
ADD COLUMN reset_token VARCHAR(255) NULL,
ADD COLUMN reset_token_expiry DATETIME NULL;

-- Create psychometric_scores table for storing assessment results
CREATE TABLE IF NOT EXISTS iap_psychometric_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    score DECIMAL(5,2) NOT NULL,
    trait_a INT DEFAULT 0,
    trait_b INT DEFAULT 0,
    trait_c INT DEFAULT 0,
    trait_d INT DEFAULT 0,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_psychometric (student_id)
);