<?php

declare(strict_types=1);

namespace App\Modules\Orders\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Commitments\Services\MOQService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Orders\Services\OrderService;

/**
 * OrderAdminController -- Internal/ops order management endpoints.
 *
 *   GET  /v1/internal/orders                    -> list()            (all orders with filters)
 *   GET  /v1/internal/orders/{id}               -> show()            (order detail)
 *   POST /v1/internal/orders/{id}/release       -> releaseToSeller() (trigger fulfillment)
 *   GET  /v1/internal/offers/{id}/moq-status    -> monitorMOQ()      (view MOQ status per offer)
 *   POST /v1/internal/orders/{id}/cancel        -> cancelOrder()     (admin cancel with reason)
 */
readonly class OrderAdminController
{
    public function __construct(
        private OrderService     $orderService,
        private Order            $orderModel,
        private OrderItem        $orderItemModel,
        private OrderStatusEvent $statusEventModel,
        private MOQService       $moqService,
    ) {}

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /v1/internal/orders
     *
     * Query params:
     *   page, per_page, status, buyer_id, offer_id, group_id
     */
    public function list(Request $request): Response
    {
        $page    = max(1, (int) $request->getQuery('page', 1));
        $perPage = min(100, max(1, (int) $request->getQuery('per_page', 20)));

        $filters = array_filter([
            'status'   => $request->getQuery('status'),
            'buyer_id' => $request->getQuery('buyer_id'),
            'offer_id' => $request->getQuery('offer_id'),
            'group_id' => $request->getQuery('group_id'),
        ], static fn($v) => $v !== null && $v !== '');

        if (!empty($filters['status']) && !in_array($filters['status'], Order::ALL_STATUSES, true)) {
            return Response::badRequest('Invalid status filter');
        }

        $result = $this->orderModel->listByStatus($filters, $page, $perPage);

        return Response::json([
            'data'       => $result['data'],
            'pagination' => [
                'total'    => $result['total'],
                'page'     => $result['page'],
                'per_page' => $result['per_page'],
                'pages'    => (int) ceil(max(1, $result['total']) / $result['per_page']),
            ],
        ]);
    }

    /**
     * GET /v1/internal/orders/{id}
     */
    public function show(Request $request): Response
    {
        $orderId = $this->resolveId($request);

        $order = $this->orderModel->find($orderId);
        if ($order === null) {
            return Response::notFound('Order not found');
        }

        $order['items']  = $this->orderItemModel->listByOrder($orderId);
        $order['events'] = $this->statusEventModel->listByOrder($orderId);

        return Response::json($order);
    }

    /**
     * POST /v1/internal/orders/{id}/release
     *
     * Release a paid order to the seller for fulfillment.
     * Requires MOQ to be met.
     */
    public function releaseToSeller(Request $request): Response
    {
        $orderId = $this->resolveId($request);
        $actorId = $this->resolveActorId($request);

        $result = $this->orderService->releaseToSeller($orderId, $actorId);

        return Response::json($result);
    }

    /**
     * GET /v1/internal/offers/{id}/moq-status
     *
     * View full MOQ status for an offer including all commitment levels.
     */
    public function monitorMOQ(Request $request): Response
    {
        $offerId = $this->resolveOfferId($request);

        $status = $this->moqService->getFullStatus($offerId);

        return Response::json($status);
    }

    /**
     * POST /v1/internal/orders/{id}/cancel
     *
     * Body: { "reason": "..." }
     */
    public function cancelOrder(Request $request): Response
    {
        $orderId = $this->resolveId($request);
        $actorId = $this->resolveActorId($request);
        $reason  = (string) ($request->getBody('reason') ?? 'Cancelled by admin');

        $result = $this->orderService->cancelOrder($orderId, $actorId, $reason, true);

        return Response::json($result);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveId(Request $request): int
    {
        $id = $request->getParam('id');
        if (empty($id)) {
            throw new HttpException('Order ID is required', 400);
        }
        return (int) $id;
    }

    private function resolveOfferId(Request $request): int
    {
        $id = $request->getParam('id');
        if (empty($id)) {
            throw new HttpException('Offer ID is required', 400);
        }
        return (int) $id;
    }

    private function resolveActorId(Request $request): int
    {
        $userCtx = $request->getParam('_user');
        if (is_string($userCtx)) {
            $decoded = json_decode($userCtx, true);
            return (int) ($decoded['user_id'] ?? 0);
        }
        return 0;
    }
}
