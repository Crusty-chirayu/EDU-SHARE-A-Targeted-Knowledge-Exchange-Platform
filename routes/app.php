<?php
declare(strict_types=1);

use EduShare\Modules\IdentityAccess\Http\RequireAuthenticated;
use EduShare\Modules\Profiles\Http\ListUniversityContributorsController;
use EduShare\Shared\Routing\Router;

return static function (
    Router $router,
    ListUniversityContributorsController $contributors,
    RequireAuthenticated $authenticated
): void {
    $router->get(
        '/api/v1/universities/{universityId}/contributors',
        $contributors,
        [$authenticated]
    );
};
