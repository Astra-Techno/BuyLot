<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * NotificationDelivery model.
 *
 * Maps the `notification_deliveries` table (Module 7, section 7.6).
 *
 * Schema columns:
 *   id               BIGINT UNSIGNED  PK
 *   notification_id  BIGINT UNSIGNED  FK -> notifications.id ON DELETE CASCADE
 *   channel          ENUM(in_app, sms, email, whatsapp)
 *   status           ENUM(queued, sent, delivered, failed)   DEFAULT queued
 *   provider_ref     VARCHAR(255)  NULL   provider-assigned message ID
 *   attempted_at     TIMESTAMP NULL
 *   created_at       TIMESTAMP
 *   updated_at       TIMESTAMP
 */
class NotificationDelivery
{
    public const TABLE = 'notification_deliveries';

    // Channels
    public const CHANNEL_IN_APP   = 'in_app';
    public const CHANNEL_SMS      = 'sms';
    public const CHANNEL_EMAIL    = 'email';
    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const VALID_CHANNELS = [
        self::CHANNEL_IN_APP,
        self::CHANNEL_SMS,
        self::CHANNEL_EMAIL,
        self::CHANNEL_WHATSAPP,
    ];

    // Statuses
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_SENT      = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED    = 'failed';

    public function __construct(private readonly Connection $connection) {}

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Create a delivery record for a notification on a given channel.
     * Returns the new row id.
     */
    public function insert(int $notificationId, string $channel): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'notification_id' => $notificationId,
                'channel'         => $channel,
                'status'          => self::STATUS_QUEUED,
                'provider_ref'    => null,
                'attempted_at'    => null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Update the delivery status for a given row.
     *
     * @param array{status: string, provider_ref?: string|null} $data
     */
    public function updateStatus(int $id, array $data): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        $payload = [
            'status'       => $data['status'],
            'attempted_at' => $now,
            'updated_at'   => $now,
        ];

        if (array_key_exists('provider_ref', $data)) {
            $payload['provider_ref'] = $data['provider_ref'];
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update($payload);

        return $rows > 0;
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
     * Fetch all delivery records for a notification.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forNotification(int $notificationId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('notification_id', $notificationId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * Count queued deliveries per channel (for monitoring).
     *
     * @return array<string, int>  channel => count
     */
    public function queuedCountByChannel(): array
    {
        $rows = $this->connection->query(
            'SELECT channel, COUNT(*) AS cnt FROM ' . self::TABLE
            . " WHERE status = '" . self::STATUS_QUEUED . "' GROUP BY channel",
        );

        $out = [];
        foreach ($rows as $row) {
            $out[$row['channel']] = (int) $row['cnt'];
        }

        return $out;
    }
}
