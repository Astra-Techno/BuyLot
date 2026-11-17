<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Http\Middleware\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;

class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private JwtHandler $jwtHandler) {}

    public function handle(Request $request, callable $next): Response
    {
        $token = $this->extractToken($request);

        if ($token === null) {
            return Response::unauthorized('Missing authentication token');
        }

        $payload = $this->jwtHandler->decode($token);

        if ($payload === null) {
            return Response::unauthorized('Invalid or expired token');
        }

        // Store user context in request
        $request = $this->setUserContext($request, $payload);

        return $next($request);
    }

    private function extractToken(Request $request): ?string
    {
        $authHeader = $request->getHeader('Authorization');

        if ($authHeader === null) {
            return null;
        }

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        return substr($authHeader, 7);
    }

    private function setUserContext(Request $request, array $payload): Request
    {
        $params = $request->getParams();
        $params['_user'] = $payload;
        return $request->setParams($params);
    }
}
