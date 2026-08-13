<?php
declare(strict_types=1);

namespace EduShare\Modules\IdentityAccess\Http;

use EduShare\Modules\IdentityAccess\Application\AuthorizationGate;
use EduShare\Modules\IdentityAccess\Application\CurrentUserProvider;
use EduShare\Shared\Http\HttpException;
use EduShare\Shared\Http\Middleware;
use EduShare\Shared\Http\Request;
use EduShare\Shared\Http\RequestHandler;
use EduShare\Shared\Http\Response;

final class RequireAbility implements Middleware
{
    public function __construct(
        private readonly CurrentUserProvider $users,
        private readonly AuthorizationGate $gate,
        private readonly string $ability
    ) {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $user = $this->users->current();
        if ($user === null) {
            throw new HttpException(401, 'Authentication is required.');
        }
        if (!$this->gate->allows($user, $this->ability)) {
            throw new HttpException(403, 'You do not have permission to perform this action.');
        }
        return $next->handle($request->withAttribute('current_user', $user));
    }
}
