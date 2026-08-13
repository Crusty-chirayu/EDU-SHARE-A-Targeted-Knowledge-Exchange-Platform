-- One-time preparation for databases created from the historical project.sql dump.
-- BACK UP THE DATABASE AND LEGACY UPLOAD DIRECTORY BEFORE RUNNING.
-- Run this file, then `php scripts/migrate_legacy_uploads.php`, then 002_p0_finalize.sql.
SET NAMES utf8mb4;
START TRANSACTION;

ALTER TABLE users
    MODIFY user_type ENUM('student', 'teacher', 'moderator', 'admin') NOT NULL DEFAULT 'student',
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;

-- Preserve the single valid semester historically assigned to materials when the subject stored 0.
UPDATE subjects s
JOIN (
    SELECT subject_id, MIN(semester) AS material_semester
      FROM materials
     WHERE subject_id IS NOT NULL AND semester BETWEEN 1 AND 12
     GROUP BY subject_id
    HAVING COUNT(DISTINCT semester) = 1
) m ON m.subject_id = s.id
SET s.semester = m.material_semester
WHERE s.semester IS NULL OR s.semester < 1 OR s.semester > 12;
UPDATE subjects SET semester = 1 WHERE semester IS NULL OR semester < 1 OR semester > 12;
ALTER TABLE subjects MODIFY semester TINYINT UNSIGNED NOT NULL;

ALTER TABLE materials
    MODIFY branch_for VARCHAR(100) NULL DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS original_filename VARCHAR(180) NULL AFTER file_path,
    ADD COLUMN IF NOT EXISTS mime_type VARCHAR(150) NULL AFTER original_filename,
    ADD COLUMN IF NOT EXISTS file_size BIGINT UNSIGNED NULL AFTER mime_type,
    ADD COLUMN IF NOT EXISTS checksum_sha256 CHAR(64) NULL AFTER file_size,
    ADD COLUMN IF NOT EXISTS visibility ENUM('public', 'authenticated', 'private') NOT NULL DEFAULT 'authenticated',
    ADD COLUMN IF NOT EXISTS status ENUM('pending', 'published', 'rejected') NOT NULL DEFAULT 'published';

-- A missing university can be derived without guesswork from the selected department.
UPDATE materials m
JOIN departments d ON d.id = m.department_id
SET m.university_id = d.university_id
WHERE m.university_id IS NULL;

-- Consolidate the historical duplicate university-favorite table before removing it.
CREATE TABLE IF NOT EXISTS university_favorites (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    university_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY university_favorites_user_university_unique (user_id, university_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Creating an empty compatibility table makes this one-time step safe when that stale table is absent.
CREATE TABLE IF NOT EXISTS user_favorites (
    id INT NOT NULL AUTO_INCREMENT,
    user_id INT NOT NULL,
    university_id INT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY user_favorites_user_university_unique (user_id, university_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO university_favorites (user_id, university_id)
SELECT user_id, university_id FROM user_favorites;
DROP TABLE user_favorites;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rate_key CHAR(64) NOT NULL,
    succeeded TINYINT(1) NOT NULL DEFAULT 0,
    attempted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY login_attempts_rate_window_idx (rate_key, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
