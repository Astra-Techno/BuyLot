<?php

declare(strict_types=1);

namespace App\Modules\Demand\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Demand\Models\DemandGroup;
use App\Modules\Demand\Services\DemandAggregationService;

/**
 * DemandController -- public and internal endpoints for demand groups.
 *
 * Public (privacy-safe, counts only):
 *   GET /v1/groups          -> listActiveGroups()
 *   GET /v1/groups/{id}     -> showGroup()
 *
 * Internal (procurement staff only, includes price aggregates):
 *   GET /v1/internal/groups/{id}/detail       -> getGroupDetail()
 *   GET /v1/internal/groups/{id}/demand-curve -> getDemandCurve()
 *
 * CRITICAL PRIVACY RULE:
 *   Public endpoints MUST NEVER expose individual buyer budgets.
 *   Only aggregate counts and quantities are public-safe.
 *   Internal endpoints return price aggregates but NEVER individual max_paise.
 */
readonly class DemandController
{
    public function __construct(
        private DemandGroup              $demandGroupModel,
        private DemandAggregationService $aggregationService,
    ) {}

    // -------------------------------------------------------------------------
    // Public endpoints -- privacy-safe
    // -------------------------------------------------------------------------

    /**
     * GET /v1/groups
     *
     * List active demand groups with privacy-safe aggregate stats.
     * No buyer identities, no price data.
     *
     * Query params: page (int), per_page (int)
     */
    public function listActiveGroups(Request $request): Response
    {
        $page    = (int) ($request->getQuery('page') ?? 1);
        $perPage = (int) ($request->getQuery('per_page') ?? 20);

        $result = $this->demandGroupModel->listActive($page, $perPage);

        // Enrich each group with privacy-safe stats
        $enriched = [];
        foreach ($result['data'] as $group) {
            $stats = $this->demandGroupModel->getStats((int) $group['id']);
            $enriched[] = [
                'group'         => $group,
                'total_members' => $stats['total_members'],
                'total_qty'     => $stats['total_qty'],
            ];
        }

        return Response::json([
            'data'     => $enriched,
            'total'    => $result['total'],
            'page'     => $result['page'],
            'per_page' => $result['per_page'],
        ]);
    }

    /**
     * GET /v1/groups/{id}
     *
     * Show a single demand group with privacy-safe aggregate stats.
     * Returns counts at each intent level but no price or buyer identity data.
     */
    public function showGroup(Request $request): Response
    {
        $groupId = (int) $request->getParam('id');

        $group = $this->demandGroupModel->find($groupId);

        if ($group === null) {
            throw new HttpException('Demand group not found', 404);
        }

        $stats = $this->aggregationService->getGroupStats($groupId);

        return Response::json([
            'group' => $group,
            'stats' => $stats,
        ]);
    }

    // -------------------------------------------------------------------------
    // Internal endpoints -- procurement staff only
    // -------------------------------------------------------------------------

    /**
     * GET /v1/internal/groups/{id}/detail
     *
     * Full group detail including aggregate price stats for procurement.
     * NEVER expose to sellers or public endpoints.
     */
    public function getGroupDetail(Request $request): Response
    {
        $groupId = (int) $request->getParam('id');

        try {
            $detail = $this->aggregationService->getGroupDetail($groupId);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException($e->getMessage(), 404);
        }

        return Response::json($detail);
    }

    /**
     * GET /v1/internal/groups/{id}/demand-curve
     *
     * Price/quantity demand curve for procurement sourcing decisions.
     * Shows how many buyers are willing to buy at each price threshold.
     * NEVER expose to sellers or public endpoints.
     */
    public function getDemandCurve(Request $request): Response
    {
        $groupId = (int) $request->getParam('id');

        try {
            $curve = $this->aggregationService->getDemandCurve($groupId);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException($e->getMessage(), 404);
        }

        return Response::json($curve);
    }
}
