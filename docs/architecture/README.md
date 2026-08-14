# EDU-SHARE architecture foundation

P1.1 introduced a controlled migration boundary around the secure Phase 0
application. It is an **incremental, frameworkless modular monolith**, not a rewrite.
P1.2 uses those boundaries for the normalized Resource/Version/File aggregate while
retaining compatible root PHP entry points and all Phase 0 security controls.

Architecture records:

- [ADR-001: incremental modular monolith](ADR-001-incremental-modular-monolith.md)
- [P1.2 normalized resource and file model](resource-model.md)
- [Bounded modules and dependency rules](modules.md)
- [Actual-file migration map](migration-map.md)
- [Compatibility, database, rollout, and test strategy](migration-strategy.md)

The target runtime remains PHP 8.1+ and MariaDB 10.4+ (or compatible MySQL 8). No
Composer/npm dependency was added. The existing Phase 0 controls in `includes/`
remain deliberate compatibility adapters. Normalized writes now pass through the
Resources application/repository/storage ports; preserved legacy material tables are
migration/audit sources, not the active model.
