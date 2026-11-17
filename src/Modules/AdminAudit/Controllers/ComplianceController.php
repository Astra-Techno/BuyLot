<?php

declare(strict_types=1);

namespace App\Modules\AdminAudit\Controllers;

use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\AdminAudit\Models\ConsentRecord;

/**
 * ComplianceController — internal DPDP / consent management endpoints.
 *
 * All endpoints require auth + compliance role (compliance_admin | super_admin).
 *
 * Routes:
 *   GET  /v1/internal/compliance/consents                       → listConsents()
 *   GET  /v1/internal/compliance/consents/{userId}              → getConsentStatus()
 *   POST /v1/internal/compliance/account-deletion/{userId}      → processAccountDeletion()
 */
readonly class ComplianceController
{
    private const ALLOWED_ROLES = ['compliance_admin', 'super_admin'];

    /**
     * PII columns on `users` that must be anonymised on DPDP deletion.
     * These are overwritten with deterministic placeholder values so that
     * FK references remain intact while personal data is removed.
     */
    private const PII_COLUMNS = [
        'mobile'    => 'DELETED',
        'email'     => null,
        'status'    => 'deactivated',
        'deleted_at' => null, // set dynamically
    ];

    public function __construct(
        private Connection    $connection,
        private ConsentRecord $consentRecordModel,
    ) {}

    // =========================================================================
    // GET /v1/internal/compliance/consents
    // =========================================================================

    /**
     * Paginated list of all consent records.
     *
     * Query params: user_id, policy_version_id, revoked (true|false), page, per_page
     */
    public function listConsents(Request $request): Response
    {
        $this->requireComplianceRole($request);

        $query   = $request->getQuery() ?? [];
        $filters = $this->buildConsentFilters($query);
        $page    = max(1, (int) ($query['page']     ?? 1));
        $perPage = max(1, min(200, (int) ($query['per_page'] ?? 20)));

        $result = $this->consentRecordModel->list($filters, $page, $perPage);

        return Response::json($result);
    }

    // =========================================================================
    // GET /v1/internal/compliance/consents/{userId}
    // =========================================================================

    /**
     * Fetch all consent records for a specific user.
     * Returns each policy version and whether consent has been granted / revoked.
     */
    public function getConsentStatus(Request $request, int $userId): Response
    {
        $this->requireComplianceRole($request);

        $records = $this->consentRecordModel->forUser($userId);

        // Enrich with policy_key by joining policy_versions
        $enriched = [];
        foreach ($records as $record) {
            $policyVersion = $this->connection->query(
                'SELECT policy_key, version, effective_at FROM policy_versions WHERE id = ?',
                [(int) $record['policy_version_id']],
            );

            $policyInfo = $policyVersion[0] ?? null;

            $enriched[] = [
                'consent_record_id' => $record['id'],
                'policy_version_id' => $record['policy_version_id'],
                'policy_key'        => $policyInfo['policy_key'] ?? null,
                'policy_version'    => $policyInfo['version']    ?? null,
                'effective_at'      => $policyInfo['effective_at'] ?? null,
                'consented_at'      => $record['consented_at'],
                'revoked_at'        => $record['revoked_at'],
                'active'            => $record['revoked_at'] === null,
            ];
        }

        return Response::json([
            'user_id'  => $userId,
            'consents' => $enriched,
        ]);
    }

    // =========================================================================
    // POST /v1/internal/compliance/account-deletion/{userId}
    // =========================================================================

    /**
     * DPDP account deletion workflow.
     *
     * Steps:
     *   1. Verify the user exists and is not already deleted.
     *   2. Anonymise PII columns on `users` (mobile, email → placeholder).
     *      The row is NEVER hard-deleted; FK references on orders, payments, etc.
     *      must be preserved for financial record retention.
     *   3. Revoke all active consents (set revoked_at) — consent records are
     *      NOT deleted (append-only).
     *   4. Set users.status = 'deactivated' and users.deleted_at = now.
     *
     * What is NOT deleted:
     *   - Order history, ledger entries, audit logs — legally required retention.
     *   - Consent records — DPDP mandates retention of consent audit trail.
     *   - Risk flags — compliance retention requirement.
     *
     * Returns 200 with a summary of what was anonymised.
     */
    public function processAccountDeletion(Request $request, int $userId): Response
    {
        $this->requireComplianceRole($request);

        // Verify user exists
        $user = $this->connection->query(
            'SELECT id, mobile, email, status, deleted_at FROM users WHERE id = ? LIMIT 1',
            [$userId],
        );

        if (empty($user)) {
            return Response::notFound("User {$userId} not found");
        }

        $user = $user[0];

        if ($user['deleted_at'] !== null) {
            throw new HttpException("User {$userId} has already been deleted", 409);
        }

        $now     = gmdate('Y-m-d H:i:s');
        $actorId = $this->resolveUserId($request);

        // --- Step 1: Anonymise PII on users table ---
        // Replace mobile with a deterministic non-PII token so the UNIQUE
        // constraint does not block future registrations with the same number.
        $anonymisedMobile = 'DELETED_' . $userId;
        $this->connection->execute(
            'UPDATE users SET mobile = ?, email = NULL, status = ?, deleted_at = ?, updated_at = ? WHERE id = ?',
            [$anonymisedMobile, 'deactivated', $now, $now, $userId],
        );

        // --- Step 2: Revoke all active consents ---
        $consentRows = $this->consentRecordModel->forUser($userId);
        $revokedCount = 0;

        foreach ($consentRows as $consent) {
            if ($consent['revoked_at'] === null) {
                $this->consentRecordModel->revoke(
                    $userId,
                    (int) $consent['policy_version_id'],
                );
                $revokedCount++;
            }
        }

        // --- Step 3: Invalidate active sessions (notification only; actual
        //     JWT invalidation requires a blocklist or short TTL strategy) ---
        // TODO: push userId onto JWT revocation list / clear refresh tokens.

        return Response::json([
            'message'           => "User {$userId} has been anonymised per DPDP deletion request",
            'user_id'           => $userId,
            'pii_anonymised'    => true,
            'consents_revoked'  => $revokedCount,
            'processed_at'      => $now,
            'processed_by'      => $actorId,
            'retained_records'  => [
                'orders',
                'ledger_entries',
                'audit_logs',
                'consent_records',
                'risk_flags',
            ],
        ]);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * @throws HttpException 401 | 403
     */
    private function requireComplianceRole(Request $request): void
    {
        $user = $request->getParam('_user');

        if (empty($user['user_id'])) {
            throw new HttpException('Authentication required', 401);
        }

        $userRoles = (array) ($user['roles'] ?? []);

        foreach (self::ALLOWED_ROLES as $role) {
            if (in_array($role, $userRoles, true)) {
                return;
            }
        }

        throw new HttpException(
            'Access denied. Required role(s): ' . implode(', ', self::ALLOWED_ROLES),
            403,
        );
    }

    private function resolveUserId(Request $request): int
    {
        $user = $request->getParam('_user');
        return (int) ($user['user_id'] ?? 0);
    }

    private function buildConsentFilters(array $query): array
    {
        $filters = [];

        if (!empty($query['user_id']) && ctype_digit((string) $query['user_id'])) {
            $filters['user_id'] = (int) $query['user_id'];
        }

        if (!empty($query['policy_version_id']) && ctype_digit((string) $query['policy_version_id'])) {
            $filters['policy_version_id'] = (int) $query['policy_version_id'];
        }

        if (isset($query['revoked'])) {
            $filters['revoked'] = filter_var($query['revoked'], FILTER_VALIDATE_BOOLEAN);
        }

        return $filters;
    }
}
