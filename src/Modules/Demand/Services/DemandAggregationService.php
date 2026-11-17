<?php

declare(strict_types=1);

namespace App\Modules\Demand\Services;

use App\Core\Database\Connection;
use App\Modules\Demand\Models\BuyerPricePreference;
use App\Modules\Demand\Models\DemandGroup;
use App\Modules\Demand\Models\GroupMembership;

/**
 * Demand aggregation and statistics.
 *
 * CRITICAL PRIVACY RULE:
 *   Individual buyer budgets (max_paise) MUST NEVER be exposed to sellers.
 *   getDemandCurve() is for internal/procurement use only.
 *   getGroupStats() returns only aggregate counts -- safe for public consumption.
 */
class DemandAggregationService
{
    public function __construct(
        private Connection           $connection,
        private DemandGroup          $demandGroupModel,
        private GroupMembership      $membershipModel,
        private BuyerPricePreference $pricePreferenceModel,
    ) {}

    /**
     * Get privacy-safe aggregate stats for a demand group.
     *
     * Returns only counts and quantities -- no price data, no buyer identities.
     * Safe for public/seller-facing endpoints.
     *
     * @return array{
     *     group_id: int,
     *     total_members: int,
     *     total_qty: int,
     *     interested_qty: int,
     *     ready_qty: int,
     *     accepted_qty: int,
     *     paid_qty: int,
     *     released_qty: int,
     *     levels: array<int, array{level: int, member_count: int, total_qty: int}>
     * }
     */
    public function getGroupStats(int $groupId): array
    {
        $group = $this->demandGroupModel->find($groupId);

        if ($group === null) {
            throw new \InvalidArgumentException("Demand group {$groupId} not found");
        }

        $stats  = $this->demandGroupModel->getStats($groupId);
        $levels = $this->membershipModel->countByLevel($groupId);

        return [
            'group_id'       => $groupId,
            'status'         => $group['status'],
            'total_members'  => $stats['total_members'],
            'total_qty'      => $stats['total_qty'],
            'interested_qty' => $stats['interested_qty'],
            'ready_qty'      => $stats['ready_qty'],
            'accepted_qty'   => $stats['accepted_qty'],
            'paid_qty'       => $stats['paid_qty'],
            'released_qty'   => $stats['released_qty'],
            'levels'         => $levels,
        ];
    }

    /**
     * Get the demand curve for a group: buyers at each price threshold.
     *
     * INTERNAL USE ONLY -- procurement staff.
     * NEVER expose to sellers or public endpoints.
     *
     * Returns how many buyers (and what quantity) are willing to buy at each
     * price bucket. Uses target_paise from BuyerPricePreference, respecting
     * visibility_scope.
     *
     * @return array{
     *     group_id: int,
     *     total_members: int,
     *     price_buckets: array<int, array{threshold_paise: int, buyers: int, qty: int}>,
     *     aggregate: array{count: int, avg_target_paise: ?float, min_target_paise: ?int, max_target_paise: ?int}
     * }
     */
    public function getDemandCurve(int $groupId): array
    {
        $group = $this->demandGroupModel->find($groupId);

        if ($group === null) {
            throw new \InvalidArgumentException("Demand group {$groupId} not found");
        }

        // Get all memberships for this group
        $members = $this->membershipModel->getMembers($groupId);

        if (empty($members)) {
            return [
                'group_id'      => $groupId,
                'total_members' => 0,
                'price_buckets' => [],
                'aggregate'     => [
                    'count'            => 0,
                    'avg_target_paise' => null,
                    'min_target_paise' => null,
                    'max_target_paise' => null,
                ],
            ];
        }

        // Collect request IDs for aggregate price stats
        $requestIds = array_filter(
            array_map(static fn(array $m): ?int => $m['request_id'] !== null ? (int) $m['request_id'] : null, $members),
        );

        $aggregate = $this->pricePreferenceModel->getAggregateStats($requestIds);

        // Build price buckets from individual procurement-visible preferences
        $priceBuckets = $this->buildPriceBuckets($members);

        return [
            'group_id'      => $groupId,
            'total_members' => count($members),
            'price_buckets' => $priceBuckets,
            'aggregate'     => $aggregate,
        ];
    }

    /**
     * Get full group detail including price aggregates for procurement staff.
     *
     * INTERNAL USE ONLY -- never expose to sellers.
     *
     * @return array{
     *     group: array,
     *     stats: array,
     *     aggregate_prices: array
     * }
     */
    public function getGroupDetail(int $groupId): array
    {
        $group = $this->demandGroupModel->find($groupId);

        if ($group === null) {
            throw new \InvalidArgumentException("Demand group {$groupId} not found");
        }

        $stats   = $this->getGroupStats($groupId);
        $members = $this->membershipModel->getMembers($groupId);

        $requestIds = array_filter(
            array_map(static fn(array $m): ?int => $m['request_id'] !== null ? (int) $m['request_id'] : null, $members),
        );

        $aggregate = $this->pricePreferenceModel->getAggregateStats($requestIds);

        return [
            'group'            => $group,
            'stats'            => $stats,
            'aggregate_prices' => $aggregate,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build price-quantity buckets from procurement-visible preferences.
     *
     * Groups buyers into price thresholds (rounded to nearest 100 paise)
     * showing how many buyers and what quantity exist at each price point.
     *
     * @return array<int, array{threshold_paise: int, buyers: int, qty: int}>
     */
    private function buildPriceBuckets(array $members): array
    {
        $buckets = [];

        foreach ($members as $member) {
            if ($member['request_id'] === null) {
                continue;
            }

            $pref = $this->pricePreferenceModel->findByRequestForProcurement((int) $member['request_id']);

            if ($pref === null || $pref['target_paise'] === null) {
                continue;
            }

            // Round to nearest 100 paise bucket
            $bucket = (int) (round((int) $pref['target_paise'] / 100) * 100);

            if (!isset($buckets[$bucket])) {
                $buckets[$bucket] = ['threshold_paise' => $bucket, 'buyers' => 0, 'qty' => 0];
            }

            $buckets[$bucket]['buyers']++;
            $buckets[$bucket]['qty'] += (int) $member['qty'];
        }

        // Sort by price ascending
        ksort($buckets);

        return array_values($buckets);
    }
}
