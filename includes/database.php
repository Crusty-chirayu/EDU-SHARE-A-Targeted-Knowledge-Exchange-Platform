<?php
declare(strict_types=1);

function db(): mysqli
{
    static $connection;
    if ($connection instanceof mysqli) {
        return $connection;
    }

    $configuration = app_config('db');
    if (app_config('env') === 'production'
        && (strtolower((string) $configuration['user']) === 'root' || (string) $configuration['password'] === '')) {
        throw new RuntimeException('Refusing privileged or passwordless production database configuration.');
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $connection = new mysqli(
        (string) $configuration['host'],
        (string) $configuration['user'],
        (string) $configuration['password'],
        (string) $configuration['name'],
        (int) $configuration['port']
    );
    $connection->set_charset('utf8mb4');

    return $connection;
}

function db_row_exists(string $table, int $id): bool
{
    $allowed = ['universities', 'departments', 'courses', 'subjects', 'materials'];
    if (!in_array($table, $allowed, true)) {
        throw new InvalidArgumentException('Unsupported table lookup.');
    }

    $statement = db()->prepare("SELECT 1 FROM {$table} WHERE id = ? LIMIT 1");
    $statement->bind_param('i', $id);
    $statement->execute();
    return $statement->get_result()->num_rows === 1;
}
