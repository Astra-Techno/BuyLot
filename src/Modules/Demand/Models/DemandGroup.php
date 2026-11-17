<?php

declare(strict_types=1);

namespace App\Modules\Demand\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * DemandGroup model.
 *
 * Table: demand_groups
 *
 * States: forming -> rfq_ready -> rfq_live -> offer_pending -> offer_live
 *         -> moq_met -> completed | expired | cancelled
 *
 * match_key: deterministic hash of (variant_id, region_id, purchase_window_bucket).
 * Used for grouping compatible buy requests together.
 *
 * See docs/state-machines.md Section 2 for full transition table.
 */
class DemandGroup
{
    private const TABLE = 'demand_groups';

    // States matching the DB ENUM
    public const STATUS_FORMING       = 'forming';
    public const STATUS_RFQ_READY     = 'rfq_ready';
    public const STATUS_RFQ_LIVE      = 'rfq_live';
    public const STATUS_OFFER_PENDING = 'offer_pending';
    public const STATUS_OFFER_LIVE    = 'offer_live';
    public const STATUS_MOQ_MET       = 'moq_met';
    public const STATUS_COMPLETED     = 'completed';
    public const STATUS_EXPIRED       = 'expired';
    public const STATUS_CANCELLED     = 'cancelled';

    public const STATUSES = [
        self::STATUS_FORMING,
        self::STATUS_RFQ_READY,
        self::STATUS_RFQ_LIVE,
        self::STATUS_OFFER_PENDING,
        self::STATUS_OFFER_LIVE,
        self::STATUS_MOQ_MET,
        self::STATUS_COMPLETED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    private const TERMINAL_STATES = [
        self::STATUS_COMPLETED,
        self::STATUS_EXPIRED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Legal state transitions.
     */
    private const TRANSITIONS = [
        self::STATUS_FORMING       => [self::STATUS_RFQ_READY, self::STATUS_EXPIRED, self::STATUS_CANCELLED],
        self::STATUS_RFQ_READY     => [self::STATUS_RFQ_LIVE, self::STATUS_CANCELLED],
        self::STATUS_RFQ_LIVE      => [self::STATUS_OFFER_PENDING, self::STATUS_CANCELLED],
        self::STATUS_OFFER_PENDING => [self::STATUS_OFFER_LIVE, self::STATUS_CANCELLED],
        self::STATUS_OFFER_LIVE    => [self::STATUS_MOQ_MET, self::STATUS_EXPIRED, self::STATUS_CANCELLED],
        self::STATUS_MOQ_MET       => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED     => [],
        self::STATUS_EXPIRED       => [],
        self::STATUS_CANCELLED     => [],
    ];

    private const PUBLIC_COLUMNS = [
        'id', 'match_key', 'region_id', 'variant_id',
        'status', 'closes_at', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('id', $id)
            ->first();
    }

    /**
     * Find a group by its match_key that is still in FORMING status.
     * Used by the matching engine to find an existing open group.
     */
    public function findByMatchKey(string $matchKey): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('match_key', $matchKey)
            ->where('status', self::STATUS_FORMING)
            ->first();
    }

    /**
     * Find a group by match_key with SELECT FOR UPDATE to prevent race conditions.
     * MUST be called within a transaction.
     */
    public function findByMatchKeyForUpdate(string $matchKey): ?array
    {
        $sql = "SELECT " . implode(', ', self::PUBLIC_COLUMNS)
             . " FROM " . self::TABLE
             . " WHERE match_key = ? AND status = ? FOR UPDATE";

        $results = $this->connection->query($sql, [$matchKey, self::STATUS_FORMING]);

        return $results[0] ?? null;
    }

    /**
     * List active (non-terminal) groups, paginated.
     * Public-safe: returns only count-based stats, no buyer-specific data.
     */
    public function listActive(int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $terminalPlaceholders = implode(',', array_fill(0, count(self::TERMINAL_STATES), '?'));

        $countSql = "SELECT COUNT(*) as count FROM " . self::TABLE
                  . " WHERE status NOT IN ({$terminalPlaceholders})";
        $countResult = $this->connection->query($countSql, self::TERMINAL_STATES);
        $total = (int) ($countResult[0]['count'] ?? 0);

        $sql = "SELECT " . implode(', ', self::PUBLIC_COLUMNS)
             . " FROM " . self::TABLE
             . " WHERE status NOT IN ({$terminalPlaceholders})"
             . " ORDER BY created_at DESC"
             . " LIMIT ? OFFSET ?";

        $params = [...self::TERMINAL_STATES, $perPage, $offset];
        $data = $this->connection->query($sql, $params);

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
     * Create a new demand group.
     *
     * @param array{match_key: string, region_id: int, variant_id: ?int, closes_at: ?string} $data
     */
    public function create(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'match_key'  => $data['match_key'],
                'region_id'  => $data['region_id'],
                'variant_id' => $data['variant_id'] ?? null,
                'status'     => self::STATUS_FORMING,
                'closes_at'  => $data['closes_at'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? ['id' => $id];
    }

    /**
     * Transition group to a new status with guard validation.
     *
     * @throws \InvalidArgumentException If the transition is not allowed.
     */
    public function updateStatus(int $id, string $newStatus): bool
    {
        $current = $this->find($id);

        if ($current === null) {
            throw new \InvalidArgumentException("Demand group {$id} not found");
        }

        $currentStatus = $current['status'];

        if (!$this->isValidTransition($currentStatus, $newStatus)) {
            throw new \InvalidArgumentException(
                "Invalid group status transition: {$currentStatus} -> {$newStatus}"
            );
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('status', $currentStatus) // Optimistic lock
            ->update([
                'status'     => $newStatus,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    // -------------------------------------------------------------------------
    // Stats (privacy-safe)
    // -------------------------------------------------------------------------

    /**
     * Get group statistics: member counts at each intent level.
     * Privacy-safe: returns only aggregate counts, never individual budgets or identities.
     *
     * @return array{
     *     total_members: int,
     *     total_qty: int,
     *     interested_qty: int,
     *     ready_qty: int,
     *     accepted_qty: int,
     *     paid_qty: int,
     *     released_qty: int
     * }
     */
    public function getStats(int $groupId): array
    {
        $sql = "SELECT
                    COUNT(*) as total_members,
                    COALESCE(SUM(qty), 0) as total_qty,
                    COALESCE(SUM(CASE WHEN intent_level >= 1 THEN qty ELSE 0 END), 0) as interested_qty,
                    COALESCE(SUM(CASE WHEN intent_level >= 2 THEN qty ELSE 0 END), 0) as ready_qty,
                    COALESCE(SUM(CASE WHEN intent_level >= 3 THEN qty ELSE 0 END), 0) as accepted_qty,
                    COALESCE(SUM(CASE WHEN intent_level >= 4 THEN qty ELSE 0 END), 0) as paid_qty,
                    COALESCE(SUM(CASE WHEN intent_level >= 5 THEN qty ELSE 0 END), 0) as released_qty
                FROM group_memberships
                WHERE group_id = ?";

        $result = $this->connection->query($sql, [$groupId]);

        $row = $result[0] ?? [];

        return [
            'total_members'  => (int) ($row['total_members'] ?? 0),
            'total_qty'      => (int) ($row['total_qty'] ?? 0),
            'interested_qty' => (int) ($row['interested_qty'] ?? 0),
            'ready_qty'      => (int) ($row['ready_qty'] ?? 0),
            'accepted_qty'   => (int) ($row['accepted_qty'] ?? 0),
            'paid_qty'       => (int) ($row['paid_qty'] ?? 0),
            'released_qty'   => (int) ($row['released_qty'] ?? 0),
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isValidTransition(string $from, string $to): bool
    {
        return isset(self::TRANSITIONS[$from])
            && in_array($to, self::TRANSITIONS[$from], true);
    }

    public function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATES, true);
    }

    /**
     * Generate a deterministic match_key for grouping.
     *
     * V1: exact variant match only (spec 6.4).
     * Hash of (variant_id, region_id, purchase_window_bucket).
     */
    public static function generateMatchKey(int $variantId, int $regionId, string $purchaseWindowBucket): string
    {
        $raw = implode('|', [$variantId, $regionId, $purchaseWindowBucket]);
        return hash('sha256', $raw);
    }

    /**
     * Calculate the purchase window bucket for a given date.
     * Groups requests into weekly buckets for matching.
     */
    public static function purchaseWindowBucket(?string $purchaseBy): string
    {
        if ($purchaseBy === null) {
            return 'open';
        }

        $ts = strtotime($purchaseBy);
        if ($ts === false) {
            return 'open';
        }

        // Weekly bucket: year + ISO week number
        return date('o-W', $ts);
    }
}
