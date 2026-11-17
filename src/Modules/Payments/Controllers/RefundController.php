<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Payments\Models\Refund;
use App\Modules\Payments\Services\RefundService;

/**
 * RefundController -- internal refund management endpoints.
 *
 * POST /v1/internal/refunds              -> initiateRefund()
 * POST /v1/internal/refunds/{id}/approve -> approveRefund()
 * GET  /v1/internal/refunds              -> listRefunds()
 */
readonly class RefundController
{
    public function __construct(
        private RefundService $refundService,
        private Refund        $refundModel,
    ) {}

    /**
     * POST /v1/internal/refunds
     *
     * Body: { "payment_id": int, "amount_paise": int, "reason": string, "order_item_id": int|null }
     */
    public function initiateRefund(Request $request): Response
    {
        $body = $request->getBody() ?? [];

        $paymentId   = (int) ($body['payment_id'] ?? 0);
        $amountPaise = (int) ($body['amount_paise'] ?? 0);
        $reason      = (string) ($body['reason'] ?? '');
        $orderItemId = isset($body['order_item_id']) ? (int) $body['order_item_id'] : null;

        if ($paymentId <= 0) {
            throw new HttpException('payment_id is required and must be positive', 422);
        }

        if ($amountPaise <= 0) {
            throw new HttpException('amount_paise must be a positive integer', 422);
        }

        if ($reason === '') {
            throw new HttpException('reason is required', 422);
        }

        try {
            $refund = $this->refundService->initiateRefund(
                $paymentId,
                $amountPaise,
                $reason,
                $orderItemId,
            );

            return Response::created($refund);
        } catch (\RuntimeException $e) {
            throw new HttpException($e->getMessage(), 422);
        }
    }

    /**
     * POST /v1/internal/refunds/{id}/approve
     *
     * Maker-checker approval: the approver is the authenticated internal user.
     */
    public function approveRefund(Request $request): Response
    {
        $refundId   = $this->resolveRefundId($request);
        $approverId = $this->resolveUserId($request);

        try {
            $refund = $this->refundService->approveRefund($refundId, $approverId);
            return Response::json($refund);
        } catch (\RuntimeException $e) {
            throw new HttpException($e->getMessage(), 422);
        }
    }

    /**
     * GET /v1/internal/refunds
     *
     * Query params: status, payment_id, page, per_page
     */
    public function listRefunds(Request $request): Response
    {
        $filters = [];

        $status = $request->getQuery('status');
        if ($status !== null && $status !== '') {
            $filters['status'] = $status;
        }

        $paymentId = $request->getQuery('payment_id');
        if ($paymentId !== null && is_numeric($paymentId)) {
            $filters['payment_id'] = (int) $paymentId;
        }

        $page    = max(1, (int) ($request->getQuery('page') ?? 1));
        $perPage = max(1, min(100, (int) ($request->getQuery('per_page') ?? 20)));

        $result = $this->refundModel->list($filters, $page, $perPage);

        return Response::json($result);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveRefundId(Request $request): int
    {
        $id = $request->getParam('id');
        if ($id === null || !is_numeric($id)) {
            throw new HttpException('Invalid refund ID', 400);
        }
        return (int) $id;
    }

    private function resolveUserId(Request $request): int
    {
        $userCtx = $request->getParam('_user');
        if (!is_array($userCtx) || empty($userCtx['user_id'])) {
            throw new HttpException('Invalid authentication context', 401);
        }
        return (int) $userCtx['user_id'];
    }
}
