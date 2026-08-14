<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
$user = require_auth();
require_csrf();
$resourceId = positive_int($_POST['id'] ?? null);
if ($resourceId === null) {
    abort_request(422, 'A valid resource ID is required.');
}

try {
    $result = delete_resource_service()->execute($resourceId, $user);
    if ($result->cleanupStatus === 'pending_cleanup') {
        flash('success', 'Resource access was removed. Private object cleanup is pending and recorded for recovery.');
    } else {
        flash('success', 'Resource deleted. Its metadata and version history remain as retained records.');
    }
    redirect('dashboard.php');
} catch (EduShare\Modules\Resources\Application\ResourceNotFound $exception) {
    abort_request(404, $exception->getMessage());
} catch (EduShare\Modules\Resources\Application\ResourceAccessDenied $exception) {
    abort_request(403, $exception->getMessage());
} catch (Throwable $exception) {
    app_log('error', 'Resource deletion failed', [
        'resource_id' => $resourceId,
        'type' => $exception::class,
    ]);
    abort_request(500, 'The resource could not be deleted safely.');
}
