<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateRfqTables extends Migration
{
    public function up(): void
    {
        // 3.1 rfqs
        $this->execute("
            CREATE TABLE rfqs (
                id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                group_id           BIGINT UNSIGNED NOT NULL,
                spec_snapshot_json JSON            NOT NULL,
                qty_band_json      JSON            NOT NULL,
                bid_deadline       TIMESTAMP       NOT NULL,
                status             ENUM(
                                       'draft',
                                       'published',
                                       'closed',
                                       'awarded',
                                       'cancelled'
                                   )               NOT NULL DEFAULT 'draft',
                created_by         BIGINT UNSIGNED NULL,
                created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_rfqs_group  (group_id),
                KEY idx_rfqs_status (status),
                CONSTRAINT fk_rfqs_group      FOREIGN KEY (group_id)   REFERENCES demand_groups (id) ON DELETE RESTRICT,
                CONSTRAINT fk_rfqs_created_by FOREIGN KEY (created_by) REFERENCES users         (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3.2 rfq_seller_invitations
        $this->execute("
            CREATE TABLE rfq_seller_invitations (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                rfq_id     BIGINT UNSIGNED NOT NULL,
                seller_id  BIGINT UNSIGNED NOT NULL,
                status     ENUM(
                               'invited',
                               'viewed',
                               'declined',
                               'bid_submitted'
                           )               NOT NULL DEFAULT 'invited',
                invited_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_rfq_invitation (rfq_id, seller_id),
                KEY idx_rfq_inv_seller (seller_id),
                CONSTRAINT fk_rfq_inv_rfq    FOREIGN KEY (rfq_id)    REFERENCES rfqs            (id) ON DELETE CASCADE,
                CONSTRAINT fk_rfq_inv_seller FOREIGN KEY (seller_id) REFERENCES seller_profiles (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3.3 seller_bids
        $this->execute("
            CREATE TABLE seller_bids (
                id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                rfq_id             BIGINT UNSIGNED NOT NULL,
                seller_id          BIGINT UNSIGNED NOT NULL,
                source             ENUM(
                                       'platform',
                                       'offline_procurement',
                                       'supplier_lead'
                                   )               NOT NULL DEFAULT 'platform',
                status             ENUM(
                                       'active',
                                       'withdrawn',
                                       'disqualified',
                                       'awarded',
                                       'expired'
                                   )               NOT NULL DEFAULT 'active',
                latest_revision_id BIGINT UNSIGNED NULL,
                created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_seller_bids_rfq_seller   (rfq_id, seller_id),
                KEY idx_seller_bids_rfq_seller (rfq_id, seller_id),
                KEY idx_seller_bids_seller     (seller_id),
                CONSTRAINT fk_seller_bids_rfq    FOREIGN KEY (rfq_id)    REFERENCES rfqs            (id) ON DELETE RESTRICT,
                CONSTRAINT fk_seller_bids_seller FOREIGN KEY (seller_id) REFERENCES seller_profiles (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3.4 seller_bid_revisions
        $this->execute("
            CREATE TABLE seller_bid_revisions (
                id               BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
                bid_id           BIGINT UNSIGNED   NOT NULL,
                revision_no      INT UNSIGNED      NOT NULL,
                unit_price_paise BIGINT            NOT NULL,
                tax_basis        VARCHAR(100)      NOT NULL,
                freight_paise    BIGINT            NOT NULL DEFAULT 0,
                moq              INT UNSIGNED      NOT NULL,
                max_qty          INT UNSIGNED      NULL,
                valid_until      TIMESTAMP         NOT NULL,
                lead_time_days   SMALLINT UNSIGNED NOT NULL,
                warranty_snapshot JSON             NULL,
                evidence_ref     VARCHAR(500)      NULL,
                submitted_at     TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at       TIMESTAMP         NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CHECK (unit_price_paise  >= 0),
                CHECK (freight_paise     >= 0),
                CHECK (moq               >  0),
                CHECK (max_qty IS NULL OR max_qty >= moq),

                PRIMARY KEY (id),
                UNIQUE KEY uq_bid_revision (bid_id, revision_no),
                KEY idx_sbr_bid (bid_id),
                CONSTRAINT fk_sbr_bid FOREIGN KEY (bid_id) REFERENCES seller_bids (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('seller_bid_revisions');
        $this->dropTable('seller_bids');
        $this->dropTable('rfq_seller_invitations');
        $this->dropTable('rfqs');
    }
}
