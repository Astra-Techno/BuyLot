<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateSettlementTables extends Migration
{
    public function up(): void
    {
        // 5.7 seller_settlements
        $this->execute("
            CREATE TABLE seller_settlements (
                id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                seller_id        BIGINT UNSIGNED NOT NULL,
                provider_ref     VARCHAR(255)    NULL,
                eligible_paise   BIGINT          NOT NULL,
                requested_paise  BIGINT          NOT NULL,
                confirmed_paise  BIGINT          NULL,
                status           ENUM(
                                     'calculating',
                                     'pending_approval',
                                     'approved',
                                     'processing',
                                     'completed',
                                     'failed',
                                     'on_hold'
                                 )               NOT NULL DEFAULT 'calculating',
                created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (eligible_paise  >= 0),
                CHECK (requested_paise >= 0),
                CHECK (confirmed_paise IS NULL OR confirmed_paise >= 0),

                PRIMARY KEY (id),
                KEY idx_settlements_seller_status (seller_id, status),
                CONSTRAINT fk_ss_seller FOREIGN KEY (seller_id) REFERENCES seller_profiles (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 5.8 settlement_items
        $this->execute("
            CREATE TABLE settlement_items (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                settlement_id BIGINT UNSIGNED NOT NULL,
                order_item_id BIGINT UNSIGNED NOT NULL,
                eligible_qty  INT UNSIGNED    NOT NULL,
                amount_paise  BIGINT          NOT NULL,
                created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (eligible_qty  > 0),
                CHECK (amount_paise >= 0),

                PRIMARY KEY (id),
                KEY idx_si_settlement (settlement_id),
                KEY idx_si_order_item (order_item_id),
                CONSTRAINT fk_si_settlement FOREIGN KEY (settlement_id) REFERENCES seller_settlements (id) ON DELETE CASCADE,
                CONSTRAINT fk_si_order_item FOREIGN KEY (order_item_id) REFERENCES order_items        (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 5.9 settlement_approvals
        $this->execute("
            CREATE TABLE settlement_approvals (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                settlement_id BIGINT UNSIGNED NOT NULL,
                approver_id   BIGINT UNSIGNED NOT NULL,
                decision      ENUM(
                                  'pending',
                                  'approved',
                                  'rejected'
                              )               NOT NULL DEFAULT 'pending',
                reason        TEXT            NULL,
                decided_at    TIMESTAMP       NULL     DEFAULT NULL,
                created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_settlement_approvals_settlement (settlement_id),
                CONSTRAINT fk_sap_settlement FOREIGN KEY (settlement_id) REFERENCES seller_settlements (id) ON DELETE CASCADE,
                CONSTRAINT fk_sap_approver   FOREIGN KEY (approver_id)   REFERENCES users              (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 5.10 reconciliation_runs
        $this->execute("
            CREATE TABLE reconciliation_runs (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                provider     VARCHAR(50)     NOT NULL,
                period_start TIMESTAMP       NOT NULL,
                period_end   TIMESTAMP       NOT NULL,
                status       ENUM(
                                 'in_progress',
                                 'completed',
                                 'failed'
                             )               NOT NULL DEFAULT 'in_progress',
                run_by       BIGINT UNSIGNED NULL,
                created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_recon_runs_provider (provider, period_start),
                CONSTRAINT fk_rr_run_by FOREIGN KEY (run_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 5.11 reconciliation_exceptions
        $this->execute("
            CREATE TABLE reconciliation_exceptions (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                run_id            BIGINT UNSIGNED NOT NULL,
                entity_type       VARCHAR(50)     NOT NULL,
                entity_id         BIGINT UNSIGNED NOT NULL,
                discrepancy_paise BIGINT          NOT NULL,
                description       TEXT            NULL,
                status            ENUM(
                                      'open',
                                      'investigating',
                                      'resolved',
                                      'waived'
                                  )               NOT NULL DEFAULT 'open',
                resolved_by       BIGINT UNSIGNED NULL,
                resolved_at       TIMESTAMP       NULL     DEFAULT NULL,
                created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_recon_exc_run    (run_id),
                KEY idx_recon_exc_entity (entity_type, entity_id),
                CONSTRAINT fk_re_run         FOREIGN KEY (run_id)      REFERENCES reconciliation_runs (id) ON DELETE RESTRICT,
                CONSTRAINT fk_re_resolved_by FOREIGN KEY (resolved_by) REFERENCES users               (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('reconciliation_exceptions');
        $this->dropTable('reconciliation_runs');
        $this->dropTable('settlement_approvals');
        $this->dropTable('settlement_items');
        $this->dropTable('seller_settlements');
    }
}
