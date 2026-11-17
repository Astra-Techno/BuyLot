<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * Inspection model.
 *
 * Maps the `inspections` table (schema 6.4).
 *
 * Tiers (stored as TINYINT; described as letters internally):
 *   1 = Tier A  — Default. Invoice + delivery proof only.
 *                 Do NOT advertise as "physically inspected".
 *   2 = Tier B  — Pre-dispatch photo/video verification.
 *   3 = Tier C  — Independent third-party physical inspection.
 *
 * States: pending | in_progress | passed | failed | waived
 * DB ENUM: pending | in_progress | completed | skipped
 *
 * "passed" / "failed" / "waived" are business outcomes stored in `result`
 * plus `status = completed` or `status = skipped`.
 */
class Inspection
{
    private const TABLE = 'inspections';

    public const TIER_A = 1;
    public const TIER_B = 2;
    public const TIER_C = 3;

    public const STATUS_PENDING     = 'pending';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_COMPLETED   = 'completed';
    public const STATUS_SKIPPED     = 'skipped';   // "waived" at business layer

    public const RESULT_PASS              = 'pass';
    public const RESULT_PASS_WITH_REMARKS = 'pass_with_remarks';
    public const RESULT_FAIL              = 'fail';
    public const RESULT_INCONCLUSIVE      = 'inconclusive';

    public const VALID_RESULTS = [
        self::RESULT_PASS,
        self::RESULT_PASS_WITH_REMARKS,
        self::RESULT_FAIL,
        self::RESULT_INCONCLUSIVE,
    ];

    /** Transitions allowed in status column */
    private const TRANSITIONS = [
        self::STATUS_PENDING     => [self::STATUS_IN_PROGRESS, self::STATUS_SKIPPED],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED   => [],
        self::STATUS_SKIPPED     => [],
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->first();

        return $row !== null ? $this->decode($row) : null;
    }

    /**
     * @return array<int, array>
     */
    public function listByOrder(int $orderId): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('order_id', $orderId)
            ->orderBy('created_at', 'ASC')
            ->get();

        return array_map(fn(array $r) => $this->decode($r), $rows);
    }

    /**
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listPending(int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('status', self::STATUS_PENDING);

        $total = (clone $qb)->count();

        $rows = $qb
            ->orderBy('created_at', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => array_map(fn(array $r) => $this->decode($r), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * Paginated list with optional status/tier filters (quality team view).
     *
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function list(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE);

        if (!empty($filters['status'])) {
            $qb->where('status', $filters['status']);
        }

        if (!empty($filters['tier'])) {
            $qb->where('tier', (int) $filters['tier']);
        }

        if (!empty($filters['inspector_id'])) {
            $qb->where('inspector_id', (int) $filters['inspector_id']);
        }

        $total = (clone $qb)->count();

        $rows = $qb
            ->orderBy('created_at', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => array_map(fn(array $r) => $this->decode($r), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new inspection record in `pending` status.
     *
     * @param array<string, mixed>|null $checklistSnapshot  Checklist version snapshot
     * @return int  New inspection id
     */
    public function create(
        int    $orderId,
        ?int   $shipmentId,
        int    $tier,
        ?array $checklistSnapshot = null,
    ): int {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'order_id'           => $orderId,
                'shipment_id'        => $shipmentId,
                'tier'               => $tier,
                'inspector_id'       => null,
                'status'             => self::STATUS_PENDING,
                'result'             => null,
                'checklist_snapshot' => $checklistSnapshot !== null
                    ? json_encode($checklistSnapshot, JSON_THROW_ON_ERROR)
                    : null,
                'completed_at'       => null,
                'created_at'         => $now,
                'updated_at'         => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Assign an inspector (ops user) to this inspection.
     */
    public function assign(int $id, int $inspectorId): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'inspector_id' => $inspectorId,
                'updated_at'   => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Transition to in_progress.
     *
     * @throws \LogicException on invalid transition
     */
    public function start(int $id): bool
    {
        return $this->transition($id, self::STATUS_IN_PROGRESS);
    }

    /**
     * Complete the inspection with a result.
     *
     * @throws \InvalidArgumentException on unknown result
     * @throws \LogicException on invalid status transition
     */
    public function complete(int $id, string $result): bool
    {
        if (!in_array($result, self::VALID_RESULTS, true)) {
            throw new \InvalidArgumentException(
                "Unknown inspection result: {$result}. Allowed: " . implode(', ', self::VALID_RESULTS),
            );
        }

        $this->transition($id, self::STATUS_COMPLETED);

        $now  = gmdate('Y-m-d H:i:s');
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'result'       => $result,
                'completed_at' => $now,
                'updated_at'   => $now,
            ]);

        return $rows > 0;
    }

    /**
     * Mark as skipped/waived (Tier A auto-waive or ops decision).
     */
    public function waive(int $id): bool
    {
        return $this->transition($id, self::STATUS_SKIPPED);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function transition(int $id, string $toStatus): bool
    {
        $inspection = $this->find($id);

        if ($inspection === null) {
            throw new \LogicException("Inspection {$id} not found");
        }

        $fromStatus = $inspection['status'];
        $allowed    = self::TRANSITIONS[$fromStatus] ?? [];

        if (!in_array($toStatus, $allowed, true)) {
            throw new \LogicException(
                "Invalid inspection transition: {$fromStatus} -> {$toStatus}",
            );
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'status'     => $toStatus,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    private function decode(array $row): array
    {
        if (isset($row['checklist_snapshot']) && is_string($row['checklist_snapshot'])) {
            $row['checklist_snapshot'] = json_decode(
                $row['checklist_snapshot'],
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }

        return $row;
    }
}
