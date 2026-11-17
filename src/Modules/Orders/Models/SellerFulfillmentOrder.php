<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * SellerFulfillmentOrder model.
 *
 * Table: seller_fulfillment_orders
 *   id                        BIGINT UNSIGNED AUTO_INCREMENT PK
 *   seller_id                 BIGINT UNSIGNED NOT NULL (references seller_profiles.id)
 *   offer_id                  BIGINT UNSIGNED NOT NULL
 *   status                    ENUM('pending_confirmation','confirmed','preparing','dispatched','completed','cancelled')
 *   supplier_confirmation_ref VARCHAR(255) NULL
 *   created_at                TIMESTAMP
 *   updated_at                TIMESTAMP
 *
 * Created only after MOQ met + seller confirms.
 */
class SellerFulfillmentOrder
{
    private const TABLE = 'seller_fulfillment_orders';

    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';
    public const STATUS_CONFIRMED            = 'confirmed';
    public const STATUS_PREPARING            = 'preparing';
    public const STATUS_DISPATCHED           = 'dispatched';
    public const STATUS_COMPLETED            = 'completed';
    public const STATUS_CANCELLED            = 'cancelled';

    public const TRANSITIONS = [
        self::STATUS_PENDING_CONFIRMATION => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED            => [self::STATUS_PREPARING, self::STATUS_CANCELLED],
        self::STATUS_PREPARING            => [self::STATUS_DISPATCHED],
        self::STATUS_DISPATCHED           => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED            => [],
        self::STATUS_CANCELLED            => [],
    ];

    private const PUBLIC_COLUMNS = [
        'id', 'seller_id', 'offer_id', 'status',
        'supplier_confirmation_ref', 'created_at', 'updated_at',
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

    public function findByOffer(int $offerId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('offer_id', $offerId)
            ->first();
    }

    /**
     * @return array<array>
     */
    public function listBySeller(int $sellerId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('seller_id', $sellerId)
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a seller fulfillment order. Returns the new ID.
     */
    public function create(int $sellerId, int $offerId): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'seller_id'  => $sellerId,
                'offer_id'   => $offerId,
                'status'     => self::STATUS_PENDING_CONFIRMATION,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Atomically update status with state-machine guard.
     */
    public function updateStatus(int $id, string $fromStatus, string $toStatus): bool
    {
        $allowed = self::TRANSITIONS[$fromStatus] ?? [];
        if (!in_array($toStatus, $allowed, true)) {
            return false;
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('status', $fromStatus)
            ->update([
                'status'     => $toStatus,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Record the supplier's confirmation reference.
     */
    public function setConfirmationRef(int $id, string $ref): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'supplier_confirmation_ref' => $ref,
                'updated_at'               => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }
}
