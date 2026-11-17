<?php

declare(strict_types=1);

namespace App\Modules\ProcurementCRM\Services;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use App\Modules\ProcurementCRM\Models\SupplierLead;

/**
 * SupplierDirectoryService — supplier discovery and performance analytics.
 *
 * Provides:
 *   - Supplier search with region/source/status filters
 *   - Response-rate metric: fraction of contact attempts that produced an outcome
 *   - Conversion metric: fraction of sourced leads that yielded a closed_awarded case
 */
class SupplierDirectoryService
{
    public function __construct(
        private SupplierLead $leadModel,
        private Connection   $connection,
    ) {}

    // -------------------------------------------------------------------------
    // Search
    // -------------------------------------------------------------------------

    /**
     * Search suppliers with optional filters.
     *
     * @param array{
     *     name?: string,
     *     source?: string,
     *     verification_status?: string,
     *     region_id?: int,
     * } $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function search(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        if (!empty($filters['region_id'])) {
            return $this->leadModel->findByRegion((int) $filters['region_id'], $page, $perPage);
        }

        return $this->leadModel->search($filters, $page, $perPage);
    }

    // -------------------------------------------------------------------------
    // Stats
    // -------------------------------------------------------------------------

    /**
     * Calculate response rate and conversion metrics for a supplier lead.
     *
     * Response rate = (contact attempts with a non-null outcome) / (total contacts)
     * Conversion    = number of procurement cases linked to a supplier that reached
     *                 `closed_awarded` stage.
     *
     * Both are returned as fractions between 0.00 and 1.00.
     *
     * @return array{
     *     supplier_lead_id: int,
     *     total_contacts: int,
     *     contacts_with_outcome: int,
     *     response_rate: float,
     *     closed_awarded_cases: int,
     *     conversion_rate: float,
     * }
     */
    public function stats(int $supplierLeadId): array
    {
        // Total contact attempts for this lead
        $totalContacts = (new QueryBuilder($this->connection))
            ->table('supplier_contacts')
            ->where('supplier_lead_id', $supplierLeadId)
            ->count();

        // Contacts that produced a non-null outcome
        $withOutcome = (int) $this->connection->query(
            'SELECT COUNT(*) AS cnt FROM supplier_contacts
              WHERE supplier_lead_id = ? AND outcome IS NOT NULL AND outcome <> \'\'',
            [$supplierLeadId],
        )[0]['cnt'] ?? 0;

        $responseRate = $totalContacts > 0
            ? round($withOutcome / $totalContacts, 4)
            : 0.0;

        // Count cases where this supplier was involved and the case closed won
        // (negotiation_events link supplier_id to case_id)
        $closedWonRows = $this->connection->query(
            'SELECT COUNT(DISTINCT ne.case_id) AS cnt
               FROM negotiation_events ne
               JOIN procurement_cases pc ON pc.id = ne.case_id
              WHERE ne.supplier_id = ?
                AND pc.stage = \'closed_awarded\'',
            [$supplierLeadId],
        );
        $closedWon = (int) ($closedWonRows[0]['cnt'] ?? 0);

        // Total distinct cases this supplier was involved in
        $totalCasesRows = $this->connection->query(
            'SELECT COUNT(DISTINCT case_id) AS cnt
               FROM negotiation_events
              WHERE supplier_id = ?',
            [$supplierLeadId],
        );
        $totalCases = (int) ($totalCasesRows[0]['cnt'] ?? 0);

        $conversionRate = $totalCases > 0
            ? round($closedWon / $totalCases, 4)
            : 0.0;

        return [
            'supplier_lead_id'     => $supplierLeadId,
            'total_contacts'       => $totalContacts,
            'contacts_with_outcome' => $withOutcome,
            'response_rate'        => $responseRate,
            'closed_awarded_cases' => $closedWon,
            'conversion_rate'      => $conversionRate,
        ];
    }
}
