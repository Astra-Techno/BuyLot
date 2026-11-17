<?php

declare(strict_types=1);

namespace App\Modules\ProcurementCRM\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * ProcurementTask model.
 *
 * Maps to the `procurement_tasks` table (schema §3.10).
 *
 * Table columns:
 *   id          BIGINT UNSIGNED AUTO_INCREMENT PK
 *   case_id     BIGINT UNSIGNED NOT NULL  FK -> procurement_cases.id  (CASCADE)
 *   assignee_id BIGINT UNSIGNED NULL      FK -> users.id
 *   title       VARCHAR(500) NOT NULL
 *   due_at      TIMESTAMP NULL
 *   status      ENUM(open|in_progress|blocked|completed|cancelled)
 *   created_at  TIMESTAMP
 *   updated_at  TIMESTAMP
 *
 * Application status aliases:
 *   PENDING     -> open
 *   IN_PROGRESS -> in_progress
 *   COMPLETED   -> completed
 *   CANCELLED   -> cancelled
 */
class ProcurementTask
{
    private const TABLE = 'procurement_tasks';

    /** Application alias -> DB enum value. */
    private const STATUS_MAP = [
        'PENDING'     => 'open',
        'IN_PROGRESS' => 'in_progress',
        'COMPLETED'   => 'completed',
        'CANCELLED'   => 'cancelled',
        // Pass DB values through unchanged
        'open'        => 'open',
        'in_progress' => 'in_progress',
        'blocked'     => 'blocked',
        'completed'   => 'completed',
        'cancelled'   => 'cancelled',
    ];

    private const COLUMNS = [
        'id', 'case_id', 'assignee_id', 'title',
        'due_at', 'status', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Find a single task by ID.
     */
    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();
    }

    /**
     * List all tasks for a case.
     *
     * @return array<int, array>
     */
    public function listForCase(int $caseId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('case_id', $caseId)
            ->orderBy('due_at', 'ASC')
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new task on a procurement case.
     *
     * @param array{
     *     case_id: int,
     *     title: string,
     *     assignee_id?: int,
     *     due_at?: string,
     * } $data
     */
    public function create(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'case_id'     => (int) $data['case_id'],
                'assignee_id' => isset($data['assignee_id']) ? (int) $data['assignee_id'] : null,
                'title'       => $data['title'],
                'due_at'      => $data['due_at'] ?? null,
                'status'      => 'open',
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

        $id = $this->connection->lastInsertId();

        return $this->find((int) $id) ?? [];
    }

    /**
     * Mark a task as completed.
     */
    public function complete(int $id): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'status'     => 'completed',
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Update task status using application alias or raw DB value.
     */
    public function updateStatus(int $id, string $status): bool
    {
        $dbStatus = self::STATUS_MAP[strtoupper($status)]
            ?? self::STATUS_MAP[$status]
            ?? 'open';

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'status'     => $dbStatus,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }
}
