<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * LedgerPosting model.
 *
 * Table: ledger_postings
 * APPEND-ONLY: rows are never updated or deleted.
 * CONSTRAINT: each entry must balance (sum debits = sum credits).
 *
 * All monetary values in integer paise.
 */
class LedgerPosting
{
    private const TABLE = 'ledger_postings';

    private const COLUMNS = [
        'id', 'entry_id', 'account_id', 'debit_paise', 'credit_paise', 'created_at',
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

    /**
     * Find all postings for a given entry.
     */
    public function findByEntryId(int $entryId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('entry_id', $entryId)
            ->orderBy('id', 'ASC')
            ->get();
    }

    /**
     * Create a posting row. APPEND-ONLY.
     *
     * @throws \InvalidArgumentException If both debit and credit are zero
     */
    public function create(int $entryId, int $accountId, int $debitPaise, int $creditPaise): int
    {
        if ($debitPaise < 0 || $creditPaise < 0) {
            throw new \InvalidArgumentException('Posting amounts must be non-negative');
        }

        if ($debitPaise === 0 && $creditPaise === 0) {
            throw new \InvalidArgumentException('At least one of debit_paise or credit_paise must be positive');
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'entry_id'     => $entryId,
                'account_id'   => $accountId,
                'debit_paise'  => $debitPaise,
                'credit_paise' => $creditPaise,
                'created_at'   => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Calculate the balance of an account (sum debits - sum credits).
     *
     * For ASSET/EXPENSE accounts, a positive balance is normal (debit-normal).
     * For LIABILITY/REVENUE/EQUITY accounts, the natural balance is credit-normal
     * (negative return here means credit balance).
     */
    public function getAccountBalance(int $accountId): int
    {
        $result = $this->connection->query(
            "SELECT COALESCE(SUM(debit_paise), 0) - COALESCE(SUM(credit_paise), 0) AS balance
             FROM {$this->getTable()}
             WHERE account_id = ?",
            [$accountId],
        );

        return (int) ($result[0]['balance'] ?? 0);
    }

    /**
     * Verify that all postings for a given entry balance.
     *
     * @return bool True if sum(debits) === sum(credits) for the entry
     */
    public function entryIsBalanced(int $entryId): bool
    {
        $result = $this->connection->query(
            "SELECT COALESCE(SUM(debit_paise), 0) AS total_debit,
                    COALESCE(SUM(credit_paise), 0) AS total_credit
             FROM {$this->getTable()}
             WHERE entry_id = ?",
            [$entryId],
        );

        if (empty($result)) {
            return false;
        }

        return (int) $result[0]['total_debit'] === (int) $result[0]['total_credit'];
    }

    private function getTable(): string
    {
        return self::TABLE;
    }
}
