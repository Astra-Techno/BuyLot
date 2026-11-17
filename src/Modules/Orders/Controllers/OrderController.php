<?php

declare(strict_types=1);

namespace App\Modules\Orders\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Orders\Services\OrderService;

/**
 * OrderController -- Buyer-facing order endpoints.
 *
 *   GET    /v1/orders/{id}          -> show()      (own only)
 *   GET    /v1/me/orders            -> listMine()   (buyer's orders with filters)
 *   POST   /v1/orders/{id}/checkout -> checkout()   (initiate payment)
 *   DELETE /v1/orders/{id}          -> cancel()     (cancel if cancellable state)
 */
readonly class OrderController
{
    public function __construct(
        private OrderService     $orderService,
        private Order            $orderModel,
        private OrderItem        $orderItemModel,
        private OrderStatusEvent $statusEventModel,
    ) {}

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /v1/orders/{id}
     *
     * Returns order detail for the authenticated buyer (own orders only).
     */
    public function show(Request $request): Response
    {
        $buyerId = $this->resolveUserId($request);
        $orderId = $this->resolveOrderId($request);

        $order = $this->orderModel->find($orderId);
        if ($order === null) {
            return Response::notFound('Order not found');
        }

        // Ownership check
        if ((int) $order['buyer_id'] !== $buyerId) {
            return Response::notFound('Order not found');
        }

        // Attach items and status history
        $order['items']  = $this->orderItemModel->listByOrder($orderId);
        $order['events'] = $this->statusEventModel->listByOrder($orderId);

        return Response::json($order);
    }

    /**
     * GET /v1/me/orders
     *
     * Query params:
     *   page     (int, default 1)
     *   per_page (int, 1-100, default 20)
     *   status   (optional filter)
     *   offer_id (optional filter)
     */
    public function listMine(Request $request): Response
    {
        $buyerId = $this->resolveUserId($request);

        $page    = max(1, (int) $request->getQuery('page', 1));
        $perPage = min(100, max(1, (int) $request->getQuery('per_page', 20)));

        $filters = array_filter([
            'status'   => $request->getQuery('status'),
            'offer_id' => $request->getQuery('offer_id'),
        ], static fn($v) => $v !== null && $v !== '');

        // Validate status filter
        if (!empty($filters['status']) && !in_array($filters['status'], Order::ALL_STATUSES, true)) {
            return Response::badRequest('Invalid status filter');
        }

        $result = $this->orderModel->listByBuyer($buyerId, $filters, $page, $perPage);

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
     * POST /v1/orders/{id}/checkout
     *
     * Initiates payment for the order.
     */
    public function checkout(Request $request): Response
    {
        $buyerId = $this->resolveUserId($request);
        $orderId = $this->resolveOrderId($request);

        // Verify ownership
        $order = $this->orderModel->find($orderId);
        if ($order === null || (int) $order['buyer_id'] !== $buyerId) {
            return Response::notFound('Order not found');
        }

        $result = $this->orderService->initiateCheckout($orderId);

        return Response::json($result);
    }

    /**
     * DELETE /v1/orders/{id}
     *
     * Cancel an order if in a cancellable state.
     */
    public function cancel(Request $request): Response
    {
        $buyerId = $this->resolveUserId($request);
        $orderId = $this->resolveOrderId($request);

        // Verify ownership
        $order = $this->orderModel->find($orderId);
        if ($order === null || (int) $order['buyer_id'] !== $buyerId) {
            return Response::notFound('Order not found');
        }

        $reason = $request->getBody('reason') ?? 'Cancelled by buyer';

        $result = $this->orderService->cancelOrder($orderId, $buyerId, (string) $reason, false);

        return Response::json($result);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveUserId(Request $request): int
    {
        $userCtx = $request->getParam('_user');

        if (empty($userCtx)) {
            throw new HttpException('Invalid authentication context', 401);
        }

        // _user may be a JSON-encoded string from middleware
        if (is_string($userCtx)) {
            $decoded = json_decode($userCtx, true);
            return (int) ($decoded['user_id'] ?? 0);
        }

        return 0;
    }

    private function resolveOrderId(Request $request): int
    {
        $id = $request->getParam('id');

        if (empty($id)) {
            throw new HttpException('Order ID is required', 400);
        }

        return (int) $id;
    }
}
