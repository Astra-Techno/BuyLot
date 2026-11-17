<?php

declare(strict_types=1);

namespace App\Modules\Offers\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Offers\Services\AcceptanceService;
use App\Modules\Offers\Services\OfferService;

/**
 * OfferController — buyer-facing endpoints.
 *
 * GET  /v1/offers/{id}        → show()
 * POST /v1/offers/{id}/accept → accept()
 * GET  /v1/me/offers          → listAvailable()
 *
 * Seller identity is redacted from buyer responses per spec.
 */
readonly class OfferController
{
    public function __construct(
        private OfferService      $offerService,
        private AcceptanceService $acceptanceService,
    ) {}

    /**
     * GET /v1/offers/{id}
     *
     * Show offer detail with tiers, terms, and expiry.
     * Redacts seller_id from buyer-facing response.
     */
    public function show(Request $request): Response
    {
        $offerId = $this->resolveIntParam($request, 'id');

        $offer = $this->offerService->getFullDetail($offerId);

        // Only show published offers to buyers
        if (!in_array($offer['status'], ['published'], true)) {
            throw new HttpException('Offer not found', 404);
        }

        return Response::json($this->redactForBuyer($offer));
    }

    /**
     * POST /v1/offers/{id}/accept
     *
     * Accept an offer with quantity and terms hash verification.
     *
     * Body: {
     *   "qty": 5,
     *   "terms_hash": "sha256hex...",
     *   "max_qty": 100  (optional, available stock from offer)
     * }
     */
    public function accept(Request $request): Response
    {
        $buyerId = $this->resolveBuyerId($request);
        $offerId = $this->resolveIntParam($request, 'id');

        $qty       = (int) ($request->getBody('qty') ?? 0);
        $termsHash = (string) ($request->getBody('terms_hash') ?? '');
        $maxQty    = $request->getBody('max_qty') !== null ? (int) $request->getBody('max_qty') : null;

        if ($qty < 1) {
            throw new ValidationException(['qty' => 'Quantity must be at least 1']);
        }

        if ($termsHash === '') {
            throw new ValidationException(['terms_hash' => 'Terms hash is required for acceptance']);
        }

        $result = $this->acceptanceService->acceptOffer($buyerId, $offerId, $qty, $termsHash, $maxQty);

        return Response::created($result);
    }

    /**
     * GET /v1/me/offers
     *
     * List available published offers for the authenticated buyer.
     */
    public function listAvailable(Request $request): Response
    {
        $page    = (int) ($request->getQuery('page') ?? 1);
        $perPage = (int) ($request->getQuery('per_page') ?? 20);

        $result = $this->offerService->listPublishedOffers($page, $perPage);

        // Redact seller identity from each offer
        $result['data'] = array_map(
            fn(array $offer) => $this->redactForBuyer($offer),
            $result['data'],
        );

        return Response::json($result);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveBuyerId(Request $request): int
    {
        $userCtx = $request->getParam('_user');

        if (!is_array($userCtx) || empty($userCtx['user_id'])) {
            throw new HttpException('Invalid authentication context', 401);
        }

        return (int) $userCtx['user_id'];
    }

    private function resolveIntParam(Request $request, string $key): int
    {
        $value = $request->getParam($key);

        if ($value === null || !ctype_digit((string) $value)) {
            throw new HttpException("Missing or invalid parameter: {$key}", 400);
        }

        return (int) $value;
    }

    /**
     * Remove seller identity and internal fields from buyer-facing response.
     */
    private function redactForBuyer(array $offer): array
    {
        unset(
            $offer['seller_id'],
            $offer['source_bid_revision_id'],
            $offer['created_by'],
            $offer['version'],
            $offer['approvals'],
        );

        return $offer;
    }
}
