<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Core\Database\Connection;

/**
 * CommercialKPIService — commercial health KPIs for the platform.
 *
 * KPIs returned:
 *   completed_gmv_paise        SUM of order_total_paise for completed orders
 *   platform_net_revenue_paise commission + service_fee - gateway_fees
 *                               - procurement_labor - inspection - support
 *                               - refund_loss (from ledger_postings by account code)
 *   contribution_margin        net_revenue_paise / gmv_paise (0–1 ratio, 4 dp)
 *   effective_take_rate        commission_paise / gmv_paise (0–1 ratio, 4 dp)
 *   buyer_repeat_rate          buyers with 2+ completed orders / buyers with any
 *                               completed order (0–1 ratio, 4 dp)
 *   supplier_repeat_rate       sellers with 2+ fulfilled sfo rows / sellers with
 *                               any fulfilled sfo row (0–1 ratio, 4 dp)
 *   refund_dispute_rate        completed orders that have an open/closed dispute /
 *                               total completed orders (0–1 ratio, 4 dp)
 *   on_time_delivery_rate      shipments delivered on or before sla_deadline /
 *                               total shipments with a delivery event (0–1, 4 dp)
 *
 * All money values are integers in paise (1 INR = 100 paise).
 * Filters: date_from / date_to (applied to orders.created_at / shipments.created_at),
 *          category_id (via order_items → product_variants → products),
 *          region_id   (demand_groups.region_id on the order's group).
 */
readonly class CommercialKPIService
{
    public function __construct(private Connection $db) {}

    /**
     * @param string|null $dateFrom   ISO-8601 date, inclusive lower bound
     * @param string|null $dateTo     ISO-8601 date, inclusive upper bound
     * @param int|null    $categoryId categories.id
     * @param int|null    $regionId   demand_groups.region_id / addresses.owner_id
     * @return array<string, int|float>
     */
    public function getKPIs(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?int    $regionId,
    ): array {
        [$orderWhere, $orderParams] = $this->buildOrderFilter(
            $dateFrom, $dateTo, $categoryId, $regionId,
        );

        $gmv        = $this->completedGmv($orderWhere, $orderParams);
        $netRevenue = $this->platformNetRevenue($dateFrom, $dateTo);
        $commission = $this->commissionTotal($dateFrom, $dateTo);

        return [
            'completed_gmv_paise'        => $gmv,
            'platform_net_revenue_paise' => $netRevenue,
            'contribution_margin'        => $gmv > 0
                                                ? round($netRevenue / $gmv, 4)
                                                : 0.0,
            'effective_take_rate'        => $gmv > 0
                                                ? round($commission / $gmv, 4)
                                                : 0.0,
            'buyer_repeat_rate'          => $this->buyerRepeatRate($orderWhere, $orderParams),
            'supplier_repeat_rate'       => $this->supplierRepeatRate($dateFrom, $dateTo, $categoryId),
            'refund_dispute_rate'        => $this->refundDisputeRate($orderWhere, $orderParams),
            'on_time_delivery_rate'      => $this->onTimeDeliveryRate($dateFrom, $dateTo, $categoryId, $regionId),
        ];
    }

    // -------------------------------------------------------------------------
    // Individual KPI methods
    // -------------------------------------------------------------------------

    /** SUM of order_total_paise for all completed orders in the filter window. */
    private function completedGmv(string $orderWhere, array $params): int
    {
        $sql = "
            SELECT COALESCE(SUM(o.order_total_paise), 0)
            FROM   orders o
            {$this->orderJoins($params)}
            WHERE  o.status = 'completed'
               AND o.deleted_at IS NULL
               {$orderWhere}
        ";

        return (int) ($this->scalar($sql, $params) ?? 0);
    }

    /**
     * Net revenue from ledger_postings:
     *   + COMMISSION, + SERVICE_FEE
     *   - GATEWAY_FEE, - PROCUREMENT_LABOR, - INSPECTION, - SUPPORT, - REFUND_LOSS
     *
     * Uses separate date params to avoid collision with order filter params.
     */
    private function platformNetRevenue(?string $dateFrom, ?string $dateTo): int
    {
        [$dateWhere, $params] = $this->buildDateFilter('lp.created_at', $dateFrom, $dateTo);

        $sql = "
            SELECT COALESCE(SUM(
                CASE
                    WHEN la.code IN ('COMMISSION', 'SERVICE_FEE')
                        THEN  lp.credit_paise - lp.debit_paise
                    WHEN la.code IN ('GATEWAY_FEE', 'PROCUREMENT_LABOR',
                                     'INSPECTION',  'SUPPORT', 'REFUND_LOSS')
                        THEN  lp.debit_paise - lp.credit_paise
                    ELSE 0
                END
            ), 0)
            FROM   ledger_postings lp
            JOIN   ledger_accounts la ON la.id = lp.account_id
                                     AND la.code IN (
                                             'COMMISSION', 'SERVICE_FEE',
                                             'GATEWAY_FEE', 'PROCUREMENT_LABOR',
                                             'INSPECTION',  'SUPPORT', 'REFUND_LOSS'
                                         )
            WHERE  1=1
                   {$dateWhere}
        ";

        return (int) ($this->scalar($sql, $params) ?? 0);
    }

    /** SUM of COMMISSION credit postings for the period (used for take-rate). */
    private function commissionTotal(?string $dateFrom, ?string $dateTo): int
    {
        [$dateWhere, $params] = $this->buildDateFilter('lp.created_at', $dateFrom, $dateTo);

        $sql = "
            SELECT COALESCE(SUM(lp.credit_paise), 0)
            FROM   ledger_postings lp
            JOIN   ledger_accounts la ON la.id = lp.account_id
                                     AND la.code = 'COMMISSION'
            WHERE  lp.credit_paise > 0
                   {$dateWhere}
        ";

        return (int) ($this->scalar($sql, $params) ?? 0);
    }

    /**
     * Buyers with 2+ completed orders in window / buyers with any completed order.
     */
    private function buyerRepeatRate(string $orderWhere, array $params): float
    {
        // Count total distinct buyers with at least one completed order
        $totalSql = "
            SELECT COUNT(DISTINCT o.buyer_id)
            FROM   orders o
            {$this->orderJoins($params)}
            WHERE  o.status = 'completed'
               AND o.deleted_at IS NULL
               {$orderWhere}
        ";

        $total = (int) ($this->scalar($totalSql, $params) ?? 0);

        if ($total === 0) {
            return 0.0;
        }

        // Suffix param keys to avoid collision in the same query context
        $repeatParams = $this->rekeySuffix($params, '_rep');

        $repeatOrderWhere = $this->rekeyWhere($orderWhere, '_rep');

        $repeatSql = "
            SELECT COUNT(*) FROM (
                SELECT o.buyer_id
                FROM   orders o
                {$this->orderJoinsKeyed($repeatParams)}
                WHERE  o.status = 'completed'
                   AND o.deleted_at IS NULL
                   {$repeatOrderWhere}
                GROUP BY o.buyer_id
                HAVING COUNT(o.id) >= 2
            ) sub
        ";

        $repeat = (int) ($this->scalar($repeatSql, $repeatParams) ?? 0);

        return round($repeat / $total, 4);
    }

    /**
     * Sellers with 2+ fulfilled seller_fulfillment_orders / sellers with any.
     */
    private function supplierRepeatRate(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
    ): float {
        [$dateWhere, $params] = $this->buildDateFilter('sfo.updated_at', $dateFrom, $dateTo);

        $categoryJoin  = $this->sfoCategory($categoryId);
        $categoryWhere = $categoryId !== null ? ' AND p.category_id = :category_id' : '';
        if ($categoryId !== null) {
            $params[':category_id'] = $categoryId;
        }

        $totalSql = "
            SELECT COUNT(DISTINCT sfo.seller_id)
            FROM   seller_fulfillment_orders sfo
            JOIN   negotiated_offers no ON no.id = sfo.offer_id
            JOIN   demand_groups dg     ON dg.id = no.group_id
            {$categoryJoin}
            WHERE  sfo.status = 'completed'
                   {$dateWhere}
                   {$categoryWhere}
        ";

        $total = (int) ($this->scalar($totalSql, $params) ?? 0);

        if ($total === 0) {
            return 0.0;
        }

        // Suffix keys for the repeat sub-query
        $repeatParams = $this->rekeySuffix($params, '_rep');

        $repeatDateWhere   = $this->rekeyWhere($dateWhere,   '_rep');
        $repeatCategoryWhere = $categoryId !== null
            ? ' AND p.category_id = :category_id_rep'
            : '';

        $repeatSql = "
            SELECT COUNT(*) FROM (
                SELECT sfo.seller_id
                FROM   seller_fulfillment_orders sfo
                JOIN   negotiated_offers no ON no.id = sfo.offer_id
                JOIN   demand_groups dg     ON dg.id = no.group_id
                {$categoryJoin}
                WHERE  sfo.status = 'completed'
                       {$repeatDateWhere}
                       {$repeatCategoryWhere}
                GROUP BY sfo.seller_id
                HAVING COUNT(sfo.id) >= 2
            ) sub
        ";

        $repeat = (int) ($this->scalar($repeatSql, $repeatParams) ?? 0);

        return round($repeat / $total, 4);
    }

    /**
     * Orders with at least one dispute record / total completed orders.
     */
    private function refundDisputeRate(string $orderWhere, array $params): float
    {
        $totalSql = "
            SELECT COUNT(DISTINCT o.id)
            FROM   orders o
            {$this->orderJoins($params)}
            WHERE  o.status = 'completed'
               AND o.deleted_at IS NULL
               {$orderWhere}
        ";

        $total = (int) ($this->scalar($totalSql, $params) ?? 0);

        if ($total === 0) {
            return 0.0;
        }

        $disputedSql = "
            SELECT COUNT(DISTINCT o.id)
            FROM   orders o
            {$this->orderJoins($params)}
            JOIN   disputes d ON d.order_id = o.id
            WHERE  o.status = 'completed'
               AND o.deleted_at IS NULL
               {$orderWhere}
        ";

        $disputed = (int) ($this->scalar($disputedSql, $params) ?? 0);

        return round($disputed / $total, 4);
    }

    /**
     * Shipments delivered on/before sla_deadline / total shipments with a
     * confirmed delivery event in the window.
     */
    private function onTimeDeliveryRate(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?int    $regionId,
    ): float {
        [$dateWhere, $params] = $this->buildDateFilter('sh.created_at', $dateFrom, $dateTo);

        $categoryJoin  = '';
        $categoryWhere = '';
        if ($categoryId !== null) {
            $categoryJoin = "
            JOIN orders o_sh          ON o_sh.id = sh.order_id AND o_sh.deleted_at IS NULL
            JOIN order_items oi_sh    ON oi_sh.order_id = o_sh.id
            JOIN product_variants pv_sh ON pv_sh.id = oi_sh.variant_id
            JOIN products p_sh         ON p_sh.id = pv_sh.product_id
            ";
            $categoryWhere = ' AND p_sh.category_id = :category_id';
            $params[':category_id'] = $categoryId;
        }

        $regionJoin  = '';
        $regionWhere = '';
        if ($regionId !== null) {
            if ($categoryId === null) {
                // Need orders join for the region path
                $regionJoin = "
            JOIN orders o_sh ON o_sh.id = sh.order_id AND o_sh.deleted_at IS NULL
            ";
            }
            $regionJoin .= "
            JOIN demand_groups dg_sh ON dg_sh.id = o_sh.group_id
            ";
            $regionWhere = ' AND dg_sh.region_id = :region_id';
            $params[':region_id'] = $regionId;
        }

        $totalSql = "
            SELECT COUNT(sh.id)
            FROM   shipments sh
            {$categoryJoin}
            {$regionJoin}
            WHERE  sh.delivered_at IS NOT NULL
                   {$dateWhere}
                   {$categoryWhere}
                   {$regionWhere}
        ";

        $total = (int) ($this->scalar($totalSql, $params) ?? 0);

        if ($total === 0) {
            return 0.0;
        }

        $onTimeSql = "
            SELECT COUNT(sh.id)
            FROM   shipments sh
            {$categoryJoin}
            {$regionJoin}
            WHERE  sh.delivered_at IS NOT NULL
               AND sh.delivered_at <= sh.sla_deadline
                   {$dateWhere}
                   {$categoryWhere}
                   {$regionWhere}
        ";

        $onTime = (int) ($this->scalar($onTimeSql, $params) ?? 0);

        return round($onTime / $total, 4);
    }

    // -------------------------------------------------------------------------
    // Join fragment helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the conditional JOIN clauses needed by order-based queries.
     * Detects which filter dimensions are active from the param keys present.
     */
    private function orderJoins(array $params): string
    {
        $hasCategory = array_key_exists(':category_id', $params);
        $hasRegion   = array_key_exists(':region_id', $params);

        $joins = '';

        if ($hasCategory) {
            $joins .= "
        JOIN order_items oi    ON oi.order_id = o.id
        JOIN product_variants pv ON pv.id = oi.variant_id
        JOIN products p          ON p.id  = pv.product_id
        ";
        }

        if ($hasRegion) {
            $joins .= "
        JOIN demand_groups dg ON dg.id = o.group_id
        ";
        }

        return $joins;
    }

    /** Same as orderJoins() but reads suffixed param keys for repeat queries. */
    private function orderJoinsKeyed(array $params): string
    {
        $hasCategory = array_key_exists(':category_id_rep', $params);
        $hasRegion   = array_key_exists(':region_id_rep', $params);

        $joins = '';

        if ($hasCategory) {
            $joins .= "
        JOIN order_items oi    ON oi.order_id = o.id
        JOIN product_variants pv ON pv.id = oi.variant_id
        JOIN products p          ON p.id  = pv.product_id
        ";
        }

        if ($hasRegion) {
            $joins .= "
        JOIN demand_groups dg ON dg.id = o.group_id
        ";
        }

        return $joins;
    }

    /** Category JOIN for SFO-based queries (from demand_groups alias 'dg'). */
    private function sfoCategory(?int $categoryId): string
    {
        if ($categoryId === null) {
            return '';
        }

        return "
        JOIN product_variants pv ON pv.id = dg.variant_id
        JOIN products p          ON p.id  = pv.product_id
        ";
    }

    // -------------------------------------------------------------------------
    // Filter builders
    // -------------------------------------------------------------------------

    /**
     * Builds WHERE fragments and params for the main order filter.
     *
     * @return array{string, array<string, mixed>}
     */
    private function buildOrderFilter(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?int    $regionId,
    ): array {
        [$where, $params] = $this->buildDateFilter('o.created_at', $dateFrom, $dateTo);

        if ($categoryId !== null) {
            $params[':category_id'] = $categoryId;
            $where .= ' AND p.category_id = :category_id';
        }

        if ($regionId !== null) {
            $params[':region_id'] = $regionId;
            $where .= ' AND dg.region_id = :region_id';
        }

        return [$where, $params];
    }

    /**
     * @return array{string, array<string, string>}
     */
    private function buildDateFilter(string $column, ?string $dateFrom, ?string $dateTo): array
    {
        $where  = '';
        $params = [];

        if ($dateFrom !== null) {
            $where             .= " AND {$column} >= :date_from";
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where           .= " AND {$column} <= :date_to";
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        return [$where, $params];
    }

    // -------------------------------------------------------------------------
    // Param/key utilities for avoiding named-parameter collisions
    // -------------------------------------------------------------------------

    /**
     * Returns a copy of $params with every key suffixed by $suffix.
     *
     * e.g. ':date_from' + '_rep' → ':date_from_rep'
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function rekeySuffix(array $params, string $suffix): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            // Strip leading colon, append suffix, re-add colon
            $bare       = ltrim($key, ':');
            $out[':' . $bare . $suffix] = $value;
        }
        return $out;
    }

    /**
     * Rewrites named parameter references inside a WHERE fragment to use
     * the same suffix applied by rekeySuffix().
     */
    private function rekeyWhere(string $where, string $suffix): string
    {
        // Replace :param_name with :param_name{suffix}
        // Param names contain word chars only.
        return preg_replace('/:([a-zA-Z_][a-zA-Z0-9_]*)/', ':$1' . $suffix, $where) ?? $where;
    }

    // -------------------------------------------------------------------------
    // DB helper
    // -------------------------------------------------------------------------

    private function scalar(string $sql, array $params): mixed
    {
        $rows = $this->db->query($sql, $params);
        return reset($rows[0] ?? [false]);
    }
}
