<?php

declare(strict_types=1);

namespace App\Modules\Addresses;

use App\Core\Auth\AuthMiddleware;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Modules\Addresses\Controllers\AddressController;
use App\Modules\Addresses\Models\Address;

/**
 * Register all Addresses routes onto the router.
 *
 * Usage (in your bootstrap / index.php):
 *   \App\Modules\Addresses\Routes::register($router, $authMiddleware, $db);
 */
class Routes
{
    public static function register(
        Router $router,
        AuthMiddleware $auth,
        Connection $db,
    ): void {
        $address    = new Address($db);
        $controller = new AddressController($address);

        // GET /v1/me/addresses — list own addresses
        $router->get(
            '/v1/me/addresses',
            fn(Request $req): Response => $controller->list($req),
            [$auth],
        );

        // POST /v1/me/addresses — add new address
        $router->post(
            '/v1/me/addresses',
            fn(Request $req): Response => $controller->create($req),
            [$auth],
        );

        // PUT /v1/me/addresses/{id} — update own address
        $router->put(
            '/v1/me/addresses/{id}',
            fn(Request $req): Response => $controller->update($req),
            [$auth],
        );

        // DELETE /v1/me/addresses/{id} — delete own address
        $router->delete(
            '/v1/me/addresses/{id}',
            fn(Request $req): Response => $controller->delete($req),
            [$auth],
        );

        // POST /v1/me/addresses/{id}/default — set as default
        $router->post(
            '/v1/me/addresses/{id}/default',
            fn(Request $req): Response => $controller->setDefault($req),
            [$auth],
        );
    }
}
