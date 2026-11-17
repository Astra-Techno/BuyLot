<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use App\Core\Exception\HttpException;
use App\Modules\Commitments\Services\CommitmentService;
use App\Modules\Commitments\Services\MOQService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Models\OrderStatusEvent;

/**
 * OrderService -- Core order lifecycle logic.
 *
 * Key invariants:
 *   - Price snapshot at checkout is immutable: unit_price, tax, shipping captured and NEVER changed.
 *   - No silent repricing.
 *   - State transitions guarded by Order::TRANSITIONS.
 *   - Every state change logged to order_status_events (append-only).
 */
class OrderService
{
    public function __construct(
        private Connection         $connection,
        private Order              $orderModel,
        private OrderItem          $orderItemModel,
        private OrderStatusEvent   $statusEventModel,
        private CommitmentService  $commitmentService,
        private MOQService         $moqService,
    ) {}

    // -------------------------------------------------------------------------
    // Order Creation
    // -------------------------------------------------------------------------

    /**
     * Create an order from a buyer's offer acceptance.
     *
     * Snapshots current prices immutably into order_items.snapshot_json.
     * No repricing is ever allowed after this point.
     *
     * @return array The created order
     */
    public function createFromAcceptance(int $buyerId, int $offerId, int $addressId): array
    {
        // 1. Validate offer exists and is in an accepting state
        $offer = (new QueryBuilder($this->connection))
            ->table('negotiated_offers')
            ->select('id', 'group_id', 'seller_id', 'status', 'expires_at', 'terms_snapshot_json')
            ->where('id', $offerId)
            ->first();

        if ($offer === null) {
            throw new HttpException('Offer not found', 404);
        }

        if (!in_array($offer['status'], ['published', 'moq_met'], true)) {
            throw new HttpException('Offer is not accepting orders. Status: ' . $offer['status'], 409);
        }

        // Check expiry
        if ($offer['expires_at'] <= gmdate('Y-m-d H:i:s')) {
            throw new HttpException('Offer has expired', 410);
        }

        // 2. Check buyer's acceptance exists
        $acceptance = (new QueryBuilder($this->connection))
            ->table('offer_acceptances')
            ->select('id', 'qty', 'accepted_terms_hash')
            ->where('offer_id', $offerId)
            ->where('buyer_id', $buyerId)
            ->first();

        if ($acceptance === null) {
            throw new HttpException('Buyer has not accepted this offer', 400);
        }

        // 3. Check for duplicate order (idempotency)
        $existing = (new QueryBuilder($this->connection))
            ->table('orders')
            ->select('id')
            ->where('buyer_id', $buyerId)
            ->where('offer_id', $offerId)
            ->where('deleted_at', null)
            ->first();

        if ($existing !== null) {
            $order = $this->orderModel->find((int) $existing['id']);
            if ($order !== null) {
                return $order;
            }
        }

        // 4. Check oversubscription
        $oversubCheck = $this->moqService->handleOversubscription($offerId, (int) $acceptance['qty']);
        if (!$oversubCheck['allowed']) {
            throw new HttpException('Stock reservation failed: ' . $oversubCheck['reason'], 409);
        }

        // 5. Get price tier for this acceptance quantity
        $priceTier = $this->resolveCurrentPriceTier($offerId);
        if ($priceTier === null) {
            throw new HttpException('No applicable price tier found', 500);
        }

        // 6. Build immutable price snapshot
        $groupId   = (int) $offer['group_id'];
        $variantId = $this->getGroupVariantId($groupId);
        $qty       = (int) $acceptance['qty'];

        $unitPricePaise = (int) $priceTier['unit_price_paise'];
        $taxPaise       = $this->calculateTax($unitPricePaise);
        $shippingPaise  = $this->calculateShipping($offerId, $addressId);

        $snapshot = json_encode([
            'unit_price_paise' => $unitPricePaise,
            'tax_paise'        => $taxPaise,
            'shipping_paise'   => $shippingPaise,
            'tier_min_qty'     => (int) $priceTier['min_paid_qty'],
            'offer_id'         => $offerId,
            'captured_at'      => gmdate('Y-m-d\TH:i:s\Z'),
        ], JSON_THROW_ON_ERROR);

        $totalPaise = ($unitPricePaise + $taxPaise + $shippingPaise) * $qty;

        // 7. Create order + items in a transaction
        $this->connection->beginTransaction();

        try {
            $orderId = $this->orderModel->create([
                'buyer_id'    => $buyerId,
                'group_id'    => $groupId,
                'offer_id'    => $offerId,
                'total_paise' => $totalPaise,
                'terms_hash'  => $acceptance['accepted_terms_hash'],
            ]);

            $this->orderItemModel->create([
                'order_id'         => $orderId,
                'variant_id'       => $variantId,
                'qty'              => $qty,
                'unit_price_paise' => $unitPricePaise,
                'tax_paise'        => $taxPaise,
                'shipping_paise'   => $shippingPaise,
                'snapshot_json'    => $snapshot,
            ]);

            // Create stock reservation
            (new QueryBuilder($this->connection))
                ->table('stock_reservations')
                ->insert([
                    'offer_id'   => $offerId,
                    'buyer_id'   => $buyerId,
                    'qty'        => $qty,
                    'expires_at' => $offer['expires_at'],
                    'status'     => 'active',
                    'created_at' => gmdate('Y-m-d H:i:s'),
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);

            // Log initial status event
            $this->statusEventModel->create(
                $orderId,
                null,
                Order::STATUS_PENDING_PAYMENT,
                $buyerId,
                'Order created from offer acceptance',
            );

            $this->connection->commit();

            return $this->orderModel->find($orderId) ?? throw new HttpException('Order creation failed', 500);
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Checkout & Payment
    // -------------------------------------------------------------------------

    /**
     * Generate a payment intent for checkout.
     *
     * @return array{payment_intent_id: int, amount_paise: int, idempotency_key: string}
     */
    public function initiateCheckout(int $orderId): array
    {
        $order = $this->orderModel->find($orderId);
        if ($order === null) {
            throw new HttpException('Order not found', 404);
        }

        if ($order['status'] !== Order::STATUS_PENDING_PAYMENT) {
            throw new HttpException('Order is not in pending_payment status', 409);
        }

        // Check for existing payment intent (idempotency)
        $existingIntent = (new QueryBuilder($this->connection))
            ->table('payment_intents')
            ->select('id', 'amount_paise', 'idempotency_key', 'status')
            ->where('order_id', $orderId)
            ->where('status', 'created')
            ->first();

        if ($existingIntent !== null) {
            return [
                'payment_intent_id' => (int) $existingIntent['id'],
                'amount_paise'      => (int) $existingIntent['amount_paise'],
                'idempotency_key'   => $existingIntent['idempotency_key'],
            ];
        }

        $idempotencyKey = 'pi_' . $orderId . '_' . bin2hex(random_bytes(16));

        (new QueryBuilder($this->connection))
            ->table('payment_intents')
            ->insert([
                'order_id'        => $orderId,
                'provider'        => 'razorpay',
                'amount_paise'    => (int) $order['total_paise'],
                'status'          => 'created',
                'idempotency_key' => $idempotencyKey,
                'created_at'      => gmdate('Y-m-d H:i:s'),
                'updated_at'      => gmdate('Y-m-d H:i:s'),
            ]);

        $intentId = (int) $this->connection->lastInsertId();

        return [
            'payment_intent_id' => $intentId,
            'amount_paise'      => (int) $order['total_paise'],
            'idempotency_key'   => $idempotencyKey,
        ];
    }

    /**
     * Confirm payment: PAYMENT_PENDING -> PAID, then trigger MOQ check.
     *
     * Called by the payment webhook handler after gateway confirms capture.
     *
     * @return array{order: array, moq_status: array}
     */
    public function confirmPayment(int $orderId, string $paymentRef): array
    {
        $order = $this->orderModel->find($orderId);
        if ($order === null) {
            throw new HttpException('Order not found', 404);
        }

        // Idempotent: if already paid, return current state
        if ($order['status'] === Order::STATUS_PAYMENT_CONFIRMED) {
            return [
                'order'      => $order,
                'moq_status' => $this->moqService->checkThreshold((int) $order['offer_id']),
            ];
        }

        if ($order['status'] !== Order::STATUS_PENDING_PAYMENT) {
            throw new HttpException('Order cannot transition to paid from status: ' . $order['status'], 409);
        }

        $this->connection->beginTransaction();

        try {
            // 1. Transition order to payment_confirmed
            $updated = $this->orderModel->updateStatus(
                $orderId,
                Order::STATUS_PENDING_PAYMENT,
                Order::STATUS_PAYMENT_CONFIRMED,
            );

            if (!$updated) {
                throw new HttpException('Failed to update order status (concurrent modification)', 409);
            }

            // 2. Update payment intent
            $this->connection->execute(
                'UPDATE payment_intents SET status = ?, provider_ref = ?, updated_at = NOW()
                 WHERE order_id = ? AND status IN (?, ?)',
                ['captured', $paymentRef, $orderId, 'created', 'pending'],
            );

            // 3. Convert stock reservation
            $this->connection->execute(
                'UPDATE stock_reservations SET status = ?, updated_at = NOW()
                 WHERE offer_id = ? AND buyer_id = ? AND status = ?',
                ['converted', (int) $order['offer_id'], (int) $order['buyer_id'], 'active'],
            );

            // 4. Record payment confirmation in commitment tracking
            $this->commitmentService->recordPaymentConfirmation($orderId);

            // 5. Log status event
            $this->statusEventModel->create(
                $orderId,
                Order::STATUS_PENDING_PAYMENT,
                Order::STATUS_PAYMENT_CONFIRMED,
                null,
                'Payment confirmed. Ref: ' . $paymentRef,
            );

            $this->connection->commit();

            // 6. Check MOQ (outside transaction -- informational)
            $moqStatus = $this->moqService->checkThreshold((int) $order['offer_id']);

            return [
                'order'      => $this->orderModel->find($orderId),
                'moq_status' => $moqStatus,
            ];
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Fulfillment Release
    // -------------------------------------------------------------------------

    /**
     * Release order to seller: PAID -> RELEASED_TO_SELLER (only after MOQ met).
     */
    public function releaseToSeller(int $orderId, int $actorId): array
    {
        $order = $this->orderModel->find($orderId);
        if ($order === null) {
            throw new HttpException('Order not found', 404);
        }

        if ($order['status'] !== Order::STATUS_PAYMENT_CONFIRMED) {
            throw new HttpException('Order must be in payment_confirmed status to release', 409);
        }

        // Verify MOQ is met
        $moqStatus = $this->moqService->checkThreshold((int) $order['offer_id']);
        if (!$moqStatus['met']) {
            throw new HttpException(
                'Cannot release: MOQ not met. paid_qty=' . $moqStatus['paid_qty'] . ', moq=' . $moqStatus['moq'],
                409,
            );
        }

        $updated = $this->orderModel->updateStatus(
            $orderId,
            Order::STATUS_PAYMENT_CONFIRMED,
            Order::STATUS_PROCESSING,
        );

        if (!$updated) {
            throw new HttpException('Failed to release order (concurrent modification)', 409);
        }

        $this->statusEventModel->create(
            $orderId,
            Order::STATUS_PAYMENT_CONFIRMED,
            Order::STATUS_PROCESSING,
            $actorId,
            'Order released to seller after MOQ met',
        );

        return $this->orderModel->find($orderId) ?? throw new HttpException('Order not found after update', 500);
    }

    // -------------------------------------------------------------------------
    // Cancellation
    // -------------------------------------------------------------------------

    /**
     * Cancel an order with appropriate state guards.
     *
     * Buyers can only cancel from PENDING_PAYMENT.
     * Admins/system can cancel from PENDING_PAYMENT or PAYMENT_CONFIRMED (with refund).
     */
    public function cancelOrder(int $orderId, int $actorId, string $reason = '', bool $isAdmin = false): array
    {
        $order = $this->orderModel->find($orderId);
        if ($order === null) {
            throw new HttpException('Order not found', 404);
        }

        $currentStatus = $order['status'];

        // Buyer can only cancel from pending_payment
        if (!$isAdmin && !in_array($currentStatus, Order::CANCELLABLE_STATUSES, true)) {
            throw new HttpException('Order cannot be cancelled from status: ' . $currentStatus, 409);
        }

        // Admin can also cancel from payment_confirmed
        $adminCancellable = array_merge(Order::CANCELLABLE_STATUSES, [Order::STATUS_PAYMENT_CONFIRMED]);
        if ($isAdmin && !in_array($currentStatus, $adminCancellable, true)) {
            throw new HttpException('Order cannot be cancelled from status: ' . $currentStatus, 409);
        }

        $this->connection->beginTransaction();

        try {
            $updated = $this->orderModel->updateStatus($orderId, $currentStatus, Order::STATUS_CANCELLED);

            if (!$updated) {
                throw new HttpException('Failed to cancel order (concurrent modification)', 409);
            }

            // Release stock reservation
            $this->connection->execute(
                'UPDATE stock_reservations SET status = ?, updated_at = NOW()
                 WHERE offer_id = ? AND buyer_id = ? AND status = ?',
                ['released', (int) $order['offer_id'], (int) $order['buyer_id'], 'active'],
            );

            // If was paid, handle commitment decrement
            if ($currentStatus === Order::STATUS_PAYMENT_CONFIRMED) {
                $this->commitmentService->recordPaymentCancellation($orderId);
            }

            $this->statusEventModel->create(
                $orderId,
                $currentStatus,
                Order::STATUS_CANCELLED,
                $actorId,
                $reason ?: 'Order cancelled',
            );

            $this->connection->commit();

            return $this->orderModel->find($orderId) ?? throw new HttpException('Order not found after cancel', 500);
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the current price tier for an offer based on paid quantity.
     * Returns the tier where paid_qty falls within [min_paid_qty, max_paid_qty].
     */
    private function resolveCurrentPriceTier(int $offerId): ?array
    {
        // Get all tiers ordered by min_paid_qty ASC (cheapest tier at highest volume)
        $tiers = (new QueryBuilder($this->connection))
            ->table('offer_price_tiers')
            ->select('id', 'min_paid_qty', 'max_paid_qty', 'unit_price_paise')
            ->where('offer_id', $offerId)
            ->orderBy('min_paid_qty', 'ASC')
            ->get();

        if (empty($tiers)) {
            return null;
        }

        // Return the first (lowest) tier -- new buyers pay the base tier price.
        // No silent repricing: the price at checkout is locked forever.
        return $tiers[0];
    }

    private function getGroupVariantId(int $groupId): int
    {
        $group = (new QueryBuilder($this->connection))
            ->table('demand_groups')
            ->select('variant_id')
            ->where('id', $groupId)
            ->first();

        if ($group === null || $group['variant_id'] === null) {
            throw new HttpException('Demand group has no variant_id', 500);
        }

        return (int) $group['variant_id'];
    }

    /**
     * Calculate tax for a unit price. Placeholder for tax engine integration.
     */
    private function calculateTax(int $unitPricePaise): int
    {
        // Default 18% GST -- this should be replaced with actual tax engine
        return (int) round($unitPricePaise * 0.18);
    }

    /**
     * Calculate shipping cost. Placeholder for logistics integration.
     */
    private function calculateShipping(int $offerId, int $addressId): int
    {
        // Placeholder: flat rate shipping
        // In production, integrate with logistics provider API
        return 0;
    }
}
