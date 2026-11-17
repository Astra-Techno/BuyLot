<?php

declare(strict_types=1);

namespace App\Modules\Offers\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * OfferPriceTier model.
 *
 * Table: offer_price_tiers
 * Tiered pricing based on paid quantity. Lower tiers unlock better unit prices.
 * All prices in integer paise (1 INR = 100 paise).
 */
class OfferPriceTier
{
    private const TABLE = 'offer_price_tiers';

    private const COLUMNS = [
        'id', 'offer_id', 'min_paid_qty', 'max_paid_qty',
        'unit_price_paise', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function findByOfferId(int $offerId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('offer_id', $offerId)
            ->orderBy('min_paid_qty', 'ASC')
            ->get();
    }

    /**
     * Find the applicable tier for a given paid quantity.
     * Returns the tier where min_paid_qty <= qty AND (max_paid_qty >= qty OR max_paid_qty IS NULL).
     */
    public function findTierForQty(int $offerId, int $qty): ?array
    {
        $tiers = $this->findByOfferId($offerId);

        $applicable = null;
        foreach ($tiers as $tier) {
            if ($qty >= (int) $tier['min_paid_qty']) {
                $maxQty = $tier['max_paid_qty'];
                if ($maxQty === null || $qty <= (int) $maxQty) {
                    $applicable = $tier;
                    break;
                }
            }
        }

        return $applicable;
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a price tier for an offer.
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'offer_id'         => $data['offer_id'],
                'min_paid_qty'     => $data['min_paid_qty'],
                'max_paid_qty'     => $data['max_paid_qty'] ?? null,
                'unit_price_paise' => $data['unit_price_paise'],
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Replace all tiers for an offer (only allowed while offer is in DRAFT).
     */
    public function replaceForOffer(int $offerId, array $tiers): void
    {
        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('offer_id', $offerId)
            ->delete();

        foreach ($tiers as $tier) {
            $this->create(array_merge($tier, ['offer_id' => $offerId]));
        }
    }

    /**
     * Delete all tiers for an offer.
     */
    public function deleteByOfferId(int $offerId): int
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('offer_id', $offerId)
            ->delete();
    }
}
