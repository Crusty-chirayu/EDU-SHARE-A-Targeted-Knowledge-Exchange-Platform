<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_method('GET');

$user = require_auth();
$userId = (int) $user['id'];
$profileStatement = db()->prepare(
    'SELECT u.full_name, u.gmail, u.user_type, uni.name AS university_name, d.name AS department_name
       FROM users u
       LEFT JOIN universities uni ON uni.id = u.university_id
       LEFT JOIN departments d ON d.id = u.department_id
      WHERE u.id = ?'
);
$profileStatement->bind_param('i', $userId);
$profileStatement->execute();
$profile = $profileStatement->get_result()->fetch_assoc();

$materialsStatement = db()->prepare(
    'SELECT m.id, m.title, m.description, m.upload_date, m.status, m.visibility,
            c.name AS course_name, d.name AS department_name, s.name AS subject_name, uni.name AS university_name
       FROM materials m
       JOIN courses c ON c.id = m.course_id
       JOIN departments d ON d.id = m.department_id
       JOIN subjects s ON s.id = m.subject_id
       JOIN universities uni ON uni.id = m.university_id
      WHERE m.user_id = ? ORDER BY m.upload_date DESC'
);
$materialsStatement->bind_param('i', $userId);
$materialsStatement->execute();
$materials = $materialsStatement->get_result()->fetch_all(MYSQLI_ASSOC);

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
        <?php if (role_can($user['user_type'], 'upload_material')): ?><a href="<?= h(app_url('upload.php')) ?>" class="bg-blue-600 text-white font-semibold py-2 px-5 rounded-lg">Upload material</a><?php endif; ?>
    </div>

    <section class="bg-white p-7 rounded-xl shadow mb-8">
        <div class="flex justify-between items-center gap-4"><h2 class="text-2xl font-bold mb-4">Account</h2><a class="text-blue-700" href="<?= h(app_url('teacher_profile.php?id=' . $userId)) ?>">View profile</a></div>
        <dl class="grid sm:grid-cols-2 lg:grid-cols-5 gap-4">
            <div><dt class="text-xs uppercase text-gray-500">Name</dt><dd class="font-semibold"><?= h($profile['full_name']) ?></dd></div>
            <div><dt class="text-xs uppercase text-gray-500">Private email</dt><dd class="font-semibold break-all"><?= h($profile['gmail']) ?></dd></div>
            <div><dt class="text-xs uppercase text-gray-500">Role</dt><dd class="font-semibold"><?= h(ucfirst($profile['user_type'])) ?></dd></div>
            <div><dt class="text-xs uppercase text-gray-500">University</dt><dd class="font-semibold"><?= h($profile['university_name'] ?? 'Not set') ?></dd></div>
            <div><dt class="text-xs uppercase text-gray-500">Department</dt><dd class="font-semibold"><?= h($profile['department_name'] ?? 'Not set') ?></dd></div>
        </dl>
    </section>

    <div class="grid lg:grid-cols-3 gap-8">
        <section class="lg:col-span-2 bg-white p-7 rounded-xl shadow">
            <h2 class="text-2xl font-bold mb-5">My uploaded materials</h2>
            <?php if ($materials === []): ?><p class="text-gray-600">You have not uploaded any materials.</p><?php endif; ?>
            <div class="space-y-4">
                <?php foreach ($materials as $material): ?>
                    <article class="bg-gray-50 border p-5 rounded-lg">
                        <div class="flex flex-wrap justify-between gap-3"><h3 class="font-bold text-lg"><?= h($material['title']) ?></h3><span class="text-xs uppercase bg-gray-200 px-2 py-1 rounded"><?= h($material['status']) ?> · <?= h($material['visibility']) ?></span></div>
                        <p class="text-sm text-gray-600 my-2"><?= h($material['university_name']) ?> · <?= h($material['department_name']) ?> · <?= h($material['course_name']) ?> · <?= h($material['subject_name']) ?></p>
                        <div class="flex flex-wrap gap-2 mt-3">
                            <a target="_blank" rel="noopener" href="<?= h(app_url('download.php?id=' . (int) $material['id'])) ?>" class="bg-gray-200 px-3 py-2 rounded">View</a>
                            <a href="<?= h(app_url('download.php?id=' . (int) $material['id'] . '&download=1')) ?>" class="bg-blue-600 text-white px-3 py-2 rounded">Download</a>
                            <form method="post" action="<?= h(app_url('delete_material.php')) ?>">
                                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int) $material['id'] ?>"><button class="bg-red-600 text-white px-3 py-2 rounded">Delete</button>
                            </form>
                        </div>
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
