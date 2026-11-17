<?php

declare(strict_types=1);

namespace App\Modules\AdminAudit\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use LogicException;

/**
 * PolicyVersion model — APPEND-ONLY.
 *
 * Maps the `policy_versions` table (Module 7, section 7.9).
 *
 * This model deliberately exposes NO update() or delete() methods.
 * Policy versions are immutable once published; new versions are
 * created as separate rows with a new version string.
 *
 * Schema columns:
 *   id           BIGINT UNSIGNED  PK
 *   policy_key   VARCHAR(100)     e.g. TERMS, PRIVACY, REFUND, COMMISSION, INSPECTION
 *                                 (stored as-is; spec uses uppercase)
 *   version      VARCHAR(20)      e.g. 1.0, 2.1
 *   effective_at TIMESTAMP
 *   content_ref  VARCHAR(500)     object storage path to the policy document
 *   created_by   BIGINT UNSIGNED NULL  FK -> users.id
 *   created_at   TIMESTAMP
 *
 * UNIQUE KEY: (policy_key, version) — prevents duplicate version publishing.
 */
class PolicyVersion
{
    public const TABLE = 'policy_versions';

    // Policy types
    public const TYPE_TERMS      = 'TERMS';
    public const TYPE_PRIVACY    = 'PRIVACY';
    public const TYPE_REFUND     = 'REFUND';
    public const TYPE_COMMISSION = 'COMMISSION';
    public const TYPE_INSPECTION = 'INSPECTION';

    public const VALID_TYPES = [
        self::TYPE_TERMS,
        self::TYPE_PRIVACY,
        self::TYPE_REFUND,
        self::TYPE_COMMISSION,
        self::TYPE_INSPECTION,
    ];

    public function __construct(private readonly Connection $connection) {}

    // -------------------------------------------------------------------------
    // Write — append only
    // -------------------------------------------------------------------------

    /**
     * Publish a new policy version. Returns the new row id.
     *
     * @param array{
     *   policy_key:    string,
     *   version:       string,
     *   effective_at:  string,  Y-m-d H:i:s
     *   content_ref:   string,
     *   published_by?: int|null,
     * } $data
     *
     * @throws \RuntimeException if the (policy_key, version) combination already exists.
     */
    public function publish(array $data): int
    {
        // Guard: check for duplicate before insert to surface a clear error.
        $existing = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('policy_key', $data['policy_key'])
            ->where('version', $data['version'])
            ->first();

        if ($existing !== null) {
            throw new \RuntimeException(
                "Policy version {$data['version']} for {$data['policy_key']} already exists.",
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'policy_key'   => $data['policy_key'],
                'version'      => $data['version'],
                'effective_at' => $data['effective_at'],
                'content_ref'  => $data['content_ref'],
                'created_by'   => $data['published_by'] ?? null,
                'created_at'   => $now,
            ]);

        return (int) $this->connection->lastInsertId();
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
     * Fetch the latest (most recently effective) version for a policy key.
     */
    public function latestForKey(string $policyKey): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('policy_key', $policyKey)
            ->orderBy('effective_at', 'DESC')
            ->first();
    }

    /**
     * List all published policy versions with optional key filter.
     *
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function list(?string $policyKey = null, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE);

        if ($policyKey !== null) {
            $qb->where('policy_key', $policyKey);
        }

        $total = (clone $qb)->count();

        $rows = $qb
            ->orderBy('policy_key', 'ASC')
            ->orderBy('effective_at', 'DESC')
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
    // Immutability guards
    // -------------------------------------------------------------------------

    /** @throws LogicException always */
    public function update(): never
    {
        throw new LogicException(
            'PolicyVersion records are immutable. Publish a new version instead.',
        );
    }

    /** @throws LogicException always */
    public function delete(): never
    {
        throw new LogicException(
            'PolicyVersion records are immutable and must not be deleted.',
        );
    }
}
