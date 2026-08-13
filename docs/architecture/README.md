# EDU-SHARE architecture foundation

P1.1 introduces a controlled migration boundary around the secure Phase 0 application.
It is an **incremental, frameworkless modular monolith**, not a rewrite. Legacy PHP
entry points remain the production-compatible surface while one versioned API slice
runs through the new route/middleware/controller/application/repository layers.

Architecture records:

- [ADR-001: incremental modular monolith](ADR-001-incremental-modular-monolith.md)
- [Bounded modules and dependency rules](modules.md)
- [Actual-file migration map](migration-map.md)
- [Compatibility, first slice, database, and test strategy](migration-strategy.md)

The target runtime remains PHP 8.1+ and MariaDB 10.4+ (or compatible MySQL 8). P1.1
adds no Composer/npm dependency and no domain-schema migration. The existing Phase 0
controls in `includes/` are deliberate compatibility adapters until each migrated
slice proves parity. P1.2 Resource/ResourceFile normalization is explicitly outside
this checkpoint.
