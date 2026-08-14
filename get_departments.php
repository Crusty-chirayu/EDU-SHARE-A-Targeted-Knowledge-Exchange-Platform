<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_method('GET');

$universityId = positive_int($_GET['university_id'] ?? null);
if ($universityId === null) {
    abort_request(422, 'A valid university ID is required.');
}
$statement = db()->prepare(
    'SELECT d.id, d.name
       FROM departments d
       JOIN universities u ON u.id = d.university_id
      WHERE u.id = ? AND u.is_active = 1 AND d.is_active = 1
      ORDER BY d.name'
);
$statement->bind_param('i', $universityId);
$statement->execute();
json_response($statement->get_result()->fetch_all(MYSQLI_ASSOC));
