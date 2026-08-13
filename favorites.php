<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_method('GET');

$user = require_auth();
$userId = (int) $user['id'];
$statement = db()->prepare(
    'SELECT m.id, m.user_id, m.title, m.description, m.upload_date, m.visibility, m.status,
            s.name AS subject_name, c.name AS course_name, d.name AS department_name,
            u.full_name AS uploader_name, uni.name AS university_name
       FROM material_favorites mf
       JOIN materials m ON m.id = mf.material_id
       JOIN subjects s ON s.id = m.subject_id
       JOIN courses c ON c.id = m.course_id
       JOIN departments d ON d.id = m.department_id
       JOIN users u ON u.id = m.user_id
       JOIN universities uni ON uni.id = m.university_id
      WHERE mf.user_id = ? ORDER BY mf.favorited_at DESC'
);
$statement->bind_param('i', $userId);
$statement->execute();
$materials = array_values(array_filter(
    $statement->get_result()->fetch_all(MYSQLI_ASSOC),
    static fn (array $material): bool => can_view_material($material, $user)
));

render_header('Favorites', 'favorites');
?>
<main class="max-w-5xl mx-auto py-10 px-4">
    <h1 class="text-4xl font-extrabold text-center mb-3">My favorites</h1>
    <p class="status-message text-center mb-5" data-status-message role="status"></p>
    <?php if ($materials === []): ?><p class="bg-white p-8 rounded-xl text-center text-gray-600">You have no available favorite materials.</p><?php endif; ?>
    <div class="space-y-5">
        <?php foreach ($materials as $material): ?>
            <article data-material-card class="bg-white p-6 rounded-xl shadow border-l-4 border-blue-500">
                <div class="flex justify-between gap-3"><h2 class="text-xl font-bold"><?= h($material['title']) ?></h2><button type="button" data-favorite-material="<?= (int) $material['id'] ?>" data-endpoint="<?= h(app_url('toggle_favorite.php')) ?>" data-remove-on-unfavorite="true" data-active="true" aria-pressed="true" class="text-red-700">♥ Favorited</button></div>
                <p class="text-gray-700 my-2 whitespace-pre-line"><?= h($material['description']) ?></p>
                <p class="text-sm text-gray-600"><?= h($material['university_name']) ?> · <?= h($material['department_name']) ?> · <?= h($material['course_name']) ?> · <?= h($material['subject_name']) ?></p>
                <p class="text-sm text-gray-600">By <a class="text-blue-700" href="<?= h(app_url('teacher_profile.php?id=' . (int) $material['user_id'])) ?>"><?= h($material['uploader_name']) ?></a></p>
                <div class="flex gap-3 mt-4"><a target="_blank" rel="noopener" href="<?= h(app_url('download.php?id=' . (int) $material['id'])) ?>" class="bg-gray-200 px-4 py-2 rounded-lg">View</a><a href="<?= h(app_url('download.php?id=' . (int) $material['id'] . '&download=1')) ?>" class="bg-blue-600 text-white px-4 py-2 rounded-lg">Download</a></div>
            </article>
        <?php endforeach; ?>
    </div>
</main>
<?php render_footer(); ?>
