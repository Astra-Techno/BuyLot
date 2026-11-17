<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateAuditTables extends Migration
{
    public function up(): void
    {
        // 7.7 audit_logs (append-only — no updated_at)
        $this->execute("
            CREATE TABLE audit_logs (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                actor_id    BIGINT UNSIGNED NULL,
                actor_role  VARCHAR(100)    NULL,
                action      VARCHAR(200)    NOT NULL,
                entity_type VARCHAR(50)     NULL,
                entity_id   BIGINT UNSIGNED NULL,
                before_json JSON            NULL,
                after_json  JSON            NULL,
                ip_address  VARCHAR(45)     NULL,
                user_agent  VARCHAR(500)    NULL,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_audit_actor  (actor_id),
                KEY idx_audit_entity (entity_type, entity_id),
                KEY idx_audit_action (action),
                KEY idx_audit_time   (created_at),
                CONSTRAINT fk_al_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 7.8 risk_flags
        $this->execute("
            CREATE TABLE risk_flags (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_type VARCHAR(50)     NOT NULL,
                entity_id   BIGINT UNSIGNED NOT NULL,
                flag_type   VARCHAR(100)    NOT NULL,
                severity    ENUM(
                                'low',
                                'medium',
                                'high',
                                'critical'
                            )               NOT NULL DEFAULT 'medium',
                status      ENUM(
                                'open',
                                'investigating',
                                'resolved',
                                'false_positive'
                            )               NOT NULL DEFAULT 'open',
                raised_by   BIGINT UNSIGNED NULL,
                resolved_by BIGINT UNSIGNED NULL,
                resolved_at TIMESTAMP       NULL     DEFAULT NULL,
                notes       TEXT            NULL,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_risk_flags_entity (entity_type, entity_id),
                KEY idx_risk_flags_status (status),
                CONSTRAINT fk_rf_raised_by   FOREIGN KEY (raised_by)   REFERENCES users (id) ON DELETE SET NULL,
                CONSTRAINT fk_rf_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 7.9 policy_versions (append-only — no updated_at)
        $this->execute("
            CREATE TABLE policy_versions (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                policy_key   VARCHAR(100)    NOT NULL,
                version      VARCHAR(20)     NOT NULL,
                effective_at TIMESTAMP       NOT NULL,
                content_ref  VARCHAR(500)    NOT NULL,
                created_by   BIGINT UNSIGNED NULL,
                created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_policy_key_version (policy_key, version),
                KEY idx_policy_versions_key (policy_key, effective_at),
                CONSTRAINT fk_pv_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 7.10 consent_records
        $this->execute("
            CREATE TABLE consent_records (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id           BIGINT UNSIGNED NOT NULL,
                policy_version_id BIGINT UNSIGNED NOT NULL,
                consented_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip_address        VARCHAR(45)     NULL,
                user_agent        VARCHAR(500)    NULL,
                revoked_at        TIMESTAMP       NULL     DEFAULT NULL,
                created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_consent_user_policy (user_id, policy_version_id),
                KEY idx_consent_user (user_id),
                CONSTRAINT fk_cr_user           FOREIGN KEY (user_id)           REFERENCES users           (id) ON DELETE RESTRICT,
                CONSTRAINT fk_cr_policy_version FOREIGN KEY (policy_version_id) REFERENCES policy_versions (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('consent_records');
        $this->dropTable('policy_versions');
        $this->dropTable('risk_flags');
        $this->dropTable('audit_logs');
    }
}
