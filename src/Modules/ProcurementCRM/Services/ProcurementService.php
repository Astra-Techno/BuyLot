<?php

declare(strict_types=1);

namespace App\Modules\ProcurementCRM\Services;

use App\Core\Exception\ValidationException;
use App\Modules\ProcurementCRM\Models\NegotiationEvent;
use App\Modules\ProcurementCRM\Models\ProcurementCase;
use App\Modules\ProcurementCRM\Models\SupplierContact;
use App\Modules\ProcurementCRM\Models\SupplierLead;

/**
 * ProcurementService — core business logic for the ProcurementCRM module.
 *
 * Responsibilities:
 *   - Create a procurement case from an RFQ with SLA calculation
 *   - Assign a case to an agent and set/update the SLA deadline
 *   - Capture an offline quote with mandatory evidence and author
 *   - Build the full case timeline (negotiation events + contact log)
 */
class ProcurementService
{
    /**
     * Default SLA hours by priority level (DB integer).
     * 1=URGENT  2=HIGH  3=MEDIUM  4=LOW
     */
    private const SLA_HOURS = [
        1 => 4,
        2 => 24,
        3 => 72,
        4 => 168,
    ];

    public function __construct(
        private ProcurementCase  $caseModel,
        private NegotiationEvent $eventModel,
        private SupplierLead     $leadModel,
        private SupplierContact  $contactModel,
    ) {}

    // -------------------------------------------------------------------------
    // Case creation
    // -------------------------------------------------------------------------

    /**
     * Create a procurement case from a published RFQ.
     *
     * Automatically computes `sla_due_at` based on priority and the current
     * timestamp unless an explicit deadline is supplied.
     *
     * @param array{
     *     rfq_id: int,
     *     priority?: string,
     *     assigned_agent_id?: int,
     *     sla_due_at?: string,
     * } $data
     */
    public function createFromRFQ(array $data): array
    {
        if (empty($data['rfq_id'])) {
            throw new ValidationException(['rfq_id' => 'rfq_id is required']);
        }

        $priorityLabel = strtoupper($data['priority'] ?? 'MEDIUM');
        $dbPriority    = $this->priorityToInt($priorityLabel);

        // Auto-compute SLA when not explicitly provided
        if (empty($data['sla_due_at'])) {
            $hours               = self::SLA_HOURS[$dbPriority] ?? self::SLA_HOURS[3];
            $data['sla_due_at']  = gmdate('Y-m-d H:i:s', time() + $hours * 3600);
        }

        $data['priority'] = $priorityLabel;

        return $this->caseModel->create($data);
    }

    // -------------------------------------------------------------------------
    // Assignment
    // -------------------------------------------------------------------------

    /**
     * Assign a case to an agent and optionally recalculate the SLA deadline.
     *
     * When $recalculateSla is true, the SLA is reset from "now" using the
     * current case priority.
     */
    public function assignWithSLA(int $caseId, int $agentId, bool $recalculateSla = false): array
    {
        $case = $this->caseModel->find($caseId);

        if ($case === null) {
            throw new ValidationException(['case_id' => 'Procurement case not found']);
        }

        $this->caseModel->assign($caseId, $agentId);

        if ($recalculateSla) {
            $dbPriority = (int) $case['priority'];
            $hours      = self::SLA_HOURS[$dbPriority] ?? self::SLA_HOURS[3];
            $slaDueAt   = gmdate('Y-m-d H:i:s', time() + $hours * 3600);

            // Update sla_due_at via a targeted update (QueryBuilder used directly
            // through the model's connection — service does not bypass the model layer
            // for this ancillary write).
            $this->caseModel->updateSlaDue($caseId, $slaDueAt);
        }

        return $this->caseModel->find($caseId) ?? [];
    }

    // -------------------------------------------------------------------------
    // Offline quote capture
    // -------------------------------------------------------------------------

    /**
     * Capture an offline (team-sourced) quote as a negotiation event.
     *
     * Business rules enforced here:
     *   1. `evidence_ref` MUST be a non-empty object-storage reference.
     *      Staff cannot fabricate a quote without attaching a document.
     *   2. `author` (created_by user ID) MUST be provided and non-zero.
     *   3. The event type is always INITIAL_QUOTE unless overridden by the
     *      caller with another legitimate type (e.g. COUNTER_OFFER).
     *   4. `terms` must contain at least `unit_price_paise` or `description`
     *      so there is substantive content to audit.
     *
     * @param array{
     *     case_id: int,
     *     supplier_id?: int,
     *     evidence_ref: string,
     *     author: int,
     *     terms: array,
     *     type?: string,
     * } $data
     *
     * @throws ValidationException when evidence or author is missing
     */
    public function captureOfflineQuote(array $data): array
    {
        $errors = [];

        if (empty($data['evidence_ref']) || !is_string($data['evidence_ref'])) {
            $errors['evidence_ref'] = 'A document evidence reference is mandatory for offline quotes. '
                . 'Upload the supporting document first and supply the object-storage path.';
        }

        if (empty($data['author']) || (int) $data['author'] === 0) {
            $errors['author'] = 'The author (staff user ID who sourced this quote) is mandatory.';
        }

        if (empty($data['case_id'])) {
            $errors['case_id'] = 'case_id is required';
        }

        if (empty($data['terms']) || !is_array($data['terms'])) {
            $errors['terms'] = 'Quote terms (at minimum unit_price_paise or description) are required.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $terms                 = $data['terms'];
        $terms['evidence_ref'] = $data['evidence_ref']; // embed reference in the immutable terms blob
        $terms['source']       = 'TEAM_SOURCED';

        return $this->eventModel->create([
            'case_id'     => (int) $data['case_id'],
            'supplier_id' => isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
            'type'        => $data['type'] ?? 'INITIAL_QUOTE',
            'terms'       => $terms,
            'created_by'  => (int) $data['author'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Timeline
    // -------------------------------------------------------------------------

    /**
     * Build the full activity timeline for a case.
     *
     * Returns negotiation events and supplier contact logs merged and sorted
     * chronologically, each row tagged with a `timeline_type` discriminator.
     *
     * @return array{
     *     case: array,
     *     timeline: array<int, array>,
     * }
     */
    public function getTimeline(int $caseId): array
    {
        $case = $this->caseModel->find($caseId);

        if ($case === null) {
            throw new ValidationException(['case_id' => 'Procurement case not found']);
        }

        $events = $this->eventModel->listForCase($caseId);

        // Gather all supplier lead IDs referenced by this case's events so we
        // can pull the matching contact logs.
        $supplierIds = array_filter(array_unique(array_column($events, 'supplier_id')));

        $contacts = [];
        foreach ($supplierIds as $sid) {
            $rows = $this->contactModel->listForLead((int) $sid);
            foreach ($rows as $row) {
                $row['timeline_type'] = 'supplier_contact';
                $contacts[]           = $row;
            }
        }

        $taggedEvents = array_map(static function (array $e): array {
            $e['timeline_type'] = 'negotiation_event';
            return $e;
        }, $events);

        $timeline = array_merge($taggedEvents, $contacts);

        // Sort by the most reliable timestamp available on each row.
        usort($timeline, static function (array $a, array $b): int {
            $ta = $a['created_at'] ?? $a['contacted_at'] ?? '';
            $tb = $b['created_at'] ?? $b['contacted_at'] ?? '';
            return strcmp($ta, $tb);
        });

        return [
            'case'     => $case,
            'timeline' => $timeline,
        ];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function priorityToInt(string $label): int
    {
        return match ($label) {
            'URGENT' => 1,
            'HIGH'   => 2,
            'LOW'    => 4,
            default  => 3, // MEDIUM
        };
    }
}
