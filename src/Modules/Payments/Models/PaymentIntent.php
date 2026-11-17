<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * PaymentIntent model.
 *
 * Table: payment_intents
 * States: created, pending, authorized, captured, failed, refunded, cancelled
 * All monetary values in integer paise (1 INR = 100 paise).
 */
class PaymentIntent
{
    private const TABLE = 'payment_intents';

    private const COLUMNS = [
        'id', 'order_id', 'provider', 'provider_ref', 'amount_paise',
        'status', 'idempotency_key', 'created_at', 'updated_at',
    ];

    /** Valid state transitions: from => [to, ...] */
    private const TRANSITIONS = [
        'created'    => ['pending'],
        'pending'    => ['authorized', 'captured', 'failed', 'cancelled'],
        'authorized' => ['captured', 'failed'],
        'captured'   => ['refunded'],
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

    public function findByIdempotencyKey(string $key): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('idempotency_key', $key)
            ->first();
    }

    public function findByProviderRef(string $provider, string $providerRef): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('provider', $provider)
            ->where('provider_ref', $providerRef)
            ->first();
    }

    public function findByOrderId(int $orderId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('order_id', $orderId)
            ->orderBy('created_at', 'DESC')
            ->first();
    }

    /**
     * Create a new payment intent with idempotency check.
     *
     * @return array The created payment intent row
     * @throws \RuntimeException If idempotency key already exists
     */
    public function create(
        int    $orderId,
        string $provider,
        int    $amountPaise,
        string $idempotencyKey,
    ): array {
        if ($amountPaise <= 0) {
            throw new \InvalidArgumentException('amount_paise must be positive');
        }

        // Check idempotency - return existing if found
        $existing = $this->findByIdempotencyKey($idempotencyKey);
        if ($existing !== null) {
            return $existing;
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'order_id'        => $orderId,
                'provider'        => $provider,
                'amount_paise'    => $amountPaise,
                'status'          => 'created',
                'idempotency_key' => $idempotencyKey,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? throw new \RuntimeException('Failed to retrieve created payment intent');
    }

    /**
     * Transition payment intent to a new status with guard check.
     *
     * @throws \RuntimeException If transition is invalid
     */
    public function updateStatus(int $id, string $newStatus, ?string $providerRef = null): bool
    {
        $intent = $this->find($id);
        if ($intent === null) {
            throw new \RuntimeException("Payment intent {$id} not found");
        }

        $currentStatus = $intent['status'];
        $allowed = self::TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new \RuntimeException(
                "Invalid payment transition: {$currentStatus} -> {$newStatus} for intent {$id}"
            );
        }

        $data = [
            'status'     => $newStatus,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($providerRef !== null) {
            $data['provider_ref'] = $providerRef;
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('status', $currentStatus) // Optimistic lock
            ->update($data);

        if ($rows === 0) {
            throw new \RuntimeException(
                "Concurrent status update detected for payment intent {$id}"
            );
        }

        return true;
    }
}
