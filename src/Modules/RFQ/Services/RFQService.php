<?php

declare(strict_types=1);

namespace App\Modules\RFQ\Services;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use App\Modules\RFQ\Models\RFQ;
use App\Modules\RFQ\Models\RFQSellerInvitation;

/**
 * RFQService -- Business logic for RFQ lifecycle management.
 *
 * Responsibilities:
 *  - Create RFQ from demand group
 *  - Publish to sellers
 *  - Deadline extension with audit logging
 *  - Seller eligibility checks
 *  - Close bidding
 */
class RFQService
{
    public function __construct(
        private readonly Connection          $connection,
        private readonly RFQ                 $rfqModel,
        private readonly RFQSellerInvitation $invitationModel,
    ) {}

    /**
     * Create an RFQ from a demand group.
     *
     * @return int The new RFQ ID.
     */
    public function createFromGroup(
        int    $groupId,
        string $specSnapshotJson,
        string $qtyBandJson,
        string $bidDeadline,
        int    $createdBy,
    ): int {
        // Validate the deadline is in the future (server time)
        $this->assertDeadlineInFuture($bidDeadline);

        return $this->rfqModel->create([
            'group_id'           => $groupId,
            'spec_snapshot_json' => $specSnapshotJson,
            'qty_band_json'      => $qtyBandJson,
            'bid_deadline'       => $bidDeadline,
            'created_by'         => $createdBy,
        ]);
    }

    /**
     * Update a draft RFQ.
     */
    public function updateDraft(int $rfqId, array $data): bool
    {
        if (isset($data['bid_deadline'])) {
            $this->assertDeadlineInFuture($data['bid_deadline']);
        }

        return $this->rfqModel->update($rfqId, $data);
    }

    /**
     * Publish an RFQ, transitioning it from draft to published.
     * Verifies at least one seller invitation exists.
     */
    public function publish(int $rfqId, int $actorId): bool
    {
        // Verify there are invited sellers
        $invitations = $this->invitationModel->listByRfq($rfqId);
        if (empty($invitations)) {
            return false;
        }

        $transitioned = $this->rfqModel->transition($rfqId, RFQ::STATUS_PUBLISHED);

        if ($transitioned) {
            $this->logAudit($actorId, 'rfq.published', 'rfq', $rfqId);
        }

        return $transitioned;
    }

    /**
     * Extend the bid deadline for a published RFQ.
     * Logs the extension with reason for audit trail.
     */
    public function extendDeadline(
        int    $rfqId,
        string $newDeadline,
        int    $actorId,
        string $reason,
    ): bool {
        $this->assertDeadlineInFuture($newDeadline);

        // Fetch current RFQ to verify it's published and to capture before-state
        $rfq = $this->rfqModel->find($rfqId);
        if ($rfq === null || $rfq['status'] !== RFQ::STATUS_PUBLISHED) {
            return false;
        }

        // New deadline must be after current deadline
        $currentDeadline = strtotime($rfq['bid_deadline']);
        $newDeadlineTs   = strtotime($newDeadline);

        if ($newDeadlineTs === false || $currentDeadline === false || $newDeadlineTs <= $currentDeadline) {
            return false;
        }

        $extended = $this->rfqModel->extendDeadline($rfqId, $newDeadline);

        if ($extended) {
            $this->logAudit($actorId, 'rfq.deadline_extended', 'rfq', $rfqId, [
                'previous_deadline' => $rfq['bid_deadline'],
                'new_deadline'      => $newDeadline,
                'reason'            => $reason,
            ]);
        }

        return $extended;
    }

    /**
     * Close bidding on an RFQ. Transitions published -> closed.
     */
    public function close(int $rfqId, int $actorId): bool
    {
        $closed = $this->rfqModel->transition($rfqId, RFQ::STATUS_CLOSED);

        if ($closed) {
            $this->logAudit($actorId, 'rfq.closed', 'rfq', $rfqId);
        }

        return $closed;
    }

    /**
     * Invite specific sellers to an RFQ.
     *
     * @param int[] $sellerIds
     * @return int Number of new invitations created.
     */
    public function inviteSellers(int $rfqId, array $sellerIds): int
    {
        $rfq = $this->rfqModel->find($rfqId);
        if ($rfq === null) {
            return 0;
        }

        // Allow inviting sellers to draft or published RFQs
        if (!in_array($rfq['status'], [RFQ::STATUS_DRAFT, RFQ::STATUS_PUBLISHED], true)) {
            return 0;
        }

        return $this->invitationModel->bulkInvite($rfqId, $sellerIds);
    }

    /**
     * Check if a seller is eligible (invited) for a specific RFQ.
     */
    public function isSellerEligible(int $rfqId, int $sellerId): bool
    {
        $invitation = $this->invitationModel->findByRfqAndSeller($rfqId, $sellerId);
        return $invitation !== null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Assert that a deadline string is a valid future timestamp.
     *
     * @throws \App\Core\Exception\ValidationException
     */
    private function assertDeadlineInFuture(string $deadline): void
    {
        $ts = strtotime($deadline);

        if ($ts === false || $ts <= time()) {
            throw new \App\Core\Exception\ValidationException([
                'bid_deadline' => 'Bid deadline must be a valid future date/time',
            ]);
        }
    }

    /**
     * Write an immutable audit log entry.
     */
    private function logAudit(
        int     $actorId,
        string  $action,
        string  $entityType,
        int     $entityId,
        ?array  $afterJson = null,
    ): void {
        (new QueryBuilder($this->connection))
            ->table('audit_logs')
            ->insert([
                'actor_id'    => $actorId,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId,
                'after_json'  => $afterJson !== null ? json_encode($afterJson) : null,
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ]);
    }
}
