<?php

declare(strict_types=1);

namespace App\Core\Auth\Middleware;

use App\Core\Database\Connection;
use App\Core\Http\Middleware\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;

/**
 * Maker-Checker middleware: ensures the approver is never the creator.
 *
 * Applicable to:
 *   - Settlement approvals (Finance Operator prepares, Finance Approver authorizes)
 *   - Offer approvals (Procurement Agent submits, Procurement Lead approves)
 *   - Refund approvals above threshold
 *   - Financial dispute adjustments
 *   - Procurement commitments above threshold
 *   - Role assignments for elevated roles
 *
 * The middleware loads the target record and verifies:
 *   created_by / preparer_id / proposer_id / submitter_id != current authenticated user
 *
 * This is enforced at the application layer AND should also be enforced at the DB
 * constraint level for defense in depth.
 */
final readonly class MakerCheckerMiddleware implements MiddlewareInterface
{
    /**
     * @param string      $table          DB table containing the record
     * @param string      $recordIdParam  Request param name holding the record ID
     * @param string[]    $creatorFields  Column names that identify the "maker"
     * @param int|null    $amountThreshold Minimum amount_paise to trigger the check (null = always)
     * @param string|null $amountField     Column name for the amount field
     */
    public function __construct(
        private string $table,
        private string $recordIdParam = 'id',
        private array $creatorFields = ['created_by', 'preparer_id', 'proposer_id', 'submitter_id'],
        private ?int $amountThreshold = null,
        private ?string $amountField = 'amount_paise',
    ) {}

    public function handle(Request $request, callable $next): Response
    {
        // ── Extract authenticated user ───────────────────────────
        $userPayload = $request->getParams()['_user'] ?? null;

        if ($userPayload === null) {
            return Response::unauthorized('Authentication required');
        }

        $currentUserId = (int) ($userPayload['user_id'] ?? $userPayload['sub'] ?? 0);

        if ($currentUserId === 0) {
            return Response::unauthorized('Invalid user identity');
        }

        // ── Resolve the target record ID ─────────────────────────
        $recordId = $request->getParam($this->recordIdParam)
            ?? $request->getBody($this->recordIdParam);

        if ($recordId === null) {
            // If no record ID in route params, try the request body
            $recordId = $request->getBody('record_id')
                ?? $request->getBody('id');
        }

        if ($recordId === null) {
            return Response::badRequest('Record identifier is required for approval');
        }

        $recordId = (int) $recordId;

        // ── Load the record ──────────────────────────────────────
        $record = $this->loadRecord($recordId);

        if ($record === null) {
            return Response::notFound("Record not found in {$this->table}");
        }

        // ── Check amount threshold (if configured) ───────────────
        if ($this->amountThreshold !== null && $this->amountField !== null) {
            $amount = (int) ($record[$this->amountField] ?? 0);
            if ($amount <= $this->amountThreshold) {
                // Below threshold — maker-checker not required, proceed
                return $next($request);
            }
        }

        // ── Enforce maker != checker ─────────────────────────────
        foreach ($this->creatorFields as $field) {
            if (!isset($record[$field])) {
                continue;
            }

            $creatorId = (int) $record[$field];

            if ($creatorId === $currentUserId) {
                $this->logSelfApprovalAttempt($currentUserId, $recordId, $field);

                return Response::json(
                    data: [
                        'error' => 'Forbidden',
                        'reason' => 'Self-approval is not permitted',
                        'detail' => 'The user who created or prepared this record cannot also approve it. '
                            . 'A different authorized user must perform the approval.',
                        'constraint' => 'maker_checker',
                        'blocked_field' => $field,
                        'record_id' => $recordId,
                        'table' => $this->table,
                        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
                    ],
                    statusCode: 403,
                );
            }
        }

        return $next($request);
    }

    // ──────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────

    private function loadRecord(int $recordId): ?array
    {
        $selectFields = array_merge($this->creatorFields, ['id']);

        if ($this->amountField !== null) {
            $selectFields[] = $this->amountField;
        }

        $columns = implode(', ', array_unique(array_map(
            static fn(string $f) => "`{$f}`",
            $selectFields,
        )));

        $db = Connection::getInstance();
        $rows = $db->query(
            "SELECT {$columns} FROM `{$this->table}` WHERE id = ? LIMIT 1",
            [$recordId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Log self-approval attempts to the immutable audit trail.
     * This is a security-significant event per Section 5.4.
     */
    private function logSelfApprovalAttempt(int $userId, int $recordId, string $blockedField): void
    {
        try {
            $db = Connection::getInstance();
            $db->execute(
                'INSERT INTO audit_log (actor_id, action, resource_type, resource_id, payload, ip_address, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [
                    $userId,
                    'self_approval_blocked',
                    $this->table,
                    $recordId,
                    json_encode([
                        'blocked_field' => $blockedField,
                        'table' => $this->table,
                        'severity' => 'high',
                    ]),
                    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                ],
            );
        } catch (\Throwable) {
            error_log("AUDIT_FAILURE: self_approval_blocked user={$userId} table={$this->table} record={$recordId}");
        }
    }
}
