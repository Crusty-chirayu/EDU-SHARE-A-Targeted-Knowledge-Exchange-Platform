<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/includes/config.php';
require APP_ROOT . '/includes/database.php';
require APP_ROOT . '/app/autoload.php';

use EduShare\Shared\Persistence\MigrationRunner;

$command = $argv[1] ?? 'status';
if (!in_array($command, ['status', 'apply'], true)) {
    fwrite(STDERR, "Usage: php scripts/migrate.php [status|apply]\n");
    exit(2);
}

try {
    $runner = new MigrationRunner(db(), APP_ROOT . '/database/migrations/forward');
    if ($command === 'apply') {
        $versions = $runner->applyPending();
        foreach ($versions as $version) {
            fwrite(STDOUT, "Applied {$version}\n");
        }
        if ($versions === []) {
            fwrite(STDOUT, "No pending migrations.\n");
        }
        exit(0);
    }

    foreach ($runner->plan() as $item) {
        $status = $item->applied ? 'applied' : 'pending';
        fwrite(STDOUT, sprintf("%-8s %s %s\n", $status, $item->version, $item->name));
    }
} catch (Throwable $exception) {
    error_log('Migration command failed safely [' . $exception::class . ']');
    fwrite(STDERR, "Migration failed. Review the server log.\n");
    exit(1);
}
