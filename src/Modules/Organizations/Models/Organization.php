<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

class Organization
{
    public const TYPE_INDIVIDUAL_BUYER   = 'individual_buyer';
    public const TYPE_BUSINESS_BUYER     = 'business_buyer';
    public const TYPE_SELLER             = 'seller';
    public const TYPE_INSPECTION_PARTNER = 'inspection_partner';
    public const TYPE_PLATFORM           = 'platform';

    public const VALID_TYPES = [
        self::TYPE_INDIVIDUAL_BUYER,
        self::TYPE_BUSINESS_BUYER,
        self::TYPE_SELLER,
        self::TYPE_INSPECTION_PARTNER,
        self::TYPE_PLATFORM,
    ];

    public const VERIFICATION_UNVERIFIED     = 'unverified';
    public const VERIFICATION_PENDING_REVIEW = 'pending_review';
    public const VERIFICATION_VERIFIED       = 'verified';
    public const VERIFICATION_REJECTED       = 'rejected';
    public const VERIFICATION_SUSPENDED      = 'suspended';

    public const VALID_VERIFICATION_STATUSES = [
        self::VERIFICATION_UNVERIFIED,
        self::VERIFICATION_PENDING_REVIEW,
        self::VERIFICATION_VERIFIED,
        self::VERIFICATION_REJECTED,
        self::VERIFICATION_SUSPENDED,
    ];

    private const TABLE = 'organizations';

    public function __construct(private readonly Connection $connection) {}

    public function findById(int $id): ?array
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('id', $id)
            ->where('deleted_at', null)
            ->first();
    }

    public function findByIdIncludingDeleted(int $id): ?array
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('id', $id)
            ->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByType(string $type, int $limit = 50, int $offset = 0): array
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('type', $type)
            ->where('deleted_at', null)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset)
            ->get();
    }

    public function findByLegalName(string $legalName): ?array
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('legal_name', $legalName)
            ->where('deleted_at', null)
            ->first();
    }

    public function findByTaxIdRef(string $taxIdRef): ?array
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('tax_id_ref', $taxIdRef)
            ->where('deleted_at', null)
            ->first();
    }

    /**
     * Insert a new organization and return its id.
     */
    public function create(array $data): int
    {
        $qb = new QueryBuilder($this->connection);
        $qb->table(self::TABLE)->insert([
            'type'                => $data['type'],
            'legal_name'          => $data['legal_name'],
            'tax_id_ref'          => $data['tax_id_ref'] ?? null,
            'verification_status' => $data['verification_status'] ?? self::VERIFICATION_UNVERIFIED,
        ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Update mutable fields on an organization.
     * Returns number of affected rows.
     */
    public function update(int $id, array $data): int
    {
        $allowed = ['legal_name', 'tax_id_ref', 'verification_status', 'type'];
        $updateData = array_intersect_key($data, array_flip($allowed));

        if (empty($updateData)) {
            return 0;
        }

        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('id', $id)
            ->where('deleted_at', null)
            ->update($updateData);
    }

    /**
     * Soft-delete an organization.
     */
    public function softDelete(int $id): int
    {
        $qb = new QueryBuilder($this->connection);
        return $qb->table(self::TABLE)
            ->where('id', $id)
            ->where('deleted_at', null)
            ->update(['deleted_at' => date('Y-m-d H:i:s')]);
    }

    public function count(array $filters = []): int
    {
        $qb = new QueryBuilder($this->connection);
        $qb->table(self::TABLE)->where('deleted_at', null);

        if (!empty($filters['type'])) {
            $qb->where('type', $filters['type']);
        }

        if (!empty($filters['verification_status'])) {
            $qb->where('verification_status', $filters['verification_status']);
        }

        return $qb->count();
    }
}
