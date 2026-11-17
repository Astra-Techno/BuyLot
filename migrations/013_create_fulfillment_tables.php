<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateFulfillmentTables extends Migration
{
    public function up(): void
    {
        // 6.1 shipments
        $this->execute("
            CREATE TABLE shipments (
                id                          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                seller_fulfillment_order_id BIGINT UNSIGNED NOT NULL,
                carrier                     VARCHAR(100)    NULL,
                tracking_ref                VARCHAR(255)    NULL,
                status                      ENUM(
                                                'pending',
                                                'picked_up',
                                                'in_transit',
                                                'out_for_delivery',
                                                'delivered',
                                                'failed_delivery',
                                                'returned'
                                            )               NOT NULL DEFAULT 'pending',
                expected_delivery_date      DATE            NULL,
                actual_delivery_at          TIMESTAMP       NULL     DEFAULT NULL,
                created_at                  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at                  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_shipments_sfo    (seller_fulfillment_order_id),
                KEY idx_shipments_status (status),
                CONSTRAINT fk_shipments_sfo FOREIGN KEY (seller_fulfillment_order_id) REFERENCES seller_fulfillment_orders (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 6.2 shipment_items
        $this->execute("
            CREATE TABLE shipment_items (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                shipment_id   BIGINT UNSIGNED NOT NULL,
                order_item_id BIGINT UNSIGNED NOT NULL,
                qty           INT UNSIGNED    NOT NULL,
                created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (qty > 0),

                PRIMARY KEY (id),
                KEY idx_shipment_items_shipment   (shipment_id),
                KEY idx_shipment_items_order_item (order_item_id),
                CONSTRAINT fk_shipitem_shipment   FOREIGN KEY (shipment_id)   REFERENCES shipments   (id) ON DELETE CASCADE,
                CONSTRAINT fk_shipitem_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 6.3 delivery_evidence (append-only — no updated_at)
        $this->execute("
            CREATE TABLE delivery_evidence (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                shipment_id   BIGINT UNSIGNED NOT NULL,
                evidence_type VARCHAR(50)     NOT NULL,
                object_ref    VARCHAR(500)    NOT NULL,
                verified_at   TIMESTAMP       NULL     DEFAULT NULL,
                verified_by   BIGINT UNSIGNED NULL,
                created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_delivery_evidence_shipment (shipment_id),
                CONSTRAINT fk_de_shipment    FOREIGN KEY (shipment_id) REFERENCES shipments (id) ON DELETE RESTRICT,
                CONSTRAINT fk_de_verified_by FOREIGN KEY (verified_by) REFERENCES users     (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 6.4 inspections
        $this->execute("
            CREATE TABLE inspections (
                id                 BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
                order_id           BIGINT UNSIGNED  NOT NULL,
                shipment_id        BIGINT UNSIGNED  NULL,
                tier               TINYINT UNSIGNED NOT NULL,
                inspector_id       BIGINT UNSIGNED  NULL,
                status             ENUM(
                                       'pending',
                                       'in_progress',
                                       'completed',
                                       'skipped'
                                   )                NOT NULL DEFAULT 'pending',
                result             ENUM(
                                       'pass',
                                       'pass_with_remarks',
                                       'fail',
                                       'inconclusive'
                                   )                NULL     DEFAULT NULL,
                checklist_snapshot JSON             NULL,
                completed_at       TIMESTAMP        NULL     DEFAULT NULL,
                created_at         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at         TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_inspections_order    (order_id),
                KEY idx_inspections_shipment (shipment_id),
                CONSTRAINT fk_insp_order     FOREIGN KEY (order_id)     REFERENCES orders    (id) ON DELETE RESTRICT,
                CONSTRAINT fk_insp_shipment  FOREIGN KEY (shipment_id)  REFERENCES shipments (id) ON DELETE SET NULL,
                CONSTRAINT fk_insp_inspector FOREIGN KEY (inspector_id) REFERENCES users     (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 6.5 inspection_findings
        $this->execute("
            CREATE TABLE inspection_findings (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                inspection_id BIGINT UNSIGNED NOT NULL,
                order_item_id BIGINT UNSIGNED NOT NULL,
                finding_type  VARCHAR(100)    NOT NULL,
                qty_affected  INT UNSIGNED    NOT NULL DEFAULT 1,
                description   TEXT            NULL,
                evidence_ref  VARCHAR(500)    NULL,
                created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                CHECK (qty_affected > 0),

                PRIMARY KEY (id),
                KEY idx_findings_inspection (inspection_id),
                KEY idx_findings_order_item (order_item_id),
                CONSTRAINT fk_if_inspection FOREIGN KEY (inspection_id) REFERENCES inspections (id) ON DELETE CASCADE,
                CONSTRAINT fk_if_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 6.6 buyer_acknowledgements
        $this->execute("
            CREATE TABLE buyer_acknowledgements (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                order_id          BIGINT UNSIGNED NOT NULL,
                shipment_id       BIGINT UNSIGNED NOT NULL,
                buyer_id          BIGINT UNSIGNED NOT NULL,
                accepted_qty_json JSON            NOT NULL,
                disputed_qty_json JSON            NOT NULL,
                submitted_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_buyer_ack_order_shipment_buyer (order_id, shipment_id, buyer_id),
                KEY idx_buyer_ack_buyer (buyer_id),
                CONSTRAINT fk_ba_order    FOREIGN KEY (order_id)    REFERENCES orders    (id) ON DELETE RESTRICT,
                CONSTRAINT fk_ba_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id) ON DELETE RESTRICT,
                CONSTRAINT fk_ba_buyer    FOREIGN KEY (buyer_id)    REFERENCES users     (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('buyer_acknowledgements');
        $this->dropTable('inspection_findings');
        $this->dropTable('inspections');
        $this->dropTable('delivery_evidence');
        $this->dropTable('shipment_items');
        $this->dropTable('shipments');
    }
}
