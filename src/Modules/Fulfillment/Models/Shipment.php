<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * Shipment model.
 *
 * Maps the `shipments` table (schema 6.1).
 *
 * State machine (section 8 of state-machines.md):
 *   ready_to_dispatch -> dispatched -> in_transit -> delivered_reported
 *   -> delivery_verified -> completed | exception
 *
 * DB status enum uses the platform's existing values:
 *   pending | picked_up | in_transit | out_for_delivery | delivered
 *   | failed_delivery | returned
 *
 * The service layer maps domain events onto these DB values.
 */
class Shipment
{
    private const TABLE = 'shipments';

    /** Valid status values as stored in the DB */
    public const STATUS_PENDING          = 'pending';
    public const STATUS_PICKED_UP        = 'picked_up';
    public const STATUS_IN_TRANSIT       = 'in_transit';
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_DELIVERED        = 'delivered';
    public const STATUS_FAILED_DELIVERY  = 'failed_delivery';
    public const STATUS_RETURNED         = 'returned';

    /**
     * Allowed forward transitions: from -> [allowed to states].
     * Guards are enforced in FulfillmentService; this table prevents impossible writes.
     */
    private const TRANSITIONS = [
        self::STATUS_PENDING          => [self::STATUS_PICKED_UP, self::STATUS_IN_TRANSIT],
        self::STATUS_PICKED_UP        => [self::STATUS_IN_TRANSIT, self::STATUS_FAILED_DELIVERY],
        self::STATUS_IN_TRANSIT       => [self::STATUS_OUT_FOR_DELIVERY, self::STATUS_DELIVERED, self::STATUS_FAILED_DELIVERY],
        self::STATUS_OUT_FOR_DELIVERY => [self::STATUS_DELIVERED, self::STATUS_FAILED_DELIVERY],
        self::STATUS_DELIVERED        => [], // terminal for carrier; delivery_verified handled via delivery_evidence
        self::STATUS_FAILED_DELIVERY  => [self::STATUS_IN_TRANSIT, self::STATUS_RETURNED],
        self::STATUS_RETURNED         => [],
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->first();
    }

    /**
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE);

        if (!empty($filters['status'])) {
            $qb->where('status', $filters['status']);
        }

        if (!empty($filters['seller_fulfillment_order_id'])) {
            $qb->where('seller_fulfillment_order_id', (int) $filters['seller_fulfillment_order_id']);
        }

        $total = (clone $qb)->count();

        $rows = $qb
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Find all shipments for a given seller_fulfillment_order.
     *
     * @return array<int, array>
     */
    public function listByFulfillmentOrder(int $fulfillmentOrderId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('seller_fulfillment_order_id', $fulfillmentOrderId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * Find shipment(s) for an order via the seller_fulfillment_orders join.
     * Returns the first matching shipment for the given order_id.
     */
    public function findByOrderId(int $orderId): ?array
    {
        $rows = $this->connection->query(
            'SELECT s.*
             FROM shipments s
             JOIN seller_fulfillment_orders sfo ON sfo.id = s.seller_fulfillment_order_id
             JOIN orders o ON o.offer_id = sfo.offer_id AND o.id = ?
             ORDER BY s.created_at ASC
             LIMIT 1',
            [$orderId],
        );

        return $rows[0] ?? null;
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a shipment row in `pending` status.
     *
     * @return int  New shipment id
     */
    public function create(int $fulfillmentOrderId, ?string $carrier = null, ?string $trackingRef = null): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'seller_fulfillment_order_id' => $fulfillmentOrderId,
                'carrier'                     => $carrier,
                'tracking_ref'                => $trackingRef,
                'status'                      => self::STATUS_PENDING,
                'created_at'                  => $now,
                'updated_at'                  => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Transition status after validating the state machine.
     *
     * @throws \LogicException on invalid transition
     */
    public function transition(int $id, string $toStatus): bool
    {
        $shipment = $this->find($id);

        if ($shipment === null) {
            throw new \LogicException("Shipment {$id} not found");
        }

        $fromStatus = $shipment['status'];
        $allowed    = self::TRANSITIONS[$fromStatus] ?? [];

        if (!in_array($toStatus, $allowed, true)) {
            throw new \LogicException(
                "Invalid shipment transition: {$fromStatus} -> {$toStatus}",
            );
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'status'     => $toStatus,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Update carrier and tracking reference (seller can call before or after dispatch).
     */
    public function updateTracking(int $id, string $carrier, string $trackingRef): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'carrier'      => $carrier,
                'tracking_ref' => $trackingRef,
                'updated_at'   => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Record actual delivery timestamp and transition to delivered.
     */
    public function markDelivered(int $id): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'status'            => self::STATUS_DELIVERED,
                'actual_delivery_at' => $now,
                'updated_at'        => $now,
            ]);

        return $rows > 0;
    }
}
