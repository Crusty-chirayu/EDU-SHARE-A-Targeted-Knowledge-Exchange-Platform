# P1.2 normalized resource and file model

## Purpose and invariants

P1.2 separates the thing a user bookmarks from its editions and physical objects. A
**Resource** has one stable ID and owns descriptive/academic metadata. A **Resource
Version** is an immutable numbered edition. A **Resource File** is one validated
object in one version. A resource can therefore contain multiple files without
appearing multiple times in the library, and a favorite remains valid when a later
version is added.

```text
users (owner) 1 ───────< resources
                           │  stable resource ID
                           │  current_version_number
                           │
                           └── 1 ───────< resource_versions
                                            │ UNIQUE(resource_id, version_number)
                                            │ current | superseded
                                            │
                                            └── 1 ───────< resource_files
                                                             opaque storage_key
                                                             MIME / bytes / SHA-256
                                                             scan / processing / storage state

users >──────< resources through resource_favorites (user_id, resource_id)
resources ──> university / department / course / subject + semester
```

Aggregate, taxonomy, and source-audit foreign keys use `ON DELETE RESTRICT`.
Application deletion is a logical lifecycle plus object cleanup; no aggregate is
destroyed by a cascading physical delete. The one intentional exception is the
nullable `legacy_favorite_migrations.resource_favorite_id` audit pointer, which
uses `ON DELETE SET NULL`: a user can remove a migrated favorite without deleting
its retained source-audit row. `materials` and `material_favorites` remain preserved, read-only
migration sources and are not the active write model.

## Records and ownership

### Resource

`resources.id` is the public logical identity used by detail links, favorites,
versions, downloads, and deletion. It never changes when a version is added. Adding a
favorite requires current view access; a user may always remove their own retained
favorite after the resource becomes hidden or enters deletion. `owner_id` is immutable in P1.2. University, department, course, subject, and semester
are validated as one canonical taxonomy path on create and are immutable in this
phase, avoiding accidental or fabricated academic reassignment.

The owner may update title, description, and visibility on an active resource through
the metadata-update contract. This does not create or rewrite a version because those
fields describe the logical resource, not file content. P1.2 has no ownership
transfer, version diff, or academic-reclassification workflow.

### Version

Versions are numbered from 1 under a row lock. `(resource_id, version_number)` is
unique. Adding version N marks N `current`, marks older versions `superseded`, and
advances `resources.current_version_number` in the same database transaction. Old
version and file rows remain retrievable in authorized history and are never
renumbered. `change_description` describes the edition but is not a diff.

A version is a complete immutable file set, not a patch against its predecessor.
P1.2 does not expose standalone version update/delete. If such deletion is introduced
later it must tombstone the version, retain its number, and cannot silently promote or
renumber another version.

### File

Every file stores an original display filename separately from its randomized opaque
storage key. The key is generated server-side and is never accepted from a client or
rendered into a URL/view. Metadata includes extension, detected MIME type, byte size,
SHA-256 computed from actual content, scan state, processing state, storage/cleanup
state, creation/update timestamps, and an internal quarantine token only while cleanup
is nonterminal. `(resource_version_id, checksum_sha256)` prevents duplicate content
inside one version; storage keys and non-null quarantine tokens are globally unique
and indexed. Neither internal identity is returned by resource history or HTTP
contracts.

`ready` files whose scan state is not `blocked` and storage state is `available` may
be streamed after resource authorization. P1.2 records scan state but does not add a
moderation/scanning workflow. A blocked, failed-processing, quarantined, missing,
cleanup-pending, or deleted file is not downloadable. P1.2 does not expose standalone
file deletion; resource deletion handles every retained version/file so history and
cleanup state cannot disagree.

## Application contracts

All IDs are canonical positive decimal integers. HTTP mutations are POST and
CSRF-protected; application services use typed metadata/file values, explicit
not-found/access-denied/duplicate failures, repository transactions, and storage
ports.

| Contract | Input and authorization | Result/invariants |
|---|---|---|
| **Create resource** | Contributor ability; validated title/description, complete taxonomy path, visibility; 1 to `UPLOAD_MAX_FILES` files (configuration clamps count to 1–5), each at most `UPLOAD_MAX_BYTES`, and combined service limit | One stable resource, version 1, and all files commit together; returns resource/version/file IDs |
| **Retrieve resource** | Stable resource ID; visibility/status/owner/moderator policy | One aggregate/read model with current version/file counts; deleted resources are hidden even from owners/moderators |
| **Retrieve files/history** | Stable resource ID under the same policy | All numbered versions and safe file metadata; storage keys are omitted |
| **Download file** | Stable resource ID and optional file ID; never a path/key | Repository proves file belongs to resource; only available/ready/non-blocked content streams after size and SHA-256 re-verification |
| **Add version** | Stable resource ID; contributor ability plus active ownership; complete validated file set and optional 500-character description | Locks resource, allocates next number, stores every object, supersedes older editions, advances pointer atomically; ID/favorites stay stable |
| **Update resource** | Stable resource ID; active owner only; validated title, description, visibility | Updates logical metadata only; owner, taxonomy, versions, files, and checksums do not change |
| **Delete resource** | Stable resource ID; active owner, moderator, or admin; the same actors may retry a `pending_cleanup` resource | Quarantines available objects, persists recovery tokens, logically archives/deletes aggregate, purges after commit, and reports `deleted` or `pending_cleanup`; retry is idempotent and retained rows preserve history/audit |

The duplicate policy rejects repeated content within a submitted version and rejects
an owner's checksum already present in another active resource. Create and add-version
lock the owner row inside their transaction before this owner-scoped check, serializing
concurrent submissions that could otherwise race. Reusing content in a later version
of the same resource is allowed because a full edition may retain unchanged files.

## Atomic storage and deletion policy

Create/add-version starts a database transaction, stores each object behind the
`ResourceStorage` port, and inserts its file metadata. Any validation, storage, or SQL
failure rolls back persistence and removes every object attempted by that operation;
object identities are tracked before the write so a write-then-throw adapter is still
compensated. False/throwing removals and rollback failures are not ignored: they raise
`ResourceCleanupFailed`, expose only safe identifiers and failure counters to the
private application log, and produce no success response. This makes an exceptional unreferenced private
object operationally visible rather than falsely claiming complete cleanup. No
partial resource/version is intentionally returned. Downloads resolve an authorized
resource/file ID through the same port's read-only stream contract; the route verifies
stored size and SHA-256 before streaming and never receives a local path from the
client or exposes an opaque key.

Deletion first locks the active resource and all file rows. Available objects move to
a private quarantine area before database commit. The internal token is a one-way
SHA-256 derivation of the already-random opaque storage key, is never exposed, and is
persisted with `quarantined`/`cleanup_pending` state. This deterministic identity makes
the move idempotently discoverable even if a process dies after filesystem rename but
before database commit. A transaction failure restores objects; a failed restore
records cleanup intent after rollback. Missing objects are explicitly marked
`missing` only after absence is confirmed; unsafe surviving upload/quarantine entries
fail closed for operator review.

After logical commit, quarantine objects are purged and rows become `deleted`; purge
failure keeps both token and `cleanup_pending`, never a false successful cleanup
claim. The authorized owner/moderator/admin may invoke deletion again for a
`pending_cleanup` aggregate. Retry safely handles an already-moved or already-purged
object, clears the token only after terminal state is recorded, and finalizes the
resource when every object is terminal. Thus crashes before commit, after commit, or
between purge and status update retain a deterministic cleanup path. Detail/download
policy still denies every non-active resource regardless of ownership.

Standalone file/version deletion is intentionally unavailable in P1.2. This keeps
supersession, favorites, retained history, and physical cleanup under one aggregate
policy.

## Visibility and publication

Owners and moderator/admin roles may view active private or non-approved resources.
Other users require an active, `published`, `approved` resource. `public` permits
anonymous retrieval; `authenticated` requires a signed-in user; `private` permits
only owner/moderation roles. Adding a version requires contributor ability and active
ownership; logical metadata updates require active ownership. Active owners and
moderator/admin roles can delete. Deletion status takes precedence over every
privileged view/delete check.

## Legacy conversion policy

`20260813120000_normalize_resources.sql` is additive, forward-only, and rerunnable
where practical. It never edits or removes `materials`/`material_favorites`.
`legacy_material_migrations` starts every source row in review and upgrades only a
fully deterministic row/group:

- the complete academic relationship exists and semester matches;
- resource/file text metadata fits the normalized character limits, and storage key,
  extension, MIME, positive size, and lowercase SHA-256 metadata are structurally valid;
- a shared upload group has identical ownership, metadata, taxonomy, visibility, and
  status;
- no storage identity is shared by multiple source rows and no group repeats a
  checksum;
- an existing same-ID resource encountered on retry matches deterministic metadata and
  timestamps, and any existing version/files form only a compatible subset of the
  expected conversion; extra or incompatible normalized rows are preserved for review
  rather than receiving files by numeric coincidence.

An ungrouped deterministic material becomes one resource/version/file. A deterministic
`upload_group_id` becomes one resource/version containing every member file, using the
smallest material ID as stable resource ID. Conflicts remain unchanged and receive a
review reason such as `academic_relationship_invalid`, `resource_metadata_invalid`,
`storage_metadata_invalid`, `storage_identity_ambiguous`, `upload_group_duplicate_content`,
`resource_identity_conflict`, `resource_version_identity_conflict`,
`resource_file_identity_conflict`, or `upload_group_ambiguous`. No missing relationship
is inferred and no uncertain rows are merged.

A legacy favorite is copied only when its source has exactly the deterministic
resource mapping. Outcomes are recorded in `legacy_favorite_migrations`; ambiguous
favorites remain in the legacy source and are marked `source_material_not_deterministic`.
The pre-P1.2 `scripts/migrate_legacy_uploads.php` utility fails closed once normalized
tables exist so it cannot mutate only one side of the mapping.

## Multi-file example

A teacher uploads `lecture.pdf` (250 KiB) and `examples.txt` (4 KiB) together:

```text
resources #420: "Graph traversal", current_version_number=1
└─ resource_versions #900: resource_id=420, version_number=1, current
   ├─ resource_files #1201: lecture.pdf, key=8f…c2.pdf, SHA-256=3a…e1
   └─ resource_files #1202: examples.txt, key=42…19.txt, SHA-256=7b…0d
```

The library renders resource `420` once and says “2 current files”. A student
favorites resource `420`. Later, the owner uploads a complete version 2 containing a
corrected PDF and text file. Version 1 becomes superseded, version 2 becomes current,
and the favorite/detail URL still targets resource `420`. Authorized history shows
both editions; browser URLs include only resource/file numeric IDs, never either
opaque key shown here as internal illustrative metadata.
