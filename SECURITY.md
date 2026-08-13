# Security policy

## Supported scope

This repository supports the Phase 0 frameworkless PHP security foundation plus the additive P1.1 modular-monolith boundary documented in `docs/architecture/`. Security fixes must preserve both legacy routes and the versioned modular route. P1.2 Resource/ResourceFile normalization and framework migration remain out of scope for this checkpoint.

## Reporting a vulnerability

Please do not open a public issue containing exploit details, private records, credentials, storage keys, or uploaded files. Use GitHub's **Security → Report a vulnerability** private-reporting flow for this repository when available. Include:

- the affected route and revision;
- minimal, synthetic reproduction steps;
- expected versus observed authorization behavior;
- impact and any suggested mitigation; and
- confirmation that testing used accounts and data you were authorized to access.

Do not test against third-party or production installations without written permission. Do not retain or redistribute private material discovered during research.

## Deployment checklist

Before production use:

- [ ] Run supported, patched PHP and MariaDB releases over TLS.
- [ ] Set `APP_ENV=production`, `APP_DEBUG=false`, and a non-default session name.
- [ ] Supply a least-privilege database user with a strong unique secret; never use `root` or a blank password.
- [ ] Keep `.env` mode `0600`, or preferably inject secrets through PHP-FPM/service configuration.
- [ ] Put `STORAGE_PATH` outside the document root, writable only by the PHP worker and backup operator.
- [ ] Confirm the Apache deny rules or equivalent Nginx rules block `.env`, dotfiles, `app/`, `routes/`, `views/`, `includes/`, `database/`, `scripts/`, `storage/`, `tests/`, and `deploy/`.
- [ ] Disable directory listing and PHP/script execution in every writable directory.
- [ ] Configure HTTPS at the web server. Set `TRUST_PROXY=true` only behind a trusted proxy that overwrites `X-Forwarded-Proto`, so session cookies are Secure without trusting client-supplied forwarding headers.
- [ ] Set web-server and PHP request/upload limits at least as strict as `UPLOAD_MAX_FILES` and `UPLOAD_MAX_BYTES`.
- [ ] Import `database/schema.sql`, use only synthetic fixtures, and bootstrap privileged accounts through the CLI.
- [ ] Run PHP lint, static tests, `tests/architecture.php`, integration tests, and HTTP smoke tests against a disposable database.
- [ ] Inspect `php scripts/migrate.php status`, back up/restore-test the database, and run only reviewed forward migrations as a temporary schema-owner account.
- [ ] Verify student, teacher, moderator, and admin positive/negative authorization paths with synthetic accounts.
- [ ] Verify private/authenticated/public material visibility and owner/moderator/admin deletion behavior.
- [ ] Schedule database, private-file, and log backups; test a complete restore, including checksums.
- [ ] Protect and rotate logs; schedule cleanup of old `login_attempts` rows.
- [ ] Configure monitoring for repeated 401/403/419/429 responses, upload failures, missing storage objects, and database errors without logging raw secrets or request bodies.

## Security design notes

- Public registration is limited to students and teachers/contributors.
- State changes require POST and centralized CSRF validation.
- Files are addressed externally by material ID and internally by random opaque keys.
- Authorization is checked before storage resolution or streaming.
- Profile pages expose affiliation and role but not contact details.
- Error responses do not contain SQL, paths, stack traces, credentials, or raw exception messages.

Operational security remains the deployer's responsibility. Use separate credentials and storage for development, test, and production.
