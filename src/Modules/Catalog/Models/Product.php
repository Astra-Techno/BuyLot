<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * Product model.
 *
 * Table schema:
 *   id                  BIGINT UNSIGNED  PK AUTO_INCREMENT
 *   category_id         BIGINT UNSIGNED  NOT NULL  FK -> categories.id
 *   brand_id            BIGINT UNSIGNED  NULL      FK -> brands.id
 *   name                VARCHAR(500)     NOT NULL
 *   slug                VARCHAR(500)     UNIQUE NOT NULL
 *   description         TEXT             NULL
 *   specifications_json JSON             NULL
 *   is_active           TINYINT(1)       NOT NULL DEFAULT 1
 *   created_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   updated_at          TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 */
class Product
{
    private const TABLE = 'products';

    private const COLUMNS = [
        'id', 'category_id', 'brand_id', 'name', 'slug', 'description',
        'specifications_json', 'is_active', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Find a product by primary key.
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
     * Find a product by its URL slug.
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
     * Full-text search across product name and description with optional filters.
     *
     * Supported $filters keys:
     *   category_id  int
     *   brand_id     int
     *   is_active    int (default 1)
     *
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function search(string $query, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS);

        // Active-only by default; allow caller to override
        $isActive = isset($filters['is_active']) ? (int) $filters['is_active'] : 1;
        $qb->where('is_active', $isActive);

        if ($query !== '') {
            $term = '%' . addcslashes($query, '%_') . '%';
            $qb->where('name', 'LIKE', $term)
               ->orWhere('description', 'LIKE', $term);
        }

        if (!empty($filters['category_id'])) {
            $qb->where('category_id', (int) $filters['category_id']);
        }

        if (!empty($filters['brand_id'])) {
            $qb->where('brand_id', (int) $filters['brand_id']);
        }

        $total = (clone $qb)->count();

        $data = $qb
            ->orderBy('name', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Paginated list of active products for a given category.
     *
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listByCategory(int $categoryId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('category_id', $categoryId)
            ->where('is_active', 1);

        $total = (clone $qb)->count();

        $data = $qb
            ->orderBy('name', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Paginated list of active products for a given brand.
     *
     * @return array{data: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function listByBrand(int $brandId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('brand_id', $brandId)
            ->where('is_active', 1);

        $total = (clone $qb)->count();

        $data = $qb
            ->orderBy('name', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Insert a new product row and return the created record.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $payload = [
            'category_id'         => (int) $data['category_id'],
            'brand_id'            => isset($data['brand_id']) ? (int) $data['brand_id'] : null,
            'name'                => $data['name'],
            'slug'                => $data['slug'],
            'description'         => $data['description'] ?? null,
            'specifications_json' => isset($data['specifications_json'])
                ? (is_string($data['specifications_json'])
                    ? $data['specifications_json']
                    : json_encode($data['specifications_json']))
                : null,
            'is_active'           => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_at'          => $now,
            'updated_at'          => $now,
        ];

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert($payload);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? $payload;
    }

    /**
     * Update mutable fields on a product.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $allowed  = [
            'category_id', 'brand_id', 'name', 'slug',
            'description', 'specifications_json', 'is_active',
        ];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (isset($filtered['specifications_json']) && !is_string($filtered['specifications_json'])) {
            $filtered['specifications_json'] = json_encode($filtered['specifications_json']);
        }

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
