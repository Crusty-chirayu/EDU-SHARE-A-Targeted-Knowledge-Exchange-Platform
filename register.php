<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_method('GET', 'POST');

if (auth_user() !== null) {
    redirect('dashboard.php', 302);
}

$input = $_POST;
$errors = [];
$values = [];
if (request_method() === 'POST') {
    require_csrf();
    [$values, $errors] = validate_registration_input($_POST);
    if ($errors === []) {
        try {
            register_user($values);
            flash('success', 'Registration successful. You can now log in.');
            redirect('login1.php');
        } catch (mysqli_sql_exception $exception) {
            if ($exception->getCode() === 1062) {
                $errors['email'] = 'An account with that email already exists.';
            } else {
                app_log('error', 'Registration database failure', ['code' => $exception->getCode()]);
                $errors['general'] = 'Registration could not be completed. Please try again.';
            }
        } catch (AppHttpException $exception) {
            if ($exception->status === 422) {
                $errors['academic'] = $exception->safeMessage;
            } else {
                throw $exception;
            }
        }
    }
}

$universities = db()->query(
    'SELECT id, name FROM universities WHERE is_active = 1 ORDER BY name'
)->fetch_all(MYSQLI_ASSOC);
$departments = [];
$courses = [];
$selectedUniversity = positive_int($input['university_id'] ?? null);
$selectedDepartment = positive_int($input['department_id'] ?? null);
$selectedCourse = positive_int($input['course_id'] ?? null);
if ($selectedUniversity !== null) {
    $statement = db()->prepare(
        'SELECT d.id, d.name
           FROM departments d JOIN universities u ON u.id = d.university_id
          WHERE u.id = ? AND u.is_active = 1 AND d.is_active = 1 ORDER BY d.name'
    );
    $statement->bind_param('i', $selectedUniversity);
    $statement->execute();
    $departments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
}
if ($selectedDepartment !== null) {
    $statement = db()->prepare(
        'SELECT c.id, c.name
           FROM courses c
           JOIN departments d ON d.id = c.department_id
           JOIN universities u ON u.id = d.university_id
          WHERE d.id = ? AND u.is_active = 1 AND d.is_active = 1 AND c.is_active = 1
          ORDER BY c.name'
    );
    $statement->bind_param('i', $selectedDepartment);
    $statement->execute();
    $courses = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
}

render_header('Register');
?>
<main class="max-w-2xl mx-auto px-4 py-12">
    <section class="bg-white p-8 rounded-xl shadow-xl">
        <h1 class="text-3xl font-extrabold text-center mb-2">Create your account</h1>
        <p class="text-center text-gray-600 mb-6">Teacher/contributor registration does not grant moderation or administration access.</p>
        <p class="status-message mb-3" data-status-message role="status"></p>
        <?php if ($errors !== []): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded mb-4" role="alert">
                <p class="font-semibold">Please correct the following:</p>
                <ul class="list-disc ml-5"><?php foreach ($errors as $message): ?><li><?= h($message) ?></li><?php endforeach; ?></ul>
            </div>
        <?php endif; ?>
        <form method="post" action="<?= h(app_url('register.php')) ?>" data-academic-chain class="space-y-4">
            <?= csrf_field() ?>
            <div>
                <label for="role" class="block text-sm font-medium mb-1">Account type</label>
                <select name="role" id="role" required class="w-full px-4 py-3 border rounded-lg">
                    <option value="">Select account type</option>
                    <option value="student" <?= ($input['role'] ?? '') === 'student' ? 'selected' : '' ?>>Student</option>
                    <option value="teacher" <?= ($input['role'] ?? '') === 'teacher' ? 'selected' : '' ?>>Teacher / contributor</option>
                </select>
            </div>
            <div class="grid md:grid-cols-2 gap-4">
                <div><label for="name" class="block text-sm font-medium mb-1">Full name</label><input id="name" name="name" value="<?= h($input['name'] ?? '') ?>" maxlength="100" autocomplete="name" required class="w-full px-4 py-3 border rounded-lg"></div>
                <div><label for="email" class="block text-sm font-medium mb-1">Email</label><input id="email" type="email" name="email" value="<?= h($input['email'] ?? '') ?>" maxlength="254" autocomplete="email" required class="w-full px-4 py-3 border rounded-lg"></div>
            </div>
            <div><label for="password" class="block text-sm font-medium mb-1">Password</label><input id="password" type="password" name="password" minlength="10" maxlength="255" autocomplete="new-password" required class="w-full px-4 py-3 border rounded-lg"><p class="text-xs text-gray-500 mt-1">At least 10 characters, including a letter and a number.</p></div>
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label for="university_id" class="block text-sm font-medium mb-1">University</label>
                    <select id="university_id" name="university_id" data-university-select data-departments-endpoint="<?= h(app_url('get_departments.php')) ?>" required class="w-full px-4 py-3 border rounded-lg">
                        <option value="">Select university</option>
                        <?php foreach ($universities as $university): ?><option value="<?= (int) $university['id'] ?>" <?= $selectedUniversity === (int) $university['id'] ? 'selected' : '' ?>><?= h($university['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="department_id" class="block text-sm font-medium mb-1">Department</label>
                    <select id="department_id" name="department_id" data-department-select data-courses-endpoint="<?= h(app_url('get_courses.php')) ?>" required class="w-full px-4 py-3 border rounded-lg">
                        <option value="">Select department</option>
                        <?php foreach ($departments as $department): ?><option value="<?= (int) $department['id'] ?>" <?= $selectedDepartment === (int) $department['id'] ? 'selected' : '' ?>><?= h($department['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="grid md:grid-cols-2 gap-4">
                <div><label for="course_id" class="block text-sm font-medium mb-1">Student course / program</label><select id="course_id" name="course_id" data-course-select class="w-full px-4 py-3 border rounded-lg"><option value="">Select course/program</option><?php foreach ($courses as $course): ?><option value="<?= (int) $course['id'] ?>" <?= $selectedCourse === (int) $course['id'] ? 'selected' : '' ?>><?= h($course['name']) ?></option><?php endforeach; ?></select><p class="text-xs text-gray-500 mt-1">Required for students; selected from the department's governed taxonomy.</p></div>
                <div><label for="year" class="block text-sm font-medium mb-1">Year of study</label><input id="year" type="number" min="1" max="8" name="year" value="<?= h($input['year'] ?? '') ?>" class="w-full px-4 py-3 border rounded-lg"><p class="text-xs text-gray-500 mt-1">Required for students (1–8).</p></div>
            </div>
            <div class="grid md:grid-cols-2 gap-4">
                <div><label for="contact" class="block text-sm font-medium mb-1">Phone (optional, private)</label><input id="contact" type="tel" name="contact" value="<?= h($input['contact'] ?? '') ?>" maxlength="20" autocomplete="tel" class="w-full px-4 py-3 border rounded-lg"></div>
                <div><label for="address" class="block text-sm font-medium mb-1">Address (optional, private)</label><input id="address" name="address" value="<?= h($input['address'] ?? '') ?>" maxlength="255" autocomplete="street-address" class="w-full px-4 py-3 border rounded-lg"></div>
            </div>
            <button type="submit" class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-3 rounded-lg">Register account</button>
        </form>
    </section>
</main>
<?php render_footer(); ?>
