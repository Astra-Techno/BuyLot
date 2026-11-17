<?php

declare(strict_types=1);

namespace App\Modules\Orders\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * Order model.
 *
 * Table: orders
 *   id           BIGINT UNSIGNED AUTO_INCREMENT PK
 *   buyer_id     BIGINT UNSIGNED NOT NULL
 *   group_id     BIGINT UNSIGNED NOT NULL
 *   offer_id     BIGINT UNSIGNED NOT NULL
 *   status       ENUM('pending_payment','payment_confirmed','processing','dispatched',
 *                     'delivered','accepted','disputed','completed','cancelled','refunded')
 *   total_paise  BIGINT NOT NULL
 *   currency     CHAR(3) DEFAULT 'INR'
 *   terms_hash   VARCHAR(128) NOT NULL
 *   deleted_at   TIMESTAMP NULL
 *   created_at   TIMESTAMP
 *   updated_at   TIMESTAMP
 */
class Order
{
    private const TABLE = 'orders';

    // States matching the state machine spec (Section 5)
    public const STATUS_PENDING_PAYMENT    = 'pending_payment';
    public const STATUS_PAYMENT_CONFIRMED  = 'payment_confirmed';
    public const STATUS_PROCESSING         = 'processing';
    public const STATUS_DISPATCHED         = 'dispatched';
    public const STATUS_DELIVERED          = 'delivered';
    public const STATUS_ACCEPTED           = 'accepted';
    public const STATUS_DISPUTED           = 'disputed';
    public const STATUS_COMPLETED          = 'completed';
    public const STATUS_CANCELLED          = 'cancelled';
    public const STATUS_REFUNDED           = 'refunded';

    public const ALL_STATUSES = [
        self::STATUS_PENDING_PAYMENT,
        self::STATUS_PAYMENT_CONFIRMED,
        self::STATUS_PROCESSING,
        self::STATUS_DISPATCHED,
        self::STATUS_DELIVERED,
        self::STATUS_ACCEPTED,
        self::STATUS_DISPUTED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_REFUNDED,
    ];

    /** States from which a buyer may cancel. */
    public const CANCELLABLE_STATUSES = [
        self::STATUS_PENDING_PAYMENT,
    ];

    /** Valid state transitions: from => [to, ...] */
    public const TRANSITIONS = [
        self::STATUS_PENDING_PAYMENT   => [self::STATUS_PAYMENT_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_PAYMENT_CONFIRMED => [self::STATUS_PROCESSING, self::STATUS_CANCELLED, self::STATUS_REFUNDED],
        self::STATUS_PROCESSING        => [self::STATUS_DISPATCHED],
        self::STATUS_DISPATCHED        => [self::STATUS_DELIVERED],
        self::STATUS_DELIVERED         => [self::STATUS_ACCEPTED, self::STATUS_DISPUTED],
        self::STATUS_ACCEPTED          => [self::STATUS_COMPLETED, self::STATUS_REFUNDED],
        self::STATUS_DISPUTED          => [self::STATUS_COMPLETED, self::STATUS_REFUNDED],
        self::STATUS_COMPLETED         => [],
        self::STATUS_CANCELLED         => [],
        self::STATUS_REFUNDED          => [],
    ];

    private const PUBLIC_COLUMNS = [
        'id', 'buyer_id', 'group_id', 'offer_id', 'status',
        'total_paise', 'currency', 'terms_hash',
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
            ->select(...self::PUBLIC_COLUMNS)
            ->where('id', $id)
            ->where('deleted_at', null)
            ->first();
    }

    /**
     * Paginated list of orders for a specific buyer.
     *
     * @param array{status?: string, offer_id?: int} $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listByBuyer(int $buyerId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('buyer_id', $buyerId)
            ->where('deleted_at', null);

        if (!empty($filters['status'])) {
            $qb->where('status', $filters['status']);
        }

        if (!empty($filters['offer_id'])) {
            $qb->where('offer_id', (int) $filters['offer_id']);
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

    /**
     * Paginated list of orders filtered by status (admin).
     *
     * @param array{status?: string, buyer_id?: int, offer_id?: int, group_id?: int} $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listByStatus(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('deleted_at', null);

        if (!empty($filters['status'])) {
            $qb->where('status', $filters['status']);
        }

        if (!empty($filters['buyer_id'])) {
            $qb->where('buyer_id', (int) $filters['buyer_id']);
        }

        if (!empty($filters['offer_id'])) {
            $qb->where('offer_id', (int) $filters['offer_id']);
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

    /**
     * Create a new order. Returns the order ID.
     *
     * @param array{buyer_id: int, group_id: int, offer_id: int, total_paise: int, terms_hash: string} $data
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'buyer_id'   => $data['buyer_id'],
                'group_id'   => $data['group_id'],
                'offer_id'   => $data['offer_id'],
                'status'     => self::STATUS_PENDING_PAYMENT,
                'total_paise' => $data['total_paise'],
                'currency'   => 'INR',
                'terms_hash' => $data['terms_hash'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Atomically update order status with state-machine guard.
     *
     * Uses optimistic concurrency: only updates if current status matches $fromStatus.
     * Returns true if the row was updated.
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
     * Count paid orders for a given offer (used for MOQ check).
     */
    public function countPaidByOffer(int $offerId): int
    {
        $result = $this->connection->query(
            'SELECT COALESCE(SUM(oi.qty), 0) AS paid_qty
             FROM orders o
             INNER JOIN order_items oi ON oi.order_id = o.id
             WHERE o.offer_id = ?
               AND o.status = ?
               AND o.deleted_at IS NULL',
            [$offerId, self::STATUS_PAYMENT_CONFIRMED],
        );

        return (int) ($result[0]['paid_qty'] ?? 0);
    }

    /**
     * Find all paid orders for a given offer (for release-to-seller batch).
     *
     * @return array<array>
     */
    public function findPaidByOffer(int $offerId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('offer_id', $offerId)
            ->where('status', self::STATUS_PAYMENT_CONFIRMED)
            ->where('deleted_at', null)
            ->get();
    }
}
