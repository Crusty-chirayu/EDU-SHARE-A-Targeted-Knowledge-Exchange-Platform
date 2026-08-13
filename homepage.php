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

$sql = "SELECT m.id, m.user_id, m.title, m.description, m.upload_date, m.semester,
               m.visibility, m.status, c.name AS course_name, d.name AS department_name,
               s.name AS subject_name, u.full_name AS uploader_name, uni.name AS university_name,
               mf.id AS favorite_id
          FROM materials m
          JOIN courses c ON c.id = m.course_id
          JOIN departments d ON d.id = m.department_id
          JOIN subjects s ON s.id = m.subject_id
          JOIN users u ON u.id = m.user_id
          JOIN universities uni ON uni.id = m.university_id
          LEFT JOIN material_favorites mf ON mf.material_id = m.id AND mf.user_id = ?
         WHERE 1=1";
$params = [$userId];
$types = 'i';
if (!role_can($user['user_type'], 'moderate_materials')) {
    $sql .= " AND ((m.status = 'published' AND m.visibility IN ('public', 'authenticated')) OR m.user_id = ?)";
    $params[] = $userId;
    $types .= 'i';
}
if ($search !== '') {
    $sql .= ' AND (m.title LIKE ? OR m.description LIKE ? OR c.name LIKE ? OR s.name LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
$fieldColumns = [
    'university_id' => 'm.university_id',
    'course_id' => 'm.course_id',
    'subject_id' => 'm.subject_id',
    'semester' => 'm.semester',
];
foreach ($fieldColumns as $field => $column) {
    if ($filters[$field] !== null) {
        $sql .= " AND {$column} = ?";
        $params[] = $filters[$field];
        $types .= 'i';
    }
}
$sql .= ' ORDER BY m.upload_date DESC LIMIT 100';
$statement = db()->prepare($sql);
$statement->bind_param($types, ...$params);
$statement->execute();
$materials = $statement->get_result()->fetch_all(MYSQLI_ASSOC);

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

render_header('Material library', 'library');
?>
<main class="max-w-7xl mx-auto py-10 px-4">
    <h1 class="text-4xl font-extrabold text-center mb-3">Material library</h1>
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
        <?php if ($materials === []): ?><p class="bg-white p-8 rounded-xl text-center text-gray-600">No materials match these filters.</p><?php endif; ?>
        <div class="grid md:grid-cols-2 gap-5">
            <?php foreach ($materials as $material): $favorited = $material['favorite_id'] !== null; ?>
                <article data-material-card class="bg-white p-6 rounded-xl shadow border-l-4 border-green-500">
                    <div class="flex justify-between gap-3"><h3 class="text-xl font-bold"><?= h($material['title']) ?></h3><button type="button" data-favorite-material="<?= (int) $material['id'] ?>" data-endpoint="<?= h(app_url('toggle_favorite.php')) ?>" data-active="<?= $favorited ? 'true' : 'false' ?>" aria-pressed="<?= $favorited ? 'true' : 'false' ?>" class="text-red-700 whitespace-nowrap"><?= $favorited ? '♥ Favorited' : '♡ Favorite' ?></button></div>
                    <p class="text-gray-700 my-3 whitespace-pre-line"><?= h($material['description']) ?></p>
                    <dl class="text-sm text-gray-600 grid grid-cols-[auto_1fr] gap-x-2 gap-y-1">
                        <dt class="font-semibold">University</dt><dd><?= h($material['university_name']) ?></dd>
                        <dt class="font-semibold">Department</dt><dd><?= h($material['department_name']) ?></dd>
                        <dt class="font-semibold">Course</dt><dd><?= h($material['course_name']) ?></dd>
                        <dt class="font-semibold">Subject</dt><dd><?= h($material['subject_name']) ?> · Semester <?= (int) $material['semester'] ?></dd>
                        <dt class="font-semibold">Contributor</dt><dd><a class="text-blue-700" href="<?= h(app_url('teacher_profile.php?id=' . (int) $material['user_id'])) ?>"><?= h($material['uploader_name']) ?></a></dd>
                    </dl>
                    <div class="flex gap-3 mt-5"><a target="_blank" rel="noopener" href="<?= h(app_url('download.php?id=' . (int) $material['id'])) ?>" class="bg-gray-200 px-4 py-2 rounded-lg">View</a><a href="<?= h(app_url('download.php?id=' . (int) $material['id'] . '&download=1')) ?>" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Download</a></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php render_footer(); ?>
