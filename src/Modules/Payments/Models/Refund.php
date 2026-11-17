<?php

declare(strict_types=1);

namespace App\Modules\Payments\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * Refund model.
 *
 * Table: refunds
 * States: requested, approved, processing, completed, failed, rejected
 * All monetary values in integer paise.
 */
class Refund
{
    private const TABLE = 'refunds';

    private const COLUMNS = [
        'id', 'payment_id', 'order_item_id', 'amount_paise', 'reason',
        'status', 'provider_ref', 'approved_by', 'created_at', 'updated_at',
    ];

    /** Valid state transitions: from => [to, ...] */
    private const TRANSITIONS = [
        'requested'  => ['approved', 'rejected'],
        'approved'   => ['processing'],
        'processing' => ['completed', 'failed'],
        'failed'     => ['processing'], // Allow retry
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

    public function findByPaymentId(int $paymentId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('payment_id', $paymentId)
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    public function findByProviderRef(string $providerRef): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('provider_ref', $providerRef)
            ->first();
    }

    /**
     * Create a new refund request.
     *
     * @throws \InvalidArgumentException If amount is non-positive
     */
    public function create(
        int     $paymentId,
        int     $amountPaise,
        string  $reason,
        ?int    $orderItemId = null,
    ): array {
        if ($amountPaise <= 0) {
            throw new \InvalidArgumentException('Refund amount_paise must be positive');
        }

        $now = gmdate('Y-m-d H:i:s');

        $data = [
            'payment_id'   => $paymentId,
            'amount_paise' => $amountPaise,
            'reason'       => $reason,
            'status'       => 'requested',
            'created_at'   => $now,
            'updated_at'   => $now,
        ];

        if ($orderItemId !== null) {
            $data['order_item_id'] = $orderItemId;
        }

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert($data);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? throw new \RuntimeException('Failed to retrieve created refund');
    }

    /**
     * Transition refund to a new status with guard check.
     *
     * @throws \RuntimeException If transition is invalid
     */
    public function updateStatus(int $id, string $newStatus, ?int $approvedBy = null, ?string $providerRef = null): bool
    {
        $refund = $this->find($id);
        if ($refund === null) {
            throw new \RuntimeException("Refund {$id} not found");
        }

        $currentStatus = $refund['status'];
        $allowed = self::TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new \RuntimeException(
                "Invalid refund transition: {$currentStatus} -> {$newStatus} for refund {$id}"
            );
        }

        $data = [
            'status'     => $newStatus,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($approvedBy !== null) {
            $data['approved_by'] = $approvedBy;
        }

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
                "Concurrent status update detected for refund {$id}"
            );
        }

        return true;
    }

    /**
     * Paginated list of refunds with optional filters.
     *
     * @param array{status?: string, payment_id?: int} $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS);

        if (!empty($filters['status'])) {
            $qb->where('status', $filters['status']);
        }

        if (!empty($filters['payment_id'])) {
            $qb->where('payment_id', (int) $filters['payment_id']);
        }

        $total = (clone $qb)->count();

        $data = $qb
            ->orderBy('created_at', 'DESC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }
}
