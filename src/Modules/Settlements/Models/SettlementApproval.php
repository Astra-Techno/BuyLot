<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * SettlementApproval model.
 *
 * Table: settlement_approvals
 * Maker-checker: approver must differ from the preparer (the user who
 * created the settlement or last acted on it).
 */
class SettlementApproval
{
    private const TABLE = 'settlement_approvals';

    private const COLUMNS = [
        'id', 'settlement_id', 'approver_id', 'decision',
        'reason', 'decided_at', 'created_at', 'updated_at',
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
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    /**
     * Find the latest pending approval for a settlement.
     */
    public function findPendingForSettlement(int $settlementId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('settlement_id', $settlementId)
            ->where('decision', 'pending')
            ->orderBy('created_at', 'DESC')
            ->first();
    }

    /**
     * Create an approval request (pending).
     */
    public function create(int $settlementId, int $approverId): array
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'settlement_id' => $settlementId,
                'approver_id'   => $approverId,
                'decision'      => 'pending',
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? throw new \RuntimeException('Failed to create settlement approval');
    }

    /**
     * Record the approval decision.
     *
     * @throws \RuntimeException If approval is not in pending state
     */
    public function decide(int $id, string $decision, ?string $reason = null): bool
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("Decision must be 'approved' or 'rejected'");
        }

        $now = gmdate('Y-m-d H:i:s');

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('decision', 'pending') // Can only decide once
            ->update([
                'decision'   => $decision,
                'reason'     => $reason,
                'decided_at' => $now,
                'updated_at' => $now,
            ]);

        if ($rows === 0) {
            throw new \RuntimeException("Approval {$id} is not in pending state or does not exist");
        }

        return true;
    }
}
