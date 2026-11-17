<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Settlements\Models\SellerSettlement;
use App\Modules\Settlements\Models\SettlementItem;
use App\Modules\Settlements\Services\ReconciliationService;
use App\Modules\Settlements\Services\SettlementService;

/**
 * SettlementAdminController -- internal settlement management endpoints.
 *
 * GET  /v1/internal/settlements                           -> listSettlements()
 * GET  /v1/internal/settlements/{id}                      -> showSettlement()
 * POST /v1/internal/settlements/{id}/approve              -> approveSettlement()
 * POST /v1/internal/settlements/{id}/release              -> requestRelease()
 * GET  /v1/internal/reconciliation/exceptions             -> listReconciliationExceptions()
 */
readonly class SettlementAdminController
{
    public function __construct(
        private SettlementService      $settlementService,
        private SellerSettlement       $settlementModel,
        private SettlementItem         $itemModel,
        private ReconciliationService  $reconciliationService,
    ) {}

    /**
     * GET /v1/internal/settlements
     *
     * Query params: seller_id, status, page, per_page
     */
    public function listSettlements(Request $request): Response
    {
        $filters = [];

        $sellerId = $request->getQuery('seller_id');
        if ($sellerId !== null && is_numeric($sellerId)) {
            $filters['seller_id'] = (int) $sellerId;
        }

        $status = $request->getQuery('status');
        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }

        $page    = max(1, (int) ($request->getQuery('page') ?? 1));
        $perPage = max(1, min(100, (int) ($request->getQuery('per_page') ?? 20)));

        $result = $this->settlementModel->list($filters, $page, $perPage);

        return Response::json($result);
    }

    /**
     * GET /v1/internal/settlements/{id}
     */
    public function showSettlement(Request $request): Response
    {
        $settlementId = $this->resolveSettlementId($request);

        $settlement = $this->settlementModel->find($settlementId);
        if ($settlement === null) {
            throw new HttpException('Settlement not found', 404);
        }

        $items = $this->itemModel->findBySettlementId($settlementId);

        return Response::json([
            'settlement' => $settlement,
            'items'      => $items,
        ]);
    }

    /**
     * POST /v1/internal/settlements/{id}/approve
     *
     * Maker-checker: the approver is the authenticated internal user and
     * must differ from the preparer.
     */
    public function approveSettlement(Request $request): Response
    {
        $settlementId = $this->resolveSettlementId($request);
        $approverId   = $this->resolveUserId($request);

        try {
            $settlement = $this->settlementService->approveSettlement($settlementId, $approverId);
            return Response::json($settlement);
        } catch (\RuntimeException $e) {
            throw new HttpException($e->getMessage(), 422);
        }
    }

    /**
     * POST /v1/internal/settlements/{id}/release
     *
     * Request fund release via the payment provider.
     */
    public function requestRelease(Request $request): Response
    {
        $settlementId = $this->resolveSettlementId($request);

        try {
            $settlement = $this->settlementService->requestRelease($settlementId);
            return Response::json($settlement);
        } catch (\RuntimeException $e) {
            throw new HttpException($e->getMessage(), 422);
        }
    }

    /**
     * GET /v1/internal/reconciliation/exceptions
     *
     * Query params: status, entity_type, page, per_page
     */
    public function listReconciliationExceptions(Request $request): Response
    {
        $filters = [];

        $status = $request->getQuery('status');
        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }

        $entityType = $request->getQuery('entity_type');
        if ($entityType !== null && $entityType !== '') {
            $filters['entity_type'] = $entityType;
        }

        $page    = max(1, (int) ($request->getQuery('page') ?? 1));
        $perPage = max(1, min(100, (int) ($request->getQuery('per_page') ?? 20)));

        $result = $this->reconciliationService->listExceptions($filters, $page, $perPage);

        return Response::json($result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveSettlementId(Request $request): int
    {
        $id = $request->getParam('id');
        if ($id === null || !is_numeric($id)) {
            throw new HttpException('Invalid settlement ID', 400);
        }
        return (int) $id;
    }

    private function resolveUserId(Request $request): int
    {
        $userCtx = $request->getParam('_user');
        if (!is_array($userCtx) || empty($userCtx['user_id'])) {
            throw new HttpException('Invalid authentication context', 401);
        }
        return (int) $userCtx['user_id'];
    }
}
