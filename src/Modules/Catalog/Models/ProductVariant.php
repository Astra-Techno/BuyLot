<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * ProductVariant model.
 *
 * Table schema:
 *   id              BIGINT UNSIGNED  PK AUTO_INCREMENT
 *   product_id      BIGINT UNSIGNED  NOT NULL  FK -> products.id
 *   sku             VARCHAR(100)     UNIQUE NOT NULL
 *   name            VARCHAR(500)     NOT NULL  (e.g. "32GB Black")
 *   attributes_json JSON             NULL      (color, size, model_year, capacity, etc.)
 *   is_active       TINYINT(1)       NOT NULL DEFAULT 1
 *   created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 */
class ProductVariant
{
    private const TABLE = 'product_variants';

    private const COLUMNS = [
        'id', 'product_id', 'sku', 'name', 'attributes_json',
        'is_active', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Find a variant by primary key.
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
     * Return all variants belonging to a product.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByProduct(int $productId, bool $activeOnly = true): array
    {
        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('product_id', $productId);

        if ($activeOnly) {
            $qb->where('is_active', 1);
        }

        return $qb->orderBy('name', 'ASC')->get();
    }

    /**
     * Find a variant by its SKU.
     *
     * @return array<string, mixed>|null
     */
    public function findBySku(string $sku): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('sku', $sku)
            ->first();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Insert a new variant row and return the created record.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $payload = [
            'product_id'      => (int) $data['product_id'],
            'sku'             => $data['sku'],
            'name'            => $data['name'],
            'attributes_json' => isset($data['attributes_json'])
                ? (is_string($data['attributes_json'])
                    ? $data['attributes_json']
                    : json_encode($data['attributes_json']))
                : null,
            'is_active'       => isset($data['is_active']) ? (int) $data['is_active'] : 1,
            'created_at'      => $now,
            'updated_at'      => $now,
        ];

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert($payload);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? $payload;
    }

    /**
     * Update mutable fields on a variant.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): bool
    {
        $allowed  = ['sku', 'name', 'attributes_json', 'is_active'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (isset($filtered['attributes_json']) && !is_string($filtered['attributes_json'])) {
            $filtered['attributes_json'] = json_encode($filtered['attributes_json']);
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
