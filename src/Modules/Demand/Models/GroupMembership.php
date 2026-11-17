<?php

declare(strict_types=1);

namespace App\Modules\Demand\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * GroupMembership model.
 *
 * Table: group_memberships
 *
 * Tracks a buyer's participation in a demand group and their current intent level.
 *
 * Intent levels (spec 4.2):
 *   L1 = Interested (OTP-verified interest)
 *   L2 = Purchase-ready (reconfirmed readiness)
 *   L3 = Offer accepted (agreed to specific terms)
 *   L4 = Payment confirmed (paid; counts toward MOQ)
 *   L5 = Order released (fulfillment commitment)
 *
 * Note: L0 (browse) has no membership record.
 */
class GroupMembership
{
    private const TABLE = 'group_memberships';

    // Intent levels per spec 4.2
    public const LEVEL_INTERESTED     = 1; // L1
    public const LEVEL_PURCHASE_READY = 2; // L2
    public const LEVEL_OFFER_ACCEPTED = 3; // L3
    public const LEVEL_PAYMENT_CONFIRMED = 4; // L4
    public const LEVEL_ORDER_RELEASED = 5; // L5

    public const LEVEL_MIN = 1;
    public const LEVEL_MAX = 5;

    private const PUBLIC_COLUMNS = [
        'id', 'group_id', 'buyer_id', 'request_id', 'qty',
        'intent_level', 'confirmed_at', 'created_at', 'updated_at',
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
     * Find membership by group + buyer (unique constraint).
     */
    public function findByGroupAndBuyer(int $groupId, int $buyerId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('group_id', $groupId)
            ->where('buyer_id', $buyerId)
            ->first();
    }

    /**
     * Get all members of a group.
     */
    public function getMembers(int $groupId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('group_id', $groupId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * Get members at or above a specific intent level.
     */
    public function getMembersByLevel(int $groupId, int $minLevel): array
    {
        $sql = "SELECT " . implode(', ', self::PUBLIC_COLUMNS)
             . " FROM " . self::TABLE
             . " WHERE group_id = ? AND intent_level >= ?"
             . " ORDER BY intent_level DESC, created_at ASC";

        return $this->connection->query($sql, [$groupId, $minLevel]);
    }

    /**
     * Count members at each intent level for a group.
     *
     * @return array<int, array{level: int, member_count: int, total_qty: int}>
     */
    public function countByLevel(int $groupId): array
    {
        $sql = "SELECT
                    intent_level as level,
                    COUNT(*) as member_count,
                    COALESCE(SUM(qty), 0) as total_qty
                FROM " . self::TABLE . "
                WHERE group_id = ?
                GROUP BY intent_level
                ORDER BY intent_level ASC";

        return $this->connection->query($sql, [$groupId]);
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Join a demand group (creates membership at L1).
     * Deduplication: unique constraint on (group_id, buyer_id).
     *
     * @param array{group_id: int, buyer_id: int, request_id: ?int, qty: int} $data
     * @throws \RuntimeException If buyer already a member of this group.
     */
    public function join(array $data): array
    {
        // Check for existing membership (deduplication per spec 4.2)
        $existing = $this->findByGroupAndBuyer($data['group_id'], $data['buyer_id']);
        if ($existing !== null) {
            throw new \RuntimeException(
                "Buyer {$data['buyer_id']} is already a member of group {$data['group_id']}"
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'group_id'     => $data['group_id'],
                'buyer_id'     => $data['buyer_id'],
                'request_id'   => $data['request_id'] ?? null,
                'qty'          => $data['qty'],
                'intent_level' => self::LEVEL_INTERESTED,
                'confirmed_at' => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? ['id' => $id];
    }

    /**
     * Update intent level for a membership.
     * Uses optimistic locking on current intent_level.
     *
     * @throws \InvalidArgumentException If the transition is not valid.
     */
    public function updateIntent(int $membershipId, int $newLevel): bool
    {
        $membership = $this->find($membershipId);

        if ($membership === null) {
            throw new \InvalidArgumentException("Membership {$membershipId} not found");
        }

        $currentLevel = (int) $membership['intent_level'];

        if (!$this->isValidIntentTransition($currentLevel, $newLevel)) {
            throw new \InvalidArgumentException(
                "Invalid intent transition: L{$currentLevel} -> L{$newLevel}"
            );
        }

        $updateData = [
            'intent_level' => $newLevel,
            'updated_at'   => gmdate('Y-m-d H:i:s'),
        ];

        // Set confirmed_at when reaching L2 (purchase-ready)
        if ($newLevel === self::LEVEL_PURCHASE_READY && $membership['confirmed_at'] === null) {
            $updateData['confirmed_at'] = gmdate('Y-m-d H:i:s');
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $membershipId)
            ->where('intent_level', $currentLevel) // Optimistic lock
            ->update($updateData);

        return $rows > 0;
    }

    /**
     * Remove a buyer from a group (leave).
     * Only allowed if no payment captured (L3 or below).
     *
     * @throws \InvalidArgumentException If buyer has paid (L4+).
     */
    public function leave(int $membershipId): bool
    {
        $membership = $this->find($membershipId);

        if ($membership === null) {
            throw new \InvalidArgumentException("Membership {$membershipId} not found");
        }

        $currentLevel = (int) $membership['intent_level'];

        if ($currentLevel >= self::LEVEL_PAYMENT_CONFIRMED) {
            throw new \InvalidArgumentException(
                "Cannot leave group after payment confirmation (L4+). Use refund workflow."
            );
        }

        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $membershipId)
            ->delete() > 0;
    }

    // -------------------------------------------------------------------------
    // Intent Transition Validation
    // -------------------------------------------------------------------------

    /**
     * Valid intent transitions.
     *
     * Forward: L1->L2->L3->L4->L5 (sequential, no skipping)
     * Backward (withdrawals): L1->remove, L2->L1, L3->L2
     * L4+ requires refund workflow (not a simple downgrade).
     */
    public function isValidIntentTransition(int $from, int $to): bool
    {
        // Forward: must be exactly one level up (no skipping)
        if ($to === $from + 1 && $to >= self::LEVEL_MIN && $to <= self::LEVEL_MAX) {
            return true;
        }

        // Backward (withdrawal/downgrade): only one level down, and only L2->L1 or L3->L2
        if ($to === $from - 1 && $from <= self::LEVEL_OFFER_ACCEPTED && $to >= self::LEVEL_INTERESTED) {
            return true;
        }

        return false;
    }
}
