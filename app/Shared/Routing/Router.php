<?php
declare(strict_types=1);

namespace EduShare\Shared\Routing;

use EduShare\Shared\Http\HttpException;
use EduShare\Shared\Http\Middleware;
use EduShare\Shared\Http\Request;
use EduShare\Shared\Http\RequestHandler;
use EduShare\Shared\Http\Response;

final class Router implements RequestHandler
{
    /** @var list<Route> */
    private array $routes = [];

    /** @param list<Middleware> $middleware */
    public function add(string $method, string $path, RequestHandler $handler, array $middleware = []): void
    {
        $this->routes[] = new Route(strtoupper($method), $path, $handler, $middleware);
    }

    /** @param list<Middleware> $middleware */
    public function get(string $path, RequestHandler $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function handle(Request $request): Response
    {
        $allowed = [];
        foreach ($this->routes as $route) {
            $parameters = $route->match($request->path());
            if ($parameters === null) {
                continue;
            }
            if ($route->method !== $request->method()) {
                $allowed[] = $route->method;
                continue;
            }

            $pipeline = new MiddlewarePipeline($route->handler, $route->middleware);
            return $pipeline->handle($request->withRouteParameters($parameters));
        }

        if ($allowed !== []) {
            $allow = implode(', ', array_values(array_unique($allowed)));
            throw new HttpException(405, 'This request method is not allowed.', ['Allow' => $allow]);
        }
        throw new HttpException(404, 'Route not found.');
    }
}
