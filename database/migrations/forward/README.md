# Forward migrations

This is the checksummed forward-only migration stream introduced in P1.1. Historical
`database/migrations/001_p0_prepare.sql` and `002_p0_finalize.sql` remain manual
compatibility steps and are intentionally not auto-discovered.

Files use `YYYYMMDDHHMMSS_short_description.sql`. Applied versions and SHA-256
checksums are stored in `schema_migrations`; modifying an applied file is an error.
Run explicitly as a schema-owner account:

```bash
php scripts/migrate.php status
php scripts/migrate.php apply
```

Migrations must be narrow, deterministic, non-destructive, and rerunnable after an
interruption where practical. The runner rejects `DROP`, `TRUNCATE`, and `DELETE
FROM`. Back up and test a restored database and private storage first. MariaDB/MySQL
may implicitly commit DDL, so recovery relies on preserved data and verified backups,
not a promise that every DDL statement can roll back.

## P1.2 migration

`20260813120000_normalize_resources.sql` adds logical resources, immutable numbered
versions, per-version file metadata (including explicit storage state and internal
quarantine-recovery tokens), stable resource favorites, and legacy conversion audit
tables. It does not modify or delete `materials` or `material_favorites`.
Complete deterministic source rows/groups are copied; uncertain academic, storage, or
group relationships remain in place as `review_required` with a reason. Review the
full model and operator policy in
[`docs/architecture/resource-model.md`](../../../docs/architecture/resource-model.md)
before applying it.
