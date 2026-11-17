<?php

declare(strict_types=1);

namespace App\Modules\Bidding\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * SellerBidRevision model -- APPEND-ONLY.
 *
 * Table: seller_bid_revisions
 *   id               BIGINT UNSIGNED AUTO_INCREMENT PK
 *   bid_id           BIGINT UNSIGNED NOT NULL
 *   revision_no      INT UNSIGNED NOT NULL (monotonically increasing, 1-based)
 *   unit_price_paise BIGINT NOT NULL
 *   tax_basis        VARCHAR(100) NOT NULL
 *   freight_paise    BIGINT NOT NULL DEFAULT 0
 *   moq              INT UNSIGNED NOT NULL
 *   max_qty          INT UNSIGNED NULL
 *   valid_until      TIMESTAMP NOT NULL
 *   lead_time_days   SMALLINT UNSIGNED NOT NULL
 *   warranty_snapshot JSON NULL
 *   evidence_ref     VARCHAR(500) NULL
 *   submitted_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
 *   created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
 *
 * UNIQUE KEY (bid_id, revision_no)
 *
 * IMMUTABILITY CONTRACT:
 *   - Rows in this table are NEVER updated or deleted.
 *   - Every revision creates a new row with an incremented revision_no.
 *   - Previous revisions remain intact for audit trail.
 */
class SellerBidRevision
{
    private const TABLE = 'seller_bid_revisions';

    // Columns returned to the owning seller
    private const SELLER_COLUMNS = [
        'id', 'bid_id', 'revision_no', 'unit_price_paise', 'tax_basis',
        'freight_paise', 'moq', 'max_qty', 'valid_until', 'lead_time_days',
        'warranty_snapshot', 'evidence_ref', 'submitted_at',
    ];

    // All columns for internal use
    private const INTERNAL_COLUMNS = [
        'id', 'bid_id', 'revision_no', 'unit_price_paise', 'tax_basis',
        'freight_paise', 'moq', 'max_qty', 'valid_until', 'lead_time_days',
        'warranty_snapshot', 'evidence_ref', 'submitted_at', 'created_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Find a specific revision by ID.
     */
    public function find(int $revisionId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::INTERNAL_COLUMNS)
            ->where('id', $revisionId)
            ->first();
    }

    /**
     * List all revisions for a bid (seller-safe columns).
     * Returns in chronological order (oldest first).
     */
    public function listByBid(int $bidId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::SELLER_COLUMNS)
            ->where('bid_id', $bidId)
            ->orderBy('revision_no', 'ASC')
            ->get();
    }

    /**
     * List all revisions for a bid (internal -- all columns).
     */
    public function listByBidInternal(int $bidId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::INTERNAL_COLUMNS)
            ->where('bid_id', $bidId)
            ->orderBy('revision_no', 'ASC')
            ->get();
    }

    /**
     * Get the latest revision for a bid.
     */
    public function getLatest(int $bidId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::INTERNAL_COLUMNS)
            ->where('bid_id', $bidId)
            ->orderBy('revision_no', 'DESC')
            ->first();
    }

    /**
     * Get the next revision number for a bid.
     */
    public function getNextRevisionNo(int $bidId): int
    {
        $latest = $this->getLatest($bidId);
        return $latest !== null ? ((int) $latest['revision_no'] + 1) : 1;
    }

    // -------------------------------------------------------------------------
    // Mutations -- APPEND-ONLY (no update, no delete)
    // -------------------------------------------------------------------------

    /**
     * Create a new revision. APPEND-ONLY: never updates existing rows.
     *
     * @return int New revision ID.
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'bid_id'            => $data['bid_id'],
                'revision_no'       => $data['revision_no'],
                'unit_price_paise'  => $data['unit_price_paise'],
                'tax_basis'         => $data['tax_basis'],
                'freight_paise'     => $data['freight_paise'] ?? 0,
                'moq'               => $data['moq'],
                'max_qty'           => $data['max_qty'] ?? null,
                'valid_until'       => $data['valid_until'],
                'lead_time_days'    => $data['lead_time_days'],
                'warranty_snapshot' => isset($data['warranty_snapshot'])
                    ? (is_string($data['warranty_snapshot'])
                        ? $data['warranty_snapshot']
                        : json_encode($data['warranty_snapshot']))
                    : null,
                'evidence_ref'      => $data['evidence_ref'] ?? null,
                'submitted_at'      => $now,
                'created_at'        => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // IMMUTABILITY ENFORCEMENT
    // No update() or delete() methods exist on this model by design.
    // seller_bid_revisions rows are append-only per business rules.
    // -------------------------------------------------------------------------
}
