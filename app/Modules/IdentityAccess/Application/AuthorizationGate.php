<?php
declare(strict_types=1);

namespace EduShare\Modules\IdentityAccess\Application;

use EduShare\Modules\IdentityAccess\Domain\CurrentUser;

interface AuthorizationGate
{
    public function allows(CurrentUser $user, string $ability): bool;
}
