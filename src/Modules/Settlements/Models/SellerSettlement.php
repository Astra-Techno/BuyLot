<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * SellerSettlement model.
 *
 * Table: seller_settlements
 * States: calculating, pending_approval, approved, processing, completed, failed, on_hold
 * All monetary values in integer paise.
 */
class SellerSettlement
{
    private const TABLE = 'seller_settlements';

    private const COLUMNS = [
        'id', 'seller_id', 'provider_ref', 'eligible_paise', 'requested_paise',
        'confirmed_paise', 'status', 'created_at', 'updated_at',
    ];

    /** Valid state transitions: from => [to, ...] */
    private const TRANSITIONS = [
        'calculating'      => ['pending_approval', 'on_hold'],
        'pending_approval' => ['approved', 'on_hold'],
        'approved'         => ['processing'],
        'processing'       => ['completed', 'failed'],
        'failed'           => ['processing', 'on_hold'], // Allow retry
        'on_hold'          => ['pending_approval', 'calculating'],
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

    public function findBySellerId(int $sellerId, ?string $status = null): array
    {
        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('seller_id', $sellerId);

        if ($status !== null) {
            $qb->where('status', $status);
        }

        return $qb->orderBy('created_at', 'DESC')->get();
    }

    /**
     * Create a new settlement in 'calculating' state.
     */
    public function create(
        int $sellerId,
        int $eligiblePaise,
        int $requestedPaise,
    ): array {
        if ($eligiblePaise < 0 || $requestedPaise < 0) {
            throw new \InvalidArgumentException('Settlement amounts must be non-negative');
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'seller_id'       => $sellerId,
                'eligible_paise'  => $eligiblePaise,
                'requested_paise' => $requestedPaise,
                'status'          => 'calculating',
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? throw new \RuntimeException('Failed to create settlement');
    }

    /**
     * Transition settlement to a new status with guard check.
     *
     * @throws \RuntimeException If transition is invalid
     */
    public function updateStatus(int $id, string $newStatus, ?string $providerRef = null, ?int $confirmedPaise = null): bool
    {
        $settlement = $this->find($id);
        if ($settlement === null) {
            throw new \RuntimeException("Settlement {$id} not found");
        }

        $currentStatus = $settlement['status'];
        $allowed = self::TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new \RuntimeException(
                "Invalid settlement transition: {$currentStatus} -> {$newStatus} for settlement {$id}"
            );
        }

        $data = [
            'status'     => $newStatus,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($providerRef !== null) {
            $data['provider_ref'] = $providerRef;
        }

        if ($confirmedPaise !== null) {
            $data['confirmed_paise'] = $confirmedPaise;
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('status', $currentStatus) // Optimistic lock
            ->update($data);

        if ($rows === 0) {
            throw new \RuntimeException(
                "Concurrent status update detected for settlement {$id}"
            );
        }

        return true;
    }

    /**
     * Paginated list of settlements with optional filters.
     *
     * @param array{seller_id?: int, status?: string} $filters
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

        if (!empty($filters['seller_id'])) {
            $qb->where('seller_id', (int) $filters['seller_id']);
        }

        if (!empty($filters['status'])) {
            $qb->where('status', $filters['status']);
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
