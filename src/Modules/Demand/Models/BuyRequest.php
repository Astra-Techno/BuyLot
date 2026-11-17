<?php

declare(strict_types=1);

namespace App\Modules\Demand\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * BuyRequest model.
 *
 * Table: buy_requests
 *
 * States: draft -> submitted -> grouping -> rfq_in_progress -> offer_pending
 *         -> offer_accepted -> completed | cancelled | expired
 *
 * See docs/state-machines.md Section 1 for full transition table.
 */
class BuyRequest
{
    private const TABLE = 'buy_requests';

    // States matching the DB ENUM
    public const STATUS_DRAFT          = 'draft';
    public const STATUS_SUBMITTED      = 'submitted';
    public const STATUS_GROUPING       = 'grouping';
    public const STATUS_RFQ_IN_PROGRESS = 'rfq_in_progress';
    public const STATUS_OFFER_PENDING  = 'offer_pending';
    public const STATUS_OFFER_ACCEPTED = 'offer_accepted';
    public const STATUS_COMPLETED      = 'completed';
    public const STATUS_CANCELLED      = 'cancelled';
    public const STATUS_EXPIRED        = 'expired';

    /** All valid statuses. */
    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_SUBMITTED,
        self::STATUS_GROUPING,
        self::STATUS_RFQ_IN_PROGRESS,
        self::STATUS_OFFER_PENDING,
        self::STATUS_OFFER_ACCEPTED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    /** Terminal states -- no further transitions allowed. */
    private const TERMINAL_STATES = [
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    /**
     * Legal state transitions: from => [allowed to states].
     */
    private const TRANSITIONS = [
        self::STATUS_DRAFT          => [self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_SUBMITTED      => [self::STATUS_GROUPING, self::STATUS_CANCELLED, self::STATUS_EXPIRED],
        self::STATUS_GROUPING       => [self::STATUS_RFQ_IN_PROGRESS, self::STATUS_SUBMITTED, self::STATUS_CANCELLED],
        self::STATUS_RFQ_IN_PROGRESS => [self::STATUS_OFFER_PENDING, self::STATUS_CANCELLED],
        self::STATUS_OFFER_PENDING  => [self::STATUS_OFFER_ACCEPTED, self::STATUS_EXPIRED, self::STATUS_CANCELLED],
        self::STATUS_OFFER_ACCEPTED => [self::STATUS_COMPLETED, self::STATUS_CANCELLED],
        self::STATUS_COMPLETED      => [],
        self::STATUS_CANCELLED      => [],
        self::STATUS_EXPIRED        => [],
    ];

    /**
     * Columns safe for API consumers (never expose deleted_at).
     */
    private const PUBLIC_COLUMNS = [
        'id', 'buyer_id', 'variant_id', 'spec_json', 'qty',
        'region_id', 'purchase_by', 'status', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('id', $id)
            ->where('deleted_at', null)
            ->first();
    }

    /**
     * List buy requests for a specific buyer (paginated).
     *
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listByBuyer(int $buyerId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('buyer_id', $buyerId)
            ->where('deleted_at', null);

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

    /**
     * List active (submitted) buy requests eligible for matching.
     */
    public function listActive(int $page = 1, int $perPage = 50): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('status', self::STATUS_SUBMITTED)
            ->where('deleted_at', null);

        $total = (clone $qb)->count();

        $data = $qb
            ->orderBy('created_at', 'ASC')
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

    /**
     * Find compatible requests for demand grouping.
     *
     * Matches on exact variant_id + region_id + overlapping purchase window.
     * V1: exact SKU match only (spec 6.4).
     */
    public function findCompatible(int $variantId, int $regionId, ?string $purchaseBy = null): array
    {
        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('variant_id', $variantId)
            ->where('region_id', $regionId)
            ->where('status', self::STATUS_SUBMITTED)
            ->where('deleted_at', null);

        if ($purchaseBy !== null) {
            $qb->where('purchase_by', '>=', $purchaseBy);
        }

        return $qb->orderBy('created_at', 'ASC')->get();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new buy request in DRAFT status.
     *
     * @param array{buyer_id: int, variant_id: ?int, spec_json: ?string, qty: int, region_id: int, purchase_by: ?string} $data
     */
    public function create(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'buyer_id'    => $data['buyer_id'],
                'variant_id'  => $data['variant_id'] ?? null,
                'spec_json'   => $data['spec_json'] ?? null,
                'qty'         => $data['qty'],
                'region_id'   => $data['region_id'],
                'purchase_by' => $data['purchase_by'] ?? null,
                'status'      => self::STATUS_DRAFT,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? ['id' => $id];
    }

    /**
     * Transition request to a new status with guard validation.
     *
     * @throws \InvalidArgumentException If the transition is not allowed.
     */
    public function updateStatus(int $id, string $newStatus): bool
    {
        $current = $this->find($id);

        if ($current === null) {
            throw new \InvalidArgumentException("Buy request {$id} not found");
        }

        $currentStatus = $current['status'];

        if (!$this->isValidTransition($currentStatus, $newStatus)) {
            throw new \InvalidArgumentException(
                "Invalid status transition: {$currentStatus} -> {$newStatus}"
            );
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('status', $currentStatus) // Optimistic lock: only update if still in expected state
            ->update([
                'status'     => $newStatus,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Soft-delete a buy request.
     */
    public function softDelete(int $id): bool
    {
        $now = gmdate('Y-m-d H:i:s');

        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'deleted_at' => $now,
                'updated_at' => $now,
            ]) > 0;
    }

    // -------------------------------------------------------------------------
    // State Machine Helpers
    // -------------------------------------------------------------------------

    public function isValidTransition(string $from, string $to): bool
    {
        return isset(self::TRANSITIONS[$from])
            && in_array($to, self::TRANSITIONS[$from], true);
    }

    public function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL_STATES, true);
    }

    /**
     * Check if request can be cancelled (no binding acceptance or payment).
     */
    public function isCancellable(array $request): bool
    {
        return !$this->isTerminal($request['status'])
            && in_array($request['status'], [
                self::STATUS_DRAFT,
                self::STATUS_SUBMITTED,
                self::STATUS_GROUPING,
                self::STATUS_OFFER_PENDING,
            ], true);
    }
}
