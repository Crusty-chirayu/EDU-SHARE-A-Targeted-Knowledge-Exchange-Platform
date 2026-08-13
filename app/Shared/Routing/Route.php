<?php
declare(strict_types=1);

namespace EduShare\Shared\Routing;

use EduShare\Shared\Http\Middleware;
use EduShare\Shared\Http\RequestHandler;

final class Route
{
    private readonly string $pattern;

    /** @param list<Middleware> $middleware */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly RequestHandler $handler,
        public readonly array $middleware = []
    ) {
        if ($path === '' || $path[0] !== '/') {
            throw new \InvalidArgumentException('Route paths must begin with a slash.');
        }

        $segments = explode('/', trim($path, '/'));
        $compiled = [];
        foreach ($segments as $segment) {
            if (preg_match('/^\{([A-Za-z][A-Za-z0-9_]*)\}$/D', $segment, $matches) === 1) {
                $compiled[] = '(?P<' . $matches[1] . '>[^/]+)';
            } else {
                $compiled[] = preg_quote($segment, '#');
            }
        }
        $this->pattern = $path === '/' ? '#^/$#D' : '#^/' . implode('/', $compiled) . '$#D';
    }

    /** @return array<string, string>|null */
    public function match(string $path): ?array
    {
        if (preg_match($this->pattern, $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $parameters[$key] = rawurldecode((string) $value);
            }
        }
        return $parameters;
    }
}
