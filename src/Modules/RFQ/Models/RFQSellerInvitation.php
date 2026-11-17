<?php

declare(strict_types=1);

namespace App\Modules\RFQ\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * RFQSellerInvitation model.
 *
 * Table: rfq_seller_invitations
 *   id         BIGINT UNSIGNED AUTO_INCREMENT PK
 *   rfq_id     BIGINT UNSIGNED NOT NULL
 *   seller_id  BIGINT UNSIGNED NOT NULL (references seller_profiles.id)
 *   status     ENUM('invited','viewed','declined','bid_submitted')
 *   invited_at TIMESTAMP
 *   created_at TIMESTAMP
 *   updated_at TIMESTAMP
 *
 * UNIQUE KEY (rfq_id, seller_id)
 */
class RFQSellerInvitation
{
    private const TABLE = 'rfq_seller_invitations';

    public const STATUS_INVITED       = 'invited';
    public const STATUS_VIEWED        = 'viewed';
    public const STATUS_BID_SUBMITTED = 'bid_submitted';
    public const STATUS_DECLINED      = 'declined';

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->first();
    }

    public function findByRfqAndSeller(int $rfqId, int $sellerId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('rfq_id', $rfqId)
            ->where('seller_id', $sellerId)
            ->first();
    }

    /**
     * List all invitations for an RFQ (internal use only).
     */
    public function listByRfq(int $rfqId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('rfq_id', $rfqId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * List all invitations for a seller.
     */
    public function listBySeller(int $sellerId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('seller_id', $sellerId)
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    public function create(int $rfqId, int $sellerId): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'rfq_id'     => $rfqId,
                'seller_id'  => $sellerId,
                'status'     => self::STATUS_INVITED,
                'invited_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Bulk invite sellers for an RFQ. Skips duplicates silently.
     *
     * @param int[] $sellerIds
     */
    public function bulkInvite(int $rfqId, array $sellerIds): int
    {
        $invited = 0;
        $now = gmdate('Y-m-d H:i:s');

        foreach ($sellerIds as $sellerId) {
            $existing = $this->findByRfqAndSeller($rfqId, (int) $sellerId);
            if ($existing !== null) {
                continue; // Already invited — idempotent
            }

            (new QueryBuilder($this->connection))
                ->table(self::TABLE)
                ->insert([
                    'rfq_id'     => $rfqId,
                    'seller_id'  => (int) $sellerId,
                    'status'     => self::STATUS_INVITED,
                    'invited_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

            $invited++;
        }

        return $invited;
    }

    public function updateStatus(int $id, string $status): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'status'     => $status,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Mark invitation as viewed. Only if currently 'invited'.
     */
    public function markViewed(int $rfqId, int $sellerId): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('rfq_id', $rfqId)
            ->where('seller_id', $sellerId)
            ->where('status', self::STATUS_INVITED)
            ->update([
                'status'     => self::STATUS_VIEWED,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Mark invitation as bid_submitted.
     */
    public function markBidSubmitted(int $rfqId, int $sellerId): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('rfq_id', $rfqId)
            ->where('seller_id', $sellerId)
            ->update([
                'status'     => self::STATUS_BID_SUBMITTED,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }
}
