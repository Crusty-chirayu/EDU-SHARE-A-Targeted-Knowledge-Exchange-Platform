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
$materialId = positive_int($data['material_id'] ?? null);
if ($materialId === null) {
    abort_request(422, 'A valid material ID is required.');
}

$action = toggle_material_favorite((int) $user['id'], $materialId);
json_response([
    'status' => 'success',
    'action' => $action,
    'favorited' => $action === 'added',
]);
