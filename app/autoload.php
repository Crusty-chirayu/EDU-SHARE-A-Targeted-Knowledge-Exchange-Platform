<?php
declare(strict_types=1);

/**
 * Dependency-free PSR-4-compatible loader used during the incremental migration.
 * Composer may generate an optimized loader later without changing namespaces.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'EduShare\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    if ($relative === '' || preg_match('/^[A-Za-z0-9_\\\\]+$/D', $relative) !== 1) {
        return;
    }

    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});
