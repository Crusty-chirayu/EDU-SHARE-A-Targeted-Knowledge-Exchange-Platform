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
$title = canonical_text($_POST['title'] ?? '');
$description = canonical_text($_POST['description'] ?? '', true);
$visibility = canonical_text($_POST['visibility'] ?? '');

try {
    $changes = new EduShare\Modules\Resources\Application\ResourceMetadataUpdate(
        $title,
        $description === '' ? null : $description,
        $visibility
    );
    update_resource_service()->execute($resourceId, $user, $changes);
    flash('success', 'Resource metadata updated. File and version history was unchanged.');
    redirect('resource.php?id=' . $resourceId);
} catch (EduShare\Modules\Resources\Application\ResourceNotFound $exception) {
    abort_request(404, $exception->getMessage());
} catch (EduShare\Modules\Resources\Application\ResourceAccessDenied $exception) {
    abort_request(403, $exception->getMessage());
} catch (InvalidArgumentException $exception) {
    flash('error', $exception->getMessage());
    redirect('resource.php?id=' . $resourceId);
} catch (Throwable $exception) {
    app_log('error', 'Resource metadata update failed', [
        'resource_id' => $resourceId,
        'user_id' => $user['id'],
        'type' => $exception::class,
        'code' => $exception->getCode(),
    ]);
    flash('error', 'The resource metadata could not be updated.');
    redirect('resource.php?id=' . $resourceId);
}
