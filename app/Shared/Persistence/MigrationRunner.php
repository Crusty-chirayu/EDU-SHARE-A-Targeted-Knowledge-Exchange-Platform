<?php
declare(strict_types=1);

namespace EduShare\Shared\Persistence;

final class MigrationRunner
{
    public function __construct(
        private readonly \mysqli $connection,
        private readonly string $directory
    ) {
    }

    /** @return list<MigrationPlanItem> */
    public function plan(): array
    {
        $this->ensureLedger();
        $applied = [];
        $result = $this->connection->query('SELECT version, checksum_sha256 FROM schema_migrations ORDER BY version');
        while ($row = $result->fetch_assoc()) {
            $applied[(string) $row['version']] = (string) $row['checksum_sha256'];
        }

        $items = [];
        $seenVersions = [];
        foreach ($this->migrationFiles() as $path) {
            $basename = basename($path, '.sql');
            [$version, $name] = explode('_', $basename, 2);
            $seenVersions[$version] = true;
            $checksum = hash_file('sha256', $path);
            if ($checksum === false) {
                throw new \RuntimeException('Unable to checksum migration ' . basename($path));
            }
            if (isset($applied[$version]) && !hash_equals($applied[$version], $checksum)) {
                throw new \RuntimeException('Applied migration ' . $version . ' has been modified.');
            }
            $items[] = new MigrationPlanItem($version, $name, $path, $checksum, isset($applied[$version]));
        }
        $missingFiles = array_diff_key($applied, $seenVersions);
        if ($missingFiles !== []) {
            throw new \RuntimeException('Applied migration files are missing: ' . implode(', ', array_keys($missingFiles)));
        }
        return $items;
    }

    /** @return list<string> applied versions */
    public function applyPending(): array
    {
        if (!$this->acquireLock()) {
            throw new \RuntimeException('Another migration process holds the EDU-SHARE migration lock.');
        }

        try {
            $applied = [];
            foreach ($this->plan() as $item) {
                if ($item->applied) {
                    continue;
                }
                $sql = file_get_contents($item->path);
                if ($sql === false) {
                    throw new \RuntimeException('Unable to read migration ' . basename($item->path));
                }
                $this->assertForwardOnly($sql, $item->version);
                $this->connection->begin_transaction();
                try {
                    $this->executeStatements($sql);
                    $statement = $this->connection->prepare(
                        'INSERT INTO schema_migrations (version, name, checksum_sha256) VALUES (?, ?, ?)'
                    );
                    $version = $item->version;
                    $name = $item->name;
                    $checksum = $item->checksum;
                    $statement->bind_param('sss', $version, $name, $checksum);
                    $statement->execute();
                    $this->connection->commit();
                    $applied[] = $item->version;
                } catch (\Throwable $exception) {
                    $this->connection->rollback();
                    throw $exception;
                }
            }
            return $applied;
        } finally {
            $this->releaseLock();
        }
    }

    private function ensureLedger(): void
    {
        $this->connection->query(
            "CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(32) NOT NULL,
                name VARCHAR(190) NOT NULL,
                checksum_sha256 CHAR(64) NOT NULL,
                applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /** @return list<string> */
    private function migrationFiles(): array
    {
        if (!is_dir($this->directory)) {
            throw new \RuntimeException('Migration directory is missing.');
        }
        $files = glob(rtrim($this->directory, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        $versions = [];
        foreach ($files as $path) {
            $basename = basename($path, '.sql');
            if (preg_match('/^(\d{14})_([a-z0-9_]+)$/D', $basename, $matches) !== 1) {
                throw new \RuntimeException('Invalid migration filename: ' . basename($path));
            }
            if (isset($versions[$matches[1]])) {
                throw new \RuntimeException('Duplicate migration version: ' . $matches[1]);
            }
            $versions[$matches[1]] = true;
        }
        return array_values($files);
    }

    private function assertForwardOnly(string $sql, string $version): void
    {
        $withoutComments = preg_replace('#/\*.*?\*/|--[^\r\n]*#s', '', $sql) ?? $sql;
        if (preg_match('/\b(?:DROP|TRUNCATE)\b/i', $withoutComments) === 1
            || preg_match('/\bDELETE\s+FROM\b/i', $withoutComments) === 1) {
            throw new \RuntimeException('Migration ' . $version . ' contains a destructive statement.');
        }
    }

    private function executeStatements(string $sql): void
    {
        if (!$this->connection->multi_query($sql)) {
            throw new \RuntimeException('Migration SQL failed.');
        }
        do {
            $result = $this->connection->store_result();
            if ($result instanceof \mysqli_result) {
                $result->free();
            }
            if (!$this->connection->more_results()) {
                break;
            }
        } while ($this->connection->next_result());

        if ($this->connection->errno !== 0) {
            throw new \RuntimeException('Migration SQL failed.');
        }
    }

    private function acquireLock(): bool
    {
        $name = 'edu_share_schema_migrations';
        $timeout = 10;
        $statement = $this->connection->prepare('SELECT GET_LOCK(?, ?) AS acquired');
        $statement->bind_param('si', $name, $timeout);
        $statement->execute();
        return (int) ($statement->get_result()->fetch_assoc()['acquired'] ?? 0) === 1;
    }

    private function releaseLock(): void
    {
        $name = 'edu_share_schema_migrations';
        $statement = $this->connection->prepare('SELECT RELEASE_LOCK(?)');
        $statement->bind_param('s', $name);
        $statement->execute();
    }
}
