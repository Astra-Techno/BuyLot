<?php

declare(strict_types=1);

namespace App\Modules\ProcurementCRM\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\ProcurementCRM\Models\NegotiationAttachment;
use App\Modules\ProcurementCRM\Models\NegotiationEvent;
use App\Modules\ProcurementCRM\Models\ProcurementCase;
use App\Modules\ProcurementCRM\Models\ProcurementTask;
use App\Modules\ProcurementCRM\Models\SupplierContact;
use App\Modules\ProcurementCRM\Models\SupplierLead;
use App\Modules\ProcurementCRM\Services\ProcurementService;
use App\Modules\ProcurementCRM\Services\SupplierDirectoryService;

/**
 * ProcurementController — internal procurement CRM endpoints.
 *
 * All routes are under /v1/internal/ and require authentication + a
 * procurement_agent or procurement_admin role (enforced by middleware in Routes).
 *
 * Public:
 *   GET  /v1/internal/procurement/cases
 *   GET  /v1/internal/procurement/cases/{id}
 *   POST /v1/internal/procurement/cases
 *   POST /v1/internal/cases/{id}/assign
 *   PUT  /v1/internal/cases/{id}/stage
 *   POST /v1/internal/cases/{id}/supplier-leads
 *   POST /v1/internal/cases/{id}/contacts
 *   POST /v1/internal/cases/{id}/offline-quotes
 *   POST /v1/internal/cases/{id}/negotiation-events
 *   POST /v1/internal/negotiation-events/{id}/attachments
 *   GET  /v1/internal/cases/{id}/tasks
 *   POST /v1/internal/cases/{id}/tasks
 *   PUT  /v1/internal/tasks/{id}/complete
 *   GET  /v1/internal/suppliers
 */
readonly class ProcurementController
{
    public function __construct(
        private ProcurementCase        $caseModel,
        private SupplierLead           $leadModel,
        private SupplierContact        $contactModel,
        private NegotiationEvent       $eventModel,
        private NegotiationAttachment  $attachmentModel,
        private ProcurementTask        $taskModel,
        private ProcurementService     $procurementService,
        private SupplierDirectoryService $directoryService,
    ) {}

    // =========================================================================
    // Cases
    // =========================================================================

    /**
     * GET /v1/internal/procurement/cases
     *
     * Query params: priority, stage, agent, overdue (1|0), page, per_page
     */
    public function listCases(Request $request): Response
    {
        $filters = array_filter([
            'priority' => $request->getQuery('priority'),
            'stage'    => $request->getQuery('stage'),
            'agent'    => $request->getQuery('agent'),
            'overdue'  => $request->getQuery('overdue'),
        ]);

        $page    = (int) ($request->getQuery('page', 1));
        $perPage = (int) ($request->getQuery('per_page', 20));

        $result = $this->caseModel->paginatedList($filters, $page, $perPage);

        return Response::json($result);
    }

    /**
     * GET /v1/internal/procurement/cases/{id}
     *
     * Returns the full case detail including negotiation timeline.
     */
    public function showCase(Request $request): Response
    {
        $id = $this->resolveIntParam($request, 'id');

        $data = $this->procurementService->getTimeline($id);

        // Augment with tasks
        $data['tasks'] = $this->taskModel->listForCase($id);

        return Response::json($data);
    }

    /**
     * POST /v1/internal/procurement/cases
     *
     * Body: { rfq_id, priority?, assigned_agent_id?, sla_due_at? }
     */
    public function createCase(Request $request): Response
    {
        $body = $request->getBody() ?? [];

        $case = $this->procurementService->createFromRFQ($body);

        return Response::created($case);
    }

    /**
     * POST /v1/internal/cases/{id}/assign
     *
     * Body: { agent_id, recalculate_sla? }
     */
    public function assignCase(Request $request): Response
    {
        $caseId  = $this->resolveIntParam($request, 'id');
        $body    = $request->getBody() ?? [];
        $agentId = (int) ($body['agent_id'] ?? 0);

        if ($agentId === 0) {
            throw new ValidationException(['agent_id' => 'agent_id is required']);
        }

        $recalculate = !empty($body['recalculate_sla']);
        $case        = $this->procurementService->assignWithSLA($caseId, $agentId, $recalculate);

        return Response::json($case);
    }

    /**
     * PUT /v1/internal/cases/{id}/stage
     *
     * Body: { stage }
     */
    public function updateStage(Request $request): Response
    {
        $caseId = $this->resolveIntParam($request, 'id');
        $body   = $request->getBody() ?? [];
        $stage  = trim($body['stage'] ?? '');

        if ($stage === '') {
            throw new ValidationException(['stage' => 'stage is required']);
        }

        $updated = $this->caseModel->updateStage($caseId, $stage);

        if (!$updated) {
            throw new HttpException('Case not found or stage unchanged', 404);
        }

        $case = $this->caseModel->find($caseId);

        return Response::json($case);
    }

    // =========================================================================
    // Supplier leads
    // =========================================================================

    /**
     * POST /v1/internal/cases/{id}/supplier-leads
     *
     * Adds a supplier lead and links it to the case via a SOURCING negotiation
     * event so there is a full audit trail of which suppliers were considered.
     *
     * Body: { legal_name, source, contact_info?, region_id?, notes? }
     */
    public function addSupplierLead(Request $request): Response
    {
        $caseId = $this->resolveIntParam($request, 'id');
        $body   = $request->getBody() ?? [];

        $actorId = $this->resolveActorId($request);

        $this->requireCase($caseId);

        $errors = [];
        if (empty($body['legal_name'])) {
            $errors['legal_name'] = 'legal_name is required';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $body['created_by'] = $actorId;
        $lead               = $this->leadModel->create($body);

        // Record in negotiation timeline that this supplier was added to scope
        $this->eventModel->create([
            'case_id'     => $caseId,
            'supplier_id' => $lead['id'],
            'type'        => 'INITIAL_QUOTE',
            'created_by'  => $actorId,
            'terms'       => ['action' => 'supplier_added_to_scope', 'supplier_lead_id' => $lead['id']],
        ]);

        return Response::created(['supplier_lead' => $lead]);
    }

    // =========================================================================
    // Contacts
    // =========================================================================

    /**
     * POST /v1/internal/cases/{id}/contacts
     *
     * Log a contact attempt against a supplier linked to this case.
     *
     * Body: { supplier_lead_id, method, outcome?, notes?, contacted_at? }
     */
    public function logContact(Request $request): Response
    {
        $this->resolveIntParam($request, 'id'); // validates case exists in URL
        $body    = $request->getBody() ?? [];
        $actorId = $this->resolveActorId($request);

        $errors = [];
        if (empty($body['supplier_lead_id'])) {
            $errors['supplier_lead_id'] = 'supplier_lead_id is required';
        }

        if (empty($body['method'])) {
            $errors['method'] = 'method is required (CALL|EMAIL|WHATSAPP|VISIT)';
        } elseif (!in_array(strtoupper($body['method']), SupplierContact::METHODS, true)) {
            $errors['method'] = 'method must be one of: ' . implode(', ', SupplierContact::METHODS);
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $body['contacted_by'] = $actorId;
        $contact              = $this->contactModel->create($body);

        return Response::created($contact);
    }

    // =========================================================================
    // Offline quotes
    // =========================================================================

    /**
     * POST /v1/internal/cases/{id}/offline-quotes
     *
     * Capture an offline (team-sourced) quote.
     *
     * MANDATORY fields — the system WILL NOT accept a quote without both:
     *   evidence_ref  string  Object-storage path of the supporting document
     *   author        int     User ID of the staff member who sourced the quote
     *
     * This prevents staff from fabricating bids without an auditable source.
     *
     * Body: {
     *   evidence_ref: string,   // MANDATORY
     *   author: int,            // MANDATORY
     *   supplier_id?: int,
     *   terms: { unit_price_paise?, description?, ... },
     *   type?: string
     * }
     */
    public function addOfflineQuote(Request $request): Response
    {
        $caseId = $this->resolveIntParam($request, 'id');
        $body   = $request->getBody() ?? [];

        $this->requireCase($caseId);

        $body['case_id'] = $caseId;

        $event = $this->procurementService->captureOfflineQuote($body);

        return Response::created($event);
    }

    // =========================================================================
    // Negotiation events
    // =========================================================================

    /**
     * POST /v1/internal/cases/{id}/negotiation-events
     *
     * Body: { type, supplier_id?, bid_id?, terms? }
     */
    public function addNegotiationEvent(Request $request): Response
    {
        $caseId  = $this->resolveIntParam($request, 'id');
        $body    = $request->getBody() ?? [];
        $actorId = $this->resolveActorId($request);

        $this->requireCase($caseId);

        $errors = [];
        if (empty($body['type'])) {
            $errors['type'] = 'type is required';
        } elseif (!in_array(strtoupper($body['type']), NegotiationEvent::TYPES, true)) {
            $errors['type'] = 'type must be one of: ' . implode(', ', NegotiationEvent::TYPES);
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $event = $this->eventModel->create([
            'case_id'     => $caseId,
            'supplier_id' => isset($body['supplier_id']) ? (int) $body['supplier_id'] : null,
            'bid_id'      => isset($body['bid_id']) ? (int) $body['bid_id'] : null,
            'type'        => $body['type'],
            'terms'       => $body['terms'] ?? null,
            'created_by'  => $actorId,
        ]);

        return Response::created($event);
    }

    // =========================================================================
    // Attachments
    // =========================================================================

    /**
     * POST /v1/internal/negotiation-events/{id}/attachments
     *
     * Body: { object_ref, mime, checksum }
     */
    public function uploadAttachment(Request $request): Response
    {
        $eventId = $this->resolveIntParam($request, 'id');
        $body    = $request->getBody() ?? [];

        $errors = [];
        if (empty($body['object_ref'])) {
            $errors['object_ref'] = 'object_ref is required';
        }
        if (empty($body['mime'])) {
            $errors['mime'] = 'mime is required';
        }
        if (empty($body['checksum'])) {
            $errors['checksum'] = 'checksum (SHA-256 hex) is required';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $event = $this->eventModel->find($eventId);
        if ($event === null) {
            throw new HttpException('Negotiation event not found', 404);
        }

        $attachment = $this->attachmentModel->create([
            'event_id'   => $eventId,
            'object_ref' => $body['object_ref'],
            'mime'       => $body['mime'],
            'checksum'   => $body['checksum'],
        ]);

        return Response::created($attachment);
    }

    // =========================================================================
    // Tasks
    // =========================================================================

    /**
     * GET /v1/internal/cases/{id}/tasks
     */
    public function listTasks(Request $request): Response
    {
        $caseId = $this->resolveIntParam($request, 'id');
        $this->requireCase($caseId);

        $tasks = $this->taskModel->listForCase($caseId);

        return Response::json(['data' => $tasks, 'total' => count($tasks)]);
    }

    /**
     * POST /v1/internal/cases/{id}/tasks
     *
     * Body: { title, assignee_id?, due_at? }
     */
    public function createTask(Request $request): Response
    {
        $caseId  = $this->resolveIntParam($request, 'id');
        $body    = $request->getBody() ?? [];

        $this->requireCase($caseId);

        if (empty($body['title'])) {
            throw new ValidationException(['title' => 'title is required']);
        }

        $task = $this->taskModel->create([
            'case_id'     => $caseId,
            'title'       => $body['title'],
            'assignee_id' => isset($body['assignee_id']) ? (int) $body['assignee_id'] : null,
            'due_at'      => $body['due_at'] ?? null,
        ]);

        return Response::created($task);
    }

    /**
     * PUT /v1/internal/tasks/{id}/complete
     */
    public function completeTask(Request $request): Response
    {
        $taskId  = $this->resolveIntParam($request, 'id');
        $updated = $this->taskModel->complete($taskId);

        if (!$updated) {
            throw new HttpException('Task not found', 404);
        }

        $task = $this->taskModel->find($taskId);

        return Response::json($task);
    }

    // =========================================================================
    // Supplier directory
    // =========================================================================

    /**
     * GET /v1/internal/suppliers
     *
     * Query params: name, source, verification_status, region_id, page, per_page
     */
    public function listSuppliers(Request $request): Response
    {
        $filters = array_filter([
            'name'                => $request->getQuery('name'),
            'source'              => $request->getQuery('source'),
            'verification_status' => $request->getQuery('verification_status'),
            'region_id'           => $request->getQuery('region_id'),
        ]);

        $page    = (int) ($request->getQuery('page', 1));
        $perPage = (int) ($request->getQuery('per_page', 20));

        $result = $this->directoryService->search($filters, $page, $perPage);

        return Response::json($result);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Resolve an integer route parameter, throwing 400 on invalid input.
     */
    private function resolveIntParam(Request $request, string $key): int
    {
        $raw = $request->getParam($key);

        if ($raw === null || !ctype_digit((string) $raw)) {
            throw new HttpException("Route parameter '{$key}' must be a positive integer", 400);
        }

        return (int) $raw;
    }

    /**
     * Resolve the authenticated actor's user ID from the JWT context.
     */
    private function resolveActorId(Request $request): int
    {
        $userCtx = $request->getParam('_user');

        if (!is_array($userCtx) || empty($userCtx['user_id'])) {
            throw new HttpException('Invalid authentication context', 401);
        }

        return (int) $userCtx['user_id'];
    }

    /**
     * Assert that a procurement case exists, throwing 404 otherwise.
     */
    private function requireCase(int $caseId): void
    {
        if ($this->caseModel->find($caseId) === null) {
            throw new HttpException('Procurement case not found', 404);
        }
    }
}
