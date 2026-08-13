<?php
declare(strict_types=1);

namespace EduShare\Modules\IdentityAccess\Http;

use EduShare\Modules\IdentityAccess\Application\CurrentUserProvider;
use EduShare\Shared\Http\HttpException;
use EduShare\Shared\Http\Middleware;
use EduShare\Shared\Http\Request;
use EduShare\Shared\Http\RequestHandler;
use EduShare\Shared\Http\Response;

final class RequireAuthenticated implements Middleware
{
    public function __construct(private readonly CurrentUserProvider $users)
    {
    }

    public function process(Request $request, RequestHandler $next): Response
    {
        $user = $this->users->current();
        if ($user === null) {
            throw new HttpException(401, 'Authentication is required.');
        }
        return $next->handle($request->withAttribute('current_user', $user));
    }
}
