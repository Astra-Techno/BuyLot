<?php

declare(strict_types=1);

namespace App\Modules\Identity\Middleware;

use App\Core\Http\Middleware\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * RoleMiddleware verifies that the authenticated user (set in `_user` by
 * AuthMiddleware) holds at least one of the required roles.
 *
 * Usage — when registering routes:
 *
 *   new RoleMiddleware('super_admin', 'compliance_admin')
 *
 * The user JWT payload is expected to contain:
 *   { "user_id": "...", "roles": ["buyer", ...] }
 */
class RoleMiddleware implements MiddlewareInterface
{
    /** @var string[] */
    private array $required;

    public function __construct(string ...$roles)
    {
        $this->required = $roles;
    }

    public function handle(Request $request, callable $next): Response
    {
        /** @var array|null $user */
        $user = $request->getParam('_user');

        if ($user === null) {
            return Response::unauthorized('Authentication required');
        }

        $userRoles = $user['roles'] ?? [];

        if (empty($userRoles) || !is_array($userRoles)) {
            return Response::forbidden('No roles assigned to this account');
        }

        foreach ($this->required as $role) {
            if (in_array($role, $userRoles, true)) {
                return $next($request);
            }
        }

        return Response::forbidden(
            'Access denied. Required role(s): ' . implode(', ', $this->required),
        );
    }
}
