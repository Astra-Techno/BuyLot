<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Services;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use App\Core\Exception\HttpException;
use App\Modules\Fulfillment\Models\BuyerAcknowledgement;
use App\Modules\Fulfillment\Models\DeliveryEvidence;
use App\Modules\Fulfillment\Models\Shipment;
use App\Modules\Fulfillment\Models\ShipmentItem;

/**
 * FulfillmentService — core shipment lifecycle logic.
 *
 * Key invariants:
 *   - Partial delivery is fully tracked: ordered / dispatched / delivered /
 *     accepted / disputed / refunded per item.
 *   - NEVER settle disputed items. Disputed qty is isolated at acknowledgement
 *     and must flow into the dispute module; this service only records the
 *     split, it does not resolve it.
 *   - Split shipments are supported: a fulfillment order may generate more
 *     than one Shipment; each runs its own state machine.
 *   - Every state transition executes inside an explicit DB transaction.
 */
class FulfillmentService
{
    public function __construct(
        private Connection            $connection,
        private Shipment              $shipmentModel,
        private ShipmentItem          $shipmentItemModel,
        private DeliveryEvidence      $evidenceModel,
        private BuyerAcknowledgement  $acknowledgementModel,
    ) {}

    // -------------------------------------------------------------------------
    // Shipment creation
    // -------------------------------------------------------------------------

    /**
     * Create a shipment for a seller_fulfillment_order.
     *
     * Validates that the total qty per order_item across all existing shipments
     * plus this new shipment does not exceed the original order_items.qty.
     *
     * @param array<int, array{order_item_id: int, qty: int}> $items
     * @return array  Created shipment row with items
     * @throws HttpException on validation failure
     */
    public function createShipment(int $fulfillmentOrderId, array $items): array
    {
        if (empty($items)) {
            throw new HttpException('Shipment must contain at least one item', 422);
        }

        $this->connection->beginTransaction();

        try {
            // Validate qty per order_item (split-shipment guard)
            foreach ($items as $item) {
                $orderItemId = (int) $item['order_item_id'];
                $qty         = (int) $item['qty'];

                if ($qty < 1) {
                    throw new HttpException("qty must be >= 1 for order_item {$orderItemId}", 422);
                }

                $ordered     = $this->getOrderedQty($orderItemId);
                $dispatched  = $this->shipmentItemModel->dispatchedQty($orderItemId);

                if (($dispatched + $qty) > $ordered) {
                    throw new HttpException(
                        "Cannot dispatch {$qty} units of order_item {$orderItemId}: "
                        . "ordered={$ordered}, already dispatched={$dispatched}",
                        422,
                    );
                }
            }

            $shipmentId = $this->shipmentModel->create($fulfillmentOrderId);
            $this->shipmentItemModel->bulkCreate($shipmentId, $items);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $shipment          = $this->shipmentModel->find($shipmentId);
        $shipment['items'] = $this->shipmentItemModel->listByShipment($shipmentId);

        return $shipment;
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    /**
     * Mark a shipment as dispatched.
     *
     * Records carrier + tracking and appends a POD delivery_evidence row
     * for the dispatch evidence object reference (invoice / AWB).
     *
     * @throws HttpException if shipment not found or already dispatched
     */
    public function recordDispatch(
        int    $shipmentId,
        string $carrier,
        string $trackingRef,
        string $evidenceObjectRef,
    ): array {
        $shipment = $this->shipmentModel->find($shipmentId);
        if ($shipment === null) {
            throw new HttpException('Shipment not found', 404);
        }

        if ($shipment['status'] !== Shipment::STATUS_PENDING) {
            throw new HttpException(
                "Shipment is already in status '{$shipment['status']}'; cannot dispatch again",
                409,
            );
        }

        $this->connection->beginTransaction();

        try {
            $this->shipmentModel->updateTracking($shipmentId, $carrier, $trackingRef);
            $this->shipmentModel->transition($shipmentId, Shipment::STATUS_PICKED_UP);

            // Append dispatch evidence (POD type carries the AWB/invoice ref)
            $this->evidenceModel->append(
                $shipmentId,
                DeliveryEvidence::TYPE_POD,
                $evidenceObjectRef,
            );

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->shipmentModel->find($shipmentId);
    }

    // -------------------------------------------------------------------------
    // Carrier updates
    // -------------------------------------------------------------------------

    /**
     * Apply a carrier status update (from webhook or polling).
     *
     * Maps carrier status strings onto Shipment constants.
     * Silently no-ops if the transition is not applicable (idempotent).
     *
     * @throws HttpException if shipment not found
     */
    public function updateFromCarrier(int $shipmentId, string $carrierStatus): array
    {
        $shipment = $this->shipmentModel->find($shipmentId);
        if ($shipment === null) {
            throw new HttpException('Shipment not found', 404);
        }

        $statusMap = [
            'in_transit'       => Shipment::STATUS_IN_TRANSIT,
            'out_for_delivery' => Shipment::STATUS_OUT_FOR_DELIVERY,
            'delivered'        => Shipment::STATUS_DELIVERED,
            'failed'           => Shipment::STATUS_FAILED_DELIVERY,
            'returned'         => Shipment::STATUS_RETURNED,
        ];

        $toStatus = $statusMap[$carrierStatus] ?? null;

        if ($toStatus !== null && $toStatus !== $shipment['status']) {
            try {
                if ($toStatus === Shipment::STATUS_DELIVERED) {
                    $this->shipmentModel->markDelivered($shipmentId);
                } else {
                    $this->shipmentModel->transition($shipmentId, $toStatus);
                }
            } catch (\LogicException) {
                // Transition not valid from current state — idempotent no-op
            }
        }

        return $this->shipmentModel->find($shipmentId);
    }

    /**
     * Update carrier and tracking reference only (no status change).
     *
     * @throws HttpException if shipment not found
     */
    public function updateTracking(int $shipmentId, string $carrier, string $trackingRef): array
    {
        $shipment = $this->shipmentModel->find($shipmentId);
        if ($shipment === null) {
            throw new HttpException('Shipment not found', 404);
        }

        $this->shipmentModel->updateTracking($shipmentId, $carrier, $trackingRef);

        return $this->shipmentModel->find($shipmentId);
    }

    // -------------------------------------------------------------------------
    // Delivery verification
    // -------------------------------------------------------------------------

    /**
     * Ops agent marks delivery as verified after reviewing evidence.
     *
     * Transitions the shipment to `delivered` (if not already) and marks all
     * un-verified evidence rows as verified by the acting agent.
     *
     * @throws HttpException if shipment not found or not in a verifiable state
     */
    public function verifyDelivery(int $shipmentId, int $actorId): array
    {
        $shipment = $this->shipmentModel->find($shipmentId);
        if ($shipment === null) {
            throw new HttpException('Shipment not found', 404);
        }

        $verifiableStatuses = [
            Shipment::STATUS_DELIVERED,
            Shipment::STATUS_OUT_FOR_DELIVERY,
        ];

        if (!in_array($shipment['status'], $verifiableStatuses, true)) {
            throw new HttpException(
                "Cannot verify delivery for shipment in status '{$shipment['status']}'",
                409,
            );
        }

        $this->connection->beginTransaction();

        try {
            // Ensure status is delivered
            if ($shipment['status'] !== Shipment::STATUS_DELIVERED) {
                $this->shipmentModel->markDelivered($shipmentId);
            }

            // Verify all un-verified evidence
            foreach ($this->evidenceModel->listByShipment($shipmentId) as $evidence) {
                if ($evidence['verified_at'] === null) {
                    $this->evidenceModel->markVerified((int) $evidence['id'], $actorId);
                }
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        $result             = $this->shipmentModel->find($shipmentId);
        $result['evidence'] = $this->evidenceModel->listByShipment($shipmentId);

        return $result;
    }

    // -------------------------------------------------------------------------
    // Buyer acknowledgement
    // -------------------------------------------------------------------------

    /**
     * Process a buyer's delivery acknowledgement.
     *
     * Partial delivery invariants enforced here:
     *   - accepted + disputed <= shipped qty per item
     *   - disputed items are isolated — they must NOT be included in settlement
     *     eligibility calculations downstream
     *
     * @param array<string, int> $acceptedQty  order_item_id -> qty
     * @param array<string, int> $disputedQty  order_item_id -> qty
     * @return array  Created acknowledgement with decoded JSON quantities
     * @throws HttpException on validation failure
     */
    public function processAcknowledgement(
        int   $shipmentId,
        int   $buyerId,
        array $acceptedQty,
        array $disputedQty,
    ): array {
        $shipment = $this->shipmentModel->find($shipmentId);
        if ($shipment === null) {
            throw new HttpException('Shipment not found', 404);
        }

        // Delivery must be verified before buyer can acknowledge
        if ($shipment['status'] !== Shipment::STATUS_DELIVERED) {
            throw new HttpException(
                "Delivery must be confirmed before acknowledgement (current status: '{$shipment['status']}')",
                409,
            );
        }

        // Resolve the order_id for this shipment
        $orderId = $this->resolveOrderIdFromShipment($shipmentId);
        if ($orderId === null) {
            throw new HttpException('Could not resolve order for shipment', 500);
        }

        // Ownership: verify this buyer owns the order
        $this->assertBuyerOwnsOrder($buyerId, $orderId);

        // Validate quantities per shipped item
        $shippedItems = $this->shipmentItemModel->listByShipment($shipmentId);
        $shippedMap   = [];
        foreach ($shippedItems as $si) {
            $shippedMap[(string) $si['order_item_id']] = (int) $si['qty'];
        }

        foreach ($shippedMap as $itemId => $shippedQty) {
            $accepted = (int) ($acceptedQty[$itemId] ?? 0);
            $disputed = (int) ($disputedQty[$itemId] ?? 0);

            if ($accepted < 0 || $disputed < 0) {
                throw new HttpException("Quantities cannot be negative for order_item {$itemId}", 422);
            }

            if (($accepted + $disputed) > $shippedQty) {
                throw new HttpException(
                    "accepted ({$accepted}) + disputed ({$disputed}) exceeds shipped qty ({$shippedQty}) "
                    . "for order_item {$itemId}",
                    422,
                );
            }
        }

        // Check for unknown item IDs in the payload
        foreach (array_keys($acceptedQty + $disputedQty) as $itemId) {
            if (!isset($shippedMap[$itemId])) {
                throw new HttpException(
                    "order_item_id {$itemId} is not part of this shipment",
                    422,
                );
            }
        }

        $ackId = $this->acknowledgementModel->create(
            $orderId,
            $shipmentId,
            $buyerId,
            $acceptedQty,
            $disputedQty,
        );

        return $this->acknowledgementModel->findByShipmentAndBuyer($shipmentId, $buyerId);
    }

    // -------------------------------------------------------------------------
    // Read helpers
    // -------------------------------------------------------------------------

    /**
     * Shipment detail enriched with items and evidence for buyer/ops view.
     *
     * @throws HttpException if not found
     */
    public function getShipmentDetail(int $shipmentId): array
    {
        $shipment = $this->shipmentModel->find($shipmentId);
        if ($shipment === null) {
            throw new HttpException('Shipment not found', 404);
        }

        $shipment['items']    = $this->shipmentItemModel->listByShipment($shipmentId);
        $shipment['evidence'] = $this->evidenceModel->listByShipment($shipmentId);

        return $shipment;
    }

    /**
     * Tracking view for buyer: status + tracking ref + evidence (no internal ops data).
     *
     * @throws HttpException if not found or buyer does not own the order
     */
    public function getTrackingForBuyer(int $orderId, int $buyerId): array
    {
        $this->assertBuyerOwnsOrder($buyerId, $orderId);

        $shipment = $this->shipmentModel->findByOrderId($orderId);
        if ($shipment === null) {
            throw new HttpException('No shipment found for this order', 404);
        }

        return [
            'shipment_id'  => $shipment['id'],
            'carrier'      => $shipment['carrier'],
            'tracking_ref' => $shipment['tracking_ref'],
            'status'       => $shipment['status'],
            'dispatched'   => $shipment['created_at'],
            'delivered_at' => $shipment['actual_delivery_at'],
            'evidence'     => $this->evidenceModel->listByShipment((int) $shipment['id']),
        ];
    }

    /**
     * Overview metrics for the fulfillment dashboard.
     *
     * @return array{by_status: array<string, int>, recent: array}
     */
    public function dashboardOverview(): array
    {
        $rows = $this->connection->query(
            'SELECT status, COUNT(*) AS cnt FROM shipments GROUP BY status',
        );

        $byStatus = [];
        foreach ($rows as $row) {
            $byStatus[$row['status']] = (int) $row['cnt'];
        }

        $recent = $this->shipmentModel->list([], 1, 10)['data'];

        return [
            'by_status' => $byStatus,
            'recent'    => $recent,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function getOrderedQty(int $orderItemId): int
    {
        $rows = $this->connection->query(
            'SELECT qty FROM order_items WHERE id = ?',
            [$orderItemId],
        );

        if (empty($rows)) {
            throw new HttpException("order_item {$orderItemId} not found", 422);
        }

        return (int) $rows[0]['qty'];
    }

    private function resolveOrderIdFromShipment(int $shipmentId): ?int
    {
        // shipment -> seller_fulfillment_order -> offer -> order
        $rows = $this->connection->query(
            'SELECT o.id AS order_id
             FROM shipments s
             JOIN seller_fulfillment_orders sfo ON sfo.id = s.seller_fulfillment_order_id
             JOIN orders o ON o.offer_id = sfo.offer_id
             WHERE s.id = ?
             LIMIT 1',
            [$shipmentId],
        );

        return isset($rows[0]['order_id']) ? (int) $rows[0]['order_id'] : null;
    }

    private function assertBuyerOwnsOrder(int $buyerId, int $orderId): void
    {
        $rows = $this->connection->query(
            'SELECT id FROM orders WHERE id = ? AND buyer_id = ? LIMIT 1',
            [$orderId, $buyerId],
        );

        if (empty($rows)) {
            throw new HttpException('Order not found', 404);
        }
    }
}
