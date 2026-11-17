<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * SettlementItem model.
 *
 * Table: settlement_items
 * Links individual order items to a settlement with eligible quantities.
 * All monetary values in integer paise.
 */
class SettlementItem
{
    private const TABLE = 'settlement_items';

    private const COLUMNS = [
        'id', 'settlement_id', 'order_item_id', 'eligible_qty',
        'amount_paise', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();
    }

    public function findBySettlementId(int $settlementId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('settlement_id', $settlementId)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /**
     * Create a settlement item.
     *
     * @throws \InvalidArgumentException If qty or amount is invalid
     */
    public function create(
        int $settlementId,
        int $orderItemId,
        int $eligibleQty,
        int $amountPaise,
    ): array {
        if ($eligibleQty <= 0) {
            throw new \InvalidArgumentException('eligible_qty must be positive');
        }

        if ($amountPaise < 0) {
            throw new \InvalidArgumentException('amount_paise must be non-negative');
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'settlement_id' => $settlementId,
                'order_item_id' => $orderItemId,
                'eligible_qty'  => $eligibleQty,
                'amount_paise'  => $amountPaise,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? throw new \RuntimeException('Failed to create settlement item');
    }

    /**
     * Calculate total amount for a settlement.
     */
    public function getTotalForSettlement(int $settlementId): int
    {
        $result = $this->connection->query(
            'SELECT COALESCE(SUM(amount_paise), 0) AS total FROM settlement_items WHERE settlement_id = ?',
            [$settlementId],
        );

        return (int) ($result[0]['total'] ?? 0);
    }
}
