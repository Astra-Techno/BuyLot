<?php

declare(strict_types=1);

namespace App\Modules\ProcurementCRM\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * ProcurementCase model.
 *
 * Maps to the `procurement_cases` table (schema §3.5).
 *
 * Table columns:
 *   id                BIGINT UNSIGNED AUTO_INCREMENT PK
 *   rfq_id            BIGINT UNSIGNED NOT NULL  FK -> rfqs.id
 *   assigned_agent_id BIGINT UNSIGNED NULL       FK -> users.id
 *   priority          TINYINT UNSIGNED NOT NULL  1=URGENT 2=HIGH 3=MEDIUM 4=LOW
 *   stage             ENUM(sourcing|bids_received|normalisation|negotiation|
 *                          approval_pending|offer_published|closed_awarded|closed_no_deal)
 *   sla_due_at        TIMESTAMP NULL
 *   created_at        TIMESTAMP
 *   updated_at        TIMESTAMP
 *
 * Priority mapping (application layer):
 *   1 => URGENT  2 => HIGH  3 => MEDIUM  4 => LOW
 */
class ProcurementCase
{
    private const TABLE = 'procurement_cases';

    /** Canonical priority labels indexed by DB integer value. */
    public const PRIORITIES = [
        1 => 'URGENT',
        2 => 'HIGH',
        3 => 'MEDIUM',
        4 => 'LOW',
    ];

    /** Reverse map: label -> DB integer. */
    private const PRIORITY_MAP = [
        'URGENT' => 1,
        'HIGH'   => 2,
        'MEDIUM' => 3,
        'LOW'    => 4,
    ];

    /** Valid stage values as defined in the DB ENUM. */
    public const STAGES = [
        'NEW'           => 'sourcing',          // alias used by application
        'SOURCING'      => 'sourcing',
        'QUOTING'       => 'bids_received',
        'NEGOTIATING'   => 'negotiation',
        'OFFER_DRAFT'   => 'approval_pending',
        'OFFER_SENT'    => 'offer_published',
        'CLOSED_WON'    => 'closed_awarded',
        'CLOSED_LOST'   => 'closed_no_deal',
        // Normalisation is an internal DB stage surfaced verbatim when needed
        'NORMALISATION' => 'normalisation',
    ];

    private const COLUMNS = [
        'id', 'rfq_id', 'assigned_agent_id', 'priority',
        'stage', 'sla_due_at', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Find a single procurement case by ID.
     */
    public function find(int $id): ?array
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();

        return $row !== null ? $this->decorate($row) : null;
    }

    /**
     * List cases assigned to a specific agent, newest first.
     *
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listByAgent(int $agentId, int $page = 1, int $perPage = 20): array
    {
        return $this->paginatedList(['agent' => $agentId], $page, $perPage);
    }

    /**
     * List cases filtered by a priority label (URGENT|HIGH|MEDIUM|LOW).
     *
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listByPriority(string $priority, int $page = 1, int $perPage = 20): array
    {
        return $this->paginatedList(['priority' => $priority], $page, $perPage);
    }

    /**
     * Return all cases whose sla_due_at is in the past and not yet closed.
     *
     * @return array<int, array>
     */
    public function getOverdue(): array
    {
        $now  = gmdate('Y-m-d H:i:s');
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('sla_due_at', '<', $now)
            ->where('stage', '!=', 'closed_awarded')
            ->where('stage', '!=', 'closed_no_deal')
            ->orderBy('sla_due_at', 'ASC')
            ->get();

        return array_map(fn(array $r) => $this->decorate($r), $rows);
    }

    /**
     * Paginated list with optional filters.
     *
     * Supported filter keys:
     *   priority  string  URGENT|HIGH|MEDIUM|LOW
     *   stage     string  DB enum value or application alias
     *   agent     int     assigned_agent_id
     *   overdue   bool    only overdue cases
     *
     * @param array<string, mixed> $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function paginatedList(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS);

        if (!empty($filters['priority'])) {
            $dbPriority = self::PRIORITY_MAP[strtoupper($filters['priority'])] ?? null;
            if ($dbPriority !== null) {
                $qb->where('priority', $dbPriority);
            }
        }

        if (!empty($filters['stage'])) {
            $dbStage = self::STAGES[strtoupper($filters['stage'])] ?? $filters['stage'];
            $qb->where('stage', $dbStage);
        }

        if (!empty($filters['agent'])) {
            $qb->where('assigned_agent_id', (int) $filters['agent']);
        }

        if (!empty($filters['overdue'])) {
            $qb->where('sla_due_at', '<', gmdate('Y-m-d H:i:s'));
        }

        $total = (clone $qb)->count();

        $rows = $qb
            ->orderBy('priority', 'ASC')
            ->orderBy('sla_due_at', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => array_map(fn(array $r) => $this->decorate($r), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new procurement case linked to an RFQ.
     *
     * @param array{rfq_id: int, priority?: string, sla_due_at?: string} $data
     */
    public function create(array $data): array
    {
        $priorityLabel = strtoupper($data['priority'] ?? 'MEDIUM');
        $dbPriority    = self::PRIORITY_MAP[$priorityLabel] ?? self::PRIORITY_MAP['MEDIUM'];
        $now           = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'rfq_id'            => (int) $data['rfq_id'],
                'assigned_agent_id' => isset($data['assigned_agent_id'])
                    ? (int) $data['assigned_agent_id']
                    : null,
                'priority'          => $dbPriority,
                'stage'             => 'sourcing',
                'sla_due_at'        => $data['sla_due_at'] ?? null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

        $id = $this->connection->lastInsertId();

        return $this->find((int) $id) ?? [];
    }

    /**
     * Assign a case to an agent. Returns true when a row was updated.
     */
    public function assign(int $id, int $agentId): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'assigned_agent_id' => $agentId,
                'updated_at'        => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Update the SLA deadline for a case.
     */
    public function updateSlaDue(int $id, string $slaDueAt): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'sla_due_at' => $slaDueAt,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Advance or change the pipeline stage.
     *
     * $stage may be an application alias (e.g. NEGOTIATING) or a raw DB enum value.
     */
    public function updateStage(int $id, string $stage): bool
    {
        $dbStage = self::STAGES[strtoupper($stage)] ?? $stage;

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'stage'      => $dbStage,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Add computed/display fields to a raw DB row.
     */
    private function decorate(array $row): array
    {
        $row['priority_label'] = self::PRIORITIES[(int) $row['priority']] ?? 'UNKNOWN';
        return $row;
    }
}
