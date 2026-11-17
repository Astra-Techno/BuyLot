<?php

declare(strict_types=1);

namespace App\Modules\Offers\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * OfferAcceptance model.
 *
 * Table: offer_acceptances
 * UNIQUE(offer_id, buyer_id) — one acceptance per buyer per offer.
 * accepted_terms_hash is a SHA-256 of the terms_snapshot_json at time of acceptance,
 * ensuring no silent repricing.
 */
class OfferAcceptance
{
    private const TABLE = 'offer_acceptances';

    private const COLUMNS = [
        'id', 'offer_id', 'buyer_id', 'qty',
        'accepted_terms_hash', 'accepted_at', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();
    }

    public function findByOfferAndBuyer(int $offerId, int $buyerId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('offer_id', $offerId)
            ->where('buyer_id', $buyerId)
            ->first();
    }

    public function findByOfferId(int $offerId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('offer_id', $offerId)
            ->orderBy('accepted_at', 'ASC')
            ->get();
    }

    public function findByBuyerId(int $buyerId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('buyer_id', $buyerId);

        $total = (clone $qb)->count();

        $acceptances = $qb
            ->orderBy('accepted_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => $acceptances,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Sum of accepted qty for an offer.
     */
    public function totalAcceptedQty(int $offerId): int
    {
        $result = $this->connection->query(
            'SELECT COALESCE(SUM(qty), 0) as total_qty FROM ' . self::TABLE . ' WHERE offer_id = ?',
            [$offerId],
        );

        return (int) ($result[0]['total_qty'] ?? 0);
    }

    /**
     * Count distinct buyers who accepted an offer.
     */
    public function countAcceptances(int $offerId): int
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('offer_id', $offerId)
            ->count();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create an acceptance record.
     * UNIQUE constraint on (offer_id, buyer_id) prevents duplicates at DB level.
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'offer_id'            => $data['offer_id'],
                'buyer_id'            => $data['buyer_id'],
                'qty'                 => $data['qty'],
                'accepted_terms_hash' => $data['accepted_terms_hash'],
                'accepted_at'         => $now,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }
}
