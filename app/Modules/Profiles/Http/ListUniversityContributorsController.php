<?php
declare(strict_types=1);

namespace EduShare\Modules\Profiles\Http;

use EduShare\Modules\Profiles\Application\ListUniversityContributors;
use EduShare\Modules\Profiles\Application\UniversityNotFound;
use EduShare\Shared\Http\HttpException;
use EduShare\Shared\Http\Request;
use EduShare\Shared\Http\RequestHandler;
use EduShare\Shared\Http\Response;

final class ListUniversityContributorsController implements RequestHandler
{
    public function __construct(private readonly ListUniversityContributors $contributors)
    {
    }

    public function handle(Request $request): Response
    {
        $universityId = ContributorDirectoryInput::universityId($request->attribute('route.universityId'));
        try {
            $contributors = $this->contributors->handle($universityId);
        } catch (UniversityNotFound) {
            throw new HttpException(404, 'University not found.');
        }

        return Response::json(['data' => $contributors]);
    }
}
