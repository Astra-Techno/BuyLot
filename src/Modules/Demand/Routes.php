<?php

declare(strict_types=1);

namespace App\Modules\Demand;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Modules\Demand\Controllers\BuyRequestController;
use App\Modules\Demand\Controllers\DemandController;
use App\Modules\Demand\Models\BuyerPricePreference;
use App\Modules\Demand\Models\BuyRequest;
use App\Modules\Demand\Models\DemandGroup;
use App\Modules\Demand\Models\GroupCompatibilityRule;
use App\Modules\Demand\Models\GroupIntentEvent;
use App\Modules\Demand\Models\GroupMembership;
use App\Modules\Demand\Services\DemandAggregationService;
use App\Modules\Demand\Services\DemandMatchingService;
use App\Modules\Demand\Services\IntentService;
use App\Modules\Identity\Middleware\RoleMiddleware;

/**
 * Register all Demand module routes onto the shared Router.
 *
 * Call from public/index.php:
 *
 *   \App\Modules\Demand\Routes::register($router, $connection);
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
        $buyRequestModel       = new BuyRequest($connection);
        $pricePreferenceModel  = new BuyerPricePreference($connection);
        $demandGroupModel      = new DemandGroup($connection);
        $membershipModel       = new GroupMembership($connection);
        $intentEventModel      = new GroupIntentEvent($connection);
        $compatibilityModel    = new GroupCompatibilityRule($connection);

        // Services
        $matchingService    = new DemandMatchingService(
            $connection,
            $buyRequestModel,
            $demandGroupModel,
            $membershipModel,
        );
        $intentService      = new IntentService($membershipModel, $intentEventModel);
        $aggregationService = new DemandAggregationService(
            $connection,
            $demandGroupModel,
            $membershipModel,
            $pricePreferenceModel,
        );

        // Controllers
        $buyRequestController = new BuyRequestController(
            $buyRequestModel,
            $pricePreferenceModel,
            $demandGroupModel,
            $membershipModel,
            $matchingService,
            $intentService,
        );
        $demandController = new DemandController($demandGroupModel, $aggregationService);

        // -------------------------------------------------------------------------
        // Middleware
        // -------------------------------------------------------------------------

        $authMiddleware     = new AuthMiddleware($jwtHandler);
        $internalMiddleware = new RoleMiddleware('procurement_staff', 'super_admin');

        // -------------------------------------------------------------------------
        // Buyer routes -- authenticated
        // -------------------------------------------------------------------------

        // POST /v1/buy-requests -- create a new buy request (auto-matches to group)
        $router->post(
            '/v1/buy-requests',
            static fn(Request $req) => $buyRequestController->create($req),
            [$authMiddleware],
        );

        // GET /v1/buy-requests/{id} -- show own buy request detail
        $router->get(
            '/v1/buy-requests/{id}',
            static fn(Request $req) => $buyRequestController->show($req),
            [$authMiddleware],
        );

        // GET /v1/me/requests -- list own buy requests
        $router->get(
            '/v1/me/requests',
            static fn(Request $req) => $buyRequestController->listMine($req),
            [$authMiddleware],
        );

        // DELETE /v1/buy-requests/{id} -- cancel own buy request
        $router->delete(
            '/v1/buy-requests/{id}',
            static fn(Request $req) => $buyRequestController->cancel($req),
            [$authMiddleware],
        );

        // POST /v1/groups/{id}/join -- join a demand group
        $router->post(
            '/v1/groups/{id}/join',
            static fn(Request $req) => $buyRequestController->joinGroup($req),
            [$authMiddleware],
        );

        // POST /v1/groups/{id}/reconfirm -- reconfirm purchase readiness (L1->L2)
        $router->post(
            '/v1/groups/{id}/reconfirm',
            static fn(Request $req) => $buyRequestController->reconfirmIntent($req),
            [$authMiddleware],
        );

        // -------------------------------------------------------------------------
        // Public routes -- no auth required
        // -------------------------------------------------------------------------

        // GET /v1/groups -- list active demand groups (privacy-safe stats)
        $router->get(
            '/v1/groups',
            static fn(Request $req) => $demandController->listActiveGroups($req),
        );

        // GET /v1/groups/{id} -- show demand group detail (privacy-safe stats)
        $router->get(
            '/v1/groups/{id}',
            static fn(Request $req) => $demandController->showGroup($req),
        );

        // -------------------------------------------------------------------------
        // Internal routes -- auth + procurement/admin role
        // -------------------------------------------------------------------------

        // GET /v1/internal/groups/{id}/detail -- full group detail with budget aggregates
        $router->get(
            '/v1/internal/groups/{id}/detail',
            static fn(Request $req) => $demandController->getGroupDetail($req),
            [$authMiddleware, $internalMiddleware],
        );

        // GET /v1/internal/groups/{id}/demand-curve -- price/quantity demand curve
        $router->get(
            '/v1/internal/groups/{id}/demand-curve',
            static fn(Request $req) => $demandController->getDemandCurve($req),
            [$authMiddleware, $internalMiddleware],
        );
    }
}
