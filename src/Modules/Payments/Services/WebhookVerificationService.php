<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Core\Config\Config;

/**
 * Webhook signature verification and replay protection.
 *
 * Verifies HMAC signatures from payment providers and rejects
 * stale events (older than 5 minutes) for replay protection.
 */
class WebhookVerificationService
{
    /** Maximum age of a webhook event in seconds before rejection */
    private const REPLAY_WINDOW_SECONDS = 300; // 5 minutes

    /**
     * Verify the HMAC signature of a webhook payload.
     *
     * @param string $provider   Payment provider name (e.g., 'razorpay', 'cashfree')
     * @param string $rawBody    Raw request body (un-parsed)
     * @param string $signature  Signature from the provider header
     * @param string|null $timestamp Event timestamp for replay protection
     *
     * @return bool True if signature is valid
     */
    public function verifySignature(
        string  $provider,
        string  $rawBody,
        string  $signature,
        ?string $timestamp = null,
    ): bool {
        $secret = $this->getWebhookSecret($provider);
        if ($secret === null) {
            $this->logSecurityEvent($provider, 'No webhook secret configured');
            return false;
        }

        // Check replay protection first if timestamp provided
        if ($timestamp !== null && !$this->checkReplayProtection($timestamp)) {
            $this->logSecurityEvent($provider, "Replay detected: timestamp={$timestamp}");
            return false;
        }

        $valid = match ($provider) {
            'razorpay' => $this->verifyRazorpaySignature($rawBody, $signature, $secret),
            'cashfree' => $this->verifyCashfreeSignature($rawBody, $signature, $secret, $timestamp),
            default    => $this->verifyGenericHmac($rawBody, $signature, $secret),
        };

        if (!$valid) {
            $this->logSecurityEvent($provider, 'Invalid HMAC signature');
        }

        return $valid;
    }

    /**
     * Check replay protection: reject events older than the configured window.
     *
     * @param string $timestamp ISO-8601 or Unix timestamp from the provider
     * @return bool True if the event is within the acceptable window
     */
    public function checkReplayProtection(string $timestamp): bool
    {
        $eventTime = is_numeric($timestamp)
            ? (int) $timestamp
            : strtotime($timestamp);

        if ($eventTime === false || $eventTime === 0) {
            return false;
        }

        $age = time() - $eventTime;

        return $age >= 0 && $age <= self::REPLAY_WINDOW_SECONDS;
    }

    /**
     * Log a security event for monitoring/alerting.
     */
    public function logSecurityEvent(string $provider, string $message): void
    {
        // In production, this would dispatch to a security monitoring service.
        // For now, write to error_log with structured data.
        error_log(sprintf(
            '[SECURITY][WEBHOOK] provider=%s message="%s" time=%s',
            $provider,
            $message,
            gmdate('Y-m-d\TH:i:s\Z'),
        ));
    }

    // -------------------------------------------------------------------------
    // Provider-specific verification
    // -------------------------------------------------------------------------

    private function verifyRazorpaySignature(string $rawBody, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    private function verifyCashfreeSignature(
        string $rawBody,
        string $signature,
        string $secret,
        ?string $timestamp,
    ): bool {
        $payload = ($timestamp !== null) ? $timestamp . $rawBody : $rawBody;
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals(base64_encode(hex2bin($expected)), $signature);
    }

    private function verifyGenericHmac(string $rawBody, string $signature, string $secret): bool
    {
        $expected = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($expected, $signature);
    }

    private function getWebhookSecret(string $provider): ?string
    {
        $secret = Config::get("payments.{$provider}.webhook_secret");
        if ($secret === null) {
            $secret = Config::env(strtoupper($provider) . '_WEBHOOK_SECRET');
        }
        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
