<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Services;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;

/**
 * NotificationService — orchestrates notification creation and multi-channel dispatch.
 *
 * Responsibilities:
 *   - Deduplication: skip if same event_type + entity scope was sent to the
 *     same user within the last 5 minutes.
 *   - Channel preference enforcement: only dispatch via channels the user
 *     has enabled in `notification_preferences`.
 *   - Single and bulk send.
 *   - Mark read (single + all).
 *
 * Channel preferences table (expected schema):
 *   notification_preferences
 *     user_id      BIGINT  FK -> users.id
 *     channel      ENUM(in_app, sms, email, whatsapp)
 *     enabled      TINYINT(1) DEFAULT 1
 *
 * In-app is always enabled; it cannot be disabled.
 *
 * Sensitive data policy:
 *   The `body` passed to send() is used verbatim.  Callers MUST NOT include
 *   raw prices, payment amounts, or other sensitive purchase data in the body
 *   because it surfaces as a push notification preview on the device lock
 *   screen.  Use generic copy ("Your order has been updated.") and reference
 *   the entity_id for deep-linking.
 */
class NotificationService
{
    private const PREFERENCES_TABLE      = 'notification_preferences';
    private const DEDUP_WINDOW_SECONDS   = 300; // 5 minutes

    public function __construct(
        private readonly Notification               $notificationModel,
        private readonly NotificationChannelService $channelService,
        private readonly Connection                 $connection,
    ) {}

    // -------------------------------------------------------------------------
    // Send
    // -------------------------------------------------------------------------

    /**
     * Create and dispatch a notification to a single user.
     *
     * @param int         $userId      Recipient user id.
     * @param string      $eventType   One of Notification::EVENT_* constants.
     * @param string      $title       Short display title (no sensitive data).
     * @param string      $body        Body text (no sensitive purchase data).
     * @param array       $data        Contextual data: may include entity_type, entity_id.
     * @param string[]    $channels    Desired channels; preferences are still applied.
     *
     * @return int|null  The notification id, or null if deduplication suppressed it.
     */
    public function send(
        int    $userId,
        string $eventType,
        string $title,
        string $body,
        array  $data     = [],
        array  $channels = [NotificationDelivery::CHANNEL_IN_APP],
    ): ?int {
        $entityType = $data['entity_type'] ?? null;
        $entityId   = isset($data['entity_id']) ? (int) $data['entity_id'] : null;

        // Deduplication check
        if ($this->notificationModel->existsRecent(
            $userId,
            $eventType,
            $entityType,
            $entityId,
            self::DEDUP_WINDOW_SECONDS,
        )) {
            return null;
        }

        $notificationId = $this->notificationModel->insert([
            'recipient_id' => $userId,
            'event_type'   => $eventType,
            'entity_type'  => $entityType,
            'entity_id'    => $entityId,
            'title'        => $title,
            'body'         => $body,
        ]);

        $notification = $this->notificationModel->findById($notificationId);

        if ($notification === null) {
            return $notificationId;
        }

        $enabledChannels = $this->resolveChannels($userId, $channels);

        foreach ($enabledChannels as $channel) {
            $this->channelService->dispatch($notification, $channel);
        }

        return $notificationId;
    }

    /**
     * Send the same notification to multiple users.
     *
     * @param int[]    $userIds
     * @param string[] $channels
     *
     * @return array<int, int|null>  userId => notificationId (null if deduped)
     */
    public function sendBulk(
        array  $userIds,
        string $eventType,
        string $title,
        string $body,
        array  $data     = [],
        array  $channels = [NotificationDelivery::CHANNEL_IN_APP],
    ): array {
        $results = [];

        foreach ($userIds as $userId) {
            $results[$userId] = $this->send(
                (int) $userId,
                $eventType,
                $title,
                $body,
                $data,
                $channels,
            );
        }

        return $results;
    }

    // -------------------------------------------------------------------------
    // Mark read
    // -------------------------------------------------------------------------

    /**
     * Mark a single notification as read.
     * Returns false if the notification does not belong to the user.
     */
    public function markRead(int $notificationId, int $userId): bool
    {
        return $this->notificationModel->markRead($notificationId, $userId);
    }

    /**
     * Mark all notifications for a user as read.
     * Returns the count of rows updated.
     */
    public function markAllRead(int $userId): int
    {
        return $this->notificationModel->markAllRead($userId);
    }

    // -------------------------------------------------------------------------
    // Preferences
    // -------------------------------------------------------------------------

    /**
     * Fetch channel preferences for a user.
     * Returns an array keyed by channel with enabled bool.
     *
     * @return array<string, bool>
     */
    public function getPreferences(int $userId): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::PREFERENCES_TABLE)
            ->select('channel', 'enabled')
            ->where('user_id', $userId)
            ->get();

        // Start with defaults: in_app always on, others default on too
        $defaults = [
            NotificationDelivery::CHANNEL_IN_APP   => true,
            NotificationDelivery::CHANNEL_SMS      => true,
            NotificationDelivery::CHANNEL_EMAIL    => true,
            NotificationDelivery::CHANNEL_WHATSAPP => true,
        ];

        foreach ($rows as $row) {
            $defaults[$row['channel']] = (bool) $row['enabled'];
        }

        // in_app is always enabled regardless of stored preference
        $defaults[NotificationDelivery::CHANNEL_IN_APP] = true;

        return $defaults;
    }

    /**
     * Update channel preferences for a user.
     *
     * @param array<string, bool> $preferences  channel => enabled
     */
    public function updatePreferences(int $userId, array $preferences): void
    {
        foreach ($preferences as $channel => $enabled) {
            if (!in_array($channel, NotificationDelivery::VALID_CHANNELS, true)) {
                continue;
            }

            // in_app cannot be disabled
            if ($channel === NotificationDelivery::CHANNEL_IN_APP) {
                continue;
            }

            $existing = (new QueryBuilder($this->connection))
                ->table(self::PREFERENCES_TABLE)
                ->where('user_id', $userId)
                ->where('channel', $channel)
                ->first();

            if ($existing !== null) {
                (new QueryBuilder($this->connection))
                    ->table(self::PREFERENCES_TABLE)
                    ->where('user_id', $userId)
                    ->where('channel', $channel)
                    ->update(['enabled' => $enabled ? 1 : 0]);
            } else {
                (new QueryBuilder($this->connection))
                    ->table(self::PREFERENCES_TABLE)
                    ->insert([
                        'user_id' => $userId,
                        'channel' => $channel,
                        'enabled' => $enabled ? 1 : 0,
                    ]);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Intersect the requested channels with the user's enabled preferences.
     * in_app is always included.
     *
     * @param string[] $requested
     * @return string[]
     */
    private function resolveChannels(int $userId, array $requested): array
    {
        $preferences = $this->getPreferences($userId);

        $resolved = [];

        foreach ($requested as $channel) {
            if (!in_array($channel, NotificationDelivery::VALID_CHANNELS, true)) {
                continue;
            }

            if ($channel === NotificationDelivery::CHANNEL_IN_APP) {
                $resolved[] = $channel; // always on
                continue;
            }

            if ($preferences[$channel] ?? false) {
                $resolved[] = $channel;
            }
        }

        // Deduplicate
        return array_values(array_unique($resolved));
    }
}
