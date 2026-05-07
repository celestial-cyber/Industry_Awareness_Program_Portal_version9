-- ============================================================================
-- IAP Portal - Master Database Schema
-- ============================================================================
-- Complete database setup for Industry Awareness Program Portal
-- All tables follow naming convention: iap_tablename
-- ============================================================================

-- Step 1: Create Database
-- ============================================================================
CREATE DATABASE IF NOT EXISTS iap_portal;
USE iap_portal;

-- ============================================================================
-- Step 2: Create Admin Users Table
-- ============================================================================
-- Stores admin user credentials
CREATE TABLE IF NOT EXISTS iap_users_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'student') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_username (username),
    INDEX idx_email (email)
);

-- ============================================================================
-- Step 3: Create Students Table
-- ============================================================================
-- Stores all student user accounts
-- Password field uses bcrypt hashing
-- is_password_changed tracks if student has changed default password
CREATE TABLE IF NOT EXISTS iap_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    roll_number VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) UNIQUE,
    department VARCHAR(100),
    year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_password_changed BOOLEAN DEFAULT FALSE,
    reset_token VARCHAR(255) NULL,
    reset_token_expiry DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_roll_number (roll_number),
    INDEX idx_email (email),
    INDEX idx_created_at (created_at)
);

-- ============================================================================
-- Step 4: Create Sessions Table
-- ============================================================================
-- Stores IAP sessions organized by academic year
-- session_code: Custom code in format ALPHANUMERIC-SESSIONNAME or auto-generated YYSNNN
-- topic: Session topic/name
CREATE TABLE IF NOT EXISTS iap_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_code VARCHAR(255) NULL,
    topic VARCHAR(255) NOT NULL,
    title VARCHAR(255) NULL,
    year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sessions_session_code (session_code),
    INDEX idx_year (year),
    INDEX idx_created_at (created_at)
);

-- ============================================================================
-- Step 5: Create Student Sessions Junction Table
-- ============================================================================
-- Links students to sessions they are registered for
-- Many-to-many relationship: one student can register for multiple sessions
-- registration_status tracks: registered, completed, or dropped
CREATE TABLE IF NOT EXISTS iap_student_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    session_year VARCHAR(20) NULL,
    registration_status ENUM('registered', 'completed', 'dropped') DEFAULT 'registered',
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_student_session (student_id, session_id),
    CONSTRAINT fk_iap_student_sessions_student
        FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_iap_student_sessions_session
        FOREIGN KEY (session_id) REFERENCES iap_sessions(id) ON DELETE CASCADE,
    INDEX idx_student_id (student_id),
    INDEX idx_session_id (session_id),
    INDEX idx_registered_at (registered_at)
);

-- ============================================================================
-- Step 6: Create Session Registrations Table
-- ============================================================================
-- Stores form submissions for session registration from public homepage
CREATE TABLE IF NOT EXISTS iap_session_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL,
    year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
    department VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    session_desired VARCHAR(255) NOT NULL,
    other_query TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_roll_number (roll_number),
    INDEX idx_submitted_at (submitted_at)
);

-- ============================================================================
-- Step 7: Create Session Suggestions Table
-- ============================================================================
-- Stores student suggestions for new sessions
CREATE TABLE IF NOT EXISTS iap_session_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL,
    year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
    branch VARCHAR(100) NOT NULL,
    section VARCHAR(100) NOT NULL,
    session_desired TEXT NOT NULL,
    other_query TEXT,
    status ENUM('pending', 'reviewed', 'approved', 'rejected') DEFAULT 'pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_submitted_at (submitted_at)
);

-- ============================================================================
-- Step 8: Create Psychometric Scores Table
-- ============================================================================
-- Stores psychometric assessment results
CREATE TABLE IF NOT EXISTS iap_psychometric_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL UNIQUE,
    score DECIMAL(5,2) NOT NULL,
    trait_a INT DEFAULT 0,
    trait_b INT DEFAULT 0,
    trait_c INT DEFAULT 0,
    trait_d INT DEFAULT 0,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_iap_psychometric_student
        FOREIGN KEY (student_id) REFERENCES iap_students(id) ON DELETE CASCADE,
    INDEX idx_student_id (student_id)
);

-- ============================================================================
-- Step 9: Create Psychometric Questions Table
-- ============================================================================
-- Stores psychometric quiz questions
CREATE TABLE IF NOT EXISTS iap_psychometric_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question TEXT NOT NULL,
    option_a VARCHAR(255) NOT NULL,
    option_b VARCHAR(255) NOT NULL,
    option_c VARCHAR(255) NOT NULL,
    option_d VARCHAR(255) NOT NULL,
    correct_answer CHAR(1) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_psychometric_question (question(255)),
    INDEX idx_created_at (created_at)
);

-- ============================================================================
-- Step 10: Insert Default Admin User
-- ============================================================================
-- Default admin credentials
-- Username: admin
-- Email: admin@example.com
-- Password: (bcrypt hash - update as needed)
INSERT IGNORE INTO iap_users_details (username, email, password, role)
VALUES ('admin', 'admin@example.com', '$2y$10$xHDNFM0xYFstLYe.BIHMUu4ZxCcEeKOQ3psUy85ZcbsCqdbWUy2Z.', 'admin');

-- ============================================================================
-- Step 11: Insert Sample Students
-- ============================================================================
-- Default password for all: "student@IAP"
-- Bcrypt hash: $2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm
INSERT IGNORE INTO iap_students (roll_number, full_name, email, department, year, password, is_password_changed)
VALUES
('2021001', 'Test Student', 'test@example.com', 'Computer Science', '1', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm', FALSE),
('2021002', 'Jane Smith', 'jane.smith@example.com', 'Information Technology', '2', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm', FALSE),
('2021003', 'Bob Johnson', 'bob.johnson@example.com', 'Electronics', '3', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm', FALSE),
('2021004', 'Alice Brown', 'alice.brown@example.com', 'Mechanical', '4', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcg7b3XeKeUxWdeS86E36P4/ECm', FALSE);

-- ============================================================================
-- Step 12: Insert Sample Sessions
-- ============================================================================
-- Sample sessions across all 4 years with auto-generated session codes
INSERT IGNORE INTO iap_sessions (session_code, topic, title, year, description)
VALUES
('01SN01', 'Introduction to Engineering Careers', 'Introduction to Engineering Careers', '1', 'Career awareness and industry expectations'),
('01SN02', 'How to Ace Ideathons', 'How to Ace Ideathons', '1', 'Innovation and problem-solving skills'),
('02SN01', 'Resume Building and Career Positioning', 'Resume Building and Career Positioning', '2', 'Professional skills development'),
('02SN02', 'Interview Preparation Fundamentals', 'Interview Preparation Fundamentals', '2', 'Interview techniques and tips'),
('03SN01', 'Internship Readiness Program', 'Internship Readiness Program', '3', 'Preparing for internships'),
('03SN02', 'Advanced System Design', 'Advanced System Design', '3', 'Technical depth and scalability'),
('04SN01', 'Startup Ecosystem and Entrepreneurship', 'Startup Ecosystem and Entrepreneurship', '4', 'Entrepreneurial pathways'),
('04SN02', 'Leadership and Management Skills', 'Leadership and Management Skills', '4', 'Leadership development');

-- ============================================================================
-- Step 13: Link Sample Students to Sessions
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
-- Step 14: Verification Queries
-- ============================================================================
-- Run these to verify everything was created correctly

SELECT 'Admin Users' as table_name, COUNT(*) as count FROM iap_users_details;
SELECT 'Students' as table_name, COUNT(*) as count FROM iap_students;
SELECT 'Sessions' as table_name, COUNT(*) as count FROM iap_sessions;
SELECT 'Student Sessions' as table_name, COUNT(*) as count FROM iap_student_sessions;
SELECT 'Session Registrations' as table_name, COUNT(*) as count FROM iap_session_registrations;
SELECT 'Session Suggestions' as table_name, COUNT(*) as count FROM iap_session_suggestions;
SELECT 'Psychometric Scores' as table_name, COUNT(*) as count FROM iap_psychometric_scores;
SELECT 'Psychometric Questions' as table_name, COUNT(*) as count FROM iap_psychometric_questions;

-- ============================================================================
-- SETUP COMPLETE!
-- ============================================================================
-- Database is ready for use
-- Test credentials:
-- Admin: admin / admin@example.com
-- Student: 2021001 / student@IAP
-- ============================================================================
