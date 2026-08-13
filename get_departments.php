<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_method('GET');

$universityId = positive_int($_GET['id'] ?? null);
if ($universityId === null) {
    abort_request(422, 'A valid university ID is required.');
}
$statement = db()->prepare('SELECT id, name FROM departments WHERE university_id = ? ORDER BY name');
$statement->bind_param('i', $universityId);
$statement->execute();
json_response($statement->get_result()->fetch_all(MYSQLI_ASSOC));
