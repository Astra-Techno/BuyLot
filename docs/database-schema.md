# Collective Commerce — Complete MySQL 8 Database Schema

**Engine:** InnoDB
**Charset:** utf8mb4
**Collation:** utf8mb4_unicode_ci
**ID convention:** `BIGINT UNSIGNED AUTO_INCREMENT` for all primary keys
**Money convention:** `BIGINT` columns with `_paise` suffix (1 INR = 100 paise); never store fractional currency
**Timestamps:** `created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP`, `updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
**Soft delete:** `deleted_at TIMESTAMP NULL DEFAULT NULL` on entities where legal retention applies
**Immutable records:** `order_status_events`, `ledger_postings`, `audit_logs` — append-only, no UPDATE or DELETE

---

## Module 1 — Identity & Organizations

### 1.1 `users`

```sql
CREATE TABLE users (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    mobile              VARCHAR(20)     NOT NULL COMMENT 'E.164 format; primary login credential',
    email               VARCHAR(255)    NULL     COMMENT 'Optional; lowercased before storage',
    status              ENUM(
                            'pending_verification',
                            'active',
                            'suspended',
                            'deactivated'
                        )               NOT NULL DEFAULT 'pending_verification',
    mobile_verified_at  TIMESTAMP       NULL     DEFAULT NULL,
    email_verified_at   TIMESTAMP       NULL     DEFAULT NULL,
    locale              VARCHAR(10)     NOT NULL DEFAULT 'en-IN',
    deleted_at          TIMESTAMP       NULL     DEFAULT NULL COMMENT 'Set on DPDP erasure request; redacts PII but preserves row for FK integrity',
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_users_mobile (mobile),
    UNIQUE KEY uq_users_email  (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.2 `organizations`

```sql
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
    tax_id_ref           VARCHAR(100)    NULL     COMMENT 'GST/PAN reference; store tokenised/encrypted externally if sensitive',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.3 `organization_members`

```sql
CREATE TABLE organization_members (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,
    user_id         BIGINT UNSIGNED NOT NULL,
    role            VARCHAR(50)     NOT NULL COMMENT 'e.g. owner, admin, buyer, approver',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.4 `roles`

```sql
CREATE TABLE roles (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(100)    NOT NULL,
    description VARCHAR(500)    NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.5 `permissions`

```sql
CREATE TABLE permissions (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(150)    NOT NULL COMMENT 'e.g. rfq:read:confidential-bids',
    description VARCHAR(500)    NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.6 `role_permissions`

```sql
CREATE TABLE role_permissions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id       BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_role_perm (role_id, permission_id),
    CONSTRAINT fk_role_perm_role FOREIGN KEY (role_id)       REFERENCES roles       (id) ON DELETE CASCADE,
    CONSTRAINT fk_role_perm_perm FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.7 `user_role_assignments`

```sql
CREATE TABLE user_role_assignments (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id         BIGINT UNSIGNED NOT NULL,
    role_id         BIGINT UNSIGNED NOT NULL,
    granted_by      BIGINT UNSIGNED NULL     COMMENT 'admin user_id who granted this role',
    expires_at      TIMESTAMP       NULL     DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_user_role (user_id, role_id),
    KEY idx_ura_role (role_id),
    CONSTRAINT fk_ura_user       FOREIGN KEY (user_id)    REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ura_role       FOREIGN KEY (role_id)    REFERENCES roles (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ura_granted_by FOREIGN KEY (granted_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.8 `seller_profiles`

```sql
CREATE TABLE seller_profiles (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organization_id   BIGINT UNSIGNED NOT NULL,
    categories_json   JSON            NULL     COMMENT 'Array of category IDs the seller is approved for',
    service_regions   JSON            NULL     COMMENT 'Array of region identifiers',
    reliability_score DECIMAL(5,2)    NULL     COMMENT 'Computed metric; range 0.00–100.00',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.9 `seller_verification_cases`

```sql
CREATE TABLE seller_verification_cases (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_id     BIGINT UNSIGNED NOT NULL COMMENT 'References seller_profiles.id',
    provider_ref  VARCHAR(255)    NULL     COMMENT 'External KYB/KYC provider reference',
    result        ENUM(
                      'pending',
                      'passed',
                      'failed',
                      'manual_review'
                  )               NOT NULL DEFAULT 'pending',
    reviewed_by   BIGINT UNSIGNED NULL     COMMENT 'Internal compliance user_id',
    reviewed_at   TIMESTAMP       NULL     DEFAULT NULL,
    notes         TEXT            NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_seller_ver_seller (seller_id),
    KEY idx_seller_ver_result  (result),
    CONSTRAINT fk_seller_ver_seller      FOREIGN KEY (seller_id)   REFERENCES seller_profiles (id) ON DELETE RESTRICT,
    CONSTRAINT fk_seller_ver_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users           (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.10 `seller_bank_accounts`

```sql
CREATE TABLE seller_bank_accounts (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_id      BIGINT UNSIGNED NOT NULL COMMENT 'References seller_profiles.id',
    provider_token VARCHAR(500)    NOT NULL COMMENT 'Opaque token from payment provider — raw account numbers MUST NOT be stored here',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 1.11 `addresses`

```sql
CREATE TABLE addresses (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    owner_type       VARCHAR(50)     NOT NULL COMMENT 'Polymorphic: user | organization | seller_profile',
    owner_id         BIGINT UNSIGNED NOT NULL,
    label            VARCHAR(100)    NULL     COMMENT 'e.g. Home, Warehouse',
    region           VARCHAR(100)    NOT NULL COMMENT 'City/district/state used for demand grouping',
    postal_code      VARCHAR(20)     NOT NULL,
    encrypted_detail TEXT            NULL     COMMENT 'Full address stored encrypted at application layer; not searchable',
    is_default       TINYINT(1)      NOT NULL DEFAULT 0,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_addresses_owner (owner_type, owner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Module 2 — Catalog & Demand

### 2.1 `categories`

```sql
CREATE TABLE categories (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_id   BIGINT UNSIGNED NULL     DEFAULT NULL COMMENT 'NULL = root category',
    slug        VARCHAR(255)    NOT NULL,
    name        VARCHAR(255)    NOT NULL,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_categories_slug (slug),
    KEY idx_categories_parent (parent_id),
    CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.2 `brands`

```sql
CREATE TABLE brands (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name        VARCHAR(255)    NOT NULL,
    slug        VARCHAR(255)    NOT NULL,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_brands_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.3 `products`

```sql
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.4 `product_variants`

```sql
CREATE TABLE product_variants (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id      BIGINT UNSIGNED NOT NULL,
    sku             VARCHAR(100)    NOT NULL,
    name            VARCHAR(500)    NOT NULL COMMENT 'Human label for this variant (e.g. 32GB Black)',
    attributes_json JSON            NULL     COMMENT 'Variant-level key/value specs',
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_variants_sku (sku),
    KEY idx_variants_product (product_id),
    CONSTRAINT fk_variants_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.5 `product_attributes`

```sql
CREATE TABLE product_attributes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id  BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(255)    NOT NULL COMMENT 'Attribute name e.g. warranty_months',
    value       VARCHAR(1000)   NOT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_product_attrs_product (product_id),
    CONSTRAINT fk_product_attrs_product FOREIGN KEY (product_id) REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.6 `buy_requests`

```sql
CREATE TABLE buy_requests (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    buyer_id    BIGINT UNSIGNED NOT NULL COMMENT 'References users.id',
    variant_id  BIGINT UNSIGNED NULL     COMMENT 'NULL if buyer specifies free-form spec only',
    spec_json   JSON            NULL     COMMENT 'Free-form buyer specification snapshot',
    qty         INT UNSIGNED    NOT NULL,
    region_id   BIGINT UNSIGNED NOT NULL COMMENT 'References addresses.id or a region lookup table',
    purchase_by DATE            NULL     COMMENT 'Buyer-stated latest purchase date',
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
    KEY idx_buy_requests_buyer          (buyer_id),
    KEY idx_buy_requests_region_variant_status (region_id, variant_id, status),
    CONSTRAINT fk_buy_requests_buyer   FOREIGN KEY (buyer_id)   REFERENCES users            (id) ON DELETE RESTRICT,
    CONSTRAINT fk_buy_requests_variant FOREIGN KEY (variant_id) REFERENCES product_variants (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.7 `buyer_price_preferences`

```sql
CREATE TABLE buyer_price_preferences (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id       BIGINT UNSIGNED NOT NULL,
    target_paise     BIGINT          NULL     COMMENT 'Buyer-stated preferred price; non-binding',
    max_paise        BIGINT          NULL     COMMENT 'Buyer-stated absolute maximum; visible only to authorised procurement staff',
    visibility_scope ENUM(
                         'private',
                         'procurement_only'
                     )               NOT NULL DEFAULT 'private',
    price_includes   JSON            NULL     COMMENT 'e.g. ["gst","shipping","installation"]',
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CHECK (target_paise IS NULL OR target_paise >= 0),
    CHECK (max_paise    IS NULL OR max_paise    >= 0),

    PRIMARY KEY (id),
    UNIQUE KEY uq_bpp_request (request_id),
    CONSTRAINT fk_bpp_request FOREIGN KEY (request_id) REFERENCES buy_requests (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.8 `demand_groups`

```sql
CREATE TABLE demand_groups (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    match_key  VARCHAR(500)    NOT NULL COMMENT 'Deterministic hash of (variant_id, region_id, purchase_window) for grouping',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.9 `group_memberships`

```sql
CREATE TABLE group_memberships (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id     BIGINT UNSIGNED NOT NULL,
    buyer_id     BIGINT UNSIGNED NOT NULL,
    request_id   BIGINT UNSIGNED NULL,
    qty          INT UNSIGNED    NOT NULL,
    intent_level TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'L1–L5 per Section 4.2',
    confirmed_at TIMESTAMP       NULL     DEFAULT NULL COMMENT 'Set when buyer reconfirms purchase-ready intent',
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CHECK (qty > 0),
    CHECK (intent_level BETWEEN 1 AND 5),

    PRIMARY KEY (id),
    UNIQUE KEY uq_group_memberships_group_buyer (group_id, buyer_id),
    KEY idx_group_memberships_buyer   (buyer_id),
    KEY idx_group_memberships_request (request_id),
    CONSTRAINT fk_gm_group   FOREIGN KEY (group_id)   REFERENCES demand_groups (id) ON DELETE RESTRICT,
    CONSTRAINT fk_gm_buyer   FOREIGN KEY (buyer_id)   REFERENCES users         (id) ON DELETE RESTRICT,
    CONSTRAINT fk_gm_request FOREIGN KEY (request_id) REFERENCES buy_requests  (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.10 `group_intent_events`

```sql
CREATE TABLE group_intent_events (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    membership_id BIGINT UNSIGNED NOT NULL,
    from_level    TINYINT UNSIGNED NOT NULL,
    to_level      TINYINT UNSIGNED NOT NULL,
    reason        VARCHAR(255)    NULL,
    actor_id      BIGINT UNSIGNED NULL     COMMENT 'User who triggered the transition; NULL for system events',
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CHECK (from_level BETWEEN 0 AND 5),
    CHECK (to_level   BETWEEN 0 AND 5),

    PRIMARY KEY (id),
    KEY idx_gie_membership (membership_id),
    CONSTRAINT fk_gie_membership FOREIGN KEY (membership_id) REFERENCES group_memberships (id) ON DELETE CASCADE,
    CONSTRAINT fk_gie_actor      FOREIGN KEY (actor_id)      REFERENCES users             (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.11 `group_compatibility_rules`

```sql
CREATE TABLE group_compatibility_rules (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id             BIGINT UNSIGNED NOT NULL,
    allowed_variants_json JSON           NOT NULL COMMENT 'Array of variant IDs acceptable as substitutes',
    constraints_json      JSON           NULL     COMMENT 'e.g. {"require_brand":true,"max_price_delta_pct":5}',
    created_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_gcr_group (group_id),
    CONSTRAINT fk_gcr_group FOREIGN KEY (group_id) REFERENCES demand_groups (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Module 3 — RFQ, Bids & Procurement

### 3.1 `rfqs`

```sql
CREATE TABLE rfqs (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id             BIGINT UNSIGNED NOT NULL,
    spec_snapshot_json   JSON            NOT NULL COMMENT 'Immutable snapshot of spec sent to sellers; do not modify after publication',
    qty_band_json        JSON            NOT NULL COMMENT '{"min":int,"max":int} visible to bidders; never expose individual buyer budgets',
    bid_deadline         TIMESTAMP       NOT NULL,
    status               ENUM(
                             'draft',
                             'published',
                             'closed',
                             'awarded',
                             'cancelled'
                         )               NOT NULL DEFAULT 'draft',
    created_by           BIGINT UNSIGNED NULL     COMMENT 'Procurement agent user_id',
    created_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_rfqs_group  (group_id),
    KEY idx_rfqs_status (status),
    CONSTRAINT fk_rfqs_group      FOREIGN KEY (group_id)   REFERENCES demand_groups (id) ON DELETE RESTRICT,
    CONSTRAINT fk_rfqs_created_by FOREIGN KEY (created_by) REFERENCES users         (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.2 `rfq_seller_invitations`

```sql
CREATE TABLE rfq_seller_invitations (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rfq_id     BIGINT UNSIGNED NOT NULL,
    seller_id  BIGINT UNSIGNED NOT NULL COMMENT 'References seller_profiles.id',
    status     ENUM(
                   'invited',
                   'viewed',
                   'declined',
                   'bid_submitted'
               )               NOT NULL DEFAULT 'invited',
    invited_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_rfq_invitation (rfq_id, seller_id),
    KEY idx_rfq_inv_seller (seller_id),
    CONSTRAINT fk_rfq_inv_rfq    FOREIGN KEY (rfq_id)    REFERENCES rfqs            (id) ON DELETE CASCADE,
    CONSTRAINT fk_rfq_inv_seller FOREIGN KEY (seller_id) REFERENCES seller_profiles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.3 `seller_bids`

```sql
CREATE TABLE seller_bids (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rfq_id              BIGINT UNSIGNED NOT NULL,
    seller_id           BIGINT UNSIGNED NOT NULL COMMENT 'References seller_profiles.id',
    source              ENUM(
                            'platform',
                            'offline_procurement',
                            'supplier_lead'
                        )               NOT NULL DEFAULT 'platform',
    status              ENUM(
                            'active',
                            'withdrawn',
                            'disqualified',
                            'awarded',
                            'expired'
                        )               NOT NULL DEFAULT 'active',
    latest_revision_id  BIGINT UNSIGNED NULL     COMMENT 'Denormalised pointer to current seller_bid_revisions.id; updated atomically',
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_seller_bids_rfq_seller (rfq_id, seller_id),   -- Section 15.6: one bid identity per seller per RFQ
    KEY idx_seller_bids_rfq_seller (rfq_id, seller_id),
    KEY idx_seller_bids_seller     (seller_id),
    CONSTRAINT fk_seller_bids_rfq    FOREIGN KEY (rfq_id)    REFERENCES rfqs            (id) ON DELETE RESTRICT,
    CONSTRAINT fk_seller_bids_seller FOREIGN KEY (seller_id) REFERENCES seller_profiles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.4 `seller_bid_revisions`

```sql
CREATE TABLE seller_bid_revisions (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    bid_id            BIGINT UNSIGNED NOT NULL,
    revision_no       INT UNSIGNED    NOT NULL COMMENT 'Monotonically increasing; 1-based',
    unit_price_paise  BIGINT          NOT NULL COMMENT 'Per-unit price before tax',
    tax_basis         VARCHAR(100)    NOT NULL COMMENT 'e.g. GST_18_PERCENT, EXEMPT',
    freight_paise     BIGINT          NOT NULL DEFAULT 0,
    moq               INT UNSIGNED    NOT NULL COMMENT 'Minimum order quantity for this price',
    max_qty           INT UNSIGNED    NULL     COMMENT 'Maximum quantity seller can supply; NULL = unlimited',
    valid_until       TIMESTAMP       NOT NULL,
    lead_time_days    SMALLINT UNSIGNED NOT NULL,
    warranty_snapshot JSON            NULL     COMMENT 'Terms snapshot at time of bid',
    evidence_ref      VARCHAR(500)    NULL     COMMENT 'Object storage path to supporting documents',
    submitted_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    CHECK (unit_price_paise  >= 0),
    CHECK (freight_paise     >= 0),
    CHECK (moq               >  0),
    CHECK (max_qty IS NULL OR max_qty >= moq),

    PRIMARY KEY (id),
    UNIQUE KEY uq_bid_revision (bid_id, revision_no),
    KEY idx_sbr_bid (bid_id),
    CONSTRAINT fk_sbr_bid FOREIGN KEY (bid_id) REFERENCES seller_bids (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.5 `procurement_cases`

```sql
CREATE TABLE procurement_cases (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rfq_id           BIGINT UNSIGNED NOT NULL,
    assigned_agent_id BIGINT UNSIGNED NULL    COMMENT 'Internal procurement agent user_id',
    priority         TINYINT UNSIGNED NOT NULL DEFAULT 2 COMMENT '1=urgent 2=normal 3=low',
    stage            ENUM(
                         'sourcing',
                         'bids_received',
                         'normalisation',
                         'negotiation',
                         'approval_pending',
                         'offer_published',
                         'closed_awarded',
                         'closed_no_deal'
                     )               NOT NULL DEFAULT 'sourcing',
    sla_due_at       TIMESTAMP       NULL     DEFAULT NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_proc_cases_rfq    (rfq_id),
    KEY idx_proc_cases_agent  (assigned_agent_id),
    KEY idx_proc_cases_stage  (stage),
    CONSTRAINT fk_proc_cases_rfq   FOREIGN KEY (rfq_id)            REFERENCES rfqs  (id) ON DELETE RESTRICT,
    CONSTRAINT fk_proc_cases_agent FOREIGN KEY (assigned_agent_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.6 `supplier_leads`

```sql
CREATE TABLE supplier_leads (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    legal_name          VARCHAR(255)    NOT NULL,
    source              VARCHAR(100)    NOT NULL COMMENT 'e.g. field_research, referral, online_search',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.7 `supplier_contacts`

```sql
CREATE TABLE supplier_contacts (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    supplier_lead_id BIGINT UNSIGNED NOT NULL,
    method           VARCHAR(50)     NOT NULL COMMENT 'e.g. phone, email, in_person',
    outcome          VARCHAR(255)    NULL,
    notes            TEXT            NULL,
    contacted_by     BIGINT UNSIGNED NOT NULL COMMENT 'Internal user_id who made contact',
    contacted_at     TIMESTAMP       NOT NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_supplier_contacts_lead (supplier_lead_id),
    CONSTRAINT fk_sc_lead         FOREIGN KEY (supplier_lead_id) REFERENCES supplier_leads (id) ON DELETE CASCADE,
    CONSTRAINT fk_sc_contacted_by FOREIGN KEY (contacted_by)     REFERENCES users          (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.8 `negotiation_events`

```sql
CREATE TABLE negotiation_events (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    case_id     BIGINT UNSIGNED NOT NULL,
    supplier_id BIGINT UNSIGNED NULL     COMMENT 'seller_profiles.id or supplier_leads.id depending on type',
    bid_id      BIGINT UNSIGNED NULL     COMMENT 'References seller_bids.id if applicable',
    type        VARCHAR(100)    NOT NULL COMMENT 'e.g. call_logged, offline_quote_recorded, counter_offer, approval_requested',
    terms_json  JSON            NULL     COMMENT 'Structured terms discussed or agreed at this event',
    created_by  BIGINT UNSIGNED NOT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_neg_events_case (case_id),
    KEY idx_neg_events_bid  (bid_id),
    CONSTRAINT fk_ne_case       FOREIGN KEY (case_id)    REFERENCES procurement_cases (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ne_bid        FOREIGN KEY (bid_id)     REFERENCES seller_bids       (id) ON DELETE SET NULL,
    CONSTRAINT fk_ne_created_by FOREIGN KEY (created_by) REFERENCES users             (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.9 `negotiation_attachments`

```sql
CREATE TABLE negotiation_attachments (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id   BIGINT UNSIGNED NOT NULL,
    object_ref VARCHAR(500)    NOT NULL COMMENT 'Object storage path/key',
    mime       VARCHAR(100)    NOT NULL,
    checksum   VARCHAR(128)    NOT NULL COMMENT 'SHA-256 hex of file content for integrity',
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_neg_attach_event (event_id),
    CONSTRAINT fk_na_event FOREIGN KEY (event_id) REFERENCES negotiation_events (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.10 `procurement_tasks`

```sql
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Module 4 — Offers, Commitments & Orders

### 4.1 `negotiated_offers`

```sql
CREATE TABLE negotiated_offers (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    group_id                BIGINT UNSIGNED NOT NULL,
    seller_id               BIGINT UNSIGNED NOT NULL COMMENT 'References seller_profiles.id',
    source_bid_revision_id  BIGINT UNSIGNED NULL     COMMENT 'Links back to winning bid revision; NULL for offline-sourced offers',
    status                  ENUM(
                                'draft',
                                'pending_approval',
                                'approved',
                                'published',
                                'moq_met',
                                'moq_failed',
                                'expired',
                                'cancelled'
                            )               NOT NULL DEFAULT 'draft',
    version                 INT UNSIGNED    NOT NULL DEFAULT 1 COMMENT 'Optimistic lock version',
    expires_at              TIMESTAMP       NOT NULL,
    terms_snapshot_json     JSON            NOT NULL COMMENT 'Immutable snapshot of all commercial terms at publication; never updated after publish',
    created_by              BIGINT UNSIGNED NULL,
    created_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_neg_offers_group  (group_id),
    KEY idx_neg_offers_seller (seller_id),
    KEY idx_neg_offers_status (status),
    CONSTRAINT fk_no_group         FOREIGN KEY (group_id)               REFERENCES demand_groups      (id) ON DELETE RESTRICT,
    CONSTRAINT fk_no_seller        FOREIGN KEY (seller_id)              REFERENCES seller_profiles    (id) ON DELETE RESTRICT,
    CONSTRAINT fk_no_bid_revision  FOREIGN KEY (source_bid_revision_id) REFERENCES seller_bid_revisions (id) ON DELETE SET NULL,
    CONSTRAINT fk_no_created_by    FOREIGN KEY (created_by)             REFERENCES users              (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.2 `offer_price_tiers`

```sql
CREATE TABLE offer_price_tiers (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    offer_id         BIGINT UNSIGNED NOT NULL,
    min_paid_qty     INT UNSIGNED    NOT NULL,
    max_paid_qty     INT UNSIGNED    NULL     COMMENT 'NULL = no upper bound for this tier',
    unit_price_paise BIGINT          NOT NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CHECK (unit_price_paise >= 0),
    CHECK (min_paid_qty     >  0),
    CHECK (max_paid_qty IS NULL OR max_paid_qty >= min_paid_qty),

    PRIMARY KEY (id),
    KEY idx_opt_offer (offer_id),
    CONSTRAINT fk_opt_offer FOREIGN KEY (offer_id) REFERENCES negotiated_offers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.3 `offer_approvals`

```sql
CREATE TABLE offer_approvals (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    offer_id    BIGINT UNSIGNED NOT NULL,
    approver_id BIGINT UNSIGNED NOT NULL COMMENT 'Must differ from offer creator; enforced at application layer per Section 2',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.4 `offer_acceptances`

```sql
CREATE TABLE offer_acceptances (
    id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    offer_id            BIGINT UNSIGNED NOT NULL,
    buyer_id            BIGINT UNSIGNED NOT NULL,
    qty                 INT UNSIGNED    NOT NULL,
    accepted_terms_hash VARCHAR(128)    NOT NULL COMMENT 'SHA-256 of terms_snapshot_json at time of acceptance; proves which version buyer agreed to',
    accepted_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CHECK (qty > 0),

    PRIMARY KEY (id),
    UNIQUE KEY uq_offer_acceptance_offer_buyer (offer_id, buyer_id),
    KEY idx_oa_buyer (buyer_id),
    CONSTRAINT fk_oac_offer FOREIGN KEY (offer_id) REFERENCES negotiated_offers (id) ON DELETE RESTRICT,
    CONSTRAINT fk_oac_buyer FOREIGN KEY (buyer_id) REFERENCES users             (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.5 `stock_reservations`

```sql
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.6 `orders`

```sql
CREATE TABLE orders (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    buyer_id     BIGINT UNSIGNED NOT NULL,
    group_id     BIGINT UNSIGNED NOT NULL,
    offer_id     BIGINT UNSIGNED NOT NULL,
    status       ENUM(
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
    total_paise  BIGINT          NOT NULL,
    currency     CHAR(3)         NOT NULL DEFAULT 'INR',
    terms_hash   VARCHAR(128)    NOT NULL COMMENT 'Must match offer_acceptances.accepted_terms_hash',
    deleted_at   TIMESTAMP       NULL     DEFAULT NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CHECK (total_paise >= 0),

    PRIMARY KEY (id),
    KEY idx_orders_buyer_status (buyer_id, status),
    KEY idx_orders_offer        (offer_id),
    KEY idx_orders_group        (group_id),
    CONSTRAINT fk_orders_buyer FOREIGN KEY (buyer_id) REFERENCES users             (id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_group FOREIGN KEY (group_id) REFERENCES demand_groups     (id) ON DELETE RESTRICT,
    CONSTRAINT fk_orders_offer FOREIGN KEY (offer_id) REFERENCES negotiated_offers (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.7 `order_items`

```sql
CREATE TABLE order_items (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id         BIGINT UNSIGNED NOT NULL,
    variant_id       BIGINT UNSIGNED NOT NULL,
    qty              INT UNSIGNED    NOT NULL,
    unit_price_paise BIGINT          NOT NULL,
    tax_paise        BIGINT          NOT NULL DEFAULT 0,
    shipping_paise   BIGINT          NOT NULL DEFAULT 0,
    snapshot_json    JSON            NOT NULL COMMENT 'Immutable snapshot of product/variant/price at time of order',
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.8 `order_status_events`

```sql
CREATE TABLE order_status_events (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id   BIGINT UNSIGNED NOT NULL,
    from_state VARCHAR(50)     NULL     COMMENT 'NULL for initial creation event',
    to_state   VARCHAR(50)     NOT NULL,
    actor_id   BIGINT UNSIGNED NULL,
    reason     VARCHAR(500)    NULL,
    created_at TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Append-only: no updated_at, no deletes
    PRIMARY KEY (id),
    KEY idx_ose_order (order_id),
    CONSTRAINT fk_ose_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ose_actor FOREIGN KEY (actor_id) REFERENCES users  (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 4.9 `seller_fulfillment_orders`

```sql
CREATE TABLE seller_fulfillment_orders (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_id                 BIGINT UNSIGNED NOT NULL COMMENT 'References seller_profiles.id',
    offer_id                  BIGINT UNSIGNED NOT NULL,
    status                    ENUM(
                                  'pending_confirmation',
                                  'confirmed',
                                  'preparing',
                                  'dispatched',
                                  'completed',
                                  'cancelled'
                              )               NOT NULL DEFAULT 'pending_confirmation',
    supplier_confirmation_ref VARCHAR(255)    NULL     COMMENT 'Supplier-issued PO or confirmation number',
    created_at                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_sfo_seller (seller_id),
    KEY idx_sfo_offer  (offer_id),
    CONSTRAINT fk_sfo_seller FOREIGN KEY (seller_id) REFERENCES seller_profiles    (id) ON DELETE RESTRICT,
    CONSTRAINT fk_sfo_offer  FOREIGN KEY (offer_id)  REFERENCES negotiated_offers  (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Module 5 — Payments & Finance

### 5.1 `payment_intents`

```sql
CREATE TABLE payment_intents (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id         BIGINT UNSIGNED NOT NULL,
    provider         VARCHAR(50)     NOT NULL COMMENT 'e.g. razorpay, cashfree',
    provider_ref     VARCHAR(255)    NULL     COMMENT 'Provider-assigned payment/order ID',
    amount_paise     BIGINT          NOT NULL,
    status           ENUM(
                         'created',
                         'pending',
                         'authorized',
                         'captured',
                         'failed',
                         'refunded',
                         'cancelled'
                     )               NOT NULL DEFAULT 'created',
    idempotency_key  VARCHAR(255)    NOT NULL COMMENT 'Client-generated key to prevent duplicate payment creation',
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CHECK (amount_paise > 0),

    PRIMARY KEY (id),
    UNIQUE KEY uq_payment_idempotency (idempotency_key),
    KEY idx_payment_intents_order    (order_id),
    KEY idx_payment_intents_provider (provider, provider_ref),
    CONSTRAINT fk_pi_order FOREIGN KEY (order_id) REFERENCES orders (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.2 `payment_webhook_events`

```sql
CREATE TABLE payment_webhook_events (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider          VARCHAR(50)     NOT NULL,
    provider_event_id VARCHAR(255)    NOT NULL COMMENT 'Provider-assigned event ID; used for replay protection',
    signature_valid   TINYINT(1)      NOT NULL DEFAULT 0,
    payload_ref       VARCHAR(500)    NULL     COMMENT 'Object storage path to raw webhook payload',
    processed_at      TIMESTAMP       NULL     DEFAULT NULL,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Append-only
    PRIMARY KEY (id),
    UNIQUE KEY uq_webhook_provider_event (provider, provider_event_id),   -- Section 15.6
    KEY idx_webhook_processed (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.3 `ledger_accounts`

```sql
CREATE TABLE ledger_accounts (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(50)     NOT NULL COMMENT 'e.g. BUYER_PAYABLE, SELLER_ESCROW, PLATFORM_REVENUE, GATEWAY_FEE',
    name        VARCHAR(255)    NOT NULL,
    type        ENUM(
                    'asset',
                    'liability',
                    'revenue',
                    'expense',
                    'equity'
                )               NOT NULL,
    is_active   TINYINT(1)      NOT NULL DEFAULT 1,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_ledger_accounts_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.4 `ledger_entries`

```sql
CREATE TABLE ledger_entries (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type      VARCHAR(50)     NOT NULL COMMENT 'e.g. payment_intent, refund, settlement',
    entity_id        BIGINT UNSIGNED NOT NULL,
    description      VARCHAR(500)    NULL,
    created_by       BIGINT UNSIGNED NULL,
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Append-only: no updates or deletes
    PRIMARY KEY (id),
    KEY idx_ledger_entries_entity (entity_type, entity_id),
    CONSTRAINT fk_le_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.5 `ledger_postings`

```sql
CREATE TABLE ledger_postings (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entry_id          BIGINT UNSIGNED NOT NULL,
    account_id        BIGINT UNSIGNED NOT NULL,
    debit_paise       BIGINT          NOT NULL DEFAULT 0,
    credit_paise      BIGINT          NOT NULL DEFAULT 0,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Every entry must have balanced postings (SUM(debit)=SUM(credit)); enforced at application layer
    -- Append-only: no updates or deletes
    CHECK (debit_paise  >= 0),
    CHECK (credit_paise >= 0),
    CHECK (debit_paise > 0 OR credit_paise > 0),

    PRIMARY KEY (id),
    KEY idx_ledger_postings_entry   (entry_id),
    KEY idx_ledger_postings_account (account_id),
    CONSTRAINT fk_lp_entry   FOREIGN KEY (entry_id)   REFERENCES ledger_entries  (id) ON DELETE RESTRICT,
    CONSTRAINT fk_lp_account FOREIGN KEY (account_id) REFERENCES ledger_accounts (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.6 `refunds`

```sql
CREATE TABLE refunds (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    payment_id      BIGINT UNSIGNED NOT NULL COMMENT 'References payment_intents.id',
    order_item_id   BIGINT UNSIGNED NULL     COMMENT 'NULL = whole-order refund',
    amount_paise    BIGINT          NOT NULL,
    reason          VARCHAR(500)    NOT NULL,
    status          ENUM(
                        'requested',
                        'approved',
                        'processing',
                        'completed',
                        'failed',
                        'rejected'
                    )               NOT NULL DEFAULT 'requested',
    provider_ref    VARCHAR(255)    NULL     COMMENT 'Provider-assigned refund ID',
    approved_by     BIGINT UNSIGNED NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CHECK (amount_paise > 0),

    PRIMARY KEY (id),
    KEY idx_refunds_payment (payment_id),
    KEY idx_refunds_status  (status),
    CONSTRAINT fk_refunds_payment    FOREIGN KEY (payment_id)    REFERENCES payment_intents (id) ON DELETE RESTRICT,
    CONSTRAINT fk_refunds_order_item FOREIGN KEY (order_item_id) REFERENCES order_items     (id) ON DELETE SET NULL,
    CONSTRAINT fk_refunds_approved   FOREIGN KEY (approved_by)   REFERENCES users           (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.7 `seller_settlements`

```sql
CREATE TABLE seller_settlements (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    seller_id         BIGINT UNSIGNED NOT NULL COMMENT 'References seller_profiles.id',
    provider_ref      VARCHAR(255)    NULL     COMMENT 'Provider-assigned payout/transfer ID',
    eligible_paise    BIGINT          NOT NULL COMMENT 'Total amount eligible for release after holds and fees',
    requested_paise   BIGINT          NOT NULL COMMENT 'Amount requested in this settlement run',
    confirmed_paise   BIGINT          NULL     COMMENT 'Actual amount confirmed by provider; set after provider confirmation',
    status            ENUM(
                          'calculating',
                          'pending_approval',
                          'approved',
                          'processing',
                          'completed',
                          'failed',
                          'on_hold'
                      )               NOT NULL DEFAULT 'calculating',
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CHECK (eligible_paise  >= 0),
    CHECK (requested_paise >= 0),
    CHECK (confirmed_paise IS NULL OR confirmed_paise >= 0),

    PRIMARY KEY (id),
    KEY idx_settlements_seller_status (seller_id, status),
    CONSTRAINT fk_ss_seller FOREIGN KEY (seller_id) REFERENCES seller_profiles (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.8 `settlement_items`

```sql
CREATE TABLE settlement_items (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    settlement_id  BIGINT UNSIGNED NOT NULL,
    order_item_id  BIGINT UNSIGNED NOT NULL,
    eligible_qty   INT UNSIGNED    NOT NULL,
    amount_paise   BIGINT          NOT NULL,
    created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CHECK (eligible_qty  > 0),
    CHECK (amount_paise >= 0),

    PRIMARY KEY (id),
    KEY idx_si_settlement (settlement_id),
    KEY idx_si_order_item (order_item_id),
    CONSTRAINT fk_si_settlement FOREIGN KEY (settlement_id) REFERENCES seller_settlements (id) ON DELETE CASCADE,
    CONSTRAINT fk_si_order_item FOREIGN KEY (order_item_id) REFERENCES order_items        (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.9 `settlement_approvals`

```sql
CREATE TABLE settlement_approvals (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    settlement_id BIGINT UNSIGNED NOT NULL,
    approver_id   BIGINT UNSIGNED NOT NULL COMMENT 'Finance approver; must have segregated authority per Section 2',
    decision      ENUM(
                      'pending',
                      'approved',
                      'rejected'
                  )               NOT NULL DEFAULT 'pending',
    reason        TEXT            NULL,
    decided_at    TIMESTAMP       NULL     DEFAULT NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_settlement_approvals_settlement (settlement_id),
    CONSTRAINT fk_sap_settlement FOREIGN KEY (settlement_id) REFERENCES seller_settlements (id) ON DELETE CASCADE,
    CONSTRAINT fk_sap_approver   FOREIGN KEY (approver_id)   REFERENCES users              (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.10 `reconciliation_runs`

```sql
CREATE TABLE reconciliation_runs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider     VARCHAR(50)     NOT NULL,
    period_start TIMESTAMP       NOT NULL,
    period_end   TIMESTAMP       NOT NULL,
    status       ENUM(
                     'in_progress',
                     'completed',
                     'failed'
                 )               NOT NULL DEFAULT 'in_progress',
    run_by       BIGINT UNSIGNED NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_recon_runs_provider (provider, period_start),
    CONSTRAINT fk_rr_run_by FOREIGN KEY (run_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 5.11 `reconciliation_exceptions`

```sql
CREATE TABLE reconciliation_exceptions (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id             BIGINT UNSIGNED NOT NULL,
    entity_type        VARCHAR(50)     NOT NULL COMMENT 'e.g. payment_intent, refund, settlement',
    entity_id          BIGINT UNSIGNED NOT NULL,
    discrepancy_paise  BIGINT          NOT NULL COMMENT 'Signed: positive = platform over-recorded, negative = under-recorded',
    description        TEXT            NULL,
    status             ENUM(
                           'open',
                           'investigating',
                           'resolved',
                           'waived'
                       )               NOT NULL DEFAULT 'open',
    resolved_by        BIGINT UNSIGNED NULL,
    resolved_at        TIMESTAMP       NULL     DEFAULT NULL,
    created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_recon_exc_run    (run_id),
    KEY idx_recon_exc_entity (entity_type, entity_id),
    CONSTRAINT fk_re_run         FOREIGN KEY (run_id)      REFERENCES reconciliation_runs (id) ON DELETE RESTRICT,
    CONSTRAINT fk_re_resolved_by FOREIGN KEY (resolved_by) REFERENCES users               (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Module 6 — Fulfillment & Quality

### 6.1 `shipments`

```sql
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.2 `shipment_items`

```sql
CREATE TABLE shipment_items (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shipment_id   BIGINT UNSIGNED NOT NULL,
    order_item_id BIGINT UNSIGNED NOT NULL,
    qty           INT UNSIGNED    NOT NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CHECK (qty > 0),

    PRIMARY KEY (id),
    KEY idx_shipment_items_shipment    (shipment_id),
    KEY idx_shipment_items_order_item  (order_item_id),
    CONSTRAINT fk_shipitem_shipment   FOREIGN KEY (shipment_id)   REFERENCES shipments   (id) ON DELETE CASCADE,
    CONSTRAINT fk_shipitem_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.3 `delivery_evidence`

```sql
CREATE TABLE delivery_evidence (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    shipment_id   BIGINT UNSIGNED NOT NULL,
    evidence_type VARCHAR(50)     NOT NULL COMMENT 'e.g. pod_photo, signature, otp_confirmation',
    object_ref    VARCHAR(500)    NOT NULL COMMENT 'Object storage path',
    verified_at   TIMESTAMP       NULL     DEFAULT NULL,
    verified_by   BIGINT UNSIGNED NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_delivery_evidence_shipment (shipment_id),
    CONSTRAINT fk_de_shipment   FOREIGN KEY (shipment_id) REFERENCES shipments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_de_verified_by FOREIGN KEY (verified_by) REFERENCES users    (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.4 `inspections`

```sql
CREATE TABLE inspections (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id          BIGINT UNSIGNED NOT NULL,
    shipment_id       BIGINT UNSIGNED NULL,
    tier              TINYINT UNSIGNED NOT NULL COMMENT '1=self-report, 2=photo-verification, 3=physical-inspection',
    inspector_id      BIGINT UNSIGNED NULL     COMMENT 'user_id of inspector or inspection partner org user',
    status            ENUM(
                          'pending',
                          'in_progress',
                          'completed',
                          'skipped'
                      )               NOT NULL DEFAULT 'pending',
    result            ENUM(
                          'pass',
                          'pass_with_remarks',
                          'fail',
                          'inconclusive'
                      )               NULL     DEFAULT NULL,
    checklist_snapshot JSON           NULL     COMMENT 'Checklist version at time of inspection',
    completed_at      TIMESTAMP       NULL     DEFAULT NULL,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_inspections_order    (order_id),
    KEY idx_inspections_shipment (shipment_id),
    CONSTRAINT fk_insp_order     FOREIGN KEY (order_id)     REFERENCES orders    (id) ON DELETE RESTRICT,
    CONSTRAINT fk_insp_shipment  FOREIGN KEY (shipment_id)  REFERENCES shipments (id) ON DELETE SET NULL,
    CONSTRAINT fk_insp_inspector FOREIGN KEY (inspector_id) REFERENCES users     (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.5 `inspection_findings`

```sql
CREATE TABLE inspection_findings (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    inspection_id  BIGINT UNSIGNED NOT NULL,
    order_item_id  BIGINT UNSIGNED NOT NULL,
    finding_type   VARCHAR(100)    NOT NULL COMMENT 'e.g. wrong_model, damaged, missing_accessory, quantity_short',
    qty_affected   INT UNSIGNED    NOT NULL DEFAULT 1,
    description    TEXT            NULL,
    evidence_ref   VARCHAR(500)    NULL     COMMENT 'Object storage path to supporting photo/video',
    created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CHECK (qty_affected > 0),

    PRIMARY KEY (id),
    KEY idx_findings_inspection (inspection_id),
    KEY idx_findings_order_item (order_item_id),
    CONSTRAINT fk_if_inspection FOREIGN KEY (inspection_id) REFERENCES inspections (id) ON DELETE CASCADE,
    CONSTRAINT fk_if_order_item FOREIGN KEY (order_item_id) REFERENCES order_items (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 6.6 `buyer_acknowledgements`

```sql
CREATE TABLE buyer_acknowledgements (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id          BIGINT UNSIGNED NOT NULL,
    shipment_id       BIGINT UNSIGNED NOT NULL,
    buyer_id          BIGINT UNSIGNED NOT NULL,
    accepted_qty_json JSON            NOT NULL COMMENT 'Map of order_item_id -> accepted qty',
    disputed_qty_json JSON            NOT NULL COMMENT 'Map of order_item_id -> disputed qty',
    submitted_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_buyer_ack_order_shipment_buyer (order_id, shipment_id, buyer_id),
    KEY idx_buyer_ack_buyer (buyer_id),
    CONSTRAINT fk_ba_order    FOREIGN KEY (order_id)    REFERENCES orders    (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ba_shipment FOREIGN KEY (shipment_id) REFERENCES shipments (id) ON DELETE RESTRICT,
    CONSTRAINT fk_ba_buyer    FOREIGN KEY (buyer_id)    REFERENCES users     (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Module 7 — Disputes, Notifications & Audit

### 7.1 `disputes`

```sql
CREATE TABLE disputes (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id    BIGINT UNSIGNED NOT NULL,
    type        ENUM(
                    'wrong_item',
                    'quantity_short',
                    'damaged',
                    'not_delivered',
                    'quality_issue',
                    'payment_issue',
                    'other'
                )               NOT NULL,
    status      ENUM(
                    'open',
                    'investigating',
                    'evidence_requested',
                    'resolved_buyer_favour',
                    'resolved_seller_favour',
                    'escalated',
                    'closed'
                )               NOT NULL DEFAULT 'open',
    opened_by   BIGINT UNSIGNED NOT NULL COMMENT 'Buyer or internal agent user_id',
    assigned_to BIGINT UNSIGNED NULL     COMMENT 'Support/disputes team user_id',
    resolution  TEXT            NULL     COMMENT 'Free-form resolution summary recorded at close',
    closed_at   TIMESTAMP       NULL     DEFAULT NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_disputes_order  (order_id),
    KEY idx_disputes_status (status),
    CONSTRAINT fk_disp_order       FOREIGN KEY (order_id)    REFERENCES orders (id) ON DELETE RESTRICT,
    CONSTRAINT fk_disp_opened_by   FOREIGN KEY (opened_by)   REFERENCES users  (id) ON DELETE RESTRICT,
    CONSTRAINT fk_disp_assigned_to FOREIGN KEY (assigned_to) REFERENCES users  (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 7.2 `dispute_events`

```sql
CREATE TABLE dispute_events (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dispute_id  BIGINT UNSIGNED NOT NULL,
    from_state  VARCHAR(50)     NULL,
    to_state    VARCHAR(50)     NOT NULL,
    actor_id    BIGINT UNSIGNED NULL,
    note        TEXT            NULL,
    created_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Append-only
    PRIMARY KEY (id),
    KEY idx_dispute_events_dispute (dispute_id),
    CONSTRAINT fk_de_dispute FOREIGN KEY (dispute_id) REFERENCES disputes (id) ON DELETE RESTRICT,
    CONSTRAINT fk_de_actor   FOREIGN KEY (actor_id)   REFERENCES users    (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 7.3 `dispute_evidence`

```sql
CREATE TABLE dispute_evidence (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dispute_id      BIGINT UNSIGNED NOT NULL,
    submitted_by    BIGINT UNSIGNED NOT NULL,
    submitter_role  VARCHAR(50)     NOT NULL COMMENT 'e.g. buyer, seller, support',
    evidence_type   VARCHAR(50)     NOT NULL COMMENT 'e.g. photo, video, invoice, chat_export',
    object_ref      VARCHAR(500)    NOT NULL,
    description     VARCHAR(1000)   NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_disp_evidence_dispute (dispute_id),
    CONSTRAINT fk_dev_dispute      FOREIGN KEY (dispute_id)   REFERENCES disputes (id) ON DELETE RESTRICT,
    CONSTRAINT fk_dev_submitted_by FOREIGN KEY (submitted_by) REFERENCES users    (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 7.4 `dispute_adjustments`

```sql
CREATE TABLE dispute_adjustments (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    dispute_id    BIGINT UNSIGNED NOT NULL,
    type          ENUM(
                      'refund',
                      'partial_refund',
                      'replacement',
                      'credit_note',
                      'seller_deduction'
                  )               NOT NULL,
    amount_paise  BIGINT          NOT NULL DEFAULT 0,
    description   TEXT            NULL,
    approved_by   BIGINT UNSIGNED NOT NULL COMMENT 'Finance approver; different from requester',
    executed_at   TIMESTAMP       NULL     DEFAULT NULL,
    created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CHECK (amount_paise >= 0),

    PRIMARY KEY (id),
    KEY idx_disp_adj_dispute (dispute_id),
    CONSTRAINT fk_da_dispute     FOREIGN KEY (dispute_id) REFERENCES disputes (id) ON DELETE RESTRICT,
    CONSTRAINT fk_da_approved_by FOREIGN KEY (approved_by) REFERENCES users   (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 7.5 `notifications`

```sql
CREATE TABLE notifications (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    recipient_id BIGINT UNSIGNED NOT NULL COMMENT 'References users.id',
    event_type   VARCHAR(100)    NOT NULL COMMENT 'e.g. offer.published, order.dispatched, dispute.update',
    entity_type  VARCHAR(50)     NULL,
    entity_id    BIGINT UNSIGNED NULL,
    title        VARCHAR(500)    NOT NULL,
    body         TEXT            NULL,
    read_at      TIMESTAMP       NULL     DEFAULT NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_notifications_recipient (recipient_id, read_at),
    CONSTRAINT fk_notif_recipient FOREIGN KEY (recipient_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 7.6 `notification_deliveries`

```sql
CREATE TABLE notification_deliveries (
    id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    notification_id BIGINT UNSIGNED NOT NULL,
    channel         ENUM(
                        'in_app',
                        'sms',
                        'email',
                        'whatsapp'
                    )               NOT NULL,
    status          ENUM(
                        'queued',
                        'sent',
                        'delivered',
                        'failed'
                    )               NOT NULL DEFAULT 'queued',
    provider_ref    VARCHAR(255)    NULL,
    attempted_at    TIMESTAMP       NULL     DEFAULT NULL,
    created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_notif_del_notification (notification_id),
    KEY idx_notif_del_status       (status),
    CONSTRAINT fk_nd_notification FOREIGN KEY (notification_id) REFERENCES notifications (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 7.7 `audit_logs`

```sql
CREATE TABLE audit_logs (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id     BIGINT UNSIGNED NULL     COMMENT 'NULL for system/automated actions',
    actor_role   VARCHAR(100)    NULL,
    action       VARCHAR(200)    NOT NULL COMMENT 'e.g. offer.approved, settlement.released, role.granted',
    entity_type  VARCHAR(50)     NULL,
    entity_id    BIGINT UNSIGNED NULL,
    before_json  JSON            NULL     COMMENT 'State snapshot before change; NULL for creates',
    after_json   JSON            NULL     COMMENT 'State snapshot after change; NULL for deletes',
    ip_address   VARCHAR(45)     NULL     COMMENT 'IPv4 or IPv6',
    user_agent   VARCHAR(500)    NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Append-only: no UPDATE or DELETE ever
    PRIMARY KEY (id),
    KEY idx_audit_actor  (actor_id),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_action (action),
    KEY idx_audit_time   (created_at),
    CONSTRAINT fk_al_actor FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 7.8 `risk_flags`

```sql
CREATE TABLE risk_flags (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    entity_type  VARCHAR(50)     NOT NULL COMMENT 'e.g. user, seller_profile, order',
    entity_id    BIGINT UNSIGNED NOT NULL,
    flag_type    VARCHAR(100)    NOT NULL COMMENT 'e.g. duplicate_account, velocity_breach, chargeback_pattern',
    severity     ENUM(
                     'low',
                     'medium',
                     'high',
                     'critical'
                 )               NOT NULL DEFAULT 'medium',
    status       ENUM(
                     'open',
                     'investigating',
                     'resolved',
                     'false_positive'
                 )               NOT NULL DEFAULT 'open',
    raised_by    BIGINT UNSIGNED NULL     COMMENT 'NULL for system-generated flags',
    resolved_by  BIGINT UNSIGNED NULL,
    resolved_at  TIMESTAMP       NULL     DEFAULT NULL,
    notes        TEXT            NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_risk_flags_entity (entity_type, entity_id),
    KEY idx_risk_flags_status (status),
    CONSTRAINT fk_rf_raised_by  FOREIGN KEY (raised_by)   REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_rf_resolved_by FOREIGN KEY (resolved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 7.9 `policy_versions`

```sql
CREATE TABLE policy_versions (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    policy_key   VARCHAR(100)    NOT NULL COMMENT 'e.g. buyer_terms, privacy_notice, seller_agreement',
    version      VARCHAR(20)     NOT NULL,
    effective_at TIMESTAMP       NOT NULL,
    content_ref  VARCHAR(500)    NOT NULL COMMENT 'Object storage path to policy document',
    created_by   BIGINT UNSIGNED NULL,
    created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_policy_key_version (policy_key, version),
    KEY idx_policy_versions_key (policy_key, effective_at),
    CONSTRAINT fk_pv_created_by FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 7.10 `consent_records`

```sql
CREATE TABLE consent_records (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          BIGINT UNSIGNED NOT NULL,
    policy_version_id BIGINT UNSIGNED NOT NULL,
    consented_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address       VARCHAR(45)     NULL,
    user_agent       VARCHAR(500)    NULL,
    revoked_at       TIMESTAMP       NULL     DEFAULT NULL COMMENT 'Set on DPDP withdrawal of consent',
    created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_consent_user_policy (user_id, policy_version_id),
    KEY idx_consent_user (user_id),
    CONSTRAINT fk_cr_user           FOREIGN KEY (user_id)           REFERENCES users           (id) ON DELETE RESTRICT,
    CONSTRAINT fk_cr_policy_version FOREIGN KEY (policy_version_id) REFERENCES policy_versions (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---

## Section 15.6 — Index Summary

The table below consolidates every index mandated by Section 15.6, cross-referencing the CREATE TABLE statements above.

| Index | Table | Columns | Type | Purpose |
|---|---|---|---|---|
| `uq_seller_bids_rfq_seller` | `seller_bids` | `(rfq_id, seller_id)` | UNIQUE | One bid identity per seller per RFQ; revisions appended to `seller_bid_revisions` |
| `uq_webhook_provider_event` | `payment_webhook_events` | `(provider, provider_event_id)` | UNIQUE | Idempotent webhook replay protection |
| `uq_payment_idempotency` | `payment_intents` | `(idempotency_key)` | UNIQUE | Prevent duplicate payment creation |
| `idx_buy_requests_region_variant_status` | `buy_requests` | `(region_id, variant_id, status)` | INDEX | Demand grouping query |
| `idx_demand_groups_match_status` | `demand_groups` | `(match_key, status)` | INDEX | Group lookup by deterministic match key |
| `idx_seller_bids_rfq_seller` | `seller_bids` | `(rfq_id, seller_id)` | INDEX | Covered by UNIQUE above; retained for read clarity |
| `idx_orders_buyer_status` | `orders` | `(buyer_id, status)` | INDEX | Buyer order list queries |
| `idx_shipments_status` | `shipments` | `(status)` | INDEX | Ops dashboard filtering |
| `idx_settlements_seller_status` | `seller_settlements` | `(seller_id, status)` | INDEX | Settlement tracking per seller |

---

## Concurrency & Transaction Guards (Section 15.6)

The following operations MUST execute inside an explicit `BEGIN ... COMMIT` with appropriate locking:

1. **Stock reservation** — `SELECT qty FROM stock_reservations WHERE offer_id = ? AND status = 'active' FOR UPDATE` before inserting a new reservation; total active reserved qty must not exceed offer's available stock.
2. **MOQ check at checkout** — atomically read `SUM(qty)` from `offer_acceptances` and validate against the minimum tier in `offer_price_tiers` before creating `payment_intents`.
3. **Stock decrement at order confirmation** — decrement against `seller_fulfillment_orders` capacity within the same transaction as `order_status_events` insert.
4. **Settlement release** — `SELECT ... FOR UPDATE` on `seller_settlements` row before `settlement_approvals` insert to prevent double-approval race.
5. **Ledger postings** — always insert `ledger_entries` and all corresponding `ledger_postings` in a single transaction; verify `SUM(debit_paise) = SUM(credit_paise)` before commit.

---

## Design Notes

- **No raw financial secrets**: `seller_bank_accounts.provider_token` holds an opaque provider token; raw IFSC/account numbers are never stored in this schema.
- **Competitor bid isolation**: sellers have no foreign key path to other sellers' `seller_bid_revisions` rows; application-layer RBAC enforces this at query time.
- **Buyer budget privacy**: `buyer_price_preferences.max_paise` has `visibility_scope = 'private'`; it is never joined into any seller-facing query.
- **Immutable records**: `order_status_events`, `ledger_entries`, `ledger_postings`, `audit_logs`, `dispute_events`, `negotiation_events` have no `updated_at` and must never be UPDATEd or DELETEd.
- **Soft delete scope**: `users`, `buy_requests`, `orders` carry `deleted_at` for DPDP/consumer protection retention; most other tables rely on status enums and do not need physical deletion.
- **Polymorphic FKs**: `addresses.owner_type/owner_id`, `audit_logs.entity_type/entity_id`, `risk_flags.entity_type/entity_id` are intentionally polymorphic; referential integrity is enforced at application layer, not by DB foreign keys.
- **Money precision**: all monetary values stored as `BIGINT` paise (1 INR = 100 paise). No `DECIMAL` or `FLOAT` for money anywhere in this schema.
