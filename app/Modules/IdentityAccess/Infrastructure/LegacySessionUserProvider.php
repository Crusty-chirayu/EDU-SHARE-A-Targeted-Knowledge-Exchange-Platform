<?php
declare(strict_types=1);

namespace EduShare\Modules\IdentityAccess\Infrastructure;

use EduShare\Modules\IdentityAccess\Application\CurrentUserProvider;
use EduShare\Modules\IdentityAccess\Domain\CurrentUser;

final class LegacySessionUserProvider implements CurrentUserProvider
{
    public function current(): ?CurrentUser
    {
        $user = \auth_user();
        if ($user === null) {
            return null;
        }

        return new CurrentUser((int) $user['id'], (string) $user['user_type'], (string) $user['full_name']);
    }
}
