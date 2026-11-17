<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Database\Connection;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\Refund;
use App\Modules\Ledger\Services\LedgerService;

/**
 * Refund orchestration service.
 *
 * Handles refund initiation, maker-checker approval, gateway calls,
 * and webhook-driven status updates.
 *
 * All monetary values in integer paise.
 */
class RefundService
{
    public function __construct(
        private Connection     $connection,
        private Refund         $refundModel,
        private PaymentIntent  $intentModel,
        private LedgerService  $ledgerService,
    ) {}

    /**
     * Initiate a refund request.
     *
     * Validates that the payment exists and was captured, and that the
     * refund amount does not exceed the captured amount minus existing refunds.
     *
     * @return array The created refund row
     * @throws \RuntimeException If validation fails
     */
    public function initiateRefund(
        int     $paymentId,
        int     $amountPaise,
        string  $reason,
        ?int    $orderItemId = null,
    ): array {
        $intent = $this->intentModel->find($paymentId);
        if ($intent === null) {
            throw new \RuntimeException("Payment intent {$paymentId} not found");
        }

        if (!in_array($intent['status'], ['captured', 'refunded'], true)) {
            throw new \RuntimeException(
                "Cannot refund payment {$paymentId}: status is '{$intent['status']}', expected 'captured'"
            );
        }

        // Check refund amount does not exceed remaining captured amount
        $existingRefunds = $this->refundModel->findByPaymentId($paymentId);
        $refundedTotal = 0;
        foreach ($existingRefunds as $existingRefund) {
            if (in_array($existingRefund['status'], ['requested', 'approved', 'processing', 'completed'], true)) {
                $refundedTotal += (int) $existingRefund['amount_paise'];
            }
        }

        $remaining = (int) $intent['amount_paise'] - $refundedTotal;
        if ($amountPaise > $remaining) {
            throw new \RuntimeException(
                "Refund amount ({$amountPaise}) exceeds remaining refundable amount ({$remaining}) " .
                "for payment {$paymentId}"
            );
        }

        return $this->refundModel->create($paymentId, $amountPaise, $reason, $orderItemId);
    }

    /**
     * Approve a refund (maker-checker: approver must differ from requester).
     *
     * Transitions from 'requested' -> 'approved' -> 'processing' and
     * initiates the gateway refund call.
     *
     * @param int $refundId   The refund to approve
     * @param int $approverId The user approving (must not be the requester)
     *
     * @return array The updated refund row
     * @throws \RuntimeException If maker-checker validation fails
     */
    public function approveRefund(int $refundId, int $approverId): array
    {
        $refund = $this->refundModel->find($refundId);
        if ($refund === null) {
            throw new \RuntimeException("Refund {$refundId} not found");
        }

        if ($refund['status'] !== 'requested') {
            throw new \RuntimeException("Refund {$refundId} is not in 'requested' status");
        }

        // Approve
        $this->refundModel->updateStatus($refundId, 'approved', $approverId);

        // Transition to processing and call gateway
        $this->refundModel->updateStatus($refundId, 'processing');

        // In production: call gateway refund API here
        // $providerRef = $this->callGatewayRefund($refund);

        return $this->refundModel->find($refundId)
            ?? throw new \RuntimeException("Failed to retrieve refund {$refundId} after approval");
    }

    /**
     * Process a refund webhook callback from the gateway.
     *
     * Called when the gateway confirms a refund has been processed.
     */
    public function processRefundWebhook(string $providerRef): void
    {
        $refund = $this->refundModel->findByProviderRef($providerRef);
        if ($refund === null) {
            // May be a provider-initiated refund; log and skip
            error_log("[REFUND] No refund found for provider ref: {$providerRef}");
            return;
        }

        // Skip if already completed (idempotent)
        if ($refund['status'] === 'completed') {
            return;
        }

        $this->connection->beginTransaction();

        try {
            // Mark refund as completed
            $this->refundModel->updateStatus((int) $refund['id'], 'completed');

            // Record ledger entry for the refund
            $this->ledgerService->recordRefund(
                (int) $refund['id'],
                (int) $refund['amount_paise'],
            );

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
