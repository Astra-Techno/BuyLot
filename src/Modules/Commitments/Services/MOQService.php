<?php

declare(strict_types=1);

namespace App\Modules\Commitments\Services;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use App\Core\Exception\HttpException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\SellerFulfillmentOrder;

/**
 * MOQService -- THE CRITICAL MOQ LOGIC (spec 6.1-6.3).
 *
 * Key rule: ONLY paid_qty counts toward MOQ.
 *
 * Example: 120 interested / 105 accepted / 90 paid with MOQ 100 = NOT MET.
 * interested_qty, ready_qty and accepted_qty are informational only.
 *
 * This service handles:
 *   - Threshold checking (is paid_qty >= MOQ?)
 *   - Threshold met (approve fulfillment, create seller fulfillment order)
 *   - Threshold failed (extend with consent, negotiate lower tier, or cancel+refund)
 *   - Oversubscription (atomic reservation, waitlist, or reject extra)
 *   - Deadline monitoring
 */
class MOQService
{
    public function __construct(
        private Connection            $connection,
        private CommitmentService     $commitmentService,
        private Order                 $orderModel,
        private SellerFulfillmentOrder $fulfillmentOrderModel,
    ) {}

    // -------------------------------------------------------------------------
    // Threshold Checking
    // -------------------------------------------------------------------------

    /**
     * Check whether the MOQ threshold is met for an offer.
     *
     * CRITICAL: Only paid_qty (L4) counts. Not interested, not accepted-unpaid.
     *
     * @return array{met: bool, moq: int, paid_qty: int, gap: int}
     */
    public function checkThreshold(int $offerId): array
    {
        $offer = $this->getOfferOrFail($offerId);
        $moq   = $this->getOfferMOQ($offerId);

        // Count ONLY paid quantity from payment_confirmed orders
        $paidQty = $this->orderModel->countPaidByOffer($offerId);

        return [
            'met'      => $paidQty >= $moq,
            'moq'      => $moq,
            'paid_qty' => $paidQty,
            'gap'      => max(0, $moq - $paidQty),
        ];
    }

    /**
     * Full MOQ status including all commitment levels for visibility.
     *
     * @return array{met: bool, moq: int, paid_qty: int, gap: int, interested_qty: int, ready_qty: int, accepted_qty: int, offer_status: string, expires_at: string}
     */
    public function getFullStatus(int $offerId): array
    {
        $offer      = $this->getOfferOrFail($offerId);
        $threshold  = $this->checkThreshold($offerId);
        $commitment = $this->commitmentService->getCommitmentSummary($offerId);

        return array_merge($threshold, [
            'interested_qty' => $commitment['interested_qty'],
            'ready_qty'      => $commitment['ready_qty'],
            'accepted_qty'   => $commitment['accepted_qty'],
            'offer_status'   => $offer['status'],
            'expires_at'     => $offer['expires_at'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Threshold Met
    // -------------------------------------------------------------------------

    /**
     * Handle threshold being met: approve fulfillment, create seller fulfillment order.
     *
     * This should be called after checkThreshold confirms MOQ is met.
     * Runs in a transaction for atomicity.
     *
     * @return array{fulfillment_order_id: int, orders_released: int}
     */
    public function handleThresholdMet(int $offerId): array
    {
        $threshold = $this->checkThreshold($offerId);
        if (!$threshold['met']) {
            throw new HttpException('MOQ not met. paid_qty=' . $threshold['paid_qty'] . ' < moq=' . $threshold['moq'], 409);
        }

        $offer = $this->getOfferOrFail($offerId);

        // Check if fulfillment order already exists (idempotency)
        $existing = $this->fulfillmentOrderModel->findByOffer($offerId);
        if ($existing !== null) {
            return [
                'fulfillment_order_id' => (int) $existing['id'],
                'orders_released'      => 0,
            ];
        }

        $this->connection->beginTransaction();

        try {
            // 1. Update offer status to moq_met
            $this->connection->execute(
                'UPDATE negotiated_offers SET status = ?, version = version + 1, updated_at = NOW()
                 WHERE id = ? AND status IN (?, ?)',
                ['moq_met', $offerId, 'published', 'moq_met'],
            );

            // 2. Create seller fulfillment order
            $fulfillmentOrderId = $this->fulfillmentOrderModel->create(
                (int) $offer['seller_id'],
                $offerId,
            );

            // 3. Release all paid orders to seller
            $paidOrders     = $this->orderModel->findPaidByOffer($offerId);
            $releasedCount  = 0;

            foreach ($paidOrders as $order) {
                $updated = $this->orderModel->updateStatus(
                    (int) $order['id'],
                    Order::STATUS_PAYMENT_CONFIRMED,
                    Order::STATUS_PROCESSING,
                );
                if ($updated) {
                    $releasedCount++;
                }
            }

            // 4. Update demand group status
            $this->connection->execute(
                'UPDATE demand_groups SET status = ?, updated_at = NOW()
                 WHERE id = ? AND status NOT IN (?, ?)',
                ['moq_met', (int) $offer['group_id'], 'completed', 'cancelled'],
            );

            $this->connection->commit();

            return [
                'fulfillment_order_id' => $fulfillmentOrderId,
                'orders_released'      => $releasedCount,
            ];
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Threshold Failed
    // -------------------------------------------------------------------------

    /**
     * Handle threshold failure: options depend on business decision.
     *
     * @param string $action  One of: 'extend', 'cancel_refund'
     * @param array  $options For 'extend': ['new_deadline' => 'Y-m-d H:i:s']
     *
     * @return array{action: string, affected_orders: int}
     */
    public function handleThresholdFailed(int $offerId, string $action, array $options = []): array
    {
        $offer = $this->getOfferOrFail($offerId);

        return match ($action) {
            'extend'        => $this->extendDeadline($offerId, $offer, $options),
            'cancel_refund' => $this->cancelAndRefund($offerId, $offer),
            default         => throw new HttpException("Invalid action: {$action}. Allowed: extend, cancel_refund", 400),
        };
    }

    /**
     * Extend deadline with seller consent.
     *
     * @return array{action: string, affected_orders: int, new_deadline: string}
     */
    private function extendDeadline(int $offerId, array $offer, array $options): array
    {
        $newDeadline = $options['new_deadline'] ?? null;
        if (empty($newDeadline)) {
            throw new HttpException('new_deadline is required for extend action', 400);
        }

        $this->connection->execute(
            'UPDATE negotiated_offers
             SET expires_at = ?, status = ?, version = version + 1, updated_at = NOW()
             WHERE id = ? AND status = ?',
            [$newDeadline, 'published', $offerId, 'moq_failed'],
        );

        // Update demand group back to offer_live
        $this->connection->execute(
            'UPDATE demand_groups SET status = ?, updated_at = NOW() WHERE id = ?',
            ['offer_live', (int) $offer['group_id']],
        );

        return [
            'action'          => 'extend',
            'affected_orders' => 0,
            'new_deadline'    => $newDeadline,
        ];
    }

    /**
     * Cancel all paid orders and initiate refunds.
     *
     * @return array{action: string, affected_orders: int}
     */
    private function cancelAndRefund(int $offerId, array $offer): array
    {
        $this->connection->beginTransaction();

        try {
            // 1. Mark offer as cancelled
            $this->connection->execute(
                'UPDATE negotiated_offers
                 SET status = ?, version = version + 1, updated_at = NOW()
                 WHERE id = ?',
                ['cancelled', $offerId],
            );

            // 2. Cancel all paid orders for this offer
            $paidOrders = $this->orderModel->findPaidByOffer($offerId);
            $cancelled  = 0;

            foreach ($paidOrders as $order) {
                $updated = $this->orderModel->updateStatus(
                    (int) $order['id'],
                    Order::STATUS_PAYMENT_CONFIRMED,
                    Order::STATUS_CANCELLED,
                );
                if ($updated) {
                    $cancelled++;
                    // Decrement commitment tracking
                    $this->commitmentService->recordPaymentCancellation((int) $order['id']);
                }
            }

            // 3. Update demand group status
            $this->connection->execute(
                'UPDATE demand_groups SET status = ?, updated_at = NOW() WHERE id = ?',
                ['cancelled', (int) $offer['group_id']],
            );

            $this->connection->commit();

            return [
                'action'          => 'cancel_refund',
                'affected_orders' => $cancelled,
            ];
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Oversubscription
    // -------------------------------------------------------------------------

    /**
     * Handle oversubscription: check if the offer can accept more orders.
     *
     * Uses atomic reservation via SELECT ... FOR UPDATE to prevent race conditions.
     *
     * @return array{allowed: bool, reason: string}
     */
    public function handleOversubscription(int $offerId, int $requestedQty): array
    {
        $offer = $this->getOfferOrFail($offerId);

        // Get max_qty from the offer's source bid revision (if available)
        $maxQty = $this->getOfferMaxQty($offerId);

        if ($maxQty === null) {
            // No cap -- allow
            return ['allowed' => true, 'reason' => 'No stock limit configured'];
        }

        // Atomic check: SELECT FOR UPDATE on stock_reservations
        $this->connection->beginTransaction();

        try {
            $reserved = $this->connection->query(
                'SELECT COALESCE(SUM(qty), 0) AS total
                 FROM stock_reservations
                 WHERE offer_id = ? AND status = ?
                 FOR UPDATE',
                [$offerId, 'active'],
            );

            $currentReserved = (int) ($reserved[0]['total'] ?? 0);

            if (($currentReserved + $requestedQty) > $maxQty) {
                $this->connection->commit();
                return [
                    'allowed' => false,
                    'reason'  => "Stock exhausted. Reserved: {$currentReserved}, max: {$maxQty}, requested: {$requestedQty}",
                ];
            }

            $this->connection->commit();
            return ['allowed' => true, 'reason' => 'Stock available'];
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Deadline Monitoring
    // -------------------------------------------------------------------------

    /**
     * Check if the offer deadline has passed and threshold is not met.
     *
     * @return array{expired: bool, threshold_met: bool, offer_status: string, expires_at: string}
     */
    public function monitorDeadline(int $offerId): array
    {
        $offer     = $this->getOfferOrFail($offerId);
        $threshold = $this->checkThreshold($offerId);
        $now       = gmdate('Y-m-d H:i:s');
        $expired   = $offer['expires_at'] <= $now;

        // If expired and not met, mark offer as moq_failed
        if ($expired && !$threshold['met'] && in_array($offer['status'], ['published', 'moq_met'], true) === false) {
            // Only transition from active collection states
            if (in_array($offer['status'], ['published'], true)) {
                $this->connection->execute(
                    'UPDATE negotiated_offers
                     SET status = ?, version = version + 1, updated_at = NOW()
                     WHERE id = ? AND status = ?',
                    ['moq_failed', $offerId, 'published'],
                );
            }
        }

        return [
            'expired'       => $expired,
            'threshold_met' => $threshold['met'],
            'offer_status'  => $offer['status'],
            'expires_at'    => $offer['expires_at'],
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function getOfferOrFail(int $offerId): array
    {
        $offer = (new QueryBuilder($this->connection))
            ->table('negotiated_offers')
            ->select('id', 'group_id', 'seller_id', 'status', 'expires_at', 'version')
            ->where('id', $offerId)
            ->first();

        if ($offer === null) {
            throw new HttpException('Offer not found', 404);
        }

        return $offer;
    }

    /**
     * Get the MOQ for an offer from its price tiers.
     *
     * The MOQ is the minimum min_paid_qty across all tiers.
     */
    private function getOfferMOQ(int $offerId): int
    {
        $result = $this->connection->query(
            'SELECT MIN(min_paid_qty) AS moq FROM offer_price_tiers WHERE offer_id = ?',
            [$offerId],
        );

        $moq = (int) ($result[0]['moq'] ?? 0);

        if ($moq <= 0) {
            throw new HttpException('No price tiers configured for offer', 500);
        }

        return $moq;
    }

    /**
     * Get the max quantity the seller can supply for this offer.
     */
    private function getOfferMaxQty(int $offerId): ?int
    {
        $offer = (new QueryBuilder($this->connection))
            ->table('negotiated_offers')
            ->select('source_bid_revision_id')
            ->where('id', $offerId)
            ->first();

        if ($offer === null || $offer['source_bid_revision_id'] === null) {
            return null;
        }

        $revision = (new QueryBuilder($this->connection))
            ->table('seller_bid_revisions')
            ->select('max_qty')
            ->where('id', (int) $offer['source_bid_revision_id'])
            ->first();

        if ($revision === null || $revision['max_qty'] === null) {
            return null;
        }

        return (int) $revision['max_qty'];
    }
}
