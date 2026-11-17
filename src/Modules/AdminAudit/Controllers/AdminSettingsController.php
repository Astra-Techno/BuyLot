<?php

declare(strict_types=1);

namespace App\Modules\AdminAudit\Controllers;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\AdminAudit\Models\PolicyVersion;
use App\Modules\AdminAudit\Models\RiskFlag;
use App\Modules\AdminAudit\Services\FeatureFlagService;

/**
 * AdminSettingsController — internal admin endpoints for platform settings,
 * feature flags, risk flags, and policy management.
 *
 * All endpoints require auth. Role enforcement:
 *   admin (compliance_admin | super_admin)   — read operations + risk flags
 *   super_admin                              — write operations (settings, flags, policies)
 *   compliance (compliance_admin)            — risk flag read/write
 *
 * Routes (registered in Routes.php):
 *   GET  /v1/internal/settings                     → getSettings()
 *   PUT  /v1/internal/settings                     → updateSettings()          [super_admin]
 *   GET  /v1/internal/feature-flags                → getFeatureFlags()
 *   PUT  /v1/internal/feature-flags/{flag}         → toggleFeatureFlag()       [super_admin]
 *   GET  /v1/internal/risk-flags                   → listRiskFlags()
 *   POST /v1/internal/risk-flags                   → createRiskFlag()
 *   PUT  /v1/internal/risk-flags/{id}/resolve      → resolveRiskFlag()
 *   GET  /v1/internal/policies                     → listPolicies()
 *   POST /v1/internal/policies                     → publishPolicy()           [super_admin]
 */
readonly class AdminSettingsController
{
    private const ROLE_ADMIN      = ['compliance_admin', 'super_admin'];
    private const ROLE_SUPER      = ['super_admin'];
    private const ROLE_COMPLIANCE = ['compliance_admin', 'super_admin'];

    public function __construct(
        private Connection         $connection,
        private FeatureFlagService $featureFlagService,
        private RiskFlag           $riskFlagModel,
        private PolicyVersion      $policyVersionModel,
    ) {}

    // =========================================================================
    // Settings
    // =========================================================================

    /**
     * GET /v1/internal/settings
     *
     * Returns platform-level configuration: active categories, enabled cities,
     * fee schedule, and current policy versions.
     */
    public function getSettings(Request $request): Response
    {
        $this->requireRoles($request, self::ROLE_ADMIN);

        $categories = $this->connection->query(
            'SELECT id, slug, name FROM categories WHERE is_active = 1 ORDER BY name ASC',
        );

        $cities = $this->connection->query(
            "SELECT flag, enabled FROM feature_flags WHERE flag LIKE 'city_%' ORDER BY flag ASC",
        );

        $activeCities = array_values(
            array_map(
                static fn($r) => substr($r['flag'], 5),
                array_filter($cities, static fn($r) => (bool) $r['enabled']),
            ),
        );

        // Latest policy per key
        $policies = [];
        foreach (PolicyVersion::VALID_TYPES as $policyKey) {
            $latest = $this->policyVersionModel->latestForKey($policyKey);
            if ($latest !== null) {
                $policies[$policyKey] = $latest;
            }
        }

        return Response::json([
            'categories'    => $categories,
            'active_cities' => $activeCities,
            'policies'      => $policies,
        ]);
    }

    /**
     * PUT /v1/internal/settings
     *
     * Update mutable platform settings.
     * Currently supports toggling active cities via feature flags.
     *
     * Body: { "cities": {"mumbai": true, "delhi": false} }
     */
    public function updateSettings(Request $request): Response
    {
        $this->requireRoles($request, self::ROLE_SUPER);

        $body    = $request->getBody() ?? [];
        $actorId = $this->resolveUserId($request);
        $updated = [];

        if (!empty($body['cities']) && is_array($body['cities'])) {
            foreach ($body['cities'] as $city => $enabled) {
                $flag = 'city_' . strtolower(preg_replace('/[^a-z0-9]/i', '_', (string) $city));
                $this->featureFlagService->setFlag($flag, (bool) $enabled, $actorId);
                $updated['cities'][$city] = (bool) $enabled;
            }
        }

        if (empty($updated)) {
            throw new HttpException('No valid settings fields provided', 422);
        }

        return Response::json([
            'message' => 'Settings updated',
            'updated' => $updated,
        ]);
    }

    // =========================================================================
    // Feature flags
    // =========================================================================

    /**
     * GET /v1/internal/feature-flags
     */
    public function getFeatureFlags(Request $request): Response
    {
        $this->requireRoles($request, self::ROLE_ADMIN);

        $flags = $this->featureFlagService->getFlags();

        return Response::json(['flags' => $flags]);
    }

    /**
     * PUT /v1/internal/feature-flags/{flag}
     *
     * Body: { "enabled": true }
     */
    public function toggleFeatureFlag(Request $request, string $flag): Response
    {
        $this->requireRoles($request, self::ROLE_SUPER);

        $body    = $request->getBody() ?? [];
        $actorId = $this->resolveUserId($request);

        if (!isset($body['enabled'])) {
            throw new HttpException('Field "enabled" (boolean) is required', 422);
        }

        try {
            $this->featureFlagService->setFlag($flag, (bool) $body['enabled'], $actorId);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException($e->getMessage(), 422);
        }

        return Response::json([
            'message' => "Feature flag '{$flag}' " . ($body['enabled'] ? 'enabled' : 'disabled'),
            'flag'    => $flag,
            'enabled' => (bool) $body['enabled'],
        ]);
    }

    // =========================================================================
    // Risk flags
    // =========================================================================

    /**
     * GET /v1/internal/risk-flags
     *
     * Query params: entity_type, entity_id, flag_type, severity, status, page, per_page
     */
    public function listRiskFlags(Request $request): Response
    {
        $this->requireRoles($request, self::ROLE_COMPLIANCE);

        $query   = $request->getQuery() ?? [];
        $filters = $this->buildRiskFlagFilters($query);
        $page    = max(1, (int) ($query['page']     ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));

        $result = $this->riskFlagModel->list($filters, $page, $perPage);

        return Response::json($result);
    }

    /**
     * POST /v1/internal/risk-flags
     *
     * Body: { entity_type, entity_id, flag_type, severity, notes? }
     */
    public function createRiskFlag(Request $request): Response
    {
        $this->requireRoles($request, self::ROLE_COMPLIANCE);

        $body    = $request->getBody() ?? [];
        $actorId = $this->resolveUserId($request);

        $this->validateRequired($body, ['entity_type', 'entity_id', 'flag_type', 'severity']);

        if (!in_array($body['flag_type'], RiskFlag::VALID_FLAG_TYPES, true)) {
            throw new HttpException(
                'Invalid flag_type. Allowed: ' . implode(', ', RiskFlag::VALID_FLAG_TYPES),
                422,
            );
        }

        if (!in_array($body['severity'], RiskFlag::VALID_SEVERITIES, true)) {
            throw new HttpException(
                'Invalid severity. Allowed: ' . implode(', ', RiskFlag::VALID_SEVERITIES),
                422,
            );
        }

        $id = $this->riskFlagModel->create([
            'entity_type' => (string) $body['entity_type'],
            'entity_id'   => (int) $body['entity_id'],
            'flag_type'   => (string) $body['flag_type'],
            'severity'    => (string) $body['severity'],
            'raised_by'   => $actorId,
            'notes'       => $body['notes'] ?? null,
        ]);

        $flag = $this->riskFlagModel->findById($id);

        return Response::json(['risk_flag' => $flag], 201);
    }

    /**
     * PUT /v1/internal/risk-flags/{id}/resolve
     *
     * Body: { status, notes? }
     * status must be one of: resolved, false_positive, investigating
     */
    public function resolveRiskFlag(Request $request, int $id): Response
    {
        $this->requireRoles($request, self::ROLE_COMPLIANCE);

        $body    = $request->getBody() ?? [];
        $actorId = $this->resolveUserId($request);

        if (empty($body['status'])) {
            throw new HttpException('Field "status" is required', 422);
        }

        $allowedResolutionStatuses = [
            RiskFlag::STATUS_INVESTIGATING,
            RiskFlag::STATUS_RESOLVED,
            RiskFlag::STATUS_FALSE_POSITIVE,
        ];

        if (!in_array($body['status'], $allowedResolutionStatuses, true)) {
            throw new HttpException(
                'Invalid status. Allowed for resolution: ' . implode(', ', $allowedResolutionStatuses),
                422,
            );
        }

        $flag = $this->riskFlagModel->findById($id);
        if ($flag === null) {
            return Response::notFound('Risk flag not found');
        }

        $this->riskFlagModel->resolve($id, [
            'status'      => $body['status'],
            'resolved_by' => $actorId,
            'notes'       => $body['notes'] ?? $flag['notes'],
        ]);

        return Response::json([
            'message'   => 'Risk flag updated',
            'risk_flag' => $this->riskFlagModel->findById($id),
        ]);
    }

    // =========================================================================
    // Policies
    // =========================================================================

    /**
     * GET /v1/internal/policies
     *
     * Query params: policy_key, page, per_page
     */
    public function listPolicies(Request $request): Response
    {
        $this->requireRoles($request, self::ROLE_ADMIN);

        $query     = $request->getQuery() ?? [];
        $policyKey = !empty($query['policy_key']) ? (string) $query['policy_key'] : null;
        $page      = max(1, (int) ($query['page']     ?? 1));
        $perPage   = max(1, min(100, (int) ($query['per_page'] ?? 20)));

        $result = $this->policyVersionModel->list($policyKey, $page, $perPage);

        return Response::json($result);
    }

    /**
     * POST /v1/internal/policies
     *
     * Body: { policy_key, version, effective_at, content_ref }
     */
    public function publishPolicy(Request $request): Response
    {
        $this->requireRoles($request, self::ROLE_SUPER);

        $body    = $request->getBody() ?? [];
        $actorId = $this->resolveUserId($request);

        $this->validateRequired($body, ['policy_key', 'version', 'effective_at', 'content_ref']);

        if (!in_array($body['policy_key'], PolicyVersion::VALID_TYPES, true)) {
            throw new HttpException(
                'Invalid policy_key. Allowed: ' . implode(', ', PolicyVersion::VALID_TYPES),
                422,
            );
        }

        try {
            $id = $this->policyVersionModel->publish([
                'policy_key'   => (string) $body['policy_key'],
                'version'      => (string) $body['version'],
                'effective_at' => (string) $body['effective_at'],
                'content_ref'  => (string) $body['content_ref'],
                'published_by' => $actorId,
            ]);
        } catch (\RuntimeException $e) {
            throw new HttpException($e->getMessage(), 409);
        }

        $policy = $this->policyVersionModel->findById($id);

        return Response::json(['policy_version' => $policy], 201);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /**
     * Require that the authenticated user holds at least one of the given roles.
     *
     * @param string[] $roles
     * @throws HttpException 401 | 403
     */
    private function requireRoles(Request $request, array $roles): void
    {
        $user = $request->getParam('_user');

        if (empty($user['user_id'])) {
            throw new HttpException('Authentication required', 401);
        }

        $userRoles = (array) ($user['roles'] ?? []);

        foreach ($roles as $role) {
            if (in_array($role, $userRoles, true)) {
                return;
            }
        }

        throw new HttpException(
            'Access denied. Required role(s): ' . implode(', ', $roles),
            403,
        );
    }

    private function resolveUserId(Request $request): int
    {
        $user = $request->getParam('_user');
        return (int) ($user['user_id'] ?? 0);
    }

    /**
     * @param string[] $fields
     * @throws HttpException 422 on missing field
     */
    private function validateRequired(array $body, array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($body[$field]) || $body[$field] === '') {
                throw new HttpException("Field \"{$field}\" is required", 422);
            }
        }
    }

    private function buildRiskFlagFilters(array $query): array
    {
        $filters = [];

        if (!empty($query['entity_type'])) {
            $filters['entity_type'] = (string) $query['entity_type'];
        }

        if (!empty($query['entity_id']) && ctype_digit((string) $query['entity_id'])) {
            $filters['entity_id'] = (int) $query['entity_id'];
        }

        if (!empty($query['flag_type'])) {
            $filters['flag_type'] = (string) $query['flag_type'];
        }

        if (!empty($query['severity'])) {
            $filters['severity'] = (string) $query['severity'];
        }

        if (!empty($query['status'])) {
            $filters['status'] = (string) $query['status'];
        }

        return $filters;
    }
}
