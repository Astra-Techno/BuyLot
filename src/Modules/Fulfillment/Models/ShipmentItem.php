<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * ShipmentItem model.
 *
 * Maps the `shipment_items` table (schema 6.2).
 *
 * Supports split shipments: a single order_item_id may appear in multiple
 * shipment_items rows (across different shipments) as long as the sum of
 * qty does not exceed the original order_items.qty.  Enforcement is the
 * responsibility of FulfillmentService.createShipment().
 */
class ShipmentItem
{
    private const TABLE = 'shipment_items';

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * All items for a given shipment.
     *
     * @return array<int, array>
     */
    public function listByShipment(int $shipmentId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('shipment_id', $shipmentId)
            ->get();
    }

    /**
     * All shipment_items rows for a given order_item across all shipments.
     * Used to calculate already-dispatched qty for split-shipment validation.
     *
     * @return array<int, array>
     */
    public function listByOrderItem(int $orderItemId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('order_item_id', $orderItemId)
            ->get();
    }

    /**
     * Sum of qty already allocated into shipments for this order_item.
     * Counts all shipment_items rows, regardless of shipment status.
     */
    public function dispatchedQty(int $orderItemId): int
    {
        $rows = $this->connection->query(
            'SELECT COALESCE(SUM(qty), 0) AS total
             FROM shipment_items
             WHERE order_item_id = ?',
            [$orderItemId],
        );

        return (int) ($rows[0]['total'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Insert a single shipment item row.
     *
     * @return int  New row id
     */
    public function create(int $shipmentId, int $orderItemId, int $qty): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'shipment_id'   => $shipmentId,
                'order_item_id' => $orderItemId,
                'qty'           => $qty,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Bulk-insert items for a new shipment inside an already-open transaction.
     *
     * @param array<int, array{order_item_id: int, qty: int}> $items
     */
    public function bulkCreate(int $shipmentId, array $items): void
    {
        $now = gmdate('Y-m-d H:i:s');

        foreach ($items as $item) {
            (new QueryBuilder($this->connection))
                ->table(self::TABLE)
                ->insert([
                    'shipment_id'   => $shipmentId,
                    'order_item_id' => (int) $item['order_item_id'],
                    'qty'           => (int) $item['qty'],
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ]);
        }
    }
}
