<?php

declare(strict_types=1);

namespace App\Modules\Offers\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * OfferApproval model.
 *
 * Table: offer_approvals
 * APPEND-ONLY audit trail of approval decisions.
 * Maker-checker: approver_id must differ from offer creator (enforced at service layer).
 */
class OfferApproval
{
    private const TABLE = 'offer_approvals';

    private const COLUMNS = [
        'id', 'offer_id', 'approver_id', 'decision',
        'reason', 'decided_at', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function findByOfferId(int $offerId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('offer_id', $offerId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * Find the latest approval record for an offer.
     */
    public function findLatestForOffer(int $offerId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('offer_id', $offerId)
            ->orderBy('created_at', 'DESC')
            ->first();
    }

    /**
     * Check if a pending approval exists for this offer.
     */
    public function hasPendingApproval(int $offerId): bool
    {
        $result = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('offer_id', $offerId)
            ->where('decision', 'pending')
            ->count();

        return $result > 0;
    }

    // -------------------------------------------------------------------------
    // Mutations (append-only)
    // -------------------------------------------------------------------------

    /**
     * Create a new approval record (pending decision).
     */
    public function create(int $offerId, int $approverId): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'offer_id'    => $offerId,
                'approver_id' => $approverId,
                'decision'    => 'pending',
                'reason'      => null,
                'decided_at'  => null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Record a decision on an existing approval record.
     */
    public function recordDecision(int $approvalId, string $decision, ?string $reason = null): bool
    {
        if (!in_array($decision, ['approved', 'rejected'], true)) {
            return false;
        }

        $now = gmdate('Y-m-d H:i:s');

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $approvalId)
            ->where('decision', 'pending')
            ->update([
                'decision'   => $decision,
                'reason'     => $reason,
                'decided_at' => $now,
                'updated_at' => $now,
            ]);

        return $rows > 0;
    }
}
