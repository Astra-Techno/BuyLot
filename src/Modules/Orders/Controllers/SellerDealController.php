<?php

declare(strict_types=1);

namespace App\Modules\Orders\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Orders\Services\SellerDealService;

/**
 * SellerDealController -- Seller-facing deal management endpoints.
 *
 *   POST /v1/seller/deals       -> create()   (create deal)
 *   PUT  /v1/seller/deals/{id}  -> update()   (edit draft deal)
 *   GET  /v1/seller/deals       -> listMine() (seller's deals)
 *   GET  /v1/seller/deals/{id}  -> show()     (deal detail)
 */
readonly class SellerDealController
{
    public function __construct(
        private SellerDealService $dealService,
    ) {}

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /**
     * POST /v1/seller/deals
     *
     * Body: {
     *   "variant_id": 123,
     *   "tiers": [{"min_qty": 100, "unit_price_paise": 50000}, ...],
     *   "deadline": "2027-01-15T23:59:59Z",
     *   "terms": { ... }
     * }
     */
    public function create(Request $request): Response
    {
        $sellerId  = $this->resolveSellerId($request);
        $variantId = (int) ($request->getBody('variant_id') ?? 0);
        $tiers     = $request->getBody('tiers') ?? [];
        $deadline  = (string) ($request->getBody('deadline') ?? '');
        $terms     = $request->getBody('terms') ?? [];

        if ($variantId <= 0) {
            return Response::badRequest('variant_id is required');
        }

        if (empty($deadline)) {
            return Response::badRequest('deadline is required');
        }

        if (!is_array($tiers) || empty($tiers)) {
            return Response::badRequest('At least one price tier is required');
        }

        $deal = $this->dealService->createDeal($sellerId, $variantId, $tiers, $deadline, is_array($terms) ? $terms : []);

        return Response::created($deal);
    }

    /**
     * PUT /v1/seller/deals/{id}
     *
     * Body: { "tiers": [...], "deadline": "...", "terms": {...} }
     * Only editable if no buyers have accepted yet.
     */
    public function update(Request $request): Response
    {
        $sellerId = $this->resolveSellerId($request);
        $dealId   = $this->resolveDealId($request);

        $updates = array_filter([
            'tiers'    => $request->getBody('tiers'),
            'deadline' => $request->getBody('deadline'),
            'terms'    => $request->getBody('terms'),
        ], static fn($v) => $v !== null);

        if (empty($updates)) {
            return Response::badRequest('No updates provided');
        }

        $deal = $this->dealService->updateDeal($dealId, $sellerId, $updates);

        return Response::json($deal);
    }

    /**
     * GET /v1/seller/deals
     *
     * Query params: page, per_page
     */
    public function listMine(Request $request): Response
    {
        $sellerId = $this->resolveSellerId($request);
        $page     = max(1, (int) $request->getQuery('page', 1));
        $perPage  = min(100, max(1, (int) $request->getQuery('per_page', 20)));

        $result = $this->dealService->listBySeller($sellerId, $page, $perPage);

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
     * GET /v1/seller/deals/{id}
     */
    public function show(Request $request): Response
    {
        $sellerId = $this->resolveSellerId($request);
        $dealId   = $this->resolveDealId($request);

        $deal = $this->dealService->getDeal($dealId);

        if ($deal === null) {
            return Response::notFound('Deal not found');
        }

        // Ownership check
        if ((int) $deal['seller_id'] !== $sellerId) {
            return Response::notFound('Deal not found');
        }

        return Response::json($deal);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve seller_profiles.id from the authenticated user context.
     *
     * The seller role middleware ensures the user has seller permissions.
     * The seller_id is expected in the JWT payload or resolved via organization membership.
     */
    private function resolveSellerId(Request $request): int
    {
        $userCtx = $request->getParam('_user');

        if (is_string($userCtx)) {
            $decoded = json_decode($userCtx, true);
            // seller_id should be set in the JWT for seller users
            $sellerId = (int) ($decoded['seller_id'] ?? 0);
            if ($sellerId > 0) {
                return $sellerId;
            }
            throw new HttpException('Seller profile not found in authentication context', 403);
        }

        throw new HttpException('Invalid authentication context', 401);
    }

    private function resolveDealId(Request $request): int
    {
        $id = $request->getParam('id');
        if (empty($id)) {
            throw new HttpException('Deal ID is required', 400);
        }
        return (int) $id;
    }
}
