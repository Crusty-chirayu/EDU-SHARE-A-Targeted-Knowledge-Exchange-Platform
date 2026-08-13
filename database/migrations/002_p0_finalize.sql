-- Run only after scripts/migrate_legacy_uploads.php reports that every material was migrated.
START TRANSACTION;

ALTER TABLE materials
    MODIFY type VARCHAR(10) NOT NULL,
    MODIFY file_path VARCHAR(80) NOT NULL COMMENT 'Opaque randomized storage key',
    MODIFY original_filename VARCHAR(180) NOT NULL,
    MODIFY mime_type VARCHAR(150) NOT NULL,
    MODIFY file_size BIGINT UNSIGNED NOT NULL,
    MODIFY checksum_sha256 CHAR(64) NOT NULL,
    MODIFY university_id INT NOT NULL,
    MODIFY department_id INT NOT NULL,
    MODIFY course_id INT NOT NULL,
    MODIFY subject_id INT NOT NULL,
    MODIFY semester TINYINT UNSIGNED NOT NULL,
    ADD UNIQUE KEY materials_storage_key_unique (file_path),
    ADD KEY materials_owner_checksum_idx (user_id, checksum_sha256),
    ADD KEY materials_discovery_idx (university_id, department_id, course_id, subject_id, semester, status);

-- Replace the historical cascading material constraints so taxonomy/account deletion cannot orphan private files.
ALTER TABLE materials
    DROP FOREIGN KEY materials_ibfk_1,
    DROP FOREIGN KEY materials_ibfk_2,
    ADD CONSTRAINT materials_course_p0_fk FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE RESTRICT,
    ADD CONSTRAINT materials_user_p0_fk FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    ADD CONSTRAINT materials_university_p0_fk FOREIGN KEY (university_id) REFERENCES universities (id) ON DELETE RESTRICT,
    ADD CONSTRAINT materials_department_p0_fk FOREIGN KEY (department_id) REFERENCES departments (id) ON DELETE RESTRICT,
    ADD CONSTRAINT materials_subject_p0_fk FOREIGN KEY (subject_id) REFERENCES subjects (id) ON DELETE RESTRICT;

COMMIT;
