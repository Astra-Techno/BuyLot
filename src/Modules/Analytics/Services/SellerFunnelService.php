<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Core\Database\Connection;

/**
 * SellerFunnelService — seller-side funnel from invitation through settlement.
 *
 * Funnel stages (each is a count of distinct seller_profiles.id unless noted):
 *   invited → bid_submitted → qualified → negotiated → awarded → fulfilled → settled
 *
 * Filters: date_from / date_to (applied to the stage's primary timestamp),
 *          category_id (via rfqs → demand_groups → product_variants → products).
 */
readonly class SellerFunnelService
{
    public function __construct(private Connection $db) {}

    /**
     * @param string|null $dateFrom  ISO-8601 date, inclusive lower bound
     * @param string|null $dateTo    ISO-8601 date, inclusive upper bound
     * @param int|null    $categoryId  categories.id
     * @return array<string, int>
     */
    public function getFunnel(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
    ): array {
        return [
            'invited'       => $this->countInvited($dateFrom, $dateTo, $categoryId),
            'bid_submitted' => $this->countBidSubmitted($dateFrom, $dateTo, $categoryId),
            'qualified'     => $this->countQualified($dateFrom, $dateTo, $categoryId),
            'negotiated'    => $this->countNegotiated($dateFrom, $dateTo, $categoryId),
            'awarded'       => $this->countAwarded($dateFrom, $dateTo, $categoryId),
            'fulfilled'     => $this->countFulfilled($dateFrom, $dateTo, $categoryId),
            'settled'       => $this->countSettled($dateFrom, $dateTo, $categoryId),
        ];
    }

    // -------------------------------------------------------------------------
    // Stage implementations
    // -------------------------------------------------------------------------

    private function countInvited(?string $dateFrom, ?string $dateTo, ?int $categoryId): int
    {
        [$where, $params] = $this->buildBaseFilter('rsi.invited_at', $dateFrom, $dateTo, $categoryId);

        $sql = "
            SELECT COUNT(DISTINCT rsi.seller_id)
            FROM   rfq_seller_invitations rsi
            JOIN   rfqs r ON r.id = rsi.rfq_id
            {$this->categoryJoin($categoryId)}
            WHERE  1=1
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    private function countBidSubmitted(?string $dateFrom, ?string $dateTo, ?int $categoryId): int
    {
        [$where, $params] = $this->buildBaseFilter('sb.created_at', $dateFrom, $dateTo, $categoryId);

        $sql = "
            SELECT COUNT(DISTINCT sb.seller_id)
            FROM   seller_bids sb
            JOIN   rfqs r ON r.id = sb.rfq_id
            {$this->categoryJoin($categoryId)}
            WHERE  sb.status NOT IN ('withdrawn', 'disqualified')
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    private function countQualified(?string $dateFrom, ?string $dateTo, ?int $categoryId): int
    {
        // "Qualified" = bid that survived screening (active or later awarded/expired).
        [$where, $params] = $this->buildBaseFilter('sb.created_at', $dateFrom, $dateTo, $categoryId);

        $sql = "
            SELECT COUNT(DISTINCT sb.seller_id)
            FROM   seller_bids sb
            JOIN   rfqs r ON r.id = sb.rfq_id
            {$this->categoryJoin($categoryId)}
            WHERE  sb.status IN ('active', 'awarded', 'expired')
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    private function countNegotiated(?string $dateFrom, ?string $dateTo, ?int $categoryId): int
    {
        // "Negotiated" = seller appeared in at least one negotiation_event.
        [$where, $params] = $this->buildBaseFilter('ne.created_at', $dateFrom, $dateTo, $categoryId);

        $sql = "
            SELECT COUNT(DISTINCT sb.seller_id)
            FROM   negotiation_events ne
            JOIN   seller_bids sb ON sb.id = ne.bid_id
            JOIN   rfqs r ON r.id = sb.rfq_id
            {$this->categoryJoin($categoryId)}
            WHERE  ne.bid_id IS NOT NULL
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    private function countAwarded(?string $dateFrom, ?string $dateTo, ?int $categoryId): int
    {
        [$where, $params] = $this->buildBaseFilter('sb.updated_at', $dateFrom, $dateTo, $categoryId);

        $sql = "
            SELECT COUNT(DISTINCT sb.seller_id)
            FROM   seller_bids sb
            JOIN   rfqs r ON r.id = sb.rfq_id
            {$this->categoryJoin($categoryId)}
            WHERE  sb.status = 'awarded'
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    private function countFulfilled(?string $dateFrom, ?string $dateTo, ?int $categoryId): int
    {
        [$where, $params] = $this->buildBaseFilter('sfo.updated_at', $dateFrom, $dateTo, $categoryId);

        $sql = "
            SELECT COUNT(DISTINCT sfo.seller_id)
            FROM   seller_fulfillment_orders sfo
            JOIN   negotiated_offers no ON no.id = sfo.offer_id
            JOIN   demand_groups dg     ON dg.id = no.group_id
            {$this->categoryJoinDg($categoryId)}
            WHERE  sfo.status IN ('completed')
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    private function countSettled(?string $dateFrom, ?string $dateTo, ?int $categoryId): int
    {
        [$where, $params] = $this->buildBaseFilter('ss.updated_at', $dateFrom, $dateTo, $categoryId);

        // Category filter joins through settlements → (no direct path without
        // order_items); we join via seller_id to rfq path when category is set.
        $categoryExtra = '';
        if ($categoryId !== null) {
            $categoryExtra = "
            JOIN seller_bids sb_cat     ON sb_cat.seller_id = ss.seller_id
            JOIN rfqs r_cat             ON r_cat.id = sb_cat.rfq_id
            JOIN demand_groups dg_cat   ON dg_cat.id = r_cat.group_id
            JOIN product_variants pv_cat ON pv_cat.id = dg_cat.variant_id
            JOIN products p_cat          ON p_cat.id  = pv_cat.product_id
                                        AND p_cat.category_id = :category_id
            ";
        }

        $sql = "
            SELECT COUNT(DISTINCT ss.seller_id)
            FROM   seller_settlements ss
            {$categoryExtra}
            WHERE  ss.status IN ('completed')
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    // -------------------------------------------------------------------------
    // Join fragments
    // -------------------------------------------------------------------------

    /** Category filter through rfqs → demand_groups → product_variants → products */
    private function categoryJoin(?int $categoryId): string
    {
        if ($categoryId === null) {
            return '';
        }

        return "
        JOIN demand_groups     dg ON dg.id = r.group_id
        JOIN product_variants  pv ON pv.id = dg.variant_id
        JOIN products           p ON p.id  = pv.product_id
                                 AND p.category_id = :category_id
        ";
    }

    /** Category filter starting from demand_groups alias 'dg' */
    private function categoryJoinDg(?int $categoryId): string
    {
        if ($categoryId === null) {
            return '';
        }

        return "
        JOIN product_variants pv ON pv.id = dg.variant_id
        JOIN products          p ON p.id  = pv.product_id
                                AND p.category_id = :category_id
        ";
    }

    // -------------------------------------------------------------------------
    // Filter builder
    // -------------------------------------------------------------------------

    /**
     * @return array{string, array<string, mixed>}
     */
    private function buildBaseFilter(
        string  $timestampColumn,
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
    ): array {
        $where  = '';
        $params = [];

        if ($dateFrom !== null) {
            $where             .= " AND {$timestampColumn} >= :date_from";
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where           .= " AND {$timestampColumn} <= :date_to";
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        if ($categoryId !== null) {
            $params[':category_id'] = $categoryId;
        }

        return [$where, $params];
    }

    private function scalar(string $sql, array $params): mixed
    {
        $rows = $this->db->query($sql, $params);
        return reset($rows[0] ?? [false]);
    }
}
