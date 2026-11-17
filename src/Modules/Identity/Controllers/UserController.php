<?php

declare(strict_types=1);

namespace App\Modules\Identity\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\UserService;

/**
 * UserController — admin-facing user management endpoints.
 *
 * All routes require authentication + an admin/compliance role
 * (enforced at the route level via RoleMiddleware).
 *
 *   GET  /v1/admin/users              → list()
 *   GET  /v1/admin/users/{id}         → show()
 *   PUT  /v1/admin/users/{id}/status  → updateStatus()
 *   POST /v1/admin/users/{id}/roles   → assignRole()
 */
readonly class UserController
{
    public function __construct(
        private UserService $userService,
        private User        $userModel,
    ) {}

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /v1/admin/users
     *
     * Query params:
     *   page     (int, default 1)
     *   per_page (int, 1–100, default 20)
     *   status   (active|suspended|pending_deletion)
     *   role     (any role name)
     *   search   (string — matches name or mobile)
     */
    public function list(Request $request): Response
    {
        $page    = max(1, (int) $request->getQuery('page', 1));
        $perPage = min(100, max(1, (int) $request->getQuery('per_page', 20)));

        $filters = array_filter([
            'status' => $request->getQuery('status'),
            'role'   => $request->getQuery('role'),
            'search' => $request->getQuery('search'),
        ], static fn($v) => $v !== null && $v !== '');

        if (!empty($filters['status'])
            && !in_array($filters['status'], ['active', 'suspended', 'pending_deletion'], true)
        ) {
            throw new ValidationException(['status' => 'Invalid status filter. Allowed: active, suspended, pending_deletion']);
        }

        $result = $this->userModel->list($filters, $page, $perPage);

        return Response::json([
            'data'       => $result['data'],
            'pagination' => [
                'total'    => $result['total'],
                'page'     => $result['page'],
                'per_page' => $result['per_page'],
                'pages'    => (int) ceil(max(1, $result['total']) / $result['per_page']),
            ],
        ]);
    }

    /**
     * GET /v1/admin/users/{id}
     */
    public function show(Request $request): Response
    {
        $id   = $this->resolveId($request);
        $user = $this->userService->getOrFail($id);

        return Response::json($user);
    }

    /**
     * PUT /v1/admin/users/{id}/status
     *
     * Body: { "status": "active"|"suspended" }
     */
    public function updateStatus(Request $request): Response
    {
        $id     = $this->resolveId($request);
        $status = $request->getBody('status');

        if (empty($status)) {
            throw new ValidationException(['status' => 'Status is required']);
        }

        $user = $this->userService->updateStatus($id, (string) $status);

        return Response::json($user);
    }

    /**
     * POST /v1/admin/users/{id}/roles
     *
     * Body: { "role": "seller" }
     */
    public function assignRole(Request $request): Response
    {
        $id   = $this->resolveId($request);
        $role = $request->getBody('role');

        if (empty($role)) {
            throw new ValidationException(['role' => 'Role is required']);
        }

        $user = $this->userService->assignRole($id, (string) $role);

        return Response::json($user, 200);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveId(Request $request): string
    {
        $id = $request->getParam('id');

        if (empty($id)) {
            throw new HttpException('User ID is required', 400);
        }

        return (string) $id;
    }
}
