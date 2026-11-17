<?php

declare(strict_types=1);

namespace App\Modules\Commitments\Services;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * CommitmentService -- Tracks four distinct quantity levels per group/offer.
 *
 * Quantities (per Section 4.2 / Buyer Intent levels):
 *   interested_qty  L1  Deduplicated soft demand (OTP-verified interest)
 *   ready_qty       L2  Buyer reconfirmed purchase readiness
 *   accepted_qty    L3  Buyer accepted a specific offer (may be unpaid)
 *   paid_qty        L4  Payment confirmed, unrefunded -- the ONLY qty eligible for MOQ
 *
 * Business rules:
 *   - Only paid_qty counts toward MOQ (spec 6.1).
 *   - No silent repricing: once a buyer pays at a price, that price is locked.
 *   - Atomic increments/decrements use SQL UPDATE with arithmetic to prevent race conditions.
 */
class CommitmentService
{
    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Get the commitment summary for an offer.
     *
     * Aggregates across group_memberships and orders to produce all four quantity levels.
     *
     * @return array{interested_qty: int, ready_qty: int, accepted_qty: int, paid_qty: int}
     */
    public function getCommitmentSummary(int $offerId): array
    {
        // Get the group_id for this offer
        $offer = (new QueryBuilder($this->connection))
            ->table('negotiated_offers')
            ->select('id', 'group_id')
            ->where('id', $offerId)
            ->first();

        if ($offer === null) {
            return [
                'interested_qty' => 0,
                'ready_qty'      => 0,
                'accepted_qty'   => 0,
                'paid_qty'       => 0,
            ];
        }

        $groupId = (int) $offer['group_id'];

        // L1: interested_qty -- count of group_memberships with intent_level >= 1
        $interested = $this->connection->query(
            'SELECT COALESCE(SUM(qty), 0) AS total
             FROM group_memberships
             WHERE group_id = ? AND intent_level >= 1',
            [$groupId],
        );

        // L2: ready_qty -- count of group_memberships with intent_level >= 2
        $ready = $this->connection->query(
            'SELECT COALESCE(SUM(qty), 0) AS total
             FROM group_memberships
             WHERE group_id = ? AND intent_level >= 2',
            [$groupId],
        );

        // L3: accepted_qty -- sum of qty from offer_acceptances for this offer
        $accepted = $this->connection->query(
            'SELECT COALESCE(SUM(qty), 0) AS total
             FROM offer_acceptances
             WHERE offer_id = ?',
            [$offerId],
        );

        // L4: paid_qty -- sum of qty from order_items for PAID orders on this offer
        // CRITICAL: Only payment_confirmed orders count toward MOQ
        $paid = $this->connection->query(
            'SELECT COALESCE(SUM(oi.qty), 0) AS total
             FROM orders o
             INNER JOIN order_items oi ON oi.order_id = o.id
             WHERE o.offer_id = ?
               AND o.status = ?
               AND o.deleted_at IS NULL',
            [$offerId, 'payment_confirmed'],
        );

        return [
            'interested_qty' => (int) ($interested[0]['total'] ?? 0),
            'ready_qty'      => (int) ($ready[0]['total'] ?? 0),
            'accepted_qty'   => (int) ($accepted[0]['total'] ?? 0),
            'paid_qty'       => (int) ($paid[0]['total'] ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Record a payment confirmation: atomically increment paid_qty tracking.
     *
     * This is called after the payment webhook confirms capture. The order must
     * already exist and be in payment_confirmed status.
     *
     * Updates the group_membership intent_level to L4 for the buyer.
     */
    public function recordPaymentConfirmation(int $orderId): bool
    {
        // Get order details
        $order = (new QueryBuilder($this->connection))
            ->table('orders')
            ->select('id', 'buyer_id', 'offer_id', 'group_id', 'status')
            ->where('id', $orderId)
            ->first();

        if ($order === null || $order['status'] !== 'payment_confirmed') {
            return false;
        }

        $buyerId = (int) $order['buyer_id'];
        $groupId = (int) $order['group_id'];

        // Atomically update group_membership intent_level to L4
        $this->connection->execute(
            'UPDATE group_memberships
             SET intent_level = 4, updated_at = NOW()
             WHERE group_id = ? AND buyer_id = ? AND intent_level < 4',
            [$groupId, $buyerId],
        );

        return true;
    }

    /**
     * Record an offer acceptance: increment accepted_qty.
     *
     * Updates the group_membership intent_level to L3 for the buyer.
     */
    public function recordOfferAcceptance(int $offerId, int $buyerId, int $groupId): void
    {
        $this->connection->execute(
            'UPDATE group_memberships
             SET intent_level = 3, updated_at = NOW()
             WHERE group_id = ? AND buyer_id = ? AND intent_level < 3',
            [$groupId, $buyerId],
        );
    }

    /**
     * Handle payment cancellation/refund: decrement paid_qty.
     *
     * Downgrades group_membership intent_level back to L3 (accepted but unpaid).
     */
    public function recordPaymentCancellation(int $orderId): bool
    {
        $order = (new QueryBuilder($this->connection))
            ->table('orders')
            ->select('buyer_id', 'group_id')
            ->where('id', $orderId)
            ->first();

        if ($order === null) {
            return false;
        }

        $buyerId = (int) $order['buyer_id'];
        $groupId = (int) $order['group_id'];

        // Check if buyer has any other paid orders for this group
        $otherPaid = $this->connection->query(
            'SELECT COUNT(*) AS cnt
             FROM orders
             WHERE group_id = ?
               AND buyer_id = ?
               AND status = ?
               AND id != ?
               AND deleted_at IS NULL',
            [$groupId, $buyerId, 'payment_confirmed', $orderId],
        );

        // Only downgrade if no other paid orders exist
        if ((int) ($otherPaid[0]['cnt'] ?? 0) === 0) {
            $this->connection->execute(
                'UPDATE group_memberships
                 SET intent_level = 3, updated_at = NOW()
                 WHERE group_id = ? AND buyer_id = ? AND intent_level = 4',
                [$groupId, $buyerId],
            );
        }

        return true;
    }
}
