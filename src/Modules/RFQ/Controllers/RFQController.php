<?php

declare(strict_types=1);

namespace App\Modules\RFQ\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\RFQ\Models\RFQ;
use App\Modules\RFQ\Models\RFQSellerInvitation;

/**
 * RFQController -- Seller-facing endpoints.
 *
 * SECURITY: Sellers see ONLY their own eligible RFQs.
 * NEVER expose: other seller info, buyer budgets, competing bids, internal metadata.
 *
 *   GET /v1/seller/rfqs        -> listEligible()
 *   GET /v1/seller/rfqs/{id}   -> show()
 */
readonly class RFQController
{
    public function __construct(
        private RFQ                   $rfqModel,
        private RFQSellerInvitation   $invitationModel,
    ) {}

    /**
     * GET /v1/seller/rfqs
     *
     * List RFQs the authenticated seller is eligible for.
     * Returns seller-safe columns only. No other seller info.
     */
    public function listEligible(Request $request): Response
    {
        $sellerId = $this->resolveSellerIdOrFail($request);

        $page    = max(1, (int) ($request->getQuery('page', 1)));
        $perPage = min(100, max(1, (int) ($request->getQuery('per_page', 20))));

        $result = $this->rfqModel->listEligibleForSeller($sellerId, $page, $perPage);

        return Response::json($result);
    }

    /**
     * GET /v1/seller/rfqs/{id}
     *
     * Show a single RFQ with seller-safe columns.
     * Verifies seller has an invitation for this RFQ.
     * NEVER returns: buyer budgets, competing bids, group_id, created_by.
     */
    public function show(Request $request): Response
    {
        $sellerId = $this->resolveSellerIdOrFail($request);
        $rfqId    = (int) $request->getParam('id');

        if ($rfqId <= 0) {
            return Response::badRequest('Invalid RFQ ID');
        }

        // Verify the seller has an invitation for this RFQ
        $invitation = $this->invitationModel->findByRfqAndSeller($rfqId, $sellerId);

        if ($invitation === null) {
            return Response::notFound('RFQ not found or you are not invited');
        }

        // Fetch seller-safe view only -- no internal metadata
        $rfq = $this->rfqModel->findForSeller($rfqId);

        if ($rfq === null) {
            return Response::notFound('RFQ not found');
        }

        // Mark invitation as viewed if still in 'invited' status
        $this->invitationModel->markViewed($rfqId, $sellerId);

        return Response::json($rfq);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extract seller_id from the authenticated user context.
     * The JWT payload must contain 'seller_id' (set during seller auth flow).
     */
    private function resolveSellerIdOrFail(Request $request): int
    {
        $userCtx = $request->getParam('_user');

        if (!is_array($userCtx) || empty($userCtx['seller_id'])) {
            throw new \App\Core\Exception\HttpException(
                'Seller profile required. Ensure you are authenticated as a verified seller.',
                403,
            );
        }

        return (int) $userCtx['seller_id'];
    }
}
