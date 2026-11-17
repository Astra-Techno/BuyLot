<?php

declare(strict_types=1);

namespace App\Modules\SupplierVerification;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\Enums\Role;
use App\Core\Database\Connection;
use App\Core\Http\Middleware\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Modules\SupplierVerification\Controllers\SellerOnboardingController;
use App\Modules\SupplierVerification\Controllers\SellerVerificationController;
use App\Modules\SupplierVerification\Models\SellerBankAccount;
use App\Modules\SupplierVerification\Models\SellerProfile;
use App\Modules\SupplierVerification\Models\SellerVerificationCase;
use App\Modules\SupplierVerification\Services\SellerVerificationService;

/**
 * Register all SupplierVerification routes onto the router.
 *
 * Usage (in your bootstrap / index.php):
 *   \App\Modules\SupplierVerification\Routes::register($router, $authMiddleware, $db);
 */
class Routes
{
    /**
     * @param AuthMiddleware            $auth         Standard JWT auth middleware
     * @param MiddlewareInterface[]     $complianceMw Middleware stack for compliance/internal routes
     *                                               (should include auth + role-check for COMPLIANCE_ADMIN)
     */
    public static function register(
        Router $router,
        AuthMiddleware $auth,
        Connection $db,
    ): void {
        // Wire up dependencies
        $sellerProfile    = new SellerProfile($db);
        $verificationCase = new SellerVerificationCase($db);
        $bankAccount      = new SellerBankAccount($db);

        $verificationService = new SellerVerificationService(
            $db,
            $sellerProfile,
            $verificationCase,
            $bankAccount,
        );

        $onboardingController = new SellerOnboardingController(
            $verificationService,
            $sellerProfile,
        );

        $verificationController = new SellerVerificationController(
            $verificationService,
            $sellerProfile,
            $verificationCase,
            $bankAccount,
        );

        // Role-check closures used as lightweight middleware
        $requireCompliance = self::requireRole([Role::COMPLIANCE_ADMIN, Role::SUPER_ADMIN]);

        // =====================================================================
        // Seller self-service (auth required)
        // =====================================================================

        // POST /v1/seller/onboarding — register new seller org + profile
        $router->post(
            '/v1/seller/onboarding',
            fn(Request $req): Response => $onboardingController->register($req),
            [$auth],
        );

        // POST /v1/seller/onboarding/documents — submit KYC/GST docs
        $router->post(
            '/v1/seller/onboarding/documents',
            fn(Request $req): Response => $onboardingController->submitDocuments($req),
            [$auth],
        );

        // GET /v1/seller/onboarding/status — check onboarding progress
        $router->get(
            '/v1/seller/onboarding/status',
            fn(Request $req): Response => $onboardingController->getStatus($req),
            [$auth],
        );

        // PUT /v1/seller/profile — update profile (approved / active sellers only)
        $router->put(
            '/v1/seller/profile',
            fn(Request $req): Response => $onboardingController->updateProfile($req),
            [$auth],
        );

        // =====================================================================
        // Internal / compliance (auth + compliance_admin required)
        // =====================================================================

        // GET /v1/internal/sellers — list all sellers with filters
        $router->get(
            '/v1/internal/sellers',
            fn(Request $req): Response => $verificationController->listSellers($req),
            [$auth, $requireCompliance],
        );

        // GET /v1/internal/sellers/{id} — full seller detail
        $router->get(
            '/v1/internal/sellers/{id}',
            fn(Request $req): Response => $verificationController->showSeller($req),
            [$auth, $requireCompliance],
        );

        // GET /v1/internal/verification-cases — cases awaiting review
        $router->get(
            '/v1/internal/verification-cases',
            fn(Request $req): Response => $verificationController->listPending($req),
            [$auth, $requireCompliance],
        );

        // POST /v1/internal/verification-cases/{id}/review — approve / reject case
        $router->post(
            '/v1/internal/verification-cases/{id}/review',
            fn(Request $req): Response => $verificationController->review($req),
            [$auth, $requireCompliance],
        );

        // PUT /v1/internal/sellers/{id}/status — suspend / change seller status
        $router->put(
            '/v1/internal/sellers/{id}/status',
            fn(Request $req): Response => $verificationController->suspendSeller($req),
            [$auth, $requireCompliance],
        );

        // PUT /v1/internal/sellers/{id}/bank-account — request bank account change
        $router->put(
            '/v1/internal/sellers/{id}/bank-account',
            fn(Request $req): Response => $verificationController->updateBankAccount($req),
            [$auth, $requireCompliance],
        );

        // POST /v1/internal/sellers/{id}/bank-account/approve — approve change (different user)
        $router->post(
            '/v1/internal/sellers/{id}/bank-account/approve',
            fn(Request $req): Response => $verificationController->approveBankChange($req),
            [$auth, $requireCompliance],
        );
    }

    // -------------------------------------------------------------------------
    // Helper: lightweight role-check middleware factory
    // -------------------------------------------------------------------------

    /**
     * Returns a MiddlewareInterface that rejects requests whose JWT _user.role
     * is not in the allowed $roles list.
     *
     * @param Role[] $roles
     */
    private static function requireRole(array $roles): MiddlewareInterface
    {
        $allowedValues = array_map(fn(Role $r) => $r->value, $roles);

        return new class ($allowedValues) implements MiddlewareInterface {
            public function __construct(private readonly array $allowedValues) {}

            public function handle(Request $request, callable $next): Response
            {
                $user = $request->getParam('_user');
                $role = is_array($user) ? ($user['role'] ?? '') : '';

                if (!in_array($role, $this->allowedValues, true)) {
                    return Response::forbidden('Insufficient permissions for this operation.');
                }

                return $next($request);
            }
        };
    }
}
