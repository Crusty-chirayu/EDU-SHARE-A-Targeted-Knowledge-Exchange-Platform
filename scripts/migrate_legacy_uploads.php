<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';

// This utility prepares P1.0/P1.1 material rows for the P1.2 data migration. Once
// normalized tables exist, changing only materials would make the immutable resource
// file metadata disagree with storage. Fail closed rather than creating that split.
$normalizedModel = db()->prepare(
    'SELECT COUNT(*) AS total
       FROM information_schema.tables
      WHERE table_schema = DATABASE() AND table_name = \'resources\''
);
$normalizedModel->execute();
if ((int) $normalizedModel->get_result()->fetch_assoc()['total'] > 0) {
    fwrite(STDERR, "The normalized resource model is already installed. This pre-P1.2 storage preparation utility will not modify legacy rows.\n");
    fwrite(STDERR, "Review legacy_material_migrations and use normalized resource cleanup tooling for any remaining operator work.\n");
    exit(2);
}

$sourceRoots = array_values(array_filter([
    realpath(APP_ROOT . '/uploads'),
    realpath(APP_ROOT . '/upload/uploads'),
    realpath(dirname(APP_ROOT) . '/upload/uploads'),
    realpath(dirname(APP_ROOT) . '/uploads'),
]));
ensure_private_directory(upload_storage_directory());

$result = db()->query(
    'SELECT id, file_path, original_filename, mime_type, file_size, checksum_sha256, type
       FROM materials ORDER BY id'
);
$migrated = 0;
$alreadySafe = 0;
$failed = 0;

while ($material = $result->fetch_assoc()) {
    $id = (int) $material['id'];
    $currentKey = (string) $material['file_path'];
    $currentSafePath = safe_storage_path($currentKey);
    $currentChecksum = $currentSafePath !== null && is_file($currentSafePath)
        ? hash_file('sha256', $currentSafePath)
        : false;
    $currentSize = $currentSafePath !== null && is_file($currentSafePath)
        ? filesize($currentSafePath)
        : false;
    if ($currentSafePath !== null
        && is_file($currentSafePath)
        && !is_link($currentSafePath)
        && is_string($currentChecksum)
        && is_int($currentSize)
        && $material['original_filename'] !== null
        && $material['mime_type'] !== null
        && $material['file_size'] !== null
        && $material['checksum_sha256'] !== null
        && $material['type'] !== null
        && strtolower((string) $material['type']) === strtolower((string) pathinfo($currentKey, PATHINFO_EXTENSION))
        && hash_equals((string) $material['checksum_sha256'], $currentChecksum)
        && (int) $material['file_size'] === $currentSize) {
        $alreadySafe++;
        continue;
    }

    $legacyName = sanitize_original_filename(basename(str_replace('\\', '/', $currentKey)));
    $source = $currentSafePath !== null && is_file($currentSafePath) && !is_link($currentSafePath)
        ? $currentSafePath
        : null;
    foreach ($sourceRoots as $root) {
        $candidate = realpath($root . DIRECTORY_SEPARATOR . basename(str_replace('\\', '/', $currentKey)));
        if ($candidate !== false
            && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)
            && is_file($candidate)
            && !is_link($candidate)) {
            $source = $candidate;
            break;
        }
    }

    if ($source === null) {
        fwrite(STDERR, "Material {$id}: legacy file was not found in an approved source directory.\n");
        $failed++;
        continue;
    }

    [$verified, $error] = validate_upload_candidate($legacyName, $source, (int) filesize($source), UPLOAD_ERR_OK, false);
    if ($verified === null) {
        fwrite(STDERR, "Material {$id}: {$error}\n");
        $failed++;
        continue;
    }

    $newKey = new_storage_key($verified['extension']);
    $destination = safe_storage_path($newKey, false);
    if ($destination === null || !copy($source, $destination)) {
        fwrite(STDERR, "Material {$id}: unable to copy into private storage.\n");
        $failed++;
        continue;
    }
    @chmod($destination, 0640);

    try {
        $statement = db()->prepare(
            'UPDATE materials
                SET file_path = ?, original_filename = ?, mime_type = ?, file_size = ?,
                    checksum_sha256 = ?, type = ?
              WHERE id = ?'
        );
        $statement->bind_param(
            'sssissi',
            $newKey,
            $verified['original_name'],
            $verified['mime_type'],
            $verified['size'],
            $verified['checksum'],
            $verified['extension'],
            $id
        );
        $statement->execute();
        $migrated++;
        fwrite(STDOUT, "Material {$id}: copied to private storage with an opaque object identity.\n");
    } catch (Throwable $exception) {
        @unlink($destination);
        fwrite(STDERR, "Material {$id}: database update failed; copied file was cleaned up.\n");
        $failed++;
    }
}

$academicFailures = 0;
$academicCheck = db()->query(
    'SELECT m.id
       FROM materials m
       LEFT JOIN universities u ON u.id = m.university_id
       LEFT JOIN departments d ON d.id = m.department_id
       LEFT JOIN courses c ON c.id = m.course_id
       LEFT JOIN subjects s ON s.id = m.subject_id
      WHERE u.id IS NULL OR d.id IS NULL OR c.id IS NULL OR s.id IS NULL
         OR d.university_id IS NULL OR c.department_id IS NULL
         OR s.course_id IS NULL OR s.department_id IS NULL OR s.semester IS NULL
         OR m.semester IS NULL OR m.semester NOT BETWEEN 1 AND 12
         OR d.university_id <> m.university_id OR c.department_id <> m.department_id
         OR s.course_id <> m.course_id OR s.department_id <> m.department_id
         OR s.semester <> m.semester
      ORDER BY m.id'
);
while ($row = $academicCheck->fetch_assoc()) {
    fwrite(STDERR, 'Material ' . (int) $row['id'] . ": academic IDs do not form a canonical university/department/course/subject/semester path.\n");
    $academicFailures++;
}

fwrite(STDOUT, "File migration summary: {$migrated} migrated, {$alreadySafe} already safe, {$failed} failed.\n");
fwrite(STDOUT, "Academic validation summary: {$academicFailures} material record(s) require review.\n");
fwrite(STDOUT, "Legacy source files were intentionally retained. Verify downloads and back up data before removing them.\n");
exit($failed === 0 && $academicFailures === 0 ? 0 : 1);
