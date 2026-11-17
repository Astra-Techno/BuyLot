<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Middleware;

use App\Core\Database\Connection;
use App\Core\Http\Middleware\MiddlewareInterface;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Organizations\Models\OrganizationMember;

/**
 * OrganizationScopeMiddleware
 *
 * Resolves the authenticated user's organization context and injects it into
 * the request params under the key '_org_context'.
 *
 * Injected context shape:
 *   _org_context => [
 *     'organization_id' => int,
 *     'role'            => string,
 *     'status'          => string,
 *   ]
 *
 * When the route contains an {id} param that is an org id, the middleware also
 * verifies the user is an active member of that specific org and short-circuits
 * with 403 if not.
 *
 * Usage: add as middleware on any route that must be scoped to an organization.
 */
class OrganizationScopeMiddleware implements MiddlewareInterface
{
    private OrganizationMember $memberModel;

    public function __construct(private readonly Connection $connection)
    {
        $this->memberModel = new OrganizationMember($connection);
    }

    public function handle(Request $request, callable $next): Response
    {
        $user = $request->getParam('_user');

        if (empty($user['user_id'])) {
            return Response::unauthorized('Authentication required');
        }

        $userId = (int) $user['user_id'];

        // If the route exposes an {id} segment that is the org id, scope to it.
        $orgId = $this->resolveOrgId($request);

        if ($orgId !== null) {
            $membership = $this->memberModel->findActiveByOrgAndUser($orgId, $userId);

            if ($membership === null) {
                return Response::forbidden('You are not a member of this organization');
            }

            $request = $this->injectOrgContext($request, [
                'organization_id' => (int) $membership['organization_id'],
                'role'            => $membership['role'],
                'status'          => $membership['status'],
            ]);

            return $next($request);
        }

        // No explicit org id in route: attach all orgs the user belongs to.
        $memberships = $this->memberModel->findOrganizationsByUser($userId);

        if (empty($memberships)) {
            // User has no org; still let the request through — the handler decides
            // whether a missing org context is acceptable.
            $request = $this->injectOrgContext($request, null);
            return $next($request);
        }

        // Default to the first active membership (oldest); handlers can inspect
        // _org_context directly when they need a single org scope.
        $activeMembership = null;
        foreach ($memberships as $m) {
            if ($m['status'] === OrganizationMember::STATUS_ACTIVE) {
                $activeMembership = $m;
                break;
            }
        }

        $context = $activeMembership !== null
            ? [
                'organization_id' => (int) $activeMembership['organization_id'],
                'role'            => $activeMembership['role'],
                'status'          => $activeMembership['status'],
                'all_memberships' => $memberships,
            ]
            : null;

        $request = $this->injectOrgContext($request, $context);

        return $next($request);
    }

    private function resolveOrgId(Request $request): ?int
    {
        // Routes use {id} for org id (e.g. /v1/organizations/{id})
        $idParam = $request->getParam('id');

        if ($idParam === null) {
            return null;
        }

        $id = filter_var($idParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $id !== false ? (int) $id : null;
    }

    private function injectOrgContext(Request $request, ?array $context): Request
    {
        $params = $request->getParams();
        $params['_org_context'] = $context;
        return $request->setParams($params);
    }
}
