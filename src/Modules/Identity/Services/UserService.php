<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Modules\Identity\Models\User;

/**
 * UserService encapsulates business logic that sits above raw model operations:
 * find-or-create on OTP verify, duplicate-email checks, status transitions, etc.
 */
class UserService
{
    private const ALLOWED_STATUSES = ['active', 'suspended'];

    private const ALLOWED_ROLES = [
        'buyer',
        'business_buyer',
        'seller',
        'procurement_agent',
        'procurement_lead',
        'quality_inspector',
        'operations_agent',
        'finance_operator',
        'finance_approver',
        'support',
        'compliance_admin',
        'super_admin',
    ];

    public function __construct(private User $userModel) {}

    // -------------------------------------------------------------------------
    // Auth-time operations
    // -------------------------------------------------------------------------

    /**
     * Find an existing user by mobile or create a new one.
     * On first creation the user is immediately marked verified.
     * On subsequent logins, re-verify (keeps `is_verified = 1`).
     *
     * @return array The user record (with roles).
     */
    public function findOrCreate(string $mobile): array
    {
        $user = $this->userModel->findByMobile($mobile);

        if ($user === null) {
            $user = $this->userModel->create($mobile);
            $this->userModel->verify($user['id']);
            // Re-fetch to get updated verified flag
            $user = $this->userModel->find($user['id']) ?? $user;
        } else {
            if ($user['status'] === 'suspended') {
                throw new HttpException('Account suspended. Please contact support.', 403);
            }

            if ($user['status'] === 'pending_deletion') {
                throw new HttpException('Account is scheduled for deletion.', 403);
            }

            // Ensure verified on subsequent logins
            if (!$user['is_verified']) {
                $this->userModel->verify($user['id']);
                $user['is_verified'] = 1;
            }
        }

        return $user;
    }

    // -------------------------------------------------------------------------
    // Profile operations
    // -------------------------------------------------------------------------

    /**
     * Get a user by ID, throwing 404 if not found.
     */
    public function getOrFail(string $id): array
    {
        $user = $this->userModel->find($id);

        if ($user === null) {
            throw new HttpException('User not found', 404);
        }

        return $user;
    }

    /**
     * Update profile; check for duplicate email if one is supplied.
     *
     * @param array<string,mixed> $data  Pre-validated fields
     */
    public function updateProfile(string $userId, array $data): array
    {
        if (!empty($data['email'])) {
            $this->assertEmailUnique($data['email'], $userId);
        }

        $this->userModel->update($userId, $data);

        return $this->getOrFail($userId);
    }

    /**
     * Soft-delete the account (marks pending_deletion, sets deleted_at).
     */
    public function requestDeletion(string $userId): void
    {
        $user = $this->getOrFail($userId);

        if ($user['status'] === 'pending_deletion') {
            // Idempotent — already scheduled
            return;
        }

        $this->userModel->softDelete($userId);
    }

    // -------------------------------------------------------------------------
    // Admin operations
    // -------------------------------------------------------------------------

    /**
     * Update user status; validates the transition.
     */
    public function updateStatus(string $userId, string $status): array
    {
        if (!in_array($status, self::ALLOWED_STATUSES, true)) {
            throw new ValidationException([
                'status' => 'Invalid status. Allowed: ' . implode(', ', self::ALLOWED_STATUSES),
            ]);
        }

        $this->getOrFail($userId); // 404 guard

        $this->userModel->updateStatus($userId, $status);

        return $this->getOrFail($userId);
    }

    /**
     * Assign a role to a user; validates the role name.
     */
    public function assignRole(string $userId, string $role): array
    {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new ValidationException([
                'role' => 'Invalid role. Allowed: ' . implode(', ', self::ALLOWED_ROLES),
            ]);
        }

        $this->getOrFail($userId); // 404 guard

        $this->userModel->assignRole($userId, $role);

        return $this->getOrFail($userId);
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    private function assertEmailUnique(string $email, string $excludeId): void
    {
        // We use the model's findByMobile-equivalent query via raw QB; however,
        // User model only exposes mobile-based lookups. We handle it here with a
        // direct QueryBuilder — but UserService does not hold QB/Connection directly.
        // Instead, check via model's list() which supports search.
        // A cleaner approach: add findByEmail to the model; for now delegate to model.
        // This is intentionally left as a lightweight guard using the User model's list.

        $results = $this->userModel->list(['search' => $email], 1, 5);

        foreach ($results['data'] as $user) {
            if (
                isset($user['email'])
                && strtolower($user['email']) === strtolower($email)
                && $user['id'] !== $excludeId
            ) {
                throw new ValidationException(['email' => 'Email address is already in use']);
            }
        }
    }
}
