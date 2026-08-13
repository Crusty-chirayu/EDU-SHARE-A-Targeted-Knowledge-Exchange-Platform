<?php
declare(strict_types=1);

function render_header(string $title, string $active = ''): void
{
    $user = auth_user();
    $links = [
        'home' => ['index.php', 'Home'],
        'library' => ['homepage.php', 'Library'],
        'favorites' => ['favorites.php', 'Favorites'],
        'dashboard' => ['dashboard.php', 'Dashboard'],
    ];
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= h(csrf_token()) ?>">
    <title><?= h($title) ?> · EDU-SHARE</title>
    <link rel="stylesheet" href="<?= h(app_url('assets/app.css')) ?>">
</head>
<body class="bg-gray-100 min-h-screen text-gray-900">
<header class="bg-white shadow-md sticky top-0 z-20">
    <div class="max-w-7xl mx-auto px-4 py-4 flex flex-wrap gap-4 justify-between items-center">
        <a href="<?= h(app_url('index.php')) ?>" class="text-2xl font-extrabold text-gray-800">EDU-SHARE</a>
        <nav class="flex flex-wrap items-center gap-4" aria-label="Primary navigation">
            <?php foreach ($links as $key => [$path, $label]): ?>
                <?php if ($user !== null || $key === 'home'): ?>
                    <a href="<?= h(app_url($path)) ?>" class="<?= $active === $key ? 'text-blue-700 font-bold' : 'text-gray-700 hover:text-blue-600' ?>"><?= h($label) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
            <?php if ($user !== null && role_can($user['user_type'], 'upload_material')): ?>
                <a href="<?= h(app_url('upload.php')) ?>" class="<?= $active === 'upload' ? 'text-blue-700 font-bold' : 'text-gray-700 hover:text-blue-600' ?>">Upload</a>
            <?php endif; ?>
            <?php if ($user !== null && role_can($user['user_type'], 'manage_academics')): ?>
                <a href="<?= h(app_url('admin.php')) ?>" class="<?= $active === 'admin' ? 'text-blue-700 font-bold' : 'text-gray-700 hover:text-blue-600' ?>">Academics</a>
            <?php endif; ?>
            <?php if ($user === null): ?>
                <a href="<?= h(app_url('login1.php')) ?>" class="bg-blue-600 text-white px-4 py-2 rounded-lg hover:bg-blue-700">Log in</a>
                <a href="<?= h(app_url('register.php')) ?>" class="bg-green-600 text-white px-4 py-2 rounded-lg hover:bg-green-700">Register</a>
            <?php else: ?>
                <form method="post" action="<?= h(app_url('logout.php')) ?>" class="inline">
                    <?= csrf_field() ?>
                    <button type="submit" class="bg-red-600 text-white px-4 py-2 rounded-lg hover:bg-red-700">Log out</button>
                </form>
            <?php endif; ?>
        </nav>
    </div>
</header>
<?php foreach (consume_flashes() as $message): ?>
    <?php $isError = ($message['type'] ?? '') === 'error'; ?>
    <div role="alert" class="max-w-5xl mx-auto mt-4 px-4 py-3 rounded border <?= $isError ? 'bg-red-100 border-red-300 text-red-800' : 'bg-green-100 border-green-300 text-green-800' ?>">
        <?= h($message['message'] ?? '') ?>
    </div>
<?php endforeach; ?>
<?php
}

function render_footer(): void
{
    ?>
<footer class="max-w-7xl mx-auto px-4 py-10 text-center text-sm text-gray-500">
    &copy; <?= date('Y') ?> EDU-SHARE
</footer>
<script src="<?= h(app_url('assets/app.js')) ?>" defer></script>
</body>
</html>
<?php
}
