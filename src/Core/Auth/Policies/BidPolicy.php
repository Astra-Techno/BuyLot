<?php

declare(strict_types=1);

namespace App\Core\Auth\Policies;

use App\Core\Auth\Enums\Role;
use App\Core\Auth\Policy;

/**
 * Policy enforcing sealed bid access control.
 *
 * Key invariants from the permissions matrix:
 *   - Seller can ONLY see own bids: WHERE seller_id = auth_user.seller_id
 *   - Buyer CANNOT see any bids
 *   - Procurement Agent sees assigned-case bids only
 *   - Procurement Lead sees all bids across all cases
 *   - Compliance/Admin and Super Admin see all bids
 *   - No bid amounts in notifications/logs to unauthorized users
 *   - Competing bid values, ranking, and other seller identities are NEVER exposed to sellers
 *
 * Enforcement points (Section 5.2):
 *   1. SQL query (WHERE bid.seller_id = ?)
 *   2. API response serializer
 *   3. Background job outputs
 *   4. Email/SMS notification templates
 *   5. Analytics aggregations
 *   6. Export files
 *   7. Admin views (non-privileged see masked values)
 *   8. Automated tests
 */
final class BidPolicy extends Policy
{
    // ──────────────────────────────────────────────────────────────
    // canView
    // ──────────────────────────────────────────────────────────────

    public function canView(object $user, mixed $resource, array $context = []): bool
    {
        // Super Admin sees everything
        if ($this->hasAnyRole($user, Role::SUPER_ADMIN)) {
            return true;
        }

        // Compliance/Admin sees all bids
        if ($this->hasAnyRole($user, Role::COMPLIANCE_ADMIN)) {
            return true;
        }

        // Procurement Lead sees all bids
        if ($this->hasAnyRole($user, Role::PROCUREMENT_LEAD)) {
            return true;
        }

        // Procurement Agent sees bids ONLY for assigned cases
        if ($this->hasAnyRole($user, Role::PROCUREMENT_AGENT)) {
            // Must have an assigned case context
            if ($resource !== null) {
                return $this->isAssigned($user, $resource, 'assigned_agent_id')
                    || (isset($context['assigned_to']) && $context['assigned_to'] === $user->id);
            }
            return isset($context['assigned_to']) && $context['assigned_to'] === $user->id;
        }

        // Seller can see ONLY own bids — enforced by seller_id match
        if ($this->hasAnyRole($user, Role::SELLER)) {
            if ($resource !== null) {
                return $this->isSellerOwner($user, $resource);
            }
            // For list queries, context must confirm seller_id scope
            return isset($context['seller_id'])
                && isset($user->seller_id)
                && $context['seller_id'] === $user->seller_id;
        }

        // Buyers: NO bid access whatsoever
        if ($this->hasAnyRole($user, Role::BUYER, Role::BUSINESS_BUYER)) {
            return false;
        }

        // Guest: NO bid access
        if ($this->hasAnyRole($user, Role::GUEST)) {
            return false;
        }

        // All other roles: NO bid access
        // Quality Inspector, Operations Agent, Finance Operator/Approver, Support
        return false;
    }

    // ──────────────────────────────────────────────────────────────
    // canCreate
    // ──────────────────────────────────────────────────────────────

    public function canCreate(object $user, mixed $resource, array $context = []): bool
    {
        // Super Admin
        if ($this->hasAnyRole($user, Role::SUPER_ADMIN)) {
            return true;
        }

        // Seller creates own bids only
        if ($this->hasAnyRole($user, Role::SELLER)) {
            // The bid being created must belong to the seller's seller_id
            if (isset($context['seller_id']) && isset($user->seller_id)) {
                return $context['seller_id'] === $user->seller_id;
            }
            // Allow creation when seller_id will be auto-assigned from auth context
            return isset($user->seller_id) && $user->seller_id > 0;
        }

        // Procurement Agent/Lead can create offline/team-sourced quotes (separate permission)
        // but NOT supplier bids. Bid creation is seller-only.
        return false;
    }

    // ──────────────────────────────────────────────────────────────
    // canUpdate
    // ──────────────────────────────────────────────────────────────

    public function canUpdate(object $user, mixed $resource, array $context = []): bool
    {
        if ($this->hasAnyRole($user, Role::SUPER_ADMIN)) {
            return true;
        }

        // Only the owning seller can revise their own bid
        if ($this->hasAnyRole($user, Role::SELLER)) {
            if ($resource !== null) {
                return $this->isSellerOwner($user, $resource);
            }
            return isset($context['seller_id'])
                && isset($user->seller_id)
                && $context['seller_id'] === $user->seller_id;
        }

        // No other role can update bids
        return false;
    }

    // ──────────────────────────────────────────────────────────────
    // canDelete (withdraw)
    // ──────────────────────────────────────────────────────────────

    public function canDelete(object $user, mixed $resource, array $context = []): bool
    {
        if ($this->hasAnyRole($user, Role::SUPER_ADMIN)) {
            return true;
        }

        // Only the owning seller can withdraw their bid
        if ($this->hasAnyRole($user, Role::SELLER)) {
            if ($resource !== null) {
                return $this->isSellerOwner($user, $resource);
            }
            return isset($context['seller_id'])
                && isset($user->seller_id)
                && $context['seller_id'] === $user->seller_id;
        }

        return false;
    }

    // ──────────────────────────────────────────────────────────────
    // canApprove — not applicable for bids directly
    // ──────────────────────────────────────────────────────────────

    public function canApprove(object $user, mixed $resource, array $context = []): bool
    {
        // Bids themselves are not "approved" — offers derived from bids are.
        return false;
    }

    // ──────────────────────────────────────────────────────────────
    // canExport
    // ──────────────────────────────────────────────────────────────

    public function canExport(object $user, mixed $resource, array $context = []): bool
    {
        if ($this->hasAnyRole($user, Role::SUPER_ADMIN)) {
            return true;
        }

        if ($this->hasAnyRole($user, Role::COMPLIANCE_ADMIN, Role::PROCUREMENT_LEAD)) {
            return true;
        }

        // Procurement Agent: assigned cases only
        if ($this->hasAnyRole($user, Role::PROCUREMENT_AGENT)) {
            return isset($context['assigned_to']) && $context['assigned_to'] === $user->id;
        }

        // Sellers, buyers, and all other roles: no bid export
        return false;
    }

    // ──────────────────────────────────────────────────────────────
    // Additional sealed-bid helpers
    // ──────────────────────────────────────────────────────────────

    /**
     * Determine which fields should be stripped from a bid response
     * for the given user. Used by API serializers.
     *
     * @return string[] Field names to EXCLUDE from the response
     */
    public function getRedactedFields(object $user, array $context = []): array
    {
        // Seller: strip all competitor information
        if ($this->hasAnyRole($user, Role::SELLER)) {
            return [
                'competing_bid_amount',
                'competing_seller_id',
                'competing_seller_name',
                'bid_rank',
                'bid_score',
                'ranking_position',
                'other_bid_count',
                'negotiation_notes',
                'buyer_max_budget',
                'platform_commission_margin',
                'internal_notes',
                'procurement_contact_history',
            ];
        }

        // Buyer: should never see bids at all, but if any leak, strip everything
        if ($this->hasAnyRole($user, Role::BUYER, Role::BUSINESS_BUYER)) {
            return [
                'bid_amount',
                'seller_id',
                'seller_name',
                'bid_rank',
                'bid_score',
                'negotiation_notes',
                'platform_commission_margin',
                'internal_notes',
            ];
        }

        // Guest: strip everything
        if ($this->hasAnyRole($user, Role::GUEST)) {
            return ['*']; // Sentinel: strip all fields
        }

        // Staff roles that should not see bid data
        if ($this->hasAnyRole(
            $user,
            Role::QUALITY_INSPECTOR,
            Role::OPERATIONS_AGENT,
            Role::FINANCE_OPERATOR,
            Role::FINANCE_APPROVER,
            Role::SUPPORT_DISPUTES,
        )) {
            return [
                'bid_amount',
                'competing_bid_amount',
                'competing_seller_id',
                'bid_rank',
                'negotiation_notes',
                'buyer_max_budget',
                'platform_commission_margin',
            ];
        }

        // Procurement Agent, Procurement Lead, Compliance, Super Admin: full access
        return [];
    }

    /**
     * Build the SQL WHERE clause fragment that enforces bid isolation.
     * Must be applied to ALL bid queries — not just API endpoints.
     *
     * @return array{sql: string, bindings: array} WHERE clause and parameter bindings
     */
    public function buildScopeFilter(object $user, array $context = []): array
    {
        // Super Admin, Compliance, Procurement Lead: no additional filter
        if ($this->hasAnyRole($user, Role::SUPER_ADMIN, Role::COMPLIANCE_ADMIN, Role::PROCUREMENT_LEAD)) {
            return ['sql' => '1=1', 'bindings' => []];
        }

        // Procurement Agent: filter by assigned case
        if ($this->hasAnyRole($user, Role::PROCUREMENT_AGENT)) {
            return [
                'sql' => 'bids.rfq_id IN (SELECT rfq_id FROM procurement_cases WHERE assigned_agent_id = ?)',
                'bindings' => [$user->id],
            ];
        }

        // Seller: strict isolation to own bids only
        if ($this->hasAnyRole($user, Role::SELLER) && isset($user->seller_id)) {
            return [
                'sql' => 'bids.seller_id = ?',
                'bindings' => [$user->seller_id],
            ];
        }

        // All other roles: return impossible condition (no results)
        return ['sql' => '1=0', 'bindings' => []];
    }
}
