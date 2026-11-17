<?php

declare(strict_types=1);

namespace App\Modules\Organizations;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Modules\Organizations\Controllers\OrganizationController;
use App\Modules\Organizations\Middleware\OrganizationScopeMiddleware;

/**
 * Register all Organization module routes onto the given Router.
 *
 * Call this from your application bootstrap:
 *   \App\Modules\Organizations\Routes::register($router, $connection, $jwtHandler);
 */
final class Routes
{
    public static function register(Router $router, Connection $connection, JwtHandler $jwtHandler): void
    {
        $controller = new OrganizationController($connection);
        $auth       = new AuthMiddleware($jwtHandler);
        $scope      = new OrganizationScopeMiddleware($connection);

        // -----------------------------------------------------------------
        // POST /v1/organizations
        // Create a new organization; authenticated user becomes the OWNER.
        // -----------------------------------------------------------------
        $router->post(
            '/v1/organizations',
            fn(Request $req): Response => $controller->create($req),
            [$auth],
        );

        // -----------------------------------------------------------------
        // GET /v1/organizations/{id}
        // Retrieve organization detail; caller must be an active member.
        // -----------------------------------------------------------------
        $router->get(
            '/v1/organizations/{id}',
            function (Request $req) use ($controller): Response {
                $id = (int) $req->getParam('id');
                return $controller->show($req, $id);
            },
            [$auth, $scope],
        );

        // -----------------------------------------------------------------
        // PUT /v1/organizations/{id}
        // Update organization; OWNER or ADMIN only.
        // -----------------------------------------------------------------
        $router->put(
            '/v1/organizations/{id}',
            function (Request $req) use ($controller): Response {
                $id = (int) $req->getParam('id');
                return $controller->update($req, $id);
            },
            [$auth, $scope],
        );

        // -----------------------------------------------------------------
        // GET /v1/organizations/{id}/members
        // List members; active member access.
        // -----------------------------------------------------------------
        $router->get(
            '/v1/organizations/{id}/members',
            function (Request $req) use ($controller): Response {
                $id = (int) $req->getParam('id');
                return $controller->listMembers($req, $id);
            },
            [$auth, $scope],
        );

        // -----------------------------------------------------------------
        // POST /v1/organizations/{id}/members
        // Invite a new member; OWNER or ADMIN only.
        // -----------------------------------------------------------------
        $router->post(
            '/v1/organizations/{id}/members',
            function (Request $req) use ($controller): Response {
                $id = (int) $req->getParam('id');
                return $controller->addMember($req, $id);
            },
            [$auth, $scope],
        );

        // -----------------------------------------------------------------
        // PUT /v1/organizations/{id}/members/{memberId}
        // Change a member's role or status; OWNER or ADMIN only.
        // -----------------------------------------------------------------
        $router->put(
            '/v1/organizations/{id}/members/{memberId}',
            function (Request $req) use ($controller): Response {
                $id       = (int) $req->getParam('id');
                $memberId = (int) $req->getParam('memberId');
                return $controller->updateMember($req, $id, $memberId);
            },
            [$auth, $scope],
        );

        // -----------------------------------------------------------------
        // DELETE /v1/organizations/{id}/members/{memberId}
        // Remove a member; OWNER or ADMIN only.
        // -----------------------------------------------------------------
        $router->delete(
            '/v1/organizations/{id}/members/{memberId}',
            function (Request $req) use ($controller): Response {
                $id       = (int) $req->getParam('id');
                $memberId = (int) $req->getParam('memberId');
                return $controller->removeMember($req, $id, $memberId);
            },
            [$auth, $scope],
        );
    }
}
