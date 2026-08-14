# ADR-001: incrementally modularize the existing PHP application

- **Status:** Accepted in P1.1; applied through P1.2
- **Date:** 2026-08-13
- **Decision scope:** Architecture foundation and incremental migration approach

## Context observed in this repository

The Phase 0 application is a compact server-rendered PHP monolith. Root `.php` files
are public entry points; `includes/bootstrap.php` composes procedural configuration,
HTTP/error handling, secure sessions, database access, authentication/RBAC, CSRF,
validation, storage, services, and layout helpers. Persistence uses prepared `mysqli`
statements against the schema in `database/schema.sql`. There is no Composer or npm
runtime dependency. Deployment is direct PHP through Apache, Nginx/PHP-FPM, or
`dev_router.php`.

This is not an unstructured prototype in every respect. `includes/auth.php`,
`includes/csrf.php`, `includes/session.php`, `includes/storage.php`, and
`includes/validation.php` contain security behavior already covered by
`tests/static_security_test.py`, `tests/integration.php`, and `tests/http_smoke.sh`.
Replacing those controls all at once would create more risk than extracting them
behind boundaries.

At the P1.1 decision point, each `materials` row represented both one learning item
and one physical file. Academic relationships included legacy ambiguity that required
operator resolution. P1.1 deliberately did not guess or normalize either concern.
P1.2 subsequently applied this ADR's ports-and-adapters approach to a non-destructive
Resource/Version/File migration; its rules are recorded in
[`resource-model.md`](resource-model.md).

## Options considered

### A. Incremental Laravel modular monolith

Install a maintained Laravel release, use its container/router/middleware,
authentication, validators, ORM/query builder, migrations, Blade, and test tooling;
mount legacy routes beside it and migrate slices.

Benefits are mature conventions, productive built-in facilities, broad testing
support, and a clear hiring/onboarding story. Costs in this repository are immediate:
a Composer dependency and framework bootstrap must become part of every deployment;
framework/session/auth semantics must be reconciled with Phase 0; the current PHP 8.1
floor may need to move depending on the selected maintained release; and two
application kernels would coexist before the first feature gains parity. PHP,
Composer, and MariaDB are unavailable in the P1.1 execution environment, so that new
runtime could not be validated here. Laravel remains a valid later option behind the
interfaces introduced by this decision, but a skeleton alone would not be a safe
architecture outcome.

### B. Incrementally modularize the existing application (selected)

Add dependency-free `EduShare\\` classes, a centralized front controller/router,
explicit middleware, ports and adapters, controllers, validation, services,
repositories, response/error boundaries, forward migrations, and tests. Keep every
legacy route while migrating one vertical slice at a time.

This provides enforceable seams now without changing deployment mechanics, session
keys, database layout, or the tested security core. The tradeoff is that EDU-SHARE
must maintain a small amount of routing/composition code and cannot immediately use a
framework ecosystem. The foundation is intentionally small and replaceable rather
than an attempt to recreate a general-purpose framework.

### C. Another mature PHP architecture/framework

A full Symfony, Slim, Laminas, or similar adoption was not separately justified for
P1.1. Each would introduce the same unvalidated package/deployment transition and
dual-bootstrap risk as option A, while the repository does not yet need a third-party
component to implement one route safely. The PSR-4-compatible directory and narrow
interfaces permit selected mature components later if an observed requirement—not
popularity—justifies them.

## Criteria assessment

Scores are relative for this repository at this checkpoint: 1 is weak/high risk and
5 is strong/low risk.

| Criterion | Incremental Laravel | Existing-app modularization | Reason for decision |
|---|---:|---:|---|
| Maintainability | 5 long-term / 2 during transition | 4 | Explicit modules improve structure without a second kernel; framework conventions remain a future option. |
| Security continuity | 3 | 5 | Existing CSRF, session, RBAC, upload/download, and escaping behavior stays authoritative. |
| Testability | 5 after migration / 2 now | 4 | Ports support unit fakes immediately and existing runtime suites continue unchanged. |
| Developer productivity | 5 after onboarding | 4 | Laravel generators/tools are stronger, but dependency/bootstrap work dominates this small first slice. |
| Deployment fit | 2 | 5 | Current hosts require no Composer vendor tree or changed document-root contract. |
| Future AI integration | 4 | 4 | Both support service ports/events; module boundaries matter more than framework choice. |
| Database evolution | 5 | 4 | Framework migrations are mature; the selected checksum/ledger runner is intentionally narrower but sufficient for controlled forward changes. |
| UI evolution | 5 | 4 | Blade is stronger; shared Phase 0 layout/components can evolve incrementally without replacing pages. |
| Scalability | 4 | 4 | Both can scale the modular monolith horizontally; database/query/storage design will dominate before router choice. |
| Migration risk | 2 | 5 | Selected approach keeps old routes and moves one read-only slice with no schema change. |
| Fit to project complexity | 3 | 5 | A compact no-dependency application does not yet justify a full framework runtime. |

## Decision

Use an incremental frameworkless modular monolith:

- namespace new code as `EduShare\\` under `app/`, loaded by `app/autoload.php`;
- keep PHP **8.1+** as the target until deployment inventory and a separately tested
  upgrade justify changing it;
- use one front controller (`app.php`), central route registry (`routes/app.php`),
  request/response/kernel abstractions, ordered middleware, and safe error responses;
- place domain/application/HTTP/infrastructure responsibilities under bounded module
  directories and depend on interfaces at boundaries;
- adapt the legacy `auth_user()`, `role_can()`, `db()`, configuration, logging, session,
  CSRF, storage, and escaping behavior rather than duplicating it;
- use the same MariaDB database during migration, while isolating query ownership in
  repositories;
- retain all legacy entry points until route-by-route parity is demonstrated;
- add only forward, checksummed, explicitly applied migrations. P1.1 did not alter
  the material/file model.

### P1.2 application of this decision

P1.2 keeps the same runtime and security adapters while adding the bounded
`Resources` domain/application/infrastructure layers. Active writes use logical
resources, numbered retained versions, and opaque file records. The forward migration
is additive and checksummed; legacy `materials`/`favorites` remain preserved as
source/audit evidence, deterministic conversions are recorded, and ambiguous rows are
sent to review without guessed academic relationships. Compatible root routes remain,
but their contracts now use stable resource/file IDs and no raw storage paths.

This is an application of ADR-001, not authorization for P1.3 search, AI,
recommendations, moderation workflows, notifications, microservices, or a framework
rewrite.

## Reuse, replacement, and temporary compatibility

**Reuse now:** `includes/config.php`, `database.php`, `auth.php`, `session.php`,
`csrf.php`, `error_handler.php`, `storage.php`, canonical validation/service policies,
`layout.php`, private storage, prepared SQL, and all Phase 0 tests.

**Replace incrementally:** direct route bootstrap/dispatch, inline request parsing,
inline SQL, mixed page/business logic, and per-file JSON response assembly. Each is
replaced only inside a migrated route after equivalent negative and positive tests
exist.

**Temporary adapters:** `LegacySessionUserProvider`, `LegacyAuthorizationGate`, and
`ApplicationFactory` call Phase 0 global functions. Session key/role semantics,
explicitly retained legacy transport aliases, normalized-schema compatibility, and
the generated CSS remain compatibility requirements. These adapters can be replaced
without changing module application services.

## Consequences and guardrails

- The repository temporarily contains procedural legacy code and namespaced code.
  That is intentional; a broken “big bang” intermediate state is not acceptable.
- New modules must not call another module's concrete repository or reach into raw
  storage. Use an application port/read model.
- Controllers validate transport input and return `Response`; application services
  enforce use-case invariants; repositories alone issue module SQL.
- Authentication establishes identity; authorization remains explicit middleware or
  a service/data-boundary decision. Unknown abilities fail closed.
- Unexpected exceptions are logged by safe type/context and returned as generic JSON;
  secrets and stack traces are not client output.
- A framework may be reconsidered when multiple migrated slices show that maintaining
  foundation code costs more than a validated package transition. That requires a
  separate ADR, deploy rehearsal, PHP-version decision, and route/session parity plan.
