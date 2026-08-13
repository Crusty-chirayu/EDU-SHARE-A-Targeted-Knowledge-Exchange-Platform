<?php
declare(strict_types=1);
require __DIR__ . '/includes/bootstrap.php';
require __DIR__ . '/includes/layout.php';
require_method('GET', 'POST');

if (auth_user() !== null) {
    redirect('dashboard.php', 302);
}

$error = '';
$email = '';
if (request_method() === 'POST') {
    require_csrf();
    $email = strtolower(canonical_text($_POST['email'] ?? ''));
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254 || $password === '') {
        $error = 'Enter a valid email address and password.';
    } elseif (login_is_rate_limited($email)) {
        http_response_code(429);
        header('Retry-After: 900');
        $error = 'Too many unsuccessful attempts. Please wait 15 minutes and try again.';
    } else {
        $user = authenticate_credentials($email, $password);
        record_login_attempt($email, $user !== null);
        if ($user === null) {
            $error = 'The email or password is incorrect.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['user_type'] = $user['user_type'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['_last_activity'] = time();
            unset($_SESSION['_csrf']);
            reset_auth_cache();
            flash('success', 'Welcome back, ' . $user['full_name'] . '.');
            redirect('dashboard.php');
        }
    }
}

render_header('Log in');
?>
<main class="max-w-md mx-auto px-4 py-12">
    <section class="bg-white p-8 rounded-xl shadow-xl">
        <h1 class="text-3xl font-extrabold text-center mb-6">Welcome back</h1>
        <?php if ($error !== ''): ?>
            <div class="bg-red-100 border border-red-300 text-red-800 px-4 py-3 rounded mb-4" role="alert"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post" action="<?= h(app_url('login1.php')) ?>" class="space-y-4">
            <?= csrf_field() ?>
            <div>
                <label for="email" class="block text-sm font-medium mb-1">Email</label>
                <input id="email" type="email" name="email" value="<?= h($email) ?>" autocomplete="email" maxlength="254" required class="w-full px-4 py-3 border rounded-lg">
            </div>
            <div>
                <label for="password" class="block text-sm font-medium mb-1">Password</label>
                <input id="password" type="password" name="password" autocomplete="current-password" required class="w-full px-4 py-3 border rounded-lg">
            </div>
            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-lg">Log in</button>
        </form>
        <p class="text-center text-sm text-gray-600 mt-4">No account? <a class="text-green-700 font-semibold" href="<?= h(app_url('register.php')) ?>">Register</a></p>
    </section>
</main>
<?php render_footer(); ?>
