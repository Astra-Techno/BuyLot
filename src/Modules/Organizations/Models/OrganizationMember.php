<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

class OrganizationMember
{
    public const ROLE_OWNER  = 'owner';
    public const ROLE_ADMIN  = 'admin';
    public const ROLE_MEMBER = 'member';
    public const ROLE_VIEWER = 'viewer';

    public const VALID_ROLES = [
        self::ROLE_OWNER,
        self::ROLE_ADMIN,
        self::ROLE_MEMBER,
        self::ROLE_VIEWER,
    ];

    public const STATUS_ACTIVE    = 'active';
    public const STATUS_INVITED   = 'invited';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_REMOVED   = 'removed';

    public const VALID_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INVITED,
        self::STATUS_SUSPENDED,
        self::STATUS_REMOVED,
    ];

    /** Roles that can manage an organization (create/update members, edit org details). */
    public const MANAGING_ROLES = [self::ROLE_OWNER, self::ROLE_ADMIN];

    private const TABLE = 'organization_members';

    public function __construct(private readonly Connection $connection) {}

    public function findById(int $id): ?array
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('id', $id)
            ->first();
    }

    public function findByOrgAndUser(int $organizationId, int $userId): ?array
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->first();
    }

    public function findActiveByOrgAndUser(int $organizationId, int $userId): ?array
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('user_id', $userId)
            ->where('status', '!=', self::STATUS_REMOVED)
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByOrganization(int $organizationId, int $limit = 100, int $offset = 0): array
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('status', '!=', self::STATUS_REMOVED)
            ->orderBy('created_at', 'ASC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    public function countByOrganization(int $organizationId): int
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('status', '!=', self::STATUS_REMOVED)
            ->count();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findOwnersByOrganization(int $organizationId): array
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('organization_id', $organizationId)
            ->where('role', self::ROLE_OWNER)
            ->where('status', self::STATUS_ACTIVE)
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findOrganizationsByUser(int $userId): array
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('status', '!=', self::STATUS_REMOVED)
            ->get();
    }

    /**
     * Insert a new membership row and return its id.
     */
    public function create(int $organizationId, int $userId, string $role, string $status = self::STATUS_INVITED): int
    {
        $qb = new QueryBuilder($this->connection);
        $qb->table(self::TABLE)->insert([
            'organization_id' => $organizationId,
            'user_id'         => $userId,
            'role'            => $role,
            'status'          => $status,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Update the role and/or status of a membership row.
     * Returns number of affected rows.
     */
    public function update(int $id, array $data): int
    {
        $allowed = ['role', 'status'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if (empty($updateData)) {
            return 0;
        }

        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('id', $id)
            ->update($updateData);
    }

    /**
     * Mark a membership as removed (logical delete).
     */
    public function remove(int $id): int
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('id', $id)
            ->update(['status' => self::STATUS_REMOVED]);
    }

    public function isMember(int $organizationId, int $userId): bool
    {
        return $this->findActiveByOrgAndUser($organizationId, $userId) !== null;
    }

    public function hasRole(int $organizationId, int $userId, string ...$roles): bool
    {
        $member = $this->findActiveByOrgAndUser($organizationId, $userId);

        if ($member === null || $member['status'] !== self::STATUS_ACTIVE) {
            return false;
        }

        return in_array($member['role'], $roles, true);
    }
}
