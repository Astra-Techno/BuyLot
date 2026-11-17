<?php

declare(strict_types=1);

namespace App\Modules\Organizations\Controllers;

use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Organizations\Services\OrganizationService;

readonly class OrganizationController
{
    private OrganizationService $service;

    public function __construct(private Connection $connection)
    {
        $this->service = new OrganizationService($connection);
    }

    // -------------------------------------------------------------------------
    // POST /v1/organizations
    // -------------------------------------------------------------------------

    public function create(Request $request): Response
    {
        $user = $this->requireAuth($request);
        $data = $request->getBody();

        $org = $this->service->createOrganization($data, (int) $user['user_id']);

        return Response::created($org);
    }

    // -------------------------------------------------------------------------
    // GET /v1/organizations/{id}
    // -------------------------------------------------------------------------

    public function show(Request $request, int $id): Response
    {
        $user = $this->requireAuth($request);

        $org = $this->service->getOrganizationForMember($id, (int) $user['user_id']);

        return Response::json($org);
    }

    // -------------------------------------------------------------------------
    // PUT /v1/organizations/{id}
    // -------------------------------------------------------------------------

    public function update(Request $request, int $id): Response
    {
        $user = $this->requireAuth($request);
        $data = $request->getBody();

        $org = $this->service->updateOrganization($id, $data, (int) $user['user_id']);

        return Response::json($org);
    }

    // -------------------------------------------------------------------------
    // GET /v1/organizations/{id}/members
    // -------------------------------------------------------------------------

    public function listMembers(Request $request, int $id): Response
    {
        $user = $this->requireAuth($request);

        $limit  = max(1, min(100, (int) ($request->getQuery('limit') ?? 50)));
        $offset = max(0, (int) ($request->getQuery('offset') ?? 0));

        $result = $this->service->listMembers($id, (int) $user['user_id'], $limit, $offset);

        return Response::json([
            'members' => $result['members'],
            'total'   => $result['total'],
            'limit'   => $limit,
            'offset'  => $offset,
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /v1/organizations/{id}/members
    // -------------------------------------------------------------------------

    public function addMember(Request $request, int $id): Response
    {
        $user = $this->requireAuth($request);
        $data = $request->getBody();

        $member = $this->service->addMember($id, $data, (int) $user['user_id']);

        return Response::created($member);
    }

    // -------------------------------------------------------------------------
    // PUT /v1/organizations/{id}/members/{memberId}
    // -------------------------------------------------------------------------

    public function updateMember(Request $request, int $id, int $memberId): Response
    {
        $user = $this->requireAuth($request);
        $data = $request->getBody();

        $member = $this->service->updateMember($id, $memberId, $data, (int) $user['user_id']);

        return Response::json($member);
    }

    // -------------------------------------------------------------------------
    // DELETE /v1/organizations/{id}/members/{memberId}
    // -------------------------------------------------------------------------

    public function removeMember(Request $request, int $id, int $memberId): Response
    {
        $user = $this->requireAuth($request);

        $this->service->removeMember($id, $memberId, (int) $user['user_id']);

        return Response::noContent();
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /**
     * Pull _user from request params (set by AuthMiddleware) and return it.
     * Throws an HttpException if not present.
     */
    private function requireAuth(Request $request): array
    {
        $user = $request->getParam('_user');

        if (empty($user['user_id'])) {
            throw new HttpException('Authentication required', 401);
        }

        return $user;
    }
}
