<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Analytics\Services\CommercialKPIService;
use App\Modules\Analytics\Services\DemandFunnelService;
use App\Modules\Analytics\Services\FinanceKPIService;
use App\Modules\Analytics\Services\ProcurementKPIService;
use App\Modules\Analytics\Services\SellerFunnelService;

/**
 * AnalyticsController — internal analytics endpoints.
 *
 * All routes require authentication plus an appropriate role guard applied at
 * the router level (see Analytics\Routes). The controller itself does not
 * re-check roles; it trusts the middleware chain.
 *
 * Common query parameters accepted by every action:
 *   ?from=YYYY-MM-DD      inclusive date lower bound
 *   ?to=YYYY-MM-DD        inclusive date upper bound
 *   ?category_id=<int>    filter by product category
 *   ?region_id=<int>      filter by demand-group region (commercial/demand only)
 */
readonly class AnalyticsController
{
    public function __construct(
        private DemandFunnelService  $demandFunnel,
        private SellerFunnelService  $sellerFunnel,
        private ProcurementKPIService $procurementKPIs,
        private CommercialKPIService $commercialKPIs,
        private FinanceKPIService    $financeKPIs,
    ) {}

    // -------------------------------------------------------------------------
    // Individual endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /v1/internal/analytics/demand-funnel
     */
    public function demandFunnel(Request $request): Response
    {
        ['from' => $from, 'to' => $to, 'category_id' => $categoryId] = $this->commonParams($request);
        $city = $this->stringParam($request, 'city');

        $data = $this->demandFunnel->getFunnel($from, $to, $categoryId, $city);

        return Response::json([
            'filters' => $this->describeFilters($from, $to, $categoryId, null, $city),
            'funnel'  => $data,
        ]);
    }

    /**
     * GET /v1/internal/analytics/seller-funnel
     */
    public function sellerFunnel(Request $request): Response
    {
        ['from' => $from, 'to' => $to, 'category_id' => $categoryId] = $this->commonParams($request);

        $data = $this->sellerFunnel->getFunnel($from, $to, $categoryId);

        return Response::json([
            'filters' => $this->describeFilters($from, $to, $categoryId),
            'funnel'  => $data,
        ]);
    }

    /**
     * GET /v1/internal/analytics/procurement-kpis
     */
    public function procurementKPIs(Request $request): Response
    {
        ['from' => $from, 'to' => $to] = $this->commonParams($request);

        $data = $this->procurementKPIs->getKPIs($from, $to);

        return Response::json([
            'filters' => $this->describeFilters($from, $to),
            'kpis'    => $data,
        ]);
    }

    /**
     * GET /v1/internal/analytics/commercial-kpis
     */
    public function commercialKPIs(Request $request): Response
    {
        ['from' => $from, 'to' => $to, 'category_id' => $categoryId, 'region_id' => $regionId]
            = $this->commonParams($request);

        $data = $this->commercialKPIs->getKPIs($from, $to, $categoryId, $regionId);

        return Response::json([
            'filters' => $this->describeFilters($from, $to, $categoryId, $regionId),
            'kpis'    => $data,
        ]);
    }

    /**
     * GET /v1/internal/analytics/finance-kpis
     */
    public function financeKPIs(Request $request): Response
    {
        ['from' => $from, 'to' => $to] = $this->commonParams($request);

        $data = $this->financeKPIs->getKPIs($from, $to);

        return Response::json([
            'filters' => $this->describeFilters($from, $to),
            'kpis'    => $data,
        ]);
    }

    /**
     * GET /v1/internal/analytics/dashboard
     *
     * Combines headline metrics from all five services into a single response
     * for the executive dashboard.
     */
    public function executiveDashboard(Request $request): Response
    {
        ['from' => $from, 'to' => $to, 'category_id' => $categoryId, 'region_id' => $regionId]
            = $this->commonParams($request);

        $city = $this->stringParam($request, 'city');

        // Run all five services; individual failures bubble up naturally.
        $demand      = $this->demandFunnel->getFunnel($from, $to, $categoryId, $city);
        $seller      = $this->sellerFunnel->getFunnel($from, $to, $categoryId);
        $procurement = $this->procurementKPIs->getKPIs($from, $to);
        $commercial  = $this->commercialKPIs->getKPIs($from, $to, $categoryId, $regionId);
        $finance     = $this->financeKPIs->getKPIs($from, $to);

        return Response::json([
            'filters'     => $this->describeFilters($from, $to, $categoryId, $regionId, $city),
            'demand'      => [
                // Surface the most actionable headline stages only.
                'requests'       => $demand['requests'],
                'purchase_ready' => $demand['purchase_ready'],
                'paid'           => $demand['paid'],
                'repeat'         => $demand['repeat'],
            ],
            'seller'      => [
                'invited'   => $seller['invited'],
                'awarded'   => $seller['awarded'],
                'fulfilled' => $seller['fulfilled'],
                'settled'   => $seller['settled'],
            ],
            'procurement' => [
                'time_to_first_quote_hours'    => $procurement['time_to_first_quote'],
                'time_to_approved_offer_hours' => $procurement['time_to_approved_offer'],
                'quote_to_deal_rate_pct'       => $procurement['quote_to_deal_rate'],
                'agent_workload'               => $procurement['agent_workload'],
            ],
            'commercial'  => [
                'completed_gmv_paise'        => $commercial['completed_gmv_paise'],
                'platform_net_revenue_paise' => $commercial['platform_net_revenue_paise'],
                'contribution_margin'        => $commercial['contribution_margin'],
                'effective_take_rate'        => $commercial['effective_take_rate'],
                'buyer_repeat_rate'          => $commercial['buyer_repeat_rate'],
                'on_time_delivery_rate'      => $commercial['on_time_delivery_rate'],
            ],
            'finance'     => [
                'payment_success_rate'            => $finance['payment_success_rate'],
                'reconciliation_exceptions_count' => $finance['reconciliation_exceptions_count'],
                'refund_aging_days'               => $finance['refund_aging_days'],
                'chargeback_rate'                 => $finance['chargeback_rate'],
            ],
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Extracts and coerces the common filter query params.
     *
     * @return array{from: string|null, to: string|null, category_id: int|null, region_id: int|null}
     */
    private function commonParams(Request $request): array
    {
        $from       = $this->dateParam($request, 'from');
        $to         = $this->dateParam($request, 'to');
        $categoryId = $this->intParam($request,  'category_id');
        $regionId   = $this->intParam($request,  'region_id');

        return [
            'from'        => $from,
            'to'          => $to,
            'category_id' => $categoryId,
            'region_id'   => $regionId,
        ];
    }

    private function dateParam(Request $request, string $key): ?string
    {
        $value = $request->getParam($key);

        if (!is_string($value) || $value === '') {
            return null;
        }

        // Accept YYYY-MM-DD only; ignore anything that doesn't match.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }

    private function intParam(Request $request, string $key): ?int
    {
        $value = $request->getParam($key);

        if ($value === null || $value === '') {
            return null;
        }

        $int = filter_var($value, FILTER_VALIDATE_INT);
        return $int !== false && $int > 0 ? (int) $int : null;
    }

    private function stringParam(Request $request, string $key): ?string
    {
        $value = $request->getParam($key);
        return (is_string($value) && $value !== '') ? trim($value) : null;
    }

    /**
     * Builds a metadata block describing which filters were active.
     *
     * @return array<string, mixed>
     */
    private function describeFilters(
        ?string $from,
        ?string $to,
        ?int    $categoryId = null,
        ?int    $regionId   = null,
        ?string $city       = null,
    ): array {
        return array_filter([
            'from'        => $from,
            'to'          => $to,
            'category_id' => $categoryId,
            'region_id'   => $regionId,
            'city'        => $city,
        ], static fn(mixed $v) => $v !== null);
    }
}
