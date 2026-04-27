-- Quiz & Exam Extension Tables
USE elms_db;

CREATE TABLE IF NOT EXISTS question_banks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    name VARCHAR(255) NOT NULL DEFAULT 'Default Bank',
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    bank_id INT NOT NULL,
    question_text TEXT NOT NULL,
    question_type ENUM('multiple_choice','true_false','essay','matching','short_answer') NOT NULL,
    points DECIMAL(5,2) DEFAULT 1.00,
    explanation TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (bank_id) REFERENCES question_banks(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS question_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    option_text TEXT NOT NULL,
    is_correct TINYINT(1) DEFAULT 0,
    match_pair TEXT DEFAULT NULL,
    option_order INT DEFAULT 0,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS exams (
    id INT AUTO_INCREMENT PRIMARY KEY,
    section_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    exam_type ENUM('quiz','exam','midterm','final','activity') DEFAULT 'quiz',
    time_limit INT DEFAULT NULL,
    max_attempts INT DEFAULT 1,
    passing_score DECIMAL(5,2) DEFAULT 60.00,
    randomize_questions TINYINT(1) DEFAULT 0,
    randomize_options TINYINT(1) DEFAULT 0,
    show_results TINYINT(1) DEFAULT 1,
    start_datetime DATETIME DEFAULT NULL,
    end_datetime DATETIME DEFAULT NULL,
    is_published TINYINT(1) DEFAULT 0,
    created_by INT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (section_id) REFERENCES sections(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS exam_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    question_id INT NOT NULL,
    order_index INT DEFAULT 0,
    points_override DECIMAL(5,2) DEFAULT NULL,
    UNIQUE KEY uq_exam_question (exam_id, question_id),
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id)
);

CREATE TABLE IF NOT EXISTS exam_attempts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    exam_id INT NOT NULL,
    student_id INT NOT NULL,
    attempt_number INT DEFAULT 1,
    started_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    submitted_at DATETIME DEFAULT NULL,
    time_spent INT DEFAULT NULL,
    score DECIMAL(5,2) DEFAULT NULL,
    total_points DECIMAL(5,2) DEFAULT NULL,
    percentage DECIMAL(5,2) DEFAULT NULL,
    is_graded TINYINT(1) DEFAULT 0,
    status ENUM('in_progress','submitted','graded') DEFAULT 'in_progress',
    question_order TEXT DEFAULT NULL,
    FOREIGN KEY (exam_id) REFERENCES exams(id),
    FOREIGN KEY (student_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS exam_answers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    question_id INT NOT NULL,
    answer_text TEXT,
    selected_option_id INT DEFAULT NULL,
    matching_pairs TEXT,
    points_earned DECIMAL(5,2) DEFAULT NULL,
    is_correct TINYINT(1) DEFAULT NULL,
    feedback TEXT,
    FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id)
);

CREATE TABLE IF NOT EXISTS rubric_criteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    criterion_name VARCHAR(255) NOT NULL,
    max_points DECIMAL(5,2) NOT NULL,
    description TEXT,
    criterion_order INT DEFAULT 0,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS integrity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attempt_id INT NOT NULL,
    event_type VARCHAR(50) NOT NULL,
    event_count INT DEFAULT 1,
    logged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (attempt_id) REFERENCES exam_attempts(id) ON DELETE CASCADE
);
