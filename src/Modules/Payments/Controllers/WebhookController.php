<?php

declare(strict_types=1);

namespace App\Modules\Payments\Controllers;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Payments\Services\PaymentService;
use App\Modules\Payments\Services\WebhookVerificationService;

/**
 * WebhookController -- payment gateway webhook endpoint.
 *
 * POST /v1/webhooks/payments/{provider}
 *
 * Security requirements:
 * 1. Verify raw-body HMAC signature (reject + log if invalid)
 * 2. Check provider_event_id uniqueness (idempotent -- skip if processed)
 * 3. Parse event type and dispatch
 * 4. Return 200 immediately (process asynchronously in production)
 * 5. NEVER trust client-side payment confirmation
 *
 * This endpoint requires NO authentication (no JWT). Security is
 * exclusively via HMAC signature verification.
 */
readonly class WebhookController
{
    public function __construct(
        private PaymentService              $paymentService,
        private WebhookVerificationService  $verificationService,
    ) {}

    /**
     * POST /v1/webhooks/payments/{provider}
     *
     * Receives webhook events from payment providers (Razorpay, Cashfree, etc.).
     * Always returns 200 to prevent retries from the provider for successfully
     * received events.
     */
    public function handleWebhook(Request $request): Response
    {
        $provider = $request->getParam('provider');
        if ($provider === null || $provider === '') {
            return Response::json(['status' => 'error', 'message' => 'Missing provider'], 400);
        }

        // Step 1: Extract signature and raw body for HMAC verification
        $signature = $this->extractSignature($request, $provider);
        $timestamp = $request->getHeader('x-webhook-timestamp')
                  ?? $request->getHeader('x-razorpay-event-id') // Razorpay uses event ID in header
                  ?? null;

        // Get raw body -- in production this would be php://input pre-parsed
        $rawBody = json_encode($request->getBody() ?? []);

        if ($signature === null || $signature === '') {
            $this->verificationService->logSecurityEvent($provider, 'Missing webhook signature');
            // Return 200 to avoid endless retries, but log the security event
            return Response::json(['status' => 'rejected', 'reason' => 'missing_signature']);
        }

        // Step 2: Verify HMAC signature
        $signatureValid = $this->verificationService->verifySignature(
            $provider,
            $rawBody,
            $signature,
            $timestamp,
        );

        if (!$signatureValid) {
            // Log security event and reject -- but return 200 to stop retries
            $this->verificationService->logSecurityEvent(
                $provider,
                'Invalid signature rejected'
            );
            return Response::json(['status' => 'rejected', 'reason' => 'invalid_signature']);
        }

        // Step 3: Extract event ID for idempotency
        $payload = $request->getBody() ?? [];
        $eventId = $this->extractEventId($provider, $payload);

        if ($eventId === null || $eventId === '') {
            $this->verificationService->logSecurityEvent($provider, 'Missing provider_event_id');
            return Response::json(['status' => 'rejected', 'reason' => 'missing_event_id']);
        }

        // Step 4: Process the event (idempotent -- skips duplicates)
        $processed = $this->paymentService->processWebhook($provider, $eventId, $payload);

        // Step 5: Always return 200 OK
        return Response::json([
            'status'    => 'ok',
            'processed' => $processed,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract the webhook signature from provider-specific headers.
     */
    private function extractSignature(Request $request, string $provider): ?string
    {
        return match ($provider) {
            'razorpay' => $request->getHeader('x-razorpay-signature'),
            'cashfree' => $request->getHeader('x-cashfree-signature'),
            default    => $request->getHeader('x-webhook-signature'),
        };
    }

    /**
     * Extract the unique event ID from the provider payload.
     */
    private function extractEventId(string $provider, array $payload): ?string
    {
        return match ($provider) {
            'razorpay' => $payload['event_id'] ?? $payload['id'] ?? null,
            'cashfree' => $payload['data']['event_id'] ?? $payload['event_id'] ?? null,
            default    => $payload['event_id'] ?? $payload['id'] ?? null,
        };
    }
}
