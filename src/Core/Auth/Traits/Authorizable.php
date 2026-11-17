<?php

declare(strict_types=1);

namespace App\Core\Auth\Traits;

use App\Core\Auth\Authorization;
use App\Core\Auth\Enums\Permission;
use App\Core\Auth\Enums\Role;
use App\Core\Database\Connection;

/**
 * Trait for the User model providing role/permission checks.
 *
 * Expects the consuming class to have:
 *   - public readonly int $id
 *   - public readonly ?int $seller_id
 *   - public readonly ?int $organization_id
 */
trait Authorizable
{
    /** @var Role[]|null Cached roles for this request lifecycle */
    private ?array $cachedRoles = null;

    /** @var Permission[]|null Cached permissions for this request lifecycle */
    private ?array $cachedPermissions = null;

    // ──────────────────────────────────────────────────────────────
    // Role helpers
    // ──────────────────────────────────────────────────────────────

    /** @return Role[] */
    public function getRoles(): array
    {
        if ($this->cachedRoles !== null) {
            return $this->cachedRoles;
        }

        $cacheKey = "user:{$this->id}:roles";

        // Try Redis first
        $cached = $this->redisGet($cacheKey);
        if ($cached !== null) {
            $this->cachedRoles = array_map(
                static fn(string $v) => Role::from($v),
                json_decode($cached, true),
            );
            return $this->cachedRoles;
        }

        // Fall back to DB
        $db = Connection::getInstance();
        $rows = $db->query(
            'SELECT r.slug FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = ? AND ur.revoked_at IS NULL',
            [$this->id],
        );

        $this->cachedRoles = array_map(
            static fn(array $row) => Role::from($row['slug']),
            $rows,
        );

        // Cache for 15 minutes
        $this->redisSet($cacheKey, json_encode(
            array_map(static fn(Role $r) => $r->value, $this->cachedRoles),
        ), 900);

        return $this->cachedRoles;
    }

    /** @return Permission[] */
    public function getPermissions(): array
    {
        if ($this->cachedPermissions !== null) {
            return $this->cachedPermissions;
        }

        $cacheKey = "user:{$this->id}:permissions";

        $cached = $this->redisGet($cacheKey);
        if ($cached !== null) {
            $this->cachedPermissions = array_filter(array_map(
                static fn(string $v) => Permission::tryFrom($v),
                json_decode($cached, true),
            ));
            return $this->cachedPermissions;
        }

        $db = Connection::getInstance();
        $rows = $db->query(
            'SELECT DISTINCT p.slug FROM permissions p
             INNER JOIN role_permissions rp ON rp.permission_id = p.id
             INNER JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = ? AND ur.revoked_at IS NULL',
            [$this->id],
        );

        $this->cachedPermissions = array_filter(array_map(
            static fn(array $row) => Permission::tryFrom($row['slug']),
            $rows,
        ));

        $this->redisSet($cacheKey, json_encode(
            array_map(static fn(Permission $p) => $p->value, $this->cachedPermissions),
        ), 900);

        return $this->cachedPermissions;
    }

    public function hasRole(Role $role): bool
    {
        return in_array($role, $this->getRoles(), true);
    }

    public function hasAnyRole(Role ...$roles): bool
    {
        foreach ($roles as $role) {
            if ($this->hasRole($role)) {
                return true;
            }
        }
        return false;
    }

    public function hasPermission(Permission $permission): bool
    {
        // Super Admin has every permission
        if ($this->hasRole(Role::SUPER_ADMIN)) {
            return true;
        }

        return in_array($permission, $this->getPermissions(), true);
    }

    /**
     * Full authorization check including ABAC context.
     */
    public function can(string $resource, string $action, array $context = []): bool
    {
        return Authorization::getInstance()->checkPermission(
            userId: $this->id,
            resource: $resource,
            action: $action,
            context: $context,
            user: $this,
        );
    }

    public function cannot(string $resource, string $action, array $context = []): bool
    {
        return !$this->can($resource, $action, $context);
    }

    /**
     * Invalidate cached roles/permissions (call after role grant/revoke).
     */
    public function flushAuthCache(): void
    {
        $this->cachedRoles = null;
        $this->cachedPermissions = null;
        $this->redisDelete("user:{$this->id}:roles");
        $this->redisDelete("user:{$this->id}:permissions");
    }

    // ──────────────────────────────────────────────────────────────
    // Redis helpers — thin wrappers; override in test doubles
    // ──────────────────────────────────────────────────────────────

    protected function redisGet(string $key): ?string
    {
        if (!class_exists(\Redis::class)) {
            return null;
        }

        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            $value = $redis->get($key);
            return $value === false ? null : $value;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function redisSet(string $key, string $value, int $ttl): void
    {
        if (!class_exists(\Redis::class)) {
            return;
        }

        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            $redis->setex($key, $ttl, $value);
        } catch (\Throwable) {
            // Silently degrade; DB is authoritative
        }
    }

    protected function redisDelete(string $key): void
    {
        if (!class_exists(\Redis::class)) {
            return;
        }

        try {
            $redis = new \Redis();
            $redis->connect('127.0.0.1', 6379);
            $redis->del($key);
        } catch (\Throwable) {
            // Silently degrade
        }
    }
}
