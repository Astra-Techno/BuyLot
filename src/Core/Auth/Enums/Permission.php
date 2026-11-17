<?php

declare(strict_types=1);

namespace App\Core\Auth\Enums;

/**
 * All platform permissions, organized by resource domain.
 *
 * Naming convention: RESOURCE_ACTION or RESOURCE_SCOPE_ACTION
 * where SCOPE clarifies own/all/assigned/public access level.
 */
enum Permission: string
{
    // ── Catalog ──────────────────────────────────────────────────
    case PRODUCTS_READ_PUBLIC        = 'products.read_public';
    case PRODUCTS_CREATE             = 'products.create';
    case PRODUCTS_UPDATE             = 'products.update';
    case PRODUCTS_UPDATE_OWN         = 'products.update_own';
    case PRODUCTS_DELETE             = 'products.delete';
    case PRODUCTS_DELETE_OWN         = 'products.delete_own';
    case PRODUCTS_EXPORT             = 'products.export';
    case PRODUCTS_EXPORT_OWN         = 'products.export_own';
    case PRODUCTS_EXPORT_ASSIGNED    = 'products.export_assigned';
    case CATEGORIES_READ             = 'categories.read';
    case CATEGORIES_MANAGE           = 'categories.manage';
    case VARIANTS_READ_PUBLIC        = 'variants.read_public';
    case VARIANTS_CREATE_OWN         = 'variants.create_own';
    case VARIANTS_UPDATE_OWN         = 'variants.update_own';
    case VARIANTS_CREATE             = 'variants.create';
    case VARIANTS_UPDATE             = 'variants.update';

    // ── Buy Requests ─────────────────────────────────────────────
    case BUY_REQUESTS_CREATE         = 'buy_requests.create';
    case BUY_REQUESTS_READ_OWN       = 'buy_requests.read_own';
    case BUY_REQUESTS_UPDATE_OWN     = 'buy_requests.update_own';
    case BUY_REQUESTS_DELETE_OWN     = 'buy_requests.delete_own';
    case BUY_REQUESTS_READ_ALL       = 'buy_requests.read_all';
    case BUY_REQUESTS_EXPORT         = 'buy_requests.export';
    case BUY_REQUESTS_EXPORT_ASSIGNED = 'buy_requests.export_assigned';
    case BUY_REQUESTS_APPROVE_ORG    = 'buy_requests.approve_org';

    // ── Demand Groups ────────────────────────────────────────────
    case DEMAND_GROUPS_READ_PUBLIC   = 'demand_groups.read_public';
    case DEMAND_GROUPS_JOIN          = 'demand_groups.join';
    case DEMAND_GROUPS_RECONFIRM     = 'demand_groups.reconfirm';
    case DEMAND_GROUPS_MEMBERS_COUNT = 'demand_groups.members_count';
    case DEMAND_GROUPS_MEMBERS_IDS   = 'demand_groups.members_identities';
    case DEMAND_GROUPS_BUDGETS       = 'demand_groups.aggregate_budgets';
    case DEMAND_GROUPS_READ_ALL      = 'demand_groups.read_all';
    case DEMAND_GROUPS_MANAGE        = 'demand_groups.manage';
    case DEMAND_GROUPS_MANAGE_ASSIGNED = 'demand_groups.manage_assigned';
    case DEMAND_GROUPS_CLOSE         = 'demand_groups.close';
    case DEMAND_GROUPS_EXPORT        = 'demand_groups.export';
    case DEMAND_GROUPS_EXPORT_ASSIGNED = 'demand_groups.export_assigned';

    // ── RFQs / Bids ─────────────────────────────────────────────
    case RFQ_SPEC_READ_PUBLIC        = 'rfq.spec_read_public';
    case BIDS_CREATE_OWN             = 'bids.create_own';
    case BIDS_READ_OWN               = 'bids.read_own';
    case BIDS_UPDATE_OWN             = 'bids.update_own';
    case BIDS_DELETE_OWN             = 'bids.delete_own';
    case BIDS_READ_ALL_SEALED        = 'bids.read_all_sealed';
    case BIDS_READ_ALL_SEALED_ASSIGNED = 'bids.read_all_sealed_assigned';
    case BIDS_COMPARE                = 'bids.compare';
    case BIDS_COMPARE_ASSIGNED       = 'bids.compare_assigned';
    case OFFLINE_QUOTE_CREATE        = 'offline_quote.create';
    case OFFLINE_QUOTE_CREATE_ASSIGNED = 'offline_quote.create_assigned';
    case OFFLINE_QUOTE_READ          = 'offline_quote.read';
    case OFFLINE_QUOTE_READ_ASSIGNED = 'offline_quote.read_assigned';
    case BIDS_NORMALIZE              = 'bids.normalize';
    case BIDS_NORMALIZE_ASSIGNED     = 'bids.normalize_assigned';
    case RFQ_DEADLINE_EXTEND         = 'rfq.deadline_extend';
    case RFQ_BID_REOPEN              = 'rfq.bid_reopen';
    case BIDS_EXPORT                 = 'bids.export';
    case BIDS_EXPORT_ASSIGNED        = 'bids.export_assigned';
    case B2B_RFQ_CREATE              = 'b2b_rfq.create';
    case B2B_RFQ_READ_OWN            = 'b2b_rfq.read_own';

    // ── Procurement Cases ────────────────────────────────────────
    case CASES_CREATE                = 'cases.create';
    case CASES_READ_ASSIGNED         = 'cases.read_assigned';
    case CASES_READ_ALL              = 'cases.read_all';
    case CASES_UPDATE_ASSIGNED       = 'cases.update_assigned';
    case CASES_UPDATE_ALL            = 'cases.update_all';
    case CASES_ASSIGN                = 'cases.assign';
    case CASES_CLOSE                 = 'cases.close';
    case CASES_DELETE                = 'cases.delete';
    case CASES_EXPORT                = 'cases.export';
    case CASES_EXPORT_ASSIGNED       = 'cases.export_assigned';
    case SUPPLIER_LEADS_MANAGE_ASSIGNED = 'supplier_leads.manage_assigned';
    case SUPPLIER_LEADS_MANAGE       = 'supplier_leads.manage';
    case SUPPLIER_LEADS_READ         = 'supplier_leads.read';
    case SUPPLIER_LEADS_READ_ASSIGNED = 'supplier_leads.read_assigned';
    case CONTACT_LOG_CREATE_ASSIGNED = 'contact_log.create_assigned';
    case CONTACT_LOG_CREATE          = 'contact_log.create';
    case CONTACT_LOG_READ            = 'contact_log.read';
    case CONTACT_LOG_READ_ASSIGNED   = 'contact_log.read_assigned';
    case NEGOTIATION_HISTORY_READ    = 'negotiation_history.read';
    case NEGOTIATION_HISTORY_READ_ASSIGNED = 'negotiation_history.read_assigned';
    case APPROVAL_WORKFLOW_TRIGGER   = 'approval_workflow.trigger';
    case APPROVAL_WORKFLOW_TRIGGER_ASSIGNED = 'approval_workflow.trigger_assigned';
    case APPROVAL_WORKFLOW_APPROVE   = 'approval_workflow.approve';
    case CASES_PRIORITY_UPDATE       = 'cases.priority_update';

    // ── Offers ───────────────────────────────────────────────────
    case OFFERS_DRAFT_CREATE         = 'offers.draft_create';
    case OFFERS_DRAFT_CREATE_OWN     = 'offers.draft_create_own';
    case OFFERS_DRAFT_CREATE_ASSIGNED = 'offers.draft_create_assigned';
    case OFFERS_DRAFT_READ           = 'offers.draft_read';
    case OFFERS_DRAFT_READ_OWN       = 'offers.draft_read_own';
    case OFFERS_DRAFT_READ_ASSIGNED  = 'offers.draft_read_assigned';
    case OFFERS_DRAFT_UPDATE         = 'offers.draft_update';
    case OFFERS_DRAFT_UPDATE_OWN     = 'offers.draft_update_own';
    case OFFERS_DRAFT_UPDATE_ASSIGNED = 'offers.draft_update_assigned';
    case OFFERS_DRAFT_DELETE         = 'offers.draft_delete';
    case OFFERS_DRAFT_DELETE_OWN     = 'offers.draft_delete_own';
    case OFFERS_PUBLISHED_READ       = 'offers.published_read';
    case OFFERS_SUBMIT_APPROVAL      = 'offers.submit_approval';
    case OFFERS_SUBMIT_APPROVAL_ASSIGNED = 'offers.submit_approval_assigned';
    case OFFERS_APPROVE              = 'offers.approve';
    case OFFERS_PUBLISH              = 'offers.publish';
    case OFFERS_ACCEPT               = 'offers.accept';
    case OFFERS_EXTEND_DEADLINE      = 'offers.extend_deadline';
    case OFFERS_EXPORT               = 'offers.export';
    case OFFERS_EXPORT_OWN           = 'offers.export_own';
    case OFFERS_EXPORT_ASSIGNED      = 'offers.export_assigned';

    // ── Orders ───────────────────────────────────────────────────
    case ORDERS_CREATE               = 'orders.create';
    case ORDERS_READ_OWN             = 'orders.read_own';
    case ORDERS_READ_ALL             = 'orders.read_all';
    case ORDERS_READ_ASSIGNED        = 'orders.read_assigned';
    case ORDERS_UPDATE_STATUS        = 'orders.update_status';
    case ORDERS_UPDATE_STATUS_ASSIGNED = 'orders.update_status_assigned';
    case ORDERS_CANCEL_OWN           = 'orders.cancel_own';
    case ORDERS_CANCEL               = 'orders.cancel';
    case ORDERS_DELETE               = 'orders.delete';
    case ORDERS_EXPORT               = 'orders.export';
    case ORDERS_EXPORT_OWN           = 'orders.export_own';
    case ORDERS_EXPORT_ASSIGNED      = 'orders.export_assigned';
    case ORDERS_SELLER_VIEW          = 'orders.seller_view';
    case ORDERS_APPROVE_ORG          = 'orders.approve_org';

    // ── Payments ─────────────────────────────────────────────────
    case PAYMENTS_INITIATE           = 'payments.initiate';
    case PAYMENTS_READ_OWN           = 'payments.read_own';
    case PAYMENTS_READ_OWN_ASSIGNED  = 'payments.read_own_assigned';
    case PAYMENTS_READ_ALL           = 'payments.read_all';
    case PAYMENTS_EXPORT             = 'payments.export';
    case PAYMENTS_EXPORT_OWN         = 'payments.export_own';
    case REFUNDS_INITIATE            = 'refunds.initiate';
    case REFUNDS_INITIATE_OWN        = 'refunds.initiate_own';
    case REFUNDS_INITIATE_ASSIGNED   = 'refunds.initiate_assigned';
    case REFUNDS_APPROVE             = 'refunds.approve';
    case SETTLEMENTS_PREPARE         = 'settlements.prepare';
    case SETTLEMENTS_APPROVE         = 'settlements.approve';
    case RECONCILIATION_READ         = 'reconciliation.read';
    case SELLER_SETTLEMENT_READ_OWN  = 'seller_settlement.read_own';
    case SELLER_SETTLEMENT_READ_ALL  = 'seller_settlement.read_all';
    case CHARGEBACK_FLAG             = 'chargeback.flag';
    case CHARGEBACK_APPROVE          = 'chargeback.approve';
    case CHARGEBACK_FLAG_ASSIGNED    = 'chargeback.flag_assigned';
    case PAYMENTS_DELETE             = 'payments.delete';

    // ── Fulfillment ──────────────────────────────────────────────
    case SHIPMENTS_CREATE_OWN        = 'shipments.create_own';
    case SHIPMENTS_CREATE_ASSIGNED   = 'shipments.create_assigned';
    case SHIPMENTS_READ_OWN          = 'shipments.read_own';
    case SHIPMENTS_READ_ASSIGNED     = 'shipments.read_assigned';
    case SHIPMENTS_READ_ALL          = 'shipments.read_all';
    case SHIPMENTS_UPDATE_OWN        = 'shipments.update_own';
    case SHIPMENTS_UPDATE_ASSIGNED   = 'shipments.update_assigned';
    case SHIPMENTS_UPDATE_ALL        = 'shipments.update_all';
    case DELIVERY_ACK_CREATE         = 'delivery_ack.create';
    case DELIVERY_ACK_READ_OWN       = 'delivery_ack.read_own';
    case DELIVERY_ACK_READ_ASSIGNED  = 'delivery_ack.read_assigned';
    case DELIVERY_ACK_READ_ALL       = 'delivery_ack.read_all';
    case INSPECTION_ASSIGN           = 'inspection.assign';
    case INSPECTION_READ_ASSIGNED    = 'inspection.read_assigned';
    case INSPECTION_COMPLETE         = 'inspection.complete';
    case INSPECTION_REPORT_READ_OWN  = 'inspection_report.read_own';
    case INSPECTION_REPORT_READ_ASSIGNED = 'inspection_report.read_assigned';
    case INSPECTION_REPORT_READ_ALL  = 'inspection_report.read_all';
    case INSPECTION_APPROVE          = 'inspection.approve';
    case FULFILLMENT_EXPORT          = 'fulfillment.export';
    case FULFILLMENT_EXPORT_OWN      = 'fulfillment.export_own';
    case FULFILLMENT_EXPORT_ASSIGNED = 'fulfillment.export_assigned';

    // ── Disputes ─────────────────────────────────────────────────
    case DISPUTES_OPEN               = 'disputes.open';
    case DISPUTES_READ_OWN           = 'disputes.read_own';
    case DISPUTES_READ_ASSIGNED      = 'disputes.read_assigned';
    case DISPUTES_READ_ALL           = 'disputes.read_all';
    case DISPUTES_ASSIGN             = 'disputes.assign';
    case DISPUTES_UPDATE_OWN         = 'disputes.update_own';
    case DISPUTES_UPDATE_ASSIGNED    = 'disputes.update_assigned';
    case DISPUTES_UPDATE_ALL         = 'disputes.update_all';
    case DISPUTES_RESOLVE            = 'disputes.resolve';
    case DISPUTES_RESOLVE_ASSIGNED   = 'disputes.resolve_assigned';
    case FINANCIAL_ADJUSTMENT_PROPOSE = 'financial_adjustment.propose';
    case FINANCIAL_ADJUSTMENT_PROPOSE_ASSIGNED = 'financial_adjustment.propose_assigned';
    case FINANCIAL_ADJUSTMENT_APPROVE = 'financial_adjustment.approve';
    case DISPUTES_EXPORT             = 'disputes.export';
    case DISPUTES_EXPORT_ASSIGNED    = 'disputes.export_assigned';
    case DISPUTES_DELETE             = 'disputes.delete';

    // ── Seller Profiles ──────────────────────────────────────────
    case SELLER_PROFILE_READ_PUBLIC  = 'seller_profile.read_public';
    case SELLER_PROFILE_CREATE_OWN   = 'seller_profile.create_own';
    case SELLER_PROFILE_UPDATE_OWN   = 'seller_profile.update_own';
    case SELLER_PROFILE_DELETE_OWN   = 'seller_profile.delete_own';
    case SELLER_PROFILE_UPDATE       = 'seller_profile.update';
    case SELLER_PROFILE_DELETE       = 'seller_profile.delete';
    case SELLER_PROFILES_READ_ALL    = 'seller_profiles.read_all';
    case SELLER_KYC_READ_OWN         = 'seller_kyc.read_own';
    case SELLER_KYC_READ             = 'seller_kyc.read';
    case SELLER_KYC_VERIFY           = 'seller_kyc.verify';
    case SELLER_KYC_REJECT           = 'seller_kyc.reject';
    case SELLER_BANK_READ_OWN        = 'seller_bank.read_own';
    case SELLER_BANK_UPDATE_OWN      = 'seller_bank.update_own';
    case SELLER_BANK_READ            = 'seller_bank.read';
    case SELLER_BANK_UPDATE          = 'seller_bank.update';
    case SELLER_RISK_FLAG            = 'seller_risk.flag';
    case SELLER_PERFORMANCE_READ_OWN = 'seller_performance.read_own';
    case SELLER_PERFORMANCE_READ_ASSIGNED = 'seller_performance.read_assigned';
    case SELLER_PERFORMANCE_READ     = 'seller_performance.read';
    case SELLER_PROFILES_EXPORT      = 'seller_profiles.export';

    // ── Admin ────────────────────────────────────────────────────
    case PLATFORM_SETTINGS_READ      = 'platform_settings.read';
    case PLATFORM_SETTINGS_UPDATE    = 'platform_settings.update';
    case AUDIT_READ_OWN              = 'audit.read_own';
    case AUDIT_READ_ALL              = 'audit.read_all';
    case AUDIT_EXPORT                = 'audit.export';
    case USERS_READ_OWN              = 'users.read_own';
    case USERS_READ_ALL              = 'users.read_all';
    case USERS_CREATE                = 'users.create';
    case USERS_UPDATE_OWN            = 'users.update_own';
    case USERS_UPDATE_ALL            = 'users.update_all';
    case USERS_SUSPEND               = 'users.suspend';
    case ROLES_READ                  = 'roles.read';
    case ROLES_GRANT                 = 'roles.grant';
    case SUPER_ADMIN_GRANT           = 'super_admin.grant';
    case FEATURE_FLAGS_READ          = 'feature_flags.read';
    case FEATURE_FLAGS_UPDATE        = 'feature_flags.update';
    case CATEGORY_POLICY_UPDATE      = 'category_policy.update';
    case FEE_COMMISSION_UPDATE       = 'fee_commission.update';

    /** The resource domain this permission belongs to. */
    public function resource(): string
    {
        return explode('.', $this->value)[0];
    }

    /** The action portion of this permission. */
    public function action(): string
    {
        return explode('.', $this->value, 2)[1];
    }

    /** Whether this permission is scoped to "own" records only. */
    public function isOwnScoped(): bool
    {
        return str_contains($this->value, '_own');
    }

    /** Whether this permission is scoped to "assigned" records only. */
    public function isAssignedScoped(): bool
    {
        return str_contains($this->value, '_assigned');
    }
}
