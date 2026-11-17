<?php

declare(strict_types=1);

namespace App\Modules\Disputes;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Modules\Disputes\Controllers\DisputeAdminController;
use App\Modules\Disputes\Controllers\DisputeController;
use App\Modules\Disputes\Models\Dispute;
use App\Modules\Disputes\Models\DisputeAdjustment;
use App\Modules\Disputes\Models\DisputeEvent;
use App\Modules\Disputes\Models\DisputeEvidence;
use App\Modules\Disputes\Services\DisputeService;
use App\Modules\Identity\Middleware\RoleMiddleware;

/**
 * Register all Disputes module routes onto the shared Router.
 *
 * Call from public/index.php:
 *
 *   \App\Modules\Disputes\Routes::register($router, $connection);
 *
 * -------------------------------------------------------------------------
 * Buyer / Seller routes (auth required):
 *   POST /v1/orders/{id}/disputes          — open a dispute
 *   GET  /v1/disputes/{id}                 — view a dispute (own / related)
 *   POST /v1/disputes/{id}/evidence        — submit evidence
 *   POST /v1/disputes/{id}/respond         — accept/reject proposed resolution
 *   GET  /v1/me/disputes                   — list own disputes
 *
 * Internal / Support routes (auth + support role required):
 *   GET  /v1/internal/disputes                        — list all with filters
 *   GET  /v1/internal/disputes/{id}                   — full detail + timeline
 *   POST /v1/internal/disputes/{id}/assign            — assign to agent
 *   POST /v1/internal/disputes/{id}/request-evidence  — request evidence
 *   POST /v1/internal/disputes/{id}/investigate       — start investigation
 *   POST /v1/internal/disputes/{id}/propose-resolution — propose resolution
 *   POST /v1/internal/disputes/{id}/resolve           — resolve (+ optional financial adj.)
 *   POST /v1/internal/disputes/{id}/appeal            — reopen for appeal
 * -------------------------------------------------------------------------
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

        $disputeModel    = new Dispute($connection);
        $eventModel      = new DisputeEvent($connection);
        $evidenceModel   = new DisputeEvidence($connection);
        $adjustmentModel = new DisputeAdjustment($connection);

        $disputeService = new DisputeService(
            $connection,
            $disputeModel,
            $eventModel,
            $evidenceModel,
            $adjustmentModel,
        );

        $disputeController      = new DisputeController($disputeService);
        $disputeAdminController = new DisputeAdminController($disputeService);

        // -------------------------------------------------------------------------
        // Middleware instances
        // -------------------------------------------------------------------------

        $authMiddleware = new AuthMiddleware($jwtHandler);

        // Support team: support_disputes, compliance_admin, super_admin
        $supportMiddleware = new RoleMiddleware(
            'support_disputes',
            'compliance_admin',
            'super_admin',
        );

        // -------------------------------------------------------------------------
        // Buyer / Seller routes — auth required
        // -------------------------------------------------------------------------

        // POST /v1/orders/{id}/disputes
        $router->post(
            '/v1/orders/{id}/disputes',
            static fn(Request $req) => $disputeController->open($req, $req->getParam('id')),
            [$authMiddleware],
        );

        // GET /v1/disputes/{id}
        $router->get(
            '/v1/disputes/{id}',
            static fn(Request $req) => $disputeController->show($req, $req->getParam('id')),
            [$authMiddleware],
        );

        // POST /v1/disputes/{id}/evidence
        $router->post(
            '/v1/disputes/{id}/evidence',
            static fn(Request $req) => $disputeController->submitEvidence($req, $req->getParam('id')),
            [$authMiddleware],
        );

        // POST /v1/disputes/{id}/respond
        $router->post(
            '/v1/disputes/{id}/respond',
            static fn(Request $req) => $disputeController->respondToResolution($req, $req->getParam('id')),
            [$authMiddleware],
        );

        // GET /v1/me/disputes
        $router->get(
            '/v1/me/disputes',
            static fn(Request $req) => $disputeController->listMine($req),
            [$authMiddleware],
        );

        // -------------------------------------------------------------------------
        // Internal / Support routes — auth + support role
        // -------------------------------------------------------------------------

        // GET /v1/internal/disputes
        $router->get(
            '/v1/internal/disputes',
            static fn(Request $req) => $disputeAdminController->list($req),
            [$authMiddleware, $supportMiddleware],
        );

        // GET /v1/internal/disputes/{id}
        $router->get(
            '/v1/internal/disputes/{id}',
            static fn(Request $req) => $disputeAdminController->show($req, $req->getParam('id')),
            [$authMiddleware, $supportMiddleware],
        );

        // POST /v1/internal/disputes/{id}/assign
        $router->post(
            '/v1/internal/disputes/{id}/assign',
            static fn(Request $req) => $disputeAdminController->assign($req, $req->getParam('id')),
            [$authMiddleware, $supportMiddleware],
        );

        // POST /v1/internal/disputes/{id}/request-evidence
        $router->post(
            '/v1/internal/disputes/{id}/request-evidence',
            static fn(Request $req) => $disputeAdminController->requestEvidence($req, $req->getParam('id')),
            [$authMiddleware, $supportMiddleware],
        );

        // POST /v1/internal/disputes/{id}/investigate
        $router->post(
            '/v1/internal/disputes/{id}/investigate',
            static fn(Request $req) => $disputeAdminController->investigate($req, $req->getParam('id')),
            [$authMiddleware, $supportMiddleware],
        );

        // POST /v1/internal/disputes/{id}/propose-resolution
        $router->post(
            '/v1/internal/disputes/{id}/propose-resolution',
            static fn(Request $req) => $disputeAdminController->proposeResolution($req, $req->getParam('id')),
            [$authMiddleware, $supportMiddleware],
        );

        // POST /v1/internal/disputes/{id}/resolve
        // Note: maker-checker for financial adjustments is enforced inside
        // DisputeService::resolveDispute() and DisputeAdjustment::create().
        // For amounts >= MAKER_CHECKER_THRESHOLD_PAISE the request body must
        // supply approved_by != created_by or the service will throw 422.
        $router->post(
            '/v1/internal/disputes/{id}/resolve',
            static fn(Request $req) => $disputeAdminController->resolve($req, $req->getParam('id')),
            [$authMiddleware, $supportMiddleware],
        );

        // POST /v1/internal/disputes/{id}/appeal
        $router->post(
            '/v1/internal/disputes/{id}/appeal',
            static fn(Request $req) => $disputeAdminController->appeal($req, $req->getParam('id')),
            [$authMiddleware, $supportMiddleware],
        );
    }
}
