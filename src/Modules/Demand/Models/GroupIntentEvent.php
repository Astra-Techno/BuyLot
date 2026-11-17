<?php

declare(strict_types=1);

namespace App\Modules\Demand\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * GroupIntentEvent model.
 *
 * Table: group_intent_events
 *
 * APPEND-ONLY: This table is an immutable audit log.
 * No UPDATE or DELETE operations are permitted.
 *
 * Records every intent level change for group memberships.
 * Each row captures: who changed, from what level, to what level, and why.
 */
class GroupIntentEvent
{
    private const TABLE = 'group_intent_events';

    private const COLUMNS = [
        'id', 'membership_id', 'from_level', 'to_level',
        'reason', 'actor_id', 'created_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders (read-only)
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();
    }

    /**
     * Get all intent events for a membership, in chronological order.
     */
    public function listByMembership(int $membershipId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('membership_id', $membershipId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * Get all intent events for a group (via membership IDs).
     * Useful for audit and analytics.
     */
    public function listByGroup(int $groupId): array
    {
        $sql = "SELECT gie." . implode(', gie.', self::COLUMNS)
             . " FROM " . self::TABLE . " gie"
             . " INNER JOIN group_memberships gm ON gm.id = gie.membership_id"
             . " WHERE gm.group_id = ?"
             . " ORDER BY gie.created_at ASC";

        return $this->connection->query($sql, [$groupId]);
    }

    /**
     * Count transitions to a specific level for a group.
     */
    public function countTransitionsToLevel(int $groupId, int $toLevel): int
    {
        $sql = "SELECT COUNT(*) as count"
             . " FROM " . self::TABLE . " gie"
             . " INNER JOIN group_memberships gm ON gm.id = gie.membership_id"
             . " WHERE gm.group_id = ? AND gie.to_level = ?";

        $result = $this->connection->query($sql, [$groupId, $toLevel]);

        return (int) ($result[0]['count'] ?? 0);
    }

    // -------------------------------------------------------------------------
    // Append (write-once)
    // -------------------------------------------------------------------------

    /**
     * Record an intent level change event.
     * This is the ONLY write operation allowed on this table.
     *
     * @param array{membership_id: int, from_level: int, to_level: int, reason: ?string, actor_id: ?int} $data
     */
    public function record(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'membership_id' => $data['membership_id'],
                'from_level'    => $data['from_level'],
                'to_level'      => $data['to_level'],
                'reason'        => $data['reason'] ?? null,
                'actor_id'      => $data['actor_id'] ?? null,
                'created_at'    => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? ['id' => $id];
    }
}
