<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * OrderStatusEvent model -- APPEND-ONLY audit log for order state transitions.
 *
 * Table: order_status_events
 *   id         BIGINT UNSIGNED AUTO_INCREMENT PK
 *   order_id   BIGINT UNSIGNED NOT NULL
 *   from_state VARCHAR(50) NULL (NULL for initial creation)
 *   to_state   VARCHAR(50) NOT NULL
 *   actor_id   BIGINT UNSIGNED NULL
 *   reason     VARCHAR(500) NULL
 *   created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
 *
 * No updated_at. No UPDATE or DELETE allowed.
 */
class OrderStatusEvent
{
    private const TABLE = 'order_status_events';

    private const PUBLIC_COLUMNS = [
        'id', 'order_id', 'from_state', 'to_state',
        'actor_id', 'reason', 'created_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('id', $id)
            ->first();
    }

    /**
     * Full audit trail for an order, oldest first.
     *
     * @return array<array>
     */
    public function listByOrder(int $orderId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('order_id', $orderId)
            ->orderBy('created_at', 'ASC')
            ->orderBy('id', 'ASC')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Mutations (append-only)
    // -------------------------------------------------------------------------

    /**
     * Record a state transition. This is append-only and must never be updated or deleted.
     */
    public function create(int $orderId, ?string $fromState, string $toState, ?int $actorId = null, ?string $reason = null): int
    {
        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'order_id'   => $orderId,
                'from_state' => $fromState,
                'to_state'   => $toState,
                'actor_id'   => $actorId,
                'reason'     => $reason,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return (int) $this->connection->lastInsertId();
    }
}
