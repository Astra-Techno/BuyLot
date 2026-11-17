<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Modules\Identity\Middleware\RoleMiddleware;
use App\Modules\Ledger\Models\LedgerAccount;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Models\LedgerPosting;
use App\Modules\Ledger\Services\LedgerService;
use App\Modules\Payments\Controllers\PaymentController;
use App\Modules\Payments\Controllers\RefundController;
use App\Modules\Payments\Controllers\WebhookController;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\RefundService;
use App\Modules\Payments\Services\WebhookVerificationService;
use App\Modules\Settlements\Controllers\SettlementAdminController;
use App\Modules\Settlements\Controllers\SettlementController;
use App\Modules\Settlements\Models\SellerSettlement;
use App\Modules\Settlements\Models\SettlementApproval;
use App\Modules\Settlements\Models\SettlementItem;
use App\Modules\Settlements\Services\ReconciliationService;
use App\Modules\Settlements\Services\SettlementService;

/**
 * Register all Payment, Ledger, and Settlement module routes.
 *
 * Call from public/index.php:
 *
 *   \App\Modules\Payments\Routes::register($router, $connection);
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
        $intentModel    = new PaymentIntent($connection);
        $webhookModel   = new PaymentWebhookEvent($connection);
        $refundModel    = new Refund($connection);
        $accountModel   = new LedgerAccount($connection);
        $entryModel     = new LedgerEntry($connection);
        $postingModel   = new LedgerPosting($connection);
        $settlementModel    = new SellerSettlement($connection);
        $settlementItem     = new SettlementItem($connection);
        $settlementApproval = new SettlementApproval($connection);

        // Services
        $ledgerService       = new LedgerService($connection, $accountModel, $entryModel, $postingModel);
        $verificationService = new WebhookVerificationService();
        $paymentService      = new PaymentService($connection, $intentModel, $webhookModel, $ledgerService);
        $refundService       = new RefundService($connection, $refundModel, $intentModel, $ledgerService);
        $settlementService   = new SettlementService(
            $connection, $settlementModel, $settlementItem, $settlementApproval, $ledgerService,
        );
        $reconciliationService = new ReconciliationService($connection);

        // Controllers
        $paymentController   = new PaymentController($paymentService, $intentModel);
        $webhookController   = new WebhookController($paymentService, $verificationService);
        $refundController    = new RefundController($refundService, $refundModel);
        $settlementCtrl      = new SettlementController($settlementModel, $settlementItem);
        $settlementAdminCtrl = new SettlementAdminController(
            $settlementService, $settlementModel, $settlementItem, $reconciliationService,
        );

        // -------------------------------------------------------------------------
        // Middleware
        // -------------------------------------------------------------------------

        $authMiddleware     = new AuthMiddleware($jwtHandler);
        $sellerMiddleware   = new RoleMiddleware('seller');
        $financeMiddleware  = new RoleMiddleware('finance_operator', 'finance_approver', 'super_admin');
        $internalMiddleware = new RoleMiddleware('finance_operator', 'finance_approver', 'procurement_lead', 'super_admin');

        // -------------------------------------------------------------------------
        // Buyer routes (authenticated)
        // -------------------------------------------------------------------------

        // POST /v1/orders/{id}/checkout
        $router->post(
            '/v1/orders/{id}/checkout',
            static fn(Request $req) => $paymentController->initiateCheckout($req),
            [$authMiddleware],
        );

        // GET /v1/orders/{id}/payment-status
        $router->get(
            '/v1/orders/{id}/payment-status',
            static fn(Request $req) => $paymentController->getPaymentStatus($req),
            [$authMiddleware],
        );

        // -------------------------------------------------------------------------
        // Webhook routes (NO auth -- signature verified by controller)
        // -------------------------------------------------------------------------

        // POST /v1/webhooks/payments/{provider}
        $router->post(
            '/v1/webhooks/payments/{provider}',
            static fn(Request $req) => $webhookController->handleWebhook($req),
        );

        // -------------------------------------------------------------------------
        // Seller routes (authenticated + seller role)
        // -------------------------------------------------------------------------

        // GET /v1/seller/settlements
        $router->get(
            '/v1/seller/settlements',
            static fn(Request $req) => $settlementCtrl->listSettlements($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // GET /v1/seller/settlements/{id}
        $router->get(
            '/v1/seller/settlements/{id}',
            static fn(Request $req) => $settlementCtrl->showSettlement($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // -------------------------------------------------------------------------
        // Internal routes (authenticated + finance/admin role)
        // -------------------------------------------------------------------------

        // POST /v1/internal/refunds
        $router->post(
            '/v1/internal/refunds',
            static fn(Request $req) => $refundController->initiateRefund($req),
            [$authMiddleware, $financeMiddleware],
        );

        // POST /v1/internal/refunds/{id}/approve
        $router->post(
            '/v1/internal/refunds/{id}/approve',
            static fn(Request $req) => $refundController->approveRefund($req),
            [$authMiddleware, $financeMiddleware],
        );

        // GET /v1/internal/refunds
        $router->get(
            '/v1/internal/refunds',
            static fn(Request $req) => $refundController->listRefunds($req),
            [$authMiddleware, $financeMiddleware],
        );

        // GET /v1/internal/settlements
        $router->get(
            '/v1/internal/settlements',
            static fn(Request $req) => $settlementAdminCtrl->listSettlements($req),
            [$authMiddleware, $internalMiddleware],
        );

        // GET /v1/internal/settlements/{id}
        $router->get(
            '/v1/internal/settlements/{id}',
            static fn(Request $req) => $settlementAdminCtrl->showSettlement($req),
            [$authMiddleware, $internalMiddleware],
        );

        // POST /v1/internal/settlements/{id}/approve
        $router->post(
            '/v1/internal/settlements/{id}/approve',
            static fn(Request $req) => $settlementAdminCtrl->approveSettlement($req),
            [$authMiddleware, $financeMiddleware],
        );

        // POST /v1/internal/settlements/{id}/release
        $router->post(
            '/v1/internal/settlements/{id}/release',
            static fn(Request $req) => $settlementAdminCtrl->requestRelease($req),
            [$authMiddleware, $financeMiddleware],
        );

        // GET /v1/internal/reconciliation/exceptions
        $router->get(
            '/v1/internal/reconciliation/exceptions',
            static fn(Request $req) => $settlementAdminCtrl->listReconciliationExceptions($req),
            [$authMiddleware, $financeMiddleware],
        );
    }
}
