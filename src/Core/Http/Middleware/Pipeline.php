<?php

declare(strict_types=1);

namespace App\Core\Http\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use Closure;

class Pipeline
{
    /**
     * @param array<string|MiddlewareInterface> $middleware
     * @param Closure(Request): Response $destination
     */
    public function through(array $middleware, Closure $destination): Closure
    {
        return array_reduce(
            array_reverse($middleware),
            fn(Closure $next, mixed $middleware) => $this->wrap($middleware, $next),
            fn(Request $request) => $this->resolve($destination)($request),
        );
    }

    private function wrap(mixed $middleware, Closure $next): Closure
    {
        return function(Request $request) use ($middleware, $next): Response {
            $instance = $this->resolve($middleware);

            if ($instance instanceof MiddlewareInterface) {
                return $instance->handle($request, $next);
            }

            if (is_callable($instance)) {
                return $instance($request, $next);
            }

            return $next($request);
        };
    }

    private function resolve(mixed $middleware): mixed
    {
        if ($middleware instanceof Closure || is_callable($middleware)) {
            return $middleware;
        }

        if (is_string($middleware)) {
            if (class_exists($middleware)) {
                return new $middleware();
            }
            return null;
        }

        return $middleware;
    }
}
