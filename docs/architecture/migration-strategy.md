# Compatibility, first slice, database, and test strategy

## Controlled migration sequence

This sequence is a safety order, not authorization to implement later phases.

1. **Freeze observed contracts.** Keep Phase 0 tests and document route methods,
   status codes, session roles, query/form/JSON names, schema assumptions, private
   storage behavior, and visibility policies.
2. **Add replaceable technical seams.** `app/autoload.php`, Shared HTTP objects,
   router/pipeline/kernel, safe response emission, route registry, Identity adapters,
   repository ports, and the explicit composition root are additive.
3. **Prove one read-only vertical slice.** Run the contributor directory through the
   modular stack while retaining `university_teachers.php`.
4. **Demonstrate parity.** Unit checks cover routing, auth, authorization, validation,
   service, and safe failures; MariaDB integration exercises the concrete repository;
   HTTP smoke checks call both new and legacy routes. Only then may callers opt into
   the versioned endpoint.
5. **Migrate later routes one at a time.** Put new SQL behind repositories and reuse
   Phase 0 policies through adapters. Keep old entry points as wrappers or unchanged
   routes until equivalent contracts and rollback are tested.
6. **Evolve the database separately.** Apply additive, backed-up, checksummed forward
   migrations before code that requires them; deploy compatible readers/writers;
   backfill with verification; enforce constraints later. Destructive cleanup is a
   separate, explicitly approved operation after rollback and retention needs expire.
7. **Consider dependency/framework adoption only from evidence.** Rehearse runtime,
   deployment, session/auth, and rollback before changing the compatibility kernel.

At every step the deployable state supports the old route. A new route can be removed
without changing its legacy counterpart. No P1.1 migration changes the domain schema.

## First vertical slice

**Use case:** list privacy-minimized contributors at one university.

**New route:** `GET /api/v1/universities/{universityId}/contributors`

**Legacy route retained:**
`GET /university_teachers.php?university_id={universityId}`

**Flow and concrete files:**

1. Apache/Nginx/`dev_router.php` sends `/api/v1/*` to `app.php`.
2. `Request::fromGlobals()` captures the method/path without exposing globals to the
   module. `routes/app.php` matches a named route parameter.
3. `RequireAuthenticated` obtains the existing session identity through
   `LegacySessionUserProvider`; anonymous requests receive safe JSON `401`.
4. `ContributorDirectoryInput` accepts only canonical positive decimal IDs (`422`).
5. `ListUniversityContributorsController` invokes
   `ListUniversityContributors`, mapping an unknown university to `404`.
6. The service depends on `ContributorDirectoryRepository`, validates the returned
   privacy-minimized role/read-model shape, and contains no SQL.
7. `MysqliContributorDirectoryRepository` uses prepared statements and selects only
   `id`, `full_name`, `user_type`, and `department_name`; it cannot return contact,
   credential, address, or raw storage data.
8. `Response::json()` emits a versioned `{ "data": [...] }` contract with no-store
   caching. `Kernel` converts route/validation errors and unexpected exceptions to
   safe responses; `ResponseEmitter` owns status/headers/output.

The endpoint is read-only and open to every authenticated role, matching the legacy
policy. `RequireAbility` and `LegacyAuthorizationGate` establish the reusable
capability boundary for later routes, but this use case does not invent a narrower
ability.

## Legacy and UI compatibility

- Root `.php` routes, methods, fields, redirects, session keys, and existing response
  formats remain available. The versioned API intentionally uses a data envelope;
  the legacy top-level array remains unchanged.
- `includes/bootstrap.php` remains the source of configuration, session/security
  headers, global error handling, database, CSRF, RBAC, validation, services, storage,
  and procedural layout during migration.
- `includes/layout.php` continues the shared header/navigation/footer and now exposes
  escaped flash/error components, CSRF-aware form opening, fixed-variant buttons, and
  card helpers. This is a reusable baseline, not a visual redesign.
- `assets/app.css` and `assets/app.js` remain unchanged. New views should prefer shared
  helpers and escaped output rather than adding inline scripts, event handlers, or
  untrusted HTML sinks.

## Database strategy

`database/schema.sql` remains the fresh-install Phase 0 schema. The historical
`database/migrations/001_p0_prepare.sql` and `002_p0_finalize.sql` remain manual
upgrade steps because they include deployment-specific legacy preparation and must
not be replayed automatically.

P1.1 adds a separate forward stream at `database/migrations/forward/` and
`scripts/migrate.php`:

- filenames carry a UTC-style 14-digit version and description;
- `schema_migrations` records version, name, SHA-256 checksum, and apply time;
- an advisory lock prevents concurrent runners;
- applied migration mutation is rejected;
- apply is explicit; there is no automatic request-time migration;
- `DROP`, `TRUNCATE`, and `DELETE FROM` are rejected;
- DDL implicit-commit behavior is documented, so backup/recovery is required rather
  than pretending every schema change can be rolled back transactionally.

The stream is intentionally empty in P1.1: the contributor slice reads the canonical
schema unchanged. No Resource/ResourceFile table, academic inference, data deletion,
or P1.2 normalization is present.

## Test strategy and validation limits

| Layer | Coverage |
|---|---|
| Dependency-free static | Existing Phase 0 controls plus front-controller auth/validation/repository boundaries, internal directory denial, and migration guardrails in `tests/static_security_test.py` |
| Dependency-free PHP unit/route | `tests/architecture.php` uses fakes for service, router, authentication, authorization, validation, status/header contracts, and safe errors |
| MariaDB integration | Existing `tests/integration.php` plus concrete contributor repository lookup against synthetic registered data |
| HTTP behavior/regression | Existing `tests/http_smoke.sh` plus anonymous/authenticated/invalid-method/invalid-ID modular calls and a legacy-route parity check |
| Deployment/static hygiene | PHP lint when available, shell syntax, Python compilation/tests, secret-pattern scan, destructive-migration scan, documentation link/reference checks, and `git diff --check` |

P1.1 was developed in an environment without PHP, Composer, MariaDB/MySQL, or a
container runtime. Python/static and shell-text checks can execute there; PHP lint,
`tests/architecture.php`, MariaDB integration, migration command, and HTTP smoke must
be run in the documented PHP/database environment before production deployment. No
runtime success is inferred from static inspection.

## Safe rollback

The new slice is additive. Disable the `/api/v1/` rewrite or stop clients using it and
the legacy route continues unchanged. P1.1 creates only the migration ledger when the
migration command is explicitly run; no domain table must be rolled back. A future
migration must state a tested recovery plan and preserve old-code compatibility for
the agreed deployment window.
