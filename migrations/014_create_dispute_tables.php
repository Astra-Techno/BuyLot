<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateDisputeTables extends Migration
{
    public function up(): void
    {
        // 7.1 disputes
        $this->execute("
            CREATE TABLE disputes (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id    BIGINT UNSIGNED NOT NULL,
                type        ENUM(
                                'wrong_item',
                                'quantity_short',
                                'damaged',
                                'not_delivered',
                                'quality_issue',
                                'payment_issue',
                                'other'
                            )               NOT NULL,
                status      ENUM(
                                'open',
                                'investigating',
                                'evidence_requested',
                                'resolved_buyer_favour',
                                'resolved_seller_favour',
                                'escalated',
                                'closed'
                            )               NOT NULL DEFAULT 'open',
                opened_by   BIGINT UNSIGNED NOT NULL,
                assigned_to BIGINT UNSIGNED NULL,
                resolution  TEXT            NULL,
                closed_at   TIMESTAMP       NULL     DEFAULT NULL,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_disputes_order  (order_id),
                KEY idx_disputes_status (status),
                CONSTRAINT fk_disp_order       FOREIGN KEY (order_id)    REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_disp_opened_by   FOREIGN KEY (opened_by)   REFERENCES users  (id) ON DELETE RESTRICT,
                CONSTRAINT fk_disp_assigned_to FOREIGN KEY (assigned_to) REFERENCES users  (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 7.2 dispute_events (append-only — no updated_at)
        $this->execute("
            CREATE TABLE dispute_events (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                dispute_id BIGINT UNSIGNED NOT NULL,
                from_state VARCHAR(50)     NULL,
                to_state   VARCHAR(50)     NOT NULL,
                actor_id   BIGINT UNSIGNED NULL,
                note       TEXT            NULL,
                created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_dispute_events_dispute (dispute_id),
                CONSTRAINT fk_de_dispute FOREIGN KEY (dispute_id) REFERENCES disputes (id) ON DELETE RESTRICT,
                CONSTRAINT fk_de_actor   FOREIGN KEY (actor_id)   REFERENCES users    (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 7.3 dispute_evidence (append-only — no updated_at)
        $this->execute("
            CREATE TABLE dispute_evidence (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                dispute_id     BIGINT UNSIGNED NOT NULL,
                submitted_by   BIGINT UNSIGNED NOT NULL,
                submitter_role VARCHAR(50)     NOT NULL,
                evidence_type  VARCHAR(50)     NOT NULL,
                object_ref     VARCHAR(500)    NOT NULL,
                description    VARCHAR(1000)   NULL,
                created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_disp_evidence_dispute (dispute_id),
                CONSTRAINT fk_dev_dispute      FOREIGN KEY (dispute_id)   REFERENCES disputes (id) ON DELETE RESTRICT,
                CONSTRAINT fk_dev_submitted_by FOREIGN KEY (submitted_by) REFERENCES users    (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 7.4 dispute_adjustments
        $this->execute("
            CREATE TABLE dispute_adjustments (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                dispute_id   BIGINT UNSIGNED NOT NULL,
                type         ENUM(
                                 'refund',
                                 'partial_refund',
                                 'replacement',
                                 'credit_note',
                                 'seller_deduction'
                             )               NOT NULL,
                amount_paise BIGINT          NOT NULL DEFAULT 0,
                description  TEXT            NULL,
                approved_by  BIGINT UNSIGNED NOT NULL,
                executed_at  TIMESTAMP       NULL     DEFAULT NULL,
                created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (amount_paise >= 0),

                PRIMARY KEY (id),
                KEY idx_disp_adj_dispute (dispute_id),
                CONSTRAINT fk_da_dispute     FOREIGN KEY (dispute_id) REFERENCES disputes (id) ON DELETE RESTRICT,
                CONSTRAINT fk_da_approved_by FOREIGN KEY (approved_by) REFERENCES users   (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('dispute_adjustments');
        $this->dropTable('dispute_evidence');
        $this->dropTable('dispute_events');
        $this->dropTable('disputes');
    }
}
