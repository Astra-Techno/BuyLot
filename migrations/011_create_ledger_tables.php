<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateLedgerTables extends Migration
{
    public function up(): void
    {
        // 5.3 ledger_accounts
        $this->execute("
            CREATE TABLE ledger_accounts (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code       VARCHAR(50)     NOT NULL,
                name       VARCHAR(255)    NOT NULL,
                type       ENUM(
                               'asset',
                               'liability',
                               'revenue',
                               'expense',
                               'equity'
                           )               NOT NULL,
                is_active  TINYINT(1)      NOT NULL DEFAULT 1,
                created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_ledger_accounts_code (code)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 5.4 ledger_entries (append-only — no updated_at)
        $this->execute("
            CREATE TABLE ledger_entries (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                entity_type VARCHAR(50)     NOT NULL,
                entity_id   BIGINT UNSIGNED NOT NULL,
                description VARCHAR(500)    NULL,
                created_by  BIGINT UNSIGNED NULL,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_ledger_entries_entity (entity_type, entity_id),
                CONSTRAINT fk_le_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 5.5 ledger_postings (append-only — no updated_at)
        $this->execute("
            CREATE TABLE ledger_postings (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                entry_id     BIGINT UNSIGNED NOT NULL,
                account_id   BIGINT UNSIGNED NOT NULL,
                debit_paise  BIGINT          NOT NULL DEFAULT 0,
                credit_paise BIGINT          NOT NULL DEFAULT 0,
                created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

                CHECK (debit_paise  >= 0),
                CHECK (credit_paise >= 0),
                CHECK (debit_paise > 0 OR credit_paise > 0),

                PRIMARY KEY (id),
                KEY idx_ledger_postings_entry   (entry_id),
                KEY idx_ledger_postings_account (account_id),
                CONSTRAINT fk_lp_entry   FOREIGN KEY (entry_id)   REFERENCES ledger_entries  (id) ON DELETE RESTRICT,
                CONSTRAINT fk_lp_account FOREIGN KEY (account_id) REFERENCES ledger_accounts (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('ledger_postings');
        $this->dropTable('ledger_entries');
        $this->dropTable('ledger_accounts');
    }
}
