<?php

declare(strict_types=1);

namespace App\Modules\RFQ\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * RFQ model.
 *
 * Table: rfqs
 *   id                   BIGINT UNSIGNED AUTO_INCREMENT PK
 *   group_id             BIGINT UNSIGNED NOT NULL
 *   spec_snapshot_json   JSON NOT NULL
 *   qty_band_json        JSON NOT NULL
 *   bid_deadline         TIMESTAMP NOT NULL
 *   status               ENUM('draft','published','closed','awarded','cancelled')
 *   created_by           BIGINT UNSIGNED NULL
 *   created_at           TIMESTAMP
 *   updated_at           TIMESTAMP
 */
class RFQ
{
    private const TABLE = 'rfqs';

    // Status constants
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_CLOSED    = 'closed';
    public const STATUS_AWARDED   = 'awarded';
    public const STATUS_CANCELLED = 'cancelled';

    public const VALID_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PUBLISHED,
        self::STATUS_CLOSED,
        self::STATUS_AWARDED,
        self::STATUS_CANCELLED,
    ];

    // Allowed state transitions
    private const TRANSITIONS = [
        self::STATUS_DRAFT     => [self::STATUS_PUBLISHED, self::STATUS_CANCELLED],
        self::STATUS_PUBLISHED => [self::STATUS_CLOSED, self::STATUS_CANCELLED],
        self::STATUS_CLOSED    => [self::STATUS_AWARDED, self::STATUS_CANCELLED],
        self::STATUS_AWARDED   => [],
        self::STATUS_CANCELLED => [],
    ];

    // Columns safe for internal staff responses
    private const INTERNAL_COLUMNS = [
        'id', 'group_id', 'spec_snapshot_json', 'qty_band_json',
        'bid_deadline', 'status', 'created_by', 'created_at', 'updated_at',
    ];

    // Columns safe for seller-facing responses (no buyer budgets, no internal metadata)
    private const SELLER_COLUMNS = [
        'id', 'spec_snapshot_json', 'qty_band_json', 'bid_deadline', 'status',
        'created_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::INTERNAL_COLUMNS)
            ->where('id', $id)
            ->first();
    }

    /**
     * Find RFQ with seller-safe columns only.
     * NEVER returns group_id, created_by or other internal fields.
     */
    public function findForSeller(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::SELLER_COLUMNS)
            ->where('id', $id)
            ->where('status', '!=', self::STATUS_DRAFT)
            ->first();
    }

    /**
     * List RFQs eligible for a seller based on their categories and regions.
     * Joins rfq_seller_invitations OR matches seller profile criteria.
     * Returns ONLY seller-safe columns. Never returns competing seller info.
     *
     * @param int $sellerId seller_profiles.id
     */
    public function listEligibleForSeller(int $sellerId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        // Only show RFQs where the seller has an invitation and status is not draft
        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(
                self::TABLE . '.id',
                self::TABLE . '.spec_snapshot_json',
                self::TABLE . '.qty_band_json',
                self::TABLE . '.bid_deadline',
                self::TABLE . '.status',
                self::TABLE . '.created_at',
                'rfq_seller_invitations.status AS invitation_status',
            )
            ->join(
                'rfq_seller_invitations',
                self::TABLE . '.id = rfq_seller_invitations.rfq_id',
            )
            ->where('rfq_seller_invitations.seller_id', $sellerId)
            ->where(self::TABLE . '.status', '!=', self::STATUS_DRAFT);

        $total = (clone $qb)->count();

        $data = $qb
            ->orderBy(self::TABLE . '.bid_deadline', 'ASC')
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
     * List all RFQs for internal staff with optional filters.
     */
    public function listInternal(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::INTERNAL_COLUMNS);

        if (!empty($filters['status'])) {
            $qb->where('status', $filters['status']);
        }

        if (!empty($filters['group_id'])) {
            $qb->where('group_id', (int) $filters['group_id']);
        }

        $total = (clone $qb)->count();

        $data = $qb
            ->orderBy('created_at', 'DESC')
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

    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'group_id'           => $data['group_id'],
                'spec_snapshot_json' => $data['spec_snapshot_json'],
                'qty_band_json'      => $data['qty_band_json'],
                'bid_deadline'       => $data['bid_deadline'],
                'status'             => self::STATUS_DRAFT,
                'created_by'         => $data['created_by'] ?? null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    public function update(int $id, array $data): bool
    {
        $allowed  = ['spec_snapshot_json', 'qty_band_json', 'bid_deadline'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (empty($filtered)) {
            return false;
        }

        $filtered['updated_at'] = gmdate('Y-m-d H:i:s');

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('status', self::STATUS_DRAFT) // Only draft RFQs can be edited
            ->update($filtered);

        return $rows > 0;
    }

    /**
     * Transition RFQ status with guard.
     */
    public function transition(int $id, string $newStatus): bool
    {
        $rfq = $this->find($id);
        if ($rfq === null) {
            return false;
        }

        $currentStatus = $rfq['status'];
        $allowed = self::TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            return false;
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('status', $currentStatus) // Optimistic lock on status
            ->update([
                'status'     => $newStatus,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Extend the bid deadline. Only for published RFQs.
     */
    public function extendDeadline(int $id, string $newDeadline): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('status', self::STATUS_PUBLISHED)
            ->update([
                'bid_deadline' => $newDeadline,
                'updated_at'   => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }
}
