-- EDU-SHARE P0 canonical schema (MariaDB 10.4+ / MySQL 8 compatible)
-- This file intentionally contains no user accounts, password hashes, uploaded material, or PII.
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS material_favorites;
DROP TABLE IF EXISTS university_favorites;
DROP TABLE IF EXISTS materials;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS subjects;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS universities;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE universities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY universities_name_unique (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE departments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    university_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY departments_university_name_unique (university_id, name),
    CONSTRAINT departments_university_fk FOREIGN KEY (university_id) REFERENCES universities (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE courses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    department_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY courses_department_name_unique (department_id, name),
    CONSTRAINT courses_department_fk FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE subjects (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(150) NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY subjects_course_semester_name_unique (course_id, semester, name),
    KEY subjects_department_idx (department_id),
    CONSTRAINT subjects_course_fk FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE RESTRICT,
    CONSTRAINT subjects_department_fk FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT subjects_semester_check CHECK (semester BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL,
    gmail VARCHAR(254) NOT NULL,
    password VARCHAR(255) NOT NULL,
    user_type ENUM('student', 'teacher', 'moderator', 'admin') NOT NULL DEFAULT 'student',
    university_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    roll_number VARCHAR(50) DEFAULT NULL,
    branch VARCHAR(100) DEFAULT NULL,
    year TINYINT UNSIGNED DEFAULT NULL,
    contact VARCHAR(20) DEFAULT NULL,
    address VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY users_email_unique (gmail),
    UNIQUE KEY users_roll_number_unique (roll_number),
    KEY users_university_idx (university_id),
    KEY users_department_idx (department_id),
    CONSTRAINT users_university_fk FOREIGN KEY (university_id) REFERENCES universities (id) ON DELETE RESTRICT,
    CONSTRAINT users_department_fk FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT users_year_check CHECK (year IS NULL OR year BETWEEN 1 AND 8)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE materials (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT DEFAULT NULL,
    type VARCHAR(10) NOT NULL,
    file_path VARCHAR(80) NOT NULL COMMENT 'Opaque randomized storage key; never a client-supplied filesystem path',
    original_filename VARCHAR(180) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    university_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    branch_for VARCHAR(100) DEFAULT NULL COMMENT 'Legacy compatibility field; no longer required by uploads',
    course_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    upload_group_id CHAR(32) DEFAULT NULL,
    visibility ENUM('public', 'authenticated', 'private') NOT NULL DEFAULT 'authenticated',
    status ENUM('pending', 'published', 'rejected') NOT NULL DEFAULT 'published',
    upload_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY materials_storage_key_unique (file_path),
    KEY materials_owner_checksum_idx (user_id, checksum_sha256),
    KEY materials_discovery_idx (university_id, department_id, course_id, subject_id, semester, status),
    KEY materials_upload_date_idx (upload_date),
    CONSTRAINT materials_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT materials_university_fk FOREIGN KEY (university_id) REFERENCES universities (id) ON DELETE RESTRICT,
    CONSTRAINT materials_department_fk FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT materials_course_fk FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE RESTRICT,
    CONSTRAINT materials_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT,
    CONSTRAINT materials_semester_check CHECK (semester BETWEEN 1 AND 12)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE material_favorites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    material_id INT UNSIGNED NOT NULL,
    favorited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY material_favorites_user_material_unique (user_id, material_id),
    KEY material_favorites_material_idx (material_id),
    CONSTRAINT material_favorites_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT material_favorites_material_fk FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE university_favorites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    university_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY university_favorites_user_university_unique (user_id, university_id),
    KEY university_favorites_university_idx (university_id),
    CONSTRAINT university_favorites_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT university_favorites_university_fk FOREIGN KEY (university_id) REFERENCES universities (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rate_key CHAR(64) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY login_attempts_rate_window_idx (rate_key, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
