<?php

declare(strict_types=1);

namespace App\Modules\SupplierVerification\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * SellerVerificationCase model.
 *
 * Maps to: seller_verification_cases
 *   id            BIGINT UNSIGNED PK AUTO_INCREMENT
 *   seller_id     BIGINT UNSIGNED NOT NULL (FK -> seller_profiles.id)
 *   type          VARCHAR(50) NOT NULL     — KYC | GST | BANK | IDENTITY
 *   provider_ref  VARCHAR(255) NULL        — external KYB/KYC provider reference
 *   documents_json JSON NULL               — array of document object-storage refs
 *   result        ENUM('pending','passed','failed','manual_review')
 *   reviewed_by   BIGINT UNSIGNED NULL     — internal compliance user_id
 *   reviewed_at   TIMESTAMP NULL
 *   notes         TEXT NULL
 *   created_at    TIMESTAMP
 *   updated_at    TIMESTAMP
 */
class SellerVerificationCase
{
    private const TABLE = 'seller_verification_cases';

    public const TYPE_KYC      = 'KYC';
    public const TYPE_GST      = 'GST';
    public const TYPE_BANK     = 'BANK';
    public const TYPE_IDENTITY = 'IDENTITY';

    public const ALLOWED_TYPES = [
        self::TYPE_KYC,
        self::TYPE_GST,
        self::TYPE_BANK,
        self::TYPE_IDENTITY,
    ];

    public const RESULT_PENDING       = 'pending';
    public const RESULT_PASSED        = 'passed';
    public const RESULT_FAILED        = 'failed';
    public const RESULT_MANUAL_REVIEW = 'manual_review';

    public const ALLOWED_RESULTS = [
        self::RESULT_PENDING,
        self::RESULT_PASSED,
        self::RESULT_FAILED,
        self::RESULT_MANUAL_REVIEW,
    ];

    public function __construct(private readonly Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->first();

        return $row !== null ? $this->decode($row) : null;
    }

    /**
     * All verification cases for a given seller profile.
     */
    public function listBySeller(int $sellerId): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('seller_id', $sellerId)
            ->orderBy('created_at', 'DESC')
            ->get();

        return array_map(fn(array $r) => $this->decode($r), $rows);
    }

    /**
     * Paginated list of cases awaiting review (result = 'pending' or 'manual_review').
     *
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listPending(int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('result', self::RESULT_PENDING)
            ->orWhere('result', self::RESULT_MANUAL_REVIEW);

        $total = (clone $qb)->count();

        $rows = $qb
            ->orderBy('created_at', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => array_map(fn(array $r) => $this->decode($r), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Open a new verification case for a seller.
     *
     * @param string[] $documentRefs  Object-storage paths / URLs for uploaded docs
     */
    public function create(
        int $sellerId,
        string $type,
        array $documentRefs = [],
        ?string $providerRef = null,
    ): array {
        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid verification case type: {$type}");
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'seller_id'      => $sellerId,
                'type'           => $type,
                'provider_ref'   => $providerRef,
                'documents_json' => json_encode($documentRefs),
                'result'         => self::RESULT_PENDING,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? [
            'id'        => $id,
            'seller_id' => $sellerId,
            'type'      => $type,
            'result'    => self::RESULT_PENDING,
        ];
    }

    /**
     * Record a compliance review decision on a verification case.
     *
     * @param string $result  One of ALLOWED_RESULTS (not 'pending')
     */
    public function review(
        int $id,
        string $result,
        int $reviewedBy,
        ?string $notes = null,
    ): bool {
        if (!in_array($result, self::ALLOWED_RESULTS, true)) {
            throw new \InvalidArgumentException("Invalid result: {$result}");
        }

        if ($result === self::RESULT_PENDING) {
            throw new \InvalidArgumentException("Cannot set result back to pending via review.");
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'result'      => $result,
                'reviewed_by' => $reviewedBy,
                'reviewed_at' => gmdate('Y-m-d H:i:s'),
                'notes'       => $notes,
                'updated_at'  => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function decode(array $row): array
    {
        if (isset($row['documents_json']) && is_string($row['documents_json'])) {
            $row['documents_json'] = json_decode($row['documents_json'], true) ?? [];
        }

        return $row;
    }
}
