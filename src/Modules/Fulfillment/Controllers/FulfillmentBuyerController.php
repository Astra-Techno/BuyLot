<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Controllers;

use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Fulfillment\Services\FulfillmentService;

/**
 * FulfillmentBuyerController — buyer-facing fulfillment endpoints.
 *
 *   GET  /v1/orders/{id}/shipment           -> trackShipment()
 *   POST /v1/shipments/{id}/acknowledgements -> acknowledgeDelivery()
 */
readonly class FulfillmentBuyerController
{
    public function __construct(
        private FulfillmentService $fulfillmentService,
    ) {}

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /v1/orders/{id}/shipment
     *
     * Returns shipping status and tracking details for a buyer's order.
     * Only the authenticated buyer who owns the order may call this.
     */
    public function trackShipment(Request $request, int $orderId): Response
    {
        $buyerId = $this->resolveUserId($request);

        $tracking = $this->fulfillmentService->getTrackingForBuyer($orderId, $buyerId);

        return Response::json($tracking);
    }

    /**
     * POST /v1/shipments/{id}/acknowledgements
     *
     * Record buyer's acknowledgement of delivery.
     *
     * Three scenarios:
     *   1. Accept all:     accepted_qty covers all shipped items, disputed_qty is empty.
     *   2. Partial accept: accepted_qty + disputed_qty cover shipped items (some disputed).
     *   3. Report issue:   disputed_qty covers all items, accepted_qty is empty.
     *
     * Body (all optional keys; omitted item IDs are treated as qty=0):
     * {
     *   "accepted_qty": { "42": 5, "43": 3 },
     *   "disputed_qty": { "44": 2 },
     *   "dispute_reason": "Two units arrived damaged"   // optional note
     * }
     *
     * Returns the created buyer_acknowledgement row.
     */
    public function acknowledgeDelivery(Request $request, int $shipmentId): Response
    {
        $buyerId = $this->resolveUserId($request);
        $body    = $request->getBody() ?? [];

        $acceptedQty = $this->parseQtyMap($body['accepted_qty'] ?? []);
        $disputedQty = $this->parseQtyMap($body['disputed_qty'] ?? []);

        // At least one item must be addressed
        if (empty($acceptedQty) && empty($disputedQty)) {
            throw new HttpException(
                'At least one of accepted_qty or disputed_qty must be provided',
                422,
            );
        }

        $acknowledgement = $this->fulfillmentService->processAcknowledgement(
            $shipmentId,
            $buyerId,
            $acceptedQty,
            $disputedQty,
        );

        return Response::json($acknowledgement, 201);
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

    /**
     * Parse and validate a qty map from the request body.
     * Accepts: { "order_item_id" => qty } or null/empty.
     * Keys are cast to string; values to int.
     *
     * @return array<string, int>
     * @throws HttpException on malformed input
     */
    private function parseQtyMap(mixed $raw): array
    {
        if (empty($raw)) {
            return [];
        }

        if (!is_array($raw)) {
            throw new HttpException('accepted_qty and disputed_qty must be objects', 422);
        }

        $result = [];
        foreach ($raw as $itemId => $qty) {
            $intQty = (int) $qty;
            if ($intQty < 0) {
                throw new HttpException(
                    "Quantity for order_item {$itemId} cannot be negative",
                    422,
                );
            }
            if ($intQty > 0) {
                $result[(string) $itemId] = $intQty;
            }
        }

        return $result;
    }
}
