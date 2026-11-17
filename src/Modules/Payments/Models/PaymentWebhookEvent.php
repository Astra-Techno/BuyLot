<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * PaymentWebhookEvent model.
 *
 * Table: payment_webhook_events
 * APPEND-ONLY: rows are never updated or deleted after creation.
 * Idempotent: rejects duplicate provider_event_id per provider.
 */
class PaymentWebhookEvent
{
    private const TABLE = 'payment_webhook_events';

    private const COLUMNS = [
        'id', 'provider', 'provider_event_id', 'signature_valid',
        'payload_ref', 'processed_at', 'created_at',
    ];

    public function __construct(private Connection $connection) {}

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();
    }

    /**
     * Check if a provider event has already been recorded.
     */
    public function existsByProviderEventId(string $provider, string $providerEventId): bool
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select('id')
            ->where('provider', $provider)
            ->where('provider_event_id', $providerEventId)
            ->first();

        return $row !== null;
    }

    /**
     * Find by provider and event ID.
     */
    public function findByProviderEventId(string $provider, string $providerEventId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('provider', $provider)
            ->where('provider_event_id', $providerEventId)
            ->first();
    }

    /**
     * Record a new webhook event. APPEND-ONLY.
     *
     * Returns null if the event was already recorded (idempotent skip).
     * Returns the created row if newly inserted.
     */
    public function record(
        string $provider,
        string $providerEventId,
        bool   $signatureValid,
        ?string $payloadRef = null,
    ): ?array {
        // Idempotency: skip if already exists
        if ($this->existsByProviderEventId($provider, $providerEventId)) {
            return null;
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'provider'          => $provider,
                'provider_event_id' => $providerEventId,
                'signature_valid'   => $signatureValid ? 1 : 0,
                'payload_ref'       => $payloadRef,
                'created_at'        => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id);
    }

    /**
     * Mark a webhook event as processed.
     *
     * This is the ONLY mutation allowed: setting the processed_at timestamp
     * on an existing row to indicate it has been handled.
     */
    public function markProcessed(int $id): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        // Use raw SQL because QueryBuilder cannot express IS NULL with bound params
        $rows = $this->connection->execute(
            "UPDATE payment_webhook_events SET processed_at = ? WHERE id = ? AND processed_at IS NULL",
            [$now, $id],
        );

        return $rows > 0;
    }
}
