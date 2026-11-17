<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Core\Database\Migration;

class CreateCatalogTables extends Migration
{
    public function up(): void
    {
        // 2.1 categories
        $this->execute("
            CREATE TABLE categories (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                parent_id  BIGINT UNSIGNED NULL     DEFAULT NULL,
                slug       VARCHAR(255)    NOT NULL,
                name       VARCHAR(255)    NOT NULL,
                is_active  TINYINT(1)      NOT NULL DEFAULT 1,
                created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_categories_slug (slug),
                KEY idx_categories_parent (parent_id),
                CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2.2 brands
        $this->execute("
            CREATE TABLE brands (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                name       VARCHAR(255)    NOT NULL,
                slug       VARCHAR(255)    NOT NULL,
                is_active  TINYINT(1)      NOT NULL DEFAULT 1,
                created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_brands_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2.3 products
        $this->execute("
            CREATE TABLE products (
                id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                category_id BIGINT UNSIGNED NOT NULL,
                brand_id    BIGINT UNSIGNED NULL,
                name        VARCHAR(500)    NOT NULL,
                description TEXT            NULL,
                is_active   TINYINT(1)      NOT NULL DEFAULT 1,
                created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_products_category (category_id),
                KEY idx_products_brand    (brand_id),
                CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories (id) ON DELETE RESTRICT,
                CONSTRAINT fk_products_brand    FOREIGN KEY (brand_id)    REFERENCES brands     (id) ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2.4 product_variants
        $this->execute("
            CREATE TABLE product_variants (
                id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id      BIGINT UNSIGNED NOT NULL,
                sku             VARCHAR(100)    NOT NULL,
                name            VARCHAR(500)    NOT NULL,
                attributes_json JSON            NULL,
                is_active       TINYINT(1)      NOT NULL DEFAULT 1,
                created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                UNIQUE KEY uq_variants_sku (sku),
                KEY idx_variants_product (product_id),
                CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // 2.5 product_attributes
        $this->execute("
            CREATE TABLE product_attributes (
                id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                product_id BIGINT UNSIGNED NOT NULL,
                name       VARCHAR(255)    NOT NULL,
                value      VARCHAR(1000)   NOT NULL,
                created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

                PRIMARY KEY (id),
                KEY idx_product_attrs_product (product_id),
                CONSTRAINT fk_product_attrs_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        $this->dropTable('product_attributes');
        $this->dropTable('product_variants');
        $this->dropTable('products');
        $this->dropTable('brands');
        $this->dropTable('categories');
    }
}
