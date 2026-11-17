<?php

declare(strict_types=1);

namespace App\Modules\AdminAudit;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Modules\AdminAudit\Controllers\AdminSettingsController;
use App\Modules\AdminAudit\Controllers\AuditController;
use App\Modules\AdminAudit\Controllers\ComplianceController;
use App\Modules\AdminAudit\Models\ConsentRecord;
use App\Modules\AdminAudit\Models\PolicyVersion;
use App\Modules\AdminAudit\Models\RiskFlag;
use App\Modules\AdminAudit\Services\FeatureFlagService;

/**
 * Register all AdminAudit module routes onto the given Router.
 *
 * Call this from your application bootstrap:
 *   \App\Modules\AdminAudit\Routes::register($router, $connection, $jwtHandler);
 *
 * Role enforcement is handled inside each controller method.
 * AuthMiddleware is applied to every route; role checks are enforced at the
 * controller layer (consistent with existing AuditController pattern).
 *
 * Note: AuditMiddleware is intentionally NOT applied to read-only audit log
 * routes. Logging read access to the audit log would create unbounded
 * recursive growth.
 */
final class Routes
{
    public static function register(Router $router, Connection $connection, JwtHandler $jwtHandler): void
    {
        $auth = new AuthMiddleware($jwtHandler);

        // -------------------------------------------------------------------------
        // Shared model / service instances
        // -------------------------------------------------------------------------

        $auditController = new AuditController($connection);

        $featureFlagService  = new FeatureFlagService($connection);
        $riskFlagModel       = new RiskFlag($connection);
        $policyVersionModel  = new PolicyVersion($connection);
        $consentRecordModel  = new ConsentRecord($connection);

        $adminSettingsController = new AdminSettingsController(
            $connection,
            $featureFlagService,
            $riskFlagModel,
            $policyVersionModel,
        );

        $complianceController = new ComplianceController($connection, $consentRecordModel);

        // =========================================================================
        // Audit log routes (read-only)
        // =========================================================================

        // GET /v1/internal/audit
        $router->get(
            '/v1/internal/audit',
            fn(Request $req): Response => $auditController->list($req),
            [$auth],
        );

        // GET /v1/internal/audit/{id}
        $router->get(
            '/v1/internal/audit/{id}',
            function (Request $req) use ($auditController): Response {
                $id = (int) $req->getParam('id');
                return $auditController->show($req, $id);
            },
            [$auth],
        );

        // =========================================================================
        // Platform settings
        // =========================================================================

        // GET /v1/internal/settings
        $router->get(
            '/v1/internal/settings',
            fn(Request $req): Response => $adminSettingsController->getSettings($req),
            [$auth],
        );

        // PUT /v1/internal/settings   [super_admin only]
        $router->put(
            '/v1/internal/settings',
            fn(Request $req): Response => $adminSettingsController->updateSettings($req),
            [$auth],
        );

        // =========================================================================
        // Feature flags
        // =========================================================================

        // GET /v1/internal/feature-flags
        $router->get(
            '/v1/internal/feature-flags',
            fn(Request $req): Response => $adminSettingsController->getFeatureFlags($req),
            [$auth],
        );

        // PUT /v1/internal/feature-flags/{flag}   [super_admin only]
        $router->put(
            '/v1/internal/feature-flags/{flag}',
            function (Request $req) use ($adminSettingsController): Response {
                $flag = (string) $req->getParam('flag');
                return $adminSettingsController->toggleFeatureFlag($req, $flag);
            },
            [$auth],
        );

        // =========================================================================
        // Risk flags
        // =========================================================================

        // GET /v1/internal/risk-flags
        $router->get(
            '/v1/internal/risk-flags',
            fn(Request $req): Response => $adminSettingsController->listRiskFlags($req),
            [$auth],
        );

        // POST /v1/internal/risk-flags
        $router->post(
            '/v1/internal/risk-flags',
            fn(Request $req): Response => $adminSettingsController->createRiskFlag($req),
            [$auth],
        );

        // PUT /v1/internal/risk-flags/{id}/resolve
        $router->put(
            '/v1/internal/risk-flags/{id}/resolve',
            function (Request $req) use ($adminSettingsController): Response {
                $id = (int) $req->getParam('id');
                return $adminSettingsController->resolveRiskFlag($req, $id);
            },
            [$auth],
        );

        // =========================================================================
        // Policy versions
        // =========================================================================

        // GET /v1/internal/policies
        $router->get(
            '/v1/internal/policies',
            fn(Request $req): Response => $adminSettingsController->listPolicies($req),
            [$auth],
        );

        // POST /v1/internal/policies   [super_admin only]
        $router->post(
            '/v1/internal/policies',
            fn(Request $req): Response => $adminSettingsController->publishPolicy($req),
            [$auth],
        );

        // =========================================================================
        // Compliance — consents and account deletion
        // =========================================================================

        // GET /v1/internal/compliance/consents
        $router->get(
            '/v1/internal/compliance/consents',
            fn(Request $req): Response => $complianceController->listConsents($req),
            [$auth],
        );

        // GET /v1/internal/compliance/consents/{userId}
        $router->get(
            '/v1/internal/compliance/consents/{userId}',
            function (Request $req) use ($complianceController): Response {
                $userId = (int) $req->getParam('userId');
                return $complianceController->getConsentStatus($req, $userId);
            },
            [$auth],
        );

        // POST /v1/internal/compliance/account-deletion/{userId}
        $router->post(
            '/v1/internal/compliance/account-deletion/{userId}',
            function (Request $req) use ($complianceController): Response {
                $userId = (int) $req->getParam('userId');
                return $complianceController->processAccountDeletion($req, $userId);
            },
            [$auth],
        );
    }
}
