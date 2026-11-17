<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateSellerTables extends Migration
{
    public function up(): void
    {
        // 1.8 seller_profiles
        $this->execute("
            CREATE TABLE seller_profiles (
                id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                organization_id   BIGINT UNSIGNED NOT NULL,
                categories_json   JSON            NULL,
                service_regions   JSON            NULL,
                reliability_score DECIMAL(5,2)    NULL,
                status            ENUM(
                                      'pending_approval',
                                      'active',
                                      'suspended',
                                      'terminated'
                                  )               NOT NULL DEFAULT 'pending_approval',
                created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_seller_profiles_org (organization_id),
                KEY idx_seller_profiles_status (status),
                CONSTRAINT fk_seller_profiles_org FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 1.9 seller_verification_cases
        $this->execute("
            CREATE TABLE seller_verification_cases (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                seller_id    BIGINT UNSIGNED NOT NULL,
                provider_ref VARCHAR(255)    NULL,
                result       ENUM(
                                 'pending',
                                 'passed',
                                 'failed',
                                 'manual_review'
                             )               NOT NULL DEFAULT 'pending',
                reviewed_by  BIGINT UNSIGNED NULL,
                reviewed_at  TIMESTAMP       NULL     DEFAULT NULL,
                notes        TEXT            NULL,
                created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_seller_ver_seller (seller_id),
                KEY idx_seller_ver_result (result),
                CONSTRAINT fk_seller_ver_seller      FOREIGN KEY (seller_id)   REFERENCES seller_profiles (id) ON DELETE RESTRICT,
                CONSTRAINT fk_seller_ver_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users           (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 1.10 seller_bank_accounts
        $this->execute("
            CREATE TABLE seller_bank_accounts (
                id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                seller_id      BIGINT UNSIGNED NOT NULL,
                provider_token VARCHAR(500)    NOT NULL,
                verified_at    TIMESTAMP       NULL     DEFAULT NULL,
                status         ENUM(
                                   'pending_verification',
                                   'verified',
                                   'failed',
                                   'deactivated'
                               )               NOT NULL DEFAULT 'pending_verification',
                created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_bank_accounts_seller (seller_id),
                CONSTRAINT fk_bank_accounts_seller FOREIGN KEY (seller_id) REFERENCES seller_profiles (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 1.11 addresses
        $this->execute("
            CREATE TABLE addresses (
                id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                owner_type       VARCHAR(50)     NOT NULL,
                owner_id         BIGINT UNSIGNED NOT NULL,
                label            VARCHAR(100)    NULL,
                region           VARCHAR(100)    NOT NULL,
                postal_code      VARCHAR(20)     NOT NULL,
                encrypted_detail TEXT            NULL,
                is_default       TINYINT(1)      NOT NULL DEFAULT 0,
                created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_addresses_owner (owner_type, owner_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('addresses');
        $this->dropTable('seller_bank_accounts');
        $this->dropTable('seller_verification_cases');
        $this->dropTable('seller_profiles');
    }
}
