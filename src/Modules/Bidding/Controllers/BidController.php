<?php

declare(strict_types=1);

namespace App\Modules\Bidding\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Bidding\Models\SellerBid;
use App\Modules\Bidding\Models\SellerBidRevision;
use App\Modules\Bidding\Services\BidService;

/**
 * BidController -- Seller-facing bid endpoints.
 *
 * SEALED BID CONFIDENTIALITY IS NON-NEGOTIABLE.
 * EVERY method enforces: seller_id = authenticated_user.seller_id
 * A seller NEVER sees another seller's bids, pricing, or ranking.
 *
 *   POST   /v1/seller/rfqs/{rfqId}/bids       -> submitBid()
 *   POST   /v1/seller/bids/{bidId}/revisions   -> reviseBid()
 *   GET    /v1/seller/rfqs/{rfqId}/my-bid      -> showMyBid()
 *   GET    /v1/seller/bids                      -> listMyBids()
 *   DELETE /v1/seller/bids/{bidId}              -> withdrawBid()
 */
readonly class BidController
{
    public function __construct(
        private BidService        $bidService,
        private SellerBid         $bidModel,
        private SellerBidRevision $revisionModel,
    ) {}

    /**
     * POST /v1/seller/rfqs/{rfqId}/bids
     *
     * Submit a new bid for an RFQ. Creates both a seller_bids row and the
     * first seller_bid_revisions row atomically.
     *
     * Requirements:
     *   - Seller must be verified
     *   - RFQ must be published (open for bidding)
     *   - Seller must be invited to this RFQ
     *   - No existing bid for this RFQ+seller (UNIQUE constraint)
     *   - Bid deadline must not have passed (DB server time)
     *
     * Body: {
     *   "unit_price_paise": int,
     *   "tax_basis": "GST_18_PERCENT",
     *   "freight_paise": int,
     *   "moq": int,
     *   "max_qty": int|null,
     *   "valid_until": "YYYY-MM-DD HH:MM:SS",
     *   "lead_time_days": int,
     *   "warranty_snapshot": object|null,
     *   "evidence_ref": string|null
     * }
     */
    public function submitBid(Request $request): Response
    {
        $sellerId = $this->resolveSellerIdOrFail($request);
        $rfqId    = (int) $request->getParam('rfqId');

        if ($rfqId <= 0) {
            return Response::badRequest('Invalid RFQ ID');
        }

        $body   = $request->getBody() ?? [];
        $errors = $this->validateRevisionPayload($body);

        if (!empty($errors)) {
            return Response::unprocessable($errors);
        }

        try {
            $result = $this->bidService->submitBid($rfqId, $sellerId, $body);
        } catch (\App\Core\Exception\ValidationException $e) {
            return Response::unprocessable($e->getErrors());
        } catch (\App\Core\Exception\HttpException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getCode());
        }

        return Response::created([
            'bid_id'      => $result['bid_id'],
            'revision_id' => $result['revision_id'],
            'revision_no' => 1,
            'status'      => SellerBid::STATUS_ACTIVE,
        ]);
    }

    /**
     * POST /v1/seller/bids/{bidId}/revisions
     *
     * Submit a revised bid. Creates a new immutable seller_bid_revisions row.
     * Previous revisions are never modified or deleted.
     *
     * Requirements:
     *   - Seller must own this bid (seller_id check)
     *   - Bid must be active
     *   - RFQ bid deadline must not have passed (DB server time)
     */
    public function reviseBid(Request $request): Response
    {
        $sellerId = $this->resolveSellerIdOrFail($request);
        $bidId    = (int) $request->getParam('bidId');

        if ($bidId <= 0) {
            return Response::badRequest('Invalid bid ID');
        }

        $body   = $request->getBody() ?? [];
        $errors = $this->validateRevisionPayload($body);

        if (!empty($errors)) {
            return Response::unprocessable($errors);
        }

        try {
            $result = $this->bidService->reviseBid($bidId, $sellerId, $body);
        } catch (\App\Core\Exception\ValidationException $e) {
            return Response::unprocessable($e->getErrors());
        } catch (\App\Core\Exception\HttpException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getCode());
        }

        return Response::created([
            'bid_id'      => $bidId,
            'revision_id' => $result['revision_id'],
            'revision_no' => $result['revision_no'],
        ]);
    }

    /**
     * GET /v1/seller/rfqs/{rfqId}/my-bid
     *
     * Show the seller's own bid and all revisions for a specific RFQ.
     * SECURITY: seller_id = authenticated seller. Never returns other sellers' data.
     */
    public function showMyBid(Request $request): Response
    {
        $sellerId = $this->resolveSellerIdOrFail($request);
        $rfqId    = (int) $request->getParam('rfqId');

        if ($rfqId <= 0) {
            return Response::badRequest('Invalid RFQ ID');
        }

        // SECURITY: seller_id filter mandatory
        $bid = $this->bidModel->findByRfqAndSeller($rfqId, $sellerId);

        if ($bid === null) {
            return Response::notFound('No bid found for this RFQ');
        }

        // Get all revisions for this bid (seller-safe columns)
        $revisions = $this->revisionModel->listByBid((int) $bid['id']);

        return Response::json([
            'bid'       => $bid,
            'revisions' => $revisions,
        ]);
    }

    /**
     * GET /v1/seller/bids
     *
     * List all bids for the authenticated seller.
     * SECURITY: seller_id filter mandatory. Seller sees only own bids.
     */
    public function listMyBids(Request $request): Response
    {
        $sellerId = $this->resolveSellerIdOrFail($request);

        $page    = max(1, (int) ($request->getQuery('page', 1)));
        $perPage = min(100, max(1, (int) ($request->getQuery('per_page', 20))));

        $result = $this->bidModel->listBySeller($sellerId, $page, $perPage);

        return Response::json($result);
    }

    /**
     * DELETE /v1/seller/bids/{bidId}
     *
     * Withdraw the seller's own bid. Soft-delete: status -> withdrawn.
     * SECURITY: seller_id = authenticated seller. Cannot withdraw others' bids.
     */
    public function withdrawBid(Request $request): Response
    {
        $sellerId = $this->resolveSellerIdOrFail($request);
        $bidId    = (int) $request->getParam('bidId');

        if ($bidId <= 0) {
            return Response::badRequest('Invalid bid ID');
        }

        try {
            $this->bidService->withdrawBid($bidId, $sellerId);
        } catch (\App\Core\Exception\HttpException $e) {
            return Response::json(['error' => $e->getMessage()], $e->getCode());
        }

        return Response::json(['message' => 'Bid withdrawn', 'bid_id' => $bidId]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extract seller_id from the authenticated user context.
     * SECURITY: This is the SOLE source of seller identity for all bid operations.
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

    /**
     * Validate the revision payload fields.
     */
    private function validateRevisionPayload(array $body): array
    {
        $errors = [];

        if (!isset($body['unit_price_paise']) || !is_numeric($body['unit_price_paise']) || (int) $body['unit_price_paise'] < 0) {
            $errors['unit_price_paise'] = 'Unit price (paise) is required and must be >= 0';
        }

        if (empty($body['tax_basis']) || !is_string($body['tax_basis'])) {
            $errors['tax_basis'] = 'Tax basis is required (e.g. GST_18_PERCENT, EXEMPT)';
        }

        if (isset($body['freight_paise']) && (!is_numeric($body['freight_paise']) || (int) $body['freight_paise'] < 0)) {
            $errors['freight_paise'] = 'Freight (paise) must be >= 0';
        }

        if (!isset($body['moq']) || !is_numeric($body['moq']) || (int) $body['moq'] <= 0) {
            $errors['moq'] = 'Minimum order quantity is required and must be > 0';
        }

        if (isset($body['max_qty']) && $body['max_qty'] !== null) {
            if (!is_numeric($body['max_qty']) || (int) $body['max_qty'] < (int) ($body['moq'] ?? 0)) {
                $errors['max_qty'] = 'Max quantity must be >= MOQ';
            }
        }

        if (empty($body['valid_until'])) {
            $errors['valid_until'] = 'Bid validity date is required';
        } elseif (strtotime($body['valid_until']) === false) {
            $errors['valid_until'] = 'Invalid date format for valid_until';
        }

        if (!isset($body['lead_time_days']) || !is_numeric($body['lead_time_days']) || (int) $body['lead_time_days'] <= 0) {
            $errors['lead_time_days'] = 'Lead time (days) is required and must be > 0';
        }

        return $errors;
    }
}
