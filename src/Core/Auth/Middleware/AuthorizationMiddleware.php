<?php

declare(strict_types=1);

namespace App\Core\Auth\Middleware;

use App\Core\Auth\Authorization;
use App\Core\Auth\PolicyRegistry;
use App\Core\Http\Middleware\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * Middleware that enforces authorization checks per route.
 *
 * Configured with:
 *   - resource:        The resource domain being accessed (e.g. "bids", "orders")
 *   - action:          The action being performed (e.g. "read_own", "approve")
 *   - contextResolver: Optional callable that extracts ABAC context from the request
 *
 * Returns 403 with a structured JSON error on denial.
 * All denials are logged to the audit trail.
 */
final readonly class AuthorizationMiddleware implements MiddlewareInterface
{
    /**
     * @param string        $resource        Resource domain identifier
     * @param string        $action          Action verb
     * @param callable|null $contextResolver fn(Request): array — extracts ABAC context from request
     */
    public function __construct(
        private string $resource,
        private string $action,
        private ?\Closure $contextResolver = null,
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        // ── Extract authenticated user from request ──────────────
        $userPayload = $request->getParams()['_user'] ?? null;

        if ($userPayload === null) {
            return $this->denyResponse(
                resource: $this->resource,
                action: $this->action,
                reason: 'Authentication required',
                statusCode: 401,
            );
        }

        $userId = (int) ($userPayload['user_id'] ?? $userPayload['sub'] ?? 0);

        if ($userId === 0) {
            return $this->denyResponse(
                resource: $this->resource,
                action: $this->action,
                reason: 'Invalid user identity',
                statusCode: 401,
            );
        }

        // ── Build ABAC context ───────────────────────────────────
        $context = [];
        if ($this->contextResolver !== null) {
            $context = ($this->contextResolver)($request);
        }

        // ── Policy check (if a policy is registered) ─────────────
        $registry = PolicyRegistry::getInstance();
        if ($registry->has($this->resource)) {
            $policy = $registry->resolve($this->resource);
            $user = $userPayload['_user_object'] ?? $this->buildUserProxy($userPayload);

            $allowed = match ($this->action) {
                'view', 'read', 'read_own', 'read_all',
                'read_assigned', 'read_public'          => $policy->canView($user, null, $context),
                'create', 'create_own'                  => $policy->canCreate($user, null, $context),
                'update', 'update_own', 'update_assigned',
                'update_status', 'update_status_assigned' => $policy->canUpdate($user, null, $context),
                'delete', 'delete_own'                  => $policy->canDelete($user, null, $context),
                'approve', 'approve_org',
                'approve_result'                        => $policy->canApprove($user, null, $context),
                'export', 'export_own',
                'export_assigned'                       => $policy->canExport($user, null, $context),
                default                                 => $this->fallbackAuthCheck($userId, $context),
            };

            if (!$allowed) {
                $this->logDenial($userId, $this->resource, $this->action, $context);
                return $this->denyResponse(
                    resource: $this->resource,
                    action: $this->action,
                    reason: 'Insufficient permissions',
                );
            }

            return $next($request);
        }

        // ── Fallback to core Authorization engine ────────────────
        $allowed = Authorization::getInstance()->checkPermission(
            userId: $userId,
            resource: $this->resource,
            action: $this->action,
            context: $context,
        );

        if (!$allowed) {
            $this->logDenial($userId, $this->resource, $this->action, $context);
            return $this->denyResponse(
                resource: $this->resource,
                action: $this->action,
                reason: 'Insufficient permissions',
            );
        }

        return $next($request);
    }

    // ──────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────

    private function fallbackAuthCheck(int $userId, array $context): bool
    {
        return Authorization::getInstance()->checkPermission(
            userId: $userId,
            resource: $this->resource,
            action: $this->action,
            context: $context,
        );
    }

    /**
     * Build a minimal user proxy object from JWT payload for policy checks.
     */
    private function buildUserProxy(array $payload): object
    {
        return new class($payload) {
            public readonly int $id;
            public readonly ?int $seller_id;
            public readonly ?int $organization_id;
            /** @var array */
            private array $payload;

            public function __construct(array $payload)
            {
                $this->payload = $payload;
                $this->id = (int) ($payload['user_id'] ?? $payload['sub'] ?? 0);
                $this->seller_id = isset($payload['seller_id']) ? (int) $payload['seller_id'] : null;
                $this->organization_id = isset($payload['organization_id']) ? (int) $payload['organization_id'] : null;
            }

            public function hasRole(\App\Core\Auth\Enums\Role $role): bool
            {
                $roles = $this->payload['roles'] ?? [];
                return in_array($role->value, $roles, true);
            }

            public function hasPermission(\App\Core\Auth\Enums\Permission $permission): bool
            {
                if ($this->hasRole(\App\Core\Auth\Enums\Role::SUPER_ADMIN)) {
                    return true;
                }
                $perms = $this->payload['permissions'] ?? [];
                return in_array($permission->value, $perms, true);
            }

            /** @return \App\Core\Auth\Enums\Role[] */
            public function getRoles(): array
            {
                return array_filter(array_map(
                    static fn(string $v) => \App\Core\Auth\Enums\Role::tryFrom($v),
                    $this->payload['roles'] ?? [],
                ));
            }

            /** @return \App\Core\Auth\Enums\Permission[] */
            public function getPermissions(): array
            {
                return array_filter(array_map(
                    static fn(string $v) => \App\Core\Auth\Enums\Permission::tryFrom($v),
                    $this->payload['permissions'] ?? [],
                ));
            }
        };
    }

    /**
     * Structured 403 response with denial details.
     */
    private function denyResponse(
        string $resource,
        string $action,
        string $reason,
        int $statusCode = 403,
    ): Response {
        return Response::json(
            data: [
                'error' => $statusCode === 401 ? 'Unauthorized' : 'Forbidden',
                'reason' => $reason,
                'resource' => $resource,
                'action' => $action,
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            statusCode: $statusCode,
        );
    }

    /**
     * Log authorization denial to audit trail.
     * Failed permission checks on sensitive resources generate audit events (Section 5.4).
     */
    private function logDenial(int $userId, string $resource, string $action, array $context): void
    {
        try {
            $db = \App\Core\Database\Connection::getInstance();
            $db->execute(
                'INSERT INTO audit_log (actor_id, action, resource_type, resource_id, payload, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [
                    $userId,
                    'permission_denied',
                    $resource,
                    $context['resource_id'] ?? null,
                    json_encode([
                        'attempted_action' => $action,
                        'context_keys' => array_keys($context),
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ],
            );
        } catch (\Throwable) {
            // Audit logging failure must not break the request flow,
            // but should be monitored via infrastructure alerting.
            error_log("AUDIT_FAILURE: permission_denied user={$userId} resource={$resource} action={$action}");
        }
    }
}
