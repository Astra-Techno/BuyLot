<?php

declare(strict_types=1);

namespace App\Modules\ProcurementCRM;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Modules\Identity\Middleware\RoleMiddleware;
use App\Modules\ProcurementCRM\Controllers\ProcurementController;
use App\Modules\ProcurementCRM\Models\NegotiationAttachment;
use App\Modules\ProcurementCRM\Models\NegotiationEvent;
use App\Modules\ProcurementCRM\Models\ProcurementCase;
use App\Modules\ProcurementCRM\Models\ProcurementTask;
use App\Modules\ProcurementCRM\Models\SupplierContact;
use App\Modules\ProcurementCRM\Models\SupplierLead;
use App\Modules\ProcurementCRM\Services\ProcurementService;
use App\Modules\ProcurementCRM\Services\SupplierDirectoryService;

/**
 * Register all ProcurementCRM module routes onto the shared Router.
 *
 * Call from public/index.php (after Identity routes):
 *
 *   \App\Modules\ProcurementCRM\Routes::register($router, $connection);
 *
 * All routes require:
 *   - Valid JWT (AuthMiddleware)
 *   - procurement_agent OR procurement_admin role (RoleMiddleware)
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

        $caseModel       = new ProcurementCase($connection);
        $leadModel       = new SupplierLead($connection);
        $contactModel    = new SupplierContact($connection);
        $eventModel      = new NegotiationEvent($connection);
        $attachmentModel = new NegotiationAttachment($connection);
        $taskModel       = new ProcurementTask($connection);

        $procurementService = new ProcurementService(
            $caseModel,
            $eventModel,
            $leadModel,
            $contactModel,
        );

        $directoryService = new SupplierDirectoryService($leadModel, $connection);

        $controller = new ProcurementController(
            $caseModel,
            $leadModel,
            $contactModel,
            $eventModel,
            $attachmentModel,
            $taskModel,
            $procurementService,
            $directoryService,
        );

        // -------------------------------------------------------------------------
        // Middleware
        // -------------------------------------------------------------------------

        $authMiddleware      = new AuthMiddleware($jwtHandler);
        $procurementMiddleware = new RoleMiddleware('procurement_agent', 'procurement_admin');

        $guard = [$authMiddleware, $procurementMiddleware];

        // -------------------------------------------------------------------------
        // Case queue & detail
        // -------------------------------------------------------------------------

        // GET /v1/internal/procurement/cases
        $router->get(
            '/v1/internal/procurement/cases',
            static fn(Request $req) => $controller->listCases($req),
            $guard,
        );

        // GET /v1/internal/procurement/cases/{id}
        $router->get(
            '/v1/internal/procurement/cases/{id}',
            static fn(Request $req) => $controller->showCase($req),
            $guard,
        );

        // POST /v1/internal/procurement/cases
        $router->post(
            '/v1/internal/procurement/cases',
            static fn(Request $req) => $controller->createCase($req),
            $guard,
        );

        // -------------------------------------------------------------------------
        // Case mutations
        // -------------------------------------------------------------------------

        // POST /v1/internal/cases/{id}/assign
        $router->post(
            '/v1/internal/cases/{id}/assign',
            static fn(Request $req) => $controller->assignCase($req),
            $guard,
        );

        // PUT /v1/internal/cases/{id}/stage
        $router->put(
            '/v1/internal/cases/{id}/stage',
            static fn(Request $req) => $controller->updateStage($req),
            $guard,
        );

        // -------------------------------------------------------------------------
        // Supplier leads & contacts
        // -------------------------------------------------------------------------

        // POST /v1/internal/cases/{id}/supplier-leads
        $router->post(
            '/v1/internal/cases/{id}/supplier-leads',
            static fn(Request $req) => $controller->addSupplierLead($req),
            $guard,
        );

        // POST /v1/internal/cases/{id}/contacts
        $router->post(
            '/v1/internal/cases/{id}/contacts',
            static fn(Request $req) => $controller->logContact($req),
            $guard,
        );

        // -------------------------------------------------------------------------
        // Quotes & negotiation events
        // -------------------------------------------------------------------------

        // POST /v1/internal/cases/{id}/offline-quotes
        $router->post(
            '/v1/internal/cases/{id}/offline-quotes',
            static fn(Request $req) => $controller->addOfflineQuote($req),
            $guard,
        );

        // POST /v1/internal/cases/{id}/negotiation-events
        $router->post(
            '/v1/internal/cases/{id}/negotiation-events',
            static fn(Request $req) => $controller->addNegotiationEvent($req),
            $guard,
        );

        // -------------------------------------------------------------------------
        // Attachments
        // -------------------------------------------------------------------------

        // POST /v1/internal/negotiation-events/{id}/attachments
        $router->post(
            '/v1/internal/negotiation-events/{id}/attachments',
            static fn(Request $req) => $controller->uploadAttachment($req),
            $guard,
        );

        // -------------------------------------------------------------------------
        // Tasks
        // -------------------------------------------------------------------------

        // GET /v1/internal/cases/{id}/tasks
        $router->get(
            '/v1/internal/cases/{id}/tasks',
            static fn(Request $req) => $controller->listTasks($req),
            $guard,
        );

        // POST /v1/internal/cases/{id}/tasks
        $router->post(
            '/v1/internal/cases/{id}/tasks',
            static fn(Request $req) => $controller->createTask($req),
            $guard,
        );

        // PUT /v1/internal/tasks/{id}/complete
        $router->put(
            '/v1/internal/tasks/{id}/complete',
            static fn(Request $req) => $controller->completeTask($req),
            $guard,
        );

        // -------------------------------------------------------------------------
        // Supplier directory
        // -------------------------------------------------------------------------

        // GET /v1/internal/suppliers
        $router->get(
            '/v1/internal/suppliers',
            static fn(Request $req) => $controller->listSuppliers($req),
            $guard,
        );
    }
}
