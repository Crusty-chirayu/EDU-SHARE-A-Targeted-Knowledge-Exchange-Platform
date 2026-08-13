<?php
declare(strict_types=1);

namespace EduShare\Modules\Profiles\Infrastructure;

use EduShare\Modules\Profiles\Application\ContributorDirectoryRepository;

final class MysqliContributorDirectoryRepository implements ContributorDirectoryRepository
{
    /** @var \Closure(): \mysqli */
    private readonly \Closure $connectionProvider;

    public function __construct(\mysqli|\Closure $connection)
    {
        $this->connectionProvider = $connection instanceof \mysqli
            ? static fn (): \mysqli => $connection
            : $connection;
    }

    public function universityExists(int $universityId): bool
    {
        $statement = $this->connection()->prepare('SELECT 1 FROM universities WHERE id = ? LIMIT 1');
        $statement->bind_param('i', $universityId);
        $statement->execute();
        return $statement->get_result()->num_rows === 1;
    }

    public function contributorsAtUniversity(int $universityId): array
    {
        $statement = $this->connection()->prepare(
            "SELECT u.id, u.full_name, u.user_type, d.name AS department_name
               FROM users u
               LEFT JOIN departments d ON d.id = u.department_id
              WHERE u.university_id = ? AND u.user_type IN ('teacher', 'moderator', 'admin')
              ORDER BY u.full_name"
        );
        $statement->bind_param('i', $universityId);
        $statement->execute();

        return array_map(
            static fn (array $row): array => [
                'id' => (int) $row['id'],
                'full_name' => (string) $row['full_name'],
                'user_type' => (string) $row['user_type'],
                'department_name' => $row['department_name'] === null ? null : (string) $row['department_name'],
            ],
            $statement->get_result()->fetch_all(MYSQLI_ASSOC)
        );
    }

    private function connection(): \mysqli
    {
        return ($this->connectionProvider)();
    }
}
