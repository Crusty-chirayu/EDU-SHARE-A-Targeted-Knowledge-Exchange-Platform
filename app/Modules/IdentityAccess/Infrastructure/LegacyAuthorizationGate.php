<?php
declare(strict_types=1);

namespace EduShare\Modules\IdentityAccess\Infrastructure;

use EduShare\Modules\IdentityAccess\Application\AuthorizationGate;
use EduShare\Modules\IdentityAccess\Domain\CurrentUser;

final class LegacyAuthorizationGate implements AuthorizationGate
{
    public function allows(CurrentUser $user, string $ability): bool
    {
        return \role_can($user->role, $ability);
    }
}
