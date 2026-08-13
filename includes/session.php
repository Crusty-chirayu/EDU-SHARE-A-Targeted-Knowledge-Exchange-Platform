<?php
declare(strict_types=1);

function start_secure_session(): void
{
    if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        return;
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string) app_config('session.lifetime'));

    session_name((string) app_config('session.name'));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => app_config('base_path') ?: '/',
        'domain' => '',
        'secure' => app_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    $now = time();
    $lastActivity = (int) ($_SESSION['_last_activity'] ?? $now);
    if (($now - $lastActivity) > (int) app_config('session.lifetime')) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['_expired'] = true;
    }
    $_SESSION['_last_activity'] = $now;
}

function destroy_current_session(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}
