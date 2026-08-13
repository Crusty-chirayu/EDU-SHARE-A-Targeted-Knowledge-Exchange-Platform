<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));

/** Load a local .env file without overriding variables provided by the host. */
function load_environment_file(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $name) || getenv($name) !== false) {
            continue;
        }

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

load_environment_file(APP_ROOT . '/.env');

function env_value(string $name, mixed $default = null): mixed
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }

    return $value;
}

function env_bool(string $name, bool $default = false): bool
{
    $value = env_value($name);
    if ($value === null) {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
}

function app_config(?string $key = null): mixed
{
    static $configuration;

    if ($configuration === null) {
        $storagePath = (string) env_value('STORAGE_PATH', dirname(APP_ROOT) . '/edu-share-storage');
        if ($storagePath === '' || $storagePath[0] !== DIRECTORY_SEPARATOR) {
            $storagePath = APP_ROOT . '/' . ltrim($storagePath, '/\\');
        }

        $basePath = '/' . trim((string) env_value('APP_BASE_PATH', ''), '/');
        if ($basePath === '/') {
            $basePath = '';
        }

        $configuration = [
            'env' => (string) env_value('APP_ENV', 'production'),
            'debug' => env_bool('APP_DEBUG', false),
            'trust_proxy' => env_bool('TRUST_PROXY', false),
            'base_path' => $basePath,
            'storage_path' => rtrim($storagePath, '/\\'),
            'db' => [
                'host' => (string) env_value('DB_HOST', '127.0.0.1'),
                'port' => (int) env_value('DB_PORT', 3306),
                'name' => (string) env_value('DB_DATABASE', 'edu_share'),
                'user' => (string) env_value('DB_USERNAME', 'edu_share_app'),
                'password' => (string) env_value('DB_PASSWORD', ''),
            ],
            'session' => [
                'name' => (string) env_value('SESSION_NAME', 'edushare_session'),
                'lifetime' => max(300, (int) env_value('SESSION_LIFETIME_SECONDS', 1800)),
            ],
            'upload' => [
                'max_files' => max(1, min(5, (int) env_value('UPLOAD_MAX_FILES', 2))),
                'max_bytes' => max(1024, (int) env_value('UPLOAD_MAX_BYTES', 10 * 1024 * 1024)),
            ],
        ];
    }

    if ($key === null) {
        return $configuration;
    }

    $value = $configuration;
    foreach (explode('.', $key) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return null;
        }
        $value = $value[$segment];
    }

    return $value;
}

function app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    $base = (string) app_config('base_path');
    return ($base === '' ? '' : $base) . '/' . $path;
}

function app_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
        return true;
    }

    return app_config('trust_proxy') === true
        && strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}
