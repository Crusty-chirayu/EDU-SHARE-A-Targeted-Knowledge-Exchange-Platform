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
$universityId = positive_int($data['university_id'] ?? null);
if ($universityId === null) {
    abort_request(422, 'A valid university ID is required.');
}

$action = toggle_university_favorite((int) $user['id'], $universityId);
json_response([
    'status' => 'success',
    'action' => $action,
    'favorited' => $action === 'added',
]);
