<?php

declare(strict_types=1);

namespace App\Modules\Disputes\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Disputes\Models\Dispute;
use App\Modules\Disputes\Models\DisputeAdjustment;
use App\Modules\Disputes\Services\DisputeService;

/**
 * DisputeAdminController — internal support/disputes team endpoints.
 *
 * All routes require: auth + support_disputes (or compliance_admin / super_admin).
 *
 * Routes:
 *   GET  /v1/internal/disputes                        → list()
 *   GET  /v1/internal/disputes/{id}                   → show()
 *   POST /v1/internal/disputes/{id}/assign            → assign()
 *   POST /v1/internal/disputes/{id}/request-evidence  → requestEvidence()
 *   POST /v1/internal/disputes/{id}/investigate       → investigate()
 *   POST /v1/internal/disputes/{id}/propose-resolution → proposeResolution()
 *   POST /v1/internal/disputes/{id}/resolve           → resolve()
 *   POST /v1/internal/disputes/{id}/appeal            → appeal()
 */
readonly class DisputeAdminController
{
    public function __construct(
        private DisputeService $disputeService,
    ) {}

    // -------------------------------------------------------------------------
    // GET /v1/internal/disputes
    // -------------------------------------------------------------------------

    /**
     * Paginated list with optional filters.
     *
     * Query params: status, type, assigned_to, order_id, page, per_page
     */
    public function list(Request $request): Response
    {
        $page    = max(1, (int) ($request->getParam('page') ?? 1));
        $perPage = min(100, max(1, (int) ($request->getParam('per_page') ?? 20)));

        $filters = [];
        foreach (['status', 'type', 'order_id'] as $key) {
            if ($v = $request->getParam($key)) {
                $filters[$key] = (string) $v;
            }
        }
        if ($at = $request->getParam('assigned_to')) {
            $filters['assigned_to'] = (int) $at;
        }

        $result = $this->disputeService->listAll($filters, $page, $perPage);

        return Response::json($result);
    }

    // -------------------------------------------------------------------------
    // GET /v1/internal/disputes/{id}
    // -------------------------------------------------------------------------

    /**
     * Full dispute detail including complete event + evidence timeline.
     */
    public function show(Request $request, string $id): Response
    {
        $dispute  = $this->disputeService->getOrFail((int) $id);
        $timeline = $this->disputeService->getTimeline((int) $id);

        return Response::json(array_merge($dispute, ['timeline' => $timeline]));
    }

    // -------------------------------------------------------------------------
    // POST /v1/internal/disputes/{id}/assign
    // -------------------------------------------------------------------------

    /**
     * Assign dispute to a support agent.
     *
     * Body: { "agent_id": 42, "notes": "..." }
     */
    public function assign(Request $request, string $id): Response
    {
        $actorId = $this->resolveUserId($request);
        $body    = $request->getBody() ?? [];

        $agentId = (int) ($body['agent_id'] ?? 0);
        if ($agentId < 1) {
            throw new ValidationException(['agent_id' => 'Required integer.']);
        }

        $dispute = $this->disputeService->assignDispute(
            (int) $id,
            $agentId,
            $actorId,
            isset($body['notes']) ? (string) $body['notes'] : null,
        );

        return Response::json($dispute);
    }

    // -------------------------------------------------------------------------
    // POST /v1/internal/disputes/{id}/request-evidence
    // -------------------------------------------------------------------------

    /**
     * Request evidence from one or both parties.
     *
     * Body: { "notes": "Please upload photos of the damaged packaging." }
     */
    public function requestEvidence(Request $request, string $id): Response
    {
        $actorId = $this->resolveUserId($request);
        $body    = $request->getBody() ?? [];

        $notes = trim((string) ($body['notes'] ?? ''));
        if ($notes === '') {
            throw new ValidationException(['notes' => 'Required. Describe what evidence is needed.']);
        }

        $dispute = $this->disputeService->requestEvidence((int) $id, $actorId, $notes);

        return Response::json($dispute);
    }

    // -------------------------------------------------------------------------
    // POST /v1/internal/disputes/{id}/investigate
    // -------------------------------------------------------------------------

    /**
     * Move dispute to under_investigation.
     *
     * Body: { "notes": "..." }  (optional)
     */
    public function investigate(Request $request, string $id): Response
    {
        $actorId = $this->resolveUserId($request);
        $body    = $request->getBody() ?? [];

        $dispute = $this->disputeService->investigate(
            (int) $id,
            $actorId,
            isset($body['notes']) ? (string) $body['notes'] : null,
        );

        return Response::json($dispute);
    }

    // -------------------------------------------------------------------------
    // POST /v1/internal/disputes/{id}/propose-resolution
    // -------------------------------------------------------------------------

    /**
     * Propose a resolution to the parties.
     *
     * Body:
     * {
     *   "resolution": "partial_refund_approved",
     *   "resolution_details": {
     *     "type": "PARTIAL_REFUND",
     *     "amount_paise": 50000,
     *     "note": "Partial refund for 2 of 5 damaged units."
     *   }
     * }
     */
    public function proposeResolution(Request $request, string $id): Response
    {
        $actorId = $this->resolveUserId($request);
        $body    = $request->getBody() ?? [];

        $resolution = trim((string) ($body['resolution'] ?? ''));
        if ($resolution === '') {
            throw new ValidationException(['resolution' => 'Required.']);
        }

        if (empty($body['resolution_details']) || !is_array($body['resolution_details'])) {
            throw new ValidationException(['resolution_details' => 'Required object.']);
        }

        $dispute = $this->disputeService->proposeResolution((int) $id, $actorId, [
            'resolution'         => $resolution,
            'resolution_details' => $body['resolution_details'],
        ]);

        return Response::json($dispute);
    }

    // -------------------------------------------------------------------------
    // POST /v1/internal/disputes/{id}/resolve
    // -------------------------------------------------------------------------

    /**
     * Finalise and resolve the dispute with optional financial adjustment.
     *
     * For adjustments >= MAKER_CHECKER_THRESHOLD_PAISE the approved_by must
     * differ from created_by (maker-checker rule).
     *
     * Body:
     * {
     *   "resolution": "refund_approved",
     *   "resolution_details": { ... },
     *   "adjustment": {               // optional
     *     "type": "REFUND",
     *     "amount_paise": 150000,
     *     "approved_by": 99,          // finance approver user_id; must != created_by for large amounts
     *     "created_by": 42,           // support agent who proposed
     *     "reason": "Full refund — wrong item delivered."
     *   }
     * }
     */
    public function resolve(Request $request, string $id): Response
    {
        $actorId = $this->resolveUserId($request);
        $body    = $request->getBody() ?? [];

        $resolution = trim((string) ($body['resolution'] ?? ''));
        if ($resolution === '') {
            throw new ValidationException(['resolution' => 'Required.']);
        }

        if (empty($body['resolution_details']) || !is_array($body['resolution_details'])) {
            throw new ValidationException(['resolution_details' => 'Required object.']);
        }

        $adjustment = null;
        if (!empty($body['adjustment']) && is_array($body['adjustment'])) {
            $adj = $body['adjustment'];

            $adjType = trim((string) ($adj['type'] ?? ''));
            if (!in_array($adjType, DisputeAdjustment::ALL_TYPES, true)) {
                throw new ValidationException([
                    'adjustment.type' => 'Invalid. Allowed: ' . implode(', ', DisputeAdjustment::ALL_TYPES),
                ]);
            }

            $approvedBy = (int) ($adj['approved_by'] ?? 0);
            $createdBy  = (int) ($adj['created_by']  ?? 0);

            if ($approvedBy < 1) {
                throw new ValidationException(['adjustment.approved_by' => 'Required integer.']);
            }
            if ($createdBy < 1) {
                throw new ValidationException(['adjustment.created_by' => 'Required integer.']);
            }

            $adjustment = [
                'type'         => $adjType,
                'amount_paise' => (int) ($adj['amount_paise'] ?? 0),
                'approved_by'  => $approvedBy,
                'created_by'   => $createdBy,
                'reason'       => isset($adj['reason']) ? (string) $adj['reason'] : null,
            ];
        }

        $dispute = $this->disputeService->resolveDispute((int) $id, $actorId, [
            'resolution'         => $resolution,
            'resolution_details' => $body['resolution_details'],
            'adjustment'         => $adjustment,
        ]);

        return Response::json($dispute);
    }

    // -------------------------------------------------------------------------
    // POST /v1/internal/disputes/{id}/appeal
    // -------------------------------------------------------------------------

    /**
     * Re-open a resolved dispute for appeal.
     *
     * Body: { "reason": "Buyer disputes the settlement amount." }
     */
    public function appeal(Request $request, string $id): Response
    {
        $actorId = $this->resolveUserId($request);
        $body    = $request->getBody() ?? [];

        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            throw new ValidationException(['reason' => 'Required.']);
        }

        $dispute = $this->disputeService->appealDispute((int) $id, $actorId, $reason);

        return Response::json($dispute);
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
}
