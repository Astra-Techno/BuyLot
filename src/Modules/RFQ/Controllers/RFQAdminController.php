<?php

declare(strict_types=1);

namespace App\Modules\RFQ\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\RFQ\Services\RFQService;

/**
 * RFQAdminController -- Internal staff endpoints for RFQ management.
 *
 * All endpoints require authentication + internal role (procurement_agent,
 * procurement_lead, compliance_admin, super_admin).
 *
 *   POST  /v1/internal/rfqs                       -> create()
 *   PUT   /v1/internal/rfqs/{id}                   -> update()
 *   POST  /v1/internal/rfqs/{id}/publish           -> publish()
 *   POST  /v1/internal/rfqs/{id}/extend            -> extendDeadline()
 *   POST  /v1/internal/rfqs/{id}/close             -> close()
 *   POST  /v1/internal/rfqs/{id}/invite-sellers    -> inviteSellers()
 */
readonly class RFQAdminController
{
    public function __construct(
        private RFQService $rfqService,
    ) {}

    /**
     * POST /v1/internal/rfqs
     *
     * Create a new RFQ from a demand group.
     *
     * Body: {
     *   "group_id": int,
     *   "spec_snapshot_json": string|object,
     *   "qty_band_json": string|object,
     *   "bid_deadline": "YYYY-MM-DD HH:MM:SS"
     * }
     */
    public function create(Request $request): Response
    {
        $userId = $this->resolveUserIdOrFail($request);
        $body   = $request->getBody() ?? [];

        $errors = $this->validateCreatePayload($body);
        if (!empty($errors)) {
            return Response::unprocessable($errors);
        }

        $rfqId = $this->rfqService->createFromGroup(
            groupId:          (int) $body['group_id'],
            specSnapshotJson: is_string($body['spec_snapshot_json'])
                ? $body['spec_snapshot_json']
                : json_encode($body['spec_snapshot_json']),
            qtyBandJson:      is_string($body['qty_band_json'])
                ? $body['qty_band_json']
                : json_encode($body['qty_band_json']),
            bidDeadline:      (string) $body['bid_deadline'],
            createdBy:        (int) $userId,
        );

        return Response::created(['id' => $rfqId, 'status' => 'draft']);
    }

    /**
     * PUT /v1/internal/rfqs/{id}
     *
     * Edit a draft RFQ. Only draft RFQs can be modified.
     *
     * Body (all optional): {
     *   "spec_snapshot_json": ...,
     *   "qty_band_json": ...,
     *   "bid_deadline": "YYYY-MM-DD HH:MM:SS"
     * }
     */
    public function update(Request $request): Response
    {
        $rfqId = (int) $request->getParam('id');
        $body  = $request->getBody() ?? [];

        if ($rfqId <= 0) {
            return Response::badRequest('Invalid RFQ ID');
        }

        $data = [];

        if (isset($body['spec_snapshot_json'])) {
            $data['spec_snapshot_json'] = is_string($body['spec_snapshot_json'])
                ? $body['spec_snapshot_json']
                : json_encode($body['spec_snapshot_json']);
        }

        if (isset($body['qty_band_json'])) {
            $data['qty_band_json'] = is_string($body['qty_band_json'])
                ? $body['qty_band_json']
                : json_encode($body['qty_band_json']);
        }

        if (isset($body['bid_deadline'])) {
            $data['bid_deadline'] = (string) $body['bid_deadline'];
        }

        if (empty($data)) {
            return Response::badRequest('No updatable fields provided');
        }

        $updated = $this->rfqService->updateDraft($rfqId, $data);

        if (!$updated) {
            return Response::badRequest('RFQ not found or is not in draft status');
        }

        return Response::json(['message' => 'RFQ updated', 'id' => $rfqId]);
    }

    /**
     * POST /v1/internal/rfqs/{id}/publish
     *
     * Publish an RFQ to invited sellers. Transitions draft -> published.
     */
    public function publish(Request $request): Response
    {
        $rfqId  = (int) $request->getParam('id');
        $userId = $this->resolveUserIdOrFail($request);

        if ($rfqId <= 0) {
            return Response::badRequest('Invalid RFQ ID');
        }

        $published = $this->rfqService->publish($rfqId, (int) $userId);

        if (!$published) {
            return Response::badRequest('Cannot publish RFQ. It may not be in draft status or may lack seller invitations.');
        }

        return Response::json(['message' => 'RFQ published', 'id' => $rfqId, 'status' => 'published']);
    }

    /**
     * POST /v1/internal/rfqs/{id}/extend
     *
     * Extend the bid deadline for a published RFQ.
     *
     * Body: { "bid_deadline": "YYYY-MM-DD HH:MM:SS", "reason": "..." }
     */
    public function extendDeadline(Request $request): Response
    {
        $rfqId  = (int) $request->getParam('id');
        $userId = $this->resolveUserIdOrFail($request);
        $body   = $request->getBody() ?? [];

        if ($rfqId <= 0) {
            return Response::badRequest('Invalid RFQ ID');
        }

        if (empty($body['bid_deadline'])) {
            return Response::unprocessable(['bid_deadline' => 'New bid deadline is required']);
        }

        $reason = (string) ($body['reason'] ?? 'No reason provided');

        $extended = $this->rfqService->extendDeadline(
            $rfqId,
            (string) $body['bid_deadline'],
            (int) $userId,
            $reason,
        );

        if (!$extended) {
            return Response::badRequest('Cannot extend deadline. RFQ may not be published or new deadline is invalid.');
        }

        return Response::json([
            'message'      => 'Bid deadline extended',
            'id'           => $rfqId,
            'bid_deadline' => $body['bid_deadline'],
        ]);
    }

    /**
     * POST /v1/internal/rfqs/{id}/close
     *
     * Close bidding on an RFQ. Transitions published -> closed.
     */
    public function close(Request $request): Response
    {
        $rfqId  = (int) $request->getParam('id');
        $userId = $this->resolveUserIdOrFail($request);

        if ($rfqId <= 0) {
            return Response::badRequest('Invalid RFQ ID');
        }

        $closed = $this->rfqService->close($rfqId, (int) $userId);

        if (!$closed) {
            return Response::badRequest('Cannot close RFQ. It may not be in published status.');
        }

        return Response::json(['message' => 'RFQ closed', 'id' => $rfqId, 'status' => 'closed']);
    }

    /**
     * POST /v1/internal/rfqs/{id}/invite-sellers
     *
     * Invite specific sellers to an RFQ.
     *
     * Body: { "seller_ids": [1, 2, 3] }
     */
    public function inviteSellers(Request $request): Response
    {
        $rfqId = (int) $request->getParam('id');
        $body  = $request->getBody() ?? [];

        if ($rfqId <= 0) {
            return Response::badRequest('Invalid RFQ ID');
        }

        if (empty($body['seller_ids']) || !is_array($body['seller_ids'])) {
            return Response::unprocessable(['seller_ids' => 'An array of seller IDs is required']);
        }

        $sellerIds = array_map('intval', $body['seller_ids']);
        $sellerIds = array_filter($sellerIds, static fn(int $id) => $id > 0);

        if (empty($sellerIds)) {
            return Response::unprocessable(['seller_ids' => 'At least one valid seller ID is required']);
        }

        $invited = $this->rfqService->inviteSellers($rfqId, $sellerIds);

        return Response::json([
            'message'       => 'Sellers invited',
            'rfq_id'        => $rfqId,
            'invited_count' => $invited,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveUserIdOrFail(Request $request): string
    {
        $userCtx = $request->getParam('_user');

        if (!is_array($userCtx) || empty($userCtx['user_id'])) {
            throw new \App\Core\Exception\HttpException('Invalid authentication context', 401);
        }

        return (string) $userCtx['user_id'];
    }

    private function validateCreatePayload(array $body): array
    {
        $errors = [];

        if (empty($body['group_id'])) {
            $errors['group_id'] = 'Demand group ID is required';
        }

        if (empty($body['spec_snapshot_json'])) {
            $errors['spec_snapshot_json'] = 'Specification snapshot is required';
        }

        if (empty($body['qty_band_json'])) {
            $errors['qty_band_json'] = 'Quantity band is required';
        }

        if (empty($body['bid_deadline'])) {
            $errors['bid_deadline'] = 'Bid deadline is required';
        }

        return $errors;
    }
}
