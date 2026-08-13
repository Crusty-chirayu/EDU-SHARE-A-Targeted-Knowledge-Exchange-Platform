<?php
declare(strict_types=1);

namespace EduShare\Modules\Profiles\Application;

interface ContributorDirectoryRepository
{
    public function universityExists(int $universityId): bool;

    /** @return list<array{id: int, full_name: string, user_type: string, department_name: ?string}> */
    public function contributorsAtUniversity(int $universityId): array;
}
