<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = require_ability('upload_material');

$maximumRequest = ((int) app_config('upload.max_files') * (int) app_config('upload.max_bytes')) + (1024 * 1024);
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maximumRequest) {
    abort_request(413, 'The upload request is too large.');
}
require_csrf();

[$metadata, $metadataErrors] = validate_upload_metadata($_POST);
if ($metadataErrors !== []) {
    flash('error', 'Upload validation failed: ' . implode(' ', array_values($metadataErrors)));
    redirect('upload.php');
}

if (!validate_academic_relationships($metadata)) {
    flash('error', 'The selected university, department, course, subject, and semester do not form a valid academic path.');
    redirect('upload.php');
}

if (!role_can($user['user_type'], 'moderate_materials')
    && ((int) $user['university_id'] !== $metadata['university_id']
        || (int) $user['department_id'] !== $metadata['department_id'])) {
    abort_request(403, 'Contributors may only upload to their own university and department.');
}

$files = normalize_uploaded_files($_FILES['files'] ?? []);
if ($files === []) {
    flash('error', 'Select at least one file to upload.');
    redirect('upload.php');
}
if (count($files) > (int) app_config('upload.max_files')) {
    flash('error', 'Choose no more than ' . (int) app_config('upload.max_files') . ' files per upload.');
    redirect('upload.php');
}

$successCount = 0;
$failures = [];
$uploadGroup = bin2hex(random_bytes(16));
foreach ($files as $file) {
    $displayName = sanitize_original_filename($file['name']);
    [$verified, $validationError] = validate_upload_candidate(
        $file['name'],
        $file['tmp_name'],
        $file['size'],
        $file['error']
    );
    if ($verified === null) {
        $failures[] = $displayName . ': ' . $validationError;
        continue;
    }

    $duplicate = db()->prepare('SELECT id FROM materials WHERE user_id = ? AND checksum_sha256 = ? LIMIT 1');
    $duplicate->bind_param('is', $user['id'], $verified['checksum']);
    $duplicate->execute();
    if ($duplicate->get_result()->num_rows > 0) {
        $failures[] = $displayName . ': this exact file has already been uploaded by your account.';
        continue;
    }

    $storageKey = new_storage_key($verified['extension']);
    $storedPath = null;
    try {
        $storedPath = store_uploaded_file($file['tmp_name'], $storageKey);
        $visibility = 'authenticated';
        $status = 'published';
        $statement = db()->prepare(
            'INSERT INTO materials
             (user_id, title, description, type, file_path, original_filename, mime_type, file_size,
              checksum_sha256, university_id, department_id, course_id, subject_id, semester,
              upload_group_id, visibility, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->bind_param(
            'issssssisiiiiisss',
            $user['id'],
            $metadata['title'],
            $metadata['description'],
            $verified['extension'],
            $storageKey,
            $verified['original_name'],
            $verified['mime_type'],
            $verified['size'],
            $verified['checksum'],
            $metadata['university_id'],
            $metadata['department_id'],
            $metadata['course_id'],
            $metadata['subject_id'],
            $metadata['semester'],
            $uploadGroup,
            $visibility,
            $status
        );
        $statement->execute();
        $successCount++;
    } catch (Throwable $exception) {
        if ($storedPath !== null && is_file($storedPath)) {
            @unlink($storedPath);
        }
        app_log('error', 'Upload persistence failed', [
            'user_id' => $user['id'],
            'type' => $exception::class,
            'code' => $exception->getCode(),
        ]);
        $failures[] = $displayName . ': the server could not save this file.';
    }
}

if ($successCount > 0 && $failures === []) {
    flash('success', "Successfully uploaded {$successCount} file(s).");
} elseif ($successCount > 0) {
    flash('success', "Uploaded {$successCount} file(s). " . count($failures) . ' file(s) failed.');
    flash('error', implode(' ', $failures));
} else {
    flash('error', 'No files were uploaded. ' . implode(' ', $failures));
}
redirect('upload.php');
