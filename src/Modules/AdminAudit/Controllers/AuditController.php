<?php

declare(strict_types=1);

namespace App\Modules\AdminAudit\Controllers;

use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\AdminAudit\Services\AuditService;

/**
 * AuditController — read-only endpoints for compliance admins.
 *
 * Intentionally exposes NO create, update, or delete operations.
 * The audit log is append-only; writes happen exclusively via AuditService.
 */
readonly class AuditController
{
    private AuditService $auditService;

    /** Roles permitted to access audit log endpoints. */
    private const ALLOWED_ROLES = ['compliance_admin', 'super_admin'];

    public function __construct(private Connection $connection)
    {
        $this->auditService = new AuditService($connection);
    }

    // -------------------------------------------------------------------------
    // GET /v1/internal/audit
    // -------------------------------------------------------------------------

    public function list(Request $request): Response
    {
        $this->requireAdminRole($request);

        $query   = $request->getQuery();
        $filters = $this->buildFilters($query);

        $result = $this->auditService->query($filters);

        return Response::json([
            'logs'   => $result['logs'],
            'total'  => $result['total'],
            'limit'  => $result['limit'],
            'offset' => $result['offset'],
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /v1/internal/audit/{id}
    // -------------------------------------------------------------------------

    public function show(Request $request, int $id): Response
    {
        $this->requireAdminRole($request);

        $log = $this->auditService->findById($id);

        if ($log === null) {
            return Response::notFound('Audit log entry not found');
        }

        return Response::json($log);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Enforce that the authenticated user holds one of the permitted admin roles.
     * Throws HttpException (401 / 403) if not.
     */
    private function requireAdminRole(Request $request): void
    {
        $user = $request->getParam('_user');

        if (empty($user['user_id'])) {
            throw new HttpException('Authentication required', 401);
        }

        $role = $user['role'] ?? '';

        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new HttpException(
                'Access denied. compliance_admin or super_admin role required.',
                403,
            );
        }
    }

    /**
     * Map query-string params to the filter array accepted by AuditService::query().
     */
    private function buildFilters(mixed $query): array
    {
        if (!is_array($query)) {
            return [];
        }

        $filters = [];

        if (!empty($query['actor_id']) && ctype_digit((string) $query['actor_id'])) {
            $filters['actor_id'] = (int) $query['actor_id'];
        }

        if (!empty($query['action'])) {
            $filters['action'] = (string) $query['action'];
        }

        if (!empty($query['entity_type'])) {
            $filters['entity_type'] = (string) $query['entity_type'];
        }

        if (!empty($query['entity_id']) && ctype_digit((string) $query['entity_id'])) {
            $filters['entity_id'] = (int) $query['entity_id'];
        }

        if (!empty($query['date_from'])) {
            $filters['date_from'] = (string) $query['date_from'];
        }

        if (!empty($query['date_to'])) {
            $filters['date_to'] = (string) $query['date_to'];
        }

        if (!empty($query['limit']) && ctype_digit((string) $query['limit'])) {
            $filters['limit'] = (int) $query['limit'];
        }

        if (!empty($query['offset']) && ctype_digit((string) $query['offset'])) {
            $filters['offset'] = (int) $query['offset'];
        }

        return $filters;
    }
}
