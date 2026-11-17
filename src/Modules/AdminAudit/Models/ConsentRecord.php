<?php

declare(strict_types=1);

namespace App\Modules\AdminAudit\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use LogicException;

/**
 * ConsentRecord model — APPEND-ONLY.
 *
 * Maps the `consent_records` table (Module 7, section 7.10).
 *
 * Consent records are legally required audit artifacts under DPDP.
 * This model deliberately exposes NO update() or delete() methods.
 * Revocation is recorded as a new state on the existing row via
 * revokeConsent() — the only permitted mutation.
 *
 * Schema columns:
 *   id                BIGINT UNSIGNED  PK
 *   user_id           BIGINT UNSIGNED  FK -> users.id
 *   policy_version_id BIGINT UNSIGNED  FK -> policy_versions.id
 *   consented_at      TIMESTAMP        DEFAULT CURRENT_TIMESTAMP
 *   ip_address        VARCHAR(45) NULL
 *   user_agent        VARCHAR(500) NULL
 *   revoked_at        TIMESTAMP NULL   set on DPDP consent withdrawal
 *   created_at        TIMESTAMP
 *   updated_at        TIMESTAMP
 *
 * UNIQUE KEY: (user_id, policy_version_id)
 *
 * Note on "append-only" vs revocation:
 *   The spec requirement is that consent records are not deleted and that
 *   new consent events are appended. Setting revoked_at is treated as a
 *   permitted status update (analogous to resolved_at on risk_flags), not
 *   a data destruction operation. All prior grant facts remain intact.
 */
class ConsentRecord
{
    public const TABLE = 'consent_records';

    // Consent types (stored as policy_key values in policy_versions)
    public const TYPE_TERMS    = 'TERMS';
    public const TYPE_PRIVACY  = 'PRIVACY';
    public const TYPE_MARKETING = 'MARKETING';
    public const TYPE_WHATSAPP = 'WHATSAPP';

    public const VALID_TYPES = [
        self::TYPE_TERMS,
        self::TYPE_PRIVACY,
        self::TYPE_MARKETING,
        self::TYPE_WHATSAPP,
    ];

    public function __construct(private readonly Connection $connection) {}

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Record a new consent grant.
     * Returns the new row id.
     *
     * @param array{
     *   user_id:            int,
     *   policy_version_id:  int,
     *   ip_address?:        string|null,
     *   user_agent?:        string|null,
     * } $data
     *
     * @throws \RuntimeException if the user has already consented to this policy version.
     */
    public function grant(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        // UNIQUE KEY (user_id, policy_version_id) will catch duplicates at DB level,
        // but we surface a readable error first.
        $existing = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('user_id', $data['user_id'])
            ->where('policy_version_id', $data['policy_version_id'])
            ->first();

        if ($existing !== null && $existing['revoked_at'] === null) {
            throw new \RuntimeException(
                "User {$data['user_id']} has already granted consent for policy version {$data['policy_version_id']}.",
            );
        }

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'user_id'           => $data['user_id'],
                'policy_version_id' => $data['policy_version_id'],
                'consented_at'      => $now,
                'ip_address'        => $data['ip_address'] ?? null,
                'user_agent'        => $data['user_agent'] ?? null,
                'revoked_at'        => null,
                'created_at'        => $now,
                'updated_at'        => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Revoke an existing consent grant (DPDP withdrawal).
     * Sets revoked_at on the existing record — the row is never deleted.
     * Returns true if the record was found and updated.
     */
    public function revoke(int $userId, int $policyVersionId): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('policy_version_id', $policyVersionId)
            ->where('revoked_at', null)
            ->update([
                'revoked_at' => $now,
                'updated_at' => $now,
            ]);

        return $rows > 0;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Fetch the current consent status for a user across all policy versions.
     * Returns an array of consent records (each with revoked_at indicating status).
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUser(int $userId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('user_id', $userId)
            ->orderBy('consented_at', 'DESC')
            ->get();
    }

    /**
     * Paginated list of all consent records with optional filters.
     *
     * @param array{
     *   user_id?:            int,
     *   policy_version_id?:  int,
     *   revoked?:            bool,
     * } $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE);

        if (!empty($filters['user_id'])) {
            $qb->where('user_id', (int) $filters['user_id']);
        }

        if (!empty($filters['policy_version_id'])) {
            $qb->where('policy_version_id', (int) $filters['policy_version_id']);
        }

        if (isset($filters['revoked'])) {
            if ($filters['revoked']) {
                $qb->where('revoked_at', '!=', null);
            } else {
                $qb->where('revoked_at', null);
            }
        }

        $total = (clone $qb)->count();

        $rows = $qb
            ->orderBy('consented_at', 'DESC')
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
    public function delete(): never
    {
        throw new LogicException(
            'ConsentRecord rows must never be deleted. DPDP mandates audit retention. '
            . 'Use revoke() to record withdrawal of consent.',
        );
    }
}
