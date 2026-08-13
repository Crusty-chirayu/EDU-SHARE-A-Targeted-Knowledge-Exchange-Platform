<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/autoload.php';

use EduShare\Modules\IdentityAccess\Application\AuthorizationGate;
use EduShare\Modules\IdentityAccess\Application\CurrentUserProvider;
use EduShare\Modules\IdentityAccess\Domain\CurrentUser;
use EduShare\Modules\IdentityAccess\Http\RequireAbility;
use EduShare\Modules\IdentityAccess\Http\RequireAuthenticated;
use EduShare\Modules\Profiles\Application\ContributorDirectoryRepository;
use EduShare\Modules\Profiles\Application\ListUniversityContributors;
use EduShare\Modules\Profiles\Application\UniversityNotFound;
use EduShare\Modules\Profiles\Http\ContributorDirectoryInput;
use EduShare\Modules\Profiles\Http\ListUniversityContributorsController;
use EduShare\Shared\Http\CallableRequestHandler;
use EduShare\Shared\Http\HttpException;
use EduShare\Shared\Http\Request;
use EduShare\Shared\Http\Response;
use EduShare\Shared\Kernel\Kernel;
use EduShare\Shared\Routing\Router;

$passed = 0;
$failed = 0;
function architecture_check(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        fwrite(STDOUT, "PASS {$message}\n");
    } else {
        $failed++;
        fwrite(STDERR, "FAIL {$message}\n");
    }
}

$repository = new class implements ContributorDirectoryRepository {
    public function universityExists(int $universityId): bool
    {
        return $universityId === 1;
    }

    public function contributorsAtUniversity(int $universityId): array
    {
        return [[
            'id' => 8,
            'full_name' => 'Synthetic Teacher',
            'user_type' => 'teacher',
            'department_name' => 'Computer Science',
        ]];
    }
};
$service = new ListUniversityContributors($repository);
$contributors = $service->handle(1);
architecture_check(count($contributors) === 1 && $contributors[0]['id'] === 8, 'contributor service returns its canonical read model');
try {
    $service->handle(2);
    architecture_check(false, 'contributor service reports an unknown university');
} catch (UniversityNotFound) {
    architecture_check(true, 'contributor service reports an unknown university');
}

foreach (['0', '-1', '1e2', '../1', '', null, ['1']] as $invalid) {
    try {
        ContributorDirectoryInput::universityId($invalid);
        architecture_check(false, 'route validation rejects malformed university IDs');
    } catch (HttpException $exception) {
        architecture_check($exception->status === 422, 'route validation rejects malformed university IDs');
    }
}
architecture_check(ContributorDirectoryInput::universityId('1') === 1, 'route validation accepts a canonical positive ID');

$anonymous = new class implements CurrentUserProvider {
    public function current(): ?CurrentUser
    {
        return null;
    }
};
$student = new class implements CurrentUserProvider {
    public function current(): ?CurrentUser
    {
        return new CurrentUser(3, 'student', 'Synthetic Student');
    }
};
$controller = new ListUniversityContributorsController($service);
$router = new Router();
$router->get('/api/v1/universities/{universityId}/contributors', $controller, [new RequireAuthenticated($anonymous)]);
$kernel = new Kernel($router);
$response = $kernel->handle(new Request('GET', '/api/v1/universities/1/contributors'));
architecture_check($response->status === 401, 'authentication middleware rejects an anonymous route request');

$router = new Router();
$router->get('/api/v1/universities/{universityId}/contributors', $controller, [new RequireAuthenticated($student)]);
$kernel = new Kernel($router);
$response = $kernel->handle(new Request('GET', '/api/v1/universities/1/contributors'));
$payload = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
architecture_check($response->status === 200 && $payload['data'][0]['full_name'] === 'Synthetic Teacher', 'route-controller-service response succeeds for an authenticated user');
$response = $kernel->handle(new Request('POST', '/api/v1/universities/1/contributors'));
architecture_check($response->status === 405 && ($response->headers['Allow'] ?? '') === 'GET', 'router returns method-not-allowed with an Allow header');
$response = $kernel->handle(new Request('GET', '/api/v1/unknown'));
architecture_check($response->status === 404, 'router returns a safe not-found response');
$response = $kernel->handle(new Request('GET', '/api/v1/universities/not-a-number/contributors'));
architecture_check($response->status === 422, 'controller validation failures cross the kernel safely');

$denyGate = new class implements AuthorizationGate {
    public function allows(CurrentUser $user, string $ability): bool
    {
        return false;
    }
};
$destination = new CallableRequestHandler(static fn (Request $request): Response => Response::json(['ok' => true]));
try {
    (new RequireAbility($student, $denyGate, 'manage_academics'))->process(new Request('GET', '/'), $destination);
    architecture_check(false, 'authorization middleware fails closed');
} catch (HttpException $exception) {
    architecture_check($exception->status === 403, 'authorization middleware fails closed');
}
$allowGate = new class implements AuthorizationGate {
    public function allows(CurrentUser $user, string $ability): bool
    {
        return $ability === 'browse_materials';
    }
};
$response = (new RequireAbility($student, $allowGate, 'browse_materials'))
    ->process(new Request('GET', '/'), $destination);
architecture_check($response->status === 200, 'authorization middleware allows an explicit capability');

$exploding = new CallableRequestHandler(static function (Request $request): Response {
    throw new RuntimeException('database password should never be disclosed');
});
$response = (new Kernel($exploding))->handle(new Request('GET', '/'));
architecture_check($response->status === 500 && !str_contains($response->body, 'password'), 'kernel conceals unexpected exception details');

fwrite(STDOUT, "Architecture checks: {$passed} passed, {$failed} failed.\n");
exit($failed === 0 ? 0 : 1);
