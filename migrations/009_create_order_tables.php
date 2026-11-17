<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateOrderTables extends Migration
{
    public function up(): void
    {
        // 4.6 orders
        $this->execute("
            CREATE TABLE orders (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                buyer_id    BIGINT UNSIGNED NOT NULL,
                group_id    BIGINT UNSIGNED NOT NULL,
                offer_id    BIGINT UNSIGNED NOT NULL,
                status      ENUM(
                                'pending_payment',
                                'payment_confirmed',
                                'processing',
                                'dispatched',
                                'delivered',
                                'accepted',
                                'disputed',
                                'completed',
                                'cancelled',
                                'refunded'
                            )               NOT NULL DEFAULT 'pending_payment',
                total_paise BIGINT          NOT NULL,
                currency    CHAR(3)         NOT NULL DEFAULT 'INR',
                terms_hash  VARCHAR(128)    NOT NULL,
                deleted_at  TIMESTAMP       NULL     DEFAULT NULL,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (total_paise >= 0),

                PRIMARY KEY (id),
                KEY idx_orders_buyer_status (buyer_id, status),
                KEY idx_orders_offer        (offer_id),
                KEY idx_orders_group        (group_id),
                CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_id) REFERENCES users             (id) ON DELETE RESTRICT,
                CONSTRAINT fk_orders_group FOREIGN KEY (group_id) REFERENCES demand_groups     (id) ON DELETE RESTRICT,
                CONSTRAINT fk_orders_offer FOREIGN KEY (offer_id) REFERENCES negotiated_offers (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 4.7 order_items
        $this->execute("
            CREATE TABLE order_items (
                id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id         BIGINT UNSIGNED NOT NULL,
                variant_id       BIGINT UNSIGNED NOT NULL,
                qty              INT UNSIGNED    NOT NULL,
                unit_price_paise BIGINT          NOT NULL,
                tax_paise        BIGINT          NOT NULL DEFAULT 0,
                shipping_paise   BIGINT          NOT NULL DEFAULT 0,
                snapshot_json    JSON            NOT NULL,
                created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (qty              >  0),
                CHECK (unit_price_paise >= 0),
                CHECK (tax_paise        >= 0),
                CHECK (shipping_paise   >= 0),

                PRIMARY KEY (id),
                KEY idx_order_items_order   (order_id),
                KEY idx_order_items_variant (variant_id),
                CONSTRAINT fk_oi_order   FOREIGN KEY (order_id)   REFERENCES orders           (id) ON DELETE RESTRICT,
                CONSTRAINT fk_oi_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 4.8 order_status_events (append-only — no updated_at)
        $this->execute("
            CREATE TABLE order_status_events (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id   BIGINT UNSIGNED NOT NULL,
                from_state VARCHAR(50)     NULL,
                to_state   VARCHAR(50)     NOT NULL,
                actor_id   BIGINT UNSIGNED NULL,
                reason     VARCHAR(500)    NULL,
                created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_ose_order (order_id),
                CONSTRAINT fk_ose_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
                CONSTRAINT fk_ose_actor FOREIGN KEY (actor_id) REFERENCES users  (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 4.9 seller_fulfillment_orders
        $this->execute("
            CREATE TABLE seller_fulfillment_orders (
                id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                seller_id                 BIGINT UNSIGNED NOT NULL,
                offer_id                  BIGINT UNSIGNED NOT NULL,
                status                    ENUM(
                                              'pending_confirmation',
                                              'confirmed',
                                              'preparing',
                                              'dispatched',
                                              'completed',
                                              'cancelled'
                                          )               NOT NULL DEFAULT 'pending_confirmation',
                supplier_confirmation_ref VARCHAR(255)    NULL,
                created_at                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_sfo_seller (seller_id),
                KEY idx_sfo_offer  (offer_id),
                CONSTRAINT fk_sfo_seller FOREIGN KEY (seller_id) REFERENCES seller_profiles   (id) ON DELETE RESTRICT,
                CONSTRAINT fk_sfo_offer  FOREIGN KEY (offer_id)  REFERENCES negotiated_offers (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('seller_fulfillment_orders');
        $this->dropTable('order_status_events');
        $this->dropTable('order_items');
        $this->dropTable('orders');
    }
}
