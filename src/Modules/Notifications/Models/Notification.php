<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use LogicException;

/**
 * Notification model.
 *
 * Maps the `notifications` table (Module 7, section 7.5).
 *
 * Schema columns used:
 *   id            BIGINT UNSIGNED  PK AUTO_INCREMENT
 *   recipient_id  BIGINT UNSIGNED  FK -> users.id
 *   event_type    VARCHAR(100)     e.g. offer_ready, payment_success
 *   entity_type   VARCHAR(50)      polymorphic context (order, offer, …)
 *   entity_id     BIGINT UNSIGNED  polymorphic context id
 *   title         VARCHAR(500)
 *   body          TEXT
 *   read_at       TIMESTAMP NULL
 *   created_at    TIMESTAMP
 *   updated_at    TIMESTAMP
 *
 * Additional runtime columns not in the base schema (stored as JSON in body
 * or derived at service layer):
 *   channel       IN_APP | SMS | EMAIL | WHATSAPP  (stored per delivery row)
 *   data_json     arbitrary context JSON           (serialised into body/entity)
 *
 * Channel preferences are stored in a separate `notification_preferences` table
 * managed by NotificationService; this model only reads that table for queries.
 */
class Notification
{
    public const TABLE = 'notifications';

    // Canonical event types from spec S17
    public const EVENT_OFFER_READY           = 'offer_ready';
    public const EVENT_RECONFIRMATION_NEEDED = 'reconfirmation_needed';
    public const EVENT_PAYMENT_SUCCESS       = 'payment_success';
    public const EVENT_PAYMENT_FAILED        = 'payment_failed';
    public const EVENT_MOQ_REACHED           = 'moq_reached';
    public const EVENT_MOQ_FAILED            = 'moq_failed';
    public const EVENT_SELLER_DISPATCHED     = 'seller_dispatched';
    public const EVENT_DELIVERY_CONFIRMED    = 'delivery_confirmed';
    public const EVENT_DISPUTE_UPDATE        = 'dispute_update';
    public const EVENT_REFUND_CONFIRMED      = 'refund_confirmed';
    public const EVENT_SETTLEMENT_CONFIRMED  = 'settlement_confirmed';

    public const VALID_EVENT_TYPES = [
        self::EVENT_OFFER_READY,
        self::EVENT_RECONFIRMATION_NEEDED,
        self::EVENT_PAYMENT_SUCCESS,
        self::EVENT_PAYMENT_FAILED,
        self::EVENT_MOQ_REACHED,
        self::EVENT_MOQ_FAILED,
        self::EVENT_SELLER_DISPATCHED,
        self::EVENT_DELIVERY_CONFIRMED,
        self::EVENT_DISPUTE_UPDATE,
        self::EVENT_REFUND_CONFIRMED,
        self::EVENT_SETTLEMENT_CONFIRMED,
    ];

    public function __construct(private readonly Connection $connection) {}

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a new notification row and return its id.
     */
    public function insert(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'recipient_id' => $data['recipient_id'],
                'event_type'   => $data['event_type'],
                'entity_type'  => $data['entity_type'] ?? null,
                'entity_id'    => $data['entity_id'] ?? null,
                'title'        => $data['title'],
                'body'         => $data['body'] ?? null,
                'read_at'      => null,
                'created_at'   => $now,
                'updated_at'   => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Mark a notification as read.
     * Returns true if the row was actually updated (owned by the given user).
     */
    public function markRead(int $id, int $userId): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('recipient_id', $userId)
            ->where('read_at', null)
            ->update([
                'read_at'    => gmdate('Y-m-d H:i:s'),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Mark all unread notifications for a user as read.
     * Returns the number of rows updated.
     */
    public function markAllRead(int $userId): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('recipient_id', $userId)
            ->where('read_at', null)
            ->update([
                'read_at'    => $now,
                'updated_at' => $now,
            ]);
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    public function findById(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->first();
    }

    /**
     * Paginated list of notifications for a user.
     *
     * @return array{data: array, total: int, unread_count: int, page: int, per_page: int}
     */
    public function listForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $baseQb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('recipient_id', $userId);

        $total = (clone $baseQb)->count();

        $unreadQb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('recipient_id', $userId)
            ->where('read_at', null);
        $unreadCount = $unreadQb->count();

        $rows = $baseQb
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'         => $rows,
            'total'        => $total,
            'unread_count' => $unreadCount,
            'page'         => $page,
            'per_page'     => $perPage,
        ];
    }

    /**
     * Check whether a notification of a given event_type + entity scope was
     * already sent to this user within the last $withinSeconds seconds.
     * Used for deduplication.
     */
    public function existsRecent(
        int    $userId,
        string $eventType,
        ?string $entityType,
        ?int   $entityId,
        int    $withinSeconds = 300,
    ): bool {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $withinSeconds);

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('recipient_id', $userId)
            ->where('event_type', $eventType)
            ->where('created_at', '>=', $cutoff);

        if ($entityType !== null) {
            $qb->where('entity_type', $entityType);
        }

        if ($entityId !== null) {
            $qb->where('entity_id', $entityId);
        }

        return $qb->count() > 0;
    }

    // -------------------------------------------------------------------------
    // Immutability guard — notifications are never hard-deleted via this model
    // -------------------------------------------------------------------------

    /** @throws LogicException always */
    public function delete(): never
    {
        throw new LogicException(
            'Notifications must not be hard-deleted via this model. Use soft-delete or cascade on user deletion.',
        );
    }
}
