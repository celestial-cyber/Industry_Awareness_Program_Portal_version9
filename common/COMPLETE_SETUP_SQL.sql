-- ============================================================================
-- IAP Portal Student System - Complete Setup SQL
-- ============================================================================
-- This file contains all the SQL commands needed to set up the student system
-- Run this file in your MySQL client to initialize the database
-- ============================================================================

-- Step 1: Use the iap_portal database (should already exist)
USE iap_portal;

-- ============================================================================
-- Step 2: Create Students Table
-- ============================================================================
-- Stores all student user accounts
-- Password field uses bcrypt hashing (60 characters)
-- is_password_changed tracks if student has changed default password

CREATE TABLE IF NOT EXISTS iap_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    roll_number VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255),
    department VARCHAR(100),
    year ENUM('1', '2', '3', '4') NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_password_changed BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_roll_number (roll_number),
    INDEX idx_created_at (created_at)
);

-- ============================================================================
-- Step 3: Create Sessions Table (if not exists)
-- ============================================================================
-- Stores IAP sessions organized by academic year
-- Updated to include 'title' field (was previously just 'topic')

CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    year ENUM('1', '2', '3', '4') NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_year (year),
    INDEX idx_created_at (created_at)
);

-- Add 'title' column if it doesn't exist (migration from old schema)
-- Uncomment if your sessions table only has 'topic' column:
-- ALTER TABLE sessions ADD COLUMN title VARCHAR(255) AFTER id;
-- UPDATE sessions SET title = topic WHERE title IS NULL;
-- ALTER TABLE sessions DROP COLUMN topic;

-- ============================================================================
-- Step 4: Create Student_Sessions Junction Table
-- ============================================================================
-- Links students to sessions they are registered for
-- Many-to-many relationship: one student can register for multiple sessions
-- registration_status tracks: registered, completed, or dropped

CREATE TABLE IF NOT EXISTS iap_student_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    registration_status ENUM('registered', 'completed', 'dropped') DEFAULT 'registered',
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    UNIQUE KEY unique_student_session (student_id, session_id),
    INDEX idx_student_id (student_id),
    INDEX idx_session_id (session_id),
    INDEX idx_registered_at (registered_at)
);

-- ============================================================================
-- Step 5: Insert Sample Students
-- ============================================================================
-- Default password for all: "student@IAP"
-- Bcrypt hash: $2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm
-- is_password_changed = FALSE (student must reset on first login)

INSERT IGNORE INTO iap_students (roll_number, full_name, email, department, year, password, is_password_changed) 
VALUES 
('2021001', 'Test Student', 'test@example.com', 'Computer Science', '1', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm', FALSE),
('2021002', 'Jane Smith', 'jane.smith@example.com', 'Information Technology', '2', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm', FALSE),
('2021003', 'Bob Johnson', 'bob.johnson@example.com', 'Electronics', '3', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm', FALSE),
('2021004', 'Alice Brown', 'alice.brown@example.com', 'Mechanical', '4', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm', FALSE);

-- ============================================================================
-- Step 6: Insert Sample Sessions
-- ============================================================================
-- 8 sample sessions across all 4 years

INSERT IGNORE INTO sessions (id, title, year, description) 
VALUES 
(1, 'Introduction to Engineering Careers', '1', 'Career awareness and industry expectations'),
(2, 'How to Ace Ideathons', '1', 'Innovation and problem-solving skills'),
(3, 'Resume Building and Career Positioning', '2', 'Professional skills development'),
(4, 'Interview Preparation Fundamentals', '2', 'Interview techniques and tips'),
(5, 'Internship Readiness Program', '3', 'Preparing for internships'),
(6, 'Advanced System Design', '3', 'Technical depth and scalability'),
(7, 'Startup Ecosystem and Entrepreneurship', '4', 'Entrepreneurial pathways'),
(8, 'Leadership and Management Skills', '4', 'Leadership development');

-- ============================================================================
-- Step 7: Link Students to Sessions
-- ============================================================================
-- Register sample students for various sessions

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

-- ============================================================================
-- Step 8: Verification Queries
-- ============================================================================
-- Run these to verify everything was created correctly

SELECT 'Students Table' as table_name, COUNT(*) as count FROM iap_students;
SELECT 'Sessions Table' as table_name, COUNT(*) as count FROM sessions;
SELECT 'Student Sessions' as table_name, COUNT(*) as count FROM iap_student_sessions;

-- View sample student data
SELECT 'Student Records:' as info;
SELECT id, roll_number, full_name, year, department FROM iap_students LIMIT 10;

-- View sample session data
SELECT 'Session Records:' as info;
SELECT id, title, year FROM sessions LIMIT 10;

-- View student registrations
SELECT 'Student Registrations:' as info;
SELECT 
    s.roll_number,
    s.full_name,
    sess.title,
    sess.year,
    ss.registration_status,
    ss.registered_at
FROM iap_student_sessions ss
JOIN iap_students s ON ss.student_id = s.id
JOIN sessions sess ON ss.session_id = sess.id
LIMIT 10;

-- ============================================================================
-- Step 9: Optional - Create Quiz Tables (for future use)
-- ============================================================================
-- Uncomment these to create tables for storing quiz questions and responses

/*
-- Quiz Questions Table
CREATE TABLE IF NOT EXISTS quiz_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    question VARCHAR(500) NOT NULL,
    question_type ENUM('multiple_choice', 'rating', 'short_text') DEFAULT 'multiple_choice',
    options JSON,
    correct_answer VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    INDEX idx_session_id (session_id)
);

-- Quiz Responses Table
CREATE TABLE IF NOT EXISTS quiz_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    question_id INT NOT NULL,
    answer TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE,
    INDEX idx_student_id (student_id),
    INDEX idx_session_id (session_id),
    INDEX idx_submitted_at (submitted_at)
);
*/

-- ============================================================================
-- SETUP COMPLETE!
-- ============================================================================
-- You can now test the student system with:
-- Login: Roll Number = 2021001, Password = student@IAP
-- ============================================================================
