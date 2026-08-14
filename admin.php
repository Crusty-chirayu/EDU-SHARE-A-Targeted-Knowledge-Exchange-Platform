<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_method('GET', 'POST');

$administrator = require_ability('manage_academics');

/** @return array{table: string, singular: string} */
function taxonomy_type(string $type): array
{
    $types = [
        'university' => ['table' => 'universities', 'singular' => 'university'],
        'department' => ['table' => 'departments', 'singular' => 'department'],
        'course' => ['table' => 'courses', 'singular' => 'course'],
        'subject' => ['table' => 'subjects', 'singular' => 'subject'],
    ];
    if (!isset($types[$type])) {
        abort_request(422, 'The academic record type is invalid.');
    }
    return $types[$type];
}

/** @return array<string, mixed> */
function lock_taxonomy_record(mysqli $connection, string $type, int $id): array
{
    $table = taxonomy_type($type)['table'];
    $statement = $connection->prepare("SELECT id, name, is_active FROM {$table} WHERE id = ? FOR UPDATE");
    $statement->bind_param('i', $id);
    $statement->execute();
    $record = $statement->get_result()->fetch_assoc();
    if ($record === null) {
        abort_request(404, 'Academic record not found.');
    }
    return $record;
}

function lock_active_taxonomy_parent(mysqli $connection, string $type, int $parentId): void
{
    $parentTables = ['department' => 'universities', 'course' => 'departments', 'subject' => 'courses'];
    if (!isset($parentTables[$type])) {
        return;
    }
    $table = $parentTables[$type];
    $statement = $connection->prepare("SELECT id FROM {$table} WHERE id = ? AND is_active = 1 FOR UPDATE");
    $statement->bind_param('i', $parentId);
    $statement->execute();
    if ($statement->get_result()->num_rows !== 1) {
        abort_request(422, 'The selected parent is unavailable or retired.');
    }
}

function taxonomy_has_active_children(mysqli $connection, string $type, int $id): bool
{
    $queries = [
        'university' => 'SELECT id FROM departments WHERE university_id = ? AND is_active = 1 LIMIT 1 FOR UPDATE',
        'department' => 'SELECT id FROM courses WHERE department_id = ? AND is_active = 1 LIMIT 1 FOR UPDATE',
        'course' => 'SELECT id FROM subjects WHERE course_id = ? AND is_active = 1 LIMIT 1 FOR UPDATE',
    ];
    if (!isset($queries[$type])) {
        return false;
    }
    $statement = $connection->prepare($queries[$type]);
    $statement->bind_param('i', $id);
    $statement->execute();
    return $statement->get_result()->num_rows > 0;
}

function record_taxonomy_event(
    mysqli $connection,
    int $actorId,
    string $type,
    int $entityId,
    string $action,
    ?string $beforeName,
    ?string $afterName
): void {
    $statement = $connection->prepare(
        'INSERT INTO academic_taxonomy_events
            (actor_id, entity_type, entity_id, action, before_name, after_name)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $statement->bind_param('isisss', $actorId, $type, $entityId, $action, $beforeName, $afterName);
    $statement->execute();
}

if (request_method() === 'POST') {
    require_csrf();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $connection = db();
    $connection->begin_transaction();
    try {
        $eventType = '';
        $eventId = 0;
        $eventAction = 'created';
        $beforeName = null;
        $afterName = null;

        if ($action === 'add_university') {
            $name = canonical_text($_POST['name'] ?? '');
            if (!valid_taxonomy_name($name)) {
                abort_request(422, 'Enter a valid university name of at most 150 characters.');
            }
            $statement = $connection->prepare('INSERT INTO universities (name) VALUES (?)');
            $statement->bind_param('s', $name);
            $statement->execute();
            $eventType = 'university';
            $eventId = (int) $connection->insert_id;
            $afterName = $name;
        } elseif ($action === 'add_department') {
            $name = canonical_text($_POST['name'] ?? '');
            $universityId = positive_int($_POST['university_id'] ?? null);
            if (!valid_taxonomy_name($name) || $universityId === null) {
                abort_request(422, 'Choose an active university and enter a valid department name.');
            }
            lock_active_taxonomy_parent($connection, 'department', $universityId);
            $statement = $connection->prepare('INSERT INTO departments (university_id, name) VALUES (?, ?)');
            $statement->bind_param('is', $universityId, $name);
            $statement->execute();
            $eventType = 'department';
            $eventId = (int) $connection->insert_id;
            $afterName = $name;
        } elseif ($action === 'add_course') {
            $name = canonical_text($_POST['name'] ?? '');
            $departmentId = positive_int($_POST['department_id'] ?? null);
            if (!valid_taxonomy_name($name) || $departmentId === null) {
                abort_request(422, 'Choose an active department and enter a valid course name.');
            }
            lock_active_taxonomy_parent($connection, 'course', $departmentId);
            $statement = $connection->prepare('INSERT INTO courses (name, department_id) VALUES (?, ?)');
            $statement->bind_param('si', $name, $departmentId);
            $statement->execute();
            $eventType = 'course';
            $eventId = (int) $connection->insert_id;
            $afterName = $name;
        } elseif ($action === 'add_subject') {
            $name = canonical_text($_POST['name'] ?? '');
            $courseId = positive_int($_POST['course_id'] ?? null);
            $semester = positive_int($_POST['semester'] ?? null);
            if (!valid_taxonomy_name($name) || $courseId === null || $semester === null || $semester > 12) {
                abort_request(422, 'Choose an active course and semester and enter a valid subject name.');
            }
            lock_active_taxonomy_parent($connection, 'subject', $courseId);
            $course = $connection->prepare(
                'SELECT c.department_id
                   FROM courses c
                   JOIN departments d ON d.id = c.department_id
                   JOIN universities u ON u.id = d.university_id
                  WHERE c.id = ? AND c.is_active = 1 AND d.is_active = 1 AND u.is_active = 1
                  FOR UPDATE'
            );
            $course->bind_param('i', $courseId);
            $course->execute();
            $courseRow = $course->get_result()->fetch_assoc();
            if ($courseRow === null) {
                abort_request(422, 'The selected course hierarchy is unavailable.');
            }
            $statement = $connection->prepare(
                'INSERT INTO subjects (name, course_id, department_id, semester) VALUES (?, ?, ?, ?)'
            );
            $statement->bind_param('siii', $name, $courseId, $courseRow['department_id'], $semester);
            $statement->execute();
            $eventType = 'subject';
            $eventId = (int) $connection->insert_id;
            $afterName = $name;
        } elseif ($action === 'rename') {
            $eventType = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';
            $eventId = positive_int($_POST['id'] ?? null) ?? 0;
            $name = canonical_text($_POST['name'] ?? '');
            if ($eventId < 1 || !valid_taxonomy_name($name)) {
                abort_request(422, 'The academic record is invalid.');
            }
            $record = lock_taxonomy_record($connection, $eventType, $eventId);
            $beforeName = (string) $record['name'];
            if ($beforeName === $name) {
                abort_request(422, 'The academic name is unchanged.');
            }
            $afterName = $name;
            $eventAction = 'renamed';
            $table = taxonomy_type($eventType)['table'];
            $statement = $connection->prepare("UPDATE {$table} SET name = ? WHERE id = ?");
            $statement->bind_param('si', $name, $eventId);
            $statement->execute();
        } elseif ($action === 'set_status') {
            $eventType = is_string($_POST['type'] ?? null) ? $_POST['type'] : '';
            $eventId = positive_int($_POST['id'] ?? null) ?? 0;
            $activeInput = is_string($_POST['is_active'] ?? null) ? $_POST['is_active'] : '';
            if ($eventId < 1 || !in_array($activeInput, ['0', '1'], true)) {
                abort_request(422, 'The academic lifecycle change is invalid.');
            }
            $record = lock_taxonomy_record($connection, $eventType, $eventId);
            $isActive = $activeInput === '1' ? 1 : 0;
            if ((int) $record['is_active'] === $isActive) {
                abort_request(422, 'The academic record already has that lifecycle status.');
            }
            if ($isActive === 0 && taxonomy_has_active_children($connection, $eventType, $eventId)) {
                abort_request(422, 'Retire active child records first; descendants are never changed implicitly.');
            }
            if ($isActive === 1) {
                if ($eventType === 'department') {
                    $parent = $connection->prepare('SELECT university_id FROM departments WHERE id = ?');
                } elseif ($eventType === 'course') {
                    $parent = $connection->prepare('SELECT department_id FROM courses WHERE id = ?');
                } elseif ($eventType === 'subject') {
                    $parent = $connection->prepare('SELECT course_id FROM subjects WHERE id = ?');
                } else {
                    $parent = null;
                }
                if ($parent !== null) {
                    $parent->bind_param('i', $eventId);
                    $parent->execute();
                    $parentId = (int) array_values($parent->get_result()->fetch_assoc())[0];
                    lock_active_taxonomy_parent($connection, $eventType, $parentId);
                }
            }
            $table = taxonomy_type($eventType)['table'];
            $statement = $connection->prepare("UPDATE {$table} SET is_active = ? WHERE id = ?");
            $statement->bind_param('ii', $isActive, $eventId);
            $statement->execute();
            $eventAction = $isActive === 1 ? 'activated' : 'retired';
            $beforeName = (string) $record['name'];
            $afterName = (string) $record['name'];
        } else {
            abort_request(422, 'Unknown academic operation.');
        }

        record_taxonomy_event(
            $connection,
            (int) $administrator['id'],
            $eventType,
            $eventId,
            $eventAction,
            $beforeName,
            $afterName
        );
        $connection->commit();
        flash('success', 'Academic taxonomy updated and audited.');
        redirect('admin.php');
    } catch (mysqli_sql_exception $exception) {
        $connection->rollback();
        if (in_array($exception->getCode(), [1062, 1451, 1452, 3819, 4025], true)) {
            flash('error', $exception->getCode() === 1062
                ? 'That academic entry already exists under the selected parent.'
                : 'This change conflicts with the governed academic hierarchy.');
            redirect('admin.php');
        }
        throw $exception;
    } catch (Throwable $exception) {
        $connection->rollback();
        throw $exception;
    }
}

$universities = db()->query(
    'SELECT id, name, is_active FROM universities ORDER BY is_active DESC, name'
)->fetch_all(MYSQLI_ASSOC);
$departments = db()->query(
    'SELECT d.id, d.name, d.university_id, d.is_active, u.name AS parent_name, u.is_active AS parent_active
       FROM departments d JOIN universities u ON u.id = d.university_id
      ORDER BY d.is_active DESC, u.name, d.name'
)->fetch_all(MYSQLI_ASSOC);
$courses = db()->query(
    'SELECT c.id, c.name, c.department_id, c.is_active, d.name AS parent_name, d.is_active AS parent_active
       FROM courses c JOIN departments d ON d.id = c.department_id
      ORDER BY c.is_active DESC, d.name, c.name'
)->fetch_all(MYSQLI_ASSOC);
$subjects = db()->query(
    'SELECT s.id, s.name, s.course_id, s.semester, s.is_active,
            c.name AS parent_name, c.is_active AS parent_active
       FROM subjects s JOIN courses c ON c.id = s.course_id AND c.department_id = s.department_id
      ORDER BY s.is_active DESC, c.name, s.semester, s.name'
)->fetch_all(MYSQLI_ASSOC);
$events = db()->query(
    'SELECT e.entity_type, e.entity_id, e.action, e.before_name, e.after_name,
            e.occurred_at, u.full_name AS actor_name
       FROM academic_taxonomy_events e
       JOIN users u ON u.id = e.actor_id
      ORDER BY e.id DESC LIMIT 25'
)->fetch_all(MYSQLI_ASSOC);

render_header('Manage academics', 'admin');

function academic_list(string $type, array $items): void
{
    ?>
    <section class="bg-white p-6 rounded-xl shadow">
        <h2 class="text-xl font-bold mb-4"><?= h(ucfirst($type)) ?> records</h2>
        <div class="space-y-3 max-h-96 overflow-auto">
            <?php foreach ($items as $item): ?>
                <div class="border rounded-lg p-3 <?= (int) $item['is_active'] === 1 ? '' : 'bg-gray-100' ?>">
                    <form method="post" action="<?= h(app_url('admin.php')) ?>" class="flex flex-wrap gap-2 items-center">
                        <?= csrf_field() ?><input type="hidden" name="action" value="rename"><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
                        <input name="name" value="<?= h($item['name']) ?>" maxlength="150" required class="border rounded p-2 flex-1 min-w-40">
                        <span class="text-xs text-gray-500"><?= h($item['parent_name'] ?? '') ?><?= isset($item['semester']) ? ' · semester ' . (int) $item['semester'] : '' ?> · <?= (int) $item['is_active'] === 1 ? 'active' : 'retired' ?></span>
                        <button class="bg-yellow-500 text-gray-900 px-3 py-2 rounded">Rename</button>
                    </form>
                    <form method="post" action="<?= h(app_url('admin.php')) ?>" class="mt-2">
                        <?= csrf_field() ?><input type="hidden" name="action" value="set_status"><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="id" value="<?= (int) $item['id'] ?>"><input type="hidden" name="is_active" value="<?= (int) $item['is_active'] === 1 ? '0' : '1' ?>"><button class="<?= (int) $item['is_active'] === 1 ? 'text-red-700' : 'text-green-700' ?> text-sm"><?= (int) $item['is_active'] === 1 ? 'Retire' : 'Reactivate' ?></button>
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
    <p class="text-gray-600 mb-8">University → department → course/program → subject and semester. Only administrators can mutate this hierarchy. Referenced records are retired, never deleted; active descendants must be retired explicitly first.</p>
    <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
        <form method="post" action="<?= h(app_url('admin.php')) ?>" class="bg-white p-5 rounded-xl shadow space-y-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_university"><h2 class="font-bold">Add university</h2><input name="name" maxlength="150" required placeholder="University name" class="w-full border rounded p-2"><button class="bg-blue-600 text-white px-4 py-2 rounded">Add</button></form>
        <form method="post" action="<?= h(app_url('admin.php')) ?>" class="bg-white p-5 rounded-xl shadow space-y-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_department"><h2 class="font-bold">Add department</h2><select name="university_id" required class="w-full border rounded p-2"><option value="">Active university</option><?php foreach ($universities as $item): ?><?php if ((int) $item['is_active'] === 1): ?><option value="<?= (int) $item['id'] ?>"><?= h($item['name']) ?></option><?php endif; ?><?php endforeach; ?></select><input name="name" maxlength="150" required placeholder="Department name" class="w-full border rounded p-2"><button class="bg-blue-600 text-white px-4 py-2 rounded">Add</button></form>
        <form method="post" action="<?= h(app_url('admin.php')) ?>" class="bg-white p-5 rounded-xl shadow space-y-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_course"><h2 class="font-bold">Add course/program</h2><select name="department_id" required class="w-full border rounded p-2"><option value="">Active department</option><?php foreach ($departments as $item): ?><?php if ((int) $item['is_active'] === 1 && (int) $item['parent_active'] === 1): ?><option value="<?= (int) $item['id'] ?>"><?= h($item['parent_name'] . ' · ' . $item['name']) ?></option><?php endif; ?><?php endforeach; ?></select><input name="name" maxlength="150" required placeholder="Course/program name" class="w-full border rounded p-2"><button class="bg-blue-600 text-white px-4 py-2 rounded">Add</button></form>
        <form method="post" action="<?= h(app_url('admin.php')) ?>" class="bg-white p-5 rounded-xl shadow space-y-3"><?= csrf_field() ?><input type="hidden" name="action" value="add_subject"><h2 class="font-bold">Add subject</h2><select name="course_id" required class="w-full border rounded p-2"><option value="">Active course</option><?php foreach ($courses as $item): ?><?php if ((int) $item['is_active'] === 1 && (int) $item['parent_active'] === 1): ?><option value="<?= (int) $item['id'] ?>"><?= h($item['parent_name'] . ' · ' . $item['name']) ?></option><?php endif; ?><?php endforeach; ?></select><select name="semester" required class="w-full border rounded p-2"><option value="">Semester</option><?php for ($semester = 1; $semester <= 12; $semester++): ?><option value="<?= $semester ?>"><?= $semester ?></option><?php endfor; ?></select><input name="name" maxlength="150" required placeholder="Subject name" class="w-full border rounded p-2"><button class="bg-blue-600 text-white px-4 py-2 rounded">Add</button></form>
    </div>
    <div class="grid lg:grid-cols-2 gap-6"><?php academic_list('university', $universities); academic_list('department', $departments); academic_list('course', $courses); academic_list('subject', $subjects); ?></div>
    <section class="bg-white p-6 rounded-xl shadow mt-8">
        <h2 class="text-xl font-bold mb-4">Recent governed changes</h2>
        <?php if ($events === []): ?><p class="text-gray-600">No taxonomy changes have been recorded.</p><?php endif; ?>
        <ul class="divide-y"><?php foreach ($events as $event): ?><li class="py-2 text-sm"><strong><?= h($event['actor_name']) ?></strong> <?= h($event['action']) ?> <?= h($event['entity_type']) ?> #<?= (int) $event['entity_id'] ?><?php if ($event['before_name'] !== $event['after_name']): ?>: <?= h($event['before_name'] ?? '') ?> → <?= h($event['after_name'] ?? '') ?><?php endif; ?> <span class="text-gray-500"><?= h($event['occurred_at']) ?></span></li><?php endforeach; ?></ul>
    </section>
</main>
<?php render_footer(); ?>
