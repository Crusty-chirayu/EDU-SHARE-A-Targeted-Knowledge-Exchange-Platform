<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_method('GET');

$user = require_auth();
$userId = (int) $user['id'];
$profileStatement = db()->prepare(
    'SELECT u.full_name, u.gmail, u.user_type, u.year,
            uni.name AS university_name, d.name AS department_name, c.name AS course_name
       FROM users u
       LEFT JOIN universities uni ON uni.id = u.university_id
       LEFT JOIN departments d ON d.id = u.department_id AND d.university_id = u.university_id
       LEFT JOIN courses c ON c.id = u.course_id AND c.department_id = u.department_id
      WHERE u.id = ?'
);
$profileStatement->bind_param('i', $userId);
$profileStatement->execute();
$profile = $profileStatement->get_result()->fetch_assoc();

$resourcesStatement = db()->prepare(
    'SELECT r.id, r.title, r.description, r.updated_at, r.publication_status,
            r.moderation_status, r.visibility, r.current_version_number, r.deletion_status,
            c.name AS course_name, d.name AS department_name,
            s.name AS subject_name, uni.name AS university_name,
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
       JOIN universities uni ON uni.id = r.university_id
      WHERE r.owner_id = ? AND r.deletion_status IN (\'active\', \'pending_cleanup\')
      ORDER BY r.updated_at DESC'
);
$resourcesStatement->bind_param('i', $userId);
$resourcesStatement->execute();
$resources = $resourcesStatement->get_result()->fetch_all(MYSQLI_ASSOC);

$universitiesStatement = db()->prepare(
    'SELECT u.id, u.name FROM university_favorites uf
      JOIN universities u ON u.id = uf.university_id WHERE uf.user_id = ? ORDER BY u.name'
);
$universitiesStatement->bind_param('i', $userId);
$universitiesStatement->execute();
$favoriteUniversities = $universitiesStatement->get_result()->fetch_all(MYSQLI_ASSOC);

render_header('Dashboard', 'dashboard');
?>
<main class="max-w-7xl mx-auto py-10 px-4">
    <div class="flex flex-wrap justify-between items-center gap-4 mb-8">
        <h1 class="text-4xl font-extrabold">My dashboard</h1>
        <?php if (role_can($user['user_type'], 'upload_resource')): ?><a href="<?= h(app_url('upload.php')) ?>" class="bg-blue-600 text-white font-semibold py-2 px-5 rounded-lg">Create resource</a><?php endif; ?>
    </div>

    <section class="bg-white p-7 rounded-xl shadow mb-8">
        <div class="flex justify-between items-center gap-4"><h2 class="text-2xl font-bold mb-4">Account</h2><a class="text-blue-700" href="<?= h(app_url('teacher_profile.php?id=' . $userId)) ?>">View profile</a></div>
        <dl class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <div><dt class="text-xs uppercase text-gray-500">Name</dt><dd class="font-semibold"><?= h($profile['full_name']) ?></dd></div>
            <div><dt class="text-xs uppercase text-gray-500">Private email</dt><dd class="font-semibold break-all"><?= h($profile['gmail']) ?></dd></div>
            <div><dt class="text-xs uppercase text-gray-500">Role</dt><dd class="font-semibold"><?= h(ucfirst($profile['user_type'])) ?></dd></div>
            <div><dt class="text-xs uppercase text-gray-500">University</dt><dd class="font-semibold"><?= h($profile['university_name'] ?? 'Not set') ?></dd></div>
            <div><dt class="text-xs uppercase text-gray-500">Department</dt><dd class="font-semibold"><?= h($profile['department_name'] ?? 'Not set') ?></dd></div>
            <div><dt class="text-xs uppercase text-gray-500">Course / program</dt><dd class="font-semibold"><?= h($profile['course_name'] ?? 'Not applicable or awaiting review') ?></dd></div>
            <div><dt class="text-xs uppercase text-gray-500">Year of study</dt><dd class="font-semibold"><?= $profile['year'] === null ? 'Not applicable' : (int) $profile['year'] ?></dd></div>
        </dl>
    </section>

    <div class="grid lg:grid-cols-3 gap-8">
        <section class="lg:col-span-2 bg-white p-7 rounded-xl shadow">
            <h2 class="text-2xl font-bold mb-5">My resources</h2>
            <?php if ($resources === []): ?><p class="text-gray-600">You have not created any resources.</p><?php endif; ?>
            <div class="space-y-4">
                <?php foreach ($resources as $resource): ?>
                    <article class="bg-gray-50 border p-5 rounded-lg">
                        <div class="flex flex-wrap justify-between gap-3"><h3 class="font-bold text-lg"><?= h($resource['title']) ?></h3><span class="text-xs uppercase bg-gray-200 px-2 py-1 rounded"><?php if ($resource['deletion_status'] === 'pending_cleanup'): ?>cleanup pending<?php else: ?><?= h($resource['publication_status'] . ' · ' . $resource['moderation_status']) ?> · <?= h($resource['visibility']) ?><?php endif; ?></span></div>
                        <p class="text-sm text-gray-600 my-2"><?= h($resource['university_name']) ?> · <?= h($resource['department_name']) ?> · <?= h($resource['course_name']) ?> · <?= h($resource['subject_name']) ?></p>
                        <?php if ($resource['deletion_status'] === 'pending_cleanup'): ?>
                            <p class="text-sm text-amber-800">Access remains revoked. Retry the recorded private-object cleanup safely.</p>
                            <form class="mt-3" method="post" action="<?= h(app_url('delete_material.php')) ?>">
                                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $resource['id'] ?>"><button class="bg-amber-700 text-white px-3 py-2 rounded">Retry cleanup</button>
                            </form>
                        <?php else: ?>
                            <p class="text-sm text-gray-600"><?= (int) $resource['file_count'] ?> current file(s) · Version <?= (int) $resource['current_version_number'] ?> of <?= (int) $resource['version_count'] ?></p>
                            <div class="flex flex-wrap gap-2 mt-3">
                                <a target="_blank" rel="noopener" href="<?= h(app_url('resource.php?id=' . (int) $resource['id'])) ?>" class="bg-gray-200 px-3 py-2 rounded">Open</a>
                                <a href="<?= h(app_url('resource.php?id=' . (int) $resource['id'])) ?>" class="bg-blue-600 text-white px-3 py-2 rounded">Files & versions</a>
                                <?php if (can_add_resource_version($resource, $user)): ?><a href="<?= h(app_url('upload.php?resource_id=' . (int) $resource['id'])) ?>" class="bg-green-700 text-white px-3 py-2 rounded">Add version</a><?php endif; ?>
                                <form method="post" action="<?= h(app_url('delete_material.php')) ?>">
                                    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $resource['id'] ?>"><button class="bg-red-600 text-white px-3 py-2 rounded">Delete resource</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="bg-white p-7 rounded-xl shadow h-fit">
            <h2 class="text-2xl font-bold mb-5">Favorite universities</h2>
            <?php if ($favoriteUniversities === []): ?><p class="text-gray-600">No favorite universities.</p><?php endif; ?>
            <ul class="space-y-3"><?php foreach ($favoriteUniversities as $university): ?><li><a class="text-blue-700" href="<?= h(app_url('homepage.php?university_id=' . (int) $university['id'])) ?>"><?= h($university['name']) ?></a></li><?php endforeach; ?></ul>
        </section>
    </div>
</main>
<?php render_footer(); ?>
