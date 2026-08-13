<?php
declare(strict_types=1);

namespace EduShare\Modules\IdentityAccess\Application;

use EduShare\Modules\IdentityAccess\Domain\CurrentUser;

interface CurrentUserProvider
{
    public function current(): ?CurrentUser;
}
