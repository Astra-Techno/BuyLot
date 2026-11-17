<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateIdentityTables extends Migration
{
    public function up(): void
    {
        // 1.2 organizations
        $this->execute("
            CREATE TABLE organizations (
                id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                type                 ENUM(
                                         'individual_buyer',
                                         'business_buyer',
                                         'seller',
                                         'inspection_partner',
                                         'platform'
                                     )               NOT NULL,
                legal_name           VARCHAR(255)    NOT NULL,
                tax_id_ref           VARCHAR(100)    NULL,
                verification_status  ENUM(
                                         'unverified',
                                         'pending_review',
                                         'verified',
                                         'rejected',
                                         'suspended'
                                     )               NOT NULL DEFAULT 'unverified',
                deleted_at           TIMESTAMP       NULL     DEFAULT NULL,
                created_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_organizations_type_status (type, verification_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 1.3 organization_members
        $this->execute("
            CREATE TABLE organization_members (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                organization_id BIGINT UNSIGNED NOT NULL,
                user_id         BIGINT UNSIGNED NOT NULL,
                role            VARCHAR(50)     NOT NULL,
                status          ENUM(
                                    'invited',
                                    'active',
                                    'suspended',
                                    'removed'
                                )               NOT NULL DEFAULT 'invited',
                created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_org_members_org_user (organization_id, user_id),
                KEY idx_org_members_user (user_id),
                CONSTRAINT fk_org_members_org  FOREIGN KEY (organization_id) REFERENCES organizations (id) ON DELETE RESTRICT,
                CONSTRAINT fk_org_members_user FOREIGN KEY (user_id)         REFERENCES users         (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 1.4 roles
        $this->execute("
            CREATE TABLE roles (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name        VARCHAR(100)    NOT NULL,
                description VARCHAR(500)    NULL,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_roles_name (name)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 1.5 permissions
        $this->execute("
            CREATE TABLE permissions (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code        VARCHAR(150)    NOT NULL,
                description VARCHAR(500)    NULL,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_permissions_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 1.6 role_permissions
        $this->execute("
            CREATE TABLE role_permissions (
                id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                role_id       BIGINT UNSIGNED NOT NULL,
                permission_id BIGINT UNSIGNED NOT NULL,
                created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_role_perm (role_id, permission_id),
                CONSTRAINT fk_role_perm_role FOREIGN KEY (role_id)       REFERENCES roles       (id) ON DELETE CASCADE,
                CONSTRAINT fk_role_perm_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 1.7 user_role_assignments
        $this->execute("
            CREATE TABLE user_role_assignments (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                user_id     BIGINT UNSIGNED NOT NULL,
                role_id     BIGINT UNSIGNED NOT NULL,
                granted_by  BIGINT UNSIGNED NULL,
                expires_at  TIMESTAMP       NULL     DEFAULT NULL,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_user_role (user_id, role_id),
                KEY idx_ura_role (role_id),
                CONSTRAINT fk_ura_user       FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE RESTRICT,
                CONSTRAINT fk_ura_role       FOREIGN KEY (role_id)    REFERENCES roles (id) ON DELETE RESTRICT,
                CONSTRAINT fk_ura_granted_by FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('user_role_assignments');
        $this->dropTable('role_permissions');
        $this->dropTable('permissions');
        $this->dropTable('roles');
        $this->dropTable('organization_members');
        $this->dropTable('organizations');
    }
}
