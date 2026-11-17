<?php

declare(strict_types=1);

namespace App\Modules\Quality\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionFinding;
use App\Modules\Quality\Services\InspectionService;

/**
 * InspectionController — internal quality-team endpoints.
 *
 *   GET  /v1/internal/inspections              -> listPending()
 *   GET  /v1/internal/inspections/{id}         -> show()
 *   POST /v1/internal/inspections/{id}/assign  -> assign()
 *   POST /v1/internal/inspections/{id}/start   -> startInspection()
 *   POST /v1/internal/inspections/{id}/findings -> addFinding()
 *   POST /v1/internal/inspections/{id}/complete -> complete()
 *   GET  /v1/internal/orders/{id}/inspections  -> listByOrder()
 */
readonly class InspectionController
{
    public function __construct(
        private InspectionService  $inspectionService,
        private Inspection         $inspectionModel,
        private InspectionFinding  $findingModel,
    ) {}

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /v1/internal/inspections
     *
     * List inspections awaiting action.
     * Query params: status (default: pending), tier, page, per_page
     */
    public function listPending(Request $request): Response
    {
        $status  = $request->getQuery('status', Inspection::STATUS_PENDING);
        $tier    = $request->getQuery('tier');
        $page    = max(1, (int) ($request->getQuery('page',     '1')));
        $perPage = min(100, max(1, (int) ($request->getQuery('per_page', '20'))));

        $filters = ['status' => $status];
        if ($tier !== null && $tier !== '') {
            $filters['tier'] = (int) $tier;
        }

        $result = $this->inspectionModel->list($filters, $page, $perPage);

        return Response::json($result);
    }

    /**
     * GET /v1/internal/inspections/{id}
     *
     * Full inspection detail including findings.
     */
    public function show(Request $request, int $id): Response
    {
        $detail = $this->inspectionService->getInspectionDetail($id);

        return Response::json($detail);
    }

    /**
     * POST /v1/internal/inspections/{id}/assign
     *
     * Assign an inspector (ops user) to this inspection.
     *
     * Body: { "inspector_id": 7 }
     */
    public function assign(Request $request, int $id): Response
    {
        $body        = $request->getBody() ?? [];
        $inspectorId = (int) ($body['inspector_id'] ?? 0);

        if ($inspectorId < 1) {
            throw new HttpException('inspector_id is required', 422);
        }

        $inspection = $this->inspectionService->assignInspector($id, $inspectorId);

        return Response::json($inspection);
    }

    /**
     * POST /v1/internal/inspections/{id}/start
     *
     * Begin inspection: transitions pending -> in_progress.
     */
    public function startInspection(Request $request, int $id): Response
    {
        $inspection = $this->inspectionService->startInspection($id);

        return Response::json($inspection);
    }

    /**
     * POST /v1/internal/inspections/{id}/findings
     *
     * Record a finding during an active inspection.
     *
     * Body: {
     *   "order_item_id": 42,
     *   "finding_type":  "MINOR_ISSUE",
     *   "qty_affected":  2,
     *   "evidence_ref":  "uploads/inspections/abc.jpg",
     *   "notes":         "Slight scratch on the casing"
     * }
     */
    public function addFinding(Request $request, int $id): Response
    {
        $body = $request->getBody() ?? [];

        $orderItemId = (int) ($body['order_item_id'] ?? 0);
        $findingType = trim((string) ($body['finding_type'] ?? ''));
        $qtyAffected = max(1, (int) ($body['qty_affected'] ?? 1));
        $evidenceRef = isset($body['evidence_ref']) ? trim((string) $body['evidence_ref']) : null;
        $notes       = isset($body['notes'])        ? trim((string) $body['notes'])        : null;

        if ($orderItemId < 1) {
            throw new HttpException('order_item_id is required', 422);
        }

        if ($findingType === '') {
            throw new HttpException('finding_type is required', 422);
        }

        $finding = $this->inspectionService->addFinding(
            $id,
            $orderItemId,
            $findingType,
            $qtyAffected,
            $evidenceRef !== '' ? $evidenceRef : null,
            $notes       !== '' ? $notes       : null,
        );

        return Response::json($finding, 201);
    }

    /**
     * POST /v1/internal/inspections/{id}/complete
     *
     * Complete an inspection with a pass/fail result.
     * Triggers settlement eligibility signal for passing results.
     *
     * Body: {
     *   "result": "pass" | "pass_with_remarks" | "fail" | "inconclusive"
     * }
     */
    public function complete(Request $request, int $id): Response
    {
        $body   = $request->getBody() ?? [];
        $result = trim((string) ($body['result'] ?? ''));

        if ($result === '') {
            throw new HttpException('result is required', 422);
        }

        $inspection = $this->inspectionService->completeInspection($id, $result);

        return Response::json($inspection);
    }

    /**
     * GET /v1/internal/orders/{id}/inspections
     *
     * List all inspections for a given order.
     */
    public function listByOrder(Request $request, int $orderId): Response
    {
        $rows = $this->inspectionModel->listByOrder($orderId);

        // Enrich each with its findings
        $enriched = array_map(function (array $inspection) {
            $inspection['findings'] = $this->findingModel->listByInspection(
                (int) $inspection['id'],
            );
            return $inspection;
        }, $rows);

        return Response::json(['data' => $enriched]);
    }
}
