<?php

declare(strict_types=1);

namespace App\Modules\Offers\Controllers;

use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Offers\Models\NegotiatedOffer;
use App\Modules\Offers\Models\OfferAcceptance;
use App\Modules\Offers\Models\OfferPriceTier;
use App\Modules\Offers\Models\StockReservation;
use App\Modules\Offers\Services\AcceptanceService;
use App\Modules\Offers\Services\OfferService;
use App\Modules\Offers\Services\StockReservationService;

/**
 * OfferAdminController — internal procurement endpoints.
 *
 * POST   /v1/internal/offers                     → create()
 * PUT    /v1/internal/offers/{id}                 → update()
 * POST   /v1/internal/offers/{id}/submit          → submitForApproval()
 * POST   /v1/internal/offers/{id}/approve         → approve()
 * POST   /v1/internal/offers/{id}/publish         → publish()
 * GET    /v1/internal/offers/{id}/monitor          → monitor()
 * DELETE /v1/internal/offers/{id}                  → cancel()
 */
readonly class OfferAdminController
{
    public function __construct(
        private Connection              $connection,
        private OfferService            $offerService,
        private AcceptanceService       $acceptanceService,
        private StockReservationService $stockService,
        private OfferAcceptance         $acceptanceModel,
        private StockReservation        $reservationModel,
    ) {}

    /**
     * POST /v1/internal/offers
     *
     * Compose a new offer from a bid.
     *
     * Body: {
     *   "group_id": 1,
     *   "seller_id": 2,
     *   "source_bid_revision_id": 3,   (optional)
     *   "expires_at": "2026-12-01 23:59:59",
     *   "terms": { ... },
     *   "tiers": [
     *     { "min_paid_qty": 1, "max_paid_qty": 49, "unit_price_paise": 150000 },
     *     { "min_paid_qty": 50, "unit_price_paise": 140000 }
     *   ]
     * }
     */
    public function create(Request $request): Response
    {
        $userId = $this->resolveUserId($request);
        $body   = $request->getBody() ?? [];

        $this->validateCreatePayload($body);

        $offer = $this->offerService->createOffer(
            groupId:        (int) $body['group_id'],
            sellerId:       (int) $body['seller_id'],
            bidRevisionId:  isset($body['source_bid_revision_id']) ? (int) $body['source_bid_revision_id'] : null,
            tiers:          $body['tiers'],
            terms:          $body['terms'],
            createdBy:      $userId,
            expiresAt:      $body['expires_at'],
        );

        return Response::created($offer);
    }

    /**
     * PUT /v1/internal/offers/{id}
     *
     * Edit a draft offer only.
     *
     * Body (all optional): {
     *   "expires_at": "...",
     *   "terms": { ... },
     *   "tiers": [ ... ]
     * }
     */
    public function update(Request $request): Response
    {
        $offerId = $this->resolveIntParam($request, 'id');
        $body    = $request->getBody() ?? [];

        $offer = $this->offerService->getOrFail($offerId);

        if ($offer['status'] !== 'draft') {
            throw new HttpException('Only draft offers can be edited', 409);
        }

        $updateData = [];

        if (isset($body['expires_at'])) {
            $updateData['expires_at'] = $body['expires_at'];
        }

        if (isset($body['terms'])) {
            $updateData['terms_snapshot_json'] = json_encode(
                $body['terms'],
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        }

        if (!empty($updateData)) {
            $offerModel = new NegotiatedOffer($this->connection);
            $updated    = $offerModel->updateDraft($offerId, $updateData);

            if (!$updated) {
                throw new HttpException('Failed to update offer', 500);
            }
        }

        // Replace tiers if provided
        if (isset($body['tiers'])) {
            $tierModel = new OfferPriceTier($this->connection);
            $tierModel->replaceForOffer($offerId, $body['tiers']);
        }

        return Response::json($this->offerService->getFullDetail($offerId));
    }

    /**
     * POST /v1/internal/offers/{id}/submit
     *
     * Submit a draft offer for approval review.
     */
    public function submitForApproval(Request $request): Response
    {
        $offerId = $this->resolveIntParam($request, 'id');
        $offer   = $this->offerService->submitForApproval($offerId);

        return Response::json($offer);
    }

    /**
     * POST /v1/internal/offers/{id}/approve
     *
     * Maker-checker approval/rejection.
     *
     * Body: {
     *   "decision": "approved" | "rejected",
     *   "reason": "..."  (required for rejection)
     * }
     */
    public function approve(Request $request): Response
    {
        $offerId    = $this->resolveIntParam($request, 'id');
        $approverId = $this->resolveUserId($request);

        $decision = (string) ($request->getBody('decision') ?? '');
        $reason   = $request->getBody('reason');

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new ValidationException(['decision' => 'Must be "approved" or "rejected"']);
        }

        if ($decision === 'rejected' && empty($reason)) {
            throw new ValidationException(['reason' => 'Reason is required for rejection']);
        }

        $offer = $this->offerService->approveOffer($offerId, $approverId, $decision, $reason);

        return Response::json($offer);
    }

    /**
     * POST /v1/internal/offers/{id}/publish
     *
     * Publish an approved offer to buyers.
     *
     * Body (optional): {
     *   "expires_at": "2026-12-01 23:59:59"
     * }
     */
    public function publish(Request $request): Response
    {
        $offerId   = $this->resolveIntParam($request, 'id');
        $expiresAt = $request->getBody('expires_at');

        $offer = $this->offerService->publishOffer($offerId, $expiresAt);

        return Response::json($offer);
    }

    /**
     * GET /v1/internal/offers/{id}/monitor
     *
     * View acceptance and reservation statistics.
     */
    public function monitor(Request $request): Response
    {
        $offerId = $this->resolveIntParam($request, 'id');
        $offer   = $this->offerService->getFullDetail($offerId);

        $acceptanceStats = $this->acceptanceService->getAcceptanceStats($offerId);
        $acceptances     = $this->acceptanceModel->findByOfferId($offerId);

        $activeReserved = $this->reservationModel->totalActiveReservedQty($offerId);
        $convertedQty   = $this->reservationModel->totalConvertedQty($offerId);

        return Response::json([
            'offer'       => $offer,
            'stats'       => [
                'total_accepted_qty'   => $acceptanceStats['total_accepted_qty'],
                'acceptance_count'     => $acceptanceStats['acceptance_count'],
                'active_reserved_qty'  => $activeReserved,
                'converted_qty'        => $convertedQty,
            ],
            'acceptances' => $acceptances,
        ]);
    }

    /**
     * DELETE /v1/internal/offers/{id}
     *
     * Cancel an offer.
     */
    public function cancel(Request $request): Response
    {
        $offerId = $this->resolveIntParam($request, 'id');
        $offer   = $this->offerService->cancelOffer($offerId);

        return Response::json($offer);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveUserId(Request $request): int
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

    private function validateCreatePayload(array $body): void
    {
        $errors = [];

        if (empty($body['group_id'])) {
            $errors['group_id'] = 'Group ID is required';
        }
        if (empty($body['seller_id'])) {
            $errors['seller_id'] = 'Seller ID is required';
        }
        if (empty($body['expires_at'])) {
            $errors['expires_at'] = 'Expiry date is required';
        }
        if (empty($body['terms']) || !is_array($body['terms'])) {
            $errors['terms'] = 'Terms object is required';
        }
        if (empty($body['tiers']) || !is_array($body['tiers'])) {
            $errors['tiers'] = 'At least one price tier is required';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

}
