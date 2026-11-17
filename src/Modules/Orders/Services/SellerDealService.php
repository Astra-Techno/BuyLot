<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use App\Core\Exception\HttpException;

/**
 * SellerDealService -- Seller-led deals (spec 3.2).
 *
 * Sellers can publish inventory directly with tier-based pricing and quantity thresholds.
 * Buyers join deals and the same MOQ logic applies.
 */
class SellerDealService
{
    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Deal Management
    // -------------------------------------------------------------------------

    /**
     * Create a seller-led deal.
     *
     * @param int    $sellerId   seller_profiles.id
     * @param int    $variantId  product_variants.id
     * @param array  $tiers      [{min_qty: int, unit_price_paise: int}, ...]
     * @param string $deadline   ISO datetime for deal expiry
     * @param array  $terms      Arbitrary terms snapshot
     *
     * @return array The created offer
     */
    public function createDeal(int $sellerId, int $variantId, array $tiers, string $deadline, array $terms = []): array
    {
        if (empty($tiers)) {
            throw new HttpException('At least one price tier is required', 400);
        }

        // Validate tiers
        foreach ($tiers as $tier) {
            if (!isset($tier['min_qty'], $tier['unit_price_paise'])) {
                throw new HttpException('Each tier must have min_qty and unit_price_paise', 400);
            }
            if ((int) $tier['min_qty'] <= 0) {
                throw new HttpException('Tier min_qty must be positive', 400);
            }
            if ((int) $tier['unit_price_paise'] < 0) {
                throw new HttpException('Tier unit_price_paise must be non-negative', 400);
            }
        }

        // Validate deadline is in the future
        if ($deadline <= gmdate('Y-m-d H:i:s')) {
            throw new HttpException('Deal deadline must be in the future', 400);
        }

        $this->connection->beginTransaction();

        try {
            $now           = gmdate('Y-m-d H:i:s');
            $termsJson     = json_encode($terms, JSON_THROW_ON_ERROR);
            $termsHash     = hash('sha256', $termsJson);

            // 1. Create or find demand group for this variant + seller deal
            $matchKey = 'seller_deal_' . $sellerId . '_' . $variantId . '_' . md5($deadline);

            $existingGroup = (new QueryBuilder($this->connection))
                ->table('demand_groups')
                ->select('id')
                ->where('match_key', $matchKey)
                ->first();

            if ($existingGroup !== null) {
                $groupId = (int) $existingGroup['id'];
            } else {
                (new QueryBuilder($this->connection))
                    ->table('demand_groups')
                    ->insert([
                        'match_key'  => $matchKey,
                        'region_id'  => 0,  // Seller deals may not be region-specific
                        'variant_id' => $variantId,
                        'status'     => 'offer_live',
                        'closes_at'  => $deadline,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                $groupId = (int) $this->connection->lastInsertId();
            }

            // 2. Create negotiated offer
            (new QueryBuilder($this->connection))
                ->table('negotiated_offers')
                ->insert([
                    'group_id'            => $groupId,
                    'seller_id'           => $sellerId,
                    'status'              => 'published',
                    'version'             => 1,
                    'expires_at'          => $deadline,
                    'terms_snapshot_json'  => $termsJson,
                    'created_by'          => null,
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);
            $offerId = (int) $this->connection->lastInsertId();

            // 3. Create price tiers
            foreach ($tiers as $tier) {
                (new QueryBuilder($this->connection))
                    ->table('offer_price_tiers')
                    ->insert([
                        'offer_id'         => $offerId,
                        'min_paid_qty'     => (int) $tier['min_qty'],
                        'max_paid_qty'     => isset($tier['max_qty']) ? (int) $tier['max_qty'] : null,
                        'unit_price_paise' => (int) $tier['unit_price_paise'],
                        'created_at'       => $now,
                        'updated_at'       => $now,
                    ]);
            }

            $this->connection->commit();

            return $this->getDeal($offerId) ?? throw new HttpException('Deal creation failed', 500);
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Update a draft/published deal (seller can edit before buyers commit).
     *
     * @param array{tiers?: array, deadline?: string, terms?: array} $updates
     */
    public function updateDeal(int $dealId, int $sellerId, array $updates): array
    {
        $deal = $this->getDeal($dealId);

        if ($deal === null) {
            throw new HttpException('Deal not found', 404);
        }

        if ((int) $deal['seller_id'] !== $sellerId) {
            throw new HttpException('Deal does not belong to this seller', 403);
        }

        // Can only update if still in draft or published with no acceptances
        if (!in_array($deal['status'], ['draft', 'published'], true)) {
            throw new HttpException('Deal cannot be edited in status: ' . $deal['status'], 409);
        }

        // Check no acceptances exist
        $acceptanceCount = (new QueryBuilder($this->connection))
            ->table('offer_acceptances')
            ->where('offer_id', $dealId)
            ->count();

        if ($acceptanceCount > 0) {
            throw new HttpException('Deal cannot be edited after buyers have accepted', 409);
        }

        $this->connection->beginTransaction();

        try {
            $now = gmdate('Y-m-d H:i:s');

            if (isset($updates['deadline'])) {
                if ($updates['deadline'] <= $now) {
                    throw new HttpException('Deadline must be in the future', 400);
                }

                $this->connection->execute(
                    'UPDATE negotiated_offers SET expires_at = ?, version = version + 1, updated_at = NOW() WHERE id = ?',
                    [$updates['deadline'], $dealId],
                );

                $this->connection->execute(
                    'UPDATE demand_groups SET closes_at = ?, updated_at = NOW() WHERE id = ?',
                    [$updates['deadline'], (int) $deal['group_id']],
                );
            }

            if (isset($updates['terms'])) {
                $termsJson = json_encode($updates['terms'], JSON_THROW_ON_ERROR);
                $this->connection->execute(
                    'UPDATE negotiated_offers SET terms_snapshot_json = ?, version = version + 1, updated_at = NOW() WHERE id = ?',
                    [$termsJson, $dealId],
                );
            }

            if (isset($updates['tiers']) && is_array($updates['tiers'])) {
                // Replace all tiers
                $this->connection->execute(
                    'DELETE FROM offer_price_tiers WHERE offer_id = ?',
                    [$dealId],
                );

                foreach ($updates['tiers'] as $tier) {
                    (new QueryBuilder($this->connection))
                        ->table('offer_price_tiers')
                        ->insert([
                            'offer_id'         => $dealId,
                            'min_paid_qty'     => (int) $tier['min_qty'],
                            'max_paid_qty'     => isset($tier['max_qty']) ? (int) $tier['max_qty'] : null,
                            'unit_price_paise' => (int) $tier['unit_price_paise'],
                            'created_at'       => $now,
                            'updated_at'       => $now,
                        ]);
                }
            }

            $this->connection->commit();

            return $this->getDeal($dealId) ?? throw new HttpException('Deal not found after update', 500);
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    /**
     * Get a deal with its price tiers.
     */
    public function getDeal(int $dealId): ?array
    {
        $deal = (new QueryBuilder($this->connection))
            ->table('negotiated_offers')
            ->select('id', 'group_id', 'seller_id', 'status', 'expires_at', 'terms_snapshot_json', 'version', 'created_at', 'updated_at')
            ->where('id', $dealId)
            ->first();

        if ($deal === null) {
            return null;
        }

        // Attach price tiers
        $deal['tiers'] = (new QueryBuilder($this->connection))
            ->table('offer_price_tiers')
            ->select('id', 'min_paid_qty', 'max_paid_qty', 'unit_price_paise')
            ->where('offer_id', $dealId)
            ->orderBy('min_paid_qty', 'ASC')
            ->get();

        return $deal;
    }

    /**
     * List deals for a seller with pagination.
     *
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listBySeller(int $sellerId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table('negotiated_offers')
            ->select('id', 'group_id', 'seller_id', 'status', 'expires_at', 'version', 'created_at', 'updated_at')
            ->where('seller_id', $sellerId);

        $total = (clone $qb)->count();

        $deals = $qb
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        // Attach tiers to each deal
        foreach ($deals as &$deal) {
            $deal['tiers'] = (new QueryBuilder($this->connection))
                ->table('offer_price_tiers')
                ->select('id', 'min_paid_qty', 'max_paid_qty', 'unit_price_paise')
                ->where('offer_id', (int) $deal['id'])
                ->orderBy('min_paid_qty', 'ASC')
                ->get();
        }
        unset($deal);

        return [
            'data'     => $deals,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }
}
