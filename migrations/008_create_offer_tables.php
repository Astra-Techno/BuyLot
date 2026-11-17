<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateOfferTables extends Migration
{
    public function up(): void
    {
        // 4.1 negotiated_offers
        $this->execute("
            CREATE TABLE negotiated_offers (
                id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                group_id               BIGINT UNSIGNED NOT NULL,
                seller_id              BIGINT UNSIGNED NOT NULL,
                source_bid_revision_id BIGINT UNSIGNED NULL,
                status                 ENUM(
                                           'draft',
                                           'pending_approval',
                                           'approved',
                                           'published',
                                           'moq_met',
                                           'moq_failed',
                                           'expired',
                                           'cancelled'
                                       )               NOT NULL DEFAULT 'draft',
                version                INT UNSIGNED    NOT NULL DEFAULT 1,
                expires_at             TIMESTAMP       NOT NULL,
                terms_snapshot_json    JSON            NOT NULL,
                created_by             BIGINT UNSIGNED NULL,
                created_at             TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at             TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_neg_offers_group  (group_id),
                KEY idx_neg_offers_seller (seller_id),
                KEY idx_neg_offers_status (status),
                CONSTRAINT fk_no_group        FOREIGN KEY (group_id)               REFERENCES demand_groups        (id) ON DELETE RESTRICT,
                CONSTRAINT fk_no_seller       FOREIGN KEY (seller_id)              REFERENCES seller_profiles      (id) ON DELETE RESTRICT,
                CONSTRAINT fk_no_bid_revision FOREIGN KEY (source_bid_revision_id) REFERENCES seller_bid_revisions (id) ON DELETE SET NULL,
                CONSTRAINT fk_no_created_by   FOREIGN KEY (created_by)             REFERENCES users                (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 4.2 offer_price_tiers
        $this->execute("
            CREATE TABLE offer_price_tiers (
                id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                offer_id         BIGINT UNSIGNED NOT NULL,
                min_paid_qty     INT UNSIGNED    NOT NULL,
                max_paid_qty     INT UNSIGNED    NULL,
                unit_price_paise BIGINT          NOT NULL,
                created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (unit_price_paise >= 0),
                CHECK (min_paid_qty     >  0),
                CHECK (max_paid_qty IS NULL OR max_paid_qty >= min_paid_qty),

                PRIMARY KEY (id),
                KEY idx_opt_offer (offer_id),
                CONSTRAINT fk_opt_offer FOREIGN KEY (offer_id) REFERENCES negotiated_offers (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 4.3 offer_approvals
        $this->execute("
            CREATE TABLE offer_approvals (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                offer_id    BIGINT UNSIGNED NOT NULL,
                approver_id BIGINT UNSIGNED NOT NULL,
                decision    ENUM(
                                'pending',
                                'approved',
                                'rejected'
                            )               NOT NULL DEFAULT 'pending',
                reason      TEXT            NULL,
                decided_at  TIMESTAMP       NULL     DEFAULT NULL,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_offer_approvals_offer    (offer_id),
                KEY idx_offer_approvals_approver (approver_id),
                CONSTRAINT fk_oa_offer    FOREIGN KEY (offer_id)    REFERENCES negotiated_offers (id) ON DELETE CASCADE,
                CONSTRAINT fk_oa_approver FOREIGN KEY (approver_id) REFERENCES users             (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 4.4 offer_acceptances
        $this->execute("
            CREATE TABLE offer_acceptances (
                id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                offer_id            BIGINT UNSIGNED NOT NULL,
                buyer_id            BIGINT UNSIGNED NOT NULL,
                qty                 INT UNSIGNED    NOT NULL,
                accepted_terms_hash VARCHAR(128)    NOT NULL,
                accepted_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (qty > 0),

                PRIMARY KEY (id),
                UNIQUE KEY uq_offer_acceptance_offer_buyer (offer_id, buyer_id),
                KEY idx_oa_buyer (buyer_id),
                CONSTRAINT fk_oac_offer FOREIGN KEY (offer_id) REFERENCES negotiated_offers (id) ON DELETE RESTRICT,
                CONSTRAINT fk_oac_buyer FOREIGN KEY (buyer_id) REFERENCES users             (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 4.5 stock_reservations
        $this->execute("
            CREATE TABLE stock_reservations (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                offer_id   BIGINT UNSIGNED NOT NULL,
                buyer_id   BIGINT UNSIGNED NOT NULL,
                qty        INT UNSIGNED    NOT NULL,
                expires_at TIMESTAMP       NOT NULL,
                status     ENUM(
                               'active',
                               'converted',
                               'released',
                               'expired'
                           )               NOT NULL DEFAULT 'active',
                created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (qty > 0),

                PRIMARY KEY (id),
                KEY idx_stock_res_offer  (offer_id),
                KEY idx_stock_res_buyer  (buyer_id),
                KEY idx_stock_res_status (status),
                CONSTRAINT fk_sr_offer FOREIGN KEY (offer_id) REFERENCES negotiated_offers (id) ON DELETE RESTRICT,
                CONSTRAINT fk_sr_buyer FOREIGN KEY (buyer_id) REFERENCES users             (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('stock_reservations');
        $this->dropTable('offer_acceptances');
        $this->dropTable('offer_approvals');
        $this->dropTable('offer_price_tiers');
        $this->dropTable('negotiated_offers');
    }
}
