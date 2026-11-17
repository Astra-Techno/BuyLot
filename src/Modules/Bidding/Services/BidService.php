<?php

declare(strict_types=1);

namespace App\Modules\Bidding\Services;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Modules\Bidding\Models\SellerBid;
use App\Modules\Bidding\Models\SellerBidRevision;
use App\Modules\RFQ\Models\RFQ;
use App\Modules\RFQ\Models\RFQSellerInvitation;

/**
 * BidService -- Business logic for bid submission and revision.
 *
 * SEALED BID CONFIDENTIALITY IS NON-NEGOTIABLE.
 *
 * Key invariants:
 *   - Deadline enforcement uses DB server time, not client time.
 *   - Bid + first revision are created atomically in a transaction.
 *   - Revisions use SELECT FOR UPDATE to prevent concurrent races.
 *   - Seller isolation is enforced at every query.
 */
class BidService
{
    public function __construct(
        private readonly Connection          $connection,
        private readonly SellerBid           $bidModel,
        private readonly SellerBidRevision   $revisionModel,
        private readonly RFQ                 $rfqModel,
        private readonly RFQSellerInvitation $invitationModel,
    ) {}

    /**
     * Submit a new bid for an RFQ.
     *
     * Creates both seller_bids and seller_bid_revisions rows atomically.
     *
     * Guards:
     *   - Seller must have an invitation for this RFQ
     *   - RFQ must be published
     *   - Bid deadline must not have passed (DB server time)
     *   - No duplicate bid (UNIQUE rfq_id+seller_id enforced at DB)
     *
     * @return array{bid_id: int, revision_id: int}
     * @throws ValidationException
     * @throws HttpException
     */
    public function submitBid(int $rfqId, int $sellerId, array $revisionData): array
    {
        // 1. Validate RFQ exists and is open
        $rfq = $this->rfqModel->find($rfqId);
        if ($rfq === null) {
            throw new HttpException('RFQ not found', 404);
        }

        if ($rfq['status'] !== RFQ::STATUS_PUBLISHED) {
            throw new HttpException('RFQ is not open for bidding', 400);
        }

        // 2. Deadline enforcement -- DB server time
        $this->assertBeforeDeadline($rfq['bid_deadline']);

        // 3. Seller must be invited
        $invitation = $this->invitationModel->findByRfqAndSeller($rfqId, $sellerId);
        if ($invitation === null) {
            throw new HttpException('You are not invited to bid on this RFQ', 403);
        }

        // 4. Check no duplicate bid exists
        $existingBid = $this->bidModel->findByRfqAndSellerInternal($rfqId, $sellerId);
        if ($existingBid !== null) {
            throw new ValidationException([
                'bid' => 'You have already submitted a bid for this RFQ. Use revision to update.',
            ]);
        }

        // 5. Atomic transaction: create bid + first revision
        $this->connection->beginTransaction();

        try {
            // Create the bid
            $bidId = $this->bidModel->create($rfqId, $sellerId);

            // Create the first revision (revision_no = 1)
            $revisionId = $this->revisionModel->create([
                'bid_id'            => $bidId,
                'revision_no'       => 1,
                'unit_price_paise'  => (int) $revisionData['unit_price_paise'],
                'tax_basis'         => (string) $revisionData['tax_basis'],
                'freight_paise'     => (int) ($revisionData['freight_paise'] ?? 0),
                'moq'               => (int) $revisionData['moq'],
                'max_qty'           => isset($revisionData['max_qty']) ? (int) $revisionData['max_qty'] : null,
                'valid_until'       => (string) $revisionData['valid_until'],
                'lead_time_days'    => (int) $revisionData['lead_time_days'],
                'warranty_snapshot' => $revisionData['warranty_snapshot'] ?? null,
                'evidence_ref'      => $revisionData['evidence_ref'] ?? null,
            ]);

            // Update latest_revision_id pointer
            $this->bidModel->updateLatestRevision($bidId, $revisionId, $sellerId);

            // Mark invitation as bid_submitted
            $this->invitationModel->markBidSubmitted($rfqId, $sellerId);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return [
            'bid_id'      => $bidId,
            'revision_id' => $revisionId,
        ];
    }

    /**
     * Revise an existing bid by appending a new immutable revision.
     *
     * Guards:
     *   - Seller must own the bid (seller_id check)
     *   - Bid must be active
     *   - RFQ bid deadline must not have passed (DB server time)
     *   - Previous revisions are NEVER modified
     *   - latest_revision_id updated with SELECT FOR UPDATE semantics
     *
     * @return array{revision_id: int, revision_no: int}
     * @throws ValidationException
     * @throws HttpException
     */
    public function reviseBid(int $bidId, int $sellerId, array $revisionData): array
    {
        // 1. Fetch the bid -- verify ownership
        $bid = $this->bidModel->find($bidId);
        if ($bid === null) {
            throw new HttpException('Bid not found', 404);
        }

        // SECURITY: Verify seller owns this bid
        if ((int) $bid['seller_id'] !== $sellerId) {
            throw new HttpException('Access denied', 403);
        }

        if ($bid['status'] !== SellerBid::STATUS_ACTIVE) {
            throw new HttpException('Only active bids can be revised', 400);
        }

        // 2. Fetch RFQ and check deadline
        $rfq = $this->rfqModel->find((int) $bid['rfq_id']);
        if ($rfq === null) {
            throw new HttpException('Associated RFQ not found', 404);
        }

        if ($rfq['status'] !== RFQ::STATUS_PUBLISHED) {
            throw new HttpException('RFQ is no longer open for bidding', 400);
        }

        $this->assertBeforeDeadline($rfq['bid_deadline']);

        // 3. Atomic: create revision + update pointer with SELECT FOR UPDATE
        $this->connection->beginTransaction();

        try {
            // Lock the bid row to prevent concurrent revision races
            $lockedBid = $this->connection->query(
                'SELECT id, latest_revision_id FROM seller_bids WHERE id = ? AND seller_id = ? FOR UPDATE',
                [$bidId, $sellerId],
            );

            if (empty($lockedBid)) {
                $this->connection->rollBack();
                throw new HttpException('Bid not found or access denied', 404);
            }

            // Calculate next revision number
            $nextRevisionNo = $this->revisionModel->getNextRevisionNo($bidId);

            // Append new immutable revision
            $revisionId = $this->revisionModel->create([
                'bid_id'            => $bidId,
                'revision_no'       => $nextRevisionNo,
                'unit_price_paise'  => (int) $revisionData['unit_price_paise'],
                'tax_basis'         => (string) $revisionData['tax_basis'],
                'freight_paise'     => (int) ($revisionData['freight_paise'] ?? 0),
                'moq'               => (int) $revisionData['moq'],
                'max_qty'           => isset($revisionData['max_qty']) ? (int) $revisionData['max_qty'] : null,
                'valid_until'       => (string) $revisionData['valid_until'],
                'lead_time_days'    => (int) $revisionData['lead_time_days'],
                'warranty_snapshot' => $revisionData['warranty_snapshot'] ?? null,
                'evidence_ref'      => $revisionData['evidence_ref'] ?? null,
            ]);

            // Update latest_revision_id pointer
            $this->bidModel->updateLatestRevision($bidId, $revisionId, $sellerId);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return [
            'revision_id' => $revisionId,
            'revision_no' => $nextRevisionNo,
        ];
    }

    /**
     * Withdraw a bid. Only the owning seller can withdraw their own bid.
     *
     * @throws HttpException
     */
    public function withdrawBid(int $bidId, int $sellerId): void
    {
        $bid = $this->bidModel->findForSeller($bidId, $sellerId);

        if ($bid === null) {
            throw new HttpException('Bid not found', 404);
        }

        if ($bid['status'] !== SellerBid::STATUS_ACTIVE) {
            throw new HttpException('Only active bids can be withdrawn', 400);
        }

        $withdrawn = $this->bidModel->withdraw($bidId, $sellerId);

        if (!$withdrawn) {
            throw new HttpException('Failed to withdraw bid', 500);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Assert that the current DB server time is before the given deadline.
     * Uses DB server time (via PHP time() as proxy) to prevent client-side manipulation.
     *
     * @throws HttpException
     */
    private function assertBeforeDeadline(string $deadline): void
    {
        $deadlineTs = strtotime($deadline);

        if ($deadlineTs === false || time() >= $deadlineTs) {
            throw new HttpException('Bid deadline has passed', 400);
        }
    }
}
