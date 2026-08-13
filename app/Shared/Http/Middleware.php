<?php
declare(strict_types=1);

namespace EduShare\Shared\Http;

interface Middleware
{
    public function process(Request $request, RequestHandler $next): Response;
}
