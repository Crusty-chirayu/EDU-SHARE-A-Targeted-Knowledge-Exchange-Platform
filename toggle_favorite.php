<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_method('POST');
$user = require_auth(true);

if (!str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    abort_request(415, 'Favorite requests must use JSON.');
}
$data = request_data();
require_csrf($data);
// material_id remains a temporary transport alias for older clients; both values are stable resource IDs.
$resourceId = positive_int($data['resource_id'] ?? ($data['material_id'] ?? null));
if ($resourceId === null) {
    abort_request(422, 'A valid resource ID is required.');
}

$action = toggle_resource_favorite((int) $user['id'], $resourceId);
json_response([
    'status' => 'success',
    'action' => $action,
    'favorited' => $action === 'added',
    'resource_id' => $resourceId,
]);
