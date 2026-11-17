<?php

declare(strict_types=1);

namespace App\Modules\Disputes\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * DisputeEvent model — APPEND-ONLY audit trail.
 *
 * Table: dispute_events (Module 7, §7.2)
 *
 *   id          BIGINT UNSIGNED AUTO_INCREMENT PK
 *   dispute_id  BIGINT UNSIGNED NOT NULL
 *   type        ENUM('OPENED','ASSIGNED','EVIDENCE_REQUESTED','EVIDENCE_SUBMITTED',
 *                    'INVESTIGATION_STARTED','RESOLUTION_PROPOSED','ACCEPTED',
 *                    'REJECTED','APPEALED','ESCALATED','CLOSED') NOT NULL
 *   actor_id    BIGINT UNSIGNED NULL   — NULL for system events
 *   notes       TEXT NULL
 *   created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
 *
 * INVARIANT: Rows are NEVER updated or deleted.
 * Every state transition in DisputeService MUST append a row here.
 */
class DisputeEvent
{
    private const TABLE = 'dispute_events';

    // -------------------------------------------------------------------------
    // Event type constants
    // -------------------------------------------------------------------------

    public const TYPE_OPENED               = 'OPENED';
    public const TYPE_ASSIGNED             = 'ASSIGNED';
    public const TYPE_EVIDENCE_REQUESTED   = 'EVIDENCE_REQUESTED';
    public const TYPE_EVIDENCE_SUBMITTED   = 'EVIDENCE_SUBMITTED';
    public const TYPE_INVESTIGATION_STARTED = 'INVESTIGATION_STARTED';
    public const TYPE_RESOLUTION_PROPOSED  = 'RESOLUTION_PROPOSED';
    public const TYPE_ACCEPTED             = 'ACCEPTED';
    public const TYPE_REJECTED             = 'REJECTED';
    public const TYPE_APPEALED             = 'APPEALED';
    public const TYPE_ESCALATED            = 'ESCALATED';
    public const TYPE_CLOSED               = 'CLOSED';

    public const ALL_TYPES = [
        self::TYPE_OPENED,
        self::TYPE_ASSIGNED,
        self::TYPE_EVIDENCE_REQUESTED,
        self::TYPE_EVIDENCE_SUBMITTED,
        self::TYPE_INVESTIGATION_STARTED,
        self::TYPE_RESOLUTION_PROPOSED,
        self::TYPE_ACCEPTED,
        self::TYPE_REJECTED,
        self::TYPE_APPEALED,
        self::TYPE_ESCALATED,
        self::TYPE_CLOSED,
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * All events for a dispute, chronologically ascending.
     *
     * @return array<array>
     */
    public function listByDispute(int $disputeId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select('id', 'dispute_id', 'type', 'actor_id', 'notes', 'created_at')
            ->where('dispute_id', $disputeId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Mutations — append-only
    // -------------------------------------------------------------------------

    /**
     * Append a new event to the dispute timeline.
     *
     * @param array{
     *   dispute_id: int,
     *   type: string,
     *   actor_id: int|null,
     *   notes?: string|null,
     * } $data
     */
    public function append(array $data): int
    {
        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'dispute_id' => $data['dispute_id'],
                'type'       => $data['type'],
                'actor_id'   => $data['actor_id'] ?? null,
                'notes'      => $data['notes'] ?? null,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return (int) $this->connection->lastInsertId();
    }
}
