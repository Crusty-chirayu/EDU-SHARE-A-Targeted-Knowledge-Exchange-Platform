-- P1.2 normalized logical resource, immutable version, and private file metadata model.
-- This migration is additive: legacy materials and material_favorites remain unchanged.
-- MariaDB/MySQL may implicitly commit DDL. Every step is rerunnable after interruption.

CREATE TABLE IF NOT EXISTS resources (
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

CREATE TABLE IF NOT EXISTS resource_versions (
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

CREATE TABLE IF NOT EXISTS resource_files (
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

CREATE TABLE IF NOT EXISTS resource_favorites (
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

CREATE TABLE IF NOT EXISTS legacy_material_migrations (
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

CREATE TABLE IF NOT EXISTS legacy_favorite_migrations (
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

-- Start with an explicit review record for every source row. The later eligibility
-- assessment upgrades only rows whose complete academic path, storage metadata, and
-- upload-group metadata are deterministic.
INSERT IGNORE INTO legacy_material_migrations
    (material_id, migration_status, reason_code)
SELECT m.id, 'review_required', 'not_yet_deterministic'
  FROM materials m;

UPDATE legacy_material_migrations lm
JOIN materials m ON m.id = lm.material_id
JOIN departments d ON d.id = m.department_id AND d.university_id = m.university_id
JOIN courses c ON c.id = m.course_id AND c.department_id = m.department_id
JOIN subjects s ON s.id = m.subject_id
               AND s.course_id = m.course_id
               AND s.department_id = m.department_id
               AND s.semester = m.semester
SET lm.proposed_resource_id = CASE
        WHEN m.upload_group_id IS NULL THEN m.id
        ELSE (SELECT MIN(g.id) FROM materials g WHERE g.upload_group_id = m.upload_group_id)
    END,
    lm.resource_id = NULL,
    lm.migration_status = 'eligible',
    lm.reason_code = NULL
WHERE lm.migration_status <> 'migrated'
  AND m.semester BETWEEN 1 AND 12
  AND CHAR_LENGTH(m.title) BETWEEN 1 AND 200
  AND CHAR_LENGTH(m.original_filename) BETWEEN 1 AND 180
  AND CHAR_LENGTH(m.mime_type) BETWEEN 1 AND 150
  AND m.file_size > 0
  AND BINARY m.checksum_sha256 REGEXP '^[0-9a-f]{64}$'
  AND BINARY m.file_path REGEXP '^[0-9a-f]{32}\\.(pdf|txt|doc|docx|xls|xlsx|ppt|pptx)$'
  AND LOWER(m.type) = LOWER(SUBSTRING_INDEX(m.file_path, '.', -1))
  AND (m.upload_group_id IS NULL OR m.upload_group_id REGEXP '^[0-9a-f]{32}$')
  -- Reject the complete upload group if any member shares a storage identity.
  AND NOT EXISTS (
        SELECT 1
          FROM materials storage_member
          JOIN materials same_storage
            ON same_storage.id <> storage_member.id
           AND same_storage.file_path = storage_member.file_path
         WHERE COALESCE(storage_member.upload_group_id, CONCAT('material:', HEX(storage_member.id)))
             = COALESCE(m.upload_group_id, CONCAT('material:', HEX(m.id)))
    )
  -- A normalized version permits each checksum once, so reject the whole group.
  AND NOT EXISTS (
        SELECT 1
          FROM materials content_member
          JOIN materials same_content
            ON same_content.upload_group_id = content_member.upload_group_id
           AND same_content.id <> content_member.id
           AND same_content.checksum_sha256 = content_member.checksum_sha256
         WHERE m.upload_group_id IS NOT NULL
           AND content_member.upload_group_id = m.upload_group_id
    )
  AND NOT EXISTS (
        SELECT 1
          FROM materials conflict
         WHERE m.upload_group_id IS NOT NULL
           AND conflict.upload_group_id = m.upload_group_id
           AND (
                NOT (conflict.user_id <=> m.user_id)
                OR NOT (conflict.title <=> m.title)
                OR NOT (conflict.description <=> m.description)
                OR NOT (conflict.university_id <=> m.university_id)
                OR NOT (conflict.department_id <=> m.department_id)
                OR NOT (conflict.course_id <=> m.course_id)
                OR NOT (conflict.subject_id <=> m.subject_id)
                OR NOT (conflict.semester <=> m.semester)
                OR NOT (conflict.visibility <=> m.visibility)
                OR NOT (conflict.status <=> m.status)
           )
    )
  AND NOT EXISTS (
        SELECT 1
          FROM materials grouped
          LEFT JOIN departments gd ON gd.id = grouped.department_id AND gd.university_id = grouped.university_id
          LEFT JOIN courses gc ON gc.id = grouped.course_id AND gc.department_id = grouped.department_id
          LEFT JOIN subjects gs ON gs.id = grouped.subject_id
                               AND gs.course_id = grouped.course_id
                               AND gs.department_id = grouped.department_id
                               AND gs.semester = grouped.semester
         WHERE m.upload_group_id IS NOT NULL
           AND grouped.upload_group_id = m.upload_group_id
           AND (
                gd.id IS NULL OR gc.id IS NULL OR gs.id IS NULL
                OR grouped.semester IS NULL OR grouped.semester NOT BETWEEN 1 AND 12
                OR grouped.title IS NULL OR CHAR_LENGTH(grouped.title) NOT BETWEEN 1 AND 200
                OR grouped.original_filename IS NULL
                OR CHAR_LENGTH(grouped.original_filename) NOT BETWEEN 1 AND 180
                OR grouped.mime_type IS NULL OR CHAR_LENGTH(grouped.mime_type) NOT BETWEEN 1 AND 150
                OR grouped.file_size IS NULL OR grouped.file_size < 1
                OR grouped.checksum_sha256 IS NULL
                OR BINARY grouped.checksum_sha256 NOT REGEXP '^[0-9a-f]{64}$'
                OR grouped.file_path IS NULL
                OR BINARY grouped.file_path NOT REGEXP '^[0-9a-f]{32}\\.(pdf|txt|doc|docx|xls|xlsx|ppt|pptx)$'
                OR grouped.type IS NULL
                OR LOWER(grouped.type) <> LOWER(SUBSTRING_INDEX(grouped.file_path, '.', -1))
           )
    );

UPDATE legacy_material_migrations lm
JOIN materials m ON m.id = lm.material_id
SET lm.reason_code = CASE
    WHEN EXISTS (
        SELECT 1
          FROM materials academic_member
          LEFT JOIN departments d
            ON d.id = academic_member.department_id
           AND d.university_id = academic_member.university_id
          LEFT JOIN courses c
            ON c.id = academic_member.course_id
           AND c.department_id = academic_member.department_id
          LEFT JOIN subjects s
            ON s.id = academic_member.subject_id
           AND s.course_id = academic_member.course_id
           AND s.department_id = academic_member.department_id
           AND s.semester = academic_member.semester
         WHERE COALESCE(academic_member.upload_group_id, CONCAT('material:', HEX(academic_member.id)))
             = COALESCE(m.upload_group_id, CONCAT('material:', HEX(m.id)))
           AND (d.id IS NULL OR c.id IS NULL OR s.id IS NULL
                OR academic_member.semester IS NULL
                OR academic_member.semester NOT BETWEEN 1 AND 12)
    ) THEN 'academic_relationship_invalid'
    WHEN EXISTS (
        SELECT 1
          FROM materials storage_member
          JOIN materials same_storage
            ON same_storage.id <> storage_member.id
           AND same_storage.file_path = storage_member.file_path
         WHERE COALESCE(storage_member.upload_group_id, CONCAT('material:', HEX(storage_member.id)))
             = COALESCE(m.upload_group_id, CONCAT('material:', HEX(m.id)))
    ) THEN 'storage_identity_ambiguous'
    WHEN m.upload_group_id IS NOT NULL AND EXISTS (
        SELECT 1
          FROM materials content_member
          JOIN materials same_content
            ON same_content.upload_group_id = content_member.upload_group_id
           AND same_content.id <> content_member.id
           AND same_content.checksum_sha256 = content_member.checksum_sha256
         WHERE content_member.upload_group_id = m.upload_group_id
    ) THEN 'upload_group_duplicate_content'
    WHEN EXISTS (
        SELECT 1
          FROM materials resource_metadata_member
         WHERE COALESCE(resource_metadata_member.upload_group_id, CONCAT('material:', HEX(resource_metadata_member.id)))
             = COALESCE(m.upload_group_id, CONCAT('material:', HEX(m.id)))
           AND (resource_metadata_member.title IS NULL
                OR CHAR_LENGTH(resource_metadata_member.title) NOT BETWEEN 1 AND 200)
    ) THEN 'resource_metadata_invalid'
    WHEN EXISTS (
        SELECT 1
          FROM materials storage_metadata_member
         WHERE COALESCE(storage_metadata_member.upload_group_id, CONCAT('material:', HEX(storage_metadata_member.id)))
             = COALESCE(m.upload_group_id, CONCAT('material:', HEX(m.id)))
           AND (
                storage_metadata_member.file_size IS NULL
                OR storage_metadata_member.file_size < 1
                OR storage_metadata_member.original_filename IS NULL
                OR CHAR_LENGTH(storage_metadata_member.original_filename) NOT BETWEEN 1 AND 180
                OR storage_metadata_member.mime_type IS NULL
                OR CHAR_LENGTH(storage_metadata_member.mime_type) NOT BETWEEN 1 AND 150
                OR storage_metadata_member.checksum_sha256 IS NULL
                OR BINARY storage_metadata_member.checksum_sha256 NOT REGEXP '^[0-9a-f]{64}$'
                OR storage_metadata_member.file_path IS NULL
                OR BINARY storage_metadata_member.file_path NOT REGEXP '^[0-9a-f]{32}\\.(pdf|txt|doc|docx|xls|xlsx|ppt|pptx)$'
                OR storage_metadata_member.type IS NULL
                OR LOWER(storage_metadata_member.type)
                    <> LOWER(SUBSTRING_INDEX(storage_metadata_member.file_path, '.', -1))
           )
    ) THEN 'storage_metadata_invalid'
    WHEN m.upload_group_id IS NOT NULL THEN 'upload_group_ambiguous'
    ELSE 'conversion_requires_review'
END
WHERE lm.migration_status = 'review_required';

-- A rerun may encounter a resource row committed before an interrupted migration.
-- Continue only when that row is byte-for-byte compatible with the deterministic
-- source metadata. A same-ID row with different meaning is preserved and reviewed;
-- it must never receive legacy versions/files merely because its numeric ID collided.
UPDATE legacy_material_migrations lm
JOIN materials representative ON representative.id = lm.proposed_resource_id
JOIN resources existing ON existing.id = lm.proposed_resource_id
SET lm.proposed_resource_id = NULL,
    lm.resource_id = NULL,
    lm.migration_status = 'review_required',
    lm.reason_code = 'resource_identity_conflict'
WHERE lm.migration_status = 'eligible'
  AND (
       NOT (existing.owner_id <=> representative.user_id)
       OR NOT (BINARY existing.title <=> BINARY representative.title)
       OR NOT (BINARY existing.description <=> BINARY representative.description)
       OR NOT (existing.university_id <=> representative.university_id)
       OR NOT (existing.department_id <=> representative.department_id)
       OR NOT (existing.course_id <=> representative.course_id)
       OR NOT (existing.subject_id <=> representative.subject_id)
       OR NOT (existing.semester <=> representative.semester)
       OR NOT (existing.visibility <=> representative.visibility)
       OR BINARY existing.document_type <> BINARY (
            SELECT CASE WHEN COUNT(DISTINCT LOWER(grouped.type)) = 1
                        THEN MIN(LOWER(grouped.type)) ELSE 'mixed' END
              FROM materials grouped
             WHERE COALESCE(grouped.upload_group_id, CONCAT('material:', HEX(grouped.id)))
                 = COALESCE(representative.upload_group_id, CONCAT('material:', HEX(representative.id)))
       )
       OR existing.language_code IS NOT NULL
       OR existing.publication_status <> 'published'
       OR existing.moderation_status <> CASE representative.status
              WHEN 'published' THEN 'approved'
              WHEN 'pending' THEN 'pending'
              WHEN 'rejected' THEN 'rejected'
              ELSE 'legacy_review'
          END
       OR existing.current_version_number <> 1
       OR existing.deletion_status <> 'active'
       OR existing.deleted_at IS NOT NULL
       OR NOT (existing.created_at <=> (
            SELECT MIN(grouped.upload_date)
              FROM materials grouped
             WHERE COALESCE(grouped.upload_group_id, CONCAT('material:', HEX(grouped.id)))
                 = COALESCE(representative.upload_group_id, CONCAT('material:', HEX(representative.id)))
       ))
       OR NOT (existing.updated_at <=> (
            SELECT MAX(grouped.upload_date)
              FROM materials grouped
             WHERE COALESCE(grouped.upload_group_id, CONCAT('material:', HEX(grouped.id)))
                 = COALESCE(representative.upload_group_id, CONCAT('material:', HEX(representative.id)))
       ))
  );

-- One resource is created per deterministic upload group. Ungrouped legacy rows each
-- become one resource. The smallest material ID is the stable resource ID for a group.
INSERT INTO resources
    (id, owner_id, title, description, document_type, university_id, department_id,
     course_id, subject_id, semester, visibility, publication_status,
     moderation_status, current_version_number, created_at, updated_at)
SELECT lm.proposed_resource_id,
       representative.user_id,
       representative.title,
       representative.description,
       CASE WHEN COUNT(DISTINCT LOWER(grouped.type)) = 1
            THEN MIN(LOWER(grouped.type)) ELSE 'mixed' END,
       representative.university_id,
       representative.department_id,
       representative.course_id,
       representative.subject_id,
       representative.semester,
       representative.visibility,
       'published',
       CASE representative.status
           WHEN 'published' THEN 'approved'
           WHEN 'pending' THEN 'pending'
           WHEN 'rejected' THEN 'rejected'
           ELSE 'legacy_review'
       END,
       1,
       MIN(grouped.upload_date),
       MAX(grouped.upload_date)
  FROM legacy_material_migrations lm
  JOIN materials representative ON representative.id = lm.proposed_resource_id
  JOIN legacy_material_migrations group_map
    ON group_map.proposed_resource_id = lm.proposed_resource_id
   AND group_map.migration_status = 'eligible'
  JOIN materials grouped ON grouped.id = group_map.material_id
 WHERE lm.migration_status = 'eligible'
 GROUP BY lm.proposed_resource_id, representative.user_id, representative.title,
          representative.description, representative.university_id,
          representative.department_id, representative.course_id,
          representative.subject_id, representative.semester,
          representative.visibility, representative.status
ON DUPLICATE KEY UPDATE id = VALUES(id);

-- Apply the same retry rule to the source-derived version identity and the unique
-- (resource_id, version_number) slot before any file can be attached.
UPDATE legacy_material_migrations lm
JOIN resources expected_resource ON expected_resource.id = lm.proposed_resource_id
JOIN resource_versions existing_version
  ON existing_version.id = expected_resource.id
  OR (existing_version.resource_id = expected_resource.id AND existing_version.version_number = 1)
SET lm.proposed_resource_id = NULL,
    lm.resource_id = NULL,
    lm.migration_status = 'review_required',
    lm.reason_code = 'resource_version_identity_conflict'
WHERE lm.migration_status = 'eligible'
  AND (
       existing_version.id <> expected_resource.id
       OR existing_version.resource_id <> expected_resource.id
       OR existing_version.version_number <> 1
       OR existing_version.created_by <> expected_resource.owner_id
       OR NOT (BINARY existing_version.change_description
               <=> BINARY 'Imported deterministically from the legacy material model.')
       OR existing_version.lifecycle_status <> 'current'
       OR NOT (existing_version.created_at <=> expected_resource.created_at)
  );

-- A deterministic legacy conversion has exactly one version. Extra pre-existing
-- versions mean this occupied resource identity is not a partial migration retry.
UPDATE legacy_material_migrations lm
JOIN resource_versions unexpected_version
  ON unexpected_version.resource_id = lm.proposed_resource_id
 AND (unexpected_version.id <> lm.proposed_resource_id
      OR unexpected_version.version_number <> 1)
SET lm.proposed_resource_id = NULL,
    lm.resource_id = NULL,
    lm.migration_status = 'review_required',
    lm.reason_code = 'resource_version_identity_conflict'
WHERE lm.migration_status = 'eligible';

INSERT INTO resource_versions
    (id, resource_id, version_number, created_by, change_description, lifecycle_status, created_at)
SELECT r.id, r.id, 1, r.owner_id, 'Imported deterministically from the legacy material model.', 'current', r.created_at
  FROM resources r
  JOIN legacy_material_migrations lm ON lm.proposed_resource_id = r.id
 WHERE lm.migration_status = 'eligible'
 GROUP BY r.id, r.owner_id, r.created_at
ON DUPLICATE KEY UPDATE id = VALUES(id);

-- Preflight every member against all normalized file identities that could satisfy a
-- unique key. If one member conflicts, the complete effective upload group returns to
-- review so a retry can never report a partial multi-file conversion as deterministic.
UPDATE legacy_material_migrations lm
JOIN legacy_material_migrations file_map
  ON file_map.proposed_resource_id = lm.proposed_resource_id
 AND file_map.migration_status = 'eligible'
JOIN materials source_file ON source_file.id = file_map.material_id
JOIN resource_versions expected_version
  ON expected_version.resource_id = lm.proposed_resource_id
 AND expected_version.version_number = 1
JOIN resource_files existing_file
  ON existing_file.id = source_file.id
  OR existing_file.storage_key = source_file.file_path
  OR (existing_file.resource_version_id = expected_version.id
      AND existing_file.checksum_sha256 = source_file.checksum_sha256)
SET lm.proposed_resource_id = NULL,
    lm.resource_id = NULL,
    lm.migration_status = 'review_required',
    lm.reason_code = 'resource_file_identity_conflict'
WHERE lm.migration_status = 'eligible'
  AND (
       existing_file.id <> source_file.id
       OR existing_file.resource_version_id <> expected_version.id
       OR NOT (BINARY existing_file.original_filename <=> BINARY source_file.original_filename)
       OR NOT (BINARY existing_file.storage_key <=> BINARY source_file.file_path)
       OR NOT (BINARY existing_file.extension <=> BINARY LOWER(source_file.type))
       OR NOT (BINARY existing_file.mime_type <=> BINARY source_file.mime_type)
       OR existing_file.file_size <> source_file.file_size
       OR NOT (BINARY existing_file.checksum_sha256 <=> BINARY source_file.checksum_sha256)
       OR existing_file.scan_status <> 'not_scanned'
       OR existing_file.processing_status <> 'ready'
       OR existing_file.storage_status <> 'available'
       OR existing_file.quarantine_token IS NOT NULL
       OR NOT (existing_file.created_at <=> source_file.upload_date)
  );

-- Likewise, a partial rerun may contain only files sourced by this effective upload
-- group. An extra file already attached to the deterministic version is incompatible.
UPDATE legacy_material_migrations lm
JOIN resource_versions expected_version
  ON expected_version.resource_id = lm.proposed_resource_id
 AND expected_version.version_number = 1
JOIN resource_files existing_version_file
  ON existing_version_file.resource_version_id = expected_version.id
LEFT JOIN legacy_material_migrations expected_file_map
  ON expected_file_map.material_id = existing_version_file.id
 AND expected_file_map.proposed_resource_id = lm.proposed_resource_id
 AND expected_file_map.migration_status = 'eligible'
SET lm.proposed_resource_id = NULL,
    lm.resource_id = NULL,
    lm.migration_status = 'review_required',
    lm.reason_code = 'resource_file_identity_conflict'
WHERE lm.migration_status = 'eligible'
  AND expected_file_map.material_id IS NULL;

INSERT INTO resource_files
    (id, resource_version_id, original_filename, storage_key, extension, mime_type,
     file_size, checksum_sha256, scan_status, processing_status, storage_status, created_at)
SELECT m.id, rv.id, m.original_filename, m.file_path, LOWER(m.type), m.mime_type,
       m.file_size, m.checksum_sha256, 'not_scanned', 'ready', 'available', m.upload_date
  FROM legacy_material_migrations lm
  JOIN materials m ON m.id = lm.material_id
  JOIN resource_versions rv
    ON rv.resource_id = lm.proposed_resource_id AND rv.version_number = 1
 WHERE lm.migration_status = 'eligible'
ON DUPLICATE KEY UPDATE id = VALUES(id);

UPDATE legacy_material_migrations lm
JOIN resource_versions rv ON rv.resource_id = lm.proposed_resource_id AND rv.version_number = 1
JOIN resource_files rf ON rf.id = lm.material_id AND rf.resource_version_id = rv.id
SET lm.resource_id = lm.proposed_resource_id,
    lm.migration_status = 'migrated',
    lm.reason_code = NULL,
    lm.migrated_at = COALESCE(lm.migrated_at, CURRENT_TIMESTAMP)
WHERE lm.migration_status = 'eligible';

UPDATE legacy_material_migrations
SET proposed_resource_id = NULL,
    resource_id = NULL,
    migration_status = 'review_required',
    reason_code = 'conversion_incomplete'
WHERE migration_status = 'eligible';

-- Favorites are copied only when their source material has one deterministic resource.
-- Source favorite rows remain available for review and rollback.
INSERT IGNORE INTO resource_favorites (user_id, resource_id, favorited_at)
SELECT mf.user_id, lm.resource_id, mf.favorited_at
  FROM material_favorites mf
  JOIN legacy_material_migrations lm ON lm.material_id = mf.material_id
 WHERE lm.migration_status = 'migrated' AND lm.resource_id IS NOT NULL;

INSERT INTO legacy_favorite_migrations
    (material_favorite_id, resource_favorite_id, migration_status, reason_code)
SELECT mf.id, rf.id, 'migrated', NULL
  FROM material_favorites mf
  JOIN legacy_material_migrations lm
    ON lm.material_id = mf.material_id AND lm.migration_status = 'migrated'
  JOIN resource_favorites rf
    ON rf.user_id = mf.user_id AND rf.resource_id = lm.resource_id
ON DUPLICATE KEY UPDATE
    resource_favorite_id = VALUES(resource_favorite_id),
    migration_status = 'migrated',
    reason_code = NULL,
    assessed_at = CURRENT_TIMESTAMP;

INSERT IGNORE INTO legacy_favorite_migrations
    (material_favorite_id, resource_favorite_id, migration_status, reason_code)
SELECT mf.id, NULL, 'review_required', 'source_material_not_deterministic'
  FROM material_favorites mf
  LEFT JOIN legacy_material_migrations lm ON lm.material_id = mf.material_id
 WHERE lm.material_id IS NULL OR lm.migration_status <> 'migrated';
