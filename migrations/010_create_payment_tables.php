<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreatePaymentTables extends Migration
{
    public function up(): void
    {
        // 5.1 payment_intents
        $this->execute("
            CREATE TABLE payment_intents (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id        BIGINT UNSIGNED NOT NULL,
                provider        VARCHAR(50)     NOT NULL,
                provider_ref    VARCHAR(255)    NULL,
                amount_paise    BIGINT          NOT NULL,
                status          ENUM(
                                    'created',
                                    'pending',
                                    'authorized',
                                    'captured',
                                    'failed',
                                    'refunded',
                                    'cancelled'
                                )               NOT NULL DEFAULT 'created',
                idempotency_key VARCHAR(255)    NOT NULL,
                created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (amount_paise > 0),

                PRIMARY KEY (id),
                UNIQUE KEY uq_payment_idempotency    (idempotency_key),
                KEY idx_payment_intents_order        (order_id),
                KEY idx_payment_intents_provider     (provider, provider_ref),
                CONSTRAINT fk_pi_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 5.2 payment_webhook_events (append-only — no updated_at)
        $this->execute("
            CREATE TABLE payment_webhook_events (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                provider          VARCHAR(50)     NOT NULL,
                provider_event_id VARCHAR(255)    NOT NULL,
                signature_valid   TINYINT(1)      NOT NULL DEFAULT 0,
                payload_ref       VARCHAR(500)    NULL,
                processed_at      TIMESTAMP       NULL     DEFAULT NULL,
                created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_webhook_provider_event (provider, provider_event_id),
                KEY idx_webhook_processed (processed_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 5.3 refunds
        $this->execute("
            CREATE TABLE refunds (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                payment_id    BIGINT UNSIGNED NOT NULL,
                order_item_id BIGINT UNSIGNED NULL,
                amount_paise  BIGINT          NOT NULL,
                reason        VARCHAR(500)    NOT NULL,
                status        ENUM(
                                  'requested',
                                  'approved',
                                  'processing',
                                  'completed',
                                  'failed',
                                  'rejected'
                              )               NOT NULL DEFAULT 'requested',
                provider_ref  VARCHAR(255)    NULL,
                approved_by   BIGINT UNSIGNED NULL,
                created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (amount_paise > 0),

                PRIMARY KEY (id),
                KEY idx_refunds_payment (payment_id),
                KEY idx_refunds_status  (status),
                CONSTRAINT fk_refunds_payment    FOREIGN KEY (payment_id)    REFERENCES payment_intents (id) ON DELETE RESTRICT,
                CONSTRAINT fk_refunds_order_item FOREIGN KEY (order_item_id) REFERENCES order_items     (id) ON DELETE SET NULL,
                CONSTRAINT fk_refunds_approved   FOREIGN KEY (approved_by)   REFERENCES users           (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('refunds');
        $this->dropTable('payment_webhook_events');
        $this->dropTable('payment_intents');
    }
}
