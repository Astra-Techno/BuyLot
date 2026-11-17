<?php

declare(strict_types=1);

namespace App\Modules\Demand\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * GroupCompatibilityRule model.
 *
 * Table: group_compatibility_rules
 *
 * Defines which product variants/specs can be grouped together
 * within a demand group. V1 uses exact SKU match (spec 6.4),
 * but compatibility rules allow future flexibility for
 * "close-enough" substitution grouping.
 *
 * Fields:
 *   - allowed_variants_json: array of variant IDs acceptable as substitutes
 *   - constraints_json: rules like {"require_brand": true, "max_price_delta_pct": 5}
 */
class GroupCompatibilityRule
{
    private const TABLE = 'group_compatibility_rules';

    private const COLUMNS = [
        'id', 'group_id', 'allowed_variants_json', 'constraints_json',
        'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();
    }

    /**
     * Get all compatibility rules for a group.
     */
    public function findByGroup(int $groupId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('group_id', $groupId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a compatibility rule for a group.
     *
     * @param array{group_id: int, allowed_variants_json: string, constraints_json: ?string} $data
     */
    public function create(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'group_id'              => $data['group_id'],
                'allowed_variants_json' => $data['allowed_variants_json'],
                'constraints_json'      => $data['constraints_json'] ?? null,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? ['id' => $id];
    }

    /**
     * Update a compatibility rule.
     */
    public function update(int $id, array $data): bool
    {
        $allowed  = ['allowed_variants_json', 'constraints_json'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (empty($filtered)) {
            return false;
        }

        $filtered['updated_at'] = gmdate('Y-m-d H:i:s');

        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update($filtered) > 0;
    }

    /**
     * Delete a compatibility rule.
     */
    public function delete(int $id): bool
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->delete() > 0;
    }

    // -------------------------------------------------------------------------
    // Compatibility Checks
    // -------------------------------------------------------------------------

    /**
     * Check if a variant is compatible with a group based on its rules.
     *
     * V1: exact match only (spec 6.4). The compatibility rules are
     * consulted but the primary match is variant_id equality on the group.
     * This method is for future-proofing when similar-but-different
     * products may be grouped.
     */
    public function isVariantCompatible(int $groupId, int $variantId): bool
    {
        $rules = $this->findByGroup($groupId);

        // If no rules defined, only exact match is allowed (V1 behavior)
        if (empty($rules)) {
            return true; // Caller must verify exact variant_id match separately
        }

        foreach ($rules as $rule) {
            $allowedVariants = json_decode($rule['allowed_variants_json'], true);
            if (is_array($allowedVariants) && in_array($variantId, $allowedVariants, true)) {
                return true;
            }
        }

        return false;
    }
}
