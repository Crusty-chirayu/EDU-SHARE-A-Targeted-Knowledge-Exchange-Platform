# Actual-file current-to-target responsibility map

This map began from the actual Phase 0 checkout, not an assumed framework layout, and
now records the implemented P1.1/P1.2 boundaries. “Later” still requires separate
scope authorization and parity tests. Legacy route names/source tables stay present
until deprecation, retention, data verification, and rollback requirements are met.

| Actual file(s) | Current responsibility | Target responsibility / migration boundary | Earliest wave |
|---|---|---|---|
| `.env.example` | Runtime setting names/placeholders | Shared Configuration contract; retain host-over-file precedence and no secrets | Preserve / P1.1 documented |
| `.gitignore` | Excludes runtime secrets/uploads | Repository hygiene; retain `.env` and generated/private-data denial | Preserve |
| `.htaccess` | Apache indexing/internal-file denial and security headers | Deployment adapter; deny `app/`, `routes/`, `views/` and dispatch only `/api/v1/` to `app.php` | P1.1 implemented |
| `deploy/nginx.conf.example` | Nginx equivalent deny/PHP rules | Deployment adapter with explicit `/api/v1/` front-controller location | P1.1 implemented |
| `dev_router.php` | Safe PHP development-server routing | Development deployment adapter; same internal denial and API dispatch as production examples | P1.1 implemented |
| `app.php` | Not present in Phase 0 | Central modular front controller: bootstrap, normalized route path, kernel, emitter | P1.1 implemented |
| `routes/app.php` | Not present in Phase 0 | Single modular route registry; route middleware visible at composition time | P1.1 implemented |
| `app/autoload.php` | Not present in Phase 0 | Dependency-free PSR-4-compatible `EduShare\\` loader; replaceable by Composer later | P1.1 implemented |
| `app/Shared/Http/Request.php` | Globals read in each entry script | Immutable request/query/body/header/route-attribute boundary | P1.1 implemented |
| `app/Shared/Http/Response.php`, `app/Shared/Http/ResponseEmitter.php` | `json_response`, `redirect`, direct echo/header | Testable response value and sole modular output emitter | P1.1 implemented |
| `app/Shared/Http/HttpException.php` | `AppHttpException`/`abort_request` | Safe modular status/message exception; never transports internal details | P1.1 implemented |
| `app/Shared/Http/RequestHandler.php`, `app/Shared/Http/Middleware.php`, `app/Shared/Http/CallableRequestHandler.php` | Inline handler sequencing | Minimal request pipeline contracts/adaptor | P1.1 implemented |
| `app/Shared/Routing/Route.php`, `app/Shared/Routing/Router.php`, `app/Shared/Routing/MiddlewarePipeline.php` | Web server selects one PHP script | Method/path matching, typed route parameters, `404`/`405`, ordered middleware | P1.1 implemented |
| `app/Shared/Kernel/Kernel.php`, `app/Shared/Kernel/ApplicationFactory.php` | `includes/bootstrap.php` plus per-file construction | Safe exception boundary and explicit composition root; legacy functions remain adapters | P1.1 implemented |
| `app/Shared/Persistence/MigrationPlanItem.php`, `app/Shared/Persistence/MigrationRunner.php` | Manual SQL files only | Checksummed forward migration ledger, lock, explicit apply, destructive-token guard | P1.1 implemented |
| `scripts/migrate.php` | Not present in Phase 0 | CLI-only status/apply entry point for new forward stream | P1.1 implemented |
| `database/migrations/forward/README.md`, `database/migrations/forward/20260813120000_normalize_resources.sql` | Not present in Phase 0 | Append-only migration policy plus the additive normalized schema/data conversion | P1.2 migration added through the P1.1 checksummed runner |
| `app/Modules/IdentityAccess/Domain/CurrentUser.php` | Session user array crosses all layers | Privacy-minimized authenticated identity value | P1.1 implemented |
| `app/Modules/IdentityAccess/Application/CurrentUserProvider.php`, `app/Modules/IdentityAccess/Application/AuthorizationGate.php` | Controllers call auth globals | Stable identity/capability ports | P1.1 implemented |
| `app/Modules/IdentityAccess/Infrastructure/LegacySessionUserProvider.php`, `app/Modules/IdentityAccess/Infrastructure/LegacyAuthorizationGate.php` | `auth_user()` and `role_can()` globals | Temporary adapters preserving Phase 0 session and fail-closed role semantics | P1.1 implemented |
| `app/Modules/IdentityAccess/Http/RequireAuthenticated.php`, `app/Modules/IdentityAccess/Http/RequireAbility.php` | Each legacy route calls `require_auth`/`require_ability` | Reusable explicit route middleware | P1.1 implemented |
| `app/Modules/Profiles/Application/ContributorDirectoryRepository.php`, `app/Modules/Profiles/Application/ListUniversityContributors.php`, `app/Modules/Profiles/Application/UniversityNotFound.php` | Inline route query/business behavior | Repository port and contributor-directory use case | P1.1 first slice |
| `app/Modules/Profiles/Http/ContributorDirectoryInput.php`, `app/Modules/Profiles/Http/ListUniversityContributorsController.php` | Legacy script validates `$_GET` and emits JSON | Route validation, controller mapping and versioned response | P1.1 first slice |
| `app/Modules/Profiles/Infrastructure/MysqliContributorDirectoryRepository.php` | SQL inside `university_teachers.php` | Prepared, privacy-minimized Profiles read adapter | P1.1 first slice |
| `includes/config.php` | `.env` loader, settings, base URL/HTTPS | Shared Configuration infrastructure adapter; keep authoritative until namespaced callers have parity | Preserve, adapt per slice |
| `includes/http.php` | Security headers, method/input/JSON response/abort/redirect helpers | Legacy HTTP compatibility; policies move to Shared HTTP without weakening CSP/status behavior | Preserve, incremental |
| `includes/error_handler.php` | Generic client errors and redacted structured logging | Shared Logging/error infrastructure; retain no stack trace/secrets response | Preserve, incremental |
| `includes/session.php` | Strict cookie/session lifecycle and flash storage | Identity & Access session adapter and UI flash store | Preserve, Identity migration later |
| `includes/database.php` | Lazy configured `mysqli` and allowlisted row lookup | Shared persistence connection factory; table-specific lookups move to owning repositories | P1.1 adapter, later replacement |
| `includes/auth.php` | Session identity, role matrix, route guards | Identity & Access policy/provider/gate; object policies remain in owning module | P1.1 adapter, later parity |
| `includes/csrf.php` | Token generation/matching/field/request guard | Identity & Access CSRF boundary/middleware for state-changing modular routes | Preserve; migrate with first command route |
| `includes/validation.php` | Canonical IDs, registration/material/taxonomy validation | Transport validators in owning module plus reusable value objects; relationship checks remain Taxonomy-owned | Incremental by route |
| `includes/services.php` | Registration, auth throttle, material/favorite/profile queries and policies | Split among Identity, Resources, Collections, Profiles; services depend on ports, not route globals | Incremental by use case |
| `includes/storage.php` | Upload validation plus opaque private-object storage/quarantine/restore/purge primitives | Resources `FileStorage` port via `PrivateResourceStorage`; File Ingestion policy remains in the entry/application boundary | Active P1.2 adapter; raw storage paths never cross HTTP contracts |
| `includes/layout.php` | Shared HTML shell, nav, escaped flash | Shared Views compatibility layout plus escaped error/form/button/card foundations | P1.1 foundation; page migration later |
| `includes/bootstrap.php` | Loads every procedural concern and starts session/security handlers | Legacy compatibility kernel; modular front controller also uses it until adapters cover all behavior | Preserve throughout controlled migration |
| `db_connect.php` | Historical database compatibility include | Temporary compatibility shim; remove only after repository-wide callers and external includes are audited | Later cleanup |
| `index.php` | Public landing page | Shared UI/public page controller/view; no module command | Later page slice; preserve |
| `mainhome.php` | Permanent legacy landing redirect | Compatibility redirect to canonical landing route | Preserve until external-traffic deprecation evidence |
| `login1.php`, `logout.php` | Sign-in/sign-out HTML handlers | Identity & Access controllers, validators, CSRF/session services and views | Later Identity slices; preserve |
| `register.php` | Public student/teacher registration | Identity account command coordinated with Profiles/Taxonomy ports; privileged roles still rejected | Later Identity slice; preserve |
| `admin.php` | Taxonomy CRUD HTML route | Academic Taxonomy admin controllers/services/repositories/views with `manage_academics` middleware | Later Taxonomy slices; preserve |
| `get_universities.php`, `get_departments.php`, `get_courses.php`, `get_subjects.php` | Public dependent-selector JSON | Academic Taxonomy read controllers/services/repositories | Later small read slices; preserve contracts |
| `university_teachers.php` | Authenticated contributor JSON, inline SQL | Profiles legacy compatibility route; keep while versioned modular endpoint proves parity | P1.1 retained |
| `teacher_profile.php` | Privacy-minimized profile plus normalized logical-resource list | Profiles view composed with Resources public read model | P1.2 normalized query active; later controller migration must preserve privacy and one-card-per-resource behavior |
| `dashboard.php` | Account summary and owned logical resources | Profiles account view composed with Resources owner read model | P1.2 normalized query active; controller/view extraction remains later scope |
| `homepage.php` | Visibility-aware browse/filter/resource cards with latest-version/file counts | Search & Discovery query/controller/view fed by Resources/Taxonomy read ports | P1.2 normalized read model active; full search remains later scope |
| `resource.php` | Stable-ID resource detail, complete numbered version/file history, and owner metadata form | Resources detail read model/view and update command | Active P1.2 boundary; download links expose only resource/file IDs |
| `update_resource.php` | Owner-only POST/CSRF metadata update | Resources `UpdateResource` application service and policy | Active P1.2 command; cannot mutate taxonomy, identity, files, or version history |
| `lib.php` | Legacy filter-preserving library redirect | Compatibility adapter to canonical discovery route | Preserve until deprecation evidence |
| `upload.php` | Multi-file initial-resource and add-version form | File Ingestion/Resources coordinated form boundary with explicit ability checks | Active P1.2 entry point using stable `resource_id` for versions |
| `process_upload.php` | CSRF multi-file validation and normalized create/add-version dispatch | File Ingestion intake plus Resources command with transaction/compensation boundaries | Active P1.2 entry point; all validated files form one numbered version atomically |
| `download.php` | Resource/file-ID authorization, integrity verification, and private stream | Resources authorization plus opaque `FileStorage` adapter | Active P1.2 route; never accepts or exposes a path/storage key |
| `delete_material.php` | Compatibility-named owner/moderator resource deletion route | Resources quarantine-first logical deletion with persisted recovery intent | Active P1.2 route; name retained only for transport compatibility |
| `favorites.php` | Saved logical-resource/university page | Collections query/controller/view via Resources/Taxonomy read ports | P1.2 resource-favorite read model active; wider Collections work remains later scope |
| `toggle_favorite.php`, `toggle_university_favorite.php` | CSRF JSON toggle commands | Collections controllers/services/repositories; retain idempotent and visibility/existence checks | Resource toggles now use stable `resource_id` (temporary `material_id` alias); university flow is unchanged |
| `assets/app.js` | Safe selector/favorite DOM behavior | Shared UI asset; module scripts only if needed, continue `textContent`/DOM construction | Preserve |
| `assets/app.css` | Committed generated utility CSS | Shared UI presentation baseline; build-tool decision separate from architecture | Preserve; no P1.1 redesign |
| `database/schema.sql` | Fresh-install canonical normalized schema with preserved empty legacy sources/audits | Installation snapshot only; upgrades use the forward runner | Updated for P1.2 after adding the append-only migration; never run as an upgrade |
| `database/seed_demo.sql` | Synthetic taxonomy fixture | Academic Taxonomy development fixture; no users/secrets | Preserve |
| `database/migrations/001_p0_prepare.sql`, `database/migrations/002_p0_finalize.sql` | One-time historical upgrade sequence | Frozen/manual compatibility migrations, excluded from new auto-discovery | Preserve |
| `scripts/migrate_legacy_uploads.php` | Conservative pre-normalization historical file preparation | File Ingestion operational compatibility tool | Guarded to fail closed after normalized tables exist; output does not disclose opaque keys |
| `scripts/create_admin.php` | CLI first-admin creation | Identity & Access privileged CLI command; never exposed over HTTP | Later adapter; preserve |
| `storage/.htaccess`, `storage/uploads/.htaccess`, `storage/uploads/.gitignore` | Defense-in-depth denial and placeholder | File Ingestion deployment controls; actual private storage remains outside document root | Preserve |
| `tests/static_security_test.py` | Dependency-free source/security checks | Phase 0/P1.1 regression plus P1.2 normalized schema, migration, query, lifecycle, and route guards | P1.2 extended, never removed |
| `tests/integration.php` | PHP/MariaDB migration, policy, storage, normalized aggregate, version, favorite, update, and deletion integration | Legacy regression plus concrete Resources repository/application integration | P1.2 extended; requires disposable MariaDB/MySQL runtime |
| `tests/http_smoke.sh` | End-to-end normalized multi-file/version/favorite/download/update/delete paths | Legacy-route compatibility plus normalized contract and parity checks | P1.2 extended |
| `tests/architecture.php` | Dependency-free modular unit/route/auth/authorization/service/safe-error checks | P1.1 contributor slice plus Resources policy/service/compensation/update/deletion checks | P1.2 extended |
| `tests/legacy_schema_fixture.sql` | Historical upgrade test fixture | Compatibility-only database fixture; never production data | Preserve |
| `README.md`, `SECURITY.md` | Runtime/operations/security contract | Project entry docs linking architecture and preserving deployment/reporting controls | P1.2 update / preserve |
| `docs/architecture/README.md`, `docs/architecture/ADR-001-incremental-modular-monolith.md`, `docs/architecture/resource-model.md`, `docs/architecture/modules.md`, `docs/architecture/migration-map.md`, `docs/architecture/migration-strategy.md` | Not present in Phase 0 | ADR, normalized model, module contract, file map, compatibility/database/test strategy | P1.2 reconciled |
| `deploy/github-actions-p0-security.yml.example` | Inactive CI recipe | Documentation-only reference; no active workflow change in P1.1 | Preserve |
| `docs/ci/phase-0.5-reference/.gitattributes`, `MANIFEST.txt`, `README.md`, `README.phase-0.5.md`, `p0-security.yml`, `phase-0.5.patch`, `static_security_test.phase-0.5.py` | Preserved inactive Phase 0.5 artifacts | Historical reference only, not active CI or source of current architecture | Preserve exactly |

## Removal rule

No legacy route is removed merely because a modular equivalent exists. Removal needs
observed parity, consumer migration/deprecation, production telemetry or an equivalent
usage audit, rollback documentation, data compatibility, and separate approval. The P1.2 checkpoint removes none.
