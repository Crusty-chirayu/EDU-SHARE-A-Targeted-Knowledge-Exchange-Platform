<?php
declare(strict_types=1);

namespace EduShare\Modules\IdentityAccess\Domain;

final class CurrentUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $role,
        public readonly string $displayName
    ) {
        if ($id < 1) {
            throw new \InvalidArgumentException('Authenticated users require a positive ID.');
        }
        if (!in_array($role, ['student', 'teacher', 'moderator', 'admin'], true)) {
            throw new \InvalidArgumentException('Authenticated users require a recognized role.');
        }
    }
}
