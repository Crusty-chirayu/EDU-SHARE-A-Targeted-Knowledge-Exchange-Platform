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
        self.assertNotRegex(read("register.php"), r"option\s+value=[\"'](?:admin|moderator)")
        self.assertIn("['student', 'teacher']", read("includes/services.php"))

    def test_role_matrix_is_centralized_and_fail_closed(self) -> None:
        source = read("includes/auth.php")
        self.assertIn("'upload_material' => ['teacher', 'moderator', 'admin']", source)
        self.assertIn("'manage_academics' => ['admin']", source)
        self.assertIn("'delete_any_material' => ['moderator', 'admin']", source)
        self.assertIn("isset($matrix[$ability])", source)
        self.assertIn("require_ability('manage_academics')", read("admin.php"))

    def test_download_is_id_based_and_storage_is_not_client_controlled(self) -> None:
        source = read("download.php")
        self.assertIn("positive_int($_GET['id']", source)
        self.assertIn("can_view_material", source)
        self.assertIn("safe_storage_path", source)
        self.assertNotRegex(source, r"\$_(?:GET|POST|REQUEST)\[['\"](?:path|file|filename|file_path)")
        storage = read("includes/storage.php")
        self.assertIn("/^[a-f0-9]{32}", storage)
        self.assertIn("realpath", storage)
        self.assertIn("is_link", storage)
        self.assertIn("random_bytes(16)", storage)

    def test_upload_has_layered_validation_and_cleanup(self) -> None:
        storage = read("includes/storage.php")
        handler = read("process_upload.php")
        for marker in ("UPLOAD_ERR_OK", "finfo", "upload_magic_matches", "is_uploaded_file", "hash_file('sha256'", "move_uploaded_file"):
            self.assertIn(marker, storage)
        self.assertIn("app_config('upload.max_files')", handler)
        self.assertIn("app_config('upload.max_bytes')", handler)
        self.assertIn("validate_academic_relationships", handler)
        self.assertIn("@unlink($storedPath)", handler)
        self.assertNotIn("$_POST['branch_for']", handler)

    def test_favorite_apis_share_json_post_contract(self) -> None:
        for route, id_field in (
            ("toggle_favorite.php", "material_id"),
            ("toggle_university_favorite.php", "university_id"),
        ):
            source = read(route)
            self.assertIn("require_method('POST')", source)
            self.assertIn("application/json", source)
            self.assertIn("request_data()", source)
            self.assertIn("require_csrf($data)", source)
            self.assertIn(f"$data['{id_field}']", source)
            self.assertIn("json_response", source)

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
        self.assertIn("includes|database|scripts|storage", apache)
        self.assertIn("Require all denied", read("storage/.htaccess"))
        nginx = read("deploy/nginx.conf.example")
        self.assertIn("location ~ ^/(?:includes|database|scripts|storage|tests|deploy)", nginx)

    def test_database_artifacts_are_sanitized_and_separated(self) -> None:
        self.assertFalse((ROOT / "project.sql").exists())
        schema = read("database/schema.sql")
        fixture = read("database/seed_demo.sql")
        self.assertIn("CREATE TABLE users", schema)
        self.assertIn("branch_for", schema)
        self.assertIn("subjects_semester_check", schema)
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
