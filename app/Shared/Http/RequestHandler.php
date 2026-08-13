<?php
declare(strict_types=1);

namespace EduShare\Shared\Http;

interface RequestHandler
{
    public function handle(Request $request): Response;
}
