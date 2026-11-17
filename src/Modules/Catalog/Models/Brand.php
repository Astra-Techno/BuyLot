<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * Brand model.
 *
 * Table schema:
 *   id          BIGINT UNSIGNED  PK AUTO_INCREMENT
 *   name        VARCHAR(255)     NOT NULL
 *   slug        VARCHAR(255)     UNIQUE NOT NULL
 *   logo_url    VARCHAR(500)     NULL
 *   is_verified TINYINT(1)       NOT NULL DEFAULT 0
 *   is_active   TINYINT(1)       NOT NULL DEFAULT 1
 *   created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 */
class Brand
{
    private const TABLE = 'brands';

    private const COLUMNS = [
        'id', 'name', 'slug', 'logo_url', 'is_verified', 'is_active',
        'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Return all brands ordered alphabetically.
     *
     * @param bool $activeOnly  When true, only is_active = 1 rows are returned.
     * @return array<int, array<string, mixed>>
     */
    public function findAll(bool $activeOnly = false): array
    {
        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->orderBy('name', 'ASC');

        if ($activeOnly) {
            $qb->where('is_active', 1);
        }

        return $qb->get();
    }

    /**
     * Find a single brand by its URL slug.
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('slug', $slug)
            ->first();
    }

    /**
     * Find a single brand by its primary key.
     *
     * @return array<string, mixed>|null
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
     * Full-text prefix search on brand name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 20): array
    {
        $term = '%' . addcslashes($query, '%_') . '%';

        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('name', 'LIKE', $term)
            ->where('is_active', 1)
            ->orderBy('name', 'ASC')
            ->limit($limit)
            ->get();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Insert a new brand row and return the created record.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $payload = [
            'name'        => $data['name'],
            'slug'        => $data['slug'],
            'logo_url'    => $data['logo_url'] ?? null,
            'is_verified' => isset($data['is_verified']) ? (int) $data['is_verified'] : 0,
            'is_active'   => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ];

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert($payload);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? $payload;
    }

    /**
     * Update mutable fields on a brand.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $allowed  = ['name', 'slug', 'logo_url', 'is_verified', 'is_active'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (empty($filtered)) {
            return false;
        }

        $filtered['updated_at'] = gmdate('Y-m-d H:i:s');

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update($filtered);

        return $rows > 0;
    }
}
