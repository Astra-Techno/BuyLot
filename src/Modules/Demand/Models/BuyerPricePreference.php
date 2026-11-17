<?php

declare(strict_types=1);

namespace App\Modules\Demand\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * BuyerPricePreference model.
 *
 * Table: buyer_price_preferences
 *
 * CRITICAL PRIVACY RULE:
 *   max_paise MUST NEVER be exposed to sellers or unauthorized users.
 *   Only the buyer themselves and authorized procurement staff may see max_paise.
 *   visibility_scope controls access:
 *     - 'private'          => buyer-only (default)
 *     - 'procurement_only' => buyer + authorized procurement staff
 */
class BuyerPricePreference
{
    private const TABLE = 'buyer_price_preferences';

    public const VISIBILITY_PRIVATE          = 'private';
    public const VISIBILITY_PROCUREMENT_ONLY = 'procurement_only';

    /**
     * Columns safe for the BUYER to see (includes their own price info).
     */
    private const BUYER_COLUMNS = [
        'id', 'request_id', 'target_paise', 'max_paise',
        'visibility_scope', 'price_includes', 'created_at', 'updated_at',
    ];

    /**
     * Columns safe for PUBLIC/SELLER view -- NEVER includes max_paise.
     */
    private const PUBLIC_COLUMNS = [
        'id', 'request_id', 'created_at', 'updated_at',
    ];

    /**
     * Columns visible to authorized procurement staff when scope allows.
     */
    private const PROCUREMENT_COLUMNS = [
        'id', 'request_id', 'target_paise', 'max_paise',
        'visibility_scope', 'price_includes', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Find by request ID -- buyer view (includes all price info).
     * ONLY call this when the authenticated user IS the buyer who owns this request.
     */
    public function findByRequestForBuyer(int $requestId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::BUYER_COLUMNS)
            ->where('request_id', $requestId)
            ->first();
    }

    /**
     * Find by request ID -- procurement staff view.
     * Returns max_paise ONLY if visibility_scope = 'procurement_only'.
     * NEVER call this for seller-facing or public endpoints.
     */
    public function findByRequestForProcurement(int $requestId): ?array
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PROCUREMENT_COLUMNS)
            ->where('request_id', $requestId)
            ->first();

        if ($row === null) {
            return null;
        }

        // Enforce visibility scope: if 'private', redact max_paise even from procurement
        if ($row['visibility_scope'] === self::VISIBILITY_PRIVATE) {
            $row['max_paise'] = null;
        }

        return $row;
    }

    /**
     * Public/seller-safe view -- NEVER exposes any price data.
     */
    public function findByRequestPublic(int $requestId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('request_id', $requestId)
            ->first();
    }

    /**
     * Find by ID (internal use only).
     */
    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::BUYER_COLUMNS)
            ->where('id', $id)
            ->first();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a price preference for a buy request.
     *
     * @param array{request_id: int, target_paise: ?int, max_paise: ?int, visibility_scope?: string, price_includes?: ?string} $data
     */
    public function create(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        // Validate: max_paise must be >= target_paise if both provided
        if (
            isset($data['target_paise'], $data['max_paise'])
            && $data['max_paise'] < $data['target_paise']
        ) {
            throw new \InvalidArgumentException(
                'max_paise must be greater than or equal to target_paise'
            );
        }

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'request_id'       => $data['request_id'],
                'target_paise'     => $data['target_paise'] ?? null,
                'max_paise'        => $data['max_paise'] ?? null,
                'visibility_scope' => $data['visibility_scope'] ?? self::VISIBILITY_PRIVATE,
                'price_includes'   => $data['price_includes'] ?? null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? ['id' => $id];
    }

    /**
     * Update price preference.
     * Only the buyer who owns the request should call this.
     */
    public function update(int $requestId, array $data): bool
    {
        $allowed  = ['target_paise', 'max_paise', 'visibility_scope', 'price_includes'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (empty($filtered)) {
            return false;
        }

        // Validate: max_paise must be >= target_paise if both are being set
        if (isset($filtered['target_paise'], $filtered['max_paise'])) {
            if ($filtered['max_paise'] < $filtered['target_paise']) {
                throw new \InvalidArgumentException(
                    'max_paise must be greater than or equal to target_paise'
                );
            }
        }

        $filtered['updated_at'] = gmdate('Y-m-d H:i:s');

        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('request_id', $requestId)
            ->update($filtered) > 0;
    }

    // -------------------------------------------------------------------------
    // Aggregations (for internal procurement use ONLY)
    // -------------------------------------------------------------------------

    /**
     * Get aggregate budget stats for a set of request IDs.
     * INTERNAL USE ONLY -- never expose to sellers.
     *
     * @param int[] $requestIds
     * @return array{count: int, avg_target_paise: ?float, min_target_paise: ?int, max_target_paise: ?int}
     */
    public function getAggregateStats(array $requestIds): array
    {
        if (empty($requestIds)) {
            return ['count' => 0, 'avg_target_paise' => null, 'min_target_paise' => null, 'max_target_paise' => null];
        }

        $placeholders = implode(',', array_fill(0, count($requestIds), '?'));
        $sql = "SELECT
                    COUNT(*) as count,
                    AVG(target_paise) as avg_target_paise,
                    MIN(target_paise) as min_target_paise,
                    MAX(target_paise) as max_target_paise
                FROM " . self::TABLE . "
                WHERE request_id IN ({$placeholders})
                  AND target_paise IS NOT NULL";

        $result = $this->connection->query($sql, array_values($requestIds));

        return $result[0] ?? [
            'count'            => 0,
            'avg_target_paise' => null,
            'min_target_paise' => null,
            'max_target_paise' => null,
        ];
    }
}
