<?php
declare(strict_types=1);

namespace EduShare\Shared\Kernel;

use EduShare\Shared\Http\HttpException;
use EduShare\Shared\Http\Request;
use EduShare\Shared\Http\RequestHandler;
use EduShare\Shared\Http\Response;

final class Kernel implements RequestHandler
{
    public function __construct(private readonly RequestHandler $routes)
    {
    }

    public function handle(Request $request): Response
    {
        try {
            return $this->routes->handle($request);
        } catch (HttpException $exception) {
            return Response::json(
                ['status' => 'error', 'message' => $exception->safeMessage],
                $exception->status,
                $exception->headers
            );
        } catch (\Throwable $exception) {
            if (function_exists('app_log')) {
                \app_log('error', 'Modular application request failed safely', ['type' => $exception::class]);
            }
            return Response::json(
                ['status' => 'error', 'message' => 'An unexpected error occurred. Please try again later.'],
                500
            );
        }
    }
}
