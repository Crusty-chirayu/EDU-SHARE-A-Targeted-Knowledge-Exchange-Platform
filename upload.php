<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_method('GET');

$user = require_ability('upload_material');
$isPrivileged = role_can($user['user_type'], 'moderate_materials');

if ($isPrivileged) {
    $universities = db()->query('SELECT id, name FROM universities ORDER BY name')->fetch_all(MYSQLI_ASSOC);
    $departments = [];
    $selectedUniversity = null;
    $selectedDepartment = null;
} else {
    $selectedUniversity = positive_int($user['university_id']);
    $selectedDepartment = positive_int($user['department_id']);
    if ($selectedUniversity === null || $selectedDepartment === null) {
        abort_request(403, 'Your account needs a valid university and department before uploading.');
    }
    $statement = db()->prepare('SELECT id, name FROM universities WHERE id = ?');
    $statement->bind_param('i', $selectedUniversity);
    $statement->execute();
    $universities = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
    $statement = db()->prepare('SELECT id, name FROM departments WHERE id = ? AND university_id = ?');
    $statement->bind_param('ii', $selectedDepartment, $selectedUniversity);
    $statement->execute();
    $departments = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
}

$courses = [];
if ($selectedDepartment !== null) {
    $statement = db()->prepare('SELECT id, name FROM courses WHERE department_id = ? ORDER BY name');
    $statement->bind_param('i', $selectedDepartment);
    $statement->execute();
    $courses = $statement->get_result()->fetch_all(MYSQLI_ASSOC);
}

render_header('Upload material', 'upload');
?>
<main class="max-w-2xl mx-auto py-10 px-4">
    <section class="bg-white p-8 rounded-xl shadow">
        <h1 class="text-3xl font-bold text-center mb-2">Upload material</h1>
        <p class="text-center text-gray-600 mb-5">Up to <?= (int) app_config('upload.max_files') ?> files, <?= h(format_bytes((int) app_config('upload.max_bytes'))) ?> each. Allowed: PDF, TXT, DOC, DOCX, XLS, XLSX, PPT, PPTX.</p>
        <p class="status-message mb-4" data-status-message role="status"></p>
        <form action="<?= h(app_url('process_upload.php')) ?>" method="post" enctype="multipart/form-data" class="space-y-4">
            <?= csrf_field() ?>
            <div><label for="title" class="block font-semibold mb-1">Title</label><input id="title" name="title" maxlength="200" required class="w-full border rounded-lg p-3"></div>
            <div><label for="description" class="block font-semibold mb-1">Description</label><textarea id="description" name="description" maxlength="2000" rows="4" class="w-full border rounded-lg p-3"></textarea></div>
            <div class="grid md:grid-cols-2 gap-4">
                <div><label for="university_id" class="block font-semibold mb-1">University</label><select id="university_id" name="university_id" data-university-select required class="w-full border rounded-lg p-3"><option value="">Select university</option><?php foreach ($universities as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $selectedUniversity === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['name']) ?></option><?php endforeach; ?></select></div>
                <div><label for="department_id" class="block font-semibold mb-1">Department</label><select id="department_id" name="department_id" data-department-select data-upload-department data-endpoint="<?= h(app_url('get_departments.php')) ?>" required class="w-full border rounded-lg p-3"><option value="">Select department</option><?php foreach ($departments as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $selectedDepartment === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['name']) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="grid md:grid-cols-2 gap-4">
                <div><label for="course_id" class="block font-semibold mb-1">Course</label><select id="course_id" name="course_id" data-course-select data-endpoint="<?= h(app_url('get_courses.php')) ?>" required class="w-full border rounded-lg p-3"><option value="">Select course</option><?php foreach ($courses as $item): ?><option value="<?= (int) $item['id'] ?>"><?= h($item['name']) ?></option><?php endforeach; ?></select></div>
                <div><label for="semester" class="block font-semibold mb-1">Semester</label><select id="semester" name="semester" data-semester-select required class="w-full border rounded-lg p-3"><option value="">Select semester</option><?php for ($semester = 1; $semester <= 12; $semester++): ?><option value="<?= $semester ?>">Semester <?= $semester ?></option><?php endfor; ?></select></div>
            </div>
            <div><label for="subject_id" class="block font-semibold mb-1">Subject</label><select id="subject_id" name="subject_id" data-subject-select data-endpoint="<?= h(app_url('get_subjects.php')) ?>" required disabled class="w-full border rounded-lg p-3"><option value="">Select course and semester</option></select></div>
            <div><label for="files" class="block font-semibold mb-1">Files</label><input id="files" type="file" name="files[]" multiple required data-upload-files data-max-files="<?= (int) app_config('upload.max_files') ?>" accept=".pdf,.txt,.doc,.docx,.xls,.xlsx,.ppt,.pptx" class="w-full border rounded-lg p-3"></div>
            <button type="submit" class="w-full bg-blue-600 text-white font-bold px-6 py-3 rounded-lg">Upload material</button>
        </form>
    </section>
</main>
<?php render_footer(); ?>
