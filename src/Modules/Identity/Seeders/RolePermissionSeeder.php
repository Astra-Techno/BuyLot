<?php

declare(strict_types=1);

namespace App\Modules\Identity\Seeders;

use App\Core\Auth\Enums\Permission;
use App\Core\Auth\Enums\Role;
use App\Core\Database\Connection;

/**
 * Seeds all roles, permissions, and the role-permission mappings.
 *
 * This seeder is the authoritative source that materializes the
 * permissions-matrix.md specification into the database.
 *
 * Run via:  php artisan db:seed --class=RolePermissionSeeder
 * Or call:  (new RolePermissionSeeder())->run();
 *
 * Idempotent — safe to re-run. Uses INSERT IGNORE to avoid duplicates.
 */
final class RolePermissionSeeder
{
    private Connection $db;

    public function __construct(?Connection $db = null)
    {
        $this->db = $db ?? Connection::getInstance();
    }

    public function run(): void
    {
        $this->db->beginTransaction();

        try {
            $this->seedRoles();
            $this->seedPermissions();
            $this->seedRolePermissions();
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Step 1: Seed roles
    // ══════════════════════════════════════════════════════════════

    private function seedRoles(): void
    {
        foreach (Role::cases() as $role) {
            $this->db->execute(
                'INSERT IGNORE INTO roles (slug, name, requires_mfa, is_staff, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())',
                [
                    $role->value,
                    $role->label(),
                    $role->requiresMfa() ? 1 : 0,
                    $role->isStaff() ? 1 : 0,
                ],
            );
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Step 2: Seed permissions
    // ══════════════════════════════════════════════════════════════

    private function seedPermissions(): void
    {
        foreach (Permission::cases() as $permission) {
            $this->db->execute(
                'INSERT IGNORE INTO permissions (slug, resource, action, created_at)
                 VALUES (?, ?, ?, NOW())',
                [
                    $permission->value,
                    $permission->resource(),
                    $permission->action(),
                ],
            );
        }
    }

    // ══════════════════════════════════════════════════════════════
    // Step 3: Seed role <-> permission mappings
    // ══════════════════════════════════════════════════════════════

    private function seedRolePermissions(): void
    {
        $map = $this->buildRolePermissionMap();

        foreach ($map as $roleValue => $permissions) {
            foreach ($permissions as $permission) {
                $this->db->execute(
                    'INSERT IGNORE INTO role_permissions (role_id, permission_id)
                     SELECT r.id, p.id FROM roles r, permissions p
                     WHERE r.slug = ? AND p.slug = ?',
                    [$roleValue, $permission->value],
                );
            }
        }
    }

    /**
     * Build the complete role -> permissions mapping based on permissions-matrix.md.
     *
     * @return array<string, Permission[]>
     */
    private function buildRolePermissionMap(): array
    {
        return [
            // ── GUEST ────────────────────────────────────────────
            Role::GUEST->value => [
                Permission::PRODUCTS_READ_PUBLIC,
                Permission::CATEGORIES_READ,
                Permission::VARIANTS_READ_PUBLIC,
                Permission::DEMAND_GROUPS_READ_PUBLIC,
                Permission::DEMAND_GROUPS_MEMBERS_COUNT,
                Permission::RFQ_SPEC_READ_PUBLIC,
                Permission::OFFERS_PUBLISHED_READ,
                Permission::SELLER_PROFILE_READ_PUBLIC,
            ],

            // ── BUYER ────────────────────────────────────────────
            Role::BUYER->value => [
                // Catalog
                Permission::PRODUCTS_READ_PUBLIC,
                Permission::CATEGORIES_READ,
                Permission::VARIANTS_READ_PUBLIC,
                // Buy Requests
                Permission::BUY_REQUESTS_CREATE,
                Permission::BUY_REQUESTS_READ_OWN,
                Permission::BUY_REQUESTS_UPDATE_OWN,
                Permission::BUY_REQUESTS_DELETE_OWN,
                // Demand Groups
                Permission::DEMAND_GROUPS_READ_PUBLIC,
                Permission::DEMAND_GROUPS_JOIN,
                Permission::DEMAND_GROUPS_RECONFIRM,
                Permission::DEMAND_GROUPS_MEMBERS_COUNT,
                // RFQ
                Permission::RFQ_SPEC_READ_PUBLIC,
                // Offers
                Permission::OFFERS_PUBLISHED_READ,
                Permission::OFFERS_ACCEPT,
                // Orders
                Permission::ORDERS_CREATE,
                Permission::ORDERS_READ_OWN,
                Permission::ORDERS_CANCEL_OWN,
                Permission::ORDERS_EXPORT_OWN,
                // Payments
                Permission::PAYMENTS_INITIATE,
                Permission::PAYMENTS_READ_OWN,
                Permission::PAYMENTS_EXPORT_OWN,
                Permission::REFUNDS_INITIATE_OWN,
                // Fulfillment
                Permission::SHIPMENTS_READ_OWN,
                Permission::DELIVERY_ACK_CREATE,
                Permission::DELIVERY_ACK_READ_OWN,
                Permission::INSPECTION_REPORT_READ_OWN,
                // Disputes
                Permission::DISPUTES_OPEN,
                Permission::DISPUTES_READ_OWN,
                Permission::DISPUTES_UPDATE_OWN,
                // Seller Profiles
                Permission::SELLER_PROFILE_READ_PUBLIC,
                // Admin (own)
                Permission::AUDIT_READ_OWN,
                Permission::USERS_READ_OWN,
                Permission::USERS_UPDATE_OWN,
            ],

            // ── BUSINESS BUYER ───────────────────────────────────
            Role::BUSINESS_BUYER->value => [
                // Catalog
                Permission::PRODUCTS_READ_PUBLIC,
                Permission::CATEGORIES_READ,
                Permission::VARIANTS_READ_PUBLIC,
                // Buy Requests
                Permission::BUY_REQUESTS_CREATE,
                Permission::BUY_REQUESTS_READ_OWN,
                Permission::BUY_REQUESTS_UPDATE_OWN,
                Permission::BUY_REQUESTS_DELETE_OWN,
                Permission::BUY_REQUESTS_APPROVE_ORG,
                // Demand Groups
                Permission::DEMAND_GROUPS_READ_PUBLIC,
                Permission::DEMAND_GROUPS_JOIN,
                Permission::DEMAND_GROUPS_RECONFIRM,
                Permission::DEMAND_GROUPS_MEMBERS_COUNT,
                // RFQ
                Permission::RFQ_SPEC_READ_PUBLIC,
                Permission::B2B_RFQ_CREATE,
                Permission::B2B_RFQ_READ_OWN,
                // Offers
                Permission::OFFERS_PUBLISHED_READ,
                Permission::OFFERS_ACCEPT,
                // Orders
                Permission::ORDERS_CREATE,
                Permission::ORDERS_READ_OWN,
                Permission::ORDERS_CANCEL_OWN,
                Permission::ORDERS_EXPORT_OWN,
                Permission::ORDERS_APPROVE_ORG,
                // Payments
                Permission::PAYMENTS_INITIATE,
                Permission::PAYMENTS_READ_OWN,
                Permission::PAYMENTS_EXPORT_OWN,
                Permission::REFUNDS_INITIATE_OWN,
                // Fulfillment
                Permission::SHIPMENTS_READ_OWN,
                Permission::DELIVERY_ACK_CREATE,
                Permission::DELIVERY_ACK_READ_OWN,
                Permission::INSPECTION_REPORT_READ_OWN,
                // Disputes
                Permission::DISPUTES_OPEN,
                Permission::DISPUTES_READ_OWN,
                Permission::DISPUTES_UPDATE_OWN,
                // Seller Profiles
                Permission::SELLER_PROFILE_READ_PUBLIC,
                // Admin (own)
                Permission::AUDIT_READ_OWN,
                Permission::USERS_READ_OWN,
                Permission::USERS_UPDATE_OWN,
            ],

            // ── SELLER ───────────────────────────────────────────
            Role::SELLER->value => [
                // Catalog
                Permission::PRODUCTS_READ_PUBLIC,
                Permission::PRODUCTS_CREATE,
                Permission::PRODUCTS_UPDATE_OWN,
                Permission::PRODUCTS_DELETE_OWN,
                Permission::PRODUCTS_EXPORT_OWN,
                Permission::CATEGORIES_READ,
                Permission::VARIANTS_READ_PUBLIC,
                Permission::VARIANTS_CREATE_OWN,
                Permission::VARIANTS_UPDATE_OWN,
                // RFQ — own bids only
                Permission::RFQ_SPEC_READ_PUBLIC,
                Permission::BIDS_CREATE_OWN,
                Permission::BIDS_READ_OWN,
                Permission::BIDS_UPDATE_OWN,
                Permission::BIDS_DELETE_OWN,
                // Offers
                Permission::OFFERS_DRAFT_CREATE_OWN,
                Permission::OFFERS_DRAFT_READ_OWN,
                Permission::OFFERS_DRAFT_UPDATE_OWN,
                Permission::OFFERS_DRAFT_DELETE_OWN,
                Permission::OFFERS_PUBLISHED_READ,
                Permission::OFFERS_EXPORT_OWN,
                // Orders — own confirmed orders
                Permission::ORDERS_READ_OWN,
                Permission::ORDERS_SELLER_VIEW,
                Permission::ORDERS_EXPORT_OWN,
                // Payments
                Permission::PAYMENTS_EXPORT_OWN,
                Permission::SELLER_SETTLEMENT_READ_OWN,
                // Fulfillment
                Permission::SHIPMENTS_CREATE_OWN,
                Permission::SHIPMENTS_READ_OWN,
                Permission::SHIPMENTS_UPDATE_OWN,
                Permission::DELIVERY_ACK_READ_OWN,
                Permission::INSPECTION_REPORT_READ_OWN,
                Permission::FULFILLMENT_EXPORT_OWN,
                // Disputes
                Permission::DISPUTES_OPEN,
                Permission::DISPUTES_READ_OWN,
                Permission::DISPUTES_UPDATE_OWN,
                // Seller Profile
                Permission::SELLER_PROFILE_READ_PUBLIC,
                Permission::SELLER_PROFILE_CREATE_OWN,
                Permission::SELLER_PROFILE_UPDATE_OWN,
                Permission::SELLER_PROFILE_DELETE_OWN,
                Permission::SELLER_KYC_READ_OWN,
                Permission::SELLER_BANK_READ_OWN,
                Permission::SELLER_BANK_UPDATE_OWN,
                Permission::SELLER_PERFORMANCE_READ_OWN,
                // Admin (own)
                Permission::AUDIT_READ_OWN,
                Permission::USERS_READ_OWN,
                Permission::USERS_UPDATE_OWN,
            ],

            // ── PROCUREMENT AGENT ────────────────────────────────
            Role::PROCUREMENT_AGENT->value => [
                // Catalog
                Permission::PRODUCTS_READ_PUBLIC,
                Permission::PRODUCTS_EXPORT_ASSIGNED,
                Permission::CATEGORIES_READ,
                Permission::VARIANTS_READ_PUBLIC,
                // Buy Requests
                Permission::BUY_REQUESTS_READ_ALL,
                Permission::BUY_REQUESTS_EXPORT_ASSIGNED,
                // Demand Groups
                Permission::DEMAND_GROUPS_READ_PUBLIC,
                Permission::DEMAND_GROUPS_MEMBERS_COUNT,
                Permission::DEMAND_GROUPS_MEMBERS_IDS,     // assigned
                Permission::DEMAND_GROUPS_BUDGETS,          // assigned
                Permission::DEMAND_GROUPS_READ_ALL,
                Permission::DEMAND_GROUPS_MANAGE_ASSIGNED,
                Permission::DEMAND_GROUPS_EXPORT_ASSIGNED,
                // RFQ — sealed bids for assigned cases
                Permission::RFQ_SPEC_READ_PUBLIC,
                Permission::BIDS_READ_ALL_SEALED_ASSIGNED,
                Permission::BIDS_COMPARE_ASSIGNED,
                Permission::OFFLINE_QUOTE_CREATE_ASSIGNED,
                Permission::OFFLINE_QUOTE_READ_ASSIGNED,
                Permission::BIDS_NORMALIZE_ASSIGNED,
                Permission::BIDS_EXPORT_ASSIGNED,
                // Procurement Cases
                Permission::CASES_CREATE,
                Permission::CASES_READ_ASSIGNED,
                Permission::CASES_UPDATE_ASSIGNED,
                Permission::CASES_EXPORT_ASSIGNED,
                Permission::SUPPLIER_LEADS_MANAGE_ASSIGNED,
                Permission::SUPPLIER_LEADS_READ_ASSIGNED,
                Permission::CONTACT_LOG_CREATE_ASSIGNED,
                Permission::CONTACT_LOG_READ_ASSIGNED,
                Permission::NEGOTIATION_HISTORY_READ_ASSIGNED,
                Permission::APPROVAL_WORKFLOW_TRIGGER_ASSIGNED,
                // Offers
                Permission::OFFERS_DRAFT_CREATE_ASSIGNED,
                Permission::OFFERS_DRAFT_READ_ASSIGNED,
                Permission::OFFERS_DRAFT_UPDATE_ASSIGNED,
                Permission::OFFERS_PUBLISHED_READ,
                Permission::OFFERS_SUBMIT_APPROVAL_ASSIGNED,
                Permission::OFFERS_EXPORT_ASSIGNED,
                // Fulfillment
                Permission::INSPECTION_REPORT_READ_ASSIGNED,
                // Seller Profiles
                Permission::SELLER_PROFILE_READ_PUBLIC,
                Permission::SELLER_PROFILES_READ_ALL,
                Permission::SELLER_PERFORMANCE_READ_ASSIGNED,
                // Admin (own)
                Permission::AUDIT_READ_OWN,
                Permission::USERS_READ_OWN,
                Permission::USERS_UPDATE_OWN,
            ],

            // ── PROCUREMENT LEAD ─────────────────────────────────
            Role::PROCUREMENT_LEAD->value => [
                // Catalog
                Permission::PRODUCTS_READ_PUBLIC,
                Permission::PRODUCTS_UPDATE,  // assigned case products
                Permission::PRODUCTS_EXPORT,
                Permission::CATEGORIES_READ,
                Permission::VARIANTS_READ_PUBLIC,
                // Buy Requests
                Permission::BUY_REQUESTS_READ_ALL,
                Permission::BUY_REQUESTS_EXPORT,
                // Demand Groups
                Permission::DEMAND_GROUPS_READ_PUBLIC,
                Permission::DEMAND_GROUPS_MEMBERS_COUNT,
                Permission::DEMAND_GROUPS_MEMBERS_IDS,
                Permission::DEMAND_GROUPS_BUDGETS,
                Permission::DEMAND_GROUPS_READ_ALL,
                Permission::DEMAND_GROUPS_MANAGE,
                Permission::DEMAND_GROUPS_CLOSE,
                Permission::DEMAND_GROUPS_EXPORT,
                // RFQ — full sealed bid access
                Permission::RFQ_SPEC_READ_PUBLIC,
                Permission::BIDS_READ_ALL_SEALED,
                Permission::BIDS_COMPARE,
                Permission::OFFLINE_QUOTE_CREATE,
                Permission::OFFLINE_QUOTE_READ,
                Permission::BIDS_NORMALIZE,
                Permission::RFQ_DEADLINE_EXTEND,
                Permission::RFQ_BID_REOPEN,
                Permission::BIDS_EXPORT,
                // Procurement Cases
                Permission::CASES_CREATE,
                Permission::CASES_READ_ALL,
                Permission::CASES_UPDATE_ALL,
                Permission::CASES_ASSIGN,
                Permission::CASES_CLOSE,
                Permission::CASES_EXPORT,
                Permission::SUPPLIER_LEADS_MANAGE,
                Permission::SUPPLIER_LEADS_READ,
                Permission::CONTACT_LOG_CREATE,
                Permission::CONTACT_LOG_READ,
                Permission::NEGOTIATION_HISTORY_READ,
                Permission::APPROVAL_WORKFLOW_TRIGGER,
                Permission::APPROVAL_WORKFLOW_APPROVE,
                Permission::CASES_PRIORITY_UPDATE,
                // Offers
                Permission::OFFERS_DRAFT_CREATE,
                Permission::OFFERS_DRAFT_READ,
                Permission::OFFERS_DRAFT_UPDATE,
                Permission::OFFERS_DRAFT_DELETE,
                Permission::OFFERS_PUBLISHED_READ,
                Permission::OFFERS_SUBMIT_APPROVAL,
                Permission::OFFERS_APPROVE,
                Permission::OFFERS_PUBLISH,
                Permission::OFFERS_EXTEND_DEADLINE,
                Permission::OFFERS_EXPORT,
                // Orders
                Permission::ORDERS_READ_ALL,
                Permission::ORDERS_UPDATE_STATUS,
                Permission::ORDERS_CANCEL,
                Permission::ORDERS_EXPORT,
                // Fulfillment
                Permission::SHIPMENTS_READ_ALL,
                Permission::DELIVERY_ACK_READ_ALL,
                Permission::INSPECTION_ASSIGN,
                Permission::INSPECTION_REPORT_READ_ALL,
                Permission::INSPECTION_APPROVE,
                Permission::FULFILLMENT_EXPORT,
                // Seller Profiles
                Permission::SELLER_PROFILE_READ_PUBLIC,
                Permission::SELLER_PROFILES_READ_ALL,
                Permission::SELLER_PERFORMANCE_READ,
                // Admin (own)
                Permission::AUDIT_READ_OWN,
                Permission::USERS_READ_OWN,
                Permission::USERS_UPDATE_OWN,
            ],

            // ── QUALITY INSPECTOR ────────────────────────────────
            Role::QUALITY_INSPECTOR->value => [
                // Catalog
                Permission::PRODUCTS_READ_PUBLIC,
                Permission::CATEGORIES_READ,
                Permission::VARIANTS_READ_PUBLIC,
                // Demand Groups
                Permission::DEMAND_GROUPS_READ_PUBLIC,
                Permission::DEMAND_GROUPS_MEMBERS_COUNT,
                // Orders (assigned inspections)
                Permission::ORDERS_READ_ASSIGNED,
                // Fulfillment
                Permission::INSPECTION_READ_ASSIGNED,
                Permission::INSPECTION_COMPLETE,
                Permission::INSPECTION_REPORT_READ_ASSIGNED,
                Permission::DELIVERY_ACK_READ_ASSIGNED,
                // Seller Profiles
                Permission::SELLER_PROFILE_READ_PUBLIC,
                // Admin (own)
                Permission::AUDIT_READ_OWN,
                Permission::USERS_READ_OWN,
                Permission::USERS_UPDATE_OWN,
            ],

            // ── OPERATIONS AGENT ─────────────────────────────────
            Role::OPERATIONS_AGENT->value => [
                // Catalog
                Permission::PRODUCTS_READ_PUBLIC,
                Permission::CATEGORIES_READ,
                Permission::VARIANTS_READ_PUBLIC,
                // Demand Groups
                Permission::DEMAND_GROUPS_READ_PUBLIC,
                Permission::DEMAND_GROUPS_MEMBERS_COUNT,
                // Offers
                Permission::OFFERS_PUBLISHED_READ,
                // Orders
                Permission::ORDERS_READ_ASSIGNED,
                Permission::ORDERS_UPDATE_STATUS_ASSIGNED,
                // Fulfillment
                Permission::SHIPMENTS_CREATE_ASSIGNED,
                Permission::SHIPMENTS_READ_ASSIGNED,
                Permission::SHIPMENTS_READ_ALL,
                Permission::SHIPMENTS_UPDATE_ASSIGNED,
                Permission::DELIVERY_ACK_READ_ALL,
                Permission::INSPECTION_REPORT_READ_ALL,
                Permission::FULFILLMENT_EXPORT,
                // Seller Profiles
                Permission::SELLER_PROFILE_READ_PUBLIC,
                // Admin (own)
                Permission::AUDIT_READ_OWN,
                Permission::USERS_READ_OWN,
                Permission::USERS_UPDATE_OWN,
            ],

            // ── FINANCE OPERATOR ─────────────────────────────────
            Role::FINANCE_OPERATOR->value => [
                // Catalog
                Permission::PRODUCTS_READ_PUBLIC,
                Permission::CATEGORIES_READ,
                Permission::VARIANTS_READ_PUBLIC,
                // Demand Groups
                Permission::DEMAND_GROUPS_READ_PUBLIC,
                Permission::DEMAND_GROUPS_MEMBERS_COUNT,
                // Offers
                Permission::OFFERS_PUBLISHED_READ,
                // Orders
                Permission::ORDERS_READ_ALL,
                Permission::ORDERS_EXPORT,
                // Payments — full read, prepare settlements, initiate refunds
                Permission::PAYMENTS_READ_ALL,
                Permission::PAYMENTS_EXPORT,
                Permission::REFUNDS_INITIATE,
                Permission::SETTLEMENTS_PREPARE,
                Permission::RECONCILIATION_READ,
                Permission::SELLER_SETTLEMENT_READ_ALL,
                Permission::CHARGEBACK_FLAG,
                // Disputes
                Permission::FINANCIAL_ADJUSTMENT_PROPOSE,
                // Seller Profiles
                Permission::SELLER_PROFILE_READ_PUBLIC,
                Permission::SELLER_PROFILES_READ_ALL,
                // Admin (own)
                Permission::AUDIT_READ_OWN,
                Permission::USERS_READ_OWN,
                Permission::USERS_UPDATE_OWN,
            ],

            // ── FINANCE APPROVER ─────────────────────────────────
            Role::FINANCE_APPROVER->value => [
                // Catalog
                Permission::PRODUCTS_READ_PUBLIC,
                Permission::CATEGORIES_READ,
                Permission::VARIANTS_READ_PUBLIC,
                // Demand Groups
                Permission::DEMAND_GROUPS_READ_PUBLIC,
                Permission::DEMAND_GROUPS_MEMBERS_COUNT,
                // Offers
                Permission::OFFERS_PUBLISHED_READ,
                // Orders
                Permission::ORDERS_READ_ALL,
                Permission::ORDERS_EXPORT,
                // Payments — approve refunds, settlements, chargebacks
                Permission::PAYMENTS_READ_ALL,
                Permission::PAYMENTS_EXPORT,
                Permission::REFUNDS_APPROVE,
                Permission::SETTLEMENTS_APPROVE,
                Permission::RECONCILIATION_READ,
                Permission::SELLER_SETTLEMENT_READ_ALL,
                Permission::CHARGEBACK_APPROVE,
                // Disputes
                Permission::FINANCIAL_ADJUSTMENT_APPROVE,
                // Seller Profiles
                Permission::SELLER_PROFILE_READ_PUBLIC,
                Permission::SELLER_PROFILES_READ_ALL,
                // Admin (own)
                Permission::AUDIT_READ_OWN,
                Permission::USERS_READ_OWN,
                Permission::USERS_UPDATE_OWN,
            ],

            // ── SUPPORT / DISPUTES ───────────────────────────────
            Role::SUPPORT_DISPUTES->value => [
                // Catalog
                Permission::PRODUCTS_READ_PUBLIC,
                Permission::CATEGORIES_READ,
                Permission::VARIANTS_READ_PUBLIC,
                // Buy Requests (assigned dispute linkage)
                Permission::BUY_REQUESTS_READ_OWN,  // own-scoped via dispute linkage
                // Demand Groups
                Permission::DEMAND_GROUPS_READ_PUBLIC,
                Permission::DEMAND_GROUPS_MEMBERS_COUNT,
                Permission::DEMAND_GROUPS_READ_ALL,  // assigned
                // Offers
                Permission::OFFERS_PUBLISHED_READ,
                // Orders (assigned dispute linkage)
                Permission::ORDERS_READ_ASSIGNED,
                Permission::ORDERS_EXPORT_ASSIGNED,
                // Payments (assigned dispute linkage)
                Permission::PAYMENTS_READ_OWN_ASSIGNED,
                // Fulfillment
                Permission::SHIPMENTS_READ_ASSIGNED,
                Permission::DELIVERY_ACK_READ_ASSIGNED,
                Permission::INSPECTION_REPORT_READ_ASSIGNED,
                // Disputes — full for assigned
                Permission::DISPUTES_READ_ASSIGNED,
                Permission::DISPUTES_READ_ALL,
                Permission::DISPUTES_ASSIGN,
                Permission::DISPUTES_UPDATE_ASSIGNED,
                Permission::DISPUTES_RESOLVE_ASSIGNED,
                Permission::FINANCIAL_ADJUSTMENT_PROPOSE_ASSIGNED,
                Permission::DISPUTES_EXPORT_ASSIGNED,
                Permission::REFUNDS_INITIATE_ASSIGNED,
                Permission::CHARGEBACK_FLAG_ASSIGNED,
                // Seller Profiles
                Permission::SELLER_PROFILE_READ_PUBLIC,
                Permission::SELLER_PROFILES_READ_ALL,
                // Admin (own)
                Permission::AUDIT_READ_OWN,
                Permission::USERS_READ_OWN,
                Permission::USERS_UPDATE_OWN,
            ],

            // ── COMPLIANCE / ADMIN ───────────────────────────────
            Role::COMPLIANCE_ADMIN->value => [
                // Catalog — full control
                Permission::PRODUCTS_READ_PUBLIC,
                Permission::PRODUCTS_UPDATE,
                Permission::PRODUCTS_DELETE,
                Permission::PRODUCTS_EXPORT,
                Permission::CATEGORIES_READ,
                Permission::CATEGORIES_MANAGE,
                Permission::VARIANTS_READ_PUBLIC,
                // Buy Requests
                Permission::BUY_REQUESTS_READ_OWN,
                Permission::BUY_REQUESTS_READ_ALL,
                Permission::BUY_REQUESTS_DELETE_OWN,  // can cancel
                Permission::BUY_REQUESTS_EXPORT,
                // Demand Groups
                Permission::DEMAND_GROUPS_READ_PUBLIC,
                Permission::DEMAND_GROUPS_MEMBERS_COUNT,
                Permission::DEMAND_GROUPS_MEMBERS_IDS,
                Permission::DEMAND_GROUPS_BUDGETS,
                Permission::DEMAND_GROUPS_READ_ALL,
                Permission::DEMAND_GROUPS_MANAGE,
                Permission::DEMAND_GROUPS_CLOSE,
                Permission::DEMAND_GROUPS_EXPORT,
                // RFQ — full sealed bid access
                Permission::RFQ_SPEC_READ_PUBLIC,
                Permission::BIDS_READ_ALL_SEALED,
                Permission::BIDS_COMPARE,
                Permission::OFFLINE_QUOTE_READ,
                Permission::BIDS_NORMALIZE,
                Permission::RFQ_DEADLINE_EXTEND,
                Permission::RFQ_BID_REOPEN,
                Permission::BIDS_EXPORT,
                // Procurement Cases
                Permission::CASES_READ_ALL,
                Permission::CASES_UPDATE_ALL,
                Permission::CASES_ASSIGN,
                Permission::CASES_CLOSE,
                Permission::CASES_EXPORT,
                Permission::SUPPLIER_LEADS_READ,
                Permission::CONTACT_LOG_READ,
                Permission::NEGOTIATION_HISTORY_READ,
                Permission::APPROVAL_WORKFLOW_APPROVE,
                Permission::CASES_PRIORITY_UPDATE,
                // Offers
                Permission::OFFERS_DRAFT_READ,
                Permission::OFFERS_DRAFT_DELETE,
                Permission::OFFERS_PUBLISHED_READ,
                Permission::OFFERS_APPROVE,
                Permission::OFFERS_PUBLISH,
                Permission::OFFERS_EXTEND_DEADLINE,
                Permission::OFFERS_EXPORT,
                // Orders
                Permission::ORDERS_READ_ALL,
                Permission::ORDERS_UPDATE_STATUS,
                Permission::ORDERS_CANCEL,
                Permission::ORDERS_EXPORT,
                // Payments
                Permission::PAYMENTS_READ_ALL,
                Permission::PAYMENTS_EXPORT,
                Permission::REFUNDS_APPROVE,
                Permission::SETTLEMENTS_APPROVE,
                Permission::RECONCILIATION_READ,
                Permission::SELLER_SETTLEMENT_READ_ALL,
                Permission::CHARGEBACK_FLAG,
                Permission::CHARGEBACK_APPROVE,
                // Fulfillment
                Permission::SHIPMENTS_READ_ALL,
                Permission::DELIVERY_ACK_READ_ALL,
                Permission::INSPECTION_ASSIGN,
                Permission::INSPECTION_READ_ASSIGNED,
                Permission::INSPECTION_COMPLETE,
                Permission::INSPECTION_REPORT_READ_ALL,
                Permission::INSPECTION_APPROVE,
                Permission::FULFILLMENT_EXPORT,
                // Disputes
                Permission::DISPUTES_READ_ALL,
                Permission::DISPUTES_ASSIGN,
                Permission::DISPUTES_UPDATE_ALL,
                Permission::DISPUTES_RESOLVE,
                Permission::FINANCIAL_ADJUSTMENT_PROPOSE,
                Permission::FINANCIAL_ADJUSTMENT_APPROVE,
                Permission::DISPUTES_EXPORT,
                // Seller Profiles — full control
                Permission::SELLER_PROFILE_READ_PUBLIC,
                Permission::SELLER_PROFILE_UPDATE,
                Permission::SELLER_PROFILE_DELETE,
                Permission::SELLER_PROFILES_READ_ALL,
                Permission::SELLER_KYC_READ,
                Permission::SELLER_KYC_VERIFY,
                Permission::SELLER_KYC_REJECT,
                Permission::SELLER_BANK_READ,
                Permission::SELLER_BANK_UPDATE,
                Permission::SELLER_RISK_FLAG,
                Permission::SELLER_PERFORMANCE_READ,
                Permission::SELLER_PROFILES_EXPORT,
                // Admin
                Permission::PLATFORM_SETTINGS_READ,
                Permission::AUDIT_READ_OWN,
                Permission::AUDIT_READ_ALL,
                Permission::AUDIT_EXPORT,
                Permission::USERS_READ_OWN,
                Permission::USERS_READ_ALL,
                Permission::USERS_CREATE,
                Permission::USERS_UPDATE_OWN,
                Permission::USERS_UPDATE_ALL,
                Permission::USERS_SUSPEND,
                Permission::ROLES_READ,
                Permission::ROLES_GRANT,
                Permission::FEATURE_FLAGS_READ,
                Permission::CATEGORY_POLICY_UPDATE,
            ],

            // ── SUPER ADMIN ──────────────────────────────────────
            // Super Admin has ALL permissions via hasRole check in the
            // Authorizable trait (bypasses RBAC). We still seed every
            // permission for audit completeness and explicit documentation.
            Role::SUPER_ADMIN->value => array_values(Permission::cases()),
        ];
    }
}
