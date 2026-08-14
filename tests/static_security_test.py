from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def read(relative: str) -> str:
    return (ROOT / relative).read_text(encoding="utf-8")


def php_files() -> list[Path]:
    return sorted(
        path
        for path in ROOT.rglob("*.php")
        if ".git" not in path.parts
    )


class StaticSecurityTests(unittest.TestCase):
    def test_literal_php_require_targets_exist(self) -> None:
        pattern = re.compile(r"require(?:_once)?\s+(?:__DIR__\s*\.\s*)?['\"]([^'\"]+)['\"]")
        missing: list[str] = []
        for source in php_files():
            for target in pattern.findall(source.read_text(encoding="utf-8")):
                candidate = (source.parent / target.lstrip('/')).resolve()
                if not candidate.is_file():
                    missing.append(f"{source.relative_to(ROOT)} -> {target}")
        self.assertEqual([], missing)

    def test_static_internal_route_targets_exist(self) -> None:
        route_pattern = re.compile(r"app_url\(\s*['\"]([A-Za-z0-9_./-]+\.php)")
        missing: set[str] = set()
        for source in php_files():
            for target in route_pattern.findall(source.read_text(encoding="utf-8")):
                if not (ROOT / target).is_file():
                    missing.add(target)
        self.assertEqual(set(), missing)

    def test_mutation_endpoints_are_post_only_and_csrf_protected(self) -> None:
        routes = [
            "logout.php",
            "process_upload.php",
            "delete_material.php",
            "update_resource.php",
            "toggle_favorite.php",
            "toggle_university_favorite.php",
        ]
        for route in routes:
            source = read(route)
            self.assertIn("require_method('POST')", source, route)
            self.assertIn("require_csrf", source, route)
        self.assertNotIn("$_GET", read("delete_material.php"))
        self.assertNotIn("$_GET", read("process_upload.php"))
        self.assertIn("request_method() === 'POST'", read("admin.php"))
        self.assertIn("require_csrf", read("admin.php"))

    def test_authentication_and_registration_are_csrf_protected(self) -> None:
        for route in ("login1.php", "register.php"):
            source = read(route)
            self.assertIn("request_method() === 'POST'", source)
            self.assertIn("require_csrf()", source)
            self.assertIn("csrf_field()", source)
        registration = read("register.php")
        self.assertNotRegex(registration, r"option\s+value=[\"'](?:admin|moderator)")
        self.assertIn('name="course_id"', registration)
        self.assertNotIn('name="branch"', registration)
        self.assertIn("['student', 'teacher']", read("includes/services.php"))
        self.assertIn("course_id, branch, year", read("includes/services.php"))

    def test_role_matrix_is_centralized_and_fail_closed(self) -> None:
        source = read("includes/auth.php")
        self.assertIn("'upload_resource' => ['teacher', 'moderator', 'admin']", source)
        self.assertIn("'upload_material' => ['teacher', 'moderator', 'admin']", source)
        self.assertIn("'manage_academics' => ['admin']", source)
        self.assertIn("'delete_any_resource' => ['moderator', 'admin']", source)
        self.assertIn("'delete_any_material' => ['moderator', 'admin']", source)
        self.assertIn("isset($matrix[$ability])", source)
        self.assertIn("require_ability('manage_academics')", read("admin.php"))

    def test_download_is_id_based_and_storage_is_not_client_controlled(self) -> None:
        source = read("download.php")
        self.assertIn("positive_int($_GET['id']", source)
        self.assertIn("positive_int($_GET['file_id']", source)
        self.assertIn("find_resource_file($resourceId, $fileId)", source)
        self.assertIn("can_view_resource", source)
        self.assertIn("resource_storage()->openReadStream", source)
        self.assertIn("hash_update_stream", source)
        self.assertNotIn("safe_storage_path", source)
        private_adapter = read("app/Modules/Resources/Infrastructure/PrivateResourceStorage.php")
        self.assertIn("safe_storage_path", private_adapter)
        self.assertNotRegex(
            source,
            r"\$_(?:GET|POST|REQUEST)\[['\"](?:path|file|filename|file_path|storage_key)['\"]\]",
        )
        detail = read("resource.php")
        self.assertIn("download.php?id=", detail)
        self.assertNotIn("storage_key", detail)
        storage = read("includes/storage.php")
        self.assertIn("/^[a-f0-9]{32}", storage)
        self.assertIn("realpath", storage)
        self.assertIn("is_link", storage)
        self.assertIn("random_bytes(16)", storage)

    def test_upload_has_layered_validation_atomicity_and_cleanup(self) -> None:
        storage = read("includes/storage.php")
        handler = read("process_upload.php")
        for marker in ("UPLOAD_ERR_OK", "finfo", "upload_magic_matches", "is_uploaded_file", "filesize", "hash_file('sha256'", "move_uploaded_file"):
            self.assertIn(marker, storage)
        self.assertIn("app_config('upload.max_files')", handler)
        self.assertIn("app_config('upload.max_bytes')", handler)
        self.assertIn("validate_academic_relationships", handler)
        self.assertIn("create_resource_service()->execute", handler)
        self.assertIn("add_resource_version_service()->execute", handler)
        self.assertNotIn("$_POST['branch_for']", handler)
        for service_path in (
            "app/Modules/Resources/Application/CreateResource.php",
            "app/Modules/Resources/Application/AddResourceVersion.php",
        ):
            service = read(service_path)
            self.assertIn("$this->repository->begin()", service)
            self.assertIn("$this->repository->rollback()", service)
            self.assertIn("if (!$this->storage->remove($storageKey))", service)
            self.assertIn("throw new ResourceCleanupFailed", service)
            self.assertLess(
                service.index("$storedKeys[] = $storageKey"),
                service.index("$this->storage->putVerifiedUpload"),
            )
        self.assertIn("catch (EduShare\\Modules\\Resources\\Application\\ResourceCleanupFailed", handler)
        self.assertIn("cleanup_failure_count", handler)

    def test_favorite_apis_share_json_post_contract(self) -> None:
        contracts = (
            ("toggle_favorite.php", "resource_id"),
            ("toggle_university_favorite.php", "university_id"),
        )
        for route, id_field in contracts:
            source = read(route)
            self.assertIn("require_method('POST')", source)
            self.assertIn("application/json", source)
            self.assertIn("request_data()", source)
            self.assertIn("require_csrf($data)", source)
            self.assertIn(f"$data['{id_field}']", source)
            self.assertIn("json_response", source)
        self.assertIn("$data['material_id']", read("toggle_favorite.php"))

    def test_no_unsafe_dom_html_sinks_or_inline_event_handlers(self) -> None:
        javascript = read("assets/app.js")
        self.assertNotIn("innerHTML", javascript)
        self.assertNotIn("outerHTML", javascript)
        self.assertNotIn("insertAdjacentHTML", javascript)
        self.assertNotIn("document.write", javascript)
        self.assertNotRegex(javascript, r"\beval\s*\(")
        self.assertIn("textContent", javascript)
        for source in php_files():
            if source.relative_to(ROOT).parts[0] == "tests":
                continue
            text = source.read_text(encoding="utf-8")
            self.assertNotRegex(text, r"\bon(?:click|load|error|mouseover)\s*=", str(source.relative_to(ROOT)))
            self.assertNotRegex(text, r"<\?=\s*\$_(?:GET|POST|REQUEST|SERVER|COOKIE)", str(source.relative_to(ROOT)))

    def test_profile_defaults_do_not_query_private_fields(self) -> None:
        source = read("teacher_profile.php")
        profile_query = source.split("$materialsStatement", 1)[0]
        for private_column in ("gmail", "contact", "address", "roll_number", "branch", "year"):
            self.assertNotRegex(profile_query, rf"u\.{private_column}\b")
        self.assertIn("$viewer = auth_user();", source)
        self.assertNotIn("$viewer = require_auth();", source)

    def test_sessions_and_error_responses_are_hardened(self) -> None:
        session = read("includes/session.php")
        for marker in ("session.use_strict_mode", "httponly", "samesite", "app_is_https()", "session_regenerate_id(true)", "_last_activity"):
            self.assertIn(marker, session)
        headers = read("includes/http.php")
        self.assertIn("script-src 'self'; style-src 'self'", headers)
        self.assertNotIn("'unsafe-inline'", headers)
        self.assertNotIn("cdn.tailwindcss.com", read("includes/layout.php"))
        error_handler = read("includes/error_handler.php")
        self.assertIn("display_errors', '0'", error_handler)
        self.assertNotIn("$exception->getMessage()", error_handler)
        self.assertNotIn("$exception->getTrace", error_handler)
        self.assertNotIn("$exception->getFile", error_handler)

    def test_environment_and_private_runtime_files_are_protected(self) -> None:
        self.assertTrue((ROOT / ".env.example").is_file())
        ignore = read(".gitignore")
        self.assertRegex(ignore, r"(?m)^\.env$")
        self.assertIn("!\.env.example".replace("\\", ""), ignore)
        self.assertIn("STORAGE_PATH", read(".env.example"))
        self.assertNotIn("DB_PASSWORD=", read("includes/config.php"))
        apache = read(".htaccess")
        self.assertIn("app|routes|views|includes|database|scripts|storage", apache)
        self.assertIn("Require all denied", read("storage/.htaccess"))
        nginx = read("deploy/nginx.conf.example")
        self.assertIn("location ~ ^/(?:app|routes|views|includes|database|scripts|storage|tests|deploy)", nginx)

    def test_database_artifacts_are_sanitized_normalized_and_separated(self) -> None:
        self.assertFalse((ROOT / "project.sql").exists())
        schema = read("database/schema.sql")
        fixture = read("database/seed_demo.sql")
        self.assertIn("CREATE TABLE users", schema)
        self.assertIn("branch_for", schema)
        self.assertIn("subjects_semester_check", schema)
        for table in ("resources", "resource_versions", "resource_files", "resource_favorites"):
            self.assertIn(f"CREATE TABLE {table}", schema)
        self.assertIn("UNIQUE KEY resource_versions_resource_number_unique (resource_id, version_number)", schema)
        self.assertIn("checksum_sha256 CHAR(64)", schema)
        self.assertIn("storage_key VARCHAR(80)", schema)
        self.assertIn("quarantine_token VARCHAR(80)", schema)
        self.assertIn("resource_files_quarantine_token_unique", schema)
        self.assertIn("ON DELETE RESTRICT", schema)
        self.assertIn(
            "legacy_favorite_migrations_resource_favorite_fk FOREIGN KEY (resource_favorite_id) REFERENCES resource_favorites (id) ON DELETE SET NULL",
            schema,
        )
        self.assertNotIn("INSERT INTO users", schema)
        fixture_statements = "\n".join(
            line for line in fixture.splitlines() if not line.lstrip().startswith("--")
        )
        self.assertNotRegex(fixture_statements, r"(?i)(?:gmail|password|contact|address|INSERT\s+INTO\s+users)")
        self.assertNotRegex(fixture_statements, r"[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}")

    def test_no_hardcoded_credentials_or_legacy_public_upload_links(self) -> None:
        combined = "\n".join(
            path.read_text(encoding="utf-8")
            for path in php_files()
            if path.relative_to(ROOT).parts[0] != "tests"
        )
        self.assertNotRegex(combined, r"new\s+mysqli\s*\(\s*['\"][^$]")
        self.assertNotRegex(combined, r"(?i)\$[A-Za-z_]*(?:password|passwd|pwd)[A-Za-z_]*\s*=\s*['\"][^'\"]{6,}['\"]")
        self.assertNotRegex(combined, r"(?:href|src)=[\"'][^\"']*uploads/")
        self.assertNotIn("project.sql", combined)

    def test_normalized_resource_pages_do_not_query_legacy_material_storage(self) -> None:
        active_resource_files = (
            "homepage.php", "dashboard.php", "favorites.php", "teacher_profile.php",
            "resource.php", "process_upload.php", "download.php", "delete_material.php",
            "toggle_favorite.php", "update_resource.php",
        )
        for path in active_resource_files:
            source = read(path)
            self.assertNotRegex(source, r"(?i)\b(?:FROM|INTO|UPDATE|JOIN)\s+materials\b", path)
            self.assertNotRegex(source, r"(?i)\b(?:FROM|INTO|UPDATE|JOIN)\s+material_favorites\b", path)
        for path in ("homepage.php", "dashboard.php", "favorites.php", "teacher_profile.php"):
            source = read(path)
            self.assertIn("resources r", source)
            self.assertIn("file_count", source)
            self.assertIn("resource.php?id=", source)

    def test_resource_application_layer_uses_ports_and_explicit_lifecycle(self) -> None:
        application_paths = (
            "app/Modules/Resources/Application/CreateResource.php",
            "app/Modules/Resources/Application/AddResourceVersion.php",
            "app/Modules/Resources/Application/UpdateResource.php",
            "app/Modules/Resources/Application/DeleteResource.php",
        )
        for path in application_paths:
            source = read(path)
            self.assertNotIn("mysqli", source, path)
            self.assertNotIn("$_GET", source, path)
            self.assertNotIn("$_POST", source, path)
        delete = read("app/Modules/Resources/Application/DeleteResource.php")
        for state in ("available", "cleanup_pending", "missing", "deleted"):
            self.assertIn(state, delete)
        self.assertIn("canRecoverDeletion", delete)
        self.assertIn("setFileQuarantine", delete)
        storage = read("includes/storage.php")
        self.assertIn("quarantine_token_for_storage_key", storage)
        self.assertIn("edu-share-resource-quarantine-v1", storage)
        self.assertIn("private_storage_entry_is_absent", storage)
        self.assertIn("if (quarantined_storage_path($token) !== null)", storage)
        self.assertIn("unsafe quarantine entry requires operator review", storage)
        policy = read("app/Modules/Resources/Domain/ResourceAccessPolicy.php")
        self.assertIn("deletion_status", policy)
        self.assertIn("publication_status", policy)
        self.assertIn("moderation_status", policy)
        self.assertIn("visibility", policy)
        create = read("app/Modules/Resources/Application/CreateResource.php")
        add_version = read("app/Modules/Resources/Application/AddResourceVersion.php")
        for operation in (create, add_version):
            self.assertIn("->lockOwner(", operation)
            self.assertLess(operation.index("->lockOwner("), operation.index("->assertFileSet("))
        repository = read("app/Modules/Resources/Infrastructure/MysqliResourceRepository.php")
        self.assertIn("SELECT id FROM users WHERE id = ? FOR UPDATE", repository)
        self.assertIn("$sql .= ' AND r.id <> ?'", repository)
        self.assertIn("if ($fileId !== null)", repository)
        self.assertIn("$sql .= ' AND rf.id = ?'", repository)
        self.assertNotIn("rf.storage_status = \\'available\\';", repository)

    def test_modular_route_preserves_auth_validation_and_data_boundaries(self) -> None:
        front_controller = read("app.php")
        self.assertIn("includes/bootstrap.php", front_controller)
        self.assertIn("ApplicationFactory::create()", front_controller)
        self.assertIn("ResponseEmitter", front_controller)
        self.assertNotIn("$_GET['_route']", front_controller)
        routes = read("routes/app.php")
        self.assertIn("/api/v1/universities/{universityId}/contributors", routes)
        self.assertIn("[$authenticated]", routes)
        validation = read("app/Modules/Profiles/Http/ContributorDirectoryInput.php")
        self.assertIn("/^[1-9][0-9]*$/D", validation)
        repository = read("app/Modules/Profiles/Infrastructure/MysqliContributorDirectoryRepository.php")
        self.assertIn("->prepare(", repository)
        self.assertIn("bind_param('i', $universityId)", repository)
        self.assertNotRegex(repository, r"SELECT[^;]*\{\$universityId\}")
        middleware = read("app/Modules/IdentityAccess/Http/RequireAuthenticated.php")
        self.assertIn("throw new HttpException(401", middleware)
        self.assertTrue((ROOT / "university_teachers.php").is_file())

    def test_forward_migrations_are_versioned_and_reject_destructive_sql(self) -> None:
        runner = read("app/Shared/Persistence/MigrationRunner.php")
        self.assertIn("schema_migrations", runner)
        self.assertIn("checksum_sha256", runner)
        self.assertIn("GET_LOCK", runner)
        self.assertIn("(?:DROP|TRUNCATE)", runner)
        self.assertIn("DELETE\\s+FROM", runner)
        self.assertNotIn("002_p0_finalize.sql", read("scripts/migrate.php"))
        migration_files = sorted((ROOT / "database/migrations/forward").glob("*.sql"))
        self.assertEqual(
            [
                "20260813120000_normalize_resources.sql",
                "20260814120000_govern_academic_taxonomy.sql",
            ],
            [path.name for path in migration_files],
        )
        for path in migration_files:
            self.assertRegex(path.name, r"^\d{14}_[a-z0-9_]+\.sql$")
            sql = path.read_text(encoding="utf-8")
            statements = re.sub(r"/\*.*?\*/|--[^\r\n]*", "", sql, flags=re.DOTALL)
            self.assertNotRegex(statements, r"(?i)\b(?:DROP|TRUNCATE)\b|\bDELETE\s+FROM\b")

    def test_resource_migration_is_non_destructive_and_audits_ambiguity(self) -> None:
        migration = read("database/migrations/forward/20260813120000_normalize_resources.sql")
        for table in (
            "resources", "resource_versions", "resource_files", "resource_favorites",
            "legacy_material_migrations", "legacy_favorite_migrations",
        ):
            self.assertIn(f"CREATE TABLE IF NOT EXISTS {table}", migration)
        self.assertIn("upload_group_ambiguous", migration)
        self.assertIn("academic_relationship_invalid", migration)
        self.assertIn("storage_metadata_invalid", migration)
        self.assertIn("storage_identity_ambiguous", migration)
        self.assertIn("upload_group_duplicate_content", migration)
        self.assertIn("resource_identity_conflict", migration)
        self.assertIn("resource_version_identity_conflict", migration)
        self.assertIn("resource_file_identity_conflict", migration)
        self.assertIn("existing_version.created_at <=> expected_resource.created_at", migration)
        self.assertIn("JOIN resource_versions unexpected_version", migration)
        self.assertIn("existing_file.created_at <=> source_file.upload_date", migration)
        self.assertIn("JOIN resource_files existing_version_file", migration)
        self.assertIn("expected_file_map.material_id IS NULL", migration)
        self.assertIn("JOIN resources existing ON existing.id = lm.proposed_resource_id", migration)
        self.assertIn("content_member.upload_group_id = m.upload_group_id", migration)
        self.assertIn("same_content.upload_group_id = content_member.upload_group_id", migration)
        self.assertIn("storage_member.upload_group_id, CONCAT('material:'", migration)
        self.assertIn("review_required", migration)
        self.assertIn("INSERT IGNORE INTO resource_favorites", migration)
        self.assertIn("ON DUPLICATE KEY UPDATE\n    resource_favorite_id = VALUES(resource_favorite_id)", migration)
        self.assertNotRegex(migration, r"(?i)\b(?:DROP|TRUNCATE)\s+(?:TABLE\s+)?materials\b")
        self.assertNotRegex(migration, r"(?i)\bDELETE\s+FROM\s+(?:materials|material_favorites)\b")

    def test_governed_taxonomy_schema_enforces_complete_academic_paths(self) -> None:
        schema = read("database/schema.sql")
        for table in ("universities", "departments", "courses", "subjects"):
            block = schema.split(f"CREATE TABLE {table} (", 1)[1].split(
                ") ENGINE=InnoDB", 1
            )[0]
            self.assertIn("is_active TINYINT(1) NOT NULL DEFAULT 1", block, table)
        self.assertIn("course_id INT UNSIGNED DEFAULT NULL", schema)
        for marker in (
            "departments_id_university_unique (id, university_id)",
            "courses_id_department_unique (id, department_id)",
            "subjects_complete_path_unique (id, course_id, department_id, semester)",
            "users_department_university_fk FOREIGN KEY (department_id, university_id)",
            "users_course_department_fk FOREIGN KEY (course_id, department_id)",
            "resources_department_university_fk FOREIGN KEY (department_id, university_id)",
            "resources_course_department_fk FOREIGN KEY (course_id, department_id)",
            "resources_subject_path_fk FOREIGN KEY (subject_id, course_id, department_id, semester)",
        ):
            self.assertIn(marker, schema)
        for table in (
            "legacy_user_academic_migrations",
            "academic_taxonomy_reviews",
            "academic_taxonomy_events",
        ):
            self.assertIn(f"CREATE TABLE {table}", schema)
        # MySQL/MariaDB requires signedness to match across each FK column pair.
        for identifier in (
            "university_id", "department_id", "course_id", "subject_id",
        ):
            declarations = re.findall(rf"(?m)^\s*{identifier}\s+([^,\n]+)", schema)
            self.assertTrue(declarations, identifier)
            self.assertTrue(
                all("INT UNSIGNED" in declaration for declaration in declarations),
                f"{identifier}: {declarations}",
            )

    def test_taxonomy_migration_is_additive_audited_and_never_guesses(self) -> None:
        migration = read(
            "database/migrations/forward/20260814120000_govern_academic_taxonomy.sql"
        )
        for marker in (
            "legacy_user_academic_migrations",
            "academic_taxonomy_reviews",
            "academic_taxonomy_events",
            "legacy_branch_no_exact_course",
            "legacy_branch_ambiguous",
            "subject_course_department_mismatch",
            "legacy_material_academic_path_invalid",
            "resource_academic_path_invalid",
            "BINARY LOWER(TRIM(candidate.name)) = BINARY LOWER(TRIM(u.branch))",
            "information_schema.COLUMNS",
            "information_schema.STATISTICS",
            "information_schema.TABLE_CONSTRAINTS",
        ):
            self.assertIn(marker, migration)
        statements = re.sub(r"/\*.*?\*/|--[^\r\n]*", "", migration, flags=re.DOTALL)
        self.assertNotRegex(
            statements,
            r"(?i)\b(?:DROP|TRUNCATE)\b|\bDELETE\s+FROM\b|FOREIGN_KEY_CHECKS",
        )
        self.assertNotRegex(migration, r"(?i)UPDATE\s+(?:materials|resources)\b")
        self.assertNotIn("UPDATE courses SET", migration)
        self.assertNotIn("UPDATE subjects SET", migration)

    def test_taxonomy_writes_are_admin_only_audited_and_non_destructive(self) -> None:
        admin = read("admin.php")
        self.assertIn("require_ability('manage_academics')", admin)
        self.assertIn("require_csrf()", admin)
        self.assertIn("academic_taxonomy_events", admin)
        self.assertIn("begin_transaction()", admin)
        self.assertIn("FOR UPDATE", admin)
        self.assertIn("is_active", admin)
        self.assertIn("Retire active child records first", admin)
        self.assertNotRegex(
            admin,
            r"(?i)DELETE\s+FROM\s+(?:universities|departments|courses|subjects)",
        )
        self.assertNotIn("action\" value=\"delete", admin)

    def test_dependent_selectors_and_writes_validate_active_parentage(self) -> None:
        selector_contracts = {
            "get_departments.php": ("university_id", "u.is_active = 1", "d.is_active = 1"),
            "get_courses.php": ("department_id", "d.is_active = 1", "c.is_active = 1"),
            "get_subjects.php": ("course_id", "c.is_active = 1", "s.is_active = 1"),
        }
        for path, markers in selector_contracts.items():
            source = read(path)
            self.assertIn("require_method('GET')", source)
            self.assertIn("->prepare(", source)
            for marker in markers:
                self.assertIn(marker, source, path)
        javascript = read("assets/app.js")
        for parameter in ("university_id=", "department_id=", "course_id=", "semester="):
            self.assertIn(parameter, javascript)
        validation = read("includes/validation.php")
        for marker in (
            "validate_academic_relationships",
            "validate_course_relationship",
            "validate_academic_filter_relationships",
            "u.is_active = 1",
            "d.is_active = 1",
            "c.is_active = 1",
            "s.is_active = 1",
        ):
            self.assertIn(marker, validation)
        repository = read(
            "app/Modules/Resources/Infrastructure/MysqliResourceRepository.php"
        )
        self.assertIn("lockActiveAcademicPath", repository)
        self.assertIn("FOR UPDATE", repository)
        create = read("app/Modules/Resources/Application/CreateResource.php")
        self.assertLess(
            create.index("->lockActiveAcademicPath("),
            create.index("->assertFileSet("),
        )

    def test_shared_view_foundations_escape_content_and_protect_post_forms(self) -> None:
        layout = read("includes/layout.php")
        for helper in (
            "render_header", "render_flash", "render_errors", "render_form_start",
            "render_form_end", "render_button", "render_card_start", "render_card_end",
        ):
            self.assertIn(f"function {helper}", layout)
        self.assertIn("csrf_field()", layout)
        self.assertIn("h($message)", layout)
        self.assertIn("h($label)", layout)
        self.assertIn("aria-label=\"Primary navigation\"", layout)

    def test_architecture_documentation_covers_required_boundaries_and_compatibility(self) -> None:
        adr = read("docs/architecture/ADR-001-incremental-modular-monolith.md")
        self.assertIn("Incremental Laravel modular monolith", adr)
        self.assertIn("Incrementally modularize the existing application (selected)", adr)
        self.assertIn("PHP **8.1+**", adr)
        modules = read("docs/architecture/modules.md")
        for module in (
            "Identity & Access", "Academic Taxonomy", "Resources", "File Ingestion",
            "Search & Discovery", "Collections", "Profiles", "Moderation",
            "Notifications", "Analytics", "AI Learning Services",
        ):
            self.assertIn(module, modules)
        strategy = read("docs/architecture/migration-strategy.md")
        self.assertIn("university_teachers.php", strategy)
        self.assertIn("P1.2", strategy)
        self.assertIn("without PHP, Composer, MariaDB/MySQL", strategy)
        migration_map = read("docs/architecture/migration-map.md")
        for path in ("process_upload.php", "includes/storage.php", "database/schema.sql", "tests/http_smoke.sh"):
            self.assertIn(path, migration_map)

    def test_bootstrap_and_canonical_routes_are_consistent(self) -> None:
        self.assertIn("includes/bootstrap.php", read("index.php"))
        mainhome = read("mainhome.php")
        self.assertIn("redirect('index.php', 301)", mainhome)
        legacy_library = read("lib.php")
        self.assertIn("$target = 'homepage.php'", legacy_library)
        self.assertIn("redirect($target, 302)", legacy_library)
        for source in php_files():
            if source.relative_to(ROOT).parts[0] in {"includes", "tests"}:
                continue
            text = source.read_text(encoding="utf-8")
            self.assertTrue(text.startswith("<?php\ndeclare(strict_types=1);"), str(source.relative_to(ROOT)))


if __name__ == "__main__":
    unittest.main(verbosity=2)
