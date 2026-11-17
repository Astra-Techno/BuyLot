<?php

declare(strict_types=1);

namespace App\Modules\Offers\Services;

use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Modules\Offers\Models\NegotiatedOffer;
use App\Modules\Offers\Models\OfferAcceptance;
use App\Modules\Offers\Models\OfferPriceTier;

/**
 * AcceptanceService — buyer acceptance flow.
 *
 * Validates offer is published + not expired, calculates tier price,
 * verifies terms hash to prevent silent repricing, and reserves stock atomically.
 */
class AcceptanceService
{
    public function __construct(
        private Connection              $connection,
        private NegotiatedOffer         $offerModel,
        private OfferAcceptance         $acceptanceModel,
        private OfferPriceTier          $tierModel,
        private OfferService            $offerService,
        private StockReservationService $stockService,
    ) {}

    /**
     * Accept an offer as a buyer.
     *
     * Flow:
     * 1. Validate offer is published and not expired
     * 2. Check buyer has not already accepted (idempotent via UNIQUE constraint)
     * 3. Compute terms hash and verify against provided hash (no silent repricing)
     * 4. Calculate applicable tier price
     * 5. Reserve stock atomically
     * 6. Create acceptance record
     *
     * @param int    $buyerId   Buyer user ID
     * @param int    $offerId   Offer ID
     * @param int    $qty       Quantity to accept
     * @param string $termsHash SHA-256 hash of the terms the buyer reviewed
     * @param int|null $maxQty  Max available stock for the offer (from offer terms)
     * @return array Acceptance record with tier price and reservation
     */
    public function acceptOffer(int $buyerId, int $offerId, int $qty, string $termsHash, ?int $maxQty = null): array
    {
        if ($qty < 1) {
            throw new ValidationException(['qty' => 'Quantity must be at least 1']);
        }

        // 1. Validate offer state
        $offer = $this->offerService->getOrFail($offerId);

        if (!in_array($offer['status'], ['published'], true)) {
            throw new HttpException('Offer is not available for acceptance (status: ' . $offer['status'] . ')', 409);
        }

        // Check expiry
        $expiresAt = strtotime($offer['expires_at']);
        if ($expiresAt !== false && $expiresAt < time()) {
            throw new HttpException('Offer has expired', 410);
        }

        // 2. Check idempotency — buyer can only accept once per offer
        $existing = $this->acceptanceModel->findByOfferAndBuyer($offerId, $buyerId);
        if ($existing !== null) {
            // Return existing acceptance (idempotent)
            return $this->enrichAcceptance($existing, $offer);
        }

        // 3. Verify terms hash — prevents silent repricing
        $currentHash = $this->offerService->computeTermsHash($offer);
        if (!hash_equals($currentHash, $termsHash)) {
            throw new HttpException(
                'Terms hash mismatch: the offer terms have changed since you last reviewed them. '
                . 'Please review the updated terms and try again.',
                409,
            );
        }

        // 4. Calculate tier price
        $unitPricePaise = $this->offerService->calculateTierPrice($offerId, $qty);

        // 5. Reserve stock atomically
        $reservation = $this->stockService->reserve($offerId, $buyerId, $qty, $maxQty);

        // 6. Create acceptance record
        $this->connection->beginTransaction();

        try {
            $acceptanceId = $this->acceptanceModel->create([
                'offer_id'            => $offerId,
                'buyer_id'            => $buyerId,
                'qty'                 => $qty,
                'accepted_terms_hash' => $termsHash,
            ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            // Release reservation on failure
            try {
                $this->stockService->release((int) $reservation['id']);
            } catch (\Throwable) {
                // Best-effort release; stale reservation will be expired by background job
            }

            throw $e;
        }

        $acceptance = $this->acceptanceModel->find($acceptanceId);

        return [
            'acceptance'       => $acceptance,
            'unit_price_paise' => $unitPricePaise,
            'total_paise'      => $unitPricePaise * $qty,
            'reservation'      => $reservation,
        ];
    }

    /**
     * Get acceptance stats for an offer (for monitoring).
     */
    public function getAcceptanceStats(int $offerId): array
    {
        return [
            'total_accepted_qty' => $this->acceptanceModel->totalAcceptedQty($offerId),
            'acceptance_count'   => $this->acceptanceModel->countAcceptances($offerId),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Enrich an existing acceptance with computed fields.
     */
    private function enrichAcceptance(array $acceptance, array $offer): array
    {
        $unitPricePaise = $this->offerService->calculateTierPrice(
            (int) $acceptance['offer_id'],
            (int) $acceptance['qty'],
        );

        return [
            'acceptance'       => $acceptance,
            'unit_price_paise' => $unitPricePaise,
            'total_paise'      => $unitPricePaise * (int) $acceptance['qty'],
            'reservation'      => null, // Already accepted; reservation may have been converted
        ];
    }
}
