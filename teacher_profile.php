<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_method('GET');

$viewer = auth_user();
$profileId = positive_int($_GET['id'] ?? null);
if ($profileId === null) {
    abort_request(422, 'A valid profile ID is required.');
}

$profileStatement = db()->prepare(
    'SELECT u.id, u.full_name, u.user_type, uni.name AS university_name, d.name AS department_name
       FROM users u
       LEFT JOIN universities uni ON uni.id = u.university_id
       LEFT JOIN departments d ON d.id = u.department_id
      WHERE u.id = ? LIMIT 1'
);
$profileStatement->bind_param('i', $profileId);
$profileStatement->execute();
$profile = $profileStatement->get_result()->fetch_assoc();
if ($profile === null) {
    abort_request(404, 'Profile not found.');
}

$resourcesStatement = db()->prepare(
    'SELECT r.id, r.owner_id, r.title, r.description, r.updated_at, r.visibility,
            r.publication_status, r.moderation_status, r.deletion_status, r.current_version_number,
            c.name AS course_name, d.name AS department_name, s.name AS subject_name,
            (SELECT COUNT(*) FROM resource_files file_count
              JOIN resource_versions current_version ON current_version.id = file_count.resource_version_id
             WHERE current_version.resource_id = r.id
               AND current_version.version_number = r.current_version_number
               AND file_count.storage_status = \'available\') AS file_count,
            (SELECT COUNT(*) FROM resource_versions version_count
              WHERE version_count.resource_id = r.id) AS version_count
       FROM resources r
       JOIN courses c ON c.id = r.course_id
       JOIN departments d ON d.id = r.department_id
       JOIN subjects s ON s.id = r.subject_id
      WHERE r.owner_id = ? ORDER BY r.updated_at DESC'
);
$resourcesStatement->bind_param('i', $profileId);
$resourcesStatement->execute();
$resources = array_values(array_filter(
    $resourcesStatement->get_result()->fetch_all(MYSQLI_ASSOC),
    static fn (array $resource): bool => can_view_resource($resource, $viewer)
));

$favoriteIds = [];
if ($viewer !== null) {
    $favoriteStatement = db()->prepare('SELECT resource_id FROM resource_favorites WHERE user_id = ?');
    $favoriteStatement->bind_param('i', $viewer['id']);
    $favoriteStatement->execute();
    foreach ($favoriteStatement->get_result()->fetch_all(MYSQLI_ASSOC) as $favorite) {
        $favoriteIds[(int) $favorite['resource_id']] = true;
    }
}

render_header($profile['full_name'] . ' profile');
?>
<main class="max-w-5xl mx-auto py-10 px-4 space-y-8">
    <section class="bg-white p-8 rounded-xl shadow">
        <div class="flex items-center gap-5"><div class="w-20 h-20 rounded-full bg-blue-100 text-blue-700 flex items-center justify-center text-3xl" aria-hidden="true">👤</div><div><h1 class="text-3xl font-bold"><?= h($profile['full_name']) ?></h1><p class="text-gray-600"><?= h(ucfirst($profile['user_type'])) ?></p></div></div>
        <dl class="grid md:grid-cols-2 gap-4 mt-6">
            <div><dt class="text-xs uppercase text-gray-500">University</dt><dd class="font-semibold"><?= h($profile['university_name'] ?? 'Not specified') ?></dd></div>
            <div><dt class="text-xs uppercase text-gray-500">Department</dt><dd class="font-semibold"><?= h($profile['department_name'] ?? 'Not specified') ?></dd></div>
        </dl>
        <p class="text-sm text-gray-500 mt-5">Email, phone, address, roll number, branch, and year are private by default.</p>
    </section>

    <section class="bg-white p-8 rounded-xl shadow">
        <h2 class="text-2xl font-bold mb-3">Resources</h2>
        <p class="status-message mb-4" data-status-message role="status"></p>
        <?php if ($resources === []): ?><p class="text-gray-600">No resources are available from this profile.</p><?php endif; ?>
        <div class="space-y-4">
            <?php foreach ($resources as $resource): $favorited = isset($favoriteIds[(int) $resource['id']]); ?>
                <article data-resource-card class="bg-gray-50 border p-5 rounded-lg">
                    <div class="flex flex-wrap justify-between gap-3"><h3 class="text-lg font-bold"><?= h($resource['title']) ?></h3><?php if ($viewer !== null && (int) $viewer['id'] !== $profileId): ?><button type="button" data-favorite-resource="<?= (int) $resource['id'] ?>" data-endpoint="<?= h(app_url('toggle_favorite.php')) ?>" aria-pressed="<?= $favorited ? 'true' : 'false' ?>" class="text-red-700"><?= $favorited ? '♥ Favorited' : '♡ Favorite' ?></button><?php endif; ?></div>
                    <p class="text-gray-700 my-2 whitespace-pre-line"><?= h($resource['description']) ?></p>
                    <p class="text-sm text-gray-600"><?= h($resource['department_name']) ?> · <?= h($resource['course_name']) ?> · <?= h($resource['subject_name']) ?> · <?= (int) $resource['file_count'] ?> current file(s) · <?= (int) $resource['version_count'] ?> version(s)</p>
                    <div class="flex gap-3 mt-4"><a target="_blank" rel="noopener" href="<?= h(app_url('resource.php?id=' . (int) $resource['id'])) ?>" class="bg-gray-200 px-4 py-2 rounded">Open resource</a><a href="<?= h(app_url('resource.php?id=' . (int) $resource['id'])) ?>" class="bg-blue-600 text-white px-4 py-2 rounded">Files & versions</a></div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</main>
<?php render_footer(); ?>
