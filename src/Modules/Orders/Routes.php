<?php

declare(strict_types=1);

namespace App\Modules\Orders;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Modules\Commitments\Services\CommitmentService;
use App\Modules\Commitments\Services\MOQService;
use App\Modules\Identity\Middleware\RoleMiddleware;
use App\Modules\Orders\Controllers\OrderAdminController;
use App\Modules\Orders\Controllers\OrderController;
use App\Modules\Orders\Controllers\SellerDealController;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusEvent;
use App\Modules\Orders\Models\SellerFulfillmentOrder;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\SellerDealService;

/**
 * Register all Orders + Commitments module routes onto the shared Router.
 *
 * Call from public/index.php:
 *
 *   \App\Modules\Orders\Routes::register($router, $connection);
 */
class Routes
{
    public static function register(Router $router, Connection $connection): void
    {
        // -------------------------------------------------------------------------
        // Shared dependencies
        // -------------------------------------------------------------------------

        $jwtConfig  = Config::get('auth.jwt', []);
        $jwtHandler = new JwtHandler($jwtConfig['secret'] ?? 'change-me');

        // Models
        $orderModel              = new Order($connection);
        $orderItemModel          = new OrderItem($connection);
        $statusEventModel        = new OrderStatusEvent($connection);
        $fulfillmentOrderModel   = new SellerFulfillmentOrder($connection);

        // Services
        $commitmentService = new CommitmentService($connection);
        $moqService        = new MOQService($connection, $commitmentService, $orderModel, $fulfillmentOrderModel);
        $orderService      = new OrderService($connection, $orderModel, $orderItemModel, $statusEventModel, $commitmentService, $moqService);
        $dealService       = new SellerDealService($connection);

        // Controllers
        $orderController      = new OrderController($orderService, $orderModel, $orderItemModel, $statusEventModel);
        $orderAdminController = new OrderAdminController($orderService, $orderModel, $orderItemModel, $statusEventModel, $moqService);
        $sellerDealController = new SellerDealController($dealService);

        // -------------------------------------------------------------------------
        // Middleware instances
        // -------------------------------------------------------------------------

        $authMiddleware = new AuthMiddleware($jwtHandler);

        // Role-based middleware
        $sellerMiddleware          = new RoleMiddleware('seller');
        $opsMiddleware             = new RoleMiddleware('ops_agent', 'procurement_agent', 'procurement_lead', 'super_admin');
        $procurementMiddleware     = new RoleMiddleware('procurement_agent', 'procurement_lead', 'super_admin');
        $procurementLeadMiddleware = new RoleMiddleware('procurement_lead', 'super_admin');

        // =====================================================================
        // BUYER ROUTES
        // =====================================================================

        // GET /v1/orders/{id} -- order detail (own only)
        $router->get(
            '/v1/orders/{id}',
            static fn(Request $req) => $orderController->show($req),
            [$authMiddleware],
        );

        // GET /v1/me/orders -- buyer's orders with filters
        $router->get(
            '/v1/me/orders',
            static fn(Request $req) => $orderController->listMine($req),
            [$authMiddleware],
        );

        // POST /v1/orders/{id}/checkout -- initiate payment
        $router->post(
            '/v1/orders/{id}/checkout',
            static fn(Request $req) => $orderController->checkout($req),
            [$authMiddleware],
        );

        // DELETE /v1/orders/{id} -- cancel if cancellable
        $router->delete(
            '/v1/orders/{id}',
            static fn(Request $req) => $orderController->cancel($req),
            [$authMiddleware],
        );

        // =====================================================================
        // SELLER ROUTES
        // =====================================================================

        // POST /v1/seller/deals -- create deal
        $router->post(
            '/v1/seller/deals',
            static fn(Request $req) => $sellerDealController->create($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // PUT /v1/seller/deals/{id} -- edit draft deal
        $router->put(
            '/v1/seller/deals/{id}',
            static fn(Request $req) => $sellerDealController->update($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // GET /v1/seller/deals -- seller's deals
        $router->get(
            '/v1/seller/deals',
            static fn(Request $req) => $sellerDealController->listMine($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // GET /v1/seller/deals/{id} -- deal detail
        $router->get(
            '/v1/seller/deals/{id}',
            static fn(Request $req) => $sellerDealController->show($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // GET /v1/seller/orders -- seller's fulfillment orders
        $router->get(
            '/v1/seller/orders',
            static fn(Request $req) => self::sellerOrders($req, $fulfillmentOrderModel),
            [$authMiddleware, $sellerMiddleware],
        );

        // POST /v1/seller/orders/{id}/confirm -- confirm fulfillment order
        $router->post(
            '/v1/seller/orders/{id}/confirm',
            static fn(Request $req) => self::sellerConfirmOrder($req, $fulfillmentOrderModel),
            [$authMiddleware, $sellerMiddleware],
        );

        // =====================================================================
        // INTERNAL / OPS ROUTES
        // =====================================================================

        // GET /v1/internal/orders -- all orders with filters
        $router->get(
            '/v1/internal/orders',
            static fn(Request $req) => $orderAdminController->list($req),
            [$authMiddleware, $opsMiddleware],
        );

        // GET /v1/internal/orders/{id} -- order detail
        $router->get(
            '/v1/internal/orders/{id}',
            static fn(Request $req) => $orderAdminController->show($req),
            [$authMiddleware, $opsMiddleware],
        );

        // POST /v1/internal/orders/{id}/release -- trigger fulfillment
        $router->post(
            '/v1/internal/orders/{id}/release',
            static fn(Request $req) => $orderAdminController->releaseToSeller($req),
            [$authMiddleware, $procurementLeadMiddleware],
        );

        // GET /v1/internal/offers/{id}/moq-status -- MOQ status per offer
        $router->get(
            '/v1/internal/offers/{id}/moq-status',
            static fn(Request $req) => $orderAdminController->monitorMOQ($req),
            [$authMiddleware, $procurementMiddleware],
        );

        // POST /v1/internal/orders/{id}/cancel -- admin cancel
        $router->post(
            '/v1/internal/orders/{id}/cancel',
            static fn(Request $req) => $orderAdminController->cancelOrder($req),
            [$authMiddleware, $opsMiddleware],
        );
    }

    // -------------------------------------------------------------------------
    // Inline seller handlers (simple enough to avoid a full controller)
    // -------------------------------------------------------------------------

    /**
     * GET /v1/seller/orders
     */
    private static function sellerOrders(Request $request, SellerFulfillmentOrder $model): \App\Core\Http\Response
    {
        $userCtx = $request->getParam('_user');
        $sellerId = 0;

        if (is_string($userCtx)) {
            $decoded  = json_decode($userCtx, true);
            $sellerId = (int) ($decoded['seller_id'] ?? 0);
        }

        if ($sellerId <= 0) {
            return \App\Core\Http\Response::forbidden('Seller profile not found');
        }

        $page    = max(1, (int) $request->getQuery('page', 1));
        $perPage = min(100, max(1, (int) $request->getQuery('per_page', 20)));

        $orders = $model->listBySeller($sellerId, $page, $perPage);

        return \App\Core\Http\Response::json(['data' => $orders]);
    }

    /**
     * POST /v1/seller/orders/{id}/confirm
     */
    private static function sellerConfirmOrder(Request $request, SellerFulfillmentOrder $model): \App\Core\Http\Response
    {
        $id = (int) ($request->getParam('id') ?? 0);
        if ($id <= 0) {
            return \App\Core\Http\Response::badRequest('Fulfillment order ID is required');
        }

        $userCtx = $request->getParam('_user');
        $sellerId = 0;

        if (is_string($userCtx)) {
            $decoded  = json_decode($userCtx, true);
            $sellerId = (int) ($decoded['seller_id'] ?? 0);
        }

        $order = $model->find($id);
        if ($order === null) {
            return \App\Core\Http\Response::notFound('Fulfillment order not found');
        }

        // Ownership check
        if ((int) $order['seller_id'] !== $sellerId) {
            return \App\Core\Http\Response::notFound('Fulfillment order not found');
        }

        $confirmRef = (string) ($request->getBody('confirmation_ref') ?? '');

        $updated = $model->updateStatus(
            $id,
            SellerFulfillmentOrder::STATUS_PENDING_CONFIRMATION,
            SellerFulfillmentOrder::STATUS_CONFIRMED,
        );

        if (!$updated) {
            return \App\Core\Http\Response::badRequest('Cannot confirm order in current status');
        }

        if (!empty($confirmRef)) {
            $model->setConfirmationRef($id, $confirmRef);
        }

        return \App\Core\Http\Response::json($model->find($id));
    }
}
