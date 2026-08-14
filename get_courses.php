<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_method('GET');

$departmentId = positive_int($_GET['department_id'] ?? null);
if ($departmentId === null) {
    abort_request(422, 'A valid department ID is required.');
}
$statement = db()->prepare(
    'SELECT c.id, c.name
       FROM courses c
       JOIN departments d ON d.id = c.department_id
       JOIN universities u ON u.id = d.university_id
      WHERE d.id = ? AND u.is_active = 1 AND d.is_active = 1 AND c.is_active = 1
      ORDER BY c.name'
);
$statement->bind_param('i', $departmentId);
$statement->execute();
json_response($statement->get_result()->fetch_all(MYSQLI_ASSOC));
