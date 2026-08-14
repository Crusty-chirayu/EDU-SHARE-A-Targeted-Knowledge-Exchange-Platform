<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (getenv('APP_ENV') === false) {
    putenv('APP_ENV=test');
}
if (getenv('STORAGE_PATH') === false) {
    putenv('STORAGE_PATH=' . sys_get_temp_dir() . '/edu-share-integration-' . getmypid());
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require APP_ROOT . '/app/autoload.php';

$passed = 0;
$failed = 0;
function check(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        fwrite(STDOUT, "PASS {$message}\n");
        return;
    }
    $failed++;
    fwrite(STDERR, "FAIL {$message}\n");
}

function expect_http_exception(callable $callback, int $status, string $message): void
{
    try {
        $callback();
        check(false, $message);
    } catch (AppHttpException $exception) {
        check($exception->status === $status, $message);
    }
}

$suffix = bin2hex(random_bytes(4));
$studentEmail = "student-{$suffix}@example.test";
$teacherEmail = "teacher-{$suffix}@example.test";
$studentPassword = 'StudentPass123';
$teacherPassword = 'TeacherPass123';

[$studentValues, $studentErrors] = validate_registration_input([
    'role' => 'student',
    'name' => 'Synthetic Student',
    'email' => $studentEmail,
    'password' => $studentPassword,
    'university_id' => '1',
    'department_id' => '1',
    'course_id' => '1',
    'year' => '2',
    'contact' => '+91 98765 43210',
    'address' => 'Synthetic test address',
]);
check($studentErrors === [], 'valid student registration input is accepted');
$studentId = register_user($studentValues);
check($studentId > 0, 'student registration persists an account');
$studentContext = db()->query('SELECT course_id, branch FROM users WHERE id = ' . $studentId)->fetch_assoc();
check(
    (int) $studentContext['course_id'] === 1 && $studentContext['branch'] === null,
    'student registration persists governed course context without new free-text branch data'
);

[$teacherValues, $teacherErrors] = validate_registration_input([
    'role' => 'teacher',
    'name' => 'Synthetic Teacher',
    'email' => $teacherEmail,
    'password' => $teacherPassword,
    'university_id' => '1',
    'department_id' => '1',
]);
check(
    $teacherErrors === []
        && $teacherValues['branch'] === null
        && $teacherValues['course_id'] === null
        && $teacherValues['year'] === null,
    'teacher registration canonicalizes student-only fields'
);
$teacherId = register_user($teacherValues);
check($teacherId > 0, 'teacher registration persists an account');

$directoryRepository = new \EduShare\Modules\Profiles\Infrastructure\MysqliContributorDirectoryRepository(db());
check($directoryRepository->universityExists(1), 'modular contributor repository finds a known university');
$universityContributors = $directoryRepository->contributorsAtUniversity(1);
$matchingContributor = array_filter(
    $universityContributors,
    static fn (array $contributor): bool => $contributor['id'] === $teacherId
);
check(count($matchingContributor) === 1, 'modular contributor repository returns the newly registered teacher');

[$privilegedValues, $privilegedErrors] = validate_registration_input([
    'role' => 'admin',
    'name' => 'Privilege Attempt',
    'email' => "admin-attempt-{$suffix}@example.test",
    'password' => 'Privilege123',
    'university_id' => '1',
    'department_id' => '1',
]);
check(isset($privilegedErrors['role']), 'public registration rejects privileged roles');
expect_http_exception(static fn () => register_user($privilegedValues), 422, 'registration service rejects privileged roles at the data boundary');

$student = authenticate_credentials($studentEmail, $studentPassword);
check($student !== null && (int) $student['id'] === $studentId, 'valid credentials authenticate');
check(authenticate_credentials($studentEmail, 'wrong-password') === null, 'invalid credentials are rejected');
for ($attempt = 0; $attempt < 5; $attempt++) {
    record_login_attempt($studentEmail, false);
}
check(login_is_rate_limited($studentEmail), 'repeated login failures are rate-limited');
record_login_attempt($studentEmail, true);
check(!login_is_rate_limited($studentEmail), 'successful login clears keyed failures');

$capabilityExpectations = [
    'student' => [false, false, false, false],
    'teacher' => [true, false, false, false],
    'moderator' => [true, false, true, true],
    'admin' => [true, true, true, true],
];
foreach ($capabilityExpectations as $role => [$upload, $academics, $moderate, $deleteAny]) {
    check(role_can($role, 'upload_material') === $upload, "{$role} upload authorization is correct");
    check(role_can($role, 'manage_academics') === $academics, "{$role} academic-admin authorization is correct");
    check(role_can($role, 'moderate_materials') === $moderate, "{$role} moderation authorization is correct");
    check(role_can($role, 'delete_any_material') === $deleteAny, "{$role} cross-owner deletion authorization is correct");
}
check(!role_can('owner', 'manage_academics') && !role_can('admin', 'unknown'), 'unknown roles and abilities fail closed');

$csrf = csrf_token();
check(strlen($csrf) === 64 && csrf_token_matches($csrf, $_SESSION['_csrf']), 'generated CSRF token validates');
check(!csrf_token_matches(str_repeat('0', 64), $_SESSION['_csrf']) && !csrf_token_matches(null, $_SESSION['_csrf']), 'missing and incorrect CSRF tokens are rejected');

check(positive_int('1') === 1 && positive_int('0') === null && positive_int('-1') === null && positive_int('1e2') === null, 'canonical positive-ID validation rejects alternate forms');
check(decode_json_object('{"material_id":1}')['material_id'] === 1, 'JSON objects are accepted');
expect_http_exception(static fn () => decode_json_object('{broken'), 400, 'malformed JSON is rejected');
expect_http_exception(static fn () => decode_json_object('[1,2]'), 400, 'non-object JSON requests are rejected');

check(validate_department_relationship(1, 1), 'valid university/department relationship is accepted');
check(!validate_department_relationship(999999, 1), 'invalid university/department relationship is rejected');
check(validate_academic_relationships([
    'university_id' => 1,
    'department_id' => 1,
    'course_id' => 1,
    'subject_id' => 1,
    'semester' => 1,
]), 'valid complete academic path is accepted');
check(!validate_academic_relationships([
    'university_id' => 1,
    'department_id' => 1,
    'course_id' => 1,
    'subject_id' => 2,
    'semester' => 1,
]), 'mismatched semester/subject path is rejected');
db()->query('UPDATE subjects SET is_active = 0 WHERE id = 1');
check(!validate_academic_relationships([
    'university_id' => 1,
    'department_id' => 1,
    'course_id' => 1,
    'subject_id' => 1,
    'semester' => 1,
]), 'retired academic nodes are rejected for new resource context');
db()->query('UPDATE subjects SET is_active = 1 WHERE id = 1');

ensure_private_directory(upload_storage_directory());
$invalidKeys = [
    '../secret.pdf', '..%2fsecret.pdf', '%2e%2e/secret.pdf', '/etc/passwd', 'C:\\secret.pdf',
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/secret.pdf', 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.php',
    'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA.pdf', "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.pdf\0.txt",
];
foreach ($invalidKeys as $invalidKey) {
    check(safe_storage_path($invalidKey) === null, 'unsafe storage key is rejected: ' . str_replace("\0", '[NUL]', $invalidKey));
}

$validPdf = tempnam(sys_get_temp_dir(), 'edu-share-pdf-');
$fakePdf = tempnam(sys_get_temp_dir(), 'edu-share-fake-');
file_put_contents($validPdf, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n");
file_put_contents($fakePdf, "<?php echo 'not a document';");
[$verifiedPdf, $pdfError] = validate_upload_candidate('../../lecture.pdf', $validPdf, (int) filesize($validPdf), UPLOAD_ERR_OK, false);
check($verifiedPdf !== null && $pdfError === null && $verifiedPdf['extension'] === 'pdf', 'valid PDF content is accepted and named safely');
[$fakeResult] = validate_upload_candidate('malicious.pdf', $fakePdf, (int) filesize($fakePdf), UPLOAD_ERR_OK, false);
check($fakeResult === null, 'extension/content mismatch is rejected');
[$unsupportedResult] = validate_upload_candidate('malicious.php', $fakePdf, (int) filesize($fakePdf), UPLOAD_ERR_OK, false);
check($unsupportedResult === null, 'unsupported executable extension is rejected');
[$oversizedResult] = validate_upload_candidate('large.pdf', $validPdf, (int) app_config('upload.max_bytes') + 1, UPLOAD_ERR_OK, false);
check($oversizedResult === null, 'oversized uploads are rejected before storage');
[$sizeMismatchResult] = validate_upload_candidate('mismatched.pdf', $validPdf, (int) filesize($validPdf) + 1, UPLOAD_ERR_OK, false);
check($sizeMismatchResult === null, 'declared upload size must match the actual content size');
check(normalize_uploaded_files(['name' => ['a.pdf'], 'tmp_name' => [], 'error' => [0], 'size' => [1]]) === [], 'malformed upload arrays are rejected');

$storageKey = new_storage_key('pdf');
$storedPath = safe_storage_path($storageKey, false);
check($storedPath !== null && copy($validPdf, $storedPath), 'verified test file can be placed under private storage');
chmod($storedPath, 0640);
check(safe_storage_path($storageKey) === realpath($storedPath), 'randomized storage key resolves inside private root');
$symlinkKey = new_storage_key('pdf');
$symlinkPath = safe_storage_path($symlinkKey, false);
$symlinkCreated = function_exists('symlink') && @symlink($validPdf, $symlinkPath);
if ($symlinkCreated) {
    check(safe_storage_path($symlinkKey) === null, 'symlinked storage objects are rejected');
    check(!remove_storage_object($symlinkKey), 'unsafe surviving storage entries are not reported as cleaned');
    $unsafeQuarantineRejected = false;
    try {
        quarantine_storage_object($symlinkKey);
    } catch (RuntimeException) {
        $unsafeQuarantineRejected = true;
    }
    check($unsafeQuarantineRejected, 'unsafe surviving storage entries are not mislabeled as missing');
    unlink($symlinkPath);
}
ensure_private_directory(storage_quarantine_directory());
$unsafeQuarantineToken = hash('sha256', 'unsafe-quarantine-test') . '.quarantine';
$unsafeQuarantinePath = storage_quarantine_directory() . DIRECTORY_SEPARATOR . $unsafeQuarantineToken;
$quarantineSymlinkCreated = function_exists('symlink') && @symlink($validPdf, $unsafeQuarantinePath);
if ($quarantineSymlinkCreated) {
    check(quarantined_storage_path($unsafeQuarantineToken) === null, 'symlinked quarantine objects are rejected');
    check(!purge_quarantined_storage_object($unsafeQuarantineToken), 'unsafe quarantine entries are not reported as purged');
    unlink($unsafeQuarantinePath);
}

$title = '<img src=x onerror=alert(1)>';
$description = '<script>document.body.textContent="owned"</script>';
$teacher = authenticate_credentials($teacherEmail, $teacherPassword);
$moderator = ['id' => 999998, 'user_type' => 'moderator'];
$admin = ['id' => 999999, 'user_type' => 'admin'];

// Exercise the exact forward migration with deterministic and ambiguous synthetic legacy rows.
$legacyInsert = db()->prepare(
    'INSERT INTO materials
        (user_id, title, description, type, file_path, original_filename, mime_type, file_size,
         checksum_sha256, university_id, department_id, course_id, subject_id, semester,
         upload_group_id, visibility, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 1, 1, 1, ?, \'authenticated\', \'published\')'
);
$insertLegacy = static function (
    string $legacyTitle,
    string $group,
    string $name,
    string $extension,
    string $mime,
    string $checksum
) use ($legacyInsert, $teacherId): int {
    $key = new_storage_key($extension);
    $size = 128;
    $description = 'Synthetic legacy migration source';
    $legacyInsert->bind_param(
        'issssssiss',
        $teacherId,
        $legacyTitle,
        $description,
        $extension,
        $key,
        $name,
        $mime,
        $size,
        $checksum,
        $group
    );
    $legacyInsert->execute();
    return (int) db()->insert_id;
};
$deterministicGroup = bin2hex(random_bytes(16));
$legacyOne = $insertLegacy('Deterministic resource', $deterministicGroup, 'part-one.pdf', 'pdf', 'application/pdf', hash('sha256', 'legacy-one-' . $suffix));
$legacyTwo = $insertLegacy('Deterministic resource', $deterministicGroup, 'part-two.txt', 'txt', 'text/plain', hash('sha256', 'legacy-two-' . $suffix));
$ambiguousGroup = bin2hex(random_bytes(16));
$ambiguousOne = $insertLegacy('Conflicting title A', $ambiguousGroup, 'ambiguous-a.pdf', 'pdf', 'application/pdf', hash('sha256', 'ambiguous-one-' . $suffix));
$ambiguousTwo = $insertLegacy('Conflicting title B', $ambiguousGroup, 'ambiguous-b.txt', 'txt', 'text/plain', hash('sha256', 'ambiguous-two-' . $suffix));
$sharedChecksum = hash('sha256', 'within-group-duplicate-' . $suffix);
$duplicateGroup = bin2hex(random_bytes(16));
$duplicateGroupFirst = $insertLegacy('Affected upload group', $duplicateGroup, 'duplicate-one.pdf', 'pdf', 'application/pdf', $sharedChecksum);
$duplicateGroupSecond = $insertLegacy('Affected upload group', $duplicateGroup, 'duplicate-two.pdf', 'pdf', 'application/pdf', $sharedChecksum);
$duplicateGroupUnique = $insertLegacy('Affected upload group', $duplicateGroup, 'otherwise-unique.txt', 'txt', 'text/plain', hash('sha256', 'otherwise-unique-' . $suffix));
$collisionGroup = bin2hex(random_bytes(16));
$collisionMaterial = $insertLegacy('Collision source', $collisionGroup, 'collision.pdf', 'pdf', 'application/pdf', hash('sha256', 'collision-' . $suffix));
$resourceCollision = db()->prepare(
    "INSERT INTO resources
        (id, owner_id, title, document_type, university_id, department_id, course_id,
         subject_id, semester, visibility, publication_status, moderation_status)
     VALUES (?, ?, 'Unrelated pre-existing resource', 'pdf', 1, 1, 1, 1, 1,
             'authenticated', 'published', 'approved')"
);
$resourceCollision->bind_param('ii', $collisionMaterial, $teacherId);
$resourceCollision->execute();

$fileConflictGroup = bin2hex(random_bytes(16));
$fileConflictMaterial = $insertLegacy('File conflict source', $fileConflictGroup, 'expected.pdf', 'pdf', 'application/pdf', hash('sha256', 'file-conflict-' . $suffix));
$exactResource = db()->prepare(
    "INSERT INTO resources
        (id, owner_id, title, description, document_type, university_id, department_id,
         course_id, subject_id, semester, visibility, publication_status, moderation_status)
     VALUES (?, ?, 'File conflict source', 'Synthetic legacy migration source', 'pdf',
             1, 1, 1, 1, 1, 'authenticated', 'published', 'approved')"
);
$exactResource->bind_param('ii', $fileConflictMaterial, $teacherId);
$exactResource->execute();
db()->query(
    "UPDATE resources r JOIN materials m ON m.id = r.id
        SET r.created_at = m.upload_date, r.updated_at = m.upload_date
      WHERE r.id = {$fileConflictMaterial}"
);
$exactVersion = db()->prepare(
    "INSERT INTO resource_versions
        (id, resource_id, version_number, created_by, change_description, lifecycle_status)
     VALUES (?, ?, 1, ?, 'Imported deterministically from the legacy material model.', 'current')"
);
$exactVersion->bind_param('iii', $fileConflictMaterial, $fileConflictMaterial, $teacherId);
$exactVersion->execute();
db()->query(
    "UPDATE resource_versions rv JOIN materials m ON m.id = rv.id
        SET rv.created_at = m.upload_date
      WHERE rv.id = {$fileConflictMaterial}"
);
$fileConflictSource = db()->query(
    "SELECT file_path, mime_type, file_size, checksum_sha256 FROM materials
      WHERE id = {$fileConflictMaterial}"
)->fetch_assoc();
$wrongFile = db()->prepare(
    "INSERT INTO resource_files
        (id, resource_version_id, original_filename, storage_key, extension, mime_type,
         file_size, checksum_sha256, scan_status, processing_status, storage_status)
     VALUES (?, ?, 'wrong-existing-name.pdf', ?, 'pdf', ?, ?, ?,
             'not_scanned', 'ready', 'available')"
);
$conflictStorageKey = (string) $fileConflictSource['file_path'];
$conflictMime = (string) $fileConflictSource['mime_type'];
$conflictSize = (int) $fileConflictSource['file_size'];
$conflictChecksum = (string) $fileConflictSource['checksum_sha256'];
$wrongFile->bind_param(
    'iissis',
    $fileConflictMaterial,
    $fileConflictMaterial,
    $conflictStorageKey,
    $conflictMime,
    $conflictSize,
    $conflictChecksum
);
$wrongFile->execute();

$versionConflictGroup = bin2hex(random_bytes(16));
$versionConflictMaterial = $insertLegacy(
    'Version conflict source',
    $versionConflictGroup,
    'version-conflict.pdf',
    'pdf',
    'application/pdf',
    hash('sha256', 'version-conflict-' . $suffix)
);
$versionConflictResource = db()->prepare(
    "INSERT INTO resources
        (id, owner_id, title, description, document_type, university_id, department_id,
         course_id, subject_id, semester, visibility, publication_status, moderation_status)
     VALUES (?, ?, 'Version conflict source', 'Synthetic legacy migration source', 'pdf',
             1, 1, 1, 1, 1, 'authenticated', 'published', 'approved')"
);
$versionConflictResource->bind_param('ii', $versionConflictMaterial, $teacherId);
$versionConflictResource->execute();
db()->query(
    "UPDATE resources r JOIN materials m ON m.id = r.id
        SET r.created_at = m.upload_date, r.updated_at = m.upload_date
      WHERE r.id = {$versionConflictMaterial}"
);
$versionConflictVersion = db()->prepare(
    "INSERT INTO resource_versions
        (id, resource_id, version_number, created_by, change_description, lifecycle_status)
     VALUES (?, ?, 2, ?, 'Imported deterministically from the legacy material model.', 'current')"
);
$versionConflictVersion->bind_param(
    'iii',
    $versionConflictMaterial,
    $versionConflictMaterial,
    $teacherId
);
$versionConflictVersion->execute();
db()->query(
    "UPDATE resource_versions rv JOIN materials m ON m.id = rv.id
        SET rv.created_at = m.upload_date
      WHERE rv.id = {$versionConflictMaterial}"
);

$legacyFavorite = db()->prepare('INSERT INTO material_favorites (user_id, material_id) VALUES (?, ?)');
$legacyFavorite->bind_param('ii', $studentId, $legacyTwo);
$legacyFavorite->execute();
$legacyFavoriteId = (int) $legacyFavorite->insert_id;

$legacyPassword = password_hash('LegacyAcademic123', PASSWORD_DEFAULT);
$legacyRole = 'student';
$legacyExactBranch = '  bachelor of computer applications  ';
$legacyExactEmail = "legacy-exact-{$suffix}@example.test";
$legacyExactName = 'Legacy Exact Academic User';
$legacyUser = db()->prepare(
    'INSERT INTO users
        (full_name, gmail, password, user_type, university_id, department_id, course_id, branch, year)
     VALUES (?, ?, ?, ?, 1, 1, NULL, ?, 2)'
);
$legacyUser->bind_param(
    'sssss',
    $legacyExactName,
    $legacyExactEmail,
    $legacyPassword,
    $legacyRole,
    $legacyExactBranch
);
$legacyUser->execute();
$legacyExactUserId = (int) $legacyUser->insert_id;
$legacyUnmatchedBranch = 'Unverified Historical Branch';
$legacyUnmatchedEmail = "legacy-unmatched-{$suffix}@example.test";
$legacyUnmatchedName = 'Legacy Unmatched Academic User';
$legacyUser->bind_param(
    'sssss',
    $legacyUnmatchedName,
    $legacyUnmatchedEmail,
    $legacyPassword,
    $legacyRole,
    $legacyUnmatchedBranch
);
$legacyUser->execute();
$legacyUnmatchedUserId = (int) $legacyUser->insert_id;

$migrationRunner = new EduShare\Shared\Persistence\MigrationRunner(
    db(),
    APP_ROOT . '/database/migrations/forward'
);
$appliedMigrations = $migrationRunner->applyPending();
check(in_array('20260813120000', $appliedMigrations, true), 'P1.2 forward migration applies through the checksummed runner');
check(in_array('20260814120000', $appliedMigrations, true), 'P1.3 taxonomy migration applies through the checksummed runner');
$studentAcademicMigration = db()->query(
    "SELECT proposed_course_id, migration_status, reason_code
       FROM legacy_user_academic_migrations WHERE user_id = {$studentId}"
)->fetch_assoc();
check(
    $studentAcademicMigration !== null
        && (int) $studentAcademicMigration['proposed_course_id'] === 1
        && $studentAcademicMigration['migration_status'] === 'migrated'
        && $studentAcademicMigration['reason_code'] === null,
    'P1.3 migration preserves an existing valid canonical student course context'
);
$teacherAcademicMigration = db()->query(
    "SELECT migration_status, reason_code
       FROM legacy_user_academic_migrations WHERE user_id = {$teacherId}"
)->fetch_assoc();
check(
    $teacherAcademicMigration !== null
        && $teacherAcademicMigration['migration_status'] === 'not_applicable'
        && $teacherAcademicMigration['reason_code'] === null,
    'P1.3 migration does not invent course context for a contributor'
);
$exactLegacyContext = db()->query(
    "SELECT u.course_id, u.branch, migration.migration_status, migration.reason_code
       FROM users u
       JOIN legacy_user_academic_migrations migration ON migration.user_id = u.id
      WHERE u.id = {$legacyExactUserId}"
)->fetch_assoc();
check(
    $exactLegacyContext !== null
        && (int) $exactLegacyContext['course_id'] === 1
        && $exactLegacyContext['branch'] === $legacyExactBranch
        && $exactLegacyContext['migration_status'] === 'migrated'
        && $exactLegacyContext['reason_code'] === null,
    'unique exact in-department legacy branch mapping is copied without rewriting its evidence'
);
$unmatchedLegacyContext = db()->query(
    "SELECT u.course_id, u.branch, migration.migration_status, migration.reason_code,
            review.review_status
       FROM users u
       JOIN legacy_user_academic_migrations migration ON migration.user_id = u.id
       JOIN academic_taxonomy_reviews review
         ON review.entity_type = 'user' AND review.entity_id = u.id
        AND review.reason_code = migration.reason_code
      WHERE u.id = {$legacyUnmatchedUserId}"
)->fetch_assoc();
check(
    $unmatchedLegacyContext !== null
        && $unmatchedLegacyContext['course_id'] === null
        && $unmatchedLegacyContext['branch'] === $legacyUnmatchedBranch
        && $unmatchedLegacyContext['migration_status'] === 'review_required'
        && $unmatchedLegacyContext['reason_code'] === 'legacy_branch_no_exact_course'
        && $unmatchedLegacyContext['review_status'] === 'open',
    'unmatched legacy branch remains unchanged and explicitly review-required'
);
$deterministicAudit = db()->query(
    "SELECT material_id, resource_id, migration_status FROM legacy_material_migrations
      WHERE material_id IN ({$legacyOne}, {$legacyTwo}) ORDER BY material_id"
)->fetch_all(MYSQLI_ASSOC);
check(count($deterministicAudit) === 2
    && $deterministicAudit[0]['migration_status'] === 'migrated'
    && (int) $deterministicAudit[0]['resource_id'] === $legacyOne
    && (int) $deterministicAudit[1]['resource_id'] === $legacyOne,
    'deterministic upload-group rows map to one stable resource ID');
$legacyFileCount = (int) db()->query(
    "SELECT COUNT(*) AS total FROM resource_files rf
      JOIN resource_versions rv ON rv.id = rf.resource_version_id
     WHERE rv.resource_id = {$legacyOne} AND rv.version_number = 1"
)->fetch_assoc()['total'];
check($legacyFileCount === 2, 'deterministic multi-file legacy group becomes one version with every file');
$ambiguousAudit = db()->query(
    "SELECT migration_status, reason_code FROM legacy_material_migrations
      WHERE material_id IN ({$ambiguousOne}, {$ambiguousTwo}) ORDER BY material_id"
)->fetch_all(MYSQLI_ASSOC);
check(count($ambiguousAudit) === 2
    && $ambiguousAudit[0]['migration_status'] === 'review_required'
    && $ambiguousAudit[0]['reason_code'] === 'upload_group_ambiguous',
    'conflicting legacy upload-group metadata is preserved and marked for review');
$ambiguousSourceCount = (int) db()->query(
    "SELECT COUNT(*) AS total FROM materials WHERE id IN ({$ambiguousOne}, {$ambiguousTwo})"
)->fetch_assoc()['total'];
check($ambiguousSourceCount === 2, 'ambiguous legacy source rows are never destroyed');
$duplicateGroupAudit = db()->query(
    "SELECT material_id, migration_status, reason_code FROM legacy_material_migrations
      WHERE material_id IN ({$duplicateGroupFirst}, {$duplicateGroupSecond}, {$duplicateGroupUnique})
      ORDER BY material_id"
)->fetch_all(MYSQLI_ASSOC);
$duplicateGroupRejectedWhole = count($duplicateGroupAudit) === 3;
foreach ($duplicateGroupAudit as $row) {
    $duplicateGroupRejectedWhole = $duplicateGroupRejectedWhole
        && $row['migration_status'] === 'review_required'
        && $row['reason_code'] === 'upload_group_duplicate_content';
}
check($duplicateGroupRejectedWhole,
    'duplicate content rejects every member of the affected upload group');
$partialDuplicateConversionCount = (int) db()->query(
    "SELECT COUNT(*) AS total FROM resource_files WHERE id = {$duplicateGroupUnique}"
)->fetch_assoc()['total'];
check($partialDuplicateConversionCount === 0,
    'an otherwise-valid group member is never partially converted when its group is ambiguous');
$collisionAudit = db()->query(
    "SELECT migration_status, reason_code FROM legacy_material_migrations
      WHERE material_id = {$collisionMaterial}"
)->fetch_assoc();
$collisionAttachmentCount = (int) db()->query(
    "SELECT COUNT(*) AS total FROM resource_files rf
      JOIN resource_versions rv ON rv.id = rf.resource_version_id
     WHERE rv.resource_id = {$collisionMaterial}"
)->fetch_assoc()['total'];
$collisionResourceTitle = (string) db()->query(
    "SELECT title FROM resources WHERE id = {$collisionMaterial}"
)->fetch_assoc()['title'];
check($collisionAudit !== null
    && $collisionAudit['migration_status'] === 'review_required'
    && $collisionAudit['reason_code'] === 'resource_identity_conflict'
    && $collisionAttachmentCount === 0
    && $collisionResourceTitle === 'Unrelated pre-existing resource',
    'a pre-existing resource ID collision is preserved for review and never receives guessed files');
$versionConflictAudit = db()->query(
    "SELECT migration_status, reason_code FROM legacy_material_migrations
      WHERE material_id = {$versionConflictMaterial}"
)->fetch_assoc();
$versionConflictNumber = (int) db()->query(
    "SELECT version_number FROM resource_versions WHERE id = {$versionConflictMaterial}"
)->fetch_assoc()['version_number'];
$versionConflictAttachmentCount = (int) db()->query(
    "SELECT COUNT(*) AS total FROM resource_files
      WHERE resource_version_id = {$versionConflictMaterial}"
)->fetch_assoc()['total'];
check($versionConflictAudit !== null
    && $versionConflictAudit['migration_status'] === 'review_required'
    && $versionConflictAudit['reason_code'] === 'resource_version_identity_conflict'
    && $versionConflictNumber === 2
    && $versionConflictAttachmentCount === 0,
    'an incompatible normalized version identity remains unchanged and receives no guessed files');
$fileConflictAudit = db()->query(
    "SELECT migration_status, reason_code FROM legacy_material_migrations
      WHERE material_id = {$fileConflictMaterial}"
)->fetch_assoc();
$fileConflictName = (string) db()->query(
    "SELECT original_filename FROM resource_files WHERE id = {$fileConflictMaterial}"
)->fetch_assoc()['original_filename'];
check($fileConflictAudit !== null
    && $fileConflictAudit['migration_status'] === 'review_required'
    && $fileConflictAudit['reason_code'] === 'resource_file_identity_conflict'
    && $fileConflictName === 'wrong-existing-name.pdf',
    'a normalized file identity collision remains unchanged and review-required on retry');
$mappedLegacyFavorite = (int) db()->query(
    "SELECT COUNT(*) AS total FROM resource_favorites WHERE user_id = {$studentId} AND resource_id = {$legacyOne}"
)->fetch_assoc()['total'];
check($mappedLegacyFavorite === 1, 'deterministic legacy favorite maps to logical resource identity');
db()->query(
    "DELETE FROM resource_favorites WHERE user_id = {$studentId} AND resource_id = {$legacyOne}"
);
$removedLegacyFavoriteAudit = db()->query(
    "SELECT migration_status, resource_favorite_id FROM legacy_favorite_migrations
      WHERE material_favorite_id = {$legacyFavoriteId}"
)->fetch_assoc();
check($removedLegacyFavoriteAudit !== null
    && $removedLegacyFavoriteAudit['migration_status'] === 'migrated'
    && $removedLegacyFavoriteAudit['resource_favorite_id'] === null,
    'removing a migrated favorite preserves its source audit with a cleared optional target pointer');

final class IntegrationResourceStorage implements EduShare\Modules\Resources\Application\ResourceStorage
{
    /** @var array<string, string> */
    public array $objects = [];
    /** @var array<string, string> */
    public array $quarantine = [];
    public bool $failPurge = false;

    public function newKey(string $extension): string
    {
        return bin2hex(random_bytes(16)) . '.' . $extension;
    }

    public function putVerifiedUpload(string $temporaryPath, string $storageKey): void
    {
        $contents = file_get_contents($temporaryPath);
        if ($contents === false) {
            throw new RuntimeException('Synthetic storage could not read input.');
        }
        $this->objects[$storageKey] = $contents;
    }

    public function openReadStream(string $storageKey)
    {
        if (!isset($this->objects[$storageKey])) {
            return null;
        }
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Synthetic stream could not be opened.');
        }
        fwrite($stream, $this->objects[$storageKey]);
        rewind($stream);
        return $stream;
    }

    public function remove(string $storageKey): bool
    {
        unset($this->objects[$storageKey]);
        return true;
    }

    public function quarantine(string $storageKey): ?string
    {
        $token = hash('sha256', "edu-share-resource-quarantine-v1\0" . $storageKey) . '.quarantine';
        if (isset($this->objects[$storageKey])) {
            $this->quarantine[$token] = $this->objects[$storageKey];
            unset($this->objects[$storageKey]);
            return $token;
        }
        return isset($this->quarantine[$token]) ? $token : null;
    }

    public function restore(string $quarantineToken, string $storageKey): bool
    {
        if (!isset($this->quarantine[$quarantineToken])) {
            return false;
        }
        $this->objects[$storageKey] = $this->quarantine[$quarantineToken];
        unset($this->quarantine[$quarantineToken]);
        return true;
    }

    public function purge(string $quarantineToken): bool
    {
        if ($this->failPurge) {
            return false;
        }
        unset($this->quarantine[$quarantineToken]);
        return true;
    }
}

$textFile = tempnam(sys_get_temp_dir(), 'edu-share-text-');
file_put_contents($textFile, "normalized resource text {$suffix}\n");
$initialFiles = [
    new EduShare\Modules\Resources\Application\VerifiedResourceFile(
        $validPdf,
        $verifiedPdf['original_name'],
        'pdf',
        $verifiedPdf['mime_type'],
        (int) $verifiedPdf['size'],
        (string) $verifiedPdf['checksum']
    ),
    new EduShare\Modules\Resources\Application\VerifiedResourceFile(
        $textFile,
        'companion.txt',
        'txt',
        'text/plain',
        (int) filesize($textFile),
        hash_file('sha256', $textFile)
    ),
];
$metadata = new EduShare\Modules\Resources\Application\ResourceMetadata(
    $title,
    $description,
    1,
    1,
    1,
    1,
    1,
    'private'
);
$resourceStorage = new IntegrationResourceStorage();
$resourceRepository = new EduShare\Modules\Resources\Infrastructure\MysqliResourceRepository(db());
$createResource = new EduShare\Modules\Resources\Application\CreateResource(
    $resourceRepository,
    $resourceStorage,
    5,
    20 * 1024 * 1024
);
$created = $createResource->execute($teacherId, $metadata, $initialFiles);
check($created->resourceId > 0 && $created->versionNumber === 1 && count($created->fileIds) === 2,
    'multi-file create produces one resource and one initial version atomically');
$resourceId = $created->resourceId;
$resource = find_resource($resourceId);
check($resource !== null && (int) $resource['file_count'] === 2 && (int) $resource['version_count'] === 1,
    'resource retrieval reports current file and version counts once');
$storedFiles = resource_versions_with_files($resourceId);
check(count($storedFiles) === 2
    && preg_match('/^[a-f0-9]{32}\.(pdf|txt)$/D', (string) array_key_first($resourceStorage->objects)) === 1
    && $storedFiles[0]['checksum_sha256'] !== '',
    'file records retain opaque keys in storage and checksums in normalized metadata');

$duplicateRejected = false;
try {
    $createResource->execute($teacherId, $metadata, [$initialFiles[0], $initialFiles[0]]);
} catch (EduShare\Modules\Resources\Application\DuplicateResourceFile) {
    $duplicateRejected = true;
}
check($duplicateRejected, 'duplicate content within one requested version is rejected before persistence');

$changedPdf = tempnam(sys_get_temp_dir(), 'edu-share-v2-');
file_put_contents($changedPdf, "%PDF-1.4\nversion-two-{$suffix}\n%%EOF\n");
$versionTwoFile = new EduShare\Modules\Resources\Application\VerifiedResourceFile(
    $changedPdf,
    'lecture-v2.pdf',
    'pdf',
    'application/pdf',
    (int) filesize($changedPdf),
    hash_file('sha256', $changedPdf)
);
$addVersion = new EduShare\Modules\Resources\Application\AddResourceVersion(
    $resourceRepository,
    $resourceStorage,
    new EduShare\Modules\Resources\Domain\ResourceAccessPolicy(),
    5,
    20 * 1024 * 1024
);
$versionTwo = $addVersion->execute($resourceId, $teacher, [$versionTwoFile], 'Corrected lecture notes');
$resourceAfterVersion = find_resource($resourceId);
check($versionTwo->resourceId === $resourceId && $versionTwo->versionNumber === 2
    && (int) $resourceAfterVersion['current_version_number'] === 2,
    'new version keeps stable resource identity and advances numbering');
$history = resource_versions_with_files($resourceId);
check(count($history) === 3
    && count(array_filter($history, static fn (array $row): bool => (int) $row['version_number'] === 1)) === 2,
    'version history retains every old-version file');

$updateResource = new EduShare\Modules\Resources\Application\UpdateResource(
    $resourceRepository,
    new EduShare\Modules\Resources\Domain\ResourceAccessPolicy()
);
$updateDenied = false;
try {
    $updateResource->execute(
        $resourceId,
        $student,
        new EduShare\Modules\Resources\Application\ResourceMetadataUpdate('Unauthorized change', null, 'public')
    );
} catch (EduShare\Modules\Resources\Application\ResourceAccessDenied) {
    $updateDenied = true;
}
check($updateDenied && find_resource($resourceId)['title'] === $title,
    'non-owner metadata update is denied without changing the resource');
$updateResource->execute(
    $resourceId,
    $teacher,
    new EduShare\Modules\Resources\Application\ResourceMetadataUpdate($title, 'Updated metadata only', 'private')
);
$resourceAfterVersion = find_resource($resourceId);
check($resourceAfterVersion['description'] === 'Updated metadata only'
    && count(resource_versions_with_files($resourceId)) === 3,
    'owner metadata update preserves stable identity and immutable file history');

check(can_view_resource($resourceAfterVersion, $teacher), 'private resource owner can retrieve files');
check(!can_view_resource($resourceAfterVersion, $student) && !can_view_resource($resourceAfterVersion, null),
    'private resource is denied to non-owner and anonymous users');
check(can_view_resource($resourceAfterVersion, $moderator) && can_view_resource($resourceAfterVersion, $admin),
    'moderator and admin can access a private resource');
check(can_delete_resource($resourceAfterVersion, $teacher) && !can_delete_resource($resourceAfterVersion, $student),
    'only owner or moderation roles can delete an active resource');

db()->query("UPDATE resources SET visibility = 'authenticated', publication_status = 'published', moderation_status = 'approved' WHERE id = {$resourceId}");
$resource = find_resource($resourceId);
check(can_view_resource($resource, $student) && !can_view_resource($resource, null), 'authenticated resource requires an account');
db()->query("UPDATE resources SET visibility = 'public' WHERE id = {$resourceId}");
$resource = find_resource($resourceId);
check(can_view_resource($resource, null), 'published public resource can be retrieved anonymously');
db()->query("UPDATE resources SET moderation_status = 'pending' WHERE id = {$resourceId}");
$resource = find_resource($resourceId);
check(!can_view_resource($resource, $student) && can_view_resource($resource, $teacher),
    'pending resource is hidden except from owner and moderation roles');

check(h($title) === '&lt;img src=x onerror=alert(1)&gt;', 'stored HTML payload is escaped on output');
check(!str_contains(h($description), '<script>'), 'stored script payload cannot create a script element');
check(!valid_display_name('<svg onload=alert(1)>'), 'active markup is rejected in profile names');

db()->query("UPDATE resources SET visibility = 'authenticated', moderation_status = 'approved' WHERE id = {$resourceId}");
$_SESSION['user_id'] = $studentId;
reset_auth_cache();
check(toggle_resource_favorite($studentId, $resourceId) === 'added', 'resource favorite can be added');
$favoriteBeforeVersionDelete = (int) db()->query(
    "SELECT COUNT(*) AS total FROM resource_favorites WHERE user_id = {$studentId} AND resource_id = {$resourceId}"
)->fetch_assoc()['total'];
check($favoriteBeforeVersionDelete === 1, 'favorite remains attached to stable resource identity after version changes');
db()->query("UPDATE resources SET moderation_status = 'pending' WHERE id = {$resourceId}");
check(toggle_resource_favorite($studentId, $resourceId) === 'removed',
    'a user can remove their own retained favorite after the resource becomes hidden');
check(toggle_university_favorite($studentId, 1) === 'added', 'university favorite can be added');
check(toggle_university_favorite($studentId, 1) === 'removed', 'university favorite can be removed');
expect_http_exception(static fn () => toggle_resource_favorite($studentId, 999999), 404, 'favorite service rejects unknown resource IDs');
expect_http_exception(static fn () => toggle_university_favorite($studentId, 999999), 404, 'favorite API service rejects unknown university IDs');

$deleteResource = new EduShare\Modules\Resources\Application\DeleteResource(
    $resourceRepository,
    $resourceStorage,
    new EduShare\Modules\Resources\Domain\ResourceAccessPolicy()
);
$deleteDenied = false;
try {
    $deleteResource->execute($resourceId, $student);
} catch (EduShare\Modules\Resources\Application\ResourceAccessDenied) {
    $deleteDenied = true;
}
check($deleteDenied && find_resource($resourceId)['deletion_status'] === 'active',
    'unauthorized resource deletion is denied without changing state');
$resourceStorage->failPurge = true;
$pendingDeletion = $deleteResource->execute($resourceId, $teacher);
$persistedCleanupCount = (int) db()->query(
    "SELECT COUNT(*) AS total FROM resource_files rf
      JOIN resource_versions rv ON rv.id = rf.resource_version_id
     WHERE rv.resource_id = {$resourceId}
       AND rf.storage_status = 'cleanup_pending'
       AND rf.quarantine_token REGEXP '^[0-9a-f]{64}[.]quarantine$'"
)->fetch_assoc()['total'];
check($pendingDeletion->cleanupStatus === 'pending_cleanup' && $persistedCleanupCount === 3,
    'deferred deletion persists every opaque quarantine recovery token');
$resourceStorage->failPurge = false;
$deleted = $deleteResource->execute($resourceId, $teacher);
$deletedResource = find_resource($resourceId);
$remainingRecoveryTokens = (int) db()->query(
    "SELECT COUNT(*) AS total FROM resource_files rf
      JOIN resource_versions rv ON rv.id = rf.resource_version_id
     WHERE rv.resource_id = {$resourceId} AND rf.quarantine_token IS NOT NULL"
)->fetch_assoc()['total'];
check($deleted->cleanupStatus === 'deleted' && $deletedResource['deletion_status'] === 'deleted'
    && !can_view_resource($deletedResource, $teacher) && $remainingRecoveryTokens === 0,
    'authorized deletion resumes cleanup, revokes access, and clears completed recovery state');
$retainedVersionCount = (int) db()->query(
    "SELECT COUNT(*) AS total FROM resource_versions WHERE resource_id = {$resourceId}"
)->fetch_assoc()['total'];
check($retainedVersionCount === 2 && $resourceStorage->objects === [],
    'resource deletion retains version records while removing all private objects');

@unlink($validPdf);
@unlink($fakePdf);
@unlink($textFile);
@unlink($changedPdf);
@unlink($storedPath);

fwrite(STDOUT, "Integration checks: {$passed} passed, {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
