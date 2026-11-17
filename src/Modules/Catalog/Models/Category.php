<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * Category model.
 *
 * Table schema:
 *   id          BIGINT UNSIGNED  PK AUTO_INCREMENT
 *   parent_id   BIGINT UNSIGNED  NULL (self-referencing tree)
 *   slug        VARCHAR(255)     UNIQUE NOT NULL
 *   name        VARCHAR(255)     NOT NULL
 *   description TEXT             NULL
 *   icon_url    VARCHAR(500)     NULL
 *   sort_order  INT UNSIGNED     NOT NULL DEFAULT 0
 *   is_active   TINYINT(1)       NOT NULL DEFAULT 1
 *   created_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   updated_at  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 */
class Category
{
    private const TABLE = 'categories';

    private const COLUMNS = [
        'id', 'parent_id', 'slug', 'name', 'description',
        'icon_url', 'sort_order', 'is_active', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Return all categories ordered by sort_order then name.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAll(): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('name', 'ASC')
            ->get();
    }

    /**
     * Find a single category by its URL slug.
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
     * Find a single category by its primary key.
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
     * Build a fully-nested category tree.
     *
     * Returns root categories (parent_id = NULL) with a recursive `children`
     * key on each node, populated from all fetched rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTree(): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('name', 'ASC')
            ->get();

        return $this->buildTree($rows, null);
    }

    /**
     * Return active root and child categories with a product_count per node.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActiveWithCounts(): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(
                self::TABLE . '.id',
                self::TABLE . '.parent_id',
                self::TABLE . '.slug',
                self::TABLE . '.name',
                self::TABLE . '.description',
                self::TABLE . '.icon_url',
                self::TABLE . '.sort_order',
                self::TABLE . '.is_active',
                self::TABLE . '.created_at',
                self::TABLE . '.updated_at',
                'COUNT(products.id) as product_count',
            )
            ->leftJoin('products', 'products.category_id = ' . self::TABLE . '.id AND products.is_active = 1')
            ->where(self::TABLE . '.is_active', 1)
            ->groupBy(self::TABLE . '.id')
            ->orderBy(self::TABLE . '.sort_order', 'ASC')
            ->orderBy(self::TABLE . '.name', 'ASC')
            ->get();

        // Cast count to int
        return array_map(static function (array $row): array {
            $row['product_count'] = (int) $row['product_count'];
            return $row;
        }, $rows);
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Insert a new category row and return the created record.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $payload = [
            'parent_id'   => $data['parent_id'] ?? null,
            'slug'        => $data['slug'],
            'name'        => $data['name'],
            'description' => $data['description'] ?? null,
            'icon_url'    => $data['icon_url'] ?? null,
            'sort_order'  => (int) ($data['sort_order'] ?? 0),
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
     * Update mutable fields on a category.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $allowed  = ['parent_id', 'name', 'slug', 'description', 'icon_url', 'sort_order', 'is_active'];
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

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Recursively assemble a flat row-list into a nested tree.
     *
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function buildTree(array $rows, ?int $parentId): array
    {
        $branch = [];

        foreach ($rows as $row) {
            $rowParent = $row['parent_id'] !== null ? (int) $row['parent_id'] : null;

            if ($rowParent === $parentId) {
                $children       = $this->buildTree($rows, (int) $row['id']);
                $row['children'] = $children;
                $branch[]        = $row;
            }
        }

        return $branch;
    }
}
