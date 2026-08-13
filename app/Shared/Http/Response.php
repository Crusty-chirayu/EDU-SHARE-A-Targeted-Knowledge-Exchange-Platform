<?php
declare(strict_types=1);

namespace EduShare\Shared\Http;

final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly array $headers = []
    ) {
        if ($status < 100 || $status > 599) {
            throw new \InvalidArgumentException('Invalid HTTP response status.');
        }
    }

    /** @param array<string, mixed> $payload @param array<string, string> $headers */
    public static function json(array $payload, int $status = 200, array $headers = []): self
    {
        return new self(
            $status,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json; charset=UTF-8', 'Cache-Control' => 'no-store'] + $headers
        );
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
