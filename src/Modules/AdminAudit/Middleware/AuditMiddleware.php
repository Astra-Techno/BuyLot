<?php

declare(strict_types=1);

namespace App\Modules\AdminAudit\Middleware;

use App\Core\Database\Connection;
use App\Core\Http\Middleware\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\AdminAudit\Models\AuditLog;
use App\Modules\AdminAudit\Services\AuditService;
use Throwable;

/**
 * AuditMiddleware
 *
 * Automatically records an audit log entry for all write operations
 * (POST, PUT, PATCH, DELETE).
 *
 * Behaviour:
 * - Runs the downstream handler first, then captures the response.
 * - Only writes an audit entry for 2xx responses (successful mutations).
 * - Is NON-BLOCKING: if the audit write itself throws, the error is logged
 *   to error_log and the original response is returned unchanged.
 * - Derives actor from _user request param (set by AuthMiddleware).
 * - Derives resource_type and resource_id from route params {id} and path.
 *
 * Usage: add as the last (outermost) middleware on write routes, after auth.
 */
class AuditMiddleware implements MiddlewareInterface
{
    private const WRITE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private AuditService $auditService;

    public function __construct(private readonly Connection $connection)
    {
        $this->auditService = new AuditService($connection);
    }

    public function handle(Request $request, callable $next): Response
    {
        $method = strtoupper($request->getMethod());

        // Pass through read-only operations without touching the audit log.
        if (!in_array($method, self::WRITE_METHODS, true)) {
            return $next($request);
        }

        // Execute the actual handler first so we can capture the response.
        $response = $next($request);

        // Only audit successful mutations; skip client errors, server errors, etc.
        $statusCode = $response->getStatusCode();
        if ($statusCode < 200 || $statusCode >= 300) {
            return $response;
        }

        // Attempt to write the audit entry non-blockingly.
        try {
            $this->writeAuditEntry($request, $response, $method);
        } catch (Throwable $e) {
            error_log(sprintf(
                '[AuditMiddleware] Audit write failed for %s %s: %s in %s:%d',
                $method,
                $request->getPath(),
                $e->getMessage(),
                $e->getFile(),
                $e->getLine(),
            ));
        }

        return $response;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function writeAuditEntry(Request $request, Response $response, string $method): void
    {
        $user       = $request->getParam('_user');
        $actorId    = !empty($user['user_id']) ? (int) $user['user_id'] : null;
        $actorType  = $actorId !== null ? AuditLog::ACTOR_TYPE_USER : AuditLog::ACTOR_TYPE_SYSTEM;
        $actorRole  = $user['role'] ?? null;

        $action        = $this->deriveAction($method, $request->getPath());
        $resourceType  = $this->deriveResourceType($request->getPath());
        $resourceId    = $this->deriveResourceId($request);

        // Capture request body as the "after" state for POST/PUT/PATCH.
        // For DELETE we record the resource id only (no body snapshot).
        $afterValues = match ($method) {
            'DELETE' => null,
            default  => $this->sanitizeBody($request->getBody()),
        };

        // Capture response data as the canonical "after" state when available.
        $responseData = $response->getData();
        if ($responseData !== null && $method !== 'DELETE') {
            $afterValues = $responseData;
        }

        $this->auditService->log(
            actorId:      $actorId,
            actorType:    $actorType,
            action:       $action,
            resourceType: $resourceType,
            resourceId:   $resourceId,
            oldValues:    null,   // Pre-change snapshots are populated by callers that need diff tracking
            newValues:    $afterValues,
            request:      $request,
        );
    }

    /**
     * Map HTTP method + path to a human-readable action token.
     * e.g. POST /v1/organizations => "organizations.created"
     */
    private function deriveAction(string $method, string $path): string
    {
        $resourceType = $this->deriveResourceType($path);

        $verb = match ($method) {
            'POST'   => 'created',
            'PUT'    => 'updated',
            'PATCH'  => 'patched',
            'DELETE' => 'deleted',
            default  => 'mutated',
        };

        return $resourceType !== null
            ? "{$resourceType}.{$verb}"
            : "request.{$verb}";
    }

    /**
     * Extract a resource type slug from the URL path.
     * /v1/organizations          => "organization"
     * /v1/organizations/5        => "organization"
     * /v1/organizations/5/members => "organization.member"
     * /v1/internal/audit         => "audit_log"
     */
    private function deriveResourceType(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', $path)));

        // Drop version prefix e.g. "v1" or "internal"
        $meaningful = [];
        foreach ($segments as $segment) {
            if (preg_match('/^v\d+$/', $segment) || $segment === 'internal') {
                continue;
            }
            // Skip numeric segments (ids)
            if (ctype_digit($segment)) {
                continue;
            }
            $meaningful[] = $segment;
        }

        if (empty($meaningful)) {
            return null;
        }

        // Singularize the first resource name naively (remove trailing 's')
        $primary = rtrim($meaningful[0], 's');

        if (count($meaningful) > 1) {
            $secondary = rtrim($meaningful[1], 's');
            return "{$primary}.{$secondary}";
        }

        return $primary;
    }

    /**
     * Extract the primary resource id from route params.
     * Uses {memberId} when present for sub-resource routes; falls back to {id}.
     */
    private function deriveResourceId(Request $request): ?int
    {
        $memberIdParam = $request->getParam('memberId');
        if ($memberIdParam !== null) {
            $id = filter_var($memberIdParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                return $id;
            }
        }

        $idParam = $request->getParam('id');
        if ($idParam !== null) {
            $id = filter_var($idParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                return $id;
            }
        }

        return null;
    }

    /**
     * Remove sensitive fields before persisting the body snapshot.
     */
    private function sanitizeBody(mixed $body): mixed
    {
        if (!is_array($body)) {
            return $body;
        }

        $sensitiveKeys = ['password', 'password_confirmation', 'token', 'secret', 'tax_id_ref'];

        foreach ($sensitiveKeys as $key) {
            if (array_key_exists($key, $body)) {
                $body[$key] = '[REDACTED]';
            }
        }

        return $body;
    }
}
