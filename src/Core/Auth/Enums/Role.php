<?php

declare(strict_types=1);

namespace App\Core\Auth\Enums;

enum Role: string
{
    case GUEST = 'guest';
    case BUYER = 'buyer';
    case BUSINESS_BUYER = 'business_buyer';
    case SELLER = 'seller';
    case PROCUREMENT_AGENT = 'procurement_agent';
    case PROCUREMENT_LEAD = 'procurement_lead';
    case QUALITY_INSPECTOR = 'quality_inspector';
    case OPERATIONS_AGENT = 'operations_agent';
    case FINANCE_OPERATOR = 'finance_operator';
    case FINANCE_APPROVER = 'finance_approver';
    case SUPPORT_DISPUTES = 'support_disputes';
    case COMPLIANCE_ADMIN = 'compliance_admin';
    case SUPER_ADMIN = 'super_admin';

    /** Roles that require MFA on every session. */
    public function requiresMfa(): bool
    {
        return match ($this) {
            self::GUEST, self::BUYER, self::BUSINESS_BUYER, self::SELLER => false,
            default => true,
        };
    }

    /** Whether this role is an internal staff role. */
    public function isStaff(): bool
    {
        return match ($this) {
            self::GUEST, self::BUYER, self::BUSINESS_BUYER, self::SELLER => false,
            default => true,
        };
    }

    /** Whether this role can approve financial items. */
    public function canApproveFinancial(): bool
    {
        return match ($this) {
            self::FINANCE_APPROVER, self::COMPLIANCE_ADMIN, self::SUPER_ADMIN => true,
            default => false,
        };
    }

    /** Whether this role has sealed-bid read access (beyond own bids). */
    public function canViewSealedBids(): bool
    {
        return match ($this) {
            self::PROCUREMENT_AGENT, self::PROCUREMENT_LEAD,
            self::COMPLIANCE_ADMIN, self::SUPER_ADMIN => true,
            default => false,
        };
    }

    /** Whether this role can view buyer budget signals. */
    public function canViewBuyerBudgets(): bool
    {
        return match ($this) {
            self::PROCUREMENT_AGENT, self::PROCUREMENT_LEAD,
            self::COMPLIANCE_ADMIN, self::SUPER_ADMIN => true,
            default => false,
        };
    }

    /**
     * Roles that this role is authorized to grant.
     * @return Role[]
     */
    public function grantableRoles(): array
    {
        return match ($this) {
            self::SUPER_ADMIN => self::cases(), // can grant everything
            self::COMPLIANCE_ADMIN => [
                self::PROCUREMENT_LEAD,
                self::PROCUREMENT_AGENT,
                self::QUALITY_INSPECTOR,
                self::OPERATIONS_AGENT,
                self::FINANCE_OPERATOR,
                self::SUPPORT_DISPUTES,
            ],
            default => [],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::GUEST => 'Guest',
            self::BUYER => 'Buyer',
            self::BUSINESS_BUYER => 'Business Buyer',
            self::SELLER => 'Seller',
            self::PROCUREMENT_AGENT => 'Procurement Agent',
            self::PROCUREMENT_LEAD => 'Procurement Lead',
            self::QUALITY_INSPECTOR => 'Quality Inspector',
            self::OPERATIONS_AGENT => 'Operations Agent',
            self::FINANCE_OPERATOR => 'Finance Operator',
            self::FINANCE_APPROVER => 'Finance Approver',
            self::SUPPORT_DISPUTES => 'Support / Disputes',
            self::COMPLIANCE_ADMIN => 'Compliance / Admin',
            self::SUPER_ADMIN => 'Super Admin',
        };
    }
}
