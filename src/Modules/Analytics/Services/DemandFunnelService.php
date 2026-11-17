<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Core\Database\Connection;

/**
 * DemandFunnelService — buyer demand funnel from first view through repeat purchase.
 *
 * Funnel stages (interest separated from paid):
 *   views → requests → verified_interest → purchase_ready
 *   → offer_received → offer_accepted → paid → delivered → accepted → repeat
 *
 * "Interest" stages (views..purchase_ready) are never conflated with paid demand.
 * All counts are distinct buyers unless the stage is naturally a document count
 * (e.g. requests = buy_request rows).
 *
 * Filters: date_from / date_to (applied to the stage's primary timestamp),
 *          category_id (via product_variants → products → categories),
 *          city (region label on addresses joined via buy_requests.region_id).
 */
readonly class DemandFunnelService
{
    public function __construct(private Connection $db) {}

    /**
     * Returns all funnel stage counts for the given filter window.
     *
     * @param string|null $dateFrom  ISO-8601 date, inclusive lower bound
     * @param string|null $dateTo    ISO-8601 date, inclusive upper bound
     * @param int|null    $categoryId  categories.id
     * @param string|null $city        addresses.region (case-insensitive LIKE)
     * @return array<string, int|float>
     */
    public function getFunnel(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): array {
        return [
            // ---- INTEREST STAGES ------------------------------------------------
            // "Views" is a proxy: distinct buyers who ever submitted a buy_request
            // in the window (a true page-view event table is outside this schema).
            'views'             => $this->countViews($dateFrom, $dateTo, $categoryId, $city),

            // All submitted buy_requests (not just draft)
            'requests'          => $this->countRequests($dateFrom, $dateTo, $categoryId, $city),

            // group_memberships with intent_level >= 2 (engaged / verified interest)
            'verified_interest' => $this->countVerifiedInterest($dateFrom, $dateTo, $categoryId, $city),

            // group_memberships with confirmed_at IS NOT NULL (purchase-ready, L4+)
            'purchase_ready'    => $this->countPurchaseReady($dateFrom, $dateTo, $categoryId, $city),

            // ---- PAID DEMAND STAGES ---------------------------------------------
            // Distinct buyers who accepted at least one offer
            'offer_received'    => $this->countOfferReceived($dateFrom, $dateTo, $categoryId, $city),

            // Distinct buyers with an offer_acceptance row
            'offer_accepted'    => $this->countOfferAccepted($dateFrom, $dateTo, $categoryId, $city),

            // Distinct buyers with an order in payment_confirmed or later status
            'paid'              => $this->countPaid($dateFrom, $dateTo, $categoryId, $city),

            // Distinct buyers with an order in delivered or later status
            'delivered'         => $this->countDelivered($dateFrom, $dateTo, $categoryId, $city),

            // Distinct buyers with an order in accepted or completed status
            'accepted'          => $this->countAccepted($dateFrom, $dateTo, $categoryId, $city),

            // Distinct buyers with 2+ completed orders in the window
            'repeat'            => $this->countRepeat($dateFrom, $dateTo, $categoryId, $city),
        ];
    }

    // -------------------------------------------------------------------------
    // Interest stages
    // -------------------------------------------------------------------------

    private function countViews(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): int {
        // Proxy: distinct buyers who created any buy_request in the window.
        [$where, $params] = $this->buildRequestFilter(
            'br', $dateFrom, $dateTo, $categoryId, $city,
        );

        $sql = "
            SELECT COUNT(DISTINCT br.buyer_id)
            FROM   buy_requests br
            {$this->requestJoins('br', $categoryId, $city)}
            WHERE  br.deleted_at IS NULL
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    private function countRequests(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): int {
        [$where, $params] = $this->buildRequestFilter(
            'br', $dateFrom, $dateTo, $categoryId, $city,
        );

        $sql = "
            SELECT COUNT(br.id)
            FROM   buy_requests br
            {$this->requestJoins('br', $categoryId, $city)}
            WHERE  br.status != 'draft'
               AND br.deleted_at IS NULL
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    private function countVerifiedInterest(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): int {
        [$where, $params] = $this->buildMembershipFilter(
            'gm', 'br', $dateFrom, $dateTo, $categoryId, $city,
        );

        $sql = "
            SELECT COUNT(DISTINCT gm.buyer_id)
            FROM   group_memberships gm
            JOIN   buy_requests br ON br.id = gm.request_id AND br.deleted_at IS NULL
            {$this->requestJoins('br', $categoryId, $city)}
            WHERE  gm.intent_level >= 2
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    private function countPurchaseReady(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): int {
        [$where, $params] = $this->buildMembershipFilter(
            'gm', 'br', $dateFrom, $dateTo, $categoryId, $city,
        );

        $sql = "
            SELECT COUNT(DISTINCT gm.buyer_id)
            FROM   group_memberships gm
            JOIN   buy_requests br ON br.id = gm.request_id AND br.deleted_at IS NULL
            {$this->requestJoins('br', $categoryId, $city)}
            WHERE  gm.confirmed_at IS NOT NULL
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    // -------------------------------------------------------------------------
    // Paid demand stages
    // -------------------------------------------------------------------------

    private function countOfferReceived(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): int {
        // Distinct buyers for whom a published/approved offer existed on a group
        // they belonged to, within the window.
        [$where, $params] = $this->buildOfferFilter(
            'no', 'gm', 'br', $dateFrom, $dateTo, $categoryId, $city,
        );

        $sql = "
            SELECT COUNT(DISTINCT gm.buyer_id)
            FROM   negotiated_offers no
            JOIN   group_memberships gm ON gm.group_id = no.group_id
            JOIN   buy_requests br      ON br.id = gm.request_id AND br.deleted_at IS NULL
            {$this->requestJoins('br', $categoryId, $city)}
            WHERE  no.status IN ('published', 'moq_met', 'moq_failed', 'expired', 'cancelled')
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    private function countOfferAccepted(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): int {
        [$where, $params] = $this->buildAcceptanceFilter(
            'oa', 'no', 'br', $dateFrom, $dateTo, $categoryId, $city,
        );

        $sql = "
            SELECT COUNT(DISTINCT oa.buyer_id)
            FROM   offer_acceptances oa
            JOIN   negotiated_offers no ON no.id = oa.offer_id
            JOIN   demand_groups     dg ON dg.id = no.group_id
            JOIN   buy_requests br
                   ON br.buyer_id = oa.buyer_id
                  AND br.deleted_at IS NULL
            {$this->requestJoins('br', $categoryId, $city)}
            WHERE  1=1
                   {$where}
        ";

        return (int) $this->scalar($sql, $params);
    }

    private function countPaid(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): int {
        return $this->countOrdersByStatus(
            ['payment_confirmed', 'processing', 'dispatched', 'delivered',
             'accepted', 'completed'],
            $dateFrom, $dateTo, $categoryId, $city,
        );
    }

    private function countDelivered(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): int {
        return $this->countOrdersByStatus(
            ['delivered', 'accepted', 'completed'],
            $dateFrom, $dateTo, $categoryId, $city,
        );
    }

    private function countAccepted(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): int {
        return $this->countOrdersByStatus(
            ['accepted', 'completed'],
            $dateFrom, $dateTo, $categoryId, $city,
        );
    }

    private function countRepeat(
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): int {
        // Buyers with 2+ completed orders in the window.
        [$dateWhere, $params] = $this->buildDateFilter('o.created_at', $dateFrom, $dateTo);

        $categoryJoin  = $this->orderCategoryJoin($categoryId);
        $categoryWhere = $categoryId !== null ? ' AND p.category_id = :category_id_rep' : '';
        if ($categoryId !== null) {
            $params[':category_id_rep'] = $categoryId;
        }

        $cityJoin  = $this->orderCityJoin($city);
        $cityWhere = $city !== null ? " AND addr.region LIKE :city_rep" : '';
        if ($city !== null) {
            $params[':city_rep'] = '%' . $city . '%';
        }

        $sql = "
            SELECT COUNT(*) FROM (
                SELECT o.buyer_id
                FROM   orders o
                {$categoryJoin}
                {$cityJoin}
                WHERE  o.status IN ('completed')
                   AND o.deleted_at IS NULL
                   {$dateWhere}
                   {$categoryWhere}
                   {$cityWhere}
                GROUP BY o.buyer_id
                HAVING COUNT(o.id) >= 2
            ) sub
        ";

        return (int) $this->scalar($sql, $params);
    }

    // -------------------------------------------------------------------------
    // Shared helpers
    // -------------------------------------------------------------------------

    /**
     * @param  string[] $statuses
     */
    private function countOrdersByStatus(
        array   $statuses,
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): int {
        [$dateWhere, $params] = $this->buildDateFilter('o.created_at', $dateFrom, $dateTo);

        $inPlaceholders = implode(',', array_fill(0, count($statuses), '?'));
        $statusParams   = array_values($statuses);

        $categoryJoin  = $this->orderCategoryJoin($categoryId);
        $categoryWhere = $categoryId !== null ? ' AND p.category_id = :category_id_ord' : '';
        if ($categoryId !== null) {
            $params[':category_id_ord'] = $categoryId;
        }

        $cityJoin  = $this->orderCityJoin($city);
        $cityWhere = $city !== null ? " AND addr.region LIKE :city_ord" : '';
        if ($city !== null) {
            $params[':city_ord'] = '%' . $city . '%';
        }

        // Mix named and positional params: positional go first for the IN clause.
        $allParams = array_merge($statusParams, array_values($params));

        $sql = "
            SELECT COUNT(DISTINCT o.buyer_id)
            FROM   orders o
            {$categoryJoin}
            {$cityJoin}
            WHERE  o.status IN ({$inPlaceholders})
               AND o.deleted_at IS NULL
               {$dateWhere}
               {$categoryWhere}
               {$cityWhere}
        ";

        return (int) $this->scalarPositional($sql, $allParams);
    }

    // -- Join fragments -------------------------------------------------------

    private function requestJoins(string $brAlias, ?int $categoryId, ?string $city): string
    {
        $joins = '';

        if ($categoryId !== null) {
            $joins .= "
            LEFT JOIN product_variants pv ON pv.id = {$brAlias}.variant_id
            LEFT JOIN products          p  ON p.id  = pv.product_id
            ";
        }

        if ($city !== null) {
            $joins .= "
            LEFT JOIN addresses addr
                   ON addr.owner_type = 'demand_group'
                  AND addr.owner_id   = {$brAlias}.region_id
            ";
        }

        return $joins;
    }

    private function orderCategoryJoin(?int $categoryId): string
    {
        if ($categoryId === null) {
            return '';
        }

        return "
        JOIN order_items oi ON oi.order_id = o.id
        JOIN product_variants pv ON pv.id = oi.variant_id
        JOIN products p ON p.id = pv.product_id
        ";
    }

    private function orderCityJoin(?string $city): string
    {
        if ($city === null) {
            return '';
        }

        return "
        JOIN demand_groups dg ON dg.id = o.group_id
        LEFT JOIN addresses addr
               ON addr.owner_type = 'demand_group'
              AND addr.owner_id   = dg.id
        ";
    }

    // -- Filter builders ------------------------------------------------------

    /**
     * @return array{string, array<string, mixed>}
     */
    private function buildRequestFilter(
        string  $brAlias,
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): array {
        [$dateWhere, $params] = $this->buildDateFilter("{$brAlias}.created_at", $dateFrom, $dateTo);

        if ($categoryId !== null) {
            $params[':category_id'] = $categoryId;
            $dateWhere .= ' AND p.category_id = :category_id';
        }

        if ($city !== null) {
            $params[':city'] = '%' . $city . '%';
            $dateWhere .= ' AND addr.region LIKE :city';
        }

        return [$dateWhere, $params];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function buildMembershipFilter(
        string  $gmAlias,
        string  $brAlias,
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): array {
        [$dateWhere, $params] = $this->buildDateFilter("{$gmAlias}.created_at", $dateFrom, $dateTo);

        if ($categoryId !== null) {
            $params[':category_id'] = $categoryId;
            $dateWhere .= ' AND p.category_id = :category_id';
        }

        if ($city !== null) {
            $params[':city'] = '%' . $city . '%';
            $dateWhere .= ' AND addr.region LIKE :city';
        }

        return [$dateWhere, $params];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function buildOfferFilter(
        string  $noAlias,
        string  $gmAlias,
        string  $brAlias,
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): array {
        [$dateWhere, $params] = $this->buildDateFilter("{$noAlias}.created_at", $dateFrom, $dateTo);

        if ($categoryId !== null) {
            $params[':category_id'] = $categoryId;
            $dateWhere .= ' AND p.category_id = :category_id';
        }

        if ($city !== null) {
            $params[':city'] = '%' . $city . '%';
            $dateWhere .= ' AND addr.region LIKE :city';
        }

        return [$dateWhere, $params];
    }

    /**
     * @return array{string, array<string, mixed>}
     */
    private function buildAcceptanceFilter(
        string  $oaAlias,
        string  $noAlias,
        string  $brAlias,
        ?string $dateFrom,
        ?string $dateTo,
        ?int    $categoryId,
        ?string $city,
    ): array {
        [$dateWhere, $params] = $this->buildDateFilter("{$oaAlias}.accepted_at", $dateFrom, $dateTo);

        if ($categoryId !== null) {
            $params[':category_id'] = $categoryId;
            $dateWhere .= ' AND p.category_id = :category_id';
        }

        if ($city !== null) {
            $params[':city'] = '%' . $city . '%';
            $dateWhere .= ' AND addr.region LIKE :city';
        }

        return [$dateWhere, $params];
    }

    /**
     * Builds a date-range WHERE fragment using named parameters.
     *
     * @return array{string, array<string, string>}
     */
    private function buildDateFilter(string $column, ?string $dateFrom, ?string $dateTo): array
    {
        $where  = '';
        $params = [];

        if ($dateFrom !== null) {
            $where            .= " AND {$column} >= :date_from";
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where            .= " AND {$column} <= :date_to";
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        return [$where, $params];
    }

    private function scalar(string $sql, array $params): mixed
    {
        $rows = $this->db->query($sql, $params);
        return reset($rows[0] ?? [false]);
    }

    private function scalarPositional(string $sql, array $params): mixed
    {
        $pdo  = $this->db->getPdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($params));
        $row = $stmt->fetch(\PDO::FETCH_NUM);
        return $row[0] ?? 0;
    }
}
