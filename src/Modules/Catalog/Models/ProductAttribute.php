<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * ProductAttribute model — structured, searchable key/value pairs for a product.
 *
 * Table schema:
 *   id              BIGINT UNSIGNED  PK AUTO_INCREMENT
 *   product_id      BIGINT UNSIGNED  NOT NULL  FK -> products.id  ON DELETE CASCADE
 *   attribute_key   VARCHAR(255)     NOT NULL  (maps to `name` column in DB)
 *   attribute_value VARCHAR(1000)    NOT NULL  (maps to `value` column in DB)
 *   created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
 *
 * Note: the DB columns are `name` and `value`; this model presents them as
 * `attribute_key` and `attribute_value` to avoid shadowing PHP reserved words
 * and to make the purpose unambiguous.
 */
class ProductAttribute
{
    private const TABLE = 'product_attributes';

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Return all attributes for a product, keyed as attribute_key / attribute_value.
     *
     * @return array<int, array{id: int, product_id: int, attribute_key: string, attribute_value: string, created_at: string, updated_at: string}>
     */
    public function findByProduct(int $productId): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select('id', 'product_id', 'name', 'value', 'created_at', 'updated_at')
            ->where('product_id', $productId)
            ->orderBy('name', 'ASC')
            ->get();

        return array_map(static function (array $row): array {
            return [
                'id'              => (int) $row['id'],
                'product_id'      => (int) $row['product_id'],
                'attribute_key'   => $row['name'],
                'attribute_value' => $row['value'],
                'created_at'      => $row['created_at'],
                'updated_at'      => $row['updated_at'],
            ];
        }, $rows);
    }

    /**
     * Return a flat key => value map for a product (useful for rendering).
     *
     * @return array<string, string>
     */
    public function getMap(int $productId): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select('name', 'value')
            ->where('product_id', $productId)
            ->orderBy('name', 'ASC')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row['name']] = $row['value'];
        }

        return $map;
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Insert a single attribute row.
     *
     * @param array{product_id: int, attribute_key: string, attribute_value: string} $data
     * @return array<string, mixed>
     */
    public function create(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        $payload = [
            'product_id' => (int) $data['product_id'],
            'name'       => $data['attribute_key'],
            'value'      => $data['attribute_value'],
            'created_at' => $now,
            'updated_at' => $now,
        ];

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert($payload);

        return [
            'id'              => (int) $this->connection->lastInsertId(),
            'product_id'      => $payload['product_id'],
            'attribute_key'   => $payload['name'],
            'attribute_value' => $payload['value'],
            'created_at'      => $payload['created_at'],
            'updated_at'      => $payload['updated_at'],
        ];
    }

    /**
     * Replace all attributes for a product in one operation.
     *
     * Deletes existing rows then bulk-inserts the new set.
     * Caller is responsible for wrapping in a transaction if needed.
     *
     * @param int                                              $productId
     * @param array<int, array{key: string, value: string}>   $attributes
     */
    public function replaceForProduct(int $productId, array $attributes): void
    {
        // Delete current attributes
        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('product_id', $productId)
            ->delete();

        $now = gmdate('Y-m-d H:i:s');

        foreach ($attributes as $attr) {
            if (empty($attr['key'])) {
                continue;
            }

            (new QueryBuilder($this->connection))
                ->table(self::TABLE)
                ->insert([
                    'product_id' => $productId,
                    'name'       => $attr['key'],
                    'value'      => $attr['value'] ?? '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
        }
    }
}
