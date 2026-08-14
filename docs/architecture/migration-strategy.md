# Compatibility, database, rollout, and test strategy

## Controlled migration sequence

1. **Preserve Phase 0 controls.** Session/cookie hardening, CSRF, fail-closed RBAC,
   canonical IDs, academic relationship checks, MIME/magic/size validation, private
   opaque-key storage, authorized downloads, escaping, safe errors, and environment
   configuration remain authoritative.
2. **Use P1.1 seams.** New behavior belongs behind `EduShare\\` domain/application
   ports and infrastructure adapters. Root routes remain compatibility entry points;
   no framework/runtime transition is coupled to data normalization.
3. **Apply schema before P1.2 code.** Back up database and private storage, run
   `php scripts/migrate.php status`, inspect review rules and capacity, then run
   `php scripts/migrate.php apply` as schema owner. The application must not receive
   P1.2 writes before the normalized tables exist.
4. **Verify conversion before traffic.** Count legacy source rows, migrated rows,
   review-required rows, resources, versions, files, and favorites. Every material is
   either deterministically mapped or review-required with a reason. Sample-check
   checksums/storage and complete academic paths. Do not “fix” ambiguity by guessing.
5. **Deploy normalized readers/writers.** Create, add-version, metadata-update,
   download, favorite, dashboard/library/profile reads, and logical deletion use
   stable resource IDs. `resource.php` provides retained history. Legacy route names
   remain where external compatibility requires them.
6. **Observe and recover forward.** Monitor generic application errors, storage
   quarantine/cleanup states and persisted recovery tokens, missing/integrity-failed
   objects, migration audit rows, and query behavior. Authorized deletion retry is
   idempotent for `pending_cleanup`; broader recovery repairs additive state from
   backups/audit and does not edit an applied migration or delete preserved source
   rows.
7. **Defer destructive retirement.** Dropping `materials`, removing legacy aliases, or
   deleting old source storage needs independent retention, usage, backup/restore,
   and operator approval. P1.2 does none of these.

The current phase remains a modular monolith with one database and private object
store. P1.3 additionally governs the existing university → department → course/program
→ subject/semester hierarchy; it does not create a speculative parallel Program or
Branch entity. Search, AI, recommendations, moderation workflows, notifications,
collections UI, microservices, and orchestration are not part of this sequence.

## Legacy and route compatibility

- `university_teachers.php` remains the authenticated legacy contributor-directory
  route beside `/api/v1/universities/{universityId}/contributors`.
- Root resource routes retain their deployed names. In particular,
  `delete_material.php` is a POST/CSRF compatibility route that now deletes a logical
  resource; it never accepts a path. `find_material`, material-named authorization
  abilities, and `material_id` input in `toggle_favorite.php` are temporary aliases
  whose value is interpreted only as a stable resource ID.
- Canonical browser links use `resource.php?id=<resource-id>` and
  `download.php?id=<resource-id>&file_id=<file-id>`. No browser contract contains a
  filename, legacy file path, or opaque storage key.
- `homepage.php`, `dashboard.php`, `favorites.php`, and `teacher_profile.php` read the
  normalized model and render one card/list item per resource with version/file
  counts. They do not query `materials`.
- Session keys, role semantics, HTTP statuses, CSRF header/form behavior, safe JSON,
  CSP, and escaped server-rendered HTML remain compatible.

## P1.2 database strategy

`database/schema.sql` is the canonical fresh-install snapshot and includes the
normalized model plus empty legacy tables for audit compatibility. It is not an
upgrade script. Historical `database/migrations/001_p0_prepare.sql` and
`002_p0_finalize.sql` remain frozen/manual compatibility artifacts.

The checksummed P1.1 runner applies
`database/migrations/forward/20260813120000_normalize_resources.sql`. The runner:

- discovers 14-digit versions in lexical order;
- records version/name/SHA-256 in `schema_migrations`;
- rejects edits to applied migration content and missing applied files;
- takes an advisory lock and requires explicit execution;
- rejects `DROP`, `TRUNCATE`, and `DELETE FROM` statements.

The P1.2 migration creates `resources`, `resource_versions`, `resource_files`,
`resource_favorites`, and two migration-audit tables without destroying legacy data.
Aggregate, taxonomy, and source-audit foreign keys use restrictive deletion; the
nullable favorite-audit target pointer alone uses `SET NULL` so unfavoriting retains
the source audit. The migration creates only deterministic mappings; review-required
source remains unchanged with a reason. Retry preflights source-derived resource,
version, file, storage, checksum, and deterministic timestamp identities. Existing
rows must form a compatible subset of the expected conversion; extra or incompatible
rows are left unchanged for review instead of receiving guessed relationships. MariaDB/MySQL DDL may
commit implicitly, so the SQL is ordered and rerunnable where practical, but backup
and restore rehearsal remain mandatory rather than claiming transactional DDL rollback.

Run `scripts/migrate_legacy_uploads.php` only in the historical P1.0/P1.1 sequence,
before installing normalized tables. It now fails closed if `resources` exists;
otherwise updating only `materials` would make normalized immutable metadata stale.

The detailed entity, relationship, visibility, versioning, storage, duplicate,
delete/cleanup, and ambiguous-conversion policies are in
[`resource-model.md`](resource-model.md).

## P1.3 taxonomy strategy

The next checksummed migration,
`database/migrations/forward/20260814120000_govern_academic_taxonomy.sql`, is additive.
It adds active/retired lifecycle state, canonical student `course_id`, composite path
keys/foreign keys, `legacy_user_academic_migrations`, `academic_taxonomy_reviews`, and
`academic_taxonomy_events`. Existing free-text branches and legacy resource/material
rows are preserved. Only a unique exact course-name match inside the user's valid
department is copied; every other student branch outcome remains review-required.

New registrations and resources validate and lock active parentage server-side.
Selectors are parent-scoped and omit retired records. Administrator mutations are
CSRF-protected, transactional, fixed-allowlist operations with an event record; there
is no taxonomy hard-delete operation. See
[`academic-taxonomy.md`](academic-taxonomy.md) for signedness requirements, ambiguity
reason codes, operator queries, and rollout details.

## Recovery and rollback posture

The database migration is non-destructive, but code rollback is not assumed to be a
complete data rollback after normalized writes begin: old code cannot interpret
multi-file/versioned resources. Before deployment, retain a synchronized database and
private-storage backup and rehearse restoration. During a failed rollout:

1. stop writes;
2. preserve database, active object storage, and quarantine exactly as observed;
3. inspect `schema_migrations`, migration audit tables, resource deletion states, and
   logs without changing source rows;
4. restore the pre-migration database and storage pair if old-code service must resume,
   or repair forward and redeploy P1.2;
5. never force a partial reverse by deleting normalized tables or rewriting checksums.

Ambiguous legacy rows are an operator queue, not an application failure. Resolution
requires evidence for storage identity and academic lineage and a separately reviewed
mapping operation; this phase does not provide a guessing workflow.

## Test strategy and validation limits

| Layer | P1.2 coverage |
|---|---|
| Dependency-free static | Phase 0 security controls; normalized schema/index/FK/state markers; forward-only SQL; migration ambiguity reasons; no active legacy material SQL; no path/key browser inputs; transaction/compensation structure |
| Dependency-free PHP architecture | Existing router/auth/validation/service boundaries plus Resource domain policy/value/use-case checks where PHP is available |
| MariaDB integration | Exact forward migration with deterministic multi-file and ambiguous groups/favorite mapping; multi-file create; metadata/checksum/key persistence; duplicate denial; stable version numbering/history; visibility/ownership; stable favorites; update and delete denial/success |
| HTTP behavior | Two-file create as one resource, stable detail/file IDs, add-version history, metadata update, favorite contract, visibility/download denial, CSRF/method/traversal denial, logical deletion, escaping |
| Hygiene | PHP lint when available, Bash syntax, Python compilation/tests, destructive SQL and raw-path scans, documentation checks, `git diff --check` |

P1.2 was developed in an environment **without PHP, Composer, MariaDB/MySQL**, or a
container runtime. Python/static and Bash syntax checks can execute here. PHP lint,
`tests/architecture.php`, `tests/integration.php`, the migration command, and HTTP
smoke tests must be run in a configured disposable PHP/database environment before
production. Their success must not be inferred from source review.
