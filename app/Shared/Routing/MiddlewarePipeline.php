<?php
declare(strict_types=1);

namespace EduShare\Shared\Routing;

use EduShare\Shared\Http\CallableRequestHandler;
use EduShare\Shared\Http\Middleware;
use EduShare\Shared\Http\Request;
use EduShare\Shared\Http\RequestHandler;
use EduShare\Shared\Http\Response;

final class MiddlewarePipeline implements RequestHandler
{
    /** @param list<Middleware> $middleware */
    public function __construct(
        private readonly RequestHandler $destination,
        private readonly array $middleware
    ) {
    }

    public function handle(Request $request): Response
    {
        $next = $this->destination;
        foreach (array_reverse($this->middleware) as $middleware) {
            $destination = $next;
            $next = new CallableRequestHandler(
                static fn (Request $current): Response => $middleware->process($current, $destination)
            );
        }
        return $next->handle($request);
    }
}
