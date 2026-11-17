<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * BuyerAcknowledgement model.
 *
 * Maps the `buyer_acknowledgements` table (schema 6.6).
 *
 * One row per (order_id, shipment_id, buyer_id) — enforced by
 * uq_buyer_ack_order_shipment_buyer.
 *
 * accepted_qty_json  — Map<order_item_id (string), int>
 * disputed_qty_json  — Map<order_item_id (string), int>
 *
 * The service layer ensures accepted + disputed <= shipped qty per item.
 * NEVER settle disputed items — this is enforced by FulfillmentService and
 * the settlement module; this model stores facts only.
 */
class BuyerAcknowledgement
{
    private const TABLE = 'buyer_acknowledgements';

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Find the acknowledgement for a specific shipment by a buyer.
     * Returns null when the buyer has not yet acknowledged.
     */
    public function findByShipmentAndBuyer(int $shipmentId, int $buyerId): ?array
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('shipment_id', $shipmentId)
            ->where('buyer_id', $buyerId)
            ->first();

        return $row !== null ? $this->decode($row) : null;
    }

    /**
     * Find by order and buyer (across all shipments for the order).
     *
     * @return array<int, array>
     */
    public function listByOrderAndBuyer(int $orderId, int $buyerId): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('order_id', $orderId)
            ->where('buyer_id', $buyerId)
            ->orderBy('submitted_at', 'ASC')
            ->get();

        return array_map(fn(array $r) => $this->decode($r), $rows);
    }

    /**
     * All acknowledgements for an order (ops view).
     *
     * @return array<int, array>
     */
    public function listByOrder(int $orderId): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('order_id', $orderId)
            ->orderBy('submitted_at', 'ASC')
            ->get();

        return array_map(fn(array $r) => $this->decode($r), $rows);
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Record a buyer acknowledgement.
     *
     * @param array<string, int> $acceptedQty   map of order_item_id -> qty accepted
     * @param array<string, int> $disputedQty   map of order_item_id -> qty disputed
     * @return int  New row id
     * @throws \RuntimeException if buyer already acknowledged this shipment
     */
    public function create(
        int   $orderId,
        int   $shipmentId,
        int   $buyerId,
        array $acceptedQty,
        array $disputedQty,
    ): int {
        // Guard: idempotency — the unique constraint will catch duplicates at DB
        // level, but we give a clear error at the application layer first.
        $existing = $this->findByShipmentAndBuyer($shipmentId, $buyerId);
        if ($existing !== null) {
            throw new \RuntimeException(
                "Buyer {$buyerId} has already acknowledged shipment {$shipmentId}",
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'order_id'          => $orderId,
                'shipment_id'       => $shipmentId,
                'buyer_id'          => $buyerId,
                'accepted_qty_json' => json_encode($acceptedQty, JSON_THROW_ON_ERROR),
                'disputed_qty_json' => json_encode($disputedQty, JSON_THROW_ON_ERROR),
                'submitted_at'      => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Decode JSON columns back to native PHP arrays.
     */
    private function decode(array $row): array
    {
        $row['accepted_qty_json'] = is_string($row['accepted_qty_json'])
            ? json_decode($row['accepted_qty_json'], true, 512, JSON_THROW_ON_ERROR)
            : ($row['accepted_qty_json'] ?? []);

        $row['disputed_qty_json'] = is_string($row['disputed_qty_json'])
            ? json_decode($row['disputed_qty_json'], true, 512, JSON_THROW_ON_ERROR)
            : ($row['disputed_qty_json'] ?? []);

        return $row;
    }
}
