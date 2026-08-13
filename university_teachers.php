<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_method('GET');
require_auth(true);

$universityId = positive_int($_GET['university_id'] ?? null);
if ($universityId === null) {
    abort_request(422, 'A valid university ID is required.');
}
if (!db_row_exists('universities', $universityId)) {
    abort_request(404, 'University not found.');
}

$statement = db()->prepare(
    "SELECT u.id, u.full_name, u.user_type, d.name AS department_name
       FROM users u
       LEFT JOIN departments d ON d.id = u.department_id
      WHERE u.university_id = ? AND u.user_type IN ('teacher', 'moderator', 'admin')
      ORDER BY u.full_name"
);
$statement->bind_param('i', $universityId);
$statement->execute();
json_response($statement->get_result()->fetch_all(MYSQLI_ASSOC));
