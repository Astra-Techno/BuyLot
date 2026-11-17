<?php

declare(strict_types=1);

namespace App\Modules\Offers\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * NegotiatedOffer model.
 *
 * Table: negotiated_offers
 * States: draft, pending_approval, approved, published, moq_met, moq_failed, expired, cancelled
 * Optimistic locking via `version` column.
 * terms_snapshot_json is IMMUTABLE after publish.
 */
class NegotiatedOffer
{
    private const TABLE = 'negotiated_offers';

    private const PUBLIC_COLUMNS = [
        'id', 'group_id', 'seller_id', 'source_bid_revision_id',
        'status', 'version', 'expires_at', 'terms_snapshot_json',
        'created_by', 'created_at', 'updated_at',
    ];

    /** Valid status transitions: from => [allowed to states] */
    private const TRANSITIONS = [
        'draft'            => ['pending_approval', 'cancelled'],
        'pending_approval' => ['approved', 'draft', 'cancelled'],
        'approved'         => ['published', 'cancelled'],
        'published'        => ['published', 'expired', 'cancelled'], // published->published for first acceptance (ACCEPTING concept)
        'moq_met'          => [],
        'moq_failed'       => ['published'], // extend deadline
        'expired'          => [],
        'cancelled'        => [],
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('id', $id)
            ->first();
    }

    public function findByGroupId(int $groupId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('group_id', $groupId)
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    /**
     * Find published/active offers available to buyers.
     */
    public function listAvailable(int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('status', 'published');

        $total = (clone $qb)->count();

        $offers = $qb
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => $offers,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new offer in DRAFT status.
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'group_id'               => $data['group_id'],
                'seller_id'              => $data['seller_id'],
                'source_bid_revision_id' => $data['source_bid_revision_id'] ?? null,
                'status'                 => 'draft',
                'version'                => 1,
                'expires_at'             => $data['expires_at'],
                'terms_snapshot_json'    => $data['terms_snapshot_json'],
                'created_by'             => $data['created_by'],
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Update mutable fields on a DRAFT offer only.
     *
     * @param array<string,mixed> $data Allowed keys: expires_at, terms_snapshot_json
     */
    public function updateDraft(int $id, array $data): bool
    {
        $allowed  = ['expires_at', 'terms_snapshot_json'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (empty($filtered)) {
            return false;
        }

        $filtered['updated_at'] = gmdate('Y-m-d H:i:s');

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('status', 'draft')
            ->update($filtered);

        return $rows > 0;
    }

    /**
     * Transition offer status with optimistic version locking.
     *
     * @return bool True if transition succeeded
     */
    public function transition(int $id, string $fromStatus, string $toStatus, int $currentVersion, array $extraFields = []): bool
    {
        if (!$this->isValidTransition($fromStatus, $toStatus)) {
            return false;
        }

        $update = array_merge($extraFields, [
            'status'     => $toStatus,
            'version'    => $currentVersion + 1,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('status', $fromStatus)
            ->where('version', $currentVersion)
            ->update($update);

        return $rows > 0;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function isValidTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
