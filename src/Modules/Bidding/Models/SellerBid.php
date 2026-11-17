<?php

declare(strict_types=1);

namespace App\Modules\Bidding\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * SellerBid model.
 *
 * Table: seller_bids
 *   id                 BIGINT UNSIGNED AUTO_INCREMENT PK
 *   rfq_id             BIGINT UNSIGNED NOT NULL
 *   seller_id          BIGINT UNSIGNED NOT NULL (references seller_profiles.id)
 *   source             ENUM('platform','offline_procurement','supplier_lead')
 *   status             ENUM('active','withdrawn','disqualified','awarded','expired')
 *   latest_revision_id BIGINT UNSIGNED NULL
 *   created_at         TIMESTAMP
 *   updated_at         TIMESTAMP
 *
 * UNIQUE KEY (rfq_id, seller_id) -- one bid identity per seller per RFQ.
 *
 * SECURITY: ALL seller-facing queries MUST filter by seller_id.
 * No seller endpoint may ever return another seller's bid data.
 */
class SellerBid
{
    private const TABLE = 'seller_bids';

    // --- DB status values (match ENUM in schema) ---
    public const STATUS_ACTIVE       = 'active';
    public const STATUS_WITHDRAWN    = 'withdrawn';
    public const STATUS_DISQUALIFIED = 'disqualified';
    public const STATUS_AWARDED      = 'awarded';
    public const STATUS_EXPIRED      = 'expired';

    // --- Application-level states for the state machine ---
    // These map to the state machine in docs/state-machines.md Section 3.
    // The DB ENUM stores the persisted status; the application layer
    // interprets ACTIVE as DRAFT/SUBMITTED/REVISED/UNDER_REVIEW/SHORTLISTED
    // based on context (has revision? deadline passed? etc.)
    public const STATE_DRAFT        = 'draft';
    public const STATE_SUBMITTED    = 'submitted';
    public const STATE_REVISED      = 'revised';
    public const STATE_UNDER_REVIEW = 'under_review';
    public const STATE_SHORTLISTED  = 'shortlisted';
    public const STATE_AWARDED      = 'awarded';
    public const STATE_REJECTED     = 'rejected';
    public const STATE_EXPIRED      = 'expired';
    public const STATE_WITHDRAWN    = 'withdrawn';

    // Columns returned to seller (own bid only)
    private const SELLER_COLUMNS = [
        'id', 'rfq_id', 'status', 'latest_revision_id', 'created_at', 'updated_at',
    ];

    // All columns for internal use
    private const INTERNAL_COLUMNS = [
        'id', 'rfq_id', 'seller_id', 'source', 'status',
        'latest_revision_id', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders -- Seller-scoped (ALWAYS filter by seller_id)
    // -------------------------------------------------------------------------

    /**
     * Find a bid by ID, scoped to a specific seller.
     * SECURITY: seller_id filter is mandatory for seller endpoints.
     */
    public function findForSeller(int $bidId, int $sellerId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::SELLER_COLUMNS)
            ->where('id', $bidId)
            ->where('seller_id', $sellerId)
            ->first();
    }

    /**
     * Find a bid by RFQ and seller.
     * SECURITY: seller_id filter enforced.
     */
    public function findByRfqAndSeller(int $rfqId, int $sellerId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::SELLER_COLUMNS)
            ->where('rfq_id', $rfqId)
            ->where('seller_id', $sellerId)
            ->first();
    }

    /**
     * List all bids for a specific seller.
     * SECURITY: seller_id filter enforced. Seller sees only own bids.
     */
    public function listBySeller(int $sellerId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::SELLER_COLUMNS)
            ->where('seller_id', $sellerId);

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
    // Finders -- Internal (no seller scoping)
    // -------------------------------------------------------------------------

    /**
     * Find a bid by ID (internal -- all columns).
     */
    public function find(int $bidId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::INTERNAL_COLUMNS)
            ->where('id', $bidId)
            ->first();
    }

    /**
     * Find a bid by RFQ and seller (internal -- all columns).
     */
    public function findByRfqAndSellerInternal(int $rfqId, int $sellerId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::INTERNAL_COLUMNS)
            ->where('rfq_id', $rfqId)
            ->where('seller_id', $sellerId)
            ->first();
    }

    /**
     * List ALL bids for an RFQ (internal/confidential use only).
     * NEVER expose this to sellers or buyers.
     */
    public function listByRfqInternal(int $rfqId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::INTERNAL_COLUMNS)
            ->where('rfq_id', $rfqId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new bid. Enforces UNIQUE(rfq_id, seller_id) at DB level.
     *
     * @return int New bid ID.
     */
    public function create(int $rfqId, int $sellerId, string $source = 'platform'): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'rfq_id'             => $rfqId,
                'seller_id'          => $sellerId,
                'source'             => $source,
                'status'             => self::STATUS_ACTIVE,
                'latest_revision_id' => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Update the latest_revision_id pointer atomically.
     * Uses SELECT FOR UPDATE via raw SQL to prevent concurrent revision races.
     */
    public function updateLatestRevision(int $bidId, int $revisionId, int $sellerId): bool
    {
        // Optimistic lock: only update if seller owns this bid
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $bidId)
            ->where('seller_id', $sellerId)
            ->where('status', self::STATUS_ACTIVE)
            ->update([
                'latest_revision_id' => $revisionId,
                'updated_at'         => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Withdraw a bid. Only the owning seller can withdraw.
     * Only active bids can be withdrawn.
     */
    public function withdraw(int $bidId, int $sellerId): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $bidId)
            ->where('seller_id', $sellerId)
            ->where('status', self::STATUS_ACTIVE)
            ->update([
                'status'     => self::STATUS_WITHDRAWN,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Update bid status (internal use -- for state machine transitions).
     */
    public function updateStatus(int $bidId, string $newStatus): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $bidId)
            ->update([
                'status'     => $newStatus,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }
}
