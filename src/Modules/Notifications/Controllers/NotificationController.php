<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Notifications\Models\NotificationDelivery;
use App\Modules\Notifications\Services\NotificationService;

/**
 * NotificationController — authenticated user-facing notification endpoints.
 *
 * Routes:
 *   GET  /v1/me/notifications                  → list()
 *   POST /v1/me/notifications/{id}/read        → markRead()
 *   POST /v1/me/notifications/read-all         → markAllRead()
 *   GET  /v1/me/notification-preferences       → getPreferences()
 *   PUT  /v1/me/notification-preferences       → updatePreferences()
 */
readonly class NotificationController
{
    public function __construct(
        private NotificationService $notificationService,
        private Notification        $notificationModel,
    ) {}

    // -------------------------------------------------------------------------
    // GET /v1/me/notifications
    // -------------------------------------------------------------------------

    public function list(Request $request): Response
    {
        $userId = $this->resolveUserId($request);

        $query   = $request->getQuery() ?? [];
        $page    = max(1, (int) ($query['page']     ?? 1));
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 20)));

        $result = $this->notificationModel->listForUser($userId, $page, $perPage);

        return Response::json([
            'notifications' => $result['data'],
            'unread_count'  => $result['unread_count'],
            'total'         => $result['total'],
            'page'          => $result['page'],
            'per_page'      => $result['per_page'],
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /v1/me/notifications/{id}/read
    // -------------------------------------------------------------------------

    public function markRead(Request $request, int $id): Response
    {
        $userId  = $this->resolveUserId($request);
        $updated = $this->notificationService->markRead($id, $userId);

        if (!$updated) {
            return Response::notFound('Notification not found or already read');
        }

        return Response::json(['message' => 'Notification marked as read']);
    }

    // -------------------------------------------------------------------------
    // POST /v1/me/notifications/read-all
    // -------------------------------------------------------------------------

    public function markAllRead(Request $request): Response
    {
        $userId = $this->resolveUserId($request);
        $count  = $this->notificationService->markAllRead($userId);

        return Response::json([
            'message'       => 'All notifications marked as read',
            'updated_count' => $count,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /v1/me/notification-preferences
    // -------------------------------------------------------------------------

    public function getPreferences(Request $request): Response
    {
        $userId      = $this->resolveUserId($request);
        $preferences = $this->notificationService->getPreferences($userId);

        return Response::json(['preferences' => $preferences]);
    }

    // -------------------------------------------------------------------------
    // PUT /v1/me/notification-preferences
    // -------------------------------------------------------------------------

    public function updatePreferences(Request $request): Response
    {
        $userId = $this->resolveUserId($request);
        $body   = $request->getBody() ?? [];

        if (!isset($body['preferences']) || !is_array($body['preferences'])) {
            throw new HttpException('Field "preferences" must be an object mapping channel to boolean', 422);
        }

        $input = [];
        foreach ($body['preferences'] as $channel => $enabled) {
            if (!in_array($channel, NotificationDelivery::VALID_CHANNELS, true)) {
                throw new HttpException("Unknown channel: {$channel}", 422);
            }
            $input[$channel] = (bool) $enabled;
        }

        $this->notificationService->updatePreferences($userId, $input);

        $updated = $this->notificationService->getPreferences($userId);

        return Response::json([
            'message'     => 'Notification preferences updated',
            'preferences' => $updated,
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveUserId(Request $request): int
    {
        $userCtx = $request->getParam('_user');

        if (!is_array($userCtx) || empty($userCtx['user_id'])) {
            throw new HttpException('Invalid authentication context', 401);
        }

        return (int) $userCtx['user_id'];
    }
}
