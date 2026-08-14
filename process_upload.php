<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = require_ability('upload_resource');

$maximumRequest = ((int) app_config('upload.max_files') * (int) app_config('upload.max_bytes')) + (1024 * 1024);
if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maximumRequest) {
    abort_request(413, 'The upload request is too large.');
}
require_csrf();

$resourceId = positive_int($_POST['resource_id'] ?? null);
if (array_key_exists('resource_id', $_POST) && $resourceId === null) {
    abort_request(422, 'A valid resource ID is required when creating a version.');
}
$isNewVersion = $resourceId !== null;
$redirectTarget = $isNewVersion ? 'upload.php?resource_id=' . $resourceId : 'upload.php';

$files = normalize_uploaded_files($_FILES['files'] ?? []);
if ($files === []) {
    flash('error', 'Select at least one file. No resource or version was created.');
    redirect($redirectTarget);
}
if (count($files) > (int) app_config('upload.max_files')) {
    flash('error', 'Choose no more than ' . (int) app_config('upload.max_files') . ' files per resource version.');
    redirect($redirectTarget);
}

$verifiedFiles = [];
$validationFailures = [];
foreach ($files as $file) {
    [$verified, $validationError] = validate_upload_candidate(
        $file['name'],
        $file['tmp_name'],
        $file['size'],
        $file['error']
    );
    if ($verified === null) {
        $validationFailures[] = sanitize_original_filename($file['name']) . ': ' . $validationError;
        continue;
    }
    $verifiedFiles[] = new EduShare\Modules\Resources\Application\VerifiedResourceFile(
        $file['tmp_name'],
        $verified['original_name'],
        $verified['extension'],
        $verified['mime_type'],
        (int) $verified['size'],
        (string) $verified['checksum']
    );
}
if ($validationFailures !== []) {
    flash('error', 'No files were saved because validation failed: ' . implode(' ', $validationFailures));
    redirect($redirectTarget);
}

try {
    if ($isNewVersion) {
        $result = add_resource_version_service()->execute(
            $resourceId,
            $user,
            $verifiedFiles,
            canonical_text($_POST['change_description'] ?? '', true)
        );
        flash('success', 'Version ' . $result->versionNumber . ' was created atomically with '
            . count($result->fileIds) . ' file(s).');
    } else {
        [$metadata, $metadataErrors] = validate_upload_metadata($_POST);
        if ($metadataErrors !== []) {
            flash('error', 'Resource validation failed: ' . implode(' ', array_values($metadataErrors)));
            redirect('upload.php');
        }
        if (!validate_academic_relationships($metadata)) {
            flash('error', 'The selected university, department, course, subject, and semester do not form a valid academic path.');
            redirect('upload.php');
        }
        if (!role_can($user['user_type'], 'moderate_resources')
            && ((int) $user['university_id'] !== $metadata['university_id']
                || (int) $user['department_id'] !== $metadata['department_id'])) {
            abort_request(403, 'Contributors may only upload to their own university and department.');
        }

        $resourceMetadata = new EduShare\Modules\Resources\Application\ResourceMetadata(
            $metadata['title'],
            $metadata['description'] === '' ? null : $metadata['description'],
            $metadata['university_id'],
            $metadata['department_id'],
            $metadata['course_id'],
            $metadata['subject_id'],
            $metadata['semester'],
            'authenticated'
        );
        $result = create_resource_service()->execute((int) $user['id'], $resourceMetadata, $verifiedFiles);
        flash('success', 'Resource created atomically with ' . count($result->fileIds) . ' file(s).');
    }
    redirect('resource.php?id=' . $result->resourceId);
} catch (EduShare\Modules\Resources\Application\ResourceNotFound $exception) {
    abort_request(404, $exception->getMessage());
} catch (EduShare\Modules\Resources\Application\ResourceAccessDenied $exception) {
    abort_request(403, $exception->getMessage());
} catch (EduShare\Modules\Resources\Application\DuplicateResourceFile|InvalidArgumentException $exception) {
    flash('error', 'No files were saved: ' . $exception->getMessage());
    redirect($redirectTarget);
} catch (EduShare\Modules\Resources\Application\ResourceCleanupFailed $exception) {
    app_log('critical', 'Resource persistence compensation requires administrator attention', [
        'user_id' => $user['id'],
        'resource_id' => $resourceId,
        'cleanup_failure_count' => $exception->failedObjectCount,
        'rollback_failed' => $exception->rollbackFailed,
    ]);
    flash('error', 'The resource operation could not be confirmed, and private storage cleanup requires administrator attention.');
    redirect($redirectTarget);
} catch (Throwable $exception) {
    app_log('error', 'Atomic resource persistence failed', [
        'user_id' => $user['id'],
        'resource_id' => $resourceId,
        'type' => $exception::class,
        'code' => $exception->getCode(),
    ]);
    flash('error', 'No files were saved because the resource operation could not be completed.');
    redirect($redirectTarget);
}
