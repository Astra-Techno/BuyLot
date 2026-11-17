<?php

declare(strict_types=1);

namespace App\Modules\Offers\Services;

use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Modules\Offers\Models\NegotiatedOffer;
use App\Modules\Offers\Models\OfferApproval;
use App\Modules\Offers\Models\OfferPriceTier;

/**
 * OfferService — core offer lifecycle logic.
 *
 * Handles creation, approval workflow (maker-checker), publishing, and tier pricing.
 * terms_snapshot_json is IMMUTABLE after publish — no silent repricing.
 */
class OfferService
{
    public function __construct(
        private Connection       $connection,
        private NegotiatedOffer  $offerModel,
        private OfferPriceTier   $tierModel,
        private OfferApproval    $approvalModel,
    ) {}

    // -------------------------------------------------------------------------
    // Offer lifecycle
    // -------------------------------------------------------------------------

    /**
     * Create a new draft offer with price tiers and terms snapshot.
     *
     * @param int    $groupId          Demand group ID
     * @param int    $sellerId         Seller profile ID
     * @param int|null $bidRevisionId  Source bid revision (NULL for offline-sourced)
     * @param array  $tiers            Array of tier definitions [{min_paid_qty, max_paid_qty?, unit_price_paise}]
     * @param array  $terms            Terms data to snapshot as JSON
     * @param int    $createdBy        User ID of the creator (procurement agent)
     * @param string $expiresAt        Expiry timestamp (Y-m-d H:i:s)
     * @return array The created offer with tiers
     */
    public function createOffer(
        int     $groupId,
        int     $sellerId,
        ?int    $bidRevisionId,
        array   $tiers,
        array   $terms,
        int     $createdBy,
        string  $expiresAt,
    ): array {
        $this->validateTiers($tiers);

        $termsJson = json_encode($terms, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $this->connection->beginTransaction();

        try {
            $offerId = $this->offerModel->create([
                'group_id'               => $groupId,
                'seller_id'              => $sellerId,
                'source_bid_revision_id' => $bidRevisionId,
                'expires_at'             => $expiresAt,
                'terms_snapshot_json'    => $termsJson,
                'created_by'             => $createdBy,
            ]);

            foreach ($tiers as $tier) {
                $this->tierModel->create([
                    'offer_id'         => $offerId,
                    'min_paid_qty'     => $tier['min_paid_qty'],
                    'max_paid_qty'     => $tier['max_paid_qty'] ?? null,
                    'unit_price_paise' => $tier['unit_price_paise'],
                ]);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $offer = $this->offerModel->find($offerId);
        $offer['tiers'] = $this->tierModel->findByOfferId($offerId);

        return $offer;
    }

    /**
     * Submit a DRAFT offer for approval.
     * Transition: DRAFT -> REVIEW_PENDING (pending_approval)
     */
    public function submitForApproval(int $offerId): array
    {
        $offer = $this->getOrFail($offerId);

        if ($offer['status'] !== 'draft') {
            throw new HttpException('Offer must be in draft status to submit for approval', 409);
        }

        // Validate that price tiers exist
        $tiers = $this->tierModel->findByOfferId($offerId);
        if (empty($tiers)) {
            throw new ValidationException(['tiers' => 'At least one price tier is required']);
        }

        $transitioned = $this->offerModel->transition(
            $offerId,
            'draft',
            'pending_approval',
            (int) $offer['version'],
        );

        if (!$transitioned) {
            throw new HttpException('Failed to submit offer — concurrent modification detected', 409);
        }

        return $this->getOrFail($offerId);
    }

    /**
     * Approve or reject an offer (maker-checker enforcement).
     * Transition: REVIEW_PENDING -> APPROVED or REVIEW_PENDING -> DRAFT (rejected)
     *
     * @param string $decision 'approved' or 'rejected'
     */
    public function approveOffer(int $offerId, int $approverId, string $decision, ?string $reason = null): array
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new ValidationException(['decision' => 'Decision must be approved or rejected']);
        }

        $offer = $this->getOrFail($offerId);

        if ($offer['status'] !== 'pending_approval') {
            throw new HttpException('Offer must be in pending_approval status', 409);
        }

        // Maker-checker: approver must not be the offer creator
        if ((int) $offer['created_by'] === $approverId) {
            throw new HttpException('Maker-checker violation: approver cannot be the offer creator', 403);
        }

        $this->connection->beginTransaction();

        try {
            // Create approval record
            $approvalId = $this->approvalModel->create($offerId, $approverId);
            $this->approvalModel->recordDecision($approvalId, $decision, $reason);

            // Determine target status
            $toStatus = $decision === 'approved' ? 'approved' : 'draft';

            $transitioned = $this->offerModel->transition(
                $offerId,
                'pending_approval',
                $toStatus,
                (int) $offer['version'],
            );

            if (!$transitioned) {
                throw new HttpException('Failed to process approval — concurrent modification detected', 409);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $result = $this->getOrFail($offerId);
        $result['approvals'] = $this->approvalModel->findByOfferId($offerId);

        return $result;
    }

    /**
     * Publish an approved offer to buyers.
     * Transition: APPROVED -> PUBLISHED
     * After this point, terms_snapshot_json is IMMUTABLE.
     */
    public function publishOffer(int $offerId, ?string $expiresAt = null): array
    {
        $offer = $this->getOrFail($offerId);

        if ($offer['status'] !== 'approved') {
            throw new HttpException('Offer must be in approved status to publish', 409);
        }

        $extraFields = [];
        if ($expiresAt !== null) {
            $extraFields['expires_at'] = $expiresAt;
        }

        $transitioned = $this->offerModel->transition(
            $offerId,
            'approved',
            'published',
            (int) $offer['version'],
            $extraFields,
        );

        if (!$transitioned) {
            throw new HttpException('Failed to publish offer — concurrent modification detected', 409);
        }

        return $this->getOrFail($offerId);
    }

    /**
     * Calculate the applicable unit price for a given quantity on an offer.
     *
     * @return int Unit price in paise
     */
    public function calculateTierPrice(int $offerId, int $qty): int
    {
        $tier = $this->tierModel->findTierForQty($offerId, $qty);

        if ($tier === null) {
            throw new HttpException('No applicable price tier found for the given quantity', 422);
        }

        return (int) $tier['unit_price_paise'];
    }

    /**
     * Cancel an offer.
     * Allowed from: draft, pending_approval, approved, published
     */
    public function cancelOffer(int $offerId): array
    {
        $offer = $this->getOrFail($offerId);

        $cancellableStatuses = ['draft', 'pending_approval', 'approved', 'published'];
        if (!in_array($offer['status'], $cancellableStatuses, true)) {
            throw new HttpException(
                'Offer cannot be cancelled from status: ' . $offer['status'],
                409,
            );
        }

        $transitioned = $this->offerModel->transition(
            $offerId,
            $offer['status'],
            'cancelled',
            (int) $offer['version'],
        );

        if (!$transitioned) {
            throw new HttpException('Failed to cancel offer — concurrent modification detected', 409);
        }

        return $this->getOrFail($offerId);
    }

    /**
     * List published offers available to buyers (paginated).
     */
    public function listPublishedOffers(int $page = 1, int $perPage = 20): array
    {
        return $this->offerModel->listAvailable($page, $perPage);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Retrieve an offer or throw 404.
     */
    public function getOrFail(int $offerId): array
    {
        $offer = $this->offerModel->find($offerId);

        if ($offer === null) {
            throw new HttpException('Offer not found', 404);
        }

        return $offer;
    }

    /**
     * Get full offer detail with tiers and approvals.
     */
    public function getFullDetail(int $offerId): array
    {
        $offer = $this->getOrFail($offerId);
        $offer['tiers']     = $this->tierModel->findByOfferId($offerId);
        $offer['approvals'] = $this->approvalModel->findByOfferId($offerId);

        return $offer;
    }

    /**
     * Compute the SHA-256 hash of an offer's terms snapshot.
     */
    public function computeTermsHash(array $offer): string
    {
        return hash('sha256', (string) $offer['terms_snapshot_json']);
    }

    /**
     * Validate tier definitions.
     */
    private function validateTiers(array $tiers): void
    {
        if (empty($tiers)) {
            throw new ValidationException(['tiers' => 'At least one price tier is required']);
        }

        foreach ($tiers as $i => $tier) {
            if (empty($tier['min_paid_qty']) || (int) $tier['min_paid_qty'] < 1) {
                throw new ValidationException(["tiers.{$i}.min_paid_qty" => 'Must be a positive integer']);
            }

            if (!isset($tier['unit_price_paise']) || (int) $tier['unit_price_paise'] < 0) {
                throw new ValidationException(["tiers.{$i}.unit_price_paise" => 'Must be a non-negative integer (paise)']);
            }

            if (isset($tier['max_paid_qty']) && (int) $tier['max_paid_qty'] < (int) $tier['min_paid_qty']) {
                throw new ValidationException(["tiers.{$i}.max_paid_qty" => 'Must be >= min_paid_qty']);
            }
        }
    }
}
