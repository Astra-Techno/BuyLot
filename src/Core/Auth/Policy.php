<?php

declare(strict_types=1);

namespace App\Core\Auth;

/**
 * Abstract policy base class for resource-specific authorization logic.
 *
 * Each concrete policy encapsulates the ABAC rules for a single resource type.
 * Policies are resolved via PolicyRegistry and invoked by AuthorizationMiddleware.
 *
 * The $user parameter is expected to use the Authorizable trait.
 * The $resource parameter is the target entity (array, object, or null for create).
 * The $context array carries additional ABAC attributes.
 */
abstract class Policy
{
    /**
     * Check if the user can view the resource.
     */
    abstract public function canView(object $user, mixed $resource, array $context = []): bool;

    /**
     * Check if the user can create a new instance of the resource.
     */
    abstract public function canCreate(object $user, mixed $resource, array $context = []): bool;

    /**
     * Check if the user can update the resource.
     */
    abstract public function canUpdate(object $user, mixed $resource, array $context = []): bool;

    /**
     * Check if the user can delete the resource.
     */
    abstract public function canDelete(object $user, mixed $resource, array $context = []): bool;

    /**
     * Check if the user can approve the resource (maker-checker gate).
     */
    public function canApprove(object $user, mixed $resource, array $context = []): bool
    {
        return false; // Default: no approval capability
    }

    /**
     * Check if the user can export the resource data.
     */
    public function canExport(object $user, mixed $resource, array $context = []): bool
    {
        return false; // Default: no export capability
    }

    // ──────────────────────────────────────────────────────────────
    // Shared helpers for concrete policies
    // ──────────────────────────────────────────────────────────────

    /**
     * Verify the user is the resource owner.
     */
    protected function isOwner(object $user, mixed $resource, string $ownerField = 'created_by'): bool
    {
        if (is_array($resource)) {
            return isset($resource[$ownerField]) && $resource[$ownerField] === $user->id;
        }

        if (is_object($resource) && property_exists($resource, $ownerField)) {
            return $resource->{$ownerField} === $user->id;
        }

        return false;
    }

    /**
     * Verify the user's seller profile matches the resource.
     */
    protected function isSellerOwner(object $user, mixed $resource, string $field = 'seller_id'): bool
    {
        $sellerId = is_array($resource) ? ($resource[$field] ?? null) : ($resource->{$field} ?? null);

        return $sellerId !== null
            && isset($user->seller_id)
            && $sellerId === $user->seller_id;
    }

    /**
     * Verify the user is assigned to the resource.
     */
    protected function isAssigned(object $user, mixed $resource, string $field = 'assigned_to'): bool
    {
        $assignedTo = is_array($resource) ? ($resource[$field] ?? null) : ($resource->{$field} ?? null);

        return $assignedTo !== null && $assignedTo === $user->id;
    }

    /**
     * Verify org-scope match.
     */
    protected function isSameOrg(object $user, mixed $resource, string $field = 'organization_id'): bool
    {
        $orgId = is_array($resource) ? ($resource[$field] ?? null) : ($resource->{$field} ?? null);

        return $orgId !== null
            && isset($user->organization_id)
            && $orgId === $user->organization_id;
    }

    /**
     * Ensure the approver is not the creator (maker-checker).
     */
    protected function isNotCreator(object $user, mixed $resource, string $field = 'created_by'): bool
    {
        $createdBy = is_array($resource) ? ($resource[$field] ?? null) : ($resource->{$field} ?? null);

        return $createdBy !== null && $createdBy !== $user->id;
    }

    /**
     * Check if user holds any of the given roles.
     */
    protected function hasAnyRole(object $user, \App\Core\Auth\Enums\Role ...$roles): bool
    {
        if (!method_exists($user, 'hasRole')) {
            return false;
        }

        foreach ($roles as $role) {
            if ($user->hasRole($role)) {
                return true;
            }
        }

        return false;
    }
}
