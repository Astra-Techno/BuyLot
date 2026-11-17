<?php

declare(strict_types=1);

namespace App\Modules\Bidding;

use App\Core\Auth\AuthMiddleware;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Router;
use App\Modules\Bidding\Controllers\BidComparisonController;
use App\Modules\Bidding\Controllers\BidController;
use App\Modules\Bidding\Models\SellerBid;
use App\Modules\Bidding\Models\SellerBidRevision;
use App\Modules\Bidding\Services\BidNormalizationService;
use App\Modules\Bidding\Services\BidService;
use App\Modules\Identity\Middleware\RoleMiddleware;
use App\Modules\RFQ\Models\RFQ;
use App\Modules\RFQ\Models\RFQSellerInvitation;

/**
 * Register all Bidding module routes onto the shared Router.
 *
 * Call from public/index.php:
 *
 *   \App\Modules\Bidding\Routes::register($router, $connection, $authMiddleware);
 *
 * Route groups:
 *
 *   Seller endpoints (auth + seller role):
 *     GET    /v1/seller/rfqs                       -> (RFQ module)
 *     GET    /v1/seller/rfqs/{rfqId}               -> (RFQ module)
 *     POST   /v1/seller/rfqs/{rfqId}/bids          -> submitBid
 *     POST   /v1/seller/bids/{bidId}/revisions     -> reviseBid
 *     GET    /v1/seller/bids                        -> listMyBids
 *     GET    /v1/seller/rfqs/{rfqId}/my-bid         -> showMyBid
 *     DELETE /v1/seller/bids/{bidId}                -> withdrawBid
 *
 *   Internal endpoints (auth + internal roles):
 *     GET    /v1/internal/rfqs/{id}/confidential-bids -> getConfidentialBids
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
        $bidModel        = new SellerBid($connection);
        $revisionModel   = new SellerBidRevision($connection);

        $bidService = new BidService(
            $connection,
            $bidModel,
            $revisionModel,
            $rfqModel,
            $invitationModel,
        );

        $normalizationService = new BidNormalizationService(
            $connection,
            $bidModel,
            $revisionModel,
        );

        $bidController        = new BidController($bidService, $bidModel, $revisionModel);
        $comparisonController = new BidComparisonController($bidModel, $normalizationService, $connection);

        // -------------------------------------------------------------------------
        // Middleware
        // -------------------------------------------------------------------------

        // Seller must be authenticated with seller role
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

        // POST /v1/seller/rfqs/{rfqId}/bids -- Submit a new bid
        $router->post(
            '/v1/seller/rfqs/{rfqId}/bids',
            static fn(Request $req) => $bidController->submitBid($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // POST /v1/seller/bids/{bidId}/revisions -- Revise an existing bid
        $router->post(
            '/v1/seller/bids/{bidId}/revisions',
            static fn(Request $req) => $bidController->reviseBid($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // GET /v1/seller/bids -- List all my bids
        $router->get(
            '/v1/seller/bids',
            static fn(Request $req) => $bidController->listMyBids($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // GET /v1/seller/rfqs/{rfqId}/my-bid -- Show my bid for a specific RFQ
        $router->get(
            '/v1/seller/rfqs/{rfqId}/my-bid',
            static fn(Request $req) => $bidController->showMyBid($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // DELETE /v1/seller/bids/{bidId} -- Withdraw my bid
        $router->delete(
            '/v1/seller/bids/{bidId}',
            static fn(Request $req) => $bidController->withdrawBid($req),
            [$authMiddleware, $sellerMiddleware],
        );

        // -------------------------------------------------------------------------
        // Internal routes -- CONFIDENTIAL bid comparison
        // -------------------------------------------------------------------------

        // GET /v1/internal/rfqs/{id}/confidential-bids
        // Additional permission check inside controller (audit-logged)
        $router->get(
            '/v1/internal/rfqs/{id}/confidential-bids',
            static fn(Request $req) => $comparisonController->getConfidentialBids($req),
            [$authMiddleware, $internalMiddleware],
        );
    }
}
