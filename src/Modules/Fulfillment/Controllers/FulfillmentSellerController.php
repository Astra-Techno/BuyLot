<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Controllers;

use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Fulfillment\Models\Shipment;
use App\Modules\Fulfillment\Models\ShipmentItem;
use App\Modules\Fulfillment\Services\FulfillmentService;

/**
 * FulfillmentSellerController — seller-facing fulfillment endpoints.
 *
 *   GET  /v1/seller/orders               -> listOrders()
 *   POST /v1/seller/orders/{id}/dispatch -> dispatch()
 *   PUT  /v1/seller/shipments/{id}/tracking -> updateTracking()
 */
readonly class FulfillmentSellerController
{
    public function __construct(
        private FulfillmentService $fulfillmentService,
        private Connection         $connection,
        private Shipment           $shipmentModel,
        private ShipmentItem       $shipmentItemModel,
    ) {}

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /v1/seller/orders
     *
     * List seller's fulfillment orders.
     * Query params: status, page, per_page
     */
    public function listOrders(Request $request): Response
    {
        $sellerId = $this->resolveSellerProfileId($request);

        $page    = max(1, (int) ($request->getQuery('page', '1')));
        $perPage = min(100, max(1, (int) ($request->getQuery('per_page', '20'))));
        $status  = $request->getQuery('status');

        $params = [$sellerId];
        $where  = 'WHERE sfo.seller_id = ?';

        if ($status !== null && $status !== '') {
            $where    .= ' AND sfo.status = ?';
            $params[]  = $status;
        }

        $offset = ($page - 1) * $perPage;

        $rows = $this->connection->query(
            "SELECT sfo.*, COUNT(s.id) AS shipment_count
             FROM seller_fulfillment_orders sfo
             LEFT JOIN shipments s ON s.seller_fulfillment_order_id = sfo.id
             {$where}
             GROUP BY sfo.id
             ORDER BY sfo.created_at DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        $totalRow = $this->connection->query(
            "SELECT COUNT(*) AS total FROM seller_fulfillment_orders sfo {$where}",
            $params,
        );

        return Response::json([
            'data'     => $rows,
            'total'    => (int) ($totalRow[0]['total'] ?? 0),
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    /**
     * POST /v1/seller/orders/{id}/dispatch
     *
     * Mark a fulfillment order as dispatched with carrier+tracking info.
     * Optionally upload evidence (object_ref from a pre-signed upload).
     *
     * Body: {
     *   "carrier":      "DTDC",
     *   "tracking_ref": "DTDC1234567890",
     *   "evidence_ref": "uploads/invoices/abc123.pdf",   // required
     *   "items": [
     *     { "order_item_id": 42, "qty": 10 }             // optional for first dispatch
     *   ]
     * }
     */
    public function dispatch(Request $request, int $orderId): Response
    {
        $sellerId = $this->resolveSellerProfileId($request);
        $body     = $request->getBody() ?? [];

        $carrier      = trim((string) ($body['carrier']      ?? ''));
        $trackingRef  = trim((string) ($body['tracking_ref'] ?? ''));
        $evidenceRef  = trim((string) ($body['evidence_ref'] ?? ''));

        if ($carrier === '' || $trackingRef === '' || $evidenceRef === '') {
            throw new HttpException('carrier, tracking_ref, and evidence_ref are required', 422);
        }

        // Resolve the seller_fulfillment_order for this seller + fulfillment order id
        $sfo = $this->connection->query(
            'SELECT id FROM seller_fulfillment_orders
             WHERE id = ? AND seller_id = ?
             LIMIT 1',
            [$orderId, $sellerId],
        );

        if (empty($sfo)) {
            return Response::notFound('Fulfillment order not found');
        }

        $fulfillmentOrderId = (int) $sfo[0]['id'];

        // Determine items to ship
        $items = [];
        if (!empty($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as $item) {
                $items[] = [
                    'order_item_id' => (int) ($item['order_item_id'] ?? 0),
                    'qty'           => (int) ($item['qty']           ?? 0),
                ];
            }
        } else {
            // Default: ship all remaining qty for each order_item in this fulfillment order
            $items = $this->buildRemainingItems($fulfillmentOrderId);
        }

        if (empty($items)) {
            throw new HttpException('No shippable items found for this fulfillment order', 422);
        }

        // Create shipment
        $shipment = $this->fulfillmentService->createShipment($fulfillmentOrderId, $items);

        // Record dispatch (carrier + tracking + evidence)
        $dispatched = $this->fulfillmentService->recordDispatch(
            (int) $shipment['id'],
            $carrier,
            $trackingRef,
            $evidenceRef,
        );

        return Response::json($dispatched, 201);
    }

    /**
     * PUT /v1/seller/shipments/{id}/tracking
     *
     * Update carrier and tracking reference for an existing shipment.
     *
     * Body: { "carrier": "BlueDart", "tracking_ref": "BD9876543210" }
     */
    public function updateTracking(Request $request, int $shipmentId): Response
    {
        $sellerId = $this->resolveSellerProfileId($request);
        $body     = $request->getBody() ?? [];

        $carrier     = trim((string) ($body['carrier']      ?? ''));
        $trackingRef = trim((string) ($body['tracking_ref'] ?? ''));

        if ($carrier === '' || $trackingRef === '') {
            throw new HttpException('carrier and tracking_ref are required', 422);
        }

        // Ownership: seller must own the fulfillment order behind this shipment
        $this->assertSellerOwnsShipment($sellerId, $shipmentId);

        $shipment = $this->fulfillmentService->updateTracking($shipmentId, $carrier, $trackingRef);

        return Response::json($shipment);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveSellerProfileId(Request $request): int
    {
        $userCtx = $request->getParam('_user');

        if (!is_array($userCtx) || empty($userCtx['user_id'])) {
            throw new HttpException('Invalid authentication context', 401);
        }

        $userId = (int) $userCtx['user_id'];

        // Look up seller_profiles.id for this user via their organization
        $rows = $this->connection->query(
            'SELECT sp.id
             FROM seller_profiles sp
             JOIN organizations o ON o.id = sp.organization_id
             JOIN organization_members om ON om.organization_id = o.id
             WHERE om.user_id = ? AND sp.status = ?
             LIMIT 1',
            [$userId, 'active'],
        );

        if (empty($rows)) {
            throw new HttpException('Active seller profile not found for this user', 403);
        }

        return (int) $rows[0]['id'];
    }

    /**
     * Build items array from all un-dispatched order_item qty in a fulfillment order.
     *
     * @return array<int, array{order_item_id: int, qty: int}>
     */
    private function buildRemainingItems(int $fulfillmentOrderId): array
    {
        // Get all order_items linked to this fulfillment order's offer
        $rows = $this->connection->query(
            'SELECT oi.id AS order_item_id, oi.qty AS ordered_qty,
                    COALESCE(SUM(si.qty), 0) AS dispatched_qty
             FROM seller_fulfillment_orders sfo
             JOIN orders o ON o.offer_id = sfo.offer_id
             JOIN order_items oi ON oi.order_id = o.id
             LEFT JOIN shipment_items si ON si.order_item_id = oi.id
             WHERE sfo.id = ?
             GROUP BY oi.id, oi.qty',
            [$fulfillmentOrderId],
        );

        $items = [];
        foreach ($rows as $row) {
            $remaining = (int) $row['ordered_qty'] - (int) $row['dispatched_qty'];
            if ($remaining > 0) {
                $items[] = [
                    'order_item_id' => (int) $row['order_item_id'],
                    'qty'           => $remaining,
                ];
            }
        }

        return $items;
    }

    private function assertSellerOwnsShipment(int $sellerId, int $shipmentId): void
    {
        $rows = $this->connection->query(
            'SELECT s.id
             FROM shipments s
             JOIN seller_fulfillment_orders sfo ON sfo.id = s.seller_fulfillment_order_id
             WHERE s.id = ? AND sfo.seller_id = ?
             LIMIT 1',
            [$shipmentId, $sellerId],
        );

        if (empty($rows)) {
            throw new HttpException('Shipment not found', 404);
        }
    }
}
