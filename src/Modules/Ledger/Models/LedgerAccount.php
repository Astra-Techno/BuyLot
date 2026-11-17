<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * LedgerAccount model.
 *
 * Table: ledger_accounts
 * Types: asset, liability, revenue, expense, equity
 *
 * Standard accounts:
 *   BUYER_PAYABLE, GATEWAY_HOLDING, SELLER_PAYABLE, PLATFORM_COMMISSION,
 *   TAX_COLLECTED, REFUND_EXPENSE, GATEWAY_FEE
 */
class LedgerAccount
{
    private const TABLE = 'ledger_accounts';

    private const COLUMNS = [
        'id', 'code', 'name', 'type', 'is_active', 'created_at', 'updated_at',
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

    public function findByCode(string $code): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('code', $code)
            ->first();
    }

    /**
     * Get or create a ledger account by code.
     */
    public function findOrCreate(string $code, string $name, string $type): array
    {
        $existing = $this->findByCode($code);
        if ($existing !== null) {
            return $existing;
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'code'       => $code,
                'name'       => $name,
                'type'       => $type,
                'is_active'  => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? throw new \RuntimeException("Failed to create ledger account: {$code}");
    }

    /**
     * List all active ledger accounts.
     */
    public function listActive(): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('is_active', 1)
            ->orderBy('code', 'ASC')
            ->get();
    }
}
