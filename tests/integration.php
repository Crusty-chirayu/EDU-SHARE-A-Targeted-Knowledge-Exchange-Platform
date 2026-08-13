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
    'branch' => 'Computer Science',
    'year' => '2',
    'contact' => '+91 98765 43210',
    'address' => 'Synthetic test address',
]);
check($studentErrors === [], 'valid student registration input is accepted');
$studentId = register_user($studentValues);
check($studentId > 0, 'student registration persists an account');

[$teacherValues, $teacherErrors] = validate_registration_input([
    'role' => 'teacher',
    'name' => 'Synthetic Teacher',
    'email' => $teacherEmail,
    'password' => $teacherPassword,
    'university_id' => '1',
    'department_id' => '1',
]);
check($teacherErrors === [] && $teacherValues['branch'] === null && $teacherValues['year'] === null, 'teacher registration canonicalizes student-only fields');
$teacherId = register_user($teacherValues);
check($teacherId > 0, 'teacher registration persists an account');

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
    unlink($symlinkPath);
}

$title = '<img src=x onerror=alert(1)>';
$description = '<script>document.body.textContent="owned"</script>';
$type = 'pdf';
$originalName = $verifiedPdf['original_name'];
$mime = $verifiedPdf['mime_type'];
$fileSize = $verifiedPdf['size'];
$checksum = $verifiedPdf['checksum'];
$universityId = 1;
$departmentId = 1;
$courseId = 1;
$subjectId = 1;
$semester = 1;
$visibility = 'private';
$status = 'published';
$insertMaterial = db()->prepare(
    'INSERT INTO materials
     (user_id, title, description, type, file_path, original_filename, mime_type, file_size, checksum_sha256,
      university_id, department_id, course_id, subject_id, semester, visibility, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertMaterial->bind_param(
    'issssssisiiiiiss',
    $teacherId,
    $title,
    $description,
    $type,
    $storageKey,
    $originalName,
    $mime,
    $fileSize,
    $checksum,
    $universityId,
    $departmentId,
    $courseId,
    $subjectId,
    $semester,
    $visibility,
    $status
);
$insertMaterial->execute();
$materialId = (int) $insertMaterial->insert_id;
$material = find_material($materialId);
$teacher = authenticate_credentials($teacherEmail, $teacherPassword);
$moderator = ['id' => 999998, 'user_type' => 'moderator'];
$admin = ['id' => 999999, 'user_type' => 'admin'];
check(can_view_material($material, $teacher), 'private material owner can download');
check(!can_view_material($material, $student) && !can_view_material($material, null), 'private material is denied to non-owner and anonymous users');
check(can_view_material($material, $moderator) && can_view_material($material, $admin), 'moderator and admin can access private material');
check(can_delete_material($material, $teacher) && !can_delete_material($material, $student), 'only owner or moderation role can delete owned material');
check(can_delete_material($material, $moderator) && can_delete_material($material, $admin), 'moderation roles can delete another owner material');

$updateVisibility = db()->prepare("UPDATE materials SET visibility = 'authenticated', status = 'published' WHERE id = ?");
$updateVisibility->bind_param('i', $materialId);
$updateVisibility->execute();
$material = find_material($materialId);
check(can_view_material($material, $student) && !can_view_material($material, null), 'authenticated material requires an account');
db()->query("UPDATE materials SET visibility = 'public' WHERE id = {$materialId}");
$material = find_material($materialId);
check(can_view_material($material, null), 'published public material can be downloaded anonymously');
db()->query("UPDATE materials SET status = 'pending' WHERE id = {$materialId}");
$material = find_material($materialId);
check(!can_view_material($material, $student) && can_view_material($material, $teacher), 'pending material is hidden except from owner/moderation roles');

check(h($title) === '&lt;img src=x onerror=alert(1)&gt;', 'stored HTML payload is escaped on output');
check(!str_contains(h($description), '<script>'), 'stored script payload cannot create a script element');
check(!valid_display_name('<svg onload=alert(1)>'), 'active markup is rejected in profile names');

$publish = db()->prepare("UPDATE materials SET visibility = 'authenticated', status = 'published' WHERE id = ?");
$publish->bind_param('i', $materialId);
$publish->execute();
$_SESSION['user_id'] = $studentId;
reset_auth_cache();
check(toggle_material_favorite($studentId, $materialId) === 'added', 'material favorite can be added');
check(toggle_material_favorite($studentId, $materialId) === 'removed', 'material favorite can be removed');
check(toggle_university_favorite($studentId, 1) === 'added', 'university favorite can be added');
check(toggle_university_favorite($studentId, 1) === 'removed', 'university favorite can be removed');
expect_http_exception(static fn () => toggle_material_favorite($studentId, 999999), 404, 'favorite API service rejects unknown material IDs');
expect_http_exception(static fn () => toggle_university_favorite($studentId, 999999), 404, 'favorite API service rejects unknown university IDs');

@unlink($validPdf);
@unlink($fakePdf);
@unlink($storedPath);

fwrite(STDOUT, "Integration checks: {$passed} passed, {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
