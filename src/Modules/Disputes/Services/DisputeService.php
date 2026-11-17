<?php

declare(strict_types=1);

namespace App\Modules\Disputes\Services;

use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Modules\Disputes\Models\Dispute;
use App\Modules\Disputes\Models\DisputeAdjustment;
use App\Modules\Disputes\Models\DisputeEvent;
use App\Modules\Disputes\Models\DisputeEvidence;

/**
 * DisputeService — orchestrates all dispute state transitions.
 *
 * Key invariants enforced here:
 *
 * 1. SETTLEMENT HOLD: when a dispute is opened the caller is responsible for
 *    holding the related settlement.  DisputeService signals this by returning
 *    the dispute row; the Orders/Settlement module must react to the
 *    dispute.opened domain event (or the controller can call the settlement
 *    service directly after openDispute() returns).  This service does NOT
 *    directly touch the settlements table to avoid circular module dependencies.
 *
 * 2. APPEND-ONLY EVENTS: every state transition appends a DisputeEvent row.
 *    No transition may skip the event log.
 *
 * 3. MAKER-CHECKER: DisputeAdjustment::create() throws if approved_by ==
 *    created_by for amounts at or above MAKER_CHECKER_THRESHOLD_PAISE.
 *    DisputeService re-validates this before entering the transaction.
 *
 * 4. DUPLICATE DISPUTE GUARD: only one active dispute per order at a time.
 */
class DisputeService
{
    public function __construct(
        private Connection        $connection,
        private Dispute           $disputeModel,
        private DisputeEvent      $eventModel,
        private DisputeEvidence   $evidenceModel,
        private DisputeAdjustment $adjustmentModel,
    ) {}

    // -------------------------------------------------------------------------
    // Open
    // -------------------------------------------------------------------------

    /**
     * Open a new dispute on an order.
     *
     * Guards:
     *  - Order must be in a disputable state (checked by caller / controller).
     *  - No existing active dispute on the order.
     *
     * Side effects:
     *  - Creates disputes row (status = opened).
     *  - Appends OPENED event.
     *  - Optionally stores initial evidence if provided.
     *
     * Returns the full dispute row.
     *
     * @param array{
     *   order_id: int,
     *   type: string,
     *   opened_by: int,
     *   description?: string|null,
     *   evidence?: array<array{object_ref: string, evidence_type: string, description?: string}>
     * } $data
     * @throws HttpException if an active dispute already exists on this order
     * @throws ValidationException if type is invalid
     */
    public function openDispute(array $data): array
    {
        $this->assertValidType($data['type']);

        $existing = $this->disputeModel->findActiveByOrder((int) $data['order_id']);
        if ($existing !== null) {
            throw new HttpException(
                'An active dispute already exists for this order (id=' . $existing['id'] . '). ' .
                'Resolve it before opening a new one.',
                409,
            );
        }

        $this->connection->beginTransaction();

        try {
            $disputeId = $this->disputeModel->create([
                'order_id'  => (int) $data['order_id'],
                'type'      => $data['type'],
                'opened_by' => (int) $data['opened_by'],
            ]);

            $this->eventModel->append([
                'dispute_id' => $disputeId,
                'type'       => DisputeEvent::TYPE_OPENED,
                'actor_id'   => (int) $data['opened_by'],
                'notes'      => $data['description'] ?? null,
            ]);

            // Persist any initial evidence supplied at open time.
            foreach ($data['evidence'] ?? [] as $ev) {
                $this->evidenceModel->append([
                    'dispute_id'    => $disputeId,
                    'submitted_by'  => (int) $data['opened_by'],
                    'evidence_type' => $ev['evidence_type'],
                    'object_ref'    => $ev['object_ref'],
                    'description'   => $ev['description'] ?? null,
                ]);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->getOrFail($disputeId);
    }

    // -------------------------------------------------------------------------
    // Assign
    // -------------------------------------------------------------------------

    /**
     * Assign a support agent to the dispute and set any SLA context in notes.
     *
     * @throws HttpException if dispute not found
     */
    public function assignDispute(int $disputeId, int $agentId, int $actorId, ?string $notes = null): array
    {
        $dispute = $this->getOrFail($disputeId);

        $this->connection->beginTransaction();

        try {
            $this->disputeModel->assign($disputeId, $agentId);

            $this->eventModel->append([
                'dispute_id' => $disputeId,
                'type'       => DisputeEvent::TYPE_ASSIGNED,
                'actor_id'   => $actorId,
                'notes'      => $notes ?? ('Assigned to agent #' . $agentId),
            ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->getOrFail($disputeId);
    }

    // -------------------------------------------------------------------------
    // Evidence
    // -------------------------------------------------------------------------

    /**
     * Submit evidence to an active dispute.
     *
     * @param array{
     *   evidence_type: string,
     *   object_ref: string,
     *   description?: string|null,
     * } $evidenceData
     * @throws HttpException if dispute not found or is closed
     */
    public function submitEvidence(int $disputeId, int $submittedBy, array $evidenceData): array
    {
        $dispute = $this->getOrFail($disputeId);

        if ($dispute['status'] === Dispute::STATUS_CLOSED) {
            throw new HttpException('Evidence cannot be submitted to a closed dispute.', 422);
        }

        $this->assertValidEvidenceType($evidenceData['evidence_type']);

        $this->connection->beginTransaction();

        try {
            $evidenceId = $this->evidenceModel->append([
                'dispute_id'    => $disputeId,
                'submitted_by'  => $submittedBy,
                'evidence_type' => $evidenceData['evidence_type'],
                'object_ref'    => $evidenceData['object_ref'],
                'description'   => $evidenceData['description'] ?? null,
            ]);

            $this->eventModel->append([
                'dispute_id' => $disputeId,
                'type'       => DisputeEvent::TYPE_EVIDENCE_SUBMITTED,
                'actor_id'   => $submittedBy,
                'notes'      => 'Evidence #' . $evidenceId . ' submitted (' . $evidenceData['evidence_type'] . ')',
            ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->getOrFail($disputeId);
    }

    // -------------------------------------------------------------------------
    // Request Evidence (support action)
    // -------------------------------------------------------------------------

    /**
     * Move dispute to EVIDENCE_REQUESTED and notify parties (notification
     * dispatch is left to the caller / event listener layer).
     *
     * @throws HttpException on invalid transition
     */
    public function requestEvidence(int $disputeId, int $actorId, string $notes): array
    {
        $dispute = $this->getOrFail($disputeId);
        $this->assertTransition($dispute['status'], Dispute::STATUS_EVIDENCE_REQUESTED);

        $this->connection->beginTransaction();

        try {
            $this->applyTransition($disputeId, $dispute['status'], Dispute::STATUS_EVIDENCE_REQUESTED);

            $this->eventModel->append([
                'dispute_id' => $disputeId,
                'type'       => DisputeEvent::TYPE_EVIDENCE_REQUESTED,
                'actor_id'   => $actorId,
                'notes'      => $notes,
            ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->getOrFail($disputeId);
    }

    // -------------------------------------------------------------------------
    // Start Investigation
    // -------------------------------------------------------------------------

    /**
     * Move dispute to UNDER_INVESTIGATION.
     *
     * @throws HttpException on invalid transition
     */
    public function investigate(int $disputeId, int $actorId, ?string $notes = null): array
    {
        $dispute = $this->getOrFail($disputeId);
        $this->assertTransition($dispute['status'], Dispute::STATUS_UNDER_INVESTIGATION);

        $this->connection->beginTransaction();

        try {
            $this->applyTransition($disputeId, $dispute['status'], Dispute::STATUS_UNDER_INVESTIGATION);

            $this->eventModel->append([
                'dispute_id' => $disputeId,
                'type'       => DisputeEvent::TYPE_INVESTIGATION_STARTED,
                'actor_id'   => $actorId,
                'notes'      => $notes,
            ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->getOrFail($disputeId);
    }

    // -------------------------------------------------------------------------
    // Propose Resolution
    // -------------------------------------------------------------------------

    /**
     * Propose a resolution (support -> RESOLUTION_PROPOSED).
     *
     * @param array{
     *   resolution: string,
     *   resolution_details: array<string,mixed>,
     * } $resolutionData
     * @throws HttpException on invalid transition
     */
    public function proposeResolution(int $disputeId, int $actorId, array $resolutionData): array
    {
        $dispute = $this->getOrFail($disputeId);
        $this->assertTransition($dispute['status'], Dispute::STATUS_RESOLUTION_PROPOSED);

        $this->connection->beginTransaction();

        try {
            $this->disputeModel->setResolution(
                $disputeId,
                $resolutionData['resolution'],
                $resolutionData['resolution_details'],
            );

            $this->applyTransition($disputeId, $dispute['status'], Dispute::STATUS_RESOLUTION_PROPOSED);

            $this->eventModel->append([
                'dispute_id' => $disputeId,
                'type'       => DisputeEvent::TYPE_RESOLUTION_PROPOSED,
                'actor_id'   => $actorId,
                'notes'      => $resolutionData['resolution'],
            ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->getOrFail($disputeId);
    }

    // -------------------------------------------------------------------------
    // Respond to Resolution (buyer / seller)
    // -------------------------------------------------------------------------

    /**
     * Buyer or seller accepts or rejects the proposed resolution.
     *
     * Accepting: transitions to RESOLVED.
     * Rejecting: transitions to APPEALED (losing party appeals).
     *
     * @throws HttpException on invalid transition or wrong status
     */
    public function respondToResolution(int $disputeId, int $actorId, bool $accept, ?string $notes = null): array
    {
        $dispute = $this->getOrFail($disputeId);

        if ($dispute['status'] !== Dispute::STATUS_RESOLUTION_PROPOSED) {
            throw new HttpException(
                'Resolution response is only valid when dispute is in resolution_proposed status.',
                422,
            );
        }

        $toStatus  = $accept ? Dispute::STATUS_RESOLVED : Dispute::STATUS_APPEALED;
        $eventType = $accept ? DisputeEvent::TYPE_ACCEPTED : DisputeEvent::TYPE_REJECTED;

        $this->connection->beginTransaction();

        try {
            $this->applyTransition($disputeId, $dispute['status'], $toStatus);

            $this->eventModel->append([
                'dispute_id' => $disputeId,
                'type'       => $eventType,
                'actor_id'   => $actorId,
                'notes'      => $notes,
            ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->getOrFail($disputeId);
    }

    // -------------------------------------------------------------------------
    // Resolve (admin finalises — creates adjustment if financial)
    // -------------------------------------------------------------------------

    /**
     * Close investigation and apply final resolution, optionally creating a
     * financial adjustment (refund / credit / replacement).
     *
     * Maker-checker enforced by DisputeAdjustment::create() for amounts
     * at or above MAKER_CHECKER_THRESHOLD_PAISE.
     *
     * @param array{
     *   resolution: string,
     *   resolution_details: array<string,mixed>,
     *   adjustment?: array{
     *     type: string,
     *     amount_paise: int,
     *     approved_by: int,
     *     created_by: int,
     *     reason?: string|null,
     *   }|null,
     * } $data
     * @throws HttpException on invalid transition
     * @throws \InvalidArgumentException on maker-checker violation
     */
    public function resolveDispute(int $disputeId, int $actorId, array $data): array
    {
        $dispute = $this->getOrFail($disputeId);
        $this->assertTransition($dispute['status'], Dispute::STATUS_RESOLVED);

        // Pre-validate maker-checker before opening the transaction.
        if (!empty($data['adjustment'])) {
            $adj = $data['adjustment'];
            $threshold = DisputeAdjustment::MAKER_CHECKER_THRESHOLD_PAISE;
            if ((int) $adj['amount_paise'] >= $threshold && (int) $adj['approved_by'] === (int) $adj['created_by']) {
                throw new \InvalidArgumentException(
                    'Maker-checker violation: the approver must differ from the creator ' .
                    'for financial adjustments at or above the threshold (' . $threshold . ' paise).',
                );
            }
        }

        $this->connection->beginTransaction();

        try {
            $this->disputeModel->setResolution(
                $disputeId,
                $data['resolution'],
                $data['resolution_details'],
            );

            $this->applyTransition($disputeId, $dispute['status'], Dispute::STATUS_RESOLVED);

            $this->eventModel->append([
                'dispute_id' => $disputeId,
                'type'       => DisputeEvent::TYPE_ACCEPTED,
                'actor_id'   => $actorId,
                'notes'      => $data['resolution'],
            ]);

            if (!empty($data['adjustment'])) {
                $adj = $data['adjustment'];
                $this->adjustmentModel->create([
                    'dispute_id'   => $disputeId,
                    'type'         => $adj['type'],
                    'amount_paise' => (int) $adj['amount_paise'],
                    'approved_by'  => (int) $adj['approved_by'],
                    'created_by'   => (int) $adj['created_by'],
                    'reason'       => $adj['reason'] ?? null,
                ]);
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->getOrFail($disputeId);
    }

    // -------------------------------------------------------------------------
    // Appeal
    // -------------------------------------------------------------------------

    /**
     * Re-open for appeal (transitions RESOLVED -> APPEALED).
     * The same method handles RESOLUTION_PROPOSED -> APPEALED for the
     * respondToResolution flow; this variant is called by the admin endpoint.
     *
     * @throws HttpException on invalid transition
     */
    public function appealDispute(int $disputeId, int $actorId, string $reason): array
    {
        $dispute = $this->getOrFail($disputeId);
        $this->assertTransition($dispute['status'], Dispute::STATUS_APPEALED);

        $this->connection->beginTransaction();

        try {
            $this->applyTransition($disputeId, $dispute['status'], Dispute::STATUS_APPEALED);

            $this->eventModel->append([
                'dispute_id' => $disputeId,
                'type'       => DisputeEvent::TYPE_APPEALED,
                'actor_id'   => $actorId,
                'notes'      => $reason,
            ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->getOrFail($disputeId);
    }

    // -------------------------------------------------------------------------
    // Close
    // -------------------------------------------------------------------------

    /**
     * Close the dispute (RESOLVED -> CLOSED).
     * Financial adjustments must have been executed before closing.
     * Settlement is unblocked by the caller after this returns.
     *
     * @throws HttpException on invalid transition
     */
    public function closeDispute(int $disputeId, int $actorId, ?string $notes = null): array
    {
        $dispute = $this->getOrFail($disputeId);
        $this->assertTransition($dispute['status'], Dispute::STATUS_CLOSED);

        $this->connection->beginTransaction();

        try {
            $this->applyTransition($disputeId, $dispute['status'], Dispute::STATUS_CLOSED);

            $this->eventModel->append([
                'dispute_id' => $disputeId,
                'type'       => DisputeEvent::TYPE_CLOSED,
                'actor_id'   => $actorId,
                'notes'      => $notes,
            ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->getOrFail($disputeId);
    }

    // -------------------------------------------------------------------------
    // Timeline
    // -------------------------------------------------------------------------

    /**
     * Return all events and evidence for a dispute, merged chronologically.
     *
     * Each entry has a `kind` key: 'event' | 'evidence'.
     *
     * @return array<array{kind: string, created_at: string, ...}>
     */
    public function getTimeline(int $disputeId): array
    {
        $this->getOrFail($disputeId); // existence check

        $events   = $this->eventModel->listByDispute($disputeId);
        $evidence = $this->evidenceModel->listByDispute($disputeId);

        $timeline = [];

        foreach ($events as $e) {
            $timeline[] = array_merge($e, ['kind' => 'event']);
        }

        foreach ($evidence as $ev) {
            $timeline[] = array_merge($ev, ['kind' => 'evidence']);
        }

        usort($timeline, static fn(array $a, array $b): int =>
            strcmp($a['created_at'], $b['created_at'])
        );

        return $timeline;
    }

    // -------------------------------------------------------------------------
    // List helpers — thin delegation to the model (read-only)
    // -------------------------------------------------------------------------

    /**
     * Disputes opened by a specific user (buyer self-service).
     *
     * @param array{status?: string, type?: string} $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listMine(int $userId, array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->disputeModel->listByOpenedBy($userId, $filters, $page, $perPage);
    }

    /**
     * All disputes — admin/support view.
     *
     * @param array{status?: string, type?: string, assigned_to?: int, order_id?: int} $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function listAll(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        return $this->disputeModel->listAll($filters, $page, $perPage);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @throws HttpException 404 if not found
     */
    public function getOrFail(int $disputeId): array
    {
        $dispute = $this->disputeModel->find($disputeId);

        if ($dispute === null) {
            throw new HttpException('Dispute not found.', 404);
        }

        return $dispute;
    }

    /**
     * @throws HttpException 422 if the transition is not in the state machine
     */
    private function assertTransition(string $fromStatus, string $toStatus): void
    {
        $allowed = Dispute::TRANSITIONS[$fromStatus] ?? [];
        if (!in_array($toStatus, $allowed, true)) {
            throw new HttpException(
                "Invalid transition: '{$fromStatus}' -> '{$toStatus}'.",
                422,
            );
        }
    }

    /**
     * Apply state transition; throws HttpException if the DB row was not
     * updated (concurrent transition race).
     */
    private function applyTransition(int $disputeId, string $fromStatus, string $toStatus): void
    {
        $updated = $this->disputeModel->updateStatus($disputeId, $fromStatus, $toStatus);
        if (!$updated) {
            throw new HttpException(
                'State transition failed — the dispute was modified concurrently. Please retry.',
                409,
            );
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertValidType(string $type): void
    {
        if (!in_array($type, Dispute::ALL_TYPES, true)) {
            throw new ValidationException([
                'type' => 'Invalid dispute type. Allowed: ' . implode(', ', Dispute::ALL_TYPES),
            ]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function assertValidEvidenceType(string $type): void
    {
        if (!in_array($type, DisputeEvidence::ALL_TYPES, true)) {
            throw new ValidationException([
                'evidence_type' => 'Invalid evidence type. Allowed: ' . implode(', ', DisputeEvidence::ALL_TYPES),
            ]);
        }
    }
}
