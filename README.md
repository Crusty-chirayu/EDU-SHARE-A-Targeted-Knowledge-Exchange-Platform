# EDU-SHARE

EDU-SHARE is a frameworkless, server-rendered PHP application for targeted academic material exchange. This repository implements **Phase 0: Secure Runnable Foundation** only. It deliberately keeps the existing PHP/MySQL architecture and does not include the proposed Phase 1 resource normalization or a frontend/framework rewrite.

## Runtime requirements

- PHP 8.1 or newer with `mysqli`, `fileinfo`, `json`, `mbstring`, and session support
- MariaDB 10.4+ (recommended) or a compatible MySQL 8 installation
- Apache 2.4 with `mod_rewrite`/`mod_headers`, Nginx + PHP-FPM, or PHP's development server
- TLS in production

No Composer or npm packages are required.

## New installation

1. **Create a least-privilege database account.** Do not use `root` or a blank password. For example, while signed in to MariaDB as a database administrator:

   ```sql
   CREATE DATABASE edu_share CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'edu_share_app'@'localhost' IDENTIFIED BY 'replace-with-a-strong-unique-password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON edu_share.* TO 'edu_share_app'@'localhost';
   FLUSH PRIVILEGES;
   ```

   The schema owner that runs installation additionally needs `CREATE`, `ALTER`, `INDEX`, `DROP`, and `REFERENCES`. Those privileges do not need to remain on the runtime account.

2. **Import the sanitized schema and optional synthetic academic fixture:**

   ```bash
   mysql -u schema_owner -p edu_share < database/schema.sql
   mysql -u schema_owner -p edu_share < database/seed_demo.sql  # optional
   ```

   `database/schema.sql` is canonical. It contains no users, password hashes, uploaded files, or private information. `database/seed_demo.sql` contains academic taxonomy only.

3. **Configure the application:**

   ```bash
   cp .env.example .env
   chmod 600 .env
   ```

   Replace every placeholder in `.env`. The built-in loader supports simple `NAME=value` entries and quoted values; host-provided environment variables take precedence. In production, prefer secrets supplied by PHP-FPM, the service manager, or the hosting platform instead of a file.

4. **Prepare private storage.** Set `STORAGE_PATH` to an absolute path outside the web document root, then grant the PHP worker read/write access and no broader access than required:

   ```bash
   sudo install -d -m 0750 -o www-data -g www-data /srv/edu-share-private
   ```

   If `STORAGE_PATH` is omitted, the fallback is a sibling directory named `edu-share-storage`, outside this repository. Uploaded files use randomized keys and mode `0640`; clients never submit or receive storage paths.

5. **Create the first administrator from the CLI.** First ensure the referenced university and department exist. Avoid placing the password in shell history; one approach in Bash is:

   ```bash
   read -r -s EDUSHARE_BOOTSTRAP_PASSWORD && export EDUSHARE_BOOTSTRAP_PASSWORD
   php scripts/create_admin.php admin@example.test 'Local Administrator' 1 1
   unset EDUSHARE_BOOTSTRAP_PASSWORD
   ```

   Public registration can create only `student` and `teacher` accounts. Moderator/admin assignment is intentionally unavailable over HTTP.

6. **Serve the application.** Point the production document root at this repository only after enabling the supplied deny rules. For local development:

   ```bash
   php -S 0.0.0.0:8000 dev_router.php
   ```

   Open `http://localhost:8000/`. Never use the PHP development server for production.

## Upgrading the historical database

The committed historical `project.sql` contained private-looking demo accounts, password hashes, contact data, stale duplicate tables, and public upload paths. It has been removed rather than preserved as a fixture.

For an existing deployment:

1. Back up the database and the complete legacy upload directory.
2. Configure `.env` and private `STORAGE_PATH`.
3. Run `database/migrations/001_p0_prepare.sql` as a schema owner.
4. Run `php scripts/migrate_legacy_uploads.php`. It copies recognized files from approved historical locations into private storage, validates extension and detected MIME, computes SHA-256 checksums, updates metadata, and deliberately leaves each source file untouched.
5. Resolve every reported missing/invalid file and each reported non-canonical academic relationship against the backup and the intended taxonomy. Re-run until both summaries report zero failures.
6. Run `database/migrations/002_p0_finalize.sql` (it targets the constraint names in the historical dump; inspect/adapt those names first if your deployed schema diverged).
7. Test authorized and unauthorized downloads before separately archiving or deleting legacy upload directories.

The migration is intentionally conservative. It does not silently discard data and does not grant historical teachers administrator access. Test a restored backup before changing production.

## Canonical routes

`index.php` is the canonical public entry point. `mainhome.php` permanently redirects to it. `homepage.php` is the authenticated material library; the legacy `lib.php` route redirects compatible filters to `homepage.php` rather than duplicating the implementation.

| Route | Methods | Access and purpose |
|---|---|---|
| `index.php` | GET | Public landing page |
| `login1.php` | GET, POST | Sign in; POST is CSRF-protected and rate-limited |
| `register.php` | GET, POST | Student/teacher registration with canonical academic validation |
| `logout.php` | POST | Authenticated, CSRF-protected sign out |
| `homepage.php` | GET | Authenticated material search/browse |
| `dashboard.php` | GET | Account summary and owned materials |
| `favorites.php` | GET | Saved materials and universities |
| `teacher_profile.php?id=<user-id>` | GET | Privacy-minimized public contributor profile |
| `upload.php` | GET | Contributor upload form |
| `process_upload.php` | POST | CSRF-protected contributor upload processing |
| `download.php?id=<material-id>` | GET | Authorized, ID-based file response |
| `delete_material.php` | POST | Owner/moderator/admin material deletion |
| `admin.php` | GET, POST | Admin-only academic taxonomy management |
| `toggle_favorite.php` | JSON POST | CSRF-protected material favorite toggle |
| `toggle_university_favorite.php` | JSON POST | CSRF-protected university favorite toggle |
| `get_universities.php`, `get_departments.php`, `get_courses.php`, `get_subjects.php` | GET JSON | Strictly validated dependent-selector data |
| `university_teachers.php` | GET JSON | Authenticated public staff-card fields only |

All state changes use POST. JSON mutation requests send `Content-Type: application/json` and an `X-CSRF-Token` header. Invalid methods return `405`; malformed requests and IDs return `400`/`422`; unauthenticated access returns `401` or redirects for HTML pages; authorization failures return `403`; missing resources return `404`; duplicate favorites remain idempotent through a single toggle operation.

## Role and visibility model

| Capability | Student | Teacher | Moderator | Admin |
|---|:---:|:---:|:---:|:---:|
| Browse/download visible material | Yes | Yes | Yes | Yes |
| Manage personal favorites | Yes | Yes | Yes | Yes |
| Upload material | No | Yes | Yes | Yes |
| Delete own material | No upload | Yes | Yes | Yes |
| Delete another user's material | No | No | Yes | Yes |
| Manage academic taxonomy | No | No | No | Yes |

Materials may be `public`, `authenticated`, or `private`, and only `published` material is discoverable to non-owners. Private material is limited to its owner and moderation roles. Public profile pages intentionally omit email, phone, roll number, and address.

## Security and operations

- Database credentials and runtime settings come from environment configuration; `.env` is ignored by Git.
- Sessions use strict mode, HttpOnly cookies, SameSite=Lax, HTTPS-aware Secure cookies, periodic ID rotation, login regeneration, and inactivity expiry.
- Centralized CSRF validation protects login, registration, logout, uploads, deletion, favorites, and admin mutations.
- Authorization is centralized by capability and repeated at the data boundary for downloads and deletion.
- Uploads are limited by count and size, extension, PHP upload status, Fileinfo MIME/magic detection, and approved extension/MIME pairs. Storage keys are random and original names are response metadata only.
- Downloads accept a positive material ID only. Opaque keys must match a strict pattern and resolve beneath private storage; traversal, absolute paths, separators, symlinks, and direct public access are rejected.
- SQL uses prepared statements. Academic IDs and relationships, profile/contact data, semesters/years, material metadata, and JSON structures receive server-side canonical validation.
- HTML is escaped by default. Shared JavaScript uses `textContent` and DOM constructors rather than untrusted `innerHTML`.
- User-facing errors are generic. Detailed exceptions are sent to the server error log only when safe to do so; `APP_DEBUG` never emits raw stack traces to clients.
- Login throttling stores a keyed digest rather than a raw IP/email pair. Schedule periodic cleanup, for example: `DELETE FROM login_attempts WHERE attempted_at < UTC_TIMESTAMP() - INTERVAL 2 DAY;`.

The root `.htaccess` blocks dotfiles and internal source/data directories for Apache. `deploy/nginx.conf.example` provides equivalent Nginx locations. These are defense in depth: production should still use a private storage path outside the document root, disable directory indexes, deny script execution in writable directories, terminate TLS, set a restrictive request-body limit, and keep PHP/MariaDB patched. Leave `TRUST_PROXY=false` unless every request reaches PHP through a trusted proxy that overwrites `X-Forwarded-Proto`; enable it behind such a proxy so HTTPS-aware session cookies remain Secure.

## Validation

Dependency-free static/security checks are in `tests/static_security_test.py`:

```bash
python3 -m unittest discover -s tests -p '*_test.py' -v
```

Runtime integration coverage requires PHP 8.2+ and a disposable MariaDB database initialized from the sanitized schema. A CI-ready GitHub Actions recipe is provided at `deploy/github-actions-p0-security.yml.example`; install it under `.github/workflows/` when repository policy permits. On a configured workstation, run:

```bash
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
php tests/integration.php
bash tests/http_smoke.sh
```

See `SECURITY.md` for reporting guidance and the deployment checklist. The application is a P0 foundation, not a completed Phase 1 redesign; operational backup/restore, mail-based account recovery, content moderation workflow, and normalized multi-resource records remain outside this phase.
