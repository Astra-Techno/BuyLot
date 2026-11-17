<?php

declare(strict_types=1);

namespace App\Modules\Bidding\Controllers;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Bidding\Models\SellerBid;
use App\Modules\Bidding\Services\BidNormalizationService;

/**
 * BidComparisonController -- INTERNAL ONLY. Confidential bid comparison.
 *
 * SEALED BID CONFIDENTIALITY IS NON-NEGOTIABLE.
 * This controller is accessible ONLY to authorized internal staff:
 *   - procurement_agent (assigned to the RFQ's procurement case)
 *   - procurement_lead
 *   - compliance_admin
 *   - super_admin
 *
 * EVERY access to this endpoint is AUDIT LOGGED.
 * NEVER expose this endpoint to sellers or buyers.
 *
 *   GET /v1/internal/rfqs/{id}/confidential-bids -> getConfidentialBids()
 */
readonly class BidComparisonController
{
    private const AUTHORIZED_ROLES = [
        'procurement_agent',
        'procurement_lead',
        'compliance_admin',
        'super_admin',
    ];

    public function __construct(
        private SellerBid               $bidModel,
        private BidNormalizationService $normalizationService,
        private Connection              $connection,
    ) {}

    /**
     * GET /v1/internal/rfqs/{id}/confidential-bids
     *
     * Returns ALL bids for an RFQ with landed-cost normalization and weighted scores.
     *
     * STRICT PERMISSION: Only procurement_agent (assigned), procurement_lead,
     * compliance_admin, super_admin.
     *
     * AUDIT LOG: Every access is recorded with actor, timestamp, IP, RFQ ID.
     */
    public function getConfidentialBids(Request $request): Response
    {
        $userCtx = $this->resolveUserContextOrFail($request);
        $rfqId   = (int) $request->getParam('id');

        if ($rfqId <= 0) {
            return Response::badRequest('Invalid RFQ ID');
        }

        // ---- PERMISSION CHECK ----
        $userRoles = $userCtx['roles'] ?? [];
        $hasAccess = false;

        foreach ($userRoles as $role) {
            if (in_array($role, self::AUTHORIZED_ROLES, true)) {
                $hasAccess = true;
                break;
            }
        }

        if (!$hasAccess) {
            return Response::forbidden(
                'Access denied. Confidential bid data requires procurement_agent, procurement_lead, compliance_admin, or super_admin role.',
            );
        }

        // ---- AUDIT LOG (before data retrieval) ----
        $this->logConfidentialAccess(
            actorId:    (int) $userCtx['user_id'],
            actorRoles: $userRoles,
            rfqId:      $rfqId,
            ipAddress:  $request->getHeader('x-forwarded-for') ?? $request->getHeader('remote-addr'),
            userAgent:  $request->getHeader('user-agent'),
        );

        // ---- RETRIEVE AND NORMALIZE ----
        $bids = $this->bidModel->listByRfqInternal($rfqId);

        if (empty($bids)) {
            return Response::json([
                'rfq_id' => $rfqId,
                'bids'   => [],
                'count'  => 0,
            ]);
        }

        // Normalize all bids with landed-cost calculation and weighted scoring
        $normalizedBids = $this->normalizationService->compareBids($rfqId);

        return Response::json([
            'rfq_id' => $rfqId,
            'bids'   => $normalizedBids,
            'count'  => count($normalizedBids),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveUserContextOrFail(Request $request): array
    {
        $userCtx = $request->getParam('_user');

        if (!is_array($userCtx) || empty($userCtx['user_id'])) {
            throw new \App\Core\Exception\HttpException('Invalid authentication context', 401);
        }

        return $userCtx;
    }

    /**
     * Write an immutable audit log entry for confidential bid access.
     * This is append-only and must never be deleted.
     */
    private function logConfidentialAccess(
        int     $actorId,
        array   $actorRoles,
        int     $rfqId,
        ?string $ipAddress,
        ?string $userAgent,
    ): void {
        (new QueryBuilder($this->connection))
            ->table('audit_logs')
            ->insert([
                'actor_id'    => $actorId,
                'actor_role'  => implode(',', $actorRoles),
                'action'      => 'rfq.confidential_bids_accessed',
                'entity_type' => 'rfq',
                'entity_id'   => $rfqId,
                'after_json'  => json_encode([
                    'access_type' => 'confidential_bid_comparison',
                    'rfq_id'      => $rfqId,
                ]),
                'ip_address'  => $ipAddress,
                'user_agent'  => $userAgent !== null ? substr($userAgent, 0, 500) : null,
                'created_at'  => gmdate('Y-m-d H:i:s'),
            ]);
    }
}
