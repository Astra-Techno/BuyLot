<?php

declare(strict_types=1);

namespace App\Modules\AdminAudit\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * RiskFlag model.
 *
 * Maps the `risk_flags` table (Module 7, section 7.8).
 *
 * Schema columns:
 *   id           BIGINT UNSIGNED  PK
 *   entity_type  VARCHAR(50)      e.g. user, seller_profile, order
 *   entity_id    BIGINT UNSIGNED  polymorphic FK (enforced at app layer)
 *   flag_type    VARCHAR(100)     FRAUD_SUSPECTED | HIGH_CHARGEBACK | FAKE_ACCOUNT | COLLUSION | COUNTERFEIT
 *   severity     ENUM(low, medium, high, critical)   DEFAULT medium
 *   status       ENUM(open, investigating, resolved, false_positive)  DEFAULT open
 *   raised_by    BIGINT UNSIGNED NULL   FK -> users.id (NULL = system-generated)
 *   resolved_by  BIGINT UNSIGNED NULL   FK -> users.id
 *   resolved_at  TIMESTAMP NULL
 *   notes        TEXT NULL
 *   created_at   TIMESTAMP
 *   updated_at   TIMESTAMP
 */
class RiskFlag
{
    public const TABLE = 'risk_flags';

    // Flag types (spec names kept uppercase for readability; stored as-is)
    public const FLAG_FRAUD_SUSPECTED  = 'FRAUD_SUSPECTED';
    public const FLAG_HIGH_CHARGEBACK  = 'HIGH_CHARGEBACK';
    public const FLAG_FAKE_ACCOUNT     = 'FAKE_ACCOUNT';
    public const FLAG_COLLUSION        = 'COLLUSION';
    public const FLAG_COUNTERFEIT      = 'COUNTERFEIT';

    public const VALID_FLAG_TYPES = [
        self::FLAG_FRAUD_SUSPECTED,
        self::FLAG_HIGH_CHARGEBACK,
        self::FLAG_FAKE_ACCOUNT,
        self::FLAG_COLLUSION,
        self::FLAG_COUNTERFEIT,
    ];

    // Severity levels (match DB ENUM values)
    public const SEVERITY_LOW      = 'low';
    public const SEVERITY_MEDIUM   = 'medium';
    public const SEVERITY_HIGH     = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    public const VALID_SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    // Status values (match DB ENUM values)
    public const STATUS_OPEN          = 'open';
    public const STATUS_INVESTIGATING = 'investigating';
    public const STATUS_RESOLVED      = 'resolved';
    public const STATUS_FALSE_POSITIVE = 'false_positive';

    public const VALID_STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_INVESTIGATING,
        self::STATUS_RESOLVED,
        self::STATUS_FALSE_POSITIVE,
    ];

    public function __construct(private readonly Connection $connection) {}

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Create a new risk flag. Returns the new row id.
     *
     * @param array{
     *   entity_type: string,
     *   entity_id:   int,
     *   flag_type:   string,
     *   severity:    string,
     *   raised_by?:  int|null,
     *   notes?:      string|null,
     * } $data
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'entity_type' => $data['entity_type'],
                'entity_id'   => $data['entity_id'],
                'flag_type'   => $data['flag_type'],
                'severity'    => $data['severity'],
                'status'      => self::STATUS_OPEN,
                'raised_by'   => $data['raised_by'] ?? null,
                'resolved_by' => null,
                'resolved_at' => null,
                'notes'       => $data['notes'] ?? null,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Resolve (or dismiss) a risk flag.
     *
     * @param array{
     *   status:      string,
     *   resolved_by: int,
     *   notes?:      string|null,
     * } $data
     */
    public function resolve(int $id, array $data): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'status'      => $data['status'],
                'resolved_by' => $data['resolved_by'],
                'resolved_at' => $now,
                'notes'       => $data['notes'] ?? null,
                'updated_at'  => $now,
            ]);

        return $rows > 0;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public function findById(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->first();
    }

    /**
     * Paginated list with filters.
     *
     * @param array{
     *   entity_type?: string,
     *   entity_id?:   int,
     *   flag_type?:   string,
     *   severity?:    string,
     *   status?:      string,
     * } $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE);

        $this->applyFilters($qb, $filters);

        $total = (clone $qb)->count();

        $rows = $qb
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function applyFilters(QueryBuilder $qb, array $filters): void
    {
        if (!empty($filters['entity_type'])) {
            $qb->where('entity_type', $filters['entity_type']);
        }

        if (!empty($filters['entity_id'])) {
            $qb->where('entity_id', (int) $filters['entity_id']);
        }

        if (!empty($filters['flag_type'])) {
            $qb->where('flag_type', $filters['flag_type']);
        }

        if (!empty($filters['severity'])) {
            $qb->where('severity', $filters['severity']);
        }

        if (!empty($filters['status'])) {
            $qb->where('status', $filters['status']);
        }
    }
}
