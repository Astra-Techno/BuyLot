<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateProcurementTables extends Migration
{
    public function up(): void
    {
        // 3.5 procurement_cases
        $this->execute("
            CREATE TABLE procurement_cases (
                id                BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
                rfq_id            BIGINT UNSIGNED  NOT NULL,
                assigned_agent_id BIGINT UNSIGNED  NULL,
                priority          TINYINT UNSIGNED NOT NULL DEFAULT 2,
                stage             ENUM(
                                      'sourcing',
                                      'bids_received',
                                      'normalisation',
                                      'negotiation',
                                      'approval_pending',
                                      'offer_published',
                                      'closed_awarded',
                                      'closed_no_deal'
                                  )               NOT NULL DEFAULT 'sourcing',
                sla_due_at        TIMESTAMP       NULL     DEFAULT NULL,
                created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_proc_cases_rfq   (rfq_id),
                KEY idx_proc_cases_agent (assigned_agent_id),
                KEY idx_proc_cases_stage (stage),
                CONSTRAINT fk_proc_cases_rfq   FOREIGN KEY (rfq_id)            REFERENCES rfqs  (id) ON DELETE RESTRICT,
                CONSTRAINT fk_proc_cases_agent FOREIGN KEY (assigned_agent_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3.6 supplier_leads
        $this->execute("
            CREATE TABLE supplier_leads (
                id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                legal_name          VARCHAR(255)    NOT NULL,
                source              VARCHAR(100)    NOT NULL,
                verification_status ENUM(
                                        'unverified',
                                        'pending',
                                        'verified',
                                        'rejected'
                                    )               NOT NULL DEFAULT 'unverified',
                region_id           BIGINT UNSIGNED NULL,
                notes               TEXT            NULL,
                created_by          BIGINT UNSIGNED NULL,
                created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_supplier_leads_status (verification_status),
                CONSTRAINT fk_supplier_leads_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3.7 supplier_contacts
        $this->execute("
            CREATE TABLE supplier_contacts (
                id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                supplier_lead_id BIGINT UNSIGNED NOT NULL,
                method           VARCHAR(50)     NOT NULL,
                outcome          VARCHAR(255)    NULL,
                notes            TEXT            NULL,
                contacted_by     BIGINT UNSIGNED NOT NULL,
                contacted_at     TIMESTAMP       NOT NULL,
                created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_supplier_contacts_lead (supplier_lead_id),
                CONSTRAINT fk_sc_lead         FOREIGN KEY (supplier_lead_id) REFERENCES supplier_leads (id) ON DELETE CASCADE,
                CONSTRAINT fk_sc_contacted_by FOREIGN KEY (contacted_by)     REFERENCES users          (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3.8 negotiation_events
        $this->execute("
            CREATE TABLE negotiation_events (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                case_id     BIGINT UNSIGNED NOT NULL,
                supplier_id BIGINT UNSIGNED NULL,
                bid_id      BIGINT UNSIGNED NULL,
                type        VARCHAR(100)    NOT NULL,
                terms_json  JSON            NULL,
                created_by  BIGINT UNSIGNED NOT NULL,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_neg_events_case (case_id),
                KEY idx_neg_events_bid  (bid_id),
                CONSTRAINT fk_ne_case       FOREIGN KEY (case_id)    REFERENCES procurement_cases (id) ON DELETE RESTRICT,
                CONSTRAINT fk_ne_bid        FOREIGN KEY (bid_id)     REFERENCES seller_bids       (id) ON DELETE SET NULL,
                CONSTRAINT fk_ne_created_by FOREIGN KEY (created_by) REFERENCES users             (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3.9 negotiation_attachments
        $this->execute("
            CREATE TABLE negotiation_attachments (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                event_id   BIGINT UNSIGNED NOT NULL,
                object_ref VARCHAR(500)    NOT NULL,
                mime       VARCHAR(100)    NOT NULL,
                checksum   VARCHAR(128)    NOT NULL,
                created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_neg_attach_event (event_id),
                CONSTRAINT fk_na_event FOREIGN KEY (event_id) REFERENCES negotiation_events (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 3.10 procurement_tasks
        $this->execute("
            CREATE TABLE procurement_tasks (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                case_id     BIGINT UNSIGNED NOT NULL,
                assignee_id BIGINT UNSIGNED NULL,
                title       VARCHAR(500)    NOT NULL,
                due_at      TIMESTAMP       NULL     DEFAULT NULL,
                status      ENUM(
                                'open',
                                'in_progress',
                                'blocked',
                                'completed',
                                'cancelled'
                            )               NOT NULL DEFAULT 'open',
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_proc_tasks_case     (case_id),
                KEY idx_proc_tasks_assignee (assignee_id),
                CONSTRAINT fk_pt_case     FOREIGN KEY (case_id)     REFERENCES procurement_cases (id) ON DELETE CASCADE,
                CONSTRAINT fk_pt_assignee FOREIGN KEY (assignee_id) REFERENCES users             (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('procurement_tasks');
        $this->dropTable('negotiation_attachments');
        $this->dropTable('negotiation_events');
        $this->dropTable('supplier_contacts');
        $this->dropTable('supplier_leads');
        $this->dropTable('procurement_cases');
    }
}
