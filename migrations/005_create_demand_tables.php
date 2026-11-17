<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateDemandTables extends Migration
{
    public function up(): void
    {
        // 2.6 buy_requests
        $this->execute("
            CREATE TABLE buy_requests (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                buyer_id    BIGINT UNSIGNED NOT NULL,
                variant_id  BIGINT UNSIGNED NULL,
                spec_json   JSON            NULL,
                qty         INT UNSIGNED    NOT NULL,
                region_id   BIGINT UNSIGNED NOT NULL,
                purchase_by DATE            NULL,
                status      ENUM(
                                'draft',
                                'submitted',
                                'grouping',
                                'rfq_in_progress',
                                'offer_pending',
                                'offer_accepted',
                                'completed',
                                'cancelled',
                                'expired'
                            )               NOT NULL DEFAULT 'draft',
                deleted_at  TIMESTAMP       NULL     DEFAULT NULL,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (qty > 0),

                PRIMARY KEY (id),
                KEY idx_buy_requests_buyer                   (buyer_id),
                KEY idx_buy_requests_region_variant_status   (region_id, variant_id, status),
                CONSTRAINT fk_buy_requests_buyer   FOREIGN KEY (buyer_id)   REFERENCES users            (id) ON DELETE RESTRICT,
                CONSTRAINT fk_buy_requests_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2.7 buyer_price_preferences
        $this->execute("
            CREATE TABLE buyer_price_preferences (
                id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                request_id       BIGINT UNSIGNED NOT NULL,
                target_paise     BIGINT          NULL,
                max_paise        BIGINT          NULL,
                visibility_scope ENUM(
                                     'private',
                                     'procurement_only'
                                 )               NOT NULL DEFAULT 'private',
                price_includes   JSON            NULL,
                created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (target_paise IS NULL OR target_paise >= 0),
                CHECK (max_paise    IS NULL OR max_paise    >= 0),

                PRIMARY KEY (id),
                UNIQUE KEY uq_bpp_request (request_id),
                CONSTRAINT fk_bpp_request FOREIGN KEY (request_id) REFERENCES buy_requests (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2.8 demand_groups
        $this->execute("
            CREATE TABLE demand_groups (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                match_key  VARCHAR(500)    NOT NULL,
                region_id  BIGINT UNSIGNED NOT NULL,
                variant_id BIGINT UNSIGNED NULL,
                status     ENUM(
                               'forming',
                               'rfq_ready',
                               'rfq_live',
                               'offer_pending',
                               'offer_live',
                               'moq_met',
                               'completed',
                               'expired',
                               'cancelled'
                           )               NOT NULL DEFAULT 'forming',
                closes_at  TIMESTAMP       NULL     DEFAULT NULL,
                created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_demand_groups_match_status (match_key, status),
                KEY idx_demand_groups_variant      (variant_id),
                CONSTRAINT fk_demand_groups_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2.9 group_memberships
        $this->execute("
            CREATE TABLE group_memberships (
                id           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
                group_id     BIGINT UNSIGNED  NOT NULL,
                buyer_id     BIGINT UNSIGNED  NOT NULL,
                request_id   BIGINT UNSIGNED  NULL,
                qty          INT UNSIGNED     NOT NULL,
                intent_level TINYINT UNSIGNED NOT NULL DEFAULT 1,
                confirmed_at TIMESTAMP        NULL     DEFAULT NULL,
                created_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (qty > 0),
                CHECK (intent_level BETWEEN 1 AND 5),

                PRIMARY KEY (id),
                UNIQUE KEY uq_group_memberships_group_buyer (group_id, buyer_id),
                KEY idx_group_memberships_buyer   (buyer_id),
                KEY idx_group_memberships_request (request_id),
                CONSTRAINT fk_gm_group   FOREIGN KEY (group_id)   REFERENCES demand_groups (id) ON DELETE RESTRICT,
                CONSTRAINT fk_gm_buyer   FOREIGN KEY (buyer_id)   REFERENCES users         (id) ON DELETE RESTRICT,
                CONSTRAINT fk_gm_request FOREIGN KEY (request_id) REFERENCES buy_requests  (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2.10 group_intent_events
        $this->execute("
            CREATE TABLE group_intent_events (
                id            BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
                membership_id BIGINT UNSIGNED  NOT NULL,
                from_level    TINYINT UNSIGNED NOT NULL,
                to_level      TINYINT UNSIGNED NOT NULL,
                reason        VARCHAR(255)     NULL,
                actor_id      BIGINT UNSIGNED  NULL,
                created_at    TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CHECK (from_level BETWEEN 0 AND 5),
                CHECK (to_level   BETWEEN 0 AND 5),

                PRIMARY KEY (id),
                KEY idx_gie_membership (membership_id),
                CONSTRAINT fk_gie_membership FOREIGN KEY (membership_id) REFERENCES group_memberships (id) ON DELETE CASCADE,
                CONSTRAINT fk_gie_actor      FOREIGN KEY (actor_id)      REFERENCES users             (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2.11 group_compatibility_rules
        $this->execute("
            CREATE TABLE group_compatibility_rules (
                id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                group_id              BIGINT UNSIGNED NOT NULL,
                allowed_variants_json JSON            NOT NULL,
                constraints_json      JSON            NULL,
                created_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at            TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_gcr_group (group_id),
                CONSTRAINT fk_gcr_group FOREIGN KEY (group_id) REFERENCES demand_groups (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('group_compatibility_rules');
        $this->dropTable('group_intent_events');
        $this->dropTable('group_memberships');
        $this->dropTable('demand_groups');
        $this->dropTable('buyer_price_preferences');
        $this->dropTable('buy_requests');
    }
}
