<?php

declare(strict_types=1);

namespace App\Modules\Disputes\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * DisputeAdjustment model.
 *
 * Table: dispute_adjustments (Module 7, §7.4)
 *
 *   id            BIGINT UNSIGNED AUTO_INCREMENT PK
 *   dispute_id    BIGINT UNSIGNED NOT NULL
 *   type          ENUM('REFUND','REPLACEMENT','CREDIT','PARTIAL_REFUND') NOT NULL
 *   amount_paise  BIGINT NOT NULL DEFAULT 0  — 0 for non-financial (replacement)
 *   approved_by   BIGINT UNSIGNED NOT NULL   — finance approver; MUST differ from created_by
 *   created_by    BIGINT UNSIGNED NOT NULL   — support agent who proposed
 *   reason        TEXT NULL
 *   executed_at   TIMESTAMP NULL
 *   created_at    TIMESTAMP NOT NULL
 *   updated_at    TIMESTAMP NOT NULL
 *
 * Maker-checker rule (spec §9):
 *   For amounts above MAKER_CHECKER_THRESHOLD_PAISE the row must have
 *   approved_by != created_by before it can be executed.
 *   DisputeService enforces this at the application layer.
 *
 * All amounts are integer paise (1 INR = 100 paise).
 */
class DisputeAdjustment
{
    private const TABLE = 'dispute_adjustments';

    /**
     * Maker-checker threshold: adjustments at or above this amount (in paise)
     * require a separate approver to differ from the creator.
     * 500 INR = 50 000 paise; configurable via Config at runtime.
     */
    public const MAKER_CHECKER_THRESHOLD_PAISE = 50_000;

    // -------------------------------------------------------------------------
    // Adjustment type constants
    // -------------------------------------------------------------------------

    public const TYPE_REFUND         = 'REFUND';
    public const TYPE_REPLACEMENT    = 'REPLACEMENT';
    public const TYPE_CREDIT         = 'CREDIT';
    public const TYPE_PARTIAL_REFUND = 'PARTIAL_REFUND';

    public const ALL_TYPES = [
        self::TYPE_REFUND,
        self::TYPE_REPLACEMENT,
        self::TYPE_CREDIT,
        self::TYPE_PARTIAL_REFUND,
    ];

    private const PUBLIC_COLUMNS = [
        'id', 'dispute_id', 'type', 'amount_paise',
        'approved_by', 'created_by', 'reason',
        'executed_at', 'created_at', 'updated_at',
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
     * All adjustments for a dispute.
     *
     * @return array<array>
     */
    public function listByDispute(int $disputeId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('dispute_id', $disputeId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new adjustment record.
     *
     * NOTE: The caller (DisputeService::resolveDispute) MUST enforce
     * maker-checker: approved_by != created_by when amount_paise >= threshold.
     *
     * @param array{
     *   dispute_id: int,
     *   type: string,
     *   amount_paise: int,
     *   approved_by: int,
     *   created_by: int,
     *   reason?: string|null,
     * } $data
     * @throws \InvalidArgumentException if maker-checker rule is violated
     */
    public function create(array $data): int
    {
        $amountPaise = (int) $data['amount_paise'];
        $approvedBy  = (int) $data['approved_by'];
        $createdBy   = (int) $data['created_by'];

        if ($amountPaise >= self::MAKER_CHECKER_THRESHOLD_PAISE && $approvedBy === $createdBy) {
            throw new \InvalidArgumentException(
                'Maker-checker violation: approved_by must differ from created_by ' .
                'for adjustments at or above the financial threshold ' .
                '(' . self::MAKER_CHECKER_THRESHOLD_PAISE . ' paise).',
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'dispute_id'   => $data['dispute_id'],
                'type'         => $data['type'],
                'amount_paise' => $amountPaise,
                'approved_by'  => $approvedBy,
                'created_by'   => $createdBy,
                'reason'       => $data['reason'] ?? null,
                'executed_at'  => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Mark an adjustment as executed (financial action completed).
     */
    public function markExecuted(int $id): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('executed_at', null)   // idempotency guard
            ->update([
                'executed_at' => $now,
                'updated_at'  => $now,
            ]);

        return $rows > 0;
    }
}
