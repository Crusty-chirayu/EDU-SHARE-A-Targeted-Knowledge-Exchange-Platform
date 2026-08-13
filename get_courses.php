<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_method('GET');

$departmentId = positive_int($_GET['id'] ?? null);
if ($departmentId === null) {
    abort_request(422, 'A valid department ID is required.');
}
$statement = db()->prepare('SELECT id, name FROM courses WHERE department_id = ? ORDER BY name');
$statement->bind_param('i', $departmentId);
$statement->execute();
json_response($statement->get_result()->fetch_all(MYSQLI_ASSOC));
