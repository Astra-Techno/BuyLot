<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\PaymentService;

/**
 * PaymentController -- buyer-facing payment endpoints.
 *
 * POST /v1/orders/{id}/checkout      -> initiateCheckout()
 * GET  /v1/orders/{id}/payment-status -> getPaymentStatus()
 */
readonly class PaymentController
{
    public function __construct(
        private PaymentService $paymentService,
        private PaymentIntent  $intentModel,
    ) {}

    /**
     * POST /v1/orders/{id}/checkout
     *
     * Creates a payment intent with an idempotency key and returns
     * gateway checkout URL/params. NEVER confirms payment client-side.
     */
    public function initiateCheckout(Request $request): Response
    {
        $orderId = $this->resolveOrderId($request);
        $this->resolveUserId($request); // Ensure caller is authenticated

        $body           = $request->getBody() ?? [];
        $amountPaise    = (int) ($body['amount_paise'] ?? 0);
        $idempotencyKey = (string) ($body['idempotency_key'] ?? '');

        if ($amountPaise <= 0) {
            throw new HttpException('amount_paise must be a positive integer', 422);
        }

        if ($idempotencyKey === '') {
            throw new HttpException('idempotency_key is required', 422);
        }

        $intent = $this->paymentService->createIntent($orderId, $amountPaise, $idempotencyKey);

        $checkout = $this->paymentService->initiateCheckout((int) $intent['id']);

        return Response::json($checkout);
    }

    /**
     * GET /v1/orders/{id}/payment-status
     *
     * Returns the current payment status for the given order.
     * This is INFORMATIONAL only -- NEVER use client-side status to confirm payment.
     * Only gateway webhooks are authoritative.
     */
    public function getPaymentStatus(Request $request): Response
    {
        $orderId = $this->resolveOrderId($request);

        $intent = $this->intentModel->findByOrderId($orderId);

        if ($intent === null) {
            return Response::json([
                'order_id' => $orderId,
                'status'   => 'no_payment',
                'message'  => 'No payment intent found for this order.',
            ]);
        }

        return Response::json([
            'order_id'     => $orderId,
            'payment_id'   => $intent['id'],
            'status'       => $intent['status'],
            'amount_paise' => $intent['amount_paise'],
            'provider'     => $intent['provider'],
            'created_at'   => $intent['created_at'],
            'message'      => 'Informational only. Authoritative status comes from gateway webhooks.',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function resolveOrderId(Request $request): int
    {
        $id = $request->getParam('id');
        if ($id === null || !is_numeric($id)) {
            throw new HttpException('Invalid order ID', 400);
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
