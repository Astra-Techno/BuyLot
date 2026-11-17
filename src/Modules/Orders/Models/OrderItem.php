<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * OrderItem model.
 *
 * Table: order_items
 *   id               BIGINT UNSIGNED AUTO_INCREMENT PK
 *   order_id         BIGINT UNSIGNED NOT NULL
 *   variant_id       BIGINT UNSIGNED NOT NULL
 *   qty              INT UNSIGNED NOT NULL
 *   unit_price_paise BIGINT NOT NULL
 *   tax_paise        BIGINT NOT NULL DEFAULT 0
 *   shipping_paise   BIGINT NOT NULL DEFAULT 0
 *   snapshot_json    JSON NOT NULL  -- immutable price snapshot at checkout
 *   created_at       TIMESTAMP
 *   updated_at       TIMESTAMP
 */
class OrderItem
{
    private const TABLE = 'order_items';

    private const PUBLIC_COLUMNS = [
        'id', 'order_id', 'variant_id', 'qty',
        'unit_price_paise', 'tax_paise', 'shipping_paise',
        'snapshot_json', 'created_at', 'updated_at',
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
     * @return array<array>
     */
    public function listByOrder(int $orderId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('order_id', $orderId)
            ->orderBy('id', 'ASC')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create an order item with an immutable price snapshot.
     *
     * @param array{order_id: int, variant_id: int, qty: int, unit_price_paise: int, tax_paise: int, shipping_paise: int, snapshot_json: string} $data
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'order_id'         => $data['order_id'],
                'variant_id'       => $data['variant_id'],
                'qty'              => $data['qty'],
                'unit_price_paise' => $data['unit_price_paise'],
                'tax_paise'        => $data['tax_paise'],
                'shipping_paise'   => $data['shipping_paise'],
                'snapshot_json'    => $data['snapshot_json'],
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Calculate total paise for all items in an order.
     */
    public function calculateOrderTotal(int $orderId): int
    {
        $result = $this->connection->query(
            'SELECT COALESCE(SUM((unit_price_paise + tax_paise + shipping_paise) * qty), 0) AS total
             FROM order_items
             WHERE order_id = ?',
            [$orderId],
        );

        return (int) ($result[0]['total'] ?? 0);
    }
}
