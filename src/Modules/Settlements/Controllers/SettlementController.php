<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Settlements\Models\SellerSettlement;
use App\Modules\Settlements\Models\SettlementItem;

/**
 * SettlementController -- seller-facing settlement endpoints.
 *
 * GET /v1/seller/settlements      -> listSettlements()
 * GET /v1/seller/settlements/{id} -> showSettlement()
 */
readonly class SettlementController
{
    public function __construct(
        private SellerSettlement $settlementModel,
        private SettlementItem  $itemModel,
    ) {}

    /**
     * GET /v1/seller/settlements
     *
     * Lists settlements for the authenticated seller.
     * Query params: status, page, per_page
     */
    public function listSettlements(Request $request): Response
    {
        $sellerId = $this->resolveSellerProfileId($request);

        $filters = ['seller_id' => $sellerId];

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
     * GET /v1/seller/settlements/{id}
     *
     * Shows settlement detail with items. The seller can only see their own settlements.
     */
    public function showSettlement(Request $request): Response
    {
        $settlementId = $this->resolveSettlementId($request);
        $sellerId     = $this->resolveSellerProfileId($request);

        $settlement = $this->settlementModel->find($settlementId);
        if ($settlement === null) {
            throw new HttpException('Settlement not found', 404);
        }

        // Authorization: seller can only view their own settlements
        if ((int) $settlement['seller_id'] !== $sellerId) {
            throw new HttpException('Settlement not found', 404); // Intentionally 404, not 403
        }

        $items = $this->itemModel->findBySettlementId($settlementId);

        return Response::json([
            'settlement' => $settlement,
            'items'      => $items,
        ]);
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

    /**
     * Resolve the seller profile ID from the authenticated user context.
     *
     * The _user param is set by AuthMiddleware and should contain
     * seller_profile_id for seller users.
     */
    private function resolveSellerProfileId(Request $request): int
    {
        $userCtx = $request->getParam('_user');
        if (!is_array($userCtx) || empty($userCtx['user_id'])) {
            throw new HttpException('Invalid authentication context', 401);
        }

        // In production, look up seller_profiles.id via organization_members
        // For now, expect seller_profile_id in the JWT payload
        $sellerId = $userCtx['seller_profile_id'] ?? null;
        if ($sellerId === null) {
            throw new HttpException('Seller profile not found for this user', 403);
        }

        return (int) $sellerId;
    }
}
