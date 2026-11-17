<?php

declare(strict_types=1);

namespace App\Core\Auth;

use App\Core\Auth\Enums\Permission;
use App\Core\Auth\Enums\Role;
use App\Core\Database\Connection;

/**
 * Core RBAC + ABAC authorization engine.
 *
 * Every authorization decision flows through checkPermission():
 *   1. RBAC check — does the user hold a role that grants the requested permission?
 *   2. ABAC check — do contextual attributes (ownership, assignment, org, thresholds) allow it?
 *
 * This class is the single source of truth for server-side access control.
 * UI-level hiding is a convenience; it is NOT a security boundary.
 */
final class Authorization
{
    private static ?self $instance = null;

    /** Configurable thresholds (paise) — loaded from DB/config on first use. */
    private readonly int $procurementApprovalLimit;
    private readonly int $refundAutoLimit;

    private function __construct()
    {
        // Load thresholds from config/DB; fallback to conservative defaults
        $this->procurementApprovalLimit = (int) ($this->loadConfig('procurement_approval_limit') ?? 500_000_00); // 5 lakh paise
        $this->refundAutoLimit          = (int) ($this->loadConfig('refund_auto_limit') ?? 100_000_00);           // 1 lakh paise
    }

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** Reset singleton (useful in tests). */
    public static function reset(): void
    {
        self::$instance = null;
    }

    // ══════════════════════════════════════════════════════════════
    // Public API
    // ══════════════════════════════════════════════════════════════

    /**
     * Main authorization gate.
     *
     * @param int    $userId   Authenticated user ID (0 = guest)
     * @param string $resource Resource domain (e.g. "bids", "orders", "settlements")
     * @param string $action   Action verb (e.g. "read_own", "create", "approve")
     * @param array  $context  ABAC attributes:
     *   - resource_owner_id:  int  — owner of the target resource
     *   - organization_id:    int  — org that owns the resource
     *   - assigned_to:        int  — staff user the resource is assigned to
     *   - seller_id:          int  — seller that owns the resource
     *   - buyer_id:           int  — buyer that owns the resource
     *   - category_id:        int  — product category for scope checks
     *   - amount_paise:       int  — transaction amount in paise
     *   - created_by:         int  — user who created the record (maker-checker)
     *   - dispute_id:         int  — linked dispute for support scope
     *   - case_id:            int  — procurement case for agent scope
     *   - inspection_id:      int  — inspection task for inspector scope
     *   - shipment_id:        int  — shipment for ops agent scope
     * @param object|null $user Optional pre-loaded user object with Authorizable trait
     */
    public function checkPermission(
        int $userId,
        string $resource,
        string $action,
        array $context = [],
        ?object $user = null,
    ): bool {
        // ── Step 0: Guest fast-path ──────────────────────────────
        if ($userId === 0) {
            return $this->checkGuestAccess($resource, $action);
        }

        // ── Step 1: Load user roles & permissions ────────────────
        $roles       = $this->getUserRoles($userId, $user);
        $permissions = $this->getUserPermissions($userId, $user);

        // Super Admin bypasses RBAC check (still subject to immutability rules)
        $isSuperAdmin = in_array(Role::SUPER_ADMIN, $roles, true);

        // Immutable records: nobody can delete/modify audit logs
        if ($resource === 'audit' && in_array($action, ['delete', 'modify', 'update'], true)) {
            return false; // Absolute rule — no role overrides this
        }

        // ── Step 2: RBAC — find matching permission ──────────────
        $permissionSlug = "{$resource}.{$action}";
        $permission = Permission::tryFrom($permissionSlug);

        if (!$isSuperAdmin) {
            if ($permission === null || !in_array($permission, $permissions, true)) {
                return false;
            }
        }

        // ── Step 3: ABAC — contextual constraints ────────────────
        return $this->evaluateAbacConstraints(
            userId: $userId,
            roles: $roles,
            resource: $resource,
            action: $action,
            context: $context,
            user: $user,
        );
    }

    // ══════════════════════════════════════════════════════════════
    // Guest access (unauthenticated)
    // ══════════════════════════════════════════════════════════════

    private function checkGuestAccess(string $resource, string $action): bool
    {
        // Only explicitly public resources
        return match (true) {
            $resource === 'products'       && $action === 'read_public'    => true,
            $resource === 'categories'     && $action === 'read'           => true,
            $resource === 'variants'       && $action === 'read_public'    => true,
            $resource === 'rfq'            && $action === 'spec_read_public' => true,
            $resource === 'demand_groups'  && $action === 'read_public'    => true,
            $resource === 'demand_groups'  && $action === 'members_count'  => true,
            $resource === 'offers'         && $action === 'published_read' => true,
            $resource === 'seller_profile' && $action === 'read_public'    => true,
            default => false,
        };
    }

    // ══════════════════════════════════════════════════════════════
    // ABAC constraint evaluator
    // ══════════════════════════════════════════════════════════════

    private function evaluateAbacConstraints(
        int $userId,
        array $roles,
        string $resource,
        string $action,
        array $context,
        ?object $user,
    ): bool {
        // ── Ownership scope ──────────────────────────────────────
        if (str_contains($action, '_own') || $this->isOwnershipAction($resource, $action)) {
            if (!$this->checkOwnershipScope($userId, $context, $user)) {
                return false;
            }
        }

        // ── Organization scope ───────────────────────────────────
        if ($this->requiresOrgScope($roles, $resource, $action)) {
            if (!$this->checkOrgScope($userId, $context, $user)) {
                return false;
            }
        }

        // ── Assignment scope ─────────────────────────────────────
        if (str_contains($action, '_assigned') || $this->isAssignmentScoped($roles, $resource, $action)) {
            if (!$this->checkAssignmentScope($userId, $context)) {
                return false;
            }
        }

        // ── Sealed bid isolation ─────────────────────────────────
        if ($resource === 'bids' || $resource === 'rfq') {
            if (!$this->checkBidIsolation($userId, $roles, $action, $context, $user)) {
                return false;
            }
        }

        // ── Buyer budget privacy ─────────────────────────────────
        if ($this->isBudgetAccess($resource, $action, $context)) {
            if (!$this->checkBudgetPrivacy($userId, $roles, $context)) {
                return false;
            }
        }

        // ── No self-approval (maker-checker) ─────────────────────
        if ($this->isApprovalAction($action)) {
            if (!$this->checkNoSelfApproval($userId, $context)) {
                return false;
            }
        }

        // ── Amount threshold escalation ──────────────────────────
        if ($this->requiresEscalation($resource, $action, $context, $roles)) {
            return false; // Must be escalated to higher authority
        }

        // ── Category scope ───────────────────────────────────────
        if (isset($context['category_id']) && $this->requiresCategoryScope($roles)) {
            if (!$this->checkCategoryScope($userId, $context['category_id'])) {
                return false;
            }
        }

        // ── Support dispute linkage ──────────────────────────────
        if (in_array(Role::SUPPORT_DISPUTES, $roles, true)) {
            if ($this->requiresDisputeLinkage($resource, $action)) {
                if (!$this->checkDisputeLinkage($userId, $context)) {
                    return false;
                }
            }
        }

        return true;
    }

    // ══════════════════════════════════════════════════════════════
    // Individual constraint checks
    // ══════════════════════════════════════════════════════════════

    /**
     * Ownership: resource.owner_id or resource.buyer_id or resource.seller_id == auth user
     */
    private function checkOwnershipScope(int $userId, array $context, ?object $user): bool
    {
        // Direct owner match
        if (isset($context['resource_owner_id'])) {
            return $context['resource_owner_id'] === $userId;
        }

        // Buyer ownership
        if (isset($context['buyer_id'])) {
            return $context['buyer_id'] === $userId;
        }

        // Seller ownership — match seller_id on user object
        if (isset($context['seller_id']) && $user !== null && isset($user->seller_id)) {
            return $context['seller_id'] === $user->seller_id;
        }

        // If no ownership context provided, deny (safe default)
        return false;
    }

    /**
     * Organization scope: resource.organization_id == user.organization_id
     */
    private function checkOrgScope(int $userId, array $context, ?object $user): bool
    {
        if (!isset($context['organization_id'])) {
            return true; // No org constraint on this resource
        }

        $userOrgId = $user->organization_id ?? $this->getUserOrgId($userId);

        return $userOrgId !== null && $context['organization_id'] === $userOrgId;
    }

    /**
     * Assignment scope: resource.assigned_to == auth user
     */
    private function checkAssignmentScope(int $userId, array $context): bool
    {
        if (isset($context['assigned_to'])) {
            return $context['assigned_to'] === $userId;
        }

        // Check specific assignment fields
        foreach (['assigned_agent_id', 'assigned_inspector_id', 'assigned_ops_agent_id'] as $field) {
            if (isset($context[$field])) {
                return $context[$field] === $userId;
            }
        }

        // No assignment context — deny for safety
        return false;
    }

    /**
     * Sealed bid isolation: Sellers see ONLY their own bids, never competing bids.
     * Procurement agents see only bids for their assigned cases.
     */
    private function checkBidIsolation(
        int $userId,
        array $roles,
        string $action,
        array $context,
        ?object $user,
    ): bool {
        $hasSeller = in_array(Role::SELLER, $roles, true);

        // Sellers: enforce WHERE seller_id = auth.user.seller_id
        if ($hasSeller && !in_array(Role::SUPER_ADMIN, $roles, true)) {
            // Seller can only access own bid CRUD
            if (in_array($action, ['create_own', 'read_own', 'update_own', 'delete_own'], true)) {
                if (isset($context['seller_id']) && $user !== null && isset($user->seller_id)) {
                    return $context['seller_id'] === $user->seller_id;
                }
                return false;
            }

            // Seller CANNOT access sealed bids, comparisons, exports, or any multi-bid views
            if (in_array($action, [
                'read_all_sealed', 'read_all_sealed_assigned', 'compare',
                'compare_assigned', 'normalize', 'normalize_assigned',
                'export', 'export_assigned', 'spec_read_public',
            ], true)) {
                // spec_read_public is allowed for sellers
                return $action === 'spec_read_public';
            }
        }

        // Buyers: cannot see any bids at all
        if (in_array(Role::BUYER, $roles, true) || in_array(Role::BUSINESS_BUYER, $roles, true)) {
            if (!in_array(Role::SUPER_ADMIN, $roles, true)) {
                // Buyers have no bid read access
                if (str_contains($action, 'read') && $resource = 'bids') {
                    return false;
                }
            }
        }

        // Procurement Agent: assigned cases only
        if (in_array(Role::PROCUREMENT_AGENT, $roles, true)) {
            if (str_contains($action, '_assigned')) {
                return $this->checkAssignmentScope($userId, $context);
            }
        }

        return true;
    }

    /**
     * Buyer budget privacy: max_budget visible only to authorized staff.
     */
    private function checkBudgetPrivacy(int $userId, array $roles, array $context): bool
    {
        // Allowed roles
        $allowedRoles = [
            Role::PROCUREMENT_AGENT,
            Role::PROCUREMENT_LEAD,
            Role::COMPLIANCE_ADMIN,
            Role::SUPER_ADMIN,
        ];

        foreach ($allowedRoles as $allowed) {
            if (in_array($allowed, $roles, true)) {
                // Procurement Agent must be assigned to the case
                if ($allowed === Role::PROCUREMENT_AGENT) {
                    return $this->checkAssignmentScope($userId, $context);
                }
                return true;
            }
        }

        // Buyer can see own budget
        if (in_array(Role::BUYER, $roles, true) || in_array(Role::BUSINESS_BUYER, $roles, true)) {
            if (isset($context['buyer_id']) && $context['buyer_id'] === $userId) {
                return true;
            }
        }

        // Support: only if linked to active dispute on that order
        if (in_array(Role::SUPPORT_DISPUTES, $roles, true)) {
            return $this->checkDisputeLinkage($userId, $context);
        }

        return false;
    }

    /**
     * No self-approval: approver must differ from creator/preparer.
     * Enforced for all financial adjustments, offer approvals, settlements.
     */
    private function checkNoSelfApproval(int $userId, array $context): bool
    {
        // Check created_by
        if (isset($context['created_by']) && $context['created_by'] === $userId) {
            return false;
        }

        // Check preparer_id
        if (isset($context['preparer_id']) && $context['preparer_id'] === $userId) {
            return false;
        }

        // Check proposer_id
        if (isset($context['proposer_id']) && $context['proposer_id'] === $userId) {
            return false;
        }

        // Check submitter_id (for offer approval)
        if (isset($context['submitter_id']) && $context['submitter_id'] === $userId) {
            return false;
        }

        return true;
    }

    /**
     * Amount threshold: determines if the action requires escalation.
     * Returns true if escalation IS required (i.e., current user is NOT authorized).
     */
    private function requiresEscalation(
        string $resource,
        string $action,
        array $context,
        array $roles,
    ): bool {
        $amount = $context['amount_paise'] ?? 0;

        if ($amount <= 0) {
            return false;
        }

        // Procurement commitment above threshold → needs Procurement Lead approval
        if ($resource === 'cases' && $action === 'approve') {
            if ($amount > $this->procurementApprovalLimit) {
                // Only Procurement Lead, Compliance, or Super Admin can approve
                return !$this->hasAnyOfRoles($roles, [
                    Role::PROCUREMENT_LEAD,
                    Role::COMPLIANCE_ADMIN,
                    Role::SUPER_ADMIN,
                ]);
            }
        }

        // Refund above auto-limit → needs Finance Approver
        if ($resource === 'refunds' && in_array($action, ['approve', 'initiate'], true)) {
            if ($amount > $this->refundAutoLimit && $action === 'initiate') {
                // Auto-approve below threshold; above needs Finance Approver
                return !$this->hasAnyOfRoles($roles, [
                    Role::FINANCE_APPROVER,
                    Role::COMPLIANCE_ADMIN,
                    Role::SUPER_ADMIN,
                ]);
            }
        }

        // All settlements require Finance Approver sign-off
        if ($resource === 'settlements' && $action === 'approve') {
            return !$this->hasAnyOfRoles($roles, [
                Role::FINANCE_APPROVER,
                Role::COMPLIANCE_ADMIN,
                Role::SUPER_ADMIN,
            ]);
        }

        // All financial adjustments require Finance Approver
        if ($resource === 'financial_adjustment' && $action === 'approve') {
            return !$this->hasAnyOfRoles($roles, [
                Role::FINANCE_APPROVER,
                Role::COMPLIANCE_ADMIN,
                Role::SUPER_ADMIN,
            ]);
        }

        return false;
    }

    /**
     * Category scope: check user is authorized for the resource's category.
     */
    private function checkCategoryScope(int $userId, int $categoryId): bool
    {
        $db = Connection::getInstance();
        $result = $db->query(
            'SELECT 1 FROM user_category_permissions
             WHERE user_id = ? AND category_id = ? LIMIT 1',
            [$userId, $categoryId],
        );

        return !empty($result);
    }

    /**
     * Support dispute linkage: Support agent can only access resources
     * linked to their assigned dispute cases.
     */
    private function checkDisputeLinkage(int $userId, array $context): bool
    {
        // If a dispute_id is provided, verify assignment
        if (isset($context['dispute_id'])) {
            $db = Connection::getInstance();
            $result = $db->query(
                'SELECT 1 FROM disputes
                 WHERE id = ? AND assigned_agent_id = ? LIMIT 1',
                [$context['dispute_id'], $userId],
            );
            return !empty($result);
        }

        // If an order_id is provided, check for linked dispute assigned to this agent
        if (isset($context['order_id'])) {
            $db = Connection::getInstance();
            $result = $db->query(
                'SELECT 1 FROM disputes
                 WHERE order_id = ? AND assigned_agent_id = ? AND status != \'closed\' LIMIT 1',
                [$context['order_id'], $userId],
            );
            return !empty($result);
        }

        return false;
    }

    // ══════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════

    private function isOwnershipAction(string $resource, string $action): bool
    {
        // Actions that implicitly require ownership even without _own suffix
        return match (true) {
            $resource === 'payments' && $action === 'initiate'       => true,
            $resource === 'orders'   && $action === 'create'         => true,
            $resource === 'disputes' && $action === 'open'           => true,
            $resource === 'delivery_ack' && $action === 'create'     => true,
            default => false,
        };
    }

    private function requiresOrgScope(array $roles, string $resource, string $action): bool
    {
        if (!in_array(Role::BUSINESS_BUYER, $roles, true)) {
            return false;
        }

        return in_array($resource, [
            'buy_requests', 'orders', 'b2b_rfq',
        ], true);
    }

    private function isAssignmentScoped(array $roles, string $resource, string $action): bool
    {
        // Staff roles that operate on assigned work items
        $assignmentRoles = [
            Role::PROCUREMENT_AGENT,
            Role::QUALITY_INSPECTOR,
            Role::OPERATIONS_AGENT,
            Role::SUPPORT_DISPUTES,
        ];

        $hasAssignmentRole = false;
        foreach ($assignmentRoles as $r) {
            if (in_array($r, $roles, true)) {
                $hasAssignmentRole = true;
                break;
            }
        }

        if (!$hasAssignmentRole) {
            return false;
        }

        // Resources that require assignment scoping for these roles
        return match (true) {
            $resource === 'cases' && in_array(Role::PROCUREMENT_AGENT, $roles, true)      => true,
            $resource === 'inspection' && in_array(Role::QUALITY_INSPECTOR, $roles, true)  => true,
            $resource === 'shipments' && in_array(Role::OPERATIONS_AGENT, $roles, true)    => true,
            $resource === 'disputes' && in_array(Role::SUPPORT_DISPUTES, $roles, true)     => true,
            default => false,
        };
    }

    private function isBudgetAccess(string $resource, string $action, array $context): bool
    {
        // Any access that might expose buyer budget data
        return isset($context['includes_budget']) && $context['includes_budget'] === true;
    }

    private function isApprovalAction(string $action): bool
    {
        return in_array($action, [
            'approve', 'approve_org', 'approve_result',
        ], true);
    }

    private function requiresCategoryScope(array $roles): bool
    {
        return in_array(Role::PROCUREMENT_AGENT, $roles, true)
            || in_array(Role::QUALITY_INSPECTOR, $roles, true);
    }

    private function requiresDisputeLinkage(string $resource, string $action): bool
    {
        // Support needs dispute linkage to access orders, payments, buy requests
        return in_array($resource, [
            'orders', 'payments', 'buy_requests',
        ], true);
    }

    /** @param Role[] $roles @param Role[] $required */
    private function hasAnyOfRoles(array $roles, array $required): bool
    {
        foreach ($required as $r) {
            if (in_array($r, $roles, true)) {
                return true;
            }
        }
        return false;
    }

    /** @return Role[] */
    private function getUserRoles(int $userId, ?object $user): array
    {
        if ($user !== null && method_exists($user, 'getRoles')) {
            return $user->getRoles();
        }

        $db = Connection::getInstance();
        $rows = $db->query(
            'SELECT r.slug FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ? AND ur.revoked_at IS NULL',
            [$userId],
        );

        return array_filter(array_map(
            static fn(array $row) => Role::tryFrom($row['slug']),
            $rows,
        ));
    }

    /** @return Permission[] */
    private function getUserPermissions(int $userId, ?object $user): array
    {
        if ($user !== null && method_exists($user, 'getPermissions')) {
            return $user->getPermissions();
        }

        $db = Connection::getInstance();
        $rows = $db->query(
            'SELECT DISTINCT p.slug FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             INNER JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = ? AND ur.revoked_at IS NULL',
            [$userId],
        );

        return array_filter(array_map(
            static fn(array $row) => Permission::tryFrom($row['slug']),
            $rows,
        ));
    }

    private function getUserOrgId(int $userId): ?int
    {
        $db = Connection::getInstance();
        $row = $db->query(
            'SELECT organization_id FROM users WHERE id = ? LIMIT 1',
            [$userId],
        );

        return isset($row[0]['organization_id']) ? (int) $row[0]['organization_id'] : null;
    }

    private function loadConfig(string $key): ?string
    {
        try {
            $db = Connection::getInstance();
            $row = $db->query(
                'SELECT value FROM platform_config WHERE `key` = ? LIMIT 1',
                [$key],
            );
            return $row[0]['value'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }
}
