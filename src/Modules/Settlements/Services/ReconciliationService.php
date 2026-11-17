<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Core\Database\Connection;

/**
 * Daily reconciliation service.
 *
 * Compares orders <-> payments <-> refunds <-> settlements <-> gateway reports
 * and flags mismatches as reconciliation exceptions.
 *
 * An unresolved exception BLOCKS any entity from being treated as "paid" or "settled".
 * All monetary values in integer paise.
 */
class ReconciliationService
{
    public function __construct(private Connection $connection) {}

    /**
     * Run daily reconciliation.
     *
     * Creates a reconciliation_runs entry and checks for discrepancies across:
     * - Orders marked 'payment_confirmed' without a matching captured payment_intent
     * - Payments captured without a corresponding order status update
     * - Refunds completed without matching ledger entries
     * - Settlements completed without matching provider confirmations
     *
     * @return array{run_id: int, exceptions_found: int, exceptions: array}
     */
    public function runDaily(?int $runBy = null): array
    {
        $now = gmdate('Y-m-d H:i:s');
        $periodStart = gmdate('Y-m-d H:i:s', strtotime('-1 day'));
        $periodEnd   = $now;

        // Create reconciliation run
        $this->connection->execute(
            "INSERT INTO reconciliation_runs (provider, period_start, period_end, status, run_by, created_at, updated_at)
             VALUES (?, ?, ?, 'in_progress', ?, ?, ?)",
            ['all', $periodStart, $periodEnd, $runBy, $now, $now],
        );
        $runId = (int) $this->connection->lastInsertId();

        $exceptions = [];

        try {
            // Check 1: Orders paid but no captured payment
            $exceptions = array_merge($exceptions, $this->checkOrdersWithoutPayment($runId));

            // Check 2: Captured payments without order confirmation
            $exceptions = array_merge($exceptions, $this->checkPaymentsWithoutOrderUpdate($runId));

            // Check 3: Completed refunds without ledger entries
            $exceptions = array_merge($exceptions, $this->checkRefundsWithoutLedger($runId));

            // Check 4: Completed settlements without ledger entries
            $exceptions = array_merge($exceptions, $this->checkSettlementsWithoutLedger($runId));

            // Mark run as completed
            $this->connection->execute(
                "UPDATE reconciliation_runs SET status = 'completed', updated_at = ? WHERE id = ?",
                [$now, $runId],
            );
        } catch (\Throwable $e) {
            $this->connection->execute(
                "UPDATE reconciliation_runs SET status = 'failed', updated_at = ? WHERE id = ?",
                [$now, $runId],
            );
            throw $e;
        }

        return [
            'run_id'           => $runId,
            'exceptions_found' => count($exceptions),
            'exceptions'       => $exceptions,
        ];
    }

    /**
     * Create a reconciliation exception.
     */
    public function createException(
        int    $runId,
        string $entityType,
        int    $entityId,
        int    $discrepancyPaise,
        string $description,
    ): int {
        $now = gmdate('Y-m-d H:i:s');

        $this->connection->execute(
            "INSERT INTO reconciliation_exceptions
                (run_id, entity_type, entity_id, discrepancy_paise, description, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 'open', ?, ?)",
            [$runId, $entityType, $entityId, $discrepancyPaise, $description, $now, $now],
        );

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Check whether an entity has any unresolved reconciliation exceptions.
     *
     * If true, the entity MUST NOT be considered "paid" or "settled".
     */
    public function hasUnresolvedExceptions(string $entityType, int $entityId): bool
    {
        $result = $this->connection->query(
            "SELECT COUNT(*) AS cnt FROM reconciliation_exceptions
             WHERE entity_type = ? AND entity_id = ? AND status IN ('open', 'investigating')",
            [$entityType, $entityId],
        );

        return ((int) ($result[0]['cnt'] ?? 0)) > 0;
    }

    /**
     * List open exceptions with optional filters.
     *
     * @param array{status?: string, entity_type?: string} $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listExceptions(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $where  = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[]  = 'status = ?';
            $params[] = $filters['status'];
        }

        if (!empty($filters['entity_type'])) {
            $where[]  = 'entity_type = ?';
            $params[] = $filters['entity_type'];
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $countResult = $this->connection->query(
            "SELECT COUNT(*) AS cnt FROM reconciliation_exceptions {$whereClause}",
            $params,
        );
        $total = (int) ($countResult[0]['cnt'] ?? 0);

        $params[] = $perPage;
        $params[] = $offset;

        $data = $this->connection->query(
            "SELECT * FROM reconciliation_exceptions {$whereClause}
             ORDER BY created_at DESC LIMIT ? OFFSET ?",
            $params,
        );

        return [
            'data'     => $data,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // -------------------------------------------------------------------------
    // Reconciliation checks
    // -------------------------------------------------------------------------

    /**
     * Orders in 'payment_confirmed' status with no captured payment intent.
     */
    private function checkOrdersWithoutPayment(int $runId): array
    {
        $rows = $this->connection->query(
            "SELECT o.id AS order_id, o.total_paise
             FROM orders o
             LEFT JOIN payment_intents pi ON pi.order_id = o.id AND pi.status = 'captured'
             WHERE o.status = 'payment_confirmed'
               AND pi.id IS NULL
               AND o.deleted_at IS NULL",
        );

        $exceptions = [];
        foreach ($rows as $row) {
            $exId = $this->createException(
                $runId,
                'order',
                (int) $row['order_id'],
                (int) $row['total_paise'],
                "Order #{$row['order_id']} marked payment_confirmed but no captured payment intent found",
            );
            $exceptions[] = ['id' => $exId, 'entity_type' => 'order', 'entity_id' => (int) $row['order_id']];
        }

        return $exceptions;
    }

    /**
     * Captured payments without a corresponding order status update.
     */
    private function checkPaymentsWithoutOrderUpdate(int $runId): array
    {
        $rows = $this->connection->query(
            "SELECT pi.id AS payment_id, pi.order_id, pi.amount_paise
             FROM payment_intents pi
             JOIN orders o ON o.id = pi.order_id
             WHERE pi.status = 'captured'
               AND o.status = 'pending_payment'
               AND o.deleted_at IS NULL",
        );

        $exceptions = [];
        foreach ($rows as $row) {
            $exId = $this->createException(
                $runId,
                'payment_intent',
                (int) $row['payment_id'],
                (int) $row['amount_paise'],
                "Payment #{$row['payment_id']} captured but order #{$row['order_id']} still pending_payment",
            );
            $exceptions[] = ['id' => $exId, 'entity_type' => 'payment_intent', 'entity_id' => (int) $row['payment_id']];
        }

        return $exceptions;
    }

    /**
     * Completed refunds without matching ledger entries.
     */
    private function checkRefundsWithoutLedger(int $runId): array
    {
        $rows = $this->connection->query(
            "SELECT r.id AS refund_id, r.amount_paise
             FROM refunds r
             LEFT JOIN ledger_entries le ON le.entity_type = 'refund' AND le.entity_id = r.id
             WHERE r.status = 'completed'
               AND le.id IS NULL",
        );

        $exceptions = [];
        foreach ($rows as $row) {
            $exId = $this->createException(
                $runId,
                'refund',
                (int) $row['refund_id'],
                (int) $row['amount_paise'],
                "Refund #{$row['refund_id']} completed but no ledger entry found",
            );
            $exceptions[] = ['id' => $exId, 'entity_type' => 'refund', 'entity_id' => (int) $row['refund_id']];
        }

        return $exceptions;
    }

    /**
     * Completed settlements without matching ledger entries.
     */
    private function checkSettlementsWithoutLedger(int $runId): array
    {
        $rows = $this->connection->query(
            "SELECT ss.id AS settlement_id, ss.confirmed_paise
             FROM seller_settlements ss
             LEFT JOIN ledger_entries le ON le.entity_type = 'settlement' AND le.entity_id = ss.id
             WHERE ss.status = 'completed'
               AND le.id IS NULL",
        );

        $exceptions = [];
        foreach ($rows as $row) {
            $exId = $this->createException(
                $runId,
                'settlement',
                (int) $row['settlement_id'],
                (int) ($row['confirmed_paise'] ?? 0),
                "Settlement #{$row['settlement_id']} completed but no ledger entry found",
            );
            $exceptions[] = ['id' => $exId, 'entity_type' => 'settlement', 'entity_id' => (int) $row['settlement_id']];
        }

        return $exceptions;
    }
}
