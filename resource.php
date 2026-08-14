<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_method('GET');

$resourceId = positive_int($_GET['id'] ?? null);
if ($resourceId === null) {
    abort_request(422, 'A valid resource ID is required.');
}
$resource = find_resource($resourceId);
if ($resource === null || ($resource['deletion_status'] ?? 'deleted') !== 'active') {
    abort_request(404, 'Resource not found.');
}
$viewer = auth_user();
if (!can_view_resource($resource, $viewer)) {
    abort_request($viewer === null ? 401 : 403, $viewer === null
        ? 'Log in to access this resource.'
        : 'You cannot access this resource.');
}

$versions = [];
foreach (resource_versions_with_files($resourceId) as $row) {
    $number = (int) $row['version_number'];
    if (!isset($versions[$number])) {
        $versions[$number] = [
            'number' => $number,
            'description' => $row['change_description'],
            'status' => $row['lifecycle_status'],
            'created_at' => $row['version_created_at'],
            'files' => [],
        ];
    }
    if ($row['file_id'] !== null) {
        $versions[$number]['files'][] = $row;
    }
}
$favorited = false;
if ($viewer !== null) {
    $favorite = db()->prepare('SELECT id FROM resource_favorites WHERE user_id = ? AND resource_id = ? LIMIT 1');
    $favorite->bind_param('ii', $viewer['id'], $resourceId);
    $favorite->execute();
    $favorited = $favorite->get_result()->num_rows === 1;
}

render_header($resource['title']);
?>
<main class="max-w-5xl mx-auto py-10 px-4 space-y-7">
    <section class="bg-white p-8 rounded-xl shadow">
        <div class="flex flex-wrap justify-between gap-4">
            <div><h1 class="text-3xl font-bold"><?= h($resource['title']) ?></h1><p class="text-gray-600 mt-1">Stable resource #<?= $resourceId ?> · Current version <?= (int) $resource['current_version_number'] ?></p></div>
            <?php if ($viewer !== null): ?><button type="button" data-favorite-resource="<?= $resourceId ?>" data-endpoint="<?= h(app_url('toggle_favorite.php')) ?>" aria-pressed="<?= $favorited ? 'true' : 'false' ?>" class="text-red-700 h-fit"><?= $favorited ? '♥ Favorited' : '♡ Favorite' ?></button><?php endif; ?>
        </div>
        <p class="status-message mt-3" data-status-message role="status"></p>
        <?php if ($resource['description'] !== null && $resource['description'] !== ''): ?><p class="text-gray-700 my-5 whitespace-pre-line"><?= h($resource['description']) ?></p><?php endif; ?>
        <dl class="grid sm:grid-cols-2 gap-3 text-sm">
            <div><dt class="font-semibold">Academic context</dt><dd><?= h($resource['university_name']) ?> · <?= h($resource['department_name']) ?> · <?= h($resource['course_name']) ?> · <?= h($resource['subject_name']) ?> · Semester <?= (int) $resource['semester'] ?></dd></div>
            <div><dt class="font-semibold">Contributor</dt><dd><a class="text-blue-700" href="<?= h(app_url('teacher_profile.php?id=' . (int) $resource['owner_id'])) ?>"><?= h($resource['owner_name']) ?></a></dd></div>
            <div><dt class="font-semibold">Access</dt><dd><?= h($resource['visibility']) ?> · <?= h($resource['publication_status']) ?> · <?= h($resource['moderation_status']) ?></dd></div>
            <div><dt class="font-semibold">Contents</dt><dd><?= (int) $resource['file_count'] ?> current file(s) · <?= (int) $resource['version_count'] ?> version(s) · <?= h(strtoupper($resource['document_type'])) ?></dd></div>
        </dl>
        <?php if ($viewer !== null && can_add_resource_version($resource, $viewer)): ?><a href="<?= h(app_url('upload.php?resource_id=' . $resourceId)) ?>" class="inline-block mt-5 bg-blue-600 text-white px-5 py-2 rounded-lg">Add version</a><?php endif; ?>
    </section>

    <?php if ($viewer !== null && can_update_resource($resource, $viewer)): ?>
        <section class="bg-white p-8 rounded-xl shadow">
            <h2 class="text-2xl font-bold mb-4">Edit resource metadata</h2>
            <p class="text-sm text-gray-600 mb-4">This changes the logical resource only. Academic context, files, checksums, and version history remain unchanged.</p>
            <form method="post" action="<?= h(app_url('update_resource.php')) ?>" class="space-y-4">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= $resourceId ?>">
                <div><label for="resource_title" class="block font-semibold mb-1">Title</label><input id="resource_title" name="title" required maxlength="200" value="<?= h($resource['title']) ?>" class="w-full border rounded-lg p-3"></div>
                <div><label for="resource_description" class="block font-semibold mb-1">Description</label><textarea id="resource_description" name="description" maxlength="5000" rows="4" class="w-full border rounded-lg p-3"><?= h($resource['description'] ?? '') ?></textarea></div>
                <div><label for="resource_visibility" class="block font-semibold mb-1">Visibility</label><select id="resource_visibility" name="visibility" class="w-full border rounded-lg p-3"><?php foreach (['public', 'authenticated', 'private'] as $visibility): ?><option value="<?= $visibility ?>" <?= $resource['visibility'] === $visibility ? 'selected' : '' ?>><?= h(ucfirst($visibility)) ?></option><?php endforeach; ?></select></div>
                <button class="bg-gray-800 text-white px-5 py-2 rounded-lg">Save metadata</button>
            </form>
        </section>
    <?php endif; ?>

    <section class="space-y-5">
        <h2 class="text-2xl font-bold">Version history</h2>
        <?php foreach ($versions as $version): ?>
            <article class="bg-white p-6 rounded-xl shadow <?= $version['number'] === (int) $resource['current_version_number'] ? 'border-l-4 border-green-500' : '' ?>">
                <div class="flex flex-wrap justify-between gap-3"><h3 class="text-xl font-bold">Version <?= $version['number'] ?><?= $version['number'] === (int) $resource['current_version_number'] ? ' · Current' : '' ?></h3><span class="text-sm text-gray-500"><?= h($version['created_at']) ?></span></div>
                <?php if ($version['description'] !== null): ?><p class="text-gray-600 mt-2 whitespace-pre-line"><?= h($version['description']) ?></p><?php endif; ?>
                <ul class="mt-4 divide-y">
                    <?php foreach ($version['files'] as $file): ?>
                        <li class="py-3 flex flex-wrap justify-between gap-3">
                            <div><p class="font-semibold"><?= h($file['original_filename']) ?></p><p class="text-xs text-gray-600"><?= h($file['mime_type']) ?> · <?= h(format_bytes((int) $file['file_size'])) ?> · SHA-256 <?= h(substr($file['checksum_sha256'], 0, 12)) ?>… · scan <?= h($file['scan_status']) ?> · <?= h($file['storage_status']) ?></p></div>
                            <?php if ($file['storage_status'] === 'available' && $file['processing_status'] === 'ready' && $file['scan_status'] !== 'blocked'): ?><div class="flex gap-2"><a target="_blank" rel="noopener" href="<?= h(app_url('download.php?id=' . $resourceId . '&file_id=' . (int) $file['file_id'])) ?>" class="bg-gray-200 px-3 py-2 rounded">View</a><a href="<?= h(app_url('download.php?id=' . $resourceId . '&file_id=' . (int) $file['file_id'] . '&download=1')) ?>" class="bg-blue-600 text-white px-3 py-2 rounded">Download</a></div><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </article>
        <?php endforeach; ?>
    </section>
</main>
<?php render_footer(); ?>
