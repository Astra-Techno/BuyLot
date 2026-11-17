<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * User model.
 *
 * Table schema (minimum columns assumed):
 *   id           VARCHAR(26)  ULID, PK
 *   mobile       VARCHAR(15)  UNIQUE NOT NULL
 *   name         VARCHAR(100) NULL
 *   email        VARCHAR(255) NULL
 *   locale       VARCHAR(10)  DEFAULT 'en'
 *   status       ENUM('active','suspended','pending_deletion') DEFAULT 'active'
 *   is_verified  TINYINT(1)   DEFAULT 0
 *   created_at   DATETIME     NOT NULL
 *   updated_at   DATETIME     NOT NULL
 *   deleted_at   DATETIME     NULL
 *
 * Roles are stored in a separate `user_roles` table:
 *   user_id  VARCHAR(26)  FK -> users.id
 *   role     VARCHAR(50)  NOT NULL
 */
class User
{
    private const TABLE       = 'users';
    private const ROLES_TABLE = 'user_roles';

    // Columns returned to API consumers (never expose internals)
    private const PUBLIC_COLUMNS = [
        'id', 'mobile', 'name', 'email', 'locale',
        'status', 'is_verified', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(string $id): ?array
    {
        $user = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('id', $id)
            ->where('deleted_at', null)
            ->first();

        if ($user === null) {
            return null;
        }

        return $this->withRoles($user);
    }

    public function findByMobile(string $mobile): ?array
    {
        $user = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('mobile', $mobile)
            ->where('deleted_at', null)
            ->first();

        if ($user === null) {
            return null;
        }

        return $this->withRoles($user);
    }

    /**
     * Paginated list with optional filters.
     *
     * @param array{status?: string, role?: string, search?: string} $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('deleted_at', null);

        if (!empty($filters['status'])) {
            $qb->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $term = '%' . addcslashes($filters['search'], '%_') . '%';
            $qb->where('name', 'LIKE', $term)
               ->orWhere('mobile', 'LIKE', $term);
        }

        // Clone for count before applying role filter (role filter needs JOIN)
        $total = (clone $qb)->count();

        if (!empty($filters['role'])) {
            $qb->join(
                self::ROLES_TABLE,
                self::TABLE . '.id = ' . self::ROLES_TABLE . '.user_id',
            )->where(self::ROLES_TABLE . '.role', $filters['role']);
        }

        $users = $qb
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        // Attach roles per user
        $users = array_map(fn(array $u) => $this->withRoles($u), $users);

        return [
            'data'     => $users,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new user and assign the default 'buyer' role.
     * Returns the newly-created user (with roles).
     */
    public function create(string $mobile): array
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'mobile'      => $mobile,
                'locale'      => 'en',
                'status'      => 'active',
                'is_verified' => 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

        $id = (string) $this->connection->getPdo()->lastInsertId();

        return $this->find($id) ?? ['id' => $id, 'mobile' => $mobile, 'roles' => []];
    }

    /**
     * Update mutable profile fields.
     *
     * @param array<string,mixed> $data  Allowed keys: name, email, locale
     */
    public function update(string $id, array $data): bool
    {
        $allowed  = ['name', 'email', 'locale'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (empty($filtered)) {
            return false;
        }

        $filtered['updated_at'] = gmdate('Y-m-d H:i:s');

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update($filtered);

        return $rows > 0;
    }

    /**
     * Mark the mobile as OTP-verified and set status to active.
     */
    public function verify(string $id): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'is_verified' => 1,
                'status'      => 'active',
                'updated_at'  => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Soft-delete / mark account for deletion.
     */
    public function softDelete(string $id): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'status'     => 'pending_deletion',
                'deleted_at' => $now,
                'updated_at' => $now,
            ]);

        return $rows > 0;
    }

    /**
     * Update status (active | suspended).
     */
    public function updateStatus(string $id, string $status): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'status'     => $status,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Assign a role to a user (idempotent — skips duplicate).
     */
    public function assignRole(string $userId, string $role): void
    {
        $existing = (new QueryBuilder($this->connection))
            ->table(self::ROLES_TABLE)
            ->where('user_id', $userId)
            ->where('role', $role)
            ->first();

        if ($existing !== null) {
            return; // already assigned
        }

        (new QueryBuilder($this->connection))
            ->table(self::ROLES_TABLE)
            ->insert([
                'user_id' => $userId,
                'role'    => $role,
            ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function withRoles(array $user): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::ROLES_TABLE)
            ->select('role')
            ->where('user_id', $user['id'])
            ->get();

        $user['roles'] = array_column($rows, 'role');

        return $user;
    }

    /**
     * Generate a ULID (Universally Unique Lexicographically Sortable Identifier).
     * 26-character base-32 string: 10 chars timestamp + 16 chars random.
     */
    private function generateUlid(): string
    {
        $t = (int) round(microtime(true) * 1000);

        $encoding = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        $timeChars = '';
        for ($i = 9; $i >= 0; $i--) {
            $timeChars = $encoding[$t % 32] . $timeChars;
            $t         = intdiv($t, 32);
        }

        $randomChars = '';
        for ($i = 0; $i < 16; $i++) {
            $randomChars .= $encoding[random_int(0, 31)];
        }

        return $timeChars . $randomChars;
    }
}
