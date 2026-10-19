SET NAMES utf8mb4;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    email VARCHAR(150) UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM(
        'student',
        'teacher',
        'accounts',
        'academic',
        'admin'
    ) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    batch_name VARCHAR(100) NOT NULL UNIQUE,
    admission_year SMALLINT UNSIGNED NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    student_id VARCHAR(50) NOT NULL UNIQUE,
    batch_id INT UNSIGNED NULL,
    phone VARCHAR(25) NULL,
    address TEXT NULL,
    admission_date DATE NULL,
    FOREIGN KEY (user_id) REFERENCES users (id),
    FOREIGN KEY (batch_id) REFERENCES batches (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE teachers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL UNIQUE,
    employee_id VARCHAR(50) NOT NULL UNIQUE,
    designation VARCHAR(100) NULL,
    department VARCHAR(100) DEFAULT 'MIS',
    phone VARCHAR(25) NULL,
    FOREIGN KEY (user_id) REFERENCES users (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE academic_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_name VARCHAR(100) NOT NULL UNIQUE,
    start_date DATE NULL,
    end_date DATE NULL,
    status ENUM(
        'planned',
        'active',
        'completed'
    ) DEFAULT 'planned'
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE semesters (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    semester_name VARCHAR(100) NOT NULL,
    start_date DATE NULL,
    end_date DATE NULL,
    registration_open TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM(
        'upcoming',
        'active',
        'completed'
    ) DEFAULT 'upcoming',
    FOREIGN KEY (session_id) REFERENCES academic_sessions (id),
    UNIQUE KEY uq_session_semester (session_id, semester_name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE courses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_code VARCHAR(30) NOT NULL UNIQUE,
    course_title VARCHAR(180) NOT NULL,
    credit_hours DECIMAL(4, 1) NOT NULL DEFAULT 3.0,
    description TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE course_offerings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    teacher_id INT UNSIGNED NULL,
    capacity INT UNSIGNED NOT NULL DEFAULT 60,
    registration_open TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (course_id) REFERENCES courses (id),
    FOREIGN KEY (semester_id) REFERENCES semesters (id),
    FOREIGN KEY (teacher_id) REFERENCES teachers (id) ON DELETE SET NULL,
    UNIQUE KEY uq_course_offering (course_id, semester_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE course_registrations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    offering_id INT UNSIGNED NOT NULL,
    status ENUM(
        'registered',
        'dropped',
        'completed'
    ) NOT NULL DEFAULT 'registered',
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students (id),
    FOREIGN KEY (offering_id) REFERENCES course_offerings (id),
    UNIQUE KEY uq_student_offering (student_id, offering_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE attendance (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NOT NULL,
    class_date DATE NOT NULL,
    status ENUM(
        'present',
        'absent',
        'late',
        'excused'
    ) NOT NULL,
    recorded_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (registration_id) REFERENCES course_registrations (id),
    FOREIGN KEY (recorded_by) REFERENCES users (id),
    UNIQUE KEY uq_registration_date (registration_id, class_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE assessments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    offering_id INT UNSIGNED NOT NULL,
    assessment_name VARCHAR(120) NOT NULL,
    max_marks DECIMAL(7, 2) NOT NULL,
    weight_percentage DECIMAL(5, 2) NOT NULL,
    FOREIGN KEY (offering_id) REFERENCES course_offerings (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE marks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    assessment_id INT UNSIGNED NOT NULL,
    registration_id INT UNSIGNED NOT NULL,
    marks_obtained DECIMAL(7, 2) NOT NULL,
    entered_by INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (assessment_id) REFERENCES assessments (id),
    FOREIGN KEY (registration_id) REFERENCES course_registrations (id),
    FOREIGN KEY (entered_by) REFERENCES users (id),
    UNIQUE KEY uq_assessment_registration (
        assessment_id,
        registration_id
    )
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE results (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_id INT UNSIGNED NOT NULL UNIQUE,
    total_marks DECIMAL(7, 2) NULL,
    letter_grade VARCHAR(3) NULL,
    grade_point DECIMAL(3, 2) NULL,
    status ENUM(
        'draft',
        'submitted',
        'approved',
        'published'
    ) NOT NULL DEFAULT 'draft',
    published_at DATETIME NULL,
    FOREIGN KEY (registration_id) REFERENCES course_registrations (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE fee_structures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    semester_id INT UNSIGNED NOT NULL,
    fee_name VARCHAR(120) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    FOREIGN KEY (semester_id) REFERENCES semesters (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE student_invoices (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    semester_id INT UNSIGNED NOT NULL,
    invoice_number VARCHAR(60) NOT NULL UNIQUE,
    total_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    status ENUM(
        'unpaid',
        'partial',
        'paid',
        'overdue'
    ) NOT NULL DEFAULT 'unpaid',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students (id),
    FOREIGN KEY (semester_id) REFERENCES semesters (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    payment_method VARCHAR(50) NOT NULL,
    transaction_reference VARCHAR(150) NULL,
    status ENUM(
        'pending',
        'verified',
        'rejected'
    ) NOT NULL DEFAULT 'pending',
    verified_by INT UNSIGNED NULL,
    paid_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES student_invoices (id),
    FOREIGN KEY (verified_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE notices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    audience ENUM(
        'all',
        'student',
        'teacher',
        'accounts',
        'academic'
    ) NOT NULL DEFAULT 'all',
    published_by INT UNSIGNED NOT NULL,
    published_at DATETIME NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (published_by) REFERENCES users (id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(100) NULL,
    entity_id VARCHAR(60) NULL,
    details TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;