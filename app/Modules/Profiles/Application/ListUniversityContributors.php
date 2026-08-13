<?php
declare(strict_types=1);

namespace EduShare\Modules\Profiles\Application;

final class ListUniversityContributors
{
    public function __construct(private readonly ContributorDirectoryRepository $contributors)
    {
    }

    /** @return list<array{id: int, full_name: string, user_type: string, department_name: ?string}> */
    public function handle(int $universityId): array
    {
        if ($universityId < 1) {
            throw new \InvalidArgumentException('University IDs must be positive.');
        }
        if (!$this->contributors->universityExists($universityId)) {
            throw new UniversityNotFound('University not found.');
        }

        $allowedRoles = ['teacher', 'moderator', 'admin'];
        $result = [];
        foreach ($this->contributors->contributorsAtUniversity($universityId) as $contributor) {
            $id = (int) ($contributor['id'] ?? 0);
            $role = (string) ($contributor['user_type'] ?? '');
            $fullName = trim((string) ($contributor['full_name'] ?? ''));
            if ($id < 1 || $fullName === '' || !in_array($role, $allowedRoles, true)) {
                throw new \UnexpectedValueException('Contributor repository returned an invalid record.');
            }
            $result[] = [
                'id' => $id,
                'full_name' => $fullName,
                'user_type' => $role,
                'department_name' => isset($contributor['department_name'])
                    ? (string) $contributor['department_name']
                    : null,
            ];
        }
        return $result;
    }
}
