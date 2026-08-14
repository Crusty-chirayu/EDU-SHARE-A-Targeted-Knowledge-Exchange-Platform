<?php
declare(strict_types=1);

/** Render the shared flash component. The message is always escaped. */
function render_flash(string $message, string $type = 'success'): void
{
    $isError = $type === 'error';
    ?>
    <div role="alert" class="max-w-5xl mx-auto mt-4 px-4 py-3 rounded border <?= $isError ? 'bg-red-100 border-red-300 text-red-800' : 'bg-green-100 border-green-300 text-green-800' ?>">
        <?= h($message) ?>
    </div>
    <?php
}

/** @param array<int|string, mixed> $errors */
function render_errors(array $errors): void
{
    $messages = [];
    foreach ($errors as $error) {
        if (is_string($error) && $error !== '') {
            $messages[] = $error;
        }
    }
    if ($messages === []) {
        return;
    }
    ?>
    <div role="alert" class="bg-red-100 border border-red-300 text-red-800 rounded-lg p-4 mb-4">
        <p class="font-bold">Please correct the following:</p>
        <ul class="list-disc ml-5">
            <?php foreach ($messages as $message): ?><li><?= h($message) ?></li><?php endforeach; ?>
        </ul>
    </div>
    <?php
}

/** Open a consistently styled form and add CSRF protection to state-changing forms. */
function render_form_start(string $action, string $method = 'post', string $class = 'space-y-4'): void
{
    $method = strtolower($method);
    if (!in_array($method, ['get', 'post'], true)) {
        throw new InvalidArgumentException('Only GET and POST forms are supported.');
    }
    ?>
    <form method="<?= h($method) ?>" action="<?= h(app_url($action)) ?>" class="<?= h($class) ?>">
        <?php if ($method === 'post'): ?><?= csrf_field() ?><?php endif; ?>
    <?php
}

function render_form_end(): void
{
    echo '</form>';
}

function render_button(string $label, string $variant = 'primary', string $type = 'submit'): void
{
    $variants = [
        'primary' => 'bg-blue-600 hover:bg-blue-700 text-white',
        'secondary' => 'bg-gray-200 text-gray-900',
        'danger' => 'bg-red-600 hover:bg-red-700 text-white',
    ];
    $class = $variants[$variant] ?? $variants['primary'];
    $safeType = in_array($type, ['button', 'submit', 'reset'], true) ? $type : 'button';
    ?><button type="<?= h($safeType) ?>" class="px-4 py-2 rounded-lg font-medium <?= h($class) ?>"><?= h($label) ?></button><?php
}

function render_card_start(?string $title = null): void
{
    ?><section class="bg-white shadow rounded-lg p-6"><?php
    if ($title !== null && $title !== '') {
        ?><h2 class="text-xl font-bold mb-4"><?= h($title) ?></h2><?php
    }
}

function render_card_end(): void
{
    echo '</section>';
}

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
            <?php if ($user !== null && role_can($user['user_type'], 'upload_resource')): ?>
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
    <?php render_flash((string) ($message['message'] ?? ''), (string) ($message['type'] ?? 'success')); ?>
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
