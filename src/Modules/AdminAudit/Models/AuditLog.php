<?php

declare(strict_types=1);

namespace App\Modules\AdminAudit\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use LogicException;

/**
 * AuditLog — APPEND-ONLY model.
 *
 * This model deliberately exposes NO update() or delete() methods.
 * Any attempt to call a mutating method other than insert() will
 * throw a LogicException at runtime (enforced by the model itself).
 *
 * Database schema reference: audit_logs (Module 7, section 7.7)
 */
class AuditLog
{
    public const ACTOR_TYPE_USER    = 'USER';
    public const ACTOR_TYPE_SYSTEM  = 'SYSTEM';
    public const ACTOR_TYPE_WEBHOOK = 'WEBHOOK';

    public const VALID_ACTOR_TYPES = [
        self::ACTOR_TYPE_USER,
        self::ACTOR_TYPE_SYSTEM,
        self::ACTOR_TYPE_WEBHOOK,
    ];

    private const TABLE = 'audit_logs';

    public function __construct(private readonly Connection $connection) {}

    // -------------------------------------------------------------------------
    // Write — append only
    // -------------------------------------------------------------------------

    /**
     * Insert a new audit log entry.
     * Returns the newly created row id.
     */
    public function insert(array $data): int
    {
        $qb = new QueryBuilder($this->connection);
        $qb->table(self::TABLE)->insert([
            'actor_id'    => $data['actor_id'] ?? null,
            'actor_role'  => $data['actor_role'] ?? null,
            'action'      => $data['action'],
            'entity_type' => $data['entity_type'] ?? null,
            'entity_id'   => $data['entity_id'] ?? null,
            'before_json' => isset($data['before_json'])
                ? (is_string($data['before_json']) ? $data['before_json'] : json_encode($data['before_json']))
                : null,
            'after_json'  => isset($data['after_json'])
                ? (is_string($data['after_json']) ? $data['after_json'] : json_encode($data['after_json']))
                : null,
            'ip_address'  => $data['ip_address'] ?? null,
            'user_agent'  => $data['user_agent'] ?? null,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public function findById(int $id): ?array
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('id', $id)
            ->first();
    }

    /**
     * Query audit logs with optional filters and pagination.
     *
     * Supported filters:
     *   actor_id      int
     *   actor_type    string   (USER|SYSTEM|WEBHOOK — matched against actor_role prefix convention)
     *   action        string   (exact match)
     *   entity_type   string
     *   entity_id     int
     *   date_from     string   Y-m-d H:i:s
     *   date_to       string   Y-m-d H:i:s
     *   limit         int      default 50, max 200
     *   offset        int      default 0
     *
     * @return array{logs: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function query(array $filters = []): array
    {
        $limit  = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $countQb = new QueryBuilder($this->connection);
        $countQb->table(self::TABLE);

        $listQb = new QueryBuilder($this->connection);
        $listQb->table(self::TABLE);

        $this->applyFilters($countQb, $filters);
        $this->applyFilters($listQb, $filters);

        $total = $countQb->count();

        $logs = $listQb
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();

        return [
            'logs'   => $logs,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    // -------------------------------------------------------------------------
    // Immutability guards
    // -------------------------------------------------------------------------

    /**
     * Update is PROHIBITED on audit logs.
     * @throws LogicException always
     */
    public function update(): never
    {
        throw new LogicException(
            'AuditLog records are immutable. UPDATE operations are not permitted on audit_logs.',
        );
    }

    /**
     * Delete is PROHIBITED on audit logs.
     * @throws LogicException always
     */
    public function delete(): never
    {
        throw new LogicException(
            'AuditLog records are immutable. DELETE operations are not permitted on audit_logs.',
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['actor_id'])) {
            $qb->where('actor_id', (int) $filters['actor_id']);
        }

        if (!empty($filters['action'])) {
            $qb->where('action', $filters['action']);
        }

        if (!empty($filters['entity_type'])) {
            $qb->where('entity_type', $filters['entity_type']);
        }

        if (!empty($filters['entity_id'])) {
            $qb->where('entity_id', (int) $filters['entity_id']);
        }

        if (!empty($filters['date_from'])) {
            $qb->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $qb->where('created_at', '<=', $filters['date_to']);
        }
    }
}
