<?php
declare(strict_types=1);

namespace EduShare\Shared\Kernel;

use EduShare\Modules\IdentityAccess\Http\RequireAuthenticated;
use EduShare\Modules\IdentityAccess\Infrastructure\LegacySessionUserProvider;
use EduShare\Modules\Profiles\Application\ListUniversityContributors;
use EduShare\Modules\Profiles\Http\ListUniversityContributorsController;
use EduShare\Modules\Profiles\Infrastructure\MysqliContributorDirectoryRepository;
use EduShare\Shared\Routing\Router;

final class ApplicationFactory
{
    public static function create(): Kernel
    {
        $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3);
        $users = new LegacySessionUserProvider();
        $authenticated = new RequireAuthenticated($users);
        $repository = new MysqliContributorDirectoryRepository(static fn (): \mysqli => \db());
        $service = new ListUniversityContributors($repository);
        $controller = new ListUniversityContributorsController($service);
        $router = new Router();

        $register = require $root . '/routes/app.php';
        $register($router, $controller, $authenticated);

        return new Kernel($router);
    }
}
