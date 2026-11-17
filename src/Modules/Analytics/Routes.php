<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Modules\Analytics\Controllers\AnalyticsController;
use App\Modules\Analytics\Services\CommercialKPIService;
use App\Modules\Analytics\Services\DemandFunnelService;
use App\Modules\Analytics\Services\FinanceKPIService;
use App\Modules\Analytics\Services\ProcurementKPIService;
use App\Modules\Analytics\Services\SellerFunnelService;
use App\Modules\Identity\Middleware\RoleMiddleware;

/**
 * Register all Analytics module routes onto the shared Router.
 *
 * Call from public/index.php:
 *
 *   \App\Modules\Analytics\Routes::register($router, $connection);
 *
 * All routes are under /v1/internal/analytics and require:
 *   - A valid JWT (authMiddleware)
 *   - An appropriate role (see per-route comments)
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

        $demandFunnel   = new DemandFunnelService($connection);
        $sellerFunnel   = new SellerFunnelService($connection);
        $procurementKPIs = new ProcurementKPIService($connection);
        $commercialKPIs = new CommercialKPIService($connection);
        $financeKPIs    = new FinanceKPIService($connection);

        $controller = new AnalyticsController(
            $demandFunnel,
            $sellerFunnel,
            $procurementKPIs,
            $commercialKPIs,
            $financeKPIs,
        );

        // -------------------------------------------------------------------------
        // Middleware instances
        // -------------------------------------------------------------------------

        $authMiddleware = new AuthMiddleware($jwtHandler);

        // Admin-only: compliance_admin, super_admin
        $adminMiddleware = new RoleMiddleware('compliance_admin', 'super_admin');

        // Procurement: procurement_lead OR any admin
        $procurementMiddleware = new RoleMiddleware(
            'procurement_lead',
            'compliance_admin',
            'super_admin',
        );

        // Finance: finance_operator, finance_approver, OR any admin
        $financeMiddleware = new RoleMiddleware(
            'finance_operator',
            'finance_approver',
            'compliance_admin',
            'super_admin',
        );

        // -------------------------------------------------------------------------
        // Routes
        // -------------------------------------------------------------------------

        // GET /v1/internal/analytics/demand-funnel  — auth + admin
        $router->get(
            '/v1/internal/analytics/demand-funnel',
            static fn(Request $req) => $controller->demandFunnel($req),
            [$authMiddleware, $adminMiddleware],
        );

        // GET /v1/internal/analytics/seller-funnel  — auth + admin
        $router->get(
            '/v1/internal/analytics/seller-funnel',
            static fn(Request $req) => $controller->sellerFunnel($req),
            [$authMiddleware, $adminMiddleware],
        );

        // GET /v1/internal/analytics/procurement-kpis  — auth + procurement_lead/admin
        $router->get(
            '/v1/internal/analytics/procurement-kpis',
            static fn(Request $req) => $controller->procurementKPIs($req),
            [$authMiddleware, $procurementMiddleware],
        );

        // GET /v1/internal/analytics/commercial-kpis  — auth + admin
        $router->get(
            '/v1/internal/analytics/commercial-kpis',
            static fn(Request $req) => $controller->commercialKPIs($req),
            [$authMiddleware, $adminMiddleware],
        );

        // GET /v1/internal/analytics/finance-kpis
        //     — auth + finance_operator/finance_approver/admin
        $router->get(
            '/v1/internal/analytics/finance-kpis',
            static fn(Request $req) => $controller->financeKPIs($req),
            [$authMiddleware, $financeMiddleware],
        );

        // GET /v1/internal/analytics/dashboard  — auth + admin
        $router->get(
            '/v1/internal/analytics/dashboard',
            static fn(Request $req) => $controller->executiveDashboard($req),
            [$authMiddleware, $adminMiddleware],
        );
    }
}
