-- ELMS Database Schema
CREATE DATABASE IF NOT EXISTS elms_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE elms_db;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    role ENUM('dean','secretary','faculty','student') NOT NULL,
    department VARCHAR(150),
    profile_picture VARCHAR(255) DEFAULT NULL,
    is_first_login TINYINT(1) DEFAULT 1,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Courses table
CREATE TABLE IF NOT EXISTS courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(50) NOT NULL UNIQUE,
    course_name VARCHAR(200) NOT NULL,
    description TEXT,
    department VARCHAR(150),
    units INT DEFAULT 3,
    created_by INT NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Class sections
CREATE TABLE IF NOT EXISTS sections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    section_name VARCHAR(100) NOT NULL,
    faculty_id INT NOT NULL,
    semester VARCHAR(50),
    school_year VARCHAR(20),
    room VARCHAR(100),
    schedule VARCHAR(255),
    max_students INT DEFAULT 40,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id),
    FOREIGN KEY (faculty_id) REFERENCES users(id)
);

-- Student enrollment
CREATE TABLE IF NOT EXISTS enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    student_id INT NOT NULL,
    enrolled_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('enrolled','dropped') DEFAULT 'enrolled',
    UNIQUE KEY unique_enrollment (section_id, student_id),
    FOREIGN KEY (section_id) REFERENCES sections(id),
    FOREIGN KEY (student_id) REFERENCES users(id)
);

-- Modules / Topics
CREATE TABLE IF NOT EXISTS modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    module_type ENUM('week','topic','module') DEFAULT 'module',
    week_number INT,
    order_index INT DEFAULT 0,
    is_visible TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES sections(id)
);

-- Learning materials
CREATE TABLE IF NOT EXISTS materials (
    id INT AUTO_INCREMENT PRIMARY KEY,
    module_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    material_type ENUM('syllabus','lecture_note','pdf','video','presentation','link','other') DEFAULT 'other',
    file_path VARCHAR(500),
    external_url VARCHAR(500),
    file_size BIGINT,
    mime_type VARCHAR(100),
    uploaded_by INT NOT NULL,
    order_index INT DEFAULT 0,
    is_visible TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (module_id) REFERENCES modules(id),
    FOREIGN KEY (uploaded_by) REFERENCES users(id)
);

-- Version control for materials
CREATE TABLE IF NOT EXISTS material_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    material_id INT NOT NULL,
    version_number INT NOT NULL,
    title VARCHAR(255),
    file_path VARCHAR(500),
    external_url VARCHAR(500),
    file_size BIGINT,
    change_notes TEXT,
    updated_by INT NOT NULL,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (material_id) REFERENCES materials(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
);

-- Course calendar events
CREATE TABLE IF NOT EXISTS calendar_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT,
    course_id INT,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    event_type ENUM('class','exam','assignment','activity','holiday','other') DEFAULT 'other',
    event_date DATE NOT NULL,
    start_time TIME,
    end_time TIME,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES sections(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

-- Announcements
CREATE TABLE IF NOT EXISTS announcements (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    posted_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES sections(id),
    FOREIGN KEY (posted_by) REFERENCES users(id)
);

-- Default admin accounts (dean and secretary)
-- Passwords are hashed: 'admin123' for dean, 'secretary123' for secretary
INSERT INTO users (username, password, first_name, last_name, email, role, is_first_login) VALUES
('dean_admin', '$2y$10$QRKfVufMrsWPJEZP7rr5xO0z2Y4dPRYT0MLlowpRO/KeEk9nh1iuG', 'Dean', 'Admin', 'dean@elms.edu', 'dean', 0),
('secretary_admin', '$2y$10$QRKfVufMrsWPJEZP7rr5xO0z2Y4dPRYT0MLlowpRO/KeEk9nh1iuG', 'Secretary', 'Admin', 'secretary@elms.edu', 'secretary', 0);
