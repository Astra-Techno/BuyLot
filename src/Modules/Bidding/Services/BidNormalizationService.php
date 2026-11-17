<?php

declare(strict_types=1);

namespace App\Modules\Bidding\Services;

use App\Core\Database\Connection;
use App\Modules\Bidding\Models\SellerBid;
use App\Modules\Bidding\Models\SellerBidRevision;

/**
 * BidNormalizationService -- INTERNAL ONLY. Confidential bid comparison logic.
 *
 * SEALED BID CONFIDENTIALITY IS NON-NEGOTIABLE.
 * This service is NEVER exposed to sellers or buyers.
 * It is used exclusively by internal procurement staff via BidComparisonController.
 *
 * Responsibilities:
 *   - Normalize landed cost: unit_price + tax + freight + installation
 *   - Compare all bids for an RFQ with normalized metrics
 *   - Compute weighted scores using configurable weights
 */
class BidNormalizationService
{
    // Configurable scoring weights (summing to 1.0).
    // In production, these would come from a configuration table or service.
    private const DEFAULT_WEIGHTS = [
        'price'    => 0.40,
        'stock'    => 0.15,
        'history'  => 0.15,
        'warranty' => 0.15,
        'delivery' => 0.15,
    ];

    // Tax rate lookup for landed cost calculation (percentage as integer basis points)
    private const TAX_RATES = [
        'GST_0_PERCENT'   => 0,
        'GST_5_PERCENT'   => 500,     // 5.00%
        'GST_12_PERCENT'  => 1200,    // 12.00%
        'GST_18_PERCENT'  => 1800,    // 18.00%
        'GST_28_PERCENT'  => 2800,    // 28.00%
        'EXEMPT'          => 0,
    ];

    public function __construct(
        private readonly Connection        $connection,
        private readonly SellerBid         $bidModel,
        private readonly SellerBidRevision $revisionModel,
    ) {}

    /**
     * Normalize landed cost for a single revision.
     *
     * Landed cost = unit_price + tax_amount + freight + installation
     * All amounts in paise (integer).
     *
     * @return array{
     *   unit_price_paise: int,
     *   tax_paise: int,
     *   freight_paise: int,
     *   installation_paise: int,
     *   landed_cost_paise: int,
     *   tax_rate_bps: int,
     * }
     */
    public function normalizeLandedCost(array $revision): array
    {
        $unitPrice   = (int) $revision['unit_price_paise'];
        $freightPer  = (int) ($revision['freight_paise'] ?? 0);
        $taxBasis    = (string) ($revision['tax_basis'] ?? 'EXEMPT');

        // Calculate tax amount
        $taxRateBps = self::TAX_RATES[$taxBasis] ?? 0;
        $taxPaise   = (int) floor($unitPrice * $taxRateBps / 10000);

        // Installation cost (from warranty_snapshot if present)
        $installPaise = 0;
        if (!empty($revision['warranty_snapshot'])) {
            $warranty = is_string($revision['warranty_snapshot'])
                ? json_decode($revision['warranty_snapshot'], true)
                : $revision['warranty_snapshot'];

            if (is_array($warranty) && isset($warranty['installation_paise'])) {
                $installPaise = (int) $warranty['installation_paise'];
            }
        }

        $landedCost = $unitPrice + $taxPaise + $freightPer + $installPaise;

        return [
            'unit_price_paise'    => $unitPrice,
            'tax_paise'           => $taxPaise,
            'freight_paise'       => $freightPer,
            'installation_paise'  => $installPaise,
            'landed_cost_paise'   => $landedCost,
            'tax_rate_bps'        => $taxRateBps,
        ];
    }

    /**
     * Compare all bids for an RFQ with normalized landed costs and weighted scores.
     *
     * INTERNAL ONLY. NEVER exposed to sellers or buyers.
     *
     * @return array<array{
     *   bid_id: int,
     *   seller_id: int,
     *   revision_no: int,
     *   landed_cost: array,
     *   scores: array,
     *   weighted_total: float,
     * }>
     */
    public function compareBids(int $rfqId): array
    {
        $bids = $this->bidModel->listByRfqInternal($rfqId);

        if (empty($bids)) {
            return [];
        }

        $results = [];

        foreach ($bids as $bid) {
            $latestRevisionId = $bid['latest_revision_id'] ?? null;

            if ($latestRevisionId === null) {
                continue; // No revision submitted yet
            }

            $revision = $this->revisionModel->find((int) $latestRevisionId);
            if ($revision === null) {
                continue;
            }

            $landedCost = $this->normalizeLandedCost($revision);
            $scores     = $this->weightedScore($revision, $landedCost);

            $results[] = [
                'bid_id'          => (int) $bid['id'],
                'seller_id'       => (int) $bid['seller_id'],
                'status'          => $bid['status'],
                'revision_no'     => (int) $revision['revision_no'],
                'unit_price_paise'=> (int) $revision['unit_price_paise'],
                'moq'             => (int) $revision['moq'],
                'max_qty'         => $revision['max_qty'] !== null ? (int) $revision['max_qty'] : null,
                'lead_time_days'  => (int) $revision['lead_time_days'],
                'valid_until'     => $revision['valid_until'],
                'landed_cost'     => $landedCost,
                'scores'          => $scores['components'],
                'weighted_total'  => $scores['total'],
            ];
        }

        // Sort by weighted total score descending (higher = better)
        usort($results, static fn(array $a, array $b) => $b['weighted_total'] <=> $a['weighted_total']);

        // Add rank
        foreach ($results as $i => &$result) {
            $result['rank'] = $i + 1;
        }
        unset($result);

        return $results;
    }

    /**
     * Calculate weighted score for a revision.
     *
     * Scores are normalized to 0.0-1.0 range, then multiplied by configurable weights.
     * Higher score = better for the platform.
     *
     * INTERNAL ONLY. NEVER exposed to sellers or buyers.
     *
     * @param array $revision The bid revision data
     * @param array $landedCost Pre-computed landed cost breakdown
     * @param array $weights Override default weights (must sum to 1.0)
     *
     * @return array{components: array<string, float>, total: float}
     */
    public function weightedScore(
        array $revision,
        array $landedCost,
        array $weights = [],
    ): array {
        $w = array_merge(self::DEFAULT_WEIGHTS, $weights);

        // --- Price score (inverse: lower price = higher score) ---
        // Normalized using a reference max of 10,00,000 paise (Rs 10,000).
        // In production, use the median/max of the bid set for normalization.
        $maxRefPrice = 1_000_000;
        $priceScore  = max(0.0, min(1.0, 1.0 - ($landedCost['landed_cost_paise'] / $maxRefPrice)));

        // --- Stock/capacity score (higher max_qty relative to moq = better) ---
        $moq     = max(1, (int) $revision['moq']);
        $maxQty  = $revision['max_qty'] !== null ? (int) $revision['max_qty'] : $moq;
        $stockScore = min(1.0, $maxQty / max(1, $moq * 10));

        // --- Delivery score (inverse: fewer days = higher score) ---
        $leadDays     = max(1, (int) $revision['lead_time_days']);
        $deliveryScore = max(0.0, min(1.0, 1.0 - ($leadDays / 90)));

        // --- Warranty score (has warranty data = better) ---
        $warrantyScore = 0.0;
        if (!empty($revision['warranty_snapshot'])) {
            $warranty = is_string($revision['warranty_snapshot'])
                ? json_decode($revision['warranty_snapshot'], true)
                : $revision['warranty_snapshot'];

            if (is_array($warranty)) {
                $months = (int) ($warranty['months'] ?? 0);
                $warrantyScore = min(1.0, $months / 24); // 24 months = max score
            }
        }

        // --- History score (placeholder: seller reliability score) ---
        // In production, this would be fetched from seller_profiles.reliability_score
        $historyScore = 0.5; // Default neutral score

        $components = [
            'price'    => round($priceScore, 4),
            'stock'    => round($stockScore, 4),
            'delivery' => round($deliveryScore, 4),
            'warranty' => round($warrantyScore, 4),
            'history'  => round($historyScore, 4),
        ];

        $total = 0.0;
        foreach ($components as $key => $score) {
            $total += $score * ($w[$key] ?? 0.0);
        }

        return [
            'components' => $components,
            'total'      => round($total, 4),
        ];
    }
}
