<?php

declare(strict_types=1);

namespace App\Modules\RFQ;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Modules\Identity\Middleware\RoleMiddleware;
use App\Modules\RFQ\Controllers\RFQAdminController;
use App\Modules\RFQ\Controllers\RFQController;
use App\Modules\RFQ\Models\RFQ;
use App\Modules\RFQ\Models\RFQSellerInvitation;
use App\Modules\RFQ\Services\RFQService;

/**
 * Register all RFQ module routes onto the shared Router.
 *
 * Call from public/index.php:
 *
 *   \App\Modules\RFQ\Routes::register($router, $connection);
 */
class Routes
{
    public static function register(Router $router, Connection $connection, AuthMiddleware $authMiddleware): void
    {
        // -------------------------------------------------------------------------
        // Dependencies
        // -------------------------------------------------------------------------

        $rfqModel        = new RFQ($connection);
        $invitationModel = new RFQSellerInvitation($connection);
        $rfqService      = new RFQService($connection, $rfqModel, $invitationModel);

        $rfqController      = new RFQController($rfqModel, $invitationModel);
        $rfqAdminController = new RFQAdminController($rfqService);

        // -------------------------------------------------------------------------
        // Middleware
        // -------------------------------------------------------------------------

        // Seller must be authenticated
        $sellerMiddleware = new RoleMiddleware('seller');

        // Internal staff: procurement_agent, procurement_lead, compliance_admin, super_admin
        $internalMiddleware = new RoleMiddleware(
            'procurement_agent',
            'procurement_lead',
            'compliance_admin',
            'super_admin',
        );

        // -------------------------------------------------------------------------
        // Seller-facing routes
        // -------------------------------------------------------------------------

        // GET /v1/seller/rfqs
        $router->get(
            '/v1/seller/rfqs',
            static fn(Request $req) => $rfqController->listEligible($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // GET /v1/seller/rfqs/{id}
        $router->get(
            '/v1/seller/rfqs/{id}',
            static fn(Request $req) => $rfqController->show($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // -------------------------------------------------------------------------
        // Internal staff routes
        // -------------------------------------------------------------------------

        // POST /v1/internal/rfqs
        $router->post(
            '/v1/internal/rfqs',
            static fn(Request $req) => $rfqAdminController->create($req),
            [$authMiddleware, $internalMiddleware],
        );

        // PUT /v1/internal/rfqs/{id}
        $router->put(
            '/v1/internal/rfqs/{id}',
            static fn(Request $req) => $rfqAdminController->update($req),
            [$authMiddleware, $internalMiddleware],
        );

        // POST /v1/internal/rfqs/{id}/publish
        $router->post(
            '/v1/internal/rfqs/{id}/publish',
            static fn(Request $req) => $rfqAdminController->publish($req),
            [$authMiddleware, $internalMiddleware],
        );

        // POST /v1/internal/rfqs/{id}/extend
        $router->post(
            '/v1/internal/rfqs/{id}/extend',
            static fn(Request $req) => $rfqAdminController->extendDeadline($req),
            [$authMiddleware, $internalMiddleware],
        );

        // POST /v1/internal/rfqs/{id}/close
        $router->post(
            '/v1/internal/rfqs/{id}/close',
            static fn(Request $req) => $rfqAdminController->close($req),
            [$authMiddleware, $internalMiddleware],
        );

        // POST /v1/internal/rfqs/{id}/invite-sellers
        $router->post(
            '/v1/internal/rfqs/{id}/invite-sellers',
            static fn(Request $req) => $rfqAdminController->inviteSellers($req),
            [$authMiddleware, $internalMiddleware],
        );
    }
}
