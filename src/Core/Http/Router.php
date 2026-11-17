<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Exception\HttpException;
use App\Core\Http\Middleware\Pipeline;
use Closure;

class Router
{
    /** @var array<array{method: string, pattern: string, handler: Closure, middleware: array}> */
    private array $routes = [];

    public function register(
        string $method,
        string $path,
        Closure $handler,
        array $middleware = [],
    ): self {
        $this->routes[] = [
            'method' => strtoupper($method),
            'pattern' => $path,
            'handler' => $handler,
            'middleware' => $middleware,
        ];
        return $this;
    }

    public function get(string $path, Closure $handler, array $middleware = []): self
    {
        return $this->register('GET', $path, $handler, $middleware);
    }

    public function post(string $path, Closure $handler, array $middleware = []): self
    {
        return $this->register('POST', $path, $handler, $middleware);
    }

    public function put(string $path, Closure $handler, array $middleware = []): self
    {
        return $this->register('PUT', $path, $handler, $middleware);
    }

    public function patch(string $path, Closure $handler, array $middleware = []): self
    {
        return $this->register('PATCH', $path, $handler, $middleware);
    }

    public function delete(string $path, Closure $handler, array $middleware = []): self
    {
        return $this->register('DELETE', $path, $handler, $middleware);
    }

    public function dispatch(Request $request): Response
    {
        $route = $this->match($request->getMethod(), $request->getPath());

        if ($route === null) {
            throw new HttpException('Route not found', 404);
        }

        $request = $request->setParams($route['params']);
        $pipeline = new Pipeline();

        return $pipeline->through(
            $route['middleware'],
            fn(Request $req) => ($route['handler'])($req),
        )($request);
    }

    private function match(string $method, string $path): ?array
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }

            $pattern = $this->pathToRegex($route['pattern']);
            if (preg_match($pattern, $path, $matches)) {
                $params = array_filter($matches, is_string(...), ARRAY_FILTER_USE_KEY);
                return [
                    'handler' => $route['handler'],
                    'middleware' => $route['middleware'],
                    'params' => $params,
                ];
            }
        }

        return null;
    }

    private function pathToRegex(string $path): string
    {
        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/',
            static fn(array $matches) => '(?P<' . $matches[1] . '>[^/]+)',
            $path,
        );

        return '#^' . $pattern . '$#';
    }
}
