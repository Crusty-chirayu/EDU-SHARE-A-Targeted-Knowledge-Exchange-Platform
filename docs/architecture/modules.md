# Bounded modules and dependency rules

These boundaries describe responsibility and future ownership. They do not claim that
Phase 0 tables are already normalized. During migration, repositories may read a
legacy table shared by concerns; only one module should own new writes, and the
exception must remain documented until a forward migration resolves it.

## Module catalog

| Module | Owns and guarantees | Current entry points/data involved | Permitted dependencies |
|---|---|---|---|
| **Identity & Access** | Credentials, authenticated identity, session lifecycle, roles/capabilities, login throttling, CSRF identity binding | `login1.php`, `register.php`, `logout.php`, `includes/auth.php`, `session.php`, `csrf.php`; identity columns in `users`, `login_attempts` | Shared HTTP/config/persistence; publishes an identity/authorization port, not session internals |
| **Academic Taxonomy** | Active/retired universities, departments, courses/programs, subjects/semesters, composite parentage, mutation audit, and ambiguity-preserving legacy lineage | `admin.php`, `get_universities.php`, `get_departments.php`, `get_courses.php`, `get_subjects.php`, `includes/validation.php`; taxonomy, event, and review tables | Identity authorization for writes; exposes validated/lockable references to Resources without depending on it |
| **Resources** | Stable learning-resource identity/metadata, immutable numbered versions, ownership, visibility/status, favorites integration, and retryable logical deletion/cleanup lifecycle | `app/Modules/Resources/`, `resource.php`, `homepage.php`, resource areas of `dashboard.php`, `process_upload.php`, `update_resource.php`, `download.php`, `delete_material.php`; `resources`, `resource_versions`, `resource_files` | Identity and validated taxonomy references; File Ingestion only through `ResourceStorage`; normalized writes never use legacy `materials` |
| **File Ingestion** | Upload acceptance, MIME/magic/size verification, SHA-256, opaque storage identity, private binary storage, quarantine/restore/purge, and authorized stream handoff | `upload.php`, validation portions of `process_upload.php`, `includes/storage.php`, `PrivateResourceStorage`, private storage deny rules | Called through Resources use-case ports; never trusts a client path or filename and never exposes an opaque key |
| **Search & Discovery** | Search/filter/query read models and visibility-aware discovery | `homepage.php`, `lib.php`, selector-assisted browsing | Read ports/projections from Resources, Taxonomy, Profiles; does not own source records |
| **Collections** | Stable resource favorites and university favorites | `favorites.php`, `toggle_favorite.php`, `toggle_university_favorite.php`; `resource_favorites`, `university_favorites` | Identity plus authorized Resource/Taxonomy existence ports; a favorite never binds to a version/file |
| **Profiles** | Privacy-minimized public contributor identity and academic affiliation read models | `teacher_profile.php`, `university_teachers.php`, new `app/Modules/Profiles/`; non-credential `users` fields | Identity public user ID/role and Taxonomy references; must not expose contact/auth fields |
| **Moderation** | Review decisions, policy actions, audit reason/status transitions | Current moderator/admin material capabilities and `materials.status`; no workflow yet | Identity authorization and Resources command ports |
| **Notifications** | User notification preferences, delivery requests/status and templates | No implementation yet | Receives events from application services; cannot own source-module transactions |
| **Analytics** | Privacy-conscious aggregate events/metrics and retention policy | No implementation yet | Consumes approved events/read models; no direct credential/private-file access |
| **AI Learning Services** *(future)* | Explicitly consented learning-assistance use cases, provider isolation, prompt/data policy, evaluation and provenance | No P1.1 implementation | Calls stable Resource/Search/Profile ports only; no raw database, session, private storage, or implicit personal-data access |

## Internal shape

A migrated module uses only the layers it needs:

```text
app/Modules/<Module>/
  Domain/          entities/value objects and domain policy
  Application/     use cases and repository/service ports
  Http/            controllers, request validation, module middleware
  Infrastructure/  mysqli and external-system adapters
```

The first slice under `app/Modules/Profiles/` demonstrates an application repository
port, use-case service, strict route input, controller, and `mysqli` adapter. Identity
middleware is supplied by `app/Modules/IdentityAccess/`. P1.2's
`app/Modules/Resources/` applies the same inward dependency rule to create,
add-version, metadata-update, retrieval, and deletion services. See
[`resource-model.md`](resource-model.md) for its relationships and lifecycle policies.

## Dependency rules

1. `Domain` depends on PHP only. `Application` may depend on its Domain and explicit
   interfaces. `Http` and `Infrastructure` depend inward, never the reverse.
2. Shared code under `app/Shared/` is technical plumbing, not a home for business
   rules. Module-to-module behavior uses a named port or immutable read model.
3. New controllers do not call global `$_GET`, `$_POST`, `$_SESSION`, `header()`, or
   `mysqli` directly. `Request`, middleware, providers, repositories, and
   `ResponseEmitter` own those edges.
4. Authentication is not authorization. Every protected route declares middleware;
   sensitive object operations must also enforce ownership/visibility at the service
   or repository boundary.
5. Profiles, Search, Analytics, Notifications, and future AI are least-data read
   consumers. They do not gain access to credentials, contact details, raw file
   paths, or private binaries by sharing a database connection.
6. Synchronous code and one database remain appropriate. Events initially mean an
   in-process contract/outbox-ready boundary, not microservices or a message cluster.
7. Extraction into a separate service requires measured scaling/security need,
   ownership, operational readiness, and a new ADR. It is not a default roadmap.
