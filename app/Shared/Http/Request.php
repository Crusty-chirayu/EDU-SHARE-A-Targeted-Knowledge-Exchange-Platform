<?php
declare(strict_types=1);

namespace EduShare\Shared\Http;

final class Request
{
    /** @param array<string, mixed> $query @param array<string, mixed> $body @param array<string, string> $headers @param array<string, mixed> $attributes */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly array $headers = [],
        private readonly array $attributes = []
    ) {
    }

    public static function fromGlobals(string $path): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_') && is_string($value)) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            $path,
            $_GET,
            $_POST,
            $headers
        );
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function body(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;
        return new self($this->method, $this->path, $this->query, $this->body, $this->headers, $attributes);
    }

    /** @param array<string, string> $parameters */
    public function withRouteParameters(array $parameters): self
    {
        $request = $this;
        foreach ($parameters as $name => $value) {
            $request = $request->withAttribute('route.' . $name, $value);
        }
        return $request;
    }
}
