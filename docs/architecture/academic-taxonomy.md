# P1.3 governed academic taxonomy

## Canonical hierarchy

P1.3 uses the entities that already have stable meaning in EDU-SHARE:

```text
University
└── Department
    └── Course (the existing program/branch concept)
        └── Subject + semester
```

A separate `Program` or `Branch` table is intentionally not introduced. Existing
course data already contains program-level values such as “Bachelor of Computer
Applications”, while `users.branch` is an inconsistent legacy free-text field. Adding
another entity would create two competing meanings and would require guessed mappings.
The retained `users.branch` column is migration evidence only. New student accounts use
`users.course_id`.

Semesters remain a bounded attribute (1–12) of a subject offering and resource context.
There is no justified repository concept for a separately governed semester entity.

## Relationship invariants

The canonical fresh schema and the P1.3 migration enforce these paths:

- `departments.university_id -> universities.id`;
- `courses.department_id -> departments.id`;
- `(subjects.course_id, subjects.department_id) -> (courses.id, courses.department_id)`;
- `(users.department_id, users.university_id) -> (departments.id, departments.university_id)`;
- `(users.course_id, users.department_id) -> (courses.id, courses.department_id)` when a
  user has a course;
- resource department/university, course/department, and
  subject/course/department/semester tuples reference the same canonical parent tuples.

The composite referenced keys are explicit unique indexes. Every identifier in these
foreign-key pairs is `INT UNSIGNED`; semester pairs are `TINYINT UNSIGNED`. This is
necessary because MariaDB/MySQL rejects foreign keys whose signedness differs.
Restrictive deletion preserves referenced academic history.

The server repeats these rules rather than relying on browser selectors. Registration
locks the selected active university/department/course in the account transaction.
Resource creation locks the complete active path in the same transaction as resource,
version, and file metadata. Cross-parent IDs, retired nodes, malformed IDs, and a
retirement race are rejected.

## Lifecycle and governance

Each taxonomy entity has `is_active`. Administrators may create, rename, retire, or
reactivate records through the CSRF-protected `admin.php` route. Normal users and
contributors cannot perform taxonomy mutations. The route:

- begins a transaction and locks the entity/parent rows;
- accepts only a fixed entity allowlist;
- refuses creation/reactivation below a retired parent;
- refuses retirement while direct active children remain, so descendants are never
  changed implicitly;
- never physically deletes taxonomy rows;
- records the actor, entity, action, before/after name, and timestamp in
  `academic_taxonomy_events` in the same transaction.

Retired records remain readable on historical resources but disappear from selectors
and cannot be used for new registrations, uploads, or university favorites.

## Dependent selectors

Registration, upload, and library filter forms use parent-scoped JSON endpoints:

- `get_departments.php?university_id=...`;
- `get_courses.php?department_id=...`;
- `get_subjects.php?course_id=...&semester=...`.

Only active rows beneath active ancestors are returned. Client-side dependency handling
is usability only. All submitted IDs are parsed as canonical positive integers and the
complete relationship is validated again on the server. Library filter combinations
are also checked so a caller cannot combine IDs from different parents manually.

## Legacy migration and ambiguity

`20260814120000_govern_academic_taxonomy.sql` is additive and follows the checksummed
P1.1 migration stream. It does not edit prior migrations, disable foreign-key checks,
change legacy material/resource IDs, delete source rows, or rewrite `users.branch`.
Information-schema guards make additive DDL retryable after an interrupted MariaDB/
MySQL implicit commit.

For each existing user, `legacy_user_academic_migrations` records one outcome:

- accounts with an invalid university/department pair: `review_required` regardless of role;
- other non-students: `not_applicable`;
- a student branch matching exactly one course name in that user's valid department,
  after case and outer-whitespace canonicalization only: copied to `users.course_id`
  and marked `migrated`;
- missing branch, no exact in-department match, ambiguous exact matches, or an invalid
  university/department pair: `review_required` with a reason.

No department-name inference, global course-name match, fuzzy alias, or arbitrary first
row is accepted. Legacy values remain unchanged for an operator with external evidence.
`academic_taxonomy_reviews` also records invalid subject, material, and normalized
resource paths. Legacy materials are not constrained by the upgrade because uncertain
source rows are intentionally retained; active normalized resources receive composite
path constraints. If an invalid subject, user parent pair, or normalized resource would
violate a new constraint, InnoDB fails closed after the review evidence is established
instead of coercing or deleting data.

## Operator checks

Before deployment, back up and restore-test the database, then run:

```bash
php scripts/migrate.php status
php scripts/migrate.php apply
```

Inspect at minimum:

```sql
SELECT migration_status, reason_code, COUNT(*)
  FROM legacy_user_academic_migrations
 GROUP BY migration_status, reason_code;

SELECT entity_type, reason_code, COUNT(*)
  FROM academic_taxonomy_reviews
 WHERE review_status = 'open'
 GROUP BY entity_type, reason_code;
```

An open review is preserved work, not permission to guess. Resolve it only from
institutional evidence through a separately reviewed operation. Never edit an applied
migration checksum or make `FOREIGN_KEY_CHECKS=0` a deployment workaround.

## Scope

P1.3 establishes taxonomy entities, lineage, lifecycle, user/resource integration,
dependent selectors, migration evidence, and authorization. It does not implement
search ranking, moderation workflows, recommendations, AI services, notifications, or
any P1.4 work.
