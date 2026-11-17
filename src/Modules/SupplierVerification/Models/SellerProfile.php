<?php

declare(strict_types=1);

namespace App\Modules\SupplierVerification\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * SellerProfile model.
 *
 * Maps to: seller_profiles
 *   id                BIGINT UNSIGNED PK AUTO_INCREMENT
 *   organization_id   BIGINT UNSIGNED NOT NULL (FK -> organizations.id)
 *   display_name      VARCHAR(255) NULL        (denormalised display label)
 *   categories_json   JSON NULL                (array of approved category IDs)
 *   service_regions   JSON NULL                (array of region identifiers)
 *   reliability_score DECIMAL(5,2) NULL        (0.00–100.00)
 *   status            ENUM('pending_approval','active','suspended','terminated')
 *   created_at        TIMESTAMP
 *   updated_at        TIMESTAMP
 */
class SellerProfile
{
    private const TABLE = 'seller_profiles';

    public const STATUS_PENDING    = 'pending_approval';
    public const STATUS_ACTIVE     = 'active';
    public const STATUS_SUSPENDED  = 'suspended';
    public const STATUS_TERMINATED = 'terminated';

    public const ALLOWED_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_TERMINATED,
    ];

    public function __construct(private readonly Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->first();

        return $row !== null ? $this->decode($row) : null;
    }

    public function findByOrganization(int $organizationId): ?array
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->first();

        return $row !== null ? $this->decode($row) : null;
    }

    /**
     * Paginated list with optional filters.
     *
     * @param array{status?: string, category_id?: int, region?: string} $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE);

        if (!empty($filters['status'])) {
            $qb->where('status', $filters['status']);
        }

        $total = (clone $qb)->count();

        $rows = $qb
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        $rows = array_map(fn(array $r) => $this->decode($r), $rows);

        // Post-filter by category or region (JSON columns — cannot SQL-filter portably here)
        if (!empty($filters['category_id'])) {
            $catId = (int) $filters['category_id'];
            $rows  = array_values(array_filter(
                $rows,
                fn(array $r) => in_array($catId, $r['categories_json'] ?? [], true),
            ));
        }

        if (!empty($filters['region'])) {
            $region = $filters['region'];
            $rows   = array_values(array_filter(
                $rows,
                fn(array $r) => in_array($region, $r['service_regions'] ?? [], true),
            ));
        }

        return [
            'data'     => $rows,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new seller profile linked to an organization.
     * Returns the full profile row.
     */
    public function create(int $organizationId, string $displayName): array
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'organization_id' => $organizationId,
                'display_name'    => $displayName,
                'categories_json' => json_encode([]),
                'service_regions' => json_encode([]),
                'status'          => self::STATUS_PENDING,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? [
            'id'              => $id,
            'organization_id' => $organizationId,
            'display_name'    => $displayName,
            'status'          => self::STATUS_PENDING,
        ];
    }

    /**
     * Update mutable profile fields (display_name, categories_json, service_regions).
     * Only permitted for ACTIVE sellers.
     */
    public function updateProfile(int $id, array $data): bool
    {
        $allowed  = ['display_name', 'categories_json', 'service_regions'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (empty($filtered)) {
            return false;
        }

        // Re-encode JSON fields
        foreach (['categories_json', 'service_regions'] as $jsonField) {
            if (array_key_exists($jsonField, $filtered) && is_array($filtered[$jsonField])) {
                $filtered[$jsonField] = json_encode($filtered[$jsonField]);
            }
        }

        $filtered['updated_at'] = gmdate('Y-m-d H:i:s');

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update($filtered);

        return $rows > 0;
    }

    /**
     * Transition seller status (compliance/admin only).
     */
    public function updateStatus(int $id, string $status): bool
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            return false;
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'status'     => $status,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Update the computed reliability score.
     */
    public function updateScore(int $id, float $score): bool
    {
        $score = max(0.0, min(100.0, $score));

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'reliability_score' => $score,
                'updated_at'        => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function decode(array $row): array
    {
        foreach (['categories_json', 'service_regions'] as $field) {
            if (isset($row[$field]) && is_string($row[$field])) {
                $row[$field] = json_decode($row[$field], true) ?? [];
            }
        }

        return $row;
    }
}
