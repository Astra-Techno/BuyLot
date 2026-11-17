<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Services;

use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMember;

class OrganizationService
{
    private Organization $organizationModel;
    private OrganizationMember $memberModel;

    public function __construct(private readonly Connection $connection)
    {
        $this->organizationModel = new Organization($connection);
        $this->memberModel       = new OrganizationMember($connection);
    }

    // -------------------------------------------------------------------------
    // Organization CRUD
    // -------------------------------------------------------------------------

    /**
     * Create a new organization and add the creator as OWNER in a single transaction.
     */
    public function createOrganization(array $data, int $creatorUserId): array
    {
        $this->validateOrganizationData($data);
        $this->checkForDuplicates($data);

        $this->connection->beginTransaction();

        try {
            $orgId = $this->organizationModel->create([
                'type'                => $data['type'],
                'legal_name'          => $data['legal_name'],
                'tax_id_ref'          => $data['tax_id_ref'] ?? null,
                'verification_status' => Organization::VERIFICATION_UNVERIFIED,
            ]);

            $this->memberModel->create(
                $orgId,
                $creatorUserId,
                OrganizationMember::ROLE_OWNER,
                OrganizationMember::STATUS_ACTIVE,
            );

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->getOrganizationOrFail($orgId);
    }

    public function getOrganizationOrFail(int $id): array
    {
        $org = $this->organizationModel->findById($id);

        if ($org === null) {
            throw new HttpException('Organization not found', 404);
        }

        return $org;
    }

    /**
     * Return org only when the requesting user is an active member.
     */
    public function getOrganizationForMember(int $id, int $userId): array
    {
        $org = $this->getOrganizationOrFail($id);
        $this->requireActiveMembership($id, $userId);

        return $org;
    }

    public function updateOrganization(int $id, array $data, int $actorUserId): array
    {
        $this->requireManagingRole($id, $actorUserId);

        $allowed = ['legal_name', 'tax_id_ref', 'type'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        $errors = [];

        if (isset($updateData['type']) && !in_array($updateData['type'], Organization::VALID_TYPES, true)) {
            $errors['type'] = 'Invalid organization type';
        }

        if (isset($updateData['legal_name'])) {
            if (empty(trim($updateData['legal_name']))) {
                $errors['legal_name'] = 'Legal name cannot be empty';
            } elseif (strlen($updateData['legal_name']) > 255) {
                $errors['legal_name'] = 'Legal name must not exceed 255 characters';
            } else {
                // Check for duplicate legal name on a different org
                $existing = $this->organizationModel->findByLegalName($updateData['legal_name']);
                if ($existing !== null && (int) $existing['id'] !== $id) {
                    $errors['legal_name'] = 'An organization with this legal name already exists';
                }
            }
        }

        if (isset($updateData['tax_id_ref']) && $updateData['tax_id_ref'] !== null) {
            $existing = $this->organizationModel->findByTaxIdRef($updateData['tax_id_ref']);
            if ($existing !== null && (int) $existing['id'] !== $id) {
                $errors['tax_id_ref'] = 'This tax ID is already registered to another organization';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        if (empty($updateData)) {
            throw new ValidationException(['update' => 'No valid fields provided for update']);
        }

        $this->organizationModel->update($id, $updateData);

        return $this->getOrganizationOrFail($id);
    }

    // -------------------------------------------------------------------------
    // Member management
    // -------------------------------------------------------------------------

    /**
     * @return array{members: array<int, array<string, mixed>>, total: int}
     */
    public function listMembers(int $orgId, int $actorUserId, int $limit = 50, int $offset = 0): array
    {
        $this->requireActiveMembership($orgId, $actorUserId);

        return [
            'members' => $this->memberModel->findByOrganization($orgId, $limit, $offset),
            'total'   => $this->memberModel->countByOrganization($orgId),
        ];
    }

    /**
     * Invite a user to an organization.
     */
    public function addMember(int $orgId, array $data, int $actorUserId): array
    {
        $this->requireManagingRole($orgId, $actorUserId);

        $errors = [];

        if (empty($data['user_id']) || !is_int($data['user_id'])) {
            $errors['user_id'] = 'user_id is required and must be an integer';
        }

        $role = $data['role'] ?? OrganizationMember::ROLE_MEMBER;

        if (!in_array($role, OrganizationMember::VALID_ROLES, true)) {
            $errors['role'] = 'Invalid role. Must be one of: ' . implode(', ', OrganizationMember::VALID_ROLES);
        }

        // Prevent adding a second OWNER via invite; OWNER can only be assigned at creation
        // or transferred via updateMember by an existing OWNER.
        if ($role === OrganizationMember::ROLE_OWNER) {
            // Only an existing OWNER can assign OWNER role
            $actorMember = $this->memberModel->findActiveByOrgAndUser($orgId, $actorUserId);
            if ($actorMember === null || $actorMember['role'] !== OrganizationMember::ROLE_OWNER) {
                $errors['role'] = 'Only an OWNER can assign the OWNER role';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $inviteeUserId = (int) $data['user_id'];

        $existing = $this->memberModel->findByOrgAndUser($orgId, $inviteeUserId);

        if ($existing !== null) {
            if ($existing['status'] !== OrganizationMember::STATUS_REMOVED) {
                throw new HttpException('User is already a member of this organization', 409);
            }

            // Re-invite a previously removed member
            $this->memberModel->update((int) $existing['id'], [
                'role'   => $role,
                'status' => OrganizationMember::STATUS_INVITED,
            ]);

            return $this->memberModel->findById((int) $existing['id']) ?? [];
        }

        $memberId = $this->memberModel->create($orgId, $inviteeUserId, $role, OrganizationMember::STATUS_INVITED);

        return $this->memberModel->findById($memberId) ?? [];
    }

    /**
     * Change a member's role or status.
     */
    public function updateMember(int $orgId, int $memberId, array $data, int $actorUserId): array
    {
        $this->requireManagingRole($orgId, $actorUserId);

        $member = $this->memberModel->findById($memberId);

        if ($member === null || (int) $member['organization_id'] !== $orgId) {
            throw new HttpException('Member not found in this organization', 404);
        }

        $errors = [];
        $updateData = [];

        if (isset($data['role'])) {
            if (!in_array($data['role'], OrganizationMember::VALID_ROLES, true)) {
                $errors['role'] = 'Invalid role. Must be one of: ' . implode(', ', OrganizationMember::VALID_ROLES);
            } else {
                // Only an OWNER can assign/change to OWNER role
                if ($data['role'] === OrganizationMember::ROLE_OWNER) {
                    $actorMember = $this->memberModel->findActiveByOrgAndUser($orgId, $actorUserId);
                    if ($actorMember === null || $actorMember['role'] !== OrganizationMember::ROLE_OWNER) {
                        $errors['role'] = 'Only an OWNER can assign the OWNER role';
                    }
                }
                $updateData['role'] = $data['role'];
            }
        }

        if (isset($data['status'])) {
            if (!in_array($data['status'], OrganizationMember::VALID_STATUSES, true)) {
                $errors['status'] = 'Invalid status. Must be one of: ' . implode(', ', OrganizationMember::VALID_STATUSES);
            } else {
                $updateData['status'] = $data['status'];
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        if (empty($updateData)) {
            throw new ValidationException(['update' => 'No valid fields provided for update']);
        }

        $this->memberModel->update($memberId, $updateData);

        return $this->memberModel->findById($memberId) ?? [];
    }

    /**
     * Remove a member from an organization.
     * An OWNER cannot remove themselves if they are the sole OWNER.
     */
    public function removeMember(int $orgId, int $memberId, int $actorUserId): void
    {
        $this->requireManagingRole($orgId, $actorUserId);

        $member = $this->memberModel->findById($memberId);

        if ($member === null || (int) $member['organization_id'] !== $orgId) {
            throw new HttpException('Member not found in this organization', 404);
        }

        // Prevent an OWNER from removing themselves when they are the only active owner.
        if (
            (int) $member['user_id'] === $actorUserId &&
            $member['role'] === OrganizationMember::ROLE_OWNER
        ) {
            $owners = $this->memberModel->findOwnersByOrganization($orgId);
            if (count($owners) <= 1) {
                throw new HttpException(
                    'Cannot remove the sole OWNER of an organization. Transfer ownership first.',
                    422,
                );
            }
        }

        $this->memberModel->remove($memberId);
    }

    // -------------------------------------------------------------------------
    // Authorization helpers
    // -------------------------------------------------------------------------

    private function requireActiveMembership(int $orgId, int $userId): void
    {
        if (!$this->memberModel->isMember($orgId, $userId)) {
            throw new HttpException('You are not a member of this organization', 403);
        }
    }

    private function requireManagingRole(int $orgId, int $userId): void
    {
        if (!$this->memberModel->hasRole($orgId, $userId, ...OrganizationMember::MANAGING_ROLES)) {
            throw new HttpException('Insufficient permissions. OWNER or ADMIN role required.', 403);
        }
    }

    // -------------------------------------------------------------------------
    // Validation helpers
    // -------------------------------------------------------------------------

    private function validateOrganizationData(array $data): void
    {
        $errors = [];

        if (empty($data['legal_name'])) {
            $errors['legal_name'] = 'Legal name is required';
        } elseif (strlen($data['legal_name']) > 255) {
            $errors['legal_name'] = 'Legal name must not exceed 255 characters';
        }

        if (empty($data['type'])) {
            $errors['type'] = 'Organization type is required';
        } elseif (!in_array($data['type'], Organization::VALID_TYPES, true)) {
            $errors['type'] = 'Invalid organization type. Must be one of: ' . implode(', ', Organization::VALID_TYPES);
        }

        if (isset($data['tax_id_ref']) && strlen((string) $data['tax_id_ref']) > 100) {
            $errors['tax_id_ref'] = 'Tax ID reference must not exceed 100 characters';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }

    private function checkForDuplicates(array $data): void
    {
        $errors = [];

        $existingByName = $this->organizationModel->findByLegalName($data['legal_name']);
        if ($existingByName !== null) {
            $errors['legal_name'] = 'An organization with this legal name already exists';
        }

        if (!empty($data['tax_id_ref'])) {
            $existingByTax = $this->organizationModel->findByTaxIdRef($data['tax_id_ref']);
            if ($existingByTax !== null) {
                $errors['tax_id_ref'] = 'This tax ID is already registered to another organization';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }
    }
}
