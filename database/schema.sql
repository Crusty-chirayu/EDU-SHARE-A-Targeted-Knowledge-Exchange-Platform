-- EDU-SHARE P1.2 canonical schema (MariaDB 10.4+ / MySQL 8 compatible)
-- This file intentionally contains no user accounts, password hashes, uploaded resources, or PII.
-- The legacy material tables remain empty on fresh installs so upgraded deployments can
-- preserve and audit their source rows without making them the active application model.
SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS legacy_favorite_migrations;
DROP TABLE IF EXISTS legacy_material_migrations;
DROP TABLE IF EXISTS resource_favorites;
DROP TABLE IF EXISTS resource_files;
DROP TABLE IF EXISTS resource_versions;
DROP TABLE IF EXISTS resources;
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

-- Active P1.2 normalized resource model. Legacy material tables above are read-only
-- compatibility and migration sources; application writes use the tables below.
CREATE TABLE resources (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    document_type VARCHAR(20) NOT NULL DEFAULT 'mixed',
    language_code VARCHAR(16) NULL,
    university_id INT UNSIGNED NOT NULL,
    department_id INT UNSIGNED NOT NULL,
    course_id INT UNSIGNED NOT NULL,
    subject_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    visibility ENUM('public', 'authenticated', 'private') NOT NULL DEFAULT 'authenticated',
    publication_status ENUM('draft', 'published', 'archived') NOT NULL DEFAULT 'published',
    moderation_status ENUM('not_required', 'pending', 'approved', 'rejected', 'legacy_review') NOT NULL DEFAULT 'approved',
    current_version_number INT UNSIGNED NOT NULL DEFAULT 1,
    deletion_status ENUM('active', 'pending_cleanup', 'deleted') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY resources_owner_updated_idx (owner_id, updated_at),
    KEY resources_academic_discovery_idx (university_id, department_id, course_id, subject_id, semester),
    KEY resources_visibility_status_idx (visibility, publication_status, moderation_status, deletion_status, updated_at),
    CONSTRAINT resources_owner_fk FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT resources_university_fk FOREIGN KEY (university_id) REFERENCES universities (id) ON DELETE RESTRICT,
    CONSTRAINT resources_department_fk FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    CONSTRAINT resources_course_fk FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE RESTRICT,
    CONSTRAINT resources_subject_fk FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT,
    CONSTRAINT resources_semester_check CHECK (semester BETWEEN 1 AND 12),
    CONSTRAINT resources_current_version_check CHECK (current_version_number >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resource_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_id INT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    created_by INT UNSIGNED NOT NULL,
    change_description VARCHAR(500) NULL,
    lifecycle_status ENUM('current', 'superseded') NOT NULL DEFAULT 'current',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY resource_versions_resource_number_unique (resource_id, version_number),
    KEY resource_versions_resource_created_idx (resource_id, created_at),
    KEY resource_versions_creator_idx (created_by),
    CONSTRAINT resource_versions_resource_fk FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE RESTRICT,
    CONSTRAINT resource_versions_creator_fk FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT resource_versions_number_check CHECK (version_number >= 1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resource_files (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    resource_version_id INT UNSIGNED NOT NULL,
    original_filename VARCHAR(180) NOT NULL,
    storage_key VARCHAR(80) NOT NULL COMMENT 'Opaque randomized object key; never a client filesystem path',
    extension VARCHAR(10) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL,
    scan_status ENUM('not_scanned', 'pending', 'clean', 'blocked', 'error') NOT NULL DEFAULT 'not_scanned',
    processing_status ENUM('ready', 'pending', 'failed') NOT NULL DEFAULT 'ready',
    storage_status ENUM('available', 'quarantined', 'cleanup_pending', 'deleted', 'missing') NOT NULL DEFAULT 'available',
    quarantine_token VARCHAR(80) NULL COMMENT 'Internal deterministic recovery token; never exposed over HTTP',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY resource_files_storage_key_unique (storage_key),
    UNIQUE KEY resource_files_quarantine_token_unique (quarantine_token),
    UNIQUE KEY resource_files_version_checksum_unique (resource_version_id, checksum_sha256),
    KEY resource_files_version_created_idx (resource_version_id, created_at),
    KEY resource_files_checksum_size_idx (checksum_sha256, file_size),
    KEY resource_files_processing_idx (scan_status, processing_status, storage_status),
    CONSTRAINT resource_files_version_fk FOREIGN KEY (resource_version_id) REFERENCES resource_versions (id) ON DELETE RESTRICT,
    CONSTRAINT resource_files_size_check CHECK (file_size > 0),
    CONSTRAINT resource_files_checksum_check CHECK (BINARY checksum_sha256 REGEXP '^[0-9a-f]{64}$'),
    CONSTRAINT resource_files_quarantine_token_check CHECK (quarantine_token IS NULL OR BINARY quarantine_token REGEXP '^[0-9a-f]{64}[.]quarantine$')
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE resource_favorites (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id INT UNSIGNED NOT NULL,
    resource_id INT UNSIGNED NOT NULL,
    favorited_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY resource_favorites_user_resource_unique (user_id, resource_id),
    KEY resource_favorites_resource_idx (resource_id),
    CONSTRAINT resource_favorites_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT resource_favorites_resource_fk FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_material_migrations (
    material_id INT UNSIGNED NOT NULL,
    proposed_resource_id INT UNSIGNED NULL,
    resource_id INT UNSIGNED NULL,
    migration_status ENUM('review_required', 'eligible', 'migrated') NOT NULL DEFAULT 'review_required',
    reason_code VARCHAR(80) NULL,
    assessed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    migrated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (material_id),
    KEY legacy_material_migrations_resource_idx (resource_id),
    KEY legacy_material_migrations_status_idx (migration_status, reason_code),
    CONSTRAINT legacy_material_migrations_material_fk FOREIGN KEY (material_id) REFERENCES materials (id) ON DELETE RESTRICT,
    CONSTRAINT legacy_material_migrations_resource_fk FOREIGN KEY (resource_id) REFERENCES resources (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE legacy_favorite_migrations (
    material_favorite_id INT UNSIGNED NOT NULL,
    resource_favorite_id INT UNSIGNED NULL,
    migration_status ENUM('review_required', 'migrated') NOT NULL DEFAULT 'review_required',
    reason_code VARCHAR(80) NULL,
    assessed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (material_favorite_id),
    KEY legacy_favorite_migrations_resource_favorite_idx (resource_favorite_id),
    KEY legacy_favorite_migrations_status_idx (migration_status, reason_code),
    CONSTRAINT legacy_favorite_migrations_material_favorite_fk FOREIGN KEY (material_favorite_id) REFERENCES material_favorites (id) ON DELETE RESTRICT,
    CONSTRAINT legacy_favorite_migrations_resource_favorite_fk FOREIGN KEY (resource_favorite_id) REFERENCES resource_favorites (id) ON DELETE SET NULL
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
