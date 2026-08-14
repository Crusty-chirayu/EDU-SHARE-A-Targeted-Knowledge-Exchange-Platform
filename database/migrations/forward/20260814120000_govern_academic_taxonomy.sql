-- P1.3 governed academic taxonomy.
--
-- Canonical hierarchy: university -> department -> course -> subject (scoped by
-- semester). In this repository a course already represents the program/branch
-- concept (for example, Bachelor of Computer Applications), so no guessed parallel
-- Program or Branch table is introduced.
--
-- This migration is additive and forward-only. Legacy users.branch and every legacy
-- material/resource row remain untouched. Exact legacy branch-to-course matches are
-- copied to users.course_id; missing, unmatched, or ambiguous values remain available
-- for review. MariaDB/MySQL can implicitly commit DDL, so each ADD is guarded through
-- information_schema and is safe to retry after an interrupted application.

-- Lifecycle columns retain referenced taxonomy records while excluding retired values
-- from new selectors and writes.
SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE universities ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER name',
        'SELECT 1')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'universities' AND COLUMN_NAME = 'is_active'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE universities ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'SELECT 1')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'universities' AND COLUMN_NAME = 'updated_at'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE departments ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER name',
        'SELECT 1')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'is_active'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE departments ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'SELECT 1')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND COLUMN_NAME = 'updated_at'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE courses ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER name',
        'SELECT 1')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'is_active'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE courses ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'SELECT 1')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND COLUMN_NAME = 'updated_at'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE subjects ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER semester',
        'SELECT 1')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'is_active'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE subjects ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
        'SELECT 1')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND COLUMN_NAME = 'updated_at'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

-- users.branch is deliberately retained. course_id is the canonical, governed student
-- program/branch context for new writes and deterministic legacy matches.
SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN course_id INT UNSIGNED NULL AFTER department_id',
        'SELECT 1')
      FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'course_id'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

CREATE TABLE IF NOT EXISTS legacy_user_academic_migrations (
    user_id INT UNSIGNED NOT NULL,
    legacy_branch VARCHAR(100) NULL,
    proposed_course_id INT UNSIGNED NULL,
    migration_status ENUM('not_applicable', 'eligible', 'migrated', 'review_required') NOT NULL,
    reason_code VARCHAR(80) NULL,
    assessed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    migrated_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (user_id),
    KEY legacy_user_academic_migrations_course_idx (proposed_course_id),
    KEY legacy_user_academic_migrations_status_idx (migration_status, reason_code),
    CONSTRAINT legacy_user_academic_migrations_user_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT legacy_user_academic_migrations_course_fk FOREIGN KEY (proposed_course_id) REFERENCES courses (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- This polymorphic review queue intentionally has no cascading entity foreign key:
-- it must retain evidence even if an operator later repairs or archives a legacy row.
CREATE TABLE IF NOT EXISTS academic_taxonomy_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type ENUM('university', 'department', 'course', 'subject', 'user', 'material', 'resource') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    reason_code VARCHAR(80) NOT NULL,
    legacy_value VARCHAR(500) NULL,
    review_status ENUM('open', 'resolved') NOT NULL DEFAULT 'open',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY academic_taxonomy_reviews_reason_unique (entity_type, entity_id, reason_code),
    KEY academic_taxonomy_reviews_queue_idx (review_status, entity_type, reason_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS academic_taxonomy_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id INT UNSIGNED NOT NULL,
    entity_type ENUM('university', 'department', 'course', 'subject') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    action ENUM('created', 'renamed', 'activated', 'retired') NOT NULL,
    before_name VARCHAR(150) NULL,
    after_name VARCHAR(150) NULL,
    occurred_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY academic_taxonomy_events_entity_idx (entity_type, entity_id, occurred_at),
    KEY academic_taxonomy_events_actor_idx (actor_id, occurred_at),
    CONSTRAINT academic_taxonomy_events_actor_fk FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Classify every account before copying anything. A pre-existing canonical course_id
-- is retained only when it belongs to the same department. Otherwise, a legacy match
-- is eligible only when the branch equals exactly one normalized course name in the
-- user's own valid department (case/outer whitespace canonicalization only).
-- Department names, global course names, and fuzzy aliases are never used as guesses.
INSERT INTO legacy_user_academic_migrations
    (user_id, legacy_branch, proposed_course_id, migration_status, reason_code)
SELECT u.id,
       u.branch,
       CASE WHEN existing_course.id IS NOT NULL THEN existing_course.id ELSE NULL END,
       CASE
           WHEN d.id IS NULL THEN 'review_required'
           WHEN u.course_id IS NOT NULL AND existing_course.id IS NULL THEN 'review_required'
           WHEN u.course_id IS NOT NULL THEN 'migrated'
           WHEN u.user_type <> 'student' THEN 'not_applicable'
           WHEN u.branch IS NULL OR TRIM(u.branch) = '' THEN 'review_required'
           WHEN (SELECT COUNT(*)
                   FROM courses candidate
                  WHERE candidate.department_id = u.department_id
                    AND BINARY LOWER(TRIM(candidate.name)) = BINARY LOWER(TRIM(u.branch))) = 1
               THEN 'eligible'
           ELSE 'review_required'
       END,
       CASE
           WHEN d.id IS NULL THEN 'invalid_department_path'
           WHEN u.course_id IS NOT NULL AND existing_course.id IS NULL THEN 'existing_course_path_invalid'
           WHEN u.course_id IS NOT NULL THEN NULL
           WHEN u.user_type <> 'student' THEN NULL
           WHEN u.branch IS NULL OR TRIM(u.branch) = '' THEN 'legacy_branch_missing'
           WHEN (SELECT COUNT(*)
                   FROM courses candidate
                  WHERE candidate.department_id = u.department_id
                    AND BINARY LOWER(TRIM(candidate.name)) = BINARY LOWER(TRIM(u.branch))) = 0
               THEN 'legacy_branch_no_exact_course'
           WHEN (SELECT COUNT(*)
                   FROM courses candidate
                  WHERE candidate.department_id = u.department_id
                    AND BINARY LOWER(TRIM(candidate.name)) = BINARY LOWER(TRIM(u.branch))) > 1
               THEN 'legacy_branch_ambiguous'
           ELSE NULL
       END
  FROM users u
  LEFT JOIN departments d
    ON d.id = u.department_id AND d.university_id = u.university_id
  LEFT JOIN courses existing_course
    ON existing_course.id = u.course_id AND existing_course.department_id = u.department_id
ON DUPLICATE KEY UPDATE
    legacy_branch = VALUES(legacy_branch),
    proposed_course_id = VALUES(proposed_course_id),
    migration_status = VALUES(migration_status),
    reason_code = VALUES(reason_code),
    assessed_at = CURRENT_TIMESTAMP,
    migrated_at = CASE
        WHEN VALUES(migration_status) = 'migrated' THEN COALESCE(migrated_at, CURRENT_TIMESTAMP)
        ELSE NULL
    END;

UPDATE legacy_user_academic_migrations
SET migrated_at = COALESCE(migrated_at, CURRENT_TIMESTAMP)
WHERE migration_status = 'migrated' AND proposed_course_id IS NOT NULL;

UPDATE legacy_user_academic_migrations migration
JOIN users u ON u.id = migration.user_id
JOIN courses c
  ON c.department_id = u.department_id
 AND BINARY LOWER(TRIM(c.name)) = BINARY LOWER(TRIM(u.branch))
SET migration.proposed_course_id = c.id
WHERE migration.migration_status = 'eligible';

UPDATE users u
JOIN legacy_user_academic_migrations migration
  ON migration.user_id = u.id AND migration.migration_status = 'eligible'
SET u.course_id = migration.proposed_course_id
WHERE migration.proposed_course_id IS NOT NULL;

UPDATE legacy_user_academic_migrations migration
JOIN users u ON u.id = migration.user_id AND u.course_id = migration.proposed_course_id
SET migration.migration_status = 'migrated',
    migration.reason_code = NULL,
    migration.migrated_at = COALESCE(migration.migrated_at, CURRENT_TIMESTAMP)
WHERE migration.migration_status = 'eligible'
  AND migration.proposed_course_id IS NOT NULL;

-- Preserve unresolved user context in the common operator queue.
INSERT IGNORE INTO academic_taxonomy_reviews
    (entity_type, entity_id, reason_code, legacy_value)
SELECT 'user', migration.user_id, migration.reason_code, migration.legacy_branch
  FROM legacy_user_academic_migrations migration
 WHERE migration.migration_status = 'review_required'
   AND migration.reason_code IS NOT NULL;

-- Audit every pre-existing cross-parent relationship. No IDs are changed here.
INSERT IGNORE INTO academic_taxonomy_reviews
    (entity_type, entity_id, reason_code, legacy_value)
SELECT 'subject', s.id, 'subject_course_department_mismatch',
       CONCAT('course_id=', s.course_id, ';department_id=', s.department_id, ';semester=', s.semester)
  FROM subjects s
  LEFT JOIN courses c ON c.id = s.course_id AND c.department_id = s.department_id
 WHERE c.id IS NULL OR s.semester NOT BETWEEN 1 AND 12;

INSERT IGNORE INTO academic_taxonomy_reviews
    (entity_type, entity_id, reason_code, legacy_value)
SELECT 'material', m.id, 'legacy_material_academic_path_invalid',
       CONCAT('university_id=', m.university_id, ';department_id=', m.department_id,
              ';course_id=', m.course_id, ';subject_id=', m.subject_id, ';semester=', m.semester)
  FROM materials m
  LEFT JOIN departments d ON d.id = m.department_id AND d.university_id = m.university_id
  LEFT JOIN courses c ON c.id = m.course_id AND c.department_id = m.department_id
  LEFT JOIN subjects s ON s.id = m.subject_id
                       AND s.course_id = m.course_id
                       AND s.department_id = m.department_id
                       AND s.semester = m.semester
 WHERE d.id IS NULL OR c.id IS NULL OR s.id IS NULL OR m.semester NOT BETWEEN 1 AND 12;

INSERT IGNORE INTO academic_taxonomy_reviews
    (entity_type, entity_id, reason_code, legacy_value)
SELECT 'resource', r.id, 'resource_academic_path_invalid',
       CONCAT('university_id=', r.university_id, ';department_id=', r.department_id,
              ';course_id=', r.course_id, ';subject_id=', r.subject_id, ';semester=', r.semester)
  FROM resources r
  LEFT JOIN departments d ON d.id = r.department_id AND d.university_id = r.university_id
  LEFT JOIN courses c ON c.id = r.course_id AND c.department_id = r.department_id
  LEFT JOIN subjects s ON s.id = r.subject_id
                       AND s.course_id = r.course_id
                       AND s.department_id = r.department_id
                       AND s.semester = r.semester
 WHERE d.id IS NULL OR c.id IS NULL OR s.id IS NULL OR r.semester NOT BETWEEN 1 AND 12;

-- Lifecycle checks and selector indexes align upgraded installations with the fresh
-- schema. These DDL steps also commit the review evidence before path constraints are
-- attempted; an invalid canonical path therefore fails closed without losing its queue.
SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE universities ADD CONSTRAINT universities_active_check CHECK (is_active IN (0, 1))',
        'SELECT 1')
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'universities' AND CONSTRAINT_NAME = 'universities_active_check'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE departments ADD KEY departments_active_parent_idx (university_id, is_active, name), ADD CONSTRAINT departments_active_check CHECK (is_active IN (0, 1))',
        'SELECT 1')
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND CONSTRAINT_NAME = 'departments_active_check'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE courses ADD KEY courses_active_parent_idx (department_id, is_active, name), ADD CONSTRAINT courses_active_check CHECK (is_active IN (0, 1))',
        'SELECT 1')
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND CONSTRAINT_NAME = 'courses_active_check'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE subjects ADD KEY subjects_active_parent_idx (course_id, semester, is_active, name), ADD CONSTRAINT subjects_active_check CHECK (is_active IN (0, 1))',
        'SELECT 1')
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND CONSTRAINT_NAME = 'subjects_active_check'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

-- Supporting composite keys make the governed lineage enforceable by InnoDB. All
-- participating identifiers are INT UNSIGNED (semester is TINYINT UNSIGNED) in the
-- P1.2 canonical prerequisite and in database/schema.sql.
SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE departments ADD UNIQUE KEY departments_id_university_unique (id, university_id)',
        'SELECT 1')
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'departments' AND INDEX_NAME = 'departments_id_university_unique'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE courses ADD UNIQUE KEY courses_id_department_unique (id, department_id)',
        'SELECT 1')
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'courses' AND INDEX_NAME = 'courses_id_department_unique'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE subjects ADD UNIQUE KEY subjects_complete_path_unique (id, course_id, department_id, semester)',
        'SELECT 1')
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND INDEX_NAME = 'subjects_complete_path_unique'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE subjects ADD CONSTRAINT subjects_course_department_fk FOREIGN KEY (course_id, department_id) REFERENCES courses (id, department_id) ON DELETE RESTRICT',
        'SELECT 1')
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'subjects' AND CONSTRAINT_NAME = 'subjects_course_department_fk'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE users ADD KEY users_course_idx (course_id), ADD KEY users_department_university_idx (department_id, university_id), ADD KEY users_course_department_idx (course_id, department_id)',
        'SELECT 1')
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'users_course_department_idx'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE users ADD CONSTRAINT users_course_fk FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE RESTRICT, ADD CONSTRAINT users_department_university_fk FOREIGN KEY (department_id, university_id) REFERENCES departments (id, university_id) ON DELETE RESTRICT, ADD CONSTRAINT users_course_department_fk FOREIGN KEY (course_id, department_id) REFERENCES courses (id, department_id) ON DELETE RESTRICT',
        'SELECT 1')
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'users_course_department_fk'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE resources ADD KEY resources_department_university_idx (department_id, university_id), ADD KEY resources_course_department_idx (course_id, department_id), ADD KEY resources_subject_path_idx (subject_id, course_id, department_id, semester)',
        'SELECT 1')
      FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'resources' AND INDEX_NAME = 'resources_subject_path_idx'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;

SET @taxonomy_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE resources ADD CONSTRAINT resources_department_university_fk FOREIGN KEY (department_id, university_id) REFERENCES departments (id, university_id) ON DELETE RESTRICT, ADD CONSTRAINT resources_course_department_fk FOREIGN KEY (course_id, department_id) REFERENCES courses (id, department_id) ON DELETE RESTRICT, ADD CONSTRAINT resources_subject_path_fk FOREIGN KEY (subject_id, course_id, department_id, semester) REFERENCES subjects (id, course_id, department_id, semester) ON DELETE RESTRICT',
        'SELECT 1')
      FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'resources' AND CONSTRAINT_NAME = 'resources_subject_path_fk'
);
PREPARE taxonomy_statement FROM @taxonomy_ddl;
EXECUTE taxonomy_statement;
DEALLOCATE PREPARE taxonomy_statement;
