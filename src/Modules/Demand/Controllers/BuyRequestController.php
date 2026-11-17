<?php

declare(strict_types=1);

namespace App\Modules\Demand\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Demand\Models\BuyerPricePreference;
use App\Modules\Demand\Models\BuyRequest;
use App\Modules\Demand\Models\DemandGroup;
use App\Modules\Demand\Models\GroupMembership;
use App\Modules\Demand\Services\DemandMatchingService;
use App\Modules\Demand\Services\IntentService;

/**
 * BuyRequestController -- buyer-facing endpoints for creating and managing buy requests.
 *
 * POST   /v1/buy-requests           -> create()
 * GET    /v1/buy-requests/{id}      -> show()
 * GET    /v1/me/requests            -> listMine()
 * DELETE /v1/buy-requests/{id}      -> cancel()
 * POST   /v1/groups/{id}/join       -> joinGroup()
 * POST   /v1/groups/{id}/reconfirm  -> reconfirmIntent()
 */
readonly class BuyRequestController
{
    public function __construct(
        private BuyRequest             $buyRequestModel,
        private BuyerPricePreference   $pricePreferenceModel,
        private DemandGroup            $demandGroupModel,
        private GroupMembership        $membershipModel,
        private DemandMatchingService  $matchingService,
        private IntentService          $intentService,
    ) {}

    /**
     * POST /v1/buy-requests
     *
     * Create a new buy request, optionally with price preferences.
     * Auto-submits and auto-matches to a compatible demand group.
     *
     * Body: {
     *   "variant_id": int,
     *   "qty": int,
     *   "region_id": int,
     *   "purchase_by": ?string (ISO 8601 date),
     *   "spec_json": ?string,
     *   "target_paise": ?int,
     *   "max_paise": ?int,
     *   "visibility_scope": ?string ("private"|"procurement_only")
     * }
     */
    public function create(Request $request): Response
    {
        $buyerId = $this->resolveUserId($request);
        $body    = $request->getBody() ?? [];

        // ---- Validate required fields ----
        $errors = [];

        if (empty($body['variant_id']) || !is_numeric($body['variant_id'])) {
            $errors['variant_id'] = 'variant_id is required and must be numeric';
        }

        if (empty($body['qty']) || !is_numeric($body['qty']) || (int) $body['qty'] < 1) {
            $errors['qty'] = 'qty is required and must be a positive integer';
        }

        if (empty($body['region_id']) || !is_numeric($body['region_id'])) {
            $errors['region_id'] = 'region_id is required and must be numeric';
        }

        if (!empty($body['purchase_by'])) {
            $ts = strtotime($body['purchase_by']);
            if ($ts === false || $ts < time()) {
                $errors['purchase_by'] = 'purchase_by must be a valid future date';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // ---- Create the buy request (starts in DRAFT) ----
        $buyRequest = $this->buyRequestModel->create([
            'buyer_id'    => $buyerId,
            'variant_id'  => (int) $body['variant_id'],
            'spec_json'   => $body['spec_json'] ?? null,
            'qty'         => (int) $body['qty'],
            'region_id'   => (int) $body['region_id'],
            'purchase_by' => $body['purchase_by'] ?? null,
        ]);

        // ---- Create price preference if budget provided ----
        if (!empty($body['target_paise']) || !empty($body['max_paise'])) {
            $this->pricePreferenceModel->create([
                'request_id'       => (int) $buyRequest['id'],
                'target_paise'     => isset($body['target_paise']) ? (int) $body['target_paise'] : null,
                'max_paise'        => isset($body['max_paise']) ? (int) $body['max_paise'] : null,
                'visibility_scope' => $body['visibility_scope'] ?? BuyerPricePreference::VISIBILITY_PRIVATE,
            ]);
        }

        // ---- Auto-submit: draft -> submitted ----
        $this->buyRequestModel->updateStatus((int) $buyRequest['id'], BuyRequest::STATUS_SUBMITTED);

        // Refresh after status change
        $buyRequest = $this->buyRequestModel->find((int) $buyRequest['id']);

        // ---- Auto-match to a demand group ----
        $matchResult = $this->matchingService->matchRequest($buyRequest);

        return Response::created([
            'buy_request' => $buyRequest,
            'group'       => $matchResult['group'],
            'membership'  => $matchResult['membership'],
        ]);
    }

    /**
     * GET /v1/buy-requests/{id}
     *
     * Show a single buy request owned by the authenticated buyer.
     * Includes the buyer's own price preferences.
     */
    public function show(Request $request): Response
    {
        $buyerId   = $this->resolveUserId($request);
        $requestId = (int) $request->getParam('id');

        $buyRequest = $this->buyRequestModel->find($requestId);

        if ($buyRequest === null) {
            throw new HttpException('Buy request not found', 404);
        }

        if ((int) $buyRequest['buyer_id'] !== $buyerId) {
            throw new HttpException('Forbidden', 403);
        }

        // Include buyer's own price preferences (they own this data)
        $pricePreference = $this->pricePreferenceModel->findByRequestForBuyer($requestId);

        return Response::json([
            'buy_request'      => $buyRequest,
            'price_preference' => $pricePreference,
        ]);
    }

    /**
     * GET /v1/me/requests
     *
     * List the authenticated buyer's own buy requests (paginated).
     */
    public function listMine(Request $request): Response
    {
        $buyerId = $this->resolveUserId($request);
        $page    = (int) ($request->getQuery('page') ?? 1);
        $perPage = (int) ($request->getQuery('per_page') ?? 20);

        $result = $this->buyRequestModel->listByBuyer($buyerId, $page, $perPage);

        return Response::json($result);
    }

    /**
     * DELETE /v1/buy-requests/{id}
     *
     * Cancel a buy request owned by the authenticated buyer.
     * Only allowed if no payment has been captured (cancellable states).
     */
    public function cancel(Request $request): Response
    {
        $buyerId   = $this->resolveUserId($request);
        $requestId = (int) $request->getParam('id');

        $buyRequest = $this->buyRequestModel->find($requestId);

        if ($buyRequest === null) {
            throw new HttpException('Buy request not found', 404);
        }

        if ((int) $buyRequest['buyer_id'] !== $buyerId) {
            throw new HttpException('Forbidden', 403);
        }

        if (!$this->buyRequestModel->isCancellable($buyRequest)) {
            throw new HttpException(
                "Cannot cancel buy request in '{$buyRequest['status']}' status",
                422,
            );
        }

        $this->buyRequestModel->updateStatus($requestId, BuyRequest::STATUS_CANCELLED);

        return Response::json(['message' => 'Buy request cancelled']);
    }

    /**
     * POST /v1/groups/{id}/join
     *
     * Join an existing demand group directly.
     * Creates a membership at L1 (interested).
     *
     * Body: { "qty": int, "request_id": ?int }
     */
    public function joinGroup(Request $request): Response
    {
        $buyerId = $this->resolveUserId($request);
        $groupId = (int) $request->getParam('id');
        $body    = $request->getBody() ?? [];

        // Validate the group exists and is in a joinable state
        $group = $this->demandGroupModel->find($groupId);

        if ($group === null) {
            throw new HttpException('Demand group not found', 404);
        }

        if ($group['status'] !== DemandGroup::STATUS_FORMING) {
            throw new HttpException(
                "Cannot join group in '{$group['status']}' status; group must be forming",
                422,
            );
        }

        if (empty($body['qty']) || !is_numeric($body['qty']) || (int) $body['qty'] < 1) {
            throw new ValidationException(['qty' => 'qty is required and must be a positive integer']);
        }

        // Check if already a member
        $existing = $this->membershipModel->findByGroupAndBuyer($groupId, $buyerId);
        if ($existing !== null) {
            throw new HttpException('You are already a member of this group', 409);
        }

        $membership = $this->membershipModel->join([
            'group_id'   => $groupId,
            'buyer_id'   => $buyerId,
            'request_id' => isset($body['request_id']) ? (int) $body['request_id'] : null,
            'qty'        => (int) $body['qty'],
        ]);

        return Response::created([
            'membership' => $membership,
            'group'      => $group,
        ]);
    }

    /**
     * POST /v1/groups/{id}/reconfirm
     *
     * Reconfirm purchase readiness (L1 -> L2).
     * Buyer must already be a member of the group at L1.
     */
    public function reconfirmIntent(Request $request): Response
    {
        $buyerId = $this->resolveUserId($request);
        $groupId = (int) $request->getParam('id');

        $membership = $this->intentService->reconfirmReady($buyerId, $groupId);

        return Response::json([
            'membership' => $membership,
            'message'    => 'Purchase readiness reconfirmed (L2)',
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveUserId(Request $request): int
    {
        $userCtx = $request->getParam('_user');

        if (!is_array($userCtx) || empty($userCtx['user_id'])) {
            throw new HttpException('Invalid authentication context', 401);
        }

        return (int) $userCtx['user_id'];
    }
}
