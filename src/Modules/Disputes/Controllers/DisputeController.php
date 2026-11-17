<?php

declare(strict_types=1);

namespace App\Modules\Disputes\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Disputes\Models\Dispute;
use App\Modules\Disputes\Services\DisputeService;

/**
 * DisputeController — buyer and seller facing dispute endpoints.
 *
 * Routes (all require auth):
 *   POST /v1/orders/{id}/disputes          → open()
 *   GET  /v1/disputes/{id}                 → show()
 *   POST /v1/disputes/{id}/evidence        → submitEvidence()
 *   POST /v1/disputes/{id}/respond         → respondToResolution()
 *   GET  /v1/me/disputes                   → listMine()
 *
 * Access rules:
 *   - Buyers may open disputes on their own orders.
 *   - Both buyer and seller may view disputes related to their orders.
 *   - Both may submit evidence and respond to a proposed resolution.
 *   - listMine() returns disputes opened by the authenticated user.
 */
readonly class DisputeController
{
    public function __construct(
        private DisputeService $disputeService,
    ) {}

    // -------------------------------------------------------------------------
    // POST /v1/orders/{id}/disputes
    // -------------------------------------------------------------------------

    /**
     * Buyer opens a dispute on an order.
     *
     * Body (JSON):
     * {
     *   "type": "wrong_item",             // required; Dispute::ALL_TYPES
     *   "description": "...",             // optional
     *   "evidence": [                     // optional initial evidence
     *     {
     *       "evidence_type": "PHOTO",
     *       "object_ref": "uploads/...",
     *       "description": "..."
     *     }
     *   ]
     * }
     */
    public function open(Request $request, string $orderId): Response
    {
        $userId = $this->resolveUserId($request);
        $body   = $request->getBody() ?? [];

        $type = trim((string) ($body['type'] ?? ''));
        if ($type === '' || !in_array($type, Dispute::ALL_TYPES, true)) {
            throw new ValidationException([
                'type' => 'Required. Allowed values: ' . implode(', ', Dispute::ALL_TYPES),
            ]);
        }

        $evidence = [];
        foreach ((array) ($body['evidence'] ?? []) as $ev) {
            if (empty($ev['evidence_type']) || empty($ev['object_ref'])) {
                throw new ValidationException([
                    'evidence' => 'Each evidence item must have evidence_type and object_ref.',
                ]);
            }
            $evidence[] = [
                'evidence_type' => (string) $ev['evidence_type'],
                'object_ref'    => (string) $ev['object_ref'],
                'description'   => isset($ev['description']) ? (string) $ev['description'] : null,
            ];
        }

        $dispute = $this->disputeService->openDispute([
            'order_id'    => (int) $orderId,
            'type'        => $type,
            'opened_by'   => $userId,
            'description' => isset($body['description']) ? (string) $body['description'] : null,
            'evidence'    => $evidence,
        ]);

        return Response::json($dispute, 201);
    }

    // -------------------------------------------------------------------------
    // GET /v1/disputes/{id}
    // -------------------------------------------------------------------------

    /**
     * Show a dispute.  Buyer sees their own; seller sees disputes on their orders.
     * The route middleware has already verified authentication.
     * Access scoping: we load the dispute and verify the caller owns or is
     * related to the underlying order.  Since this module does not own the
     * orders table we apply a simple rule: allow if opened_by == userId,
     * or allow if the caller has a seller/support role (checked via JWT roles).
     */
    public function show(Request $request, string $id): Response
    {
        $userId = $this->resolveUserId($request);
        $roles  = $this->resolveRoles($request);

        $dispute = $this->disputeService->getOrFail((int) $id);

        $isSeller  = in_array('seller', $roles, true);
        $isSupport = array_intersect(['support_disputes', 'compliance_admin', 'super_admin'], $roles) !== [];

        if ($dispute['opened_by'] !== $userId && !$isSeller && !$isSupport) {
            throw new HttpException('Access denied.', 403);
        }

        return Response::json($dispute);
    }

    // -------------------------------------------------------------------------
    // POST /v1/disputes/{id}/evidence
    // -------------------------------------------------------------------------

    /**
     * Buyer or seller uploads evidence.
     *
     * Body (JSON):
     * {
     *   "evidence_type": "PHOTO",
     *   "object_ref": "uploads/...",
     *   "description": "..."
     * }
     */
    public function submitEvidence(Request $request, string $id): Response
    {
        $userId = $this->resolveUserId($request);
        $body   = $request->getBody() ?? [];

        $evidenceType = trim((string) ($body['evidence_type'] ?? ''));
        $objectRef    = trim((string) ($body['object_ref'] ?? ''));

        if ($evidenceType === '') {
            throw new ValidationException(['evidence_type' => 'Required.']);
        }

        if ($objectRef === '') {
            throw new ValidationException(['object_ref' => 'Required.']);
        }

        $dispute = $this->disputeService->submitEvidence((int) $id, $userId, [
            'evidence_type' => $evidenceType,
            'object_ref'    => $objectRef,
            'description'   => isset($body['description']) ? (string) $body['description'] : null,
        ]);

        return Response::json($dispute);
    }

    // -------------------------------------------------------------------------
    // POST /v1/disputes/{id}/respond
    // -------------------------------------------------------------------------

    /**
     * Buyer (or seller) accepts or rejects a proposed resolution.
     *
     * Body (JSON):
     * {
     *   "accept": true,       // required; boolean
     *   "notes": "..."        // optional
     * }
     */
    public function respondToResolution(Request $request, string $id): Response
    {
        $userId = $this->resolveUserId($request);
        $body   = $request->getBody() ?? [];

        if (!isset($body['accept'])) {
            throw new ValidationException(['accept' => 'Required. Boolean true or false.']);
        }

        $accept = filter_var($body['accept'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($accept === null) {
            throw new ValidationException(['accept' => 'Must be a boolean.']);
        }

        $dispute = $this->disputeService->respondToResolution(
            (int) $id,
            $userId,
            $accept,
            isset($body['notes']) ? (string) $body['notes'] : null,
        );

        return Response::json($dispute);
    }

    // -------------------------------------------------------------------------
    // GET /v1/me/disputes
    // -------------------------------------------------------------------------

    /**
     * Paginated list of disputes opened by the authenticated user.
     *
     * Query params: status, type, page, per_page
     */
    public function listMine(Request $request): Response
    {
        $userId  = $this->resolveUserId($request);
        $page    = max(1, (int) ($request->getParam('page') ?? 1));
        $perPage = min(100, max(1, (int) ($request->getParam('per_page') ?? 20)));

        $filters = [];
        if ($s = $request->getParam('status')) {
            $filters['status'] = (string) $s;
        }
        if ($t = $request->getParam('type')) {
            $filters['type'] = (string) $t;
        }

        // Delegate to the model via service helper (no extra service method needed).
        // We call the model directly through the service's exposed model (not ideal
        // for strict DI but pragmatic given the codebase pattern — service owns the
        // lifecycle, controller orchestrates queries for reads).
        // Instead, expose a lightweight wrapper on the service:
        $result = $this->disputeService->listMine($userId, $filters, $page, $perPage);

        return Response::json($result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveUserId(Request $request): int
    {
        $userCtx = $request->getParam('_user');

        if (!is_array($userCtx) || empty($userCtx['user_id'])) {
            throw new HttpException('Invalid authentication context.', 401);
        }

        return (int) $userCtx['user_id'];
    }

    /** @return string[] */
    private function resolveRoles(Request $request): array
    {
        $userCtx = $request->getParam('_user');
        return (array) ($userCtx['roles'] ?? []);
    }
}
