<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Ledger\Services\LedgerService;

/**
 * Core payment orchestration service.
 *
 * Handles payment intent creation with idempotency, webhook processing,
 * and coordination between gateway events and internal state.
 *
 * NEVER trusts client-side payment confirmation -- only gateway webhooks.
 * All monetary values in integer paise.
 */
class PaymentService
{
    public function __construct(
        private Connection            $connection,
        private PaymentIntent         $intentModel,
        private PaymentWebhookEvent   $webhookModel,
        private LedgerService         $ledgerService,
    ) {}

    /**
     * Create a payment intent with idempotency.
     *
     * If an intent with the same idempotency key already exists, returns
     * the existing one (no duplicate creation).
     *
     * @return array The payment intent row
     */
    public function createIntent(int $orderId, int $amountPaise, string $idempotencyKey): array
    {
        // Check for duplicate first
        $existing = $this->intentModel->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $provider = $this->getDefaultProvider();

        return $this->intentModel->create($orderId, $provider, $amountPaise, $idempotencyKey);
    }

    /**
     * Initiate checkout: transition intent from created -> pending and return
     * gateway-specific checkout parameters.
     *
     * @return array{intent: array, checkout_url: string|null, checkout_params: array}
     */
    public function initiateCheckout(int $intentId): array
    {
        $intent = $this->intentModel->find($intentId);
        if ($intent === null) {
            throw new \RuntimeException("Payment intent {$intentId} not found");
        }

        // Transition to pending
        $this->intentModel->updateStatus((int) $intent['id'], 'pending');

        // In production, call gateway API to create a checkout session.
        // For sandbox mode, return mock checkout params.
        $checkoutUrl    = null;
        $checkoutParams = [];

        if ($this->isSandboxMode()) {
            $checkoutParams = [
                'sandbox'        => true,
                'intent_id'      => $intent['id'],
                'amount_paise'   => $intent['amount_paise'],
                'provider'       => $intent['provider'],
                'callback_url'   => Config::env('APP_URL', '') . '/v1/webhooks/payments/' . $intent['provider'],
            ];
        } else {
            // Gateway-specific checkout session creation would go here
            $checkoutParams = [
                'provider'    => $intent['provider'],
                'amount'      => (int) $intent['amount_paise'],
                'currency'    => 'INR',
                'order_id'    => $intent['order_id'],
                'provider_ref' => $intent['provider_ref'],
            ];
        }

        $updatedIntent = $this->intentModel->find($intentId);

        return [
            'intent'          => $updatedIntent ?? $intent,
            'checkout_url'    => $checkoutUrl,
            'checkout_params' => $checkoutParams,
        ];
    }

    /**
     * Process an incoming webhook event (idempotent).
     *
     * 1. Record the webhook event (skip if duplicate provider_event_id)
     * 2. Parse the event type
     * 3. Dispatch to the appropriate handler
     *
     * Returns true if processed, false if skipped (duplicate).
     */
    public function processWebhook(string $provider, string $eventId, array $payload): bool
    {
        // Step 1: Record event (idempotent)
        $event = $this->webhookModel->record(
            provider:        $provider,
            providerEventId: $eventId,
            signatureValid:  true, // Caller verified before reaching here
            payloadRef:      null, // In production, store payload to object storage
        );

        if ($event === null) {
            // Duplicate event -- already processed
            return false;
        }

        // Step 2: Parse event type from payload
        $eventType = $this->parseEventType($provider, $payload);

        // Step 3: Dispatch to handler
        try {
            match ($eventType) {
                'payment.captured'    => $this->handlePaymentCaptured($provider, $payload),
                'payment.authorized'  => $this->handlePaymentAuthorized($provider, $payload),
                'payment.failed'      => $this->handlePaymentFailed($provider, $payload),
                'refund.processed'    => $this->handleRefundProcessed($provider, $payload),
                default               => null, // Unknown event type -- log and skip
            };

            $this->webhookModel->markProcessed((int) $event['id']);
        } catch (\Throwable $e) {
            // Log the error but don't re-throw -- the webhook event is recorded
            // and can be retried via a background job
            error_log(sprintf(
                '[PAYMENT_WEBHOOK] Processing failed: provider=%s event_id=%s error=%s',
                $provider,
                $eventId,
                $e->getMessage(),
            ));
        }

        return true;
    }

    /**
     * Handle a payment.captured event from the gateway.
     *
     * Updates payment intent status and records the ledger entry.
     */
    public function handlePaymentCaptured(string $provider, array $payload): void
    {
        $providerRef = $this->extractProviderRef($provider, $payload);
        $intent = $this->intentModel->findByProviderRef($provider, $providerRef);

        if ($intent === null) {
            // Try to match by other means (order_id from payload)
            $orderId = $this->extractOrderId($provider, $payload);
            if ($orderId !== null) {
                $intent = $this->intentModel->findByOrderId($orderId);
            }
        }

        if ($intent === null) {
            throw new \RuntimeException("No payment intent found for {$provider} ref: {$providerRef}");
        }

        // Skip if already captured (idempotent)
        if ($intent['status'] === 'captured') {
            return;
        }

        $this->connection->beginTransaction();

        try {
            // Update intent status
            $this->intentModel->updateStatus(
                (int) $intent['id'],
                'captured',
                $providerRef,
            );

            // Record double-entry ledger posting
            $this->ledgerService->recordPayment(
                (int) $intent['order_id'],
                (int) $intent['amount_paise'],
            );

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Handle a payment.failed event from the gateway.
     */
    public function handlePaymentFailed(string $provider, array $payload): void
    {
        $providerRef = $this->extractProviderRef($provider, $payload);
        $intent = $this->intentModel->findByProviderRef($provider, $providerRef);

        if ($intent === null) {
            $orderId = $this->extractOrderId($provider, $payload);
            if ($orderId !== null) {
                $intent = $this->intentModel->findByOrderId($orderId);
            }
        }

        if ($intent === null) {
            throw new \RuntimeException("No payment intent found for {$provider} ref: {$providerRef}");
        }

        // Skip if already in a terminal state (idempotent)
        if (in_array($intent['status'], ['failed', 'captured', 'refunded', 'cancelled'], true)) {
            return;
        }

        $this->intentModel->updateStatus((int) $intent['id'], 'failed', $providerRef);

        // Notify buyer would go here (via event/queue)
    }

    /**
     * Handle a payment.authorized event (optional step, gateway-dependent).
     */
    public function handlePaymentAuthorized(string $provider, array $payload): void
    {
        $providerRef = $this->extractProviderRef($provider, $payload);
        $intent = $this->intentModel->findByProviderRef($provider, $providerRef);

        if ($intent === null) {
            $orderId = $this->extractOrderId($provider, $payload);
            if ($orderId !== null) {
                $intent = $this->intentModel->findByOrderId($orderId);
            }
        }

        if ($intent === null || $intent['status'] !== 'pending') {
            return; // Silently skip
        }

        $this->intentModel->updateStatus((int) $intent['id'], 'authorized', $providerRef);
    }

    /**
     * Handle a refund.processed event from the gateway.
     */
    public function handleRefundProcessed(string $provider, array $payload): void
    {
        // Refund webhook processing is delegated to RefundService
        // This is a pass-through; the actual logic lives there
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function parseEventType(string $provider, array $payload): string
    {
        return match ($provider) {
            'razorpay' => $payload['event'] ?? 'unknown',
            'cashfree' => $payload['type'] ?? $payload['event'] ?? 'unknown',
            default    => $payload['event'] ?? $payload['type'] ?? 'unknown',
        };
    }

    private function extractProviderRef(string $provider, array $payload): string
    {
        return match ($provider) {
            'razorpay' => $payload['payload']['payment']['entity']['id']
                          ?? $payload['payment_id']
                          ?? '',
            'cashfree' => $payload['data']['payment']['cf_payment_id']
                          ?? $payload['cf_payment_id']
                          ?? '',
            default    => $payload['payment_id'] ?? $payload['id'] ?? '',
        };
    }

    private function extractOrderId(string $provider, array $payload): ?int
    {
        $ref = match ($provider) {
            'razorpay' => $payload['payload']['payment']['entity']['notes']['order_id']
                          ?? $payload['notes']['order_id']
                          ?? null,
            'cashfree' => $payload['data']['order']['order_id']
                          ?? $payload['order_id']
                          ?? null,
            default    => $payload['order_id'] ?? null,
        };

        return $ref !== null ? (int) $ref : null;
    }

    private function getDefaultProvider(): string
    {
        return Config::get('payments.default_provider', 'razorpay');
    }

    private function isSandboxMode(): bool
    {
        return Config::env('PAYMENT_SANDBOX', 'true') === 'true'
            || Config::env('APP_ENV', 'production') !== 'production';
    }
}
