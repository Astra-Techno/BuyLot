<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Core\Database\Connection;

/**
 * FinanceKPIService — payment and reconciliation KPIs for the finance function.
 *
 * KPIs returned:
 *   payment_success_rate             captured intents / total intents (0–1, 4 dp)
 *   reconciliation_exceptions_count  open rows in reconciliation_exceptions
 *   refund_aging_days                AVG days from refund_requests.requested_at
 *                                    to refund_requests.completed_at
 *   seller_payable_aging_days        AVG days from seller_settlements.eligible_at
 *                                    to seller_settlements.paid_at (confirmed payout)
 *   chargeback_rate                  payments with status='chargeback' /
 *                                    total captured payments (0–1, 4 dp)
 *   gateway_settlement_exceptions    count of reconciliation_exceptions with
 *                                    type='gateway_mismatch'
 *
 * Filters: date_from / date_to applied to the primary timestamp of each KPI's
 * base table (payment_intents.created_at, refund_requests.requested_at, etc.).
 */
readonly class FinanceKPIService
{
    public function __construct(private Connection $db) {}

    /**
     * @param string|null $dateFrom  ISO-8601 date, inclusive lower bound
     * @param string|null $dateTo    ISO-8601 date, inclusive upper bound
     * @return array<string, int|float>
     */
    public function getKPIs(?string $dateFrom, ?string $dateTo): array
    {
        return [
            'payment_success_rate'            => $this->paymentSuccessRate($dateFrom, $dateTo),
            'reconciliation_exceptions_count' => $this->reconciliationExceptionsCount($dateFrom, $dateTo),
            'refund_aging_days'               => $this->refundAgingDays($dateFrom, $dateTo),
            'seller_payable_aging_days'       => $this->sellerPayableAgingDays($dateFrom, $dateTo),
            'chargeback_rate'                 => $this->chargebackRate($dateFrom, $dateTo),
            'gateway_settlement_exceptions'   => $this->gatewaySettlementExceptions($dateFrom, $dateTo),
        ];
    }

    // -------------------------------------------------------------------------
    // Individual KPI methods
    // -------------------------------------------------------------------------

    /**
     * Captured payment intents / total payment intents in the window.
     *
     * "Captured" = status IN ('captured', 'settled') — intent reached a
     * successful terminal state.
     */
    private function paymentSuccessRate(?string $dateFrom, ?string $dateTo): float
    {
        [$dateWhere, $params] = $this->buildDateFilter('pi.created_at', $dateFrom, $dateTo);

        $sql = "
            SELECT
                SUM(CASE WHEN pi.status IN ('captured', 'settled') THEN 1 ELSE 0 END) AS captured,
                COUNT(pi.id)                                                            AS total
            FROM   payment_intents pi
            WHERE  1=1
                   {$dateWhere}
        ";

        $rows    = $this->db->query($sql, $params);
        $row     = $rows[0] ?? ['captured' => 0, 'total' => 0];
        $total   = (int) ($row['total']   ?? 0);
        $captured = (int) ($row['captured'] ?? 0);

        if ($total === 0) {
            return 0.0;
        }

        return round($captured / $total, 4);
    }

    /**
     * Count of open (unresolved) reconciliation exceptions in the window.
     * "Open" = resolved_at IS NULL.
     */
    private function reconciliationExceptionsCount(?string $dateFrom, ?string $dateTo): int
    {
        [$dateWhere, $params] = $this->buildDateFilter('re.created_at', $dateFrom, $dateTo);

        $sql = "
            SELECT COUNT(re.id)
            FROM   reconciliation_exceptions re
            WHERE  re.resolved_at IS NULL
                   {$dateWhere}
        ";

        return (int) ($this->scalar($sql, $params) ?? 0);
    }

    /**
     * Average days from refund request creation to completion.
     * Only counts requests that have been completed (completed_at IS NOT NULL).
     */
    private function refundAgingDays(?string $dateFrom, ?string $dateTo): float
    {
        [$dateWhere, $params] = $this->buildDateFilter('rr.requested_at', $dateFrom, $dateTo);

        $sql = "
            SELECT AVG(
                TIMESTAMPDIFF(MINUTE, rr.requested_at, rr.completed_at) / 1440.0
            ) AS avg_days
            FROM   refund_requests rr
            WHERE  rr.completed_at IS NOT NULL
                   {$dateWhere}
        ";

        return round((float) ($this->scalar($sql, $params) ?? 0.0), 2);
    }

    /**
     * Average days from settlement eligibility to confirmed payout.
     * Only counts settlements that have been paid (paid_at IS NOT NULL).
     */
    private function sellerPayableAgingDays(?string $dateFrom, ?string $dateTo): float
    {
        [$dateWhere, $params] = $this->buildDateFilter('ss.eligible_at', $dateFrom, $dateTo);

        $sql = "
            SELECT AVG(
                TIMESTAMPDIFF(MINUTE, ss.eligible_at, ss.paid_at) / 1440.0
            ) AS avg_days
            FROM   seller_settlements ss
            WHERE  ss.paid_at IS NOT NULL
               AND ss.status  = 'completed'
                   {$dateWhere}
        ";

        return round((float) ($this->scalar($sql, $params) ?? 0.0), 2);
    }

    /**
     * Payments with chargeback status / total captured payments.
     *
     * Base set = payment_intents that reached 'captured' or 'settled' at some
     * point (we include chargebacked ones in the denominator since they were
     * captured first).
     */
    private function chargebackRate(?string $dateFrom, ?string $dateTo): float
    {
        [$dateWhere, $params] = $this->buildDateFilter('pi.created_at', $dateFrom, $dateTo);

        $sql = "
            SELECT
                SUM(CASE WHEN pi.status = 'chargeback' THEN 1 ELSE 0 END) AS chargebacks,
                SUM(CASE WHEN pi.status IN ('captured', 'settled', 'chargeback')
                         THEN 1 ELSE 0 END)                                AS captured_total
            FROM   payment_intents pi
            WHERE  1=1
                   {$dateWhere}
        ";

        $rows           = $this->db->query($sql, $params);
        $row            = $rows[0] ?? ['chargebacks' => 0, 'captured_total' => 0];
        $capturedTotal  = (int) ($row['captured_total'] ?? 0);
        $chargebacks    = (int) ($row['chargebacks']    ?? 0);

        if ($capturedTotal === 0) {
            return 0.0;
        }

        return round($chargebacks / $capturedTotal, 4);
    }

    /**
     * Count of reconciliation_exceptions of type 'gateway_mismatch' in the window.
     */
    private function gatewaySettlementExceptions(?string $dateFrom, ?string $dateTo): int
    {
        [$dateWhere, $params] = $this->buildDateFilter('re.created_at', $dateFrom, $dateTo);

        $sql = "
            SELECT COUNT(re.id)
            FROM   reconciliation_exceptions re
            WHERE  re.type = 'gateway_mismatch'
                   {$dateWhere}
        ";

        return (int) ($this->scalar($sql, $params) ?? 0);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{string, array<string, string>}
     */
    private function buildDateFilter(string $column, ?string $dateFrom, ?string $dateTo): array
    {
        $where  = '';
        $params = [];

        if ($dateFrom !== null) {
            $where             .= " AND {$column} >= :date_from";
            $params[':date_from'] = $dateFrom . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where           .= " AND {$column} <= :date_to";
            $params[':date_to'] = $dateTo . ' 23:59:59';
        }

        return [$where, $params];
    }

    private function scalar(string $sql, array $params): mixed
    {
        $rows = $this->db->query($sql, $params);
        return reset($rows[0] ?? [false]);
    }
}
