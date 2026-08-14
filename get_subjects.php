<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require_method('GET');

$courseId = positive_int($_GET['course_id'] ?? null);
$semester = isset($_GET['semester']) ? positive_int($_GET['semester']) : null;
if ($courseId === null || (isset($_GET['semester']) && ($semester === null || $semester > 12))) {
    abort_request(422, 'A valid course and semester are required.');
}

$sql = 'SELECT s.id, s.name, s.semester
          FROM subjects s
          JOIN courses c ON c.id = s.course_id AND c.department_id = s.department_id
          JOIN departments d ON d.id = c.department_id
          JOIN universities u ON u.id = d.university_id
         WHERE c.id = ?
           AND u.is_active = 1 AND d.is_active = 1 AND c.is_active = 1 AND s.is_active = 1';
if ($semester === null) {
    $statement = db()->prepare($sql . ' ORDER BY s.semester, s.name');
    $statement->bind_param('i', $courseId);
} else {
    $statement = db()->prepare($sql . ' AND s.semester = ? ORDER BY s.name');
    $statement->bind_param('ii', $courseId, $semester);
}
$statement->execute();
json_response($statement->get_result()->fetch_all(MYSQLI_ASSOC));
