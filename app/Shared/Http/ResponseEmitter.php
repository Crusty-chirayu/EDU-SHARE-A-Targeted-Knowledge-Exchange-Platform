<?php
declare(strict_types=1);

namespace EduShare\Shared\Http;

final class ResponseEmitter
{
    public function emit(Response $response): never
    {
        http_response_code($response->status);
        foreach ($response->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $response->body;
        exit;
    }
}
