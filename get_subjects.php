<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_method('GET');

$courseId = positive_int($_GET['id'] ?? null);
$semester = isset($_GET['semester']) ? positive_int($_GET['semester']) : null;
if ($courseId === null || (isset($_GET['semester']) && ($semester === null || $semester > 12))) {
    abort_request(422, 'A valid course and semester are required.');
}

if ($semester === null) {
    $statement = db()->prepare('SELECT id, name, semester FROM subjects WHERE course_id = ? ORDER BY semester, name');
    $statement->bind_param('i', $courseId);
} else {
    $statement = db()->prepare('SELECT id, name, semester FROM subjects WHERE course_id = ? AND semester = ? ORDER BY name');
    $statement->bind_param('ii', $courseId, $semester);
}
$statement->execute();
json_response($statement->get_result()->fetch_all(MYSQLI_ASSOC));
