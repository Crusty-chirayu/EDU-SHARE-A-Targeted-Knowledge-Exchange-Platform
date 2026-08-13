<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = require_auth();
require_csrf();
$materialId = positive_int($_POST['id'] ?? null);
if ($materialId === null) {
    abort_request(422, 'A valid material ID is required.');
}

$connection = db();
$connection->begin_transaction();
$originalPath = null;
$quarantinePath = null;
try {
    $statement = $connection->prepare(
        'SELECT id, user_id, file_path, visibility, status FROM materials WHERE id = ? FOR UPDATE'
    );
    $statement->bind_param('i', $materialId);
    $statement->execute();
    $material = $statement->get_result()->fetch_assoc();
    if ($material === null) {
        abort_request(404, 'Material not found.');
    }
    if (!can_delete_material($material, $user)) {
        abort_request(403, 'You do not have permission to delete this material.');
    }

    $originalPath = safe_storage_path((string) $material['file_path']);
    if ($originalPath !== null) {
        $trashDirectory = (string) app_config('storage_path') . '/trash';
        ensure_private_directory($trashDirectory);
        $quarantinePath = $trashDirectory . '/' . bin2hex(random_bytes(16)) . '.deleted';
        if (!rename($originalPath, $quarantinePath)) {
            throw new RuntimeException('Unable to quarantine material before deletion.');
        }
    }

    $delete = $connection->prepare('DELETE FROM materials WHERE id = ?');
    $delete->bind_param('i', $materialId);
    $delete->execute();
    $connection->commit();

    if ($quarantinePath !== null && is_file($quarantinePath) && !@unlink($quarantinePath)) {
        app_log('warning', 'Unable to remove quarantined material', ['material_id' => $materialId]);
    }
    flash('success', 'Material deleted.');
    redirect('dashboard.php');
} catch (Throwable $exception) {
    $connection->rollback();
    if ($quarantinePath !== null && $originalPath !== null && is_file($quarantinePath) && !file_exists($originalPath)) {
        @rename($quarantinePath, $originalPath);
    }
    if ($exception instanceof AppHttpException) {
        throw $exception;
    }
    app_log('error', 'Material deletion failed', ['material_id' => $materialId, 'type' => $exception::class]);
    abort_request(500, 'The material could not be deleted.');
}
