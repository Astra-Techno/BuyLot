<?php

declare(strict_types=1);

namespace App\Modules\Notifications;

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Modules\Notifications\Controllers\NotificationController;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Services\NotificationChannelService;
use App\Modules\Notifications\Services\NotificationService;

/**
 * Register all Notifications module routes onto the shared Router.
 *
 * Call from public/index.php:
 *
 *   \App\Modules\Notifications\Routes::register($router, $connection, $jwtHandler);
 */
final class Routes
{
    public static function register(Router $router, Connection $connection, JwtHandler $jwtHandler): void
    {
        // -------------------------------------------------------------------------
        // Dependencies
        // -------------------------------------------------------------------------

        $notificationModel  = new Notification($connection);
        $deliveryModel      = new NotificationDelivery($connection);
        $channelService     = new NotificationChannelService($deliveryModel);
        $notificationService = new NotificationService($notificationModel, $channelService, $connection);

        $controller = new NotificationController($notificationService, $notificationModel);
        $auth       = new AuthMiddleware($jwtHandler);

        // -------------------------------------------------------------------------
        // GET /v1/me/notifications
        // Paginated list of notifications for the authenticated user.
        // -------------------------------------------------------------------------

        $router->get(
            '/v1/me/notifications',
            fn(Request $req): Response => $controller->list($req),
            [$auth],
        );

        // -------------------------------------------------------------------------
        // POST /v1/me/notifications/{id}/read
        // Mark a single notification as read.
        // -------------------------------------------------------------------------

        $router->post(
            '/v1/me/notifications/{id}/read',
            function (Request $req) use ($controller): Response {
                $id = (int) $req->getParam('id');
                return $controller->markRead($req, $id);
            },
            [$auth],
        );

        // -------------------------------------------------------------------------
        // POST /v1/me/notifications/read-all
        // Mark all notifications as read for the authenticated user.
        // -------------------------------------------------------------------------

        $router->post(
            '/v1/me/notifications/read-all',
            fn(Request $req): Response => $controller->markAllRead($req),
            [$auth],
        );

        // -------------------------------------------------------------------------
        // GET /v1/me/notification-preferences
        // Fetch channel preferences for the authenticated user.
        // -------------------------------------------------------------------------

        $router->get(
            '/v1/me/notification-preferences',
            fn(Request $req): Response => $controller->getPreferences($req),
            [$auth],
        );

        // -------------------------------------------------------------------------
        // PUT /v1/me/notification-preferences
        // Update channel preferences for the authenticated user.
        // -------------------------------------------------------------------------

        $router->put(
            '/v1/me/notification-preferences',
            fn(Request $req): Response => $controller->updatePreferences($req),
            [$auth],
        );
    }
}
