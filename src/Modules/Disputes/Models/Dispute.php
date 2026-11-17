<?php

declare(strict_types=1);

namespace App\Modules\Disputes\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * Dispute model.
 *
 * Table: disputes (Module 7, §7.1)
 *
 *   id                       BIGINT UNSIGNED AUTO_INCREMENT PK
 *   order_id                 BIGINT UNSIGNED NOT NULL
 *   type                     ENUM('wrong_item','damaged','missing','late',
 *                                 'counterfeit','non_delivery','quality','other') NOT NULL
 *   status                   ENUM('opened','evidence_requested','under_investigation',
 *                                 'resolution_proposed','resolved','appealed','closed')
 *                            NOT NULL DEFAULT 'opened'
 *   opened_by                BIGINT UNSIGNED NOT NULL  — buyer or system actor
 *   assigned_to              BIGINT UNSIGNED NULL      — support/disputes agent
 *   resolution               TEXT NULL                 — resolution outcome label
 *   resolution_details_json  JSON NULL                 — structured resolution terms
 *   closed_at                TIMESTAMP NULL
 *   created_at               TIMESTAMP NOT NULL
 *   updated_at               TIMESTAMP NOT NULL
 *
 * State machine (§9 of state-machines.md):
 *   opened -> evidence_requested -> under_investigation -> resolution_proposed
 *          -> resolved -> closed
 *                      -> appealed -> under_investigation (cycle)
 *
 * Safety invariant: a dispute in any non-terminal state MUST hold the related
 * settlement.  The DisputeService is responsible for that cross-module call.
 */
class Dispute
{
    private const TABLE = 'disputes';

    // -------------------------------------------------------------------------
    // State constants (match DB ENUM)
    // -------------------------------------------------------------------------

    public const STATUS_OPENED               = 'opened';
    public const STATUS_EVIDENCE_REQUESTED   = 'evidence_requested';
    public const STATUS_UNDER_INVESTIGATION  = 'under_investigation';
    public const STATUS_RESOLUTION_PROPOSED  = 'resolution_proposed';
    public const STATUS_RESOLVED             = 'resolved';
    public const STATUS_APPEALED             = 'appealed';
    public const STATUS_CLOSED               = 'closed';

    public const ALL_STATUSES = [
        self::STATUS_OPENED,
        self::STATUS_EVIDENCE_REQUESTED,
        self::STATUS_UNDER_INVESTIGATION,
        self::STATUS_RESOLUTION_PROPOSED,
        self::STATUS_RESOLVED,
        self::STATUS_APPEALED,
        self::STATUS_CLOSED,
    ];

    /**
     * Valid state transitions from -> [to, ...].
     * The state machine permits re-entry into UNDER_INVESTIGATION from APPEALED.
     */
    public const TRANSITIONS = [
        self::STATUS_OPENED              => [self::STATUS_EVIDENCE_REQUESTED, self::STATUS_UNDER_INVESTIGATION],
        self::STATUS_EVIDENCE_REQUESTED  => [self::STATUS_UNDER_INVESTIGATION],
        self::STATUS_UNDER_INVESTIGATION => [self::STATUS_RESOLUTION_PROPOSED],
        self::STATUS_RESOLUTION_PROPOSED => [self::STATUS_RESOLVED, self::STATUS_APPEALED],
        self::STATUS_RESOLVED            => [self::STATUS_CLOSED, self::STATUS_APPEALED],
        self::STATUS_APPEALED            => [self::STATUS_UNDER_INVESTIGATION],
        self::STATUS_CLOSED              => [],
    ];

    // -------------------------------------------------------------------------
    // Type constants (match DB ENUM)
    // -------------------------------------------------------------------------

    public const TYPE_WRONG_ITEM   = 'wrong_item';
    public const TYPE_DAMAGED      = 'damaged';
    public const TYPE_MISSING      = 'missing';
    public const TYPE_LATE         = 'late';
    public const TYPE_COUNTERFEIT  = 'counterfeit';
    public const TYPE_NON_DELIVERY = 'non_delivery';
    public const TYPE_QUALITY      = 'quality';
    public const TYPE_OTHER        = 'other';

    public const ALL_TYPES = [
        self::TYPE_WRONG_ITEM,
        self::TYPE_DAMAGED,
        self::TYPE_MISSING,
        self::TYPE_LATE,
        self::TYPE_COUNTERFEIT,
        self::TYPE_NON_DELIVERY,
        self::TYPE_QUALITY,
        self::TYPE_OTHER,
    ];

    /** States from which the dispute is considered active (not terminal). */
    public const ACTIVE_STATUSES = [
        self::STATUS_OPENED,
        self::STATUS_EVIDENCE_REQUESTED,
        self::STATUS_UNDER_INVESTIGATION,
        self::STATUS_RESOLUTION_PROPOSED,
        self::STATUS_RESOLVED,
        self::STATUS_APPEALED,
    ];

    private const PUBLIC_COLUMNS = [
        'id', 'order_id', 'type', 'status',
        'opened_by', 'assigned_to',
        'resolution', 'resolution_details_json',
        'closed_at', 'created_at', 'updated_at',
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
            ->first();
    }

    /**
     * Paginated list of disputes opened by a specific user (buyer/seller view).
     *
     * @param array{status?: string, type?: string} $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listByOpenedBy(int $userId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS)
            ->where('opened_by', $userId);

        if (!empty($filters['status'])) {
            $qb->where('status', $filters['status']);
        }

        if (!empty($filters['type'])) {
            $qb->where('type', $filters['type']);
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

    /**
     * Paginated list of disputes related to orders owned by a buyer
     * OR to orders where $userId is the seller (via orders.offer_id -> seller).
     * For simplicity the caller pre-resolves which order IDs are "related"
     * and passes them as $orderIds.
     *
     * @param int[]  $orderIds
     * @param array{status?: string, type?: string} $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listByOrderIds(array $orderIds, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        if (empty($orderIds)) {
            return ['data' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage];
        }

        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

        $where = 'order_id IN (' . $placeholders . ')';

        $params = $orderIds;

        if (!empty($filters['status'])) {
            $where    .= ' AND status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['type'])) {
            $where    .= ' AND type = ?';
            $params[] = $filters['type'];
        }

        $total = (int) ($this->connection->query(
            'SELECT COUNT(*) AS cnt FROM ' . self::TABLE . ' WHERE ' . $where,
            $params,
        )[0]['cnt'] ?? 0);

        $params[] = $perPage;
        $params[] = $offset;

        $data = $this->connection->query(
            'SELECT ' . implode(', ', self::PUBLIC_COLUMNS) .
            ' FROM ' . self::TABLE .
            ' WHERE ' . $where .
            ' ORDER BY created_at DESC LIMIT ? OFFSET ?',
            $params,
        );

        return [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Admin / support: paginated list with broad filters.
     *
     * @param array{status?: string, type?: string, assigned_to?: int, order_id?: int} $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::PUBLIC_COLUMNS);

        if (!empty($filters['status'])) {
            $qb->where('status', $filters['status']);
        }

        if (!empty($filters['type'])) {
            $qb->where('type', $filters['type']);
        }

        if (!empty($filters['assigned_to'])) {
            $qb->where('assigned_to', (int) $filters['assigned_to']);
        }

        if (!empty($filters['order_id'])) {
            $qb->where('order_id', (int) $filters['order_id']);
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

    /**
     * Find any active (non-closed) dispute on an order.
     * Used to enforce "one active dispute per order" rule.
     */
    public function findActiveByOrder(int $orderId): ?array
    {
        $placeholders = implode(',', array_fill(0, count(self::ACTIVE_STATUSES), '?'));

        $rows = $this->connection->query(
            'SELECT ' . implode(', ', self::PUBLIC_COLUMNS) .
            ' FROM ' . self::TABLE .
            ' WHERE order_id = ? AND status IN (' . $placeholders . ')' .
            ' LIMIT 1',
            array_merge([$orderId], self::ACTIVE_STATUSES),
        );

        return $rows[0] ?? null;
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new dispute. Returns the new dispute ID.
     *
     * @param array{
     *   order_id: int,
     *   type: string,
     *   opened_by: int,
     * } $data
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'order_id'               => $data['order_id'],
                'type'                   => $data['type'],
                'status'                 => self::STATUS_OPENED,
                'opened_by'              => $data['opened_by'],
                'assigned_to'            => null,
                'resolution'             => null,
                'resolution_details_json'=> null,
                'closed_at'              => null,
                'created_at'             => $now,
                'updated_at'             => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Atomically update dispute status with state-machine guard.
     * Returns true if the row was updated (from-state matched).
     */
    public function updateStatus(int $id, string $fromStatus, string $toStatus): bool
    {
        $allowed = self::TRANSITIONS[$fromStatus] ?? [];
        if (!in_array($toStatus, $allowed, true)) {
            return false;
        }

        $update = [
            'status'     => $toStatus,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($toStatus === self::STATUS_CLOSED) {
            $update['closed_at'] = gmdate('Y-m-d H:i:s');
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('status', $fromStatus)
            ->update($update);

        return $rows > 0;
    }

    /**
     * Assign the dispute to a support agent.
     */
    public function assign(int $id, int $agentId): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'assigned_to' => $agentId,
                'updated_at'  => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Record the proposed / final resolution terms.
     *
     * @param array<string,mixed> $detailsArray  Structured resolution terms
     */
    public function setResolution(int $id, string $resolution, array $detailsArray): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'resolution'              => $resolution,
                'resolution_details_json' => json_encode($detailsArray, JSON_THROW_ON_ERROR),
                'updated_at'              => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }
}
