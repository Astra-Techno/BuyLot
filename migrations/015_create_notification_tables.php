<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateNotificationTables extends Migration
{
    public function up(): void
    {
        // 7.5 notifications
        $this->execute("
            CREATE TABLE notifications (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                recipient_id BIGINT UNSIGNED NOT NULL,
                event_type   VARCHAR(100)    NOT NULL,
                entity_type  VARCHAR(50)     NULL,
                entity_id    BIGINT UNSIGNED NULL,
                title        VARCHAR(500)    NOT NULL,
                body         TEXT            NULL,
                read_at      TIMESTAMP       NULL     DEFAULT NULL,
                created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_notifications_recipient (recipient_id, read_at),
                CONSTRAINT fk_notif_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 7.6 notification_deliveries
        $this->execute("
            CREATE TABLE notification_deliveries (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                notification_id BIGINT UNSIGNED NOT NULL,
                channel         ENUM(
                                    'in_app',
                                    'sms',
                                    'email',
                                    'whatsapp'
                                )               NOT NULL,
                status          ENUM(
                                    'queued',
                                    'sent',
                                    'delivered',
                                    'failed'
                                )               NOT NULL DEFAULT 'queued',
                provider_ref    VARCHAR(255)    NULL,
                attempted_at    TIMESTAMP       NULL     DEFAULT NULL,
                created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_notif_del_notification (notification_id),
                KEY idx_notif_del_status       (status),
                CONSTRAINT fk_nd_notification FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('notification_deliveries');
        $this->dropTable('notifications');
    }
}
