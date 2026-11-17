<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Modules\Fulfillment\Controllers\FulfillmentAdminController;
use App\Modules\Fulfillment\Controllers\FulfillmentBuyerController;
use App\Modules\Fulfillment\Controllers\FulfillmentSellerController;
use App\Modules\Fulfillment\Models\BuyerAcknowledgement;
use App\Modules\Fulfillment\Models\DeliveryEvidence;
use App\Modules\Fulfillment\Models\Shipment;
use App\Modules\Fulfillment\Models\ShipmentItem;
use App\Modules\Fulfillment\Services\FulfillmentService;
use App\Modules\Identity\Middleware\RoleMiddleware;
use App\Modules\Quality\Controllers\InspectionController;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionFinding;
use App\Modules\Quality\Services\InspectionService;

/**
 * Register Fulfillment and Quality module routes onto the shared Router.
 *
 * Call from public/index.php:
 *
 *   \App\Modules\Fulfillment\Routes::register($router, $connection);
 *
 * ---
 * Seller routes   — auth + seller role
 * Buyer routes    — auth + own-resource check (no role middleware; service layer guards)
 * Internal routes — auth + ops/quality role
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
        $shipmentModel         = new Shipment($connection);
        $shipmentItemModel     = new ShipmentItem($connection);
        $evidenceModel         = new DeliveryEvidence($connection);
        $acknowledgementModel  = new BuyerAcknowledgement($connection);
        $inspectionModel       = new Inspection($connection);
        $findingModel          = new InspectionFinding($connection);

        // Services
        $fulfillmentService = new FulfillmentService(
            $connection,
            $shipmentModel,
            $shipmentItemModel,
            $evidenceModel,
            $acknowledgementModel,
        );

        $inspectionService = new InspectionService(
            $connection,
            $inspectionModel,
            $findingModel,
        );

        // Controllers
        $sellerController    = new FulfillmentSellerController(
            $fulfillmentService,
            $connection,
            $shipmentModel,
            $shipmentItemModel,
        );

        $buyerController     = new FulfillmentBuyerController($fulfillmentService);

        $adminController     = new FulfillmentAdminController(
            $fulfillmentService,
            $shipmentModel,
            $acknowledgementModel,
        );

        $inspectionController = new InspectionController(
            $inspectionService,
            $inspectionModel,
            $findingModel,
        );

        // -------------------------------------------------------------------------
        // Middleware instances
        // -------------------------------------------------------------------------

        $authMiddleware    = new AuthMiddleware($jwtHandler);
        $sellerMiddleware  = new RoleMiddleware('seller');
        $opsMiddleware     = new RoleMiddleware('ops', 'super_admin');
        $qualityMiddleware = new RoleMiddleware('quality', 'ops', 'super_admin');

        // -------------------------------------------------------------------------
        // Seller routes — auth + seller role
        // -------------------------------------------------------------------------

        // GET /v1/seller/orders
        $router->get(
            '/v1/seller/orders',
            static fn(Request $req) => $sellerController->listOrders($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // POST /v1/seller/orders/{id}/dispatch
        $router->post(
            '/v1/seller/orders/{id}/dispatch',
            static fn(Request $req) => $sellerController->dispatch($req, (int) $req->getParam('id')),
            [$authMiddleware, $sellerMiddleware],
        );

        // PUT /v1/seller/shipments/{id}/tracking
        $router->put(
            '/v1/seller/shipments/{id}/tracking',
            static fn(Request $req) => $sellerController->updateTracking($req, (int) $req->getParam('id')),
            [$authMiddleware, $sellerMiddleware],
        );

        // -------------------------------------------------------------------------
        // Buyer routes — auth only; ownership enforced in service layer
        // -------------------------------------------------------------------------

        // GET /v1/orders/{id}/shipment
        $router->get(
            '/v1/orders/{id}/shipment',
            static fn(Request $req) => $buyerController->trackShipment($req, (int) $req->getParam('id')),
            [$authMiddleware],
        );

        // POST /v1/shipments/{id}/acknowledgements
        $router->post(
            '/v1/shipments/{id}/acknowledgements',
            static fn(Request $req) => $buyerController->acknowledgeDelivery($req, (int) $req->getParam('id')),
            [$authMiddleware],
        );

        // -------------------------------------------------------------------------
        // Internal ops routes — auth + ops role
        // -------------------------------------------------------------------------

        // GET /v1/internal/shipments
        $router->get(
            '/v1/internal/shipments',
            static fn(Request $req) => $adminController->listShipments($req),
            [$authMiddleware, $opsMiddleware],
        );

        // GET /v1/internal/shipments/{id}
        $router->get(
            '/v1/internal/shipments/{id}',
            static fn(Request $req) => $adminController->showShipment($req, (int) $req->getParam('id')),
            [$authMiddleware, $opsMiddleware],
        );

        // POST /v1/internal/shipments/{id}/verify
        $router->post(
            '/v1/internal/shipments/{id}/verify',
            static fn(Request $req) => $adminController->verifyDelivery($req, (int) $req->getParam('id')),
            [$authMiddleware, $opsMiddleware],
        );

        // GET /v1/internal/fulfillment/dashboard
        $router->get(
            '/v1/internal/fulfillment/dashboard',
            static fn(Request $req) => $adminController->trackingDashboard($req),
            [$authMiddleware, $opsMiddleware],
        );

        // -------------------------------------------------------------------------
        // Internal quality routes — auth + quality role
        // -------------------------------------------------------------------------

        // GET /v1/internal/inspections
        $router->get(
            '/v1/internal/inspections',
            static fn(Request $req) => $inspectionController->listPending($req),
            [$authMiddleware, $qualityMiddleware],
        );

        // GET /v1/internal/inspections/{id}
        $router->get(
            '/v1/internal/inspections/{id}',
            static fn(Request $req) => $inspectionController->show($req, (int) $req->getParam('id')),
            [$authMiddleware, $qualityMiddleware],
        );

        // POST /v1/internal/inspections/{id}/assign
        $router->post(
            '/v1/internal/inspections/{id}/assign',
            static fn(Request $req) => $inspectionController->assign($req, (int) $req->getParam('id')),
            [$authMiddleware, $qualityMiddleware],
        );

        // POST /v1/internal/inspections/{id}/start
        $router->post(
            '/v1/internal/inspections/{id}/start',
            static fn(Request $req) => $inspectionController->startInspection($req, (int) $req->getParam('id')),
            [$authMiddleware, $qualityMiddleware],
        );

        // POST /v1/internal/inspections/{id}/findings
        $router->post(
            '/v1/internal/inspections/{id}/findings',
            static fn(Request $req) => $inspectionController->addFinding($req, (int) $req->getParam('id')),
            [$authMiddleware, $qualityMiddleware],
        );

        // POST /v1/internal/inspections/{id}/complete
        $router->post(
            '/v1/internal/inspections/{id}/complete',
            static fn(Request $req) => $inspectionController->complete($req, (int) $req->getParam('id')),
            [$authMiddleware, $qualityMiddleware],
        );

        // GET /v1/internal/orders/{id}/inspections
        $router->get(
            '/v1/internal/orders/{id}/inspections',
            static fn(Request $req) => $inspectionController->listByOrder($req, (int) $req->getParam('id')),
            [$authMiddleware, $qualityMiddleware],
        );
    }
}
