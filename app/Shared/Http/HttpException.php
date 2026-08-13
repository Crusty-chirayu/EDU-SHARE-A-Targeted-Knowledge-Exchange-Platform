<?php
declare(strict_types=1);

namespace EduShare\Shared\Http;

final class HttpException extends \RuntimeException
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $safeMessage,
        public readonly array $headers = []
    ) {
        parent::__construct($safeMessage);
    }
}
