# Forward migrations

This directory is the repeatable migration stream introduced in P1.1. The historical
`001_p0_prepare.sql` and `002_p0_finalize.sql` files remain manual compatibility steps
and are intentionally not auto-discovered.

New files must be named `YYYYMMDDHHMMSS_short_description.sql`. Applied versions and
SHA-256 checksums are stored in `schema_migrations`; modifying an applied file is an
error. Run `php scripts/migrate.php status` to inspect the plan and
`php scripts/migrate.php apply` explicitly as a schema-owner account.

Migrations are forward-only, narrowly scoped, idempotent where practical, and must
preserve existing data. The runner rejects `DROP`, `TRUNCATE`, and `DELETE FROM`.
Back up and test a restored database first. MySQL/MariaDB can implicitly commit DDL,
so each migration must also document any safe recovery action rather than relying on
a transaction to reverse schema changes.

P1.1 adds no domain-schema migration. Resource/File redesign belongs to P1.2 and must
not be inferred or added here.
