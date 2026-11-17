<?php

declare(strict_types=1);

namespace App\Modules\Offers;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Modules\Identity\Middleware\RoleMiddleware;
use App\Modules\Offers\Controllers\OfferAdminController;
use App\Modules\Offers\Controllers\OfferController;
use App\Modules\Offers\Models\NegotiatedOffer;
use App\Modules\Offers\Models\OfferAcceptance;
use App\Modules\Offers\Models\OfferApproval;
use App\Modules\Offers\Models\OfferPriceTier;
use App\Modules\Offers\Models\StockReservation;
use App\Modules\Offers\Services\AcceptanceService;
use App\Modules\Offers\Services\OfferService;
use App\Modules\Offers\Services\StockReservationService;

/**
 * Register all Offers module routes onto the shared Router.
 *
 * Call from public/index.php:
 *
 *   \App\Modules\Offers\Routes::register($router, $connection);
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
        $offerModel       = new NegotiatedOffer($connection);
        $tierModel        = new OfferPriceTier($connection);
        $approvalModel    = new OfferApproval($connection);
        $acceptanceModel  = new OfferAcceptance($connection);
        $reservationModel = new StockReservation($connection);

        // Services
        $offerService = new OfferService($connection, $offerModel, $tierModel, $approvalModel);
        $stockService = new StockReservationService($connection, $reservationModel);
        $acceptanceService = new AcceptanceService(
            $connection,
            $offerModel,
            $acceptanceModel,
            $tierModel,
            $offerService,
            $stockService,
        );

        // Controllers
        $offerController = new OfferController($offerService, $acceptanceService);
        $adminController = new OfferAdminController(
            $connection,
            $offerService,
            $acceptanceService,
            $stockService,
            $acceptanceModel,
            $reservationModel,
        );

        // -------------------------------------------------------------------------
        // Middleware instances
        // -------------------------------------------------------------------------

        $authMiddleware = new AuthMiddleware($jwtHandler);

        // Procurement roles for internal offer management
        $procurementMiddleware     = new RoleMiddleware('procurement_agent', 'procurement_lead');
        $procurementLeadMiddleware = new RoleMiddleware('procurement_lead', 'finance_approver');

        // -------------------------------------------------------------------------
        // Buyer-facing routes — auth required
        // -------------------------------------------------------------------------

        // GET /v1/offers/{id} — offer detail with tiers, terms, expiry (seller identity redacted)
        $router->get(
            '/v1/offers/{id}',
            static fn(Request $req) => $offerController->show($req),
            [$authMiddleware],
        );

        // POST /v1/offers/{id}/accept — accept offer with qty and terms hash verification
        $router->post(
            '/v1/offers/{id}/accept',
            static fn(Request $req) => $offerController->accept($req),
            [$authMiddleware],
        );

        // GET /v1/me/offers — buyer's available offers
        $router->get(
            '/v1/me/offers',
            static fn(Request $req) => $offerController->listAvailable($req),
            [$authMiddleware],
        );

        // -------------------------------------------------------------------------
        // Internal routes — auth + procurement roles
        // -------------------------------------------------------------------------

        // POST /v1/internal/offers — compose offer from bid
        $router->post(
            '/v1/internal/offers',
            static fn(Request $req) => $adminController->create($req),
            [$authMiddleware, $procurementMiddleware],
        );

        // PUT /v1/internal/offers/{id} — edit draft only
        $router->put(
            '/v1/internal/offers/{id}',
            static fn(Request $req) => $adminController->update($req),
            [$authMiddleware, $procurementMiddleware],
        );

        // POST /v1/internal/offers/{id}/submit — submit for approval
        $router->post(
            '/v1/internal/offers/{id}/submit',
            static fn(Request $req) => $adminController->submitForApproval($req),
            [$authMiddleware, $procurementMiddleware],
        );

        // POST /v1/internal/offers/{id}/approve — maker-checker approval
        $router->post(
            '/v1/internal/offers/{id}/approve',
            static fn(Request $req) => $adminController->approve($req),
            [$authMiddleware, $procurementLeadMiddleware],
        );

        // POST /v1/internal/offers/{id}/publish — publish to buyers
        $router->post(
            '/v1/internal/offers/{id}/publish',
            static fn(Request $req) => $adminController->publish($req),
            [$authMiddleware, $procurementLeadMiddleware],
        );

        // GET /v1/internal/offers/{id}/monitor — acceptance/reservation stats
        $router->get(
            '/v1/internal/offers/{id}/monitor',
            static fn(Request $req) => $adminController->monitor($req),
            [$authMiddleware, $procurementMiddleware],
        );

        // DELETE /v1/internal/offers/{id} — cancel offer
        $router->delete(
            '/v1/internal/offers/{id}',
            static fn(Request $req) => $adminController->cancel($req),
            [$authMiddleware, $procurementLeadMiddleware],
        );
    }
}
