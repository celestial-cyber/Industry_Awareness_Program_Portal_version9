CREATE DATABASE IF NOT EXISTS iap_portal;
USE iap_portal;

CREATE TABLE IF NOT EXISTS iap_users_details (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(255) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'student') NOT NULL
);

CREATE TABLE IF NOT EXISTS IAP_students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    roll_number VARCHAR(50) NOT NULL UNIQUE,
    full_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    department VARCHAR(100) NOT NULL,
    year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
    password VARCHAR(255) NOT NULL,
    is_password_changed BOOLEAN DEFAULT FALSE,
    reset_token VARCHAR(255) NULL,
    reset_token_expiry DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    session_code VARCHAR(20) NULL,
    topic VARCHAR(255) NOT NULL,
    year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_sessions_session_code (session_code)
);

CREATE TABLE IF NOT EXISTS iap_session_registrations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL,
    year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
    department VARCHAR(100) NOT NULL,
    email VARCHAR(255) NOT NULL,
    session_desired VARCHAR(255) NOT NULL,
    other_query TEXT,
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS iap_session_suggestions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    roll_number VARCHAR(50) NOT NULL,
    year ENUM('1', '2', '3', '4', 'Graduate') NOT NULL,
    branch VARCHAR(100) NOT NULL,
    section VARCHAR(100) NOT NULL,
    session_desired VARCHAR(255) NOT NULL,
    other_query TEXT,
    status ENUM('pending', 'reviewed', 'approved', 'rejected') DEFAULT 'pending',
    submitted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS iap_student_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    session_id INT NOT NULL,
    session_year VARCHAR(20) NULL,
    registration_status ENUM('registered', 'completed', 'dropped') DEFAULT 'registered',
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_student_session (student_id, session_id),
    CONSTRAINT fk_iap_student_sessions_student
        FOREIGN KEY (student_id) REFERENCES IAP_students(id) ON DELETE CASCADE,
    CONSTRAINT fk_iap_student_sessions_session
        FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS iap_psychometric_scores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL UNIQUE,
    score DECIMAL(5,2) NOT NULL,
    trait_a INT DEFAULT 0,
    trait_b INT DEFAULT 0,
    trait_c INT DEFAULT 0,
    trait_d INT DEFAULT 0,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_psychometric_student
        FOREIGN KEY (student_id) REFERENCES IAP_students(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS psychometric_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question TEXT NOT NULL,
    option_a VARCHAR(255) NOT NULL,
    option_b VARCHAR(255) NOT NULL,
    option_c VARCHAR(255) NOT NULL,
    option_d VARCHAR(255) NOT NULL,
    correct_answer CHAR(1) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT IGNORE INTO iap_users_details (username, email, password, role)
VALUES ('admin', 'admin@example.com', '$2y$10$xHDNFM0xYFstLYe.BIHMUu4ZxCcEeKOQ3psUy85ZcbsCqdbWUy2Z.', 'admin');
