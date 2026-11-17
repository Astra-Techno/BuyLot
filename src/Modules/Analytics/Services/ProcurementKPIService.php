<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Services;

use App\Core\Database\Connection;

/**
 * ProcurementKPIService — operational KPIs for the procurement function.
 *
 * KPIs returned:
 *   time_to_first_quote        avg hours from procurement_case creation to first seller_bid
 *   time_to_approved_offer     avg hours from case creation to offer_approvals.decided_at (approved)
 *   sourcing_success_zero_bids % of RFQs that received zero platform bids where staff
 *                               ultimately recorded a bid (offline_procurement / supplier_lead)
 *   contacted_suppliers        total unique supplier_leads contacted in period
 *   quote_to_deal_rate         awarded bids / total non-withdrawn/non-disqualified bids (%)
 *   cost_per_case              avg procurement labor paise per case (placeholder: 0 until
 *                               a labor-cost ledger account is wired)
 *   agent_workload             cases per distinct assigned_agent in period
 *   lost_reasons               grouped counts of procurement_cases closed with stage=closed_no_deal
 *                               keyed by the last negotiation_event.type before close
 *
 * Filters: date_from / date_to applied to procurement_cases.created_at.
 */
readonly class ProcurementKPIService
{
    public function __construct(private Connection $db) {}

    /**
     * @param string|null $dateFrom  ISO-8601 date, inclusive lower bound
     * @param string|null $dateTo    ISO-8601 date, inclusive upper bound
     * @return array<string, mixed>
     */
    public function getKPIs(?string $dateFrom, ?string $dateTo): array
    {
        [$dateWhere, $params] = $this->buildDateFilter('pc.created_at', $dateFrom, $dateTo);

        return [
            'time_to_first_quote'        => $this->timeToFirstQuote($dateWhere, $params),
            'time_to_approved_offer'     => $this->timeToApprovedOffer($dateWhere, $params),
            'sourcing_success_zero_bids' => $this->sourcingSuccessZeroBids($dateWhere, $params),
            'contacted_suppliers'        => $this->contactedSuppliers($dateFrom, $dateTo),
            'quote_to_deal_rate'         => $this->quoteToDealRate($dateWhere, $params),
            'cost_per_case'              => $this->costPerCase($dateWhere, $params),
            'agent_workload'             => $this->agentWorkload($dateWhere, $params),
            'lost_reasons'               => $this->lostReasons($dateWhere, $params),
        ];
    }

    // -------------------------------------------------------------------------
    // Individual KPI methods
    // -------------------------------------------------------------------------

    /** Average hours between case creation and first bid submitted on its RFQ. */
    private function timeToFirstQuote(string $dateWhere, array $params): float
    {
        $sql = "
            SELECT AVG(
                TIMESTAMPDIFF(MINUTE, pc.created_at, first_bid.submitted_at) / 60.0
            ) AS avg_hours
            FROM   procurement_cases pc
            JOIN   rfqs r ON r.id = pc.rfq_id
            JOIN   (
                SELECT sbr.rfq_id,
                       MIN(sbr.submitted_at) AS submitted_at
                FROM   seller_bid_revisions sbr
                JOIN   seller_bids sb ON sb.id = sbr.bid_id
                GROUP BY sbr.rfq_id
            ) first_bid ON first_bid.rfq_id = r.id
            WHERE  1=1
                   {$dateWhere}
        ";

        return round((float) ($this->scalar($sql, $params) ?? 0.0), 2);
    }

    /** Average hours between case creation and offer approval decision. */
    private function timeToApprovedOffer(string $dateWhere, array $params): float
    {
        $sql = "
            SELECT AVG(
                TIMESTAMPDIFF(MINUTE, pc.created_at, oa.decided_at) / 60.0
            ) AS avg_hours
            FROM   procurement_cases pc
            JOIN   rfqs r              ON r.id  = pc.rfq_id
            JOIN   demand_groups dg    ON dg.id = r.group_id
            JOIN   negotiated_offers no ON no.group_id = dg.id
            JOIN   offer_approvals oa  ON oa.offer_id = no.id
                                      AND oa.decision = 'approved'
            WHERE  oa.decided_at IS NOT NULL
                   {$dateWhere}
        ";

        return round((float) ($this->scalar($sql, $params) ?? 0.0), 2);
    }

    /**
     * % of RFQs with zero platform bids where at least one offline/supplier_lead
     * bid was eventually recorded (i.e., staff successfully sourced a supplier).
     */
    private function sourcingSuccessZeroBids(string $dateWhere, array $params): float
    {
        // RFQs in period with zero platform bids
        $zeroBidsSql = "
            SELECT COUNT(DISTINCT r.id)
            FROM   procurement_cases pc
            JOIN   rfqs r ON r.id = pc.rfq_id
            WHERE  NOT EXISTS (
                SELECT 1 FROM seller_bids sb
                WHERE  sb.rfq_id = r.id
                   AND sb.source = 'platform'
                   AND sb.status NOT IN ('withdrawn', 'disqualified')
            )
            {$dateWhere}
        ";

        $zeroBids = (int) ($this->scalar($zeroBidsSql, $params) ?? 0);

        if ($zeroBids === 0) {
            return 0.0;
        }

        // Of those, ones where staff found an offline/supplier_lead bid
        $staffFoundSql = "
            SELECT COUNT(DISTINCT r.id)
            FROM   procurement_cases pc
            JOIN   rfqs r ON r.id = pc.rfq_id
            WHERE  NOT EXISTS (
                SELECT 1 FROM seller_bids sb_p
                WHERE  sb_p.rfq_id = r.id
                   AND sb_p.source = 'platform'
                   AND sb_p.status NOT IN ('withdrawn', 'disqualified')
            )
            AND EXISTS (
                SELECT 1 FROM seller_bids sb_o
                WHERE  sb_o.rfq_id = r.id
                   AND sb_o.source IN ('offline_procurement', 'supplier_lead')
                   AND sb_o.status NOT IN ('withdrawn', 'disqualified')
            )
            {$dateWhere}
        ";

        $staffFound = (int) ($this->scalar($staffFoundSql, $params) ?? 0);

        return round(($staffFound / $zeroBids) * 100, 2);
    }

    /** Total unique supplier_leads contacted (by contacted_at) in the date window. */
    private function contactedSuppliers(?string $dateFrom, ?string $dateTo): int
    {
        [$dateWhere, $params] = $this->buildDateFilter('sc.contacted_at', $dateFrom, $dateTo);

        $sql = "
            SELECT COUNT(DISTINCT sc.supplier_lead_id)
            FROM   supplier_contacts sc
            WHERE  1=1
                   {$dateWhere}
        ";

        return (int) ($this->scalar($sql, $params) ?? 0);
    }

    /** awarded bids / total active-or-awarded bids × 100 */
    private function quoteToDealRate(string $dateWhere, array $params): float
    {
        $sql = "
            SELECT
                SUM(CASE WHEN sb.status = 'awarded' THEN 1 ELSE 0 END)   AS awarded,
                COUNT(sb.id)                                               AS total
            FROM   seller_bids sb
            JOIN   rfqs r ON r.id = sb.rfq_id
            JOIN   procurement_cases pc ON pc.rfq_id = r.id
            WHERE  sb.status NOT IN ('withdrawn', 'disqualified')
                   {$dateWhere}
        ";

        $rows = $this->db->query($sql, $params);
        $row  = $rows[0] ?? ['awarded' => 0, 'total' => 0];

        $total   = (int) ($row['total']   ?? 0);
        $awarded = (int) ($row['awarded'] ?? 0);

        if ($total === 0) {
            return 0.0;
        }

        return round(($awarded / $total) * 100, 2);
    }

    /**
     * Average procurement labor cost per case in paise.
     *
     * Reads from ledger_postings where the account code is 'PROCUREMENT_LABOR'
     * (credit side = cost incurred). Returns 0 if the account has no postings
     * in the period (e.g., not yet wired up).
     */
    private function costPerCase(string $dateWhere, array $params): int
    {
        $caseCountSql = "
            SELECT COUNT(pc.id)
            FROM   procurement_cases pc
            WHERE  1=1
                   {$dateWhere}
        ";

        $caseCount = (int) ($this->scalar($caseCountSql, $params) ?? 0);

        if ($caseCount === 0) {
            return 0;
        }

        // Sum of PROCUREMENT_LABOR credit postings in the period
        [$ledgerWhere, $ledgerParams] = $this->buildDateFilter('lp.created_at', null, null);
        // Reuse date params but keyed differently to avoid collision
        $ledgerParams2 = [];
        if (str_contains($dateWhere, ':date_from')) {
            preg_match('/date_from/', $dateWhere, $m);
            $ledgerWhere .= ' AND lp.created_at >= :lp_date_from';
            $ledgerParams2[':lp_date_from'] = $params[':date_from'] ?? null;
        }
        if (str_contains($dateWhere, ':date_to')) {
            $ledgerWhere .= ' AND lp.created_at <= :lp_date_to';
            $ledgerParams2[':lp_date_to'] = $params[':date_to'] ?? null;
        }

        $laborSql = "
            SELECT COALESCE(SUM(lp.credit_paise), 0)
            FROM   ledger_postings lp
            JOIN   ledger_accounts la ON la.id = lp.account_id
                                     AND la.code = 'PROCUREMENT_LABOR'
            WHERE  lp.credit_paise > 0
                   {$ledgerWhere}
        ";

        $totalLabor = (int) ($this->scalar($laborSql, $ledgerParams2) ?? 0);

        return (int) round($totalLabor / $caseCount);
    }

    /** Cases per distinct assigned agent in the period (average). */
    private function agentWorkload(string $dateWhere, array $params): float
    {
        $sql = "
            SELECT AVG(cases_per_agent) FROM (
                SELECT COUNT(pc.id) AS cases_per_agent
                FROM   procurement_cases pc
                WHERE  pc.assigned_agent_id IS NOT NULL
                       {$dateWhere}
                GROUP BY pc.assigned_agent_id
            ) sub
        ";

        return round((float) ($this->scalar($sql, $params) ?? 0.0), 2);
    }

    /**
     * Groups closed_no_deal cases by the most recent negotiation_event.type
     * logged before the case reached closed_no_deal.
     *
     * @return array<string, int>
     */
    private function lostReasons(string $dateWhere, array $params): array
    {
        $sql = "
            SELECT
                COALESCE(last_ne.type, 'no_negotiation') AS reason,
                COUNT(pc.id)                              AS total
            FROM procurement_cases pc
            LEFT JOIN LATERAL (
                SELECT ne.type
                FROM   negotiation_events ne
                WHERE  ne.case_id = pc.id
                ORDER  BY ne.created_at DESC
                LIMIT  1
            ) last_ne ON TRUE
            WHERE  pc.stage = 'closed_no_deal'
                   {$dateWhere}
            GROUP  BY reason
            ORDER  BY total DESC
        ";

        // LATERAL is MySQL 8.0.14+; fall back to correlated subquery if needed.
        // The schema targets MySQL 8 so LATERAL is safe here.
        $rows = $this->db->query($sql, $params);

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['reason']] = (int) $row['total'];
        }

        return $result;
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
