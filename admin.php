<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_method('GET', 'POST');

require_ability('manage_academics');

if (request_method() === 'POST') {
    require_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    try {
        if ($action === 'add_university') {
            $name = canonical_text($_POST['name'] ?? '');
            if (!valid_taxonomy_name($name)) {
                abort_request(422, 'Enter a valid university name of at most 150 characters.');
            }
            $statement = db()->prepare('INSERT INTO universities (name) VALUES (?)');
            $statement->bind_param('s', $name);
            $statement->execute();
        } elseif ($action === 'add_department') {
            $name = canonical_text($_POST['name'] ?? '');
            $universityId = positive_int($_POST['university_id'] ?? null);
            if (!valid_taxonomy_name($name) || $universityId === null || !db_row_exists('universities', $universityId)) {
                abort_request(422, 'Choose a university and enter a valid department name.');
            }
            $statement = db()->prepare('INSERT INTO departments (university_id, name) VALUES (?, ?)');
            $statement->bind_param('is', $universityId, $name);
            $statement->execute();
        } elseif ($action === 'add_course') {
            $name = canonical_text($_POST['name'] ?? '');
            $departmentId = positive_int($_POST['department_id'] ?? null);
            if (!valid_taxonomy_name($name) || $departmentId === null || !db_row_exists('departments', $departmentId)) {
                abort_request(422, 'Choose a department and enter a valid course name.');
            }
            $statement = db()->prepare('INSERT INTO courses (name, department_id) VALUES (?, ?)');
            $statement->bind_param('si', $name, $departmentId);
            $statement->execute();
        } elseif ($action === 'add_subject') {
            $name = canonical_text($_POST['name'] ?? '');
            $courseId = positive_int($_POST['course_id'] ?? null);
            $semester = positive_int($_POST['semester'] ?? null);
            if (!valid_taxonomy_name($name) || $courseId === null || $semester === null || $semester > 12) {
                abort_request(422, 'Choose a course and semester and enter a valid subject name.');
            }
            $course = db()->prepare('SELECT department_id FROM courses WHERE id = ?');
            $course->bind_param('i', $courseId);
            $course->execute();
            $courseRow = $course->get_result()->fetch_assoc();
            if ($courseRow === null) {
                abort_request(422, 'The selected course does not exist.');
            }
            $statement = db()->prepare('INSERT INTO subjects (name, course_id, department_id, semester) VALUES (?, ?, ?, ?)');
            $statement->bind_param('siii', $name, $courseId, $courseRow['department_id'], $semester);
            $statement->execute();
        } elseif ($action === 'update') {
            $type = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';
            $id = positive_int($_POST['id'] ?? null);
            $name = canonical_text($_POST['name'] ?? '');
            $tables = ['university' => 'universities', 'department' => 'departments', 'course' => 'courses', 'subject' => 'subjects'];
            if (!isset($tables[$type]) || $id === null || !valid_taxonomy_name($name)) {
                abort_request(422, 'The academic record is invalid.');
            }
            $statement = db()->prepare("UPDATE {$tables[$type]} SET name = ? WHERE id = ?");
            $statement->bind_param('si', $name, $id);
            $statement->execute();
            if ($statement->affected_rows === 0 && !db_row_exists($tables[$type], $id)) {
                abort_request(404, 'Academic record not found.');
            }
        } elseif ($action === 'delete') {
            $type = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';
            $id = positive_int($_POST['id'] ?? null);
            $tables = ['university' => 'universities', 'department' => 'departments', 'course' => 'courses', 'subject' => 'subjects'];
            if (!isset($tables[$type]) || $id === null) {
                abort_request(422, 'The academic record is invalid.');
            }
            $statement = db()->prepare("DELETE FROM {$tables[$type]} WHERE id = ?");
            $statement->bind_param('i', $id);
            $statement->execute();
            if ($statement->affected_rows !== 1) {
                abort_request(404, 'Academic record not found.');
            }
        } else {
            abort_request(422, 'Unknown academic operation.');
        }

        flash('success', 'Academic data updated.');
        redirect('admin.php');
    } catch (mysqli_sql_exception $exception) {
        if (in_array($exception->getCode(), [1062, 1451, 1452], true)) {
            flash('error', $exception->getCode() === 1062
                ? 'That academic entry already exists.'
                : 'This change conflicts with related academic or material records.');
            redirect('admin.php');
        }
        throw $exception;
    }
}

$universities = db()->query('SELECT id, name FROM universities ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$departments = db()->query(
    'SELECT d.id, d.name, d.university_id, u.name AS parent_name FROM departments d JOIN universities u ON u.id = d.university_id ORDER BY u.name, d.name'
)->fetch_all(MYSQLI_ASSOC);
$courses = db()->query(
    'SELECT c.id, c.name, c.department_id, d.name AS parent_name FROM courses c JOIN departments d ON d.id = c.department_id ORDER BY d.name, c.name'
)->fetch_all(MYSQLI_ASSOC);
$subjects = db()->query(
    'SELECT s.id, s.name, s.course_id, s.semester, c.name AS parent_name FROM subjects s JOIN courses c ON c.id = s.course_id ORDER BY c.name, s.semester, s.name'
)->fetch_all(MYSQLI_ASSOC);

render_header('Manage academics', 'admin');

function academic_list(string $type, array $items): void
{
    ?>
    <section class="bg-white p-6 rounded-xl shadow">
        <h2 class="text-xl font-bold mb-4"><?= h(ucfirst($type)) ?> records</h2>
        <div class="space-y-3 max-h-96 overflow-auto">
            <?php foreach ($items as $item): ?>
                <div class="border rounded-lg p-3">
                    <form method="post" action="<?= h(app_url('admin.php')) ?>" class="flex flex-wrap gap-2 items-center">
                        <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <input name="name" value="<?= h($item['name']) ?>" maxlength="150" required class="border rounded p-2 flex-1 min-w-40">
                        <span class="text-xs text-gray-500"><?= h($item['parent_name'] ?? '') ?><?= isset($item['semester']) ? ' · semester ' . (int) $item['semester'] : '' ?></span>
                        <button class="bg-yellow-500 text-gray-900 px-3 py-2 rounded">Update</button>
                    </form>
                    <form method="post" action="<?= h(app_url('admin.php')) ?>" class="mt-2">
                        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><button class="text-red-700 text-sm">Delete</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php
}
?>
<main class="max-w-7xl mx-auto py-10 px-4">
    <h1 class="text-4xl font-extrabold mb-2">Manage academic taxonomy</h1>
    <p class="text-gray-600 mb-8">Only explicit administrators can change this hierarchy. Deletion is blocked while dependent records exist.</p>
    <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
        <form method="post" action="<?= h(app_url('admin.php')) ?>" class="bg-white p-5 rounded-xl shadow space-y-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_university"><h2 class="font-bold">Add university</h2><input name="name" maxlength="150" required placeholder="University name" class="w-full border rounded p-2"><button class="bg-blue-600 text-white px-4 py-2 rounded">Add</button></form>
        <form method="post" action="<?= h(app_url('admin.php')) ?>" class="bg-white p-5 rounded-xl shadow space-y-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_department"><h2 class="font-bold">Add department</h2><select name="university_id" required class="w-full border rounded p-2"><option value="">University</option><?php foreach ($universities as $item): ?><option value="<?= (int) $item['id'] ?>"><?= h($item['name']) ?></option><?php endforeach; ?></select><input name="name" maxlength="150" required placeholder="Department name" class="w-full border rounded p-2"><button class="bg-blue-600 text-white px-4 py-2 rounded">Add</button></form>
        <form method="post" action="<?= h(app_url('admin.php')) ?>" class="bg-white p-5 rounded-xl shadow space-y-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_course"><h2 class="font-bold">Add course</h2><select name="department_id" required class="w-full border rounded p-2"><option value="">Department</option><?php foreach ($departments as $item): ?><option value="<?= (int) $item['id'] ?>"><?= h($item['parent_name'] . ' · ' . $item['name']) ?></option><?php endforeach; ?></select><input name="name" maxlength="150" required placeholder="Course name" class="w-full border rounded p-2"><button class="bg-blue-600 text-white px-4 py-2 rounded">Add</button></form>
        <form method="post" action="<?= h(app_url('admin.php')) ?>" class="bg-white p-5 rounded-xl shadow space-y-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_subject"><h2 class="font-bold">Add subject</h2><select name="course_id" required class="w-full border rounded p-2"><option value="">Course</option><?php foreach ($courses as $item): ?><option value="<?= (int) $item['id'] ?>"><?= h($item['parent_name'] . ' · ' . $item['name']) ?></option><?php endforeach; ?></select><select name="semester" required class="w-full border rounded p-2"><option value="">Semester</option><?php for ($semester = 1; $semester <= 12; $semester++): ?><option value="<?= $semester ?>"><?= $semester ?></option><?php endfor; ?></select><input name="name" maxlength="150" required placeholder="Subject name" class="w-full border rounded p-2"><button class="bg-blue-600 text-white px-4 py-2 rounded">Add</button></form>
    </div>
    <div class="grid lg:grid-cols-2 gap-6"><?php academic_list('university', $universities); academic_list('department', $departments); academic_list('course', $courses); academic_list('subject', $subjects); ?></div>
</main>
<?php render_footer(); ?>
