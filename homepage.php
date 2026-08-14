<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_method('GET');

$user = require_auth();
$userId = (int) $user['id'];
$search = canonical_text($_GET['search'] ?? '');
if (mb_strlen($search) > 100) {
    abort_request(422, 'Search text must be at most 100 characters.');
}

$filters = [];
foreach (['university_id', 'course_id', 'subject_id', 'semester'] as $field) {
    if (!isset($_GET[$field]) || $_GET[$field] === '') {
        $filters[$field] = null;
        continue;
    }
    $filters[$field] = positive_int($_GET[$field]);
    if ($filters[$field] === null || ($field === 'semester' && $filters[$field] > 12)) {
        abort_request(422, 'One of the selected filters is invalid.');
    }
}

$sql = "SELECT r.id, r.owner_id, r.title, r.description, r.updated_at, r.semester,
               r.visibility, r.publication_status, r.moderation_status, r.current_version_number,
               c.name AS course_name, d.name AS department_name, s.name AS subject_name,
               u.full_name AS uploader_name, uni.name AS university_name, favorite.id AS favorite_id,
               (SELECT COUNT(*) FROM resource_files file_count
                 JOIN resource_versions current_version ON current_version.id = file_count.resource_version_id
                WHERE current_version.resource_id = r.id
                  AND current_version.version_number = r.current_version_number
                  AND file_count.storage_status = 'available') AS file_count,
               (SELECT COUNT(*) FROM resource_versions version_count
                 WHERE version_count.resource_id = r.id) AS version_count
          FROM resources r
          JOIN courses c ON c.id = r.course_id
          JOIN departments d ON d.id = r.department_id
          JOIN subjects s ON s.id = r.subject_id
          JOIN users u ON u.id = r.owner_id
          JOIN universities uni ON uni.id = r.university_id
          LEFT JOIN resource_favorites favorite ON favorite.resource_id = r.id AND favorite.user_id = ?
         WHERE r.deletion_status = 'active'";
$params = [$userId];
$types = 'i';
if (!role_can($user['user_type'], 'moderate_resources')) {
    $sql .= " AND ((r.publication_status = 'published' AND r.moderation_status = 'approved'
                    AND r.visibility IN ('public', 'authenticated')) OR r.owner_id = ?)";
    $params[] = $userId;
    $types .= 'i';
}
if ($search !== '') {
    $sql .= ' AND (r.title LIKE ? OR r.description LIKE ? OR c.name LIKE ? OR s.name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
$fieldColumns = [
    'university_id' => 'r.university_id',
    'course_id' => 'r.course_id',
    'subject_id' => 'r.subject_id',
    'semester' => 'r.semester',
];
foreach ($fieldColumns as $field => $column) {
    if ($filters[$field] !== null) {
        $sql .= " AND {$column} = ?";
        $params[] = $filters[$field];
        $types .= 'i';
    }
}
$sql .= ' ORDER BY r.updated_at DESC LIMIT 100';
$statement = db()->prepare($sql);
$statement->bind_param($types, ...$params);
$statement->execute();
$resources = $statement->get_result()->fetch_all(MYSQLI_ASSOC);

$universities = db()->query('SELECT id, name FROM universities ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$courses = db()->query('SELECT id, name FROM courses ORDER BY name')->fetch_all(MYSQLI_ASSOC);
$subjects = db()->query('SELECT id, name, semester FROM subjects ORDER BY name')->fetch_all(MYSQLI_ASSOC);

$universityFavorites = [];
$favoritesStatement = db()->prepare('SELECT university_id FROM university_favorites WHERE user_id = ?');
$favoritesStatement->bind_param('i', $userId);
$favoritesStatement->execute();
foreach ($favoritesStatement->get_result()->fetch_all(MYSQLI_ASSOC) as $favorite) {
    $universityFavorites[(int) $favorite['university_id']] = true;
}

render_header('Resource library', 'library');
?>
<main class="max-w-7xl mx-auto py-10 px-4">
    <h1 class="text-4xl font-extrabold text-center mb-3">Resource library</h1>
    <p class="status-message text-center mb-5" data-status-message role="status"></p>

    <form method="get" action="<?= h(app_url('homepage.php')) ?>" class="bg-white p-5 rounded-xl shadow mb-8 grid md:grid-cols-6 gap-3">
        <label class="md:col-span-2"><span class="sr-only">Search</span><input name="search" value="<?= h($search) ?>" maxlength="100" placeholder="Search title, description, course, subject" class="w-full border rounded-lg p-3"></label>
        <label><span class="sr-only">University</span><select name="university_id" class="w-full border rounded-lg p-3"><option value="">All universities</option><?php foreach ($universities as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $filters['university_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['name']) ?></option><?php endforeach; ?></select></label>
        <label><span class="sr-only">Course</span><select name="course_id" class="w-full border rounded-lg p-3"><option value="">All courses</option><?php foreach ($courses as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $filters['course_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['name']) ?></option><?php endforeach; ?></select></label>
        <label><span class="sr-only">Subject</span><select name="subject_id" class="w-full border rounded-lg p-3"><option value="">All subjects</option><?php foreach ($subjects as $item): ?><option value="<?= (int) $item['id'] ?>" <?= $filters['subject_id'] === (int) $item['id'] ? 'selected' : '' ?>><?= h($item['name']) ?></option><?php endforeach; ?></select></label>
        <label><span class="sr-only">Semester</span><select name="semester" class="w-full border rounded-lg p-3"><option value="">All semesters</option><?php for ($semester = 1; $semester <= 12; $semester++): ?><option value="<?= $semester ?>" <?= $filters['semester'] === $semester ? 'selected' : '' ?>>Semester <?= $semester ?></option><?php endfor; ?></select></label>
        <div class="md:col-span-6 flex gap-3 justify-end"><a href="<?= h(app_url('homepage.php')) ?>" class="px-5 py-2 text-gray-700">Clear</a><button class="bg-blue-600 text-white px-6 py-2 rounded-lg">Search</button></div>
    </form>

    <section class="mb-8">
        <h2 class="text-2xl font-bold mb-3">Universities</h2>
        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
            <?php foreach ($universities as $university): $favorited = isset($universityFavorites[(int) $university['id']]); ?>
                <article class="bg-white p-4 rounded-lg shadow flex justify-between gap-3">
                    <a class="font-semibold text-blue-700" href="<?= h(app_url('homepage.php?university_id=' . (int) $university['id'])) ?>"><?= h($university['name']) ?></a>
                    <button type="button" data-favorite-university="<?= (int) $university['id'] ?>" data-endpoint="<?= h(app_url('toggle_university_favorite.php')) ?>" aria-label="Toggle favorite university" aria-pressed="<?= $favorited ? 'true' : 'false' ?>" class="text-xl"><?= $favorited ? '♥' : '♡' ?></button>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <section>
        <h2 class="text-2xl font-bold mb-4">Resources</h2>
        <?php if ($resources === []): ?><p class="bg-white p-8 rounded-xl text-center text-gray-600">No resources match these filters.</p><?php endif; ?>
        <div class="grid md:grid-cols-2 gap-5">
            <?php foreach ($resources as $resource): $favorited = $resource['favorite_id'] !== null; ?>
                <article data-resource-card class="bg-white p-6 rounded-xl shadow border-l-4 border-green-500">
                    <div class="flex justify-between gap-3"><h3 class="text-xl font-bold"><?= h($resource['title']) ?></h3><button type="button" data-favorite-resource="<?= (int) $resource['id'] ?>" data-endpoint="<?= h(app_url('toggle_favorite.php')) ?>" data-active="<?= $favorited ? 'true' : 'false' ?>" aria-pressed="<?= $favorited ? 'true' : 'false' ?>" class="text-red-700 whitespace-nowrap"><?= $favorited ? '♥ Favorited' : '♡ Favorite' ?></button></div>
                    <p class="text-gray-700 my-3 whitespace-pre-line"><?= h($resource['description']) ?></p>
                    <dl class="text-sm text-gray-600 grid grid-cols-[auto_1fr] gap-x-2 gap-y-1">
                        <dt class="font-semibold">University</dt><dd><?= h($resource['university_name']) ?></dd>
                        <dt class="font-semibold">Department</dt><dd><?= h($resource['department_name']) ?></dd>
                        <dt class="font-semibold">Course</dt><dd><?= h($resource['course_name']) ?></dd>
                        <dt class="font-semibold">Subject</dt><dd><?= h($resource['subject_name']) ?> · Semester <?= (int) $resource['semester'] ?></dd>
                        <dt class="font-semibold">Contributor</dt><dd><a class="text-blue-700" href="<?= h(app_url('teacher_profile.php?id=' . (int) $resource['owner_id'])) ?>"><?= h($resource['uploader_name']) ?></a></dd>
                        <dt class="font-semibold">Contents</dt><dd><?= (int) $resource['file_count'] ?> file(s) in version <?= (int) $resource['current_version_number'] ?> · <?= (int) $resource['version_count'] ?> version(s)</dd>
                    </dl>
                    <div class="flex gap-3 mt-5"><a target="_blank" rel="noopener" href="<?= h(app_url('resource.php?id=' . (int) $resource['id'])) ?>" class="bg-gray-200 px-4 py-2 rounded-lg">Open resource</a><a href="<?= h(app_url('resource.php?id=' . (int) $resource['id'])) ?>" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Files & versions</a></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php render_footer(); ?>
