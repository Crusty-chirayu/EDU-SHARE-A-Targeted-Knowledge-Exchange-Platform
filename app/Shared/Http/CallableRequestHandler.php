<?php
declare(strict_types=1);

namespace EduShare\Shared\Http;

final class CallableRequestHandler implements RequestHandler
{
    /** @param \Closure(Request): Response $handler */
    public function __construct(private readonly \Closure $handler)
    {
    }

    public function handle(Request $request): Response
    {
        return ($this->handler)($request);
    }
}
