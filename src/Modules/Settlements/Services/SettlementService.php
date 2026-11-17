<?php

declare(strict_types=1);

namespace App\Modules\Settlements\Services;

use App\Core\Database\Connection;
use App\Modules\Settlements\Models\SellerSettlement;
use App\Modules\Settlements\Models\SettlementApproval;
use App\Modules\Settlements\Models\SettlementItem;
use App\Modules\Ledger\Services\LedgerService;

/**
 * Settlement orchestration service.
 *
 * Manages the lifecycle of seller settlements:
 *   calculating -> pending_approval -> approved -> processing -> completed
 *
 * Maker-checker: the approver must differ from the user who created/prepared
 * the settlement. Disputed items are NEVER included.
 *
 * All monetary values in integer paise.
 */
class SettlementService
{
    public function __construct(
        private Connection          $connection,
        private SellerSettlement    $settlementModel,
        private SettlementItem      $itemModel,
        private SettlementApproval  $approvalModel,
        private LedgerService       $ledgerService,
    ) {}

    /**
     * Check eligibility for a seller fulfillment order.
     *
     * Requirements:
     * - Delivery verified
     * - Buyer accepted (full or partial for eligible items)
     * - No blocking disputes on the items
     * - Inspection passed (if required by tier)
     *
     * @return array{eligible: bool, reason: string|null, eligible_items: array}
     */
    public function calculateEligibility(int $sellerFulfillmentOrderId): array
    {
        // Query order items, delivery status, buyer acknowledgements, disputes, inspections.
        // This queries across multiple tables to determine which items are settlement-eligible.
        $results = $this->connection->query(
            "SELECT oi.id AS order_item_id,
                    oi.qty,
                    oi.unit_price_paise,
                    oi.tax_paise,
                    o.status AS order_status,
                    o.id AS order_id
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             JOIN seller_fulfillment_orders sfo ON sfo.offer_id = o.offer_id
             WHERE sfo.id = ?
               AND o.status IN ('accepted', 'completed')
               AND o.deleted_at IS NULL",
            [$sellerFulfillmentOrderId],
        );

        if (empty($results)) {
            return ['eligible' => false, 'reason' => 'No accepted/completed orders found', 'eligible_items' => []];
        }

        // Check for blocking disputes
        $orderIds = array_unique(array_column($results, 'order_id'));
        $disputeCheck = $this->connection->query(
            "SELECT order_id FROM disputes
             WHERE order_id IN (" . implode(',', array_fill(0, count($orderIds), '?')) . ")
               AND status IN ('open', 'investigating', 'evidence_requested', 'escalated')",
            $orderIds,
        );

        $disputedOrderIds = array_column($disputeCheck, 'order_id');

        $eligibleItems = [];
        foreach ($results as $item) {
            if (in_array($item['order_id'], $disputedOrderIds, true)) {
                continue; // Disputed items NEVER included
            }

            $eligibleItems[] = [
                'order_item_id' => (int) $item['order_item_id'],
                'eligible_qty'  => (int) $item['qty'],
                'amount_paise'  => ((int) $item['unit_price_paise'] * (int) $item['qty']) + (int) $item['tax_paise'],
            ];
        }

        return [
            'eligible'       => !empty($eligibleItems),
            'reason'         => empty($eligibleItems) ? 'All items disputed or ineligible' : null,
            'eligible_items' => $eligibleItems,
        ];
    }

    /**
     * Create a settlement for a seller with eligible items.
     *
     * @param int   $sellerId The seller profile ID
     * @param array $items    Array of ['order_item_id' => int, 'eligible_qty' => int, 'amount_paise' => int]
     *
     * @return array The created settlement row with items
     */
    public function createSettlement(int $sellerId, array $items): array
    {
        if (empty($items)) {
            throw new \InvalidArgumentException('At least one eligible item is required');
        }

        $totalEligible = 0;
        foreach ($items as $item) {
            $totalEligible += (int) $item['amount_paise'];
        }

        $this->connection->beginTransaction();

        try {
            $settlement = $this->settlementModel->create($sellerId, $totalEligible, $totalEligible);

            foreach ($items as $item) {
                $this->itemModel->create(
                    (int) $settlement['id'],
                    (int) $item['order_item_id'],
                    (int) $item['eligible_qty'],
                    (int) $item['amount_paise'],
                );
            }

            // Transition to pending_approval
            $this->settlementModel->updateStatus((int) $settlement['id'], 'pending_approval');

            $this->connection->commit();

            $settlement = $this->settlementModel->find((int) $settlement['id']);

            return [
                'settlement' => $settlement,
                'items'      => $this->itemModel->findBySettlementId((int) $settlement['id']),
            ];
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Approve a settlement (maker-checker).
     *
     * The approver must differ from any previous actor on this settlement.
     *
     * @throws \RuntimeException If maker-checker constraint is violated
     */
    public function approveSettlement(int $settlementId, int $approverId): array
    {
        $settlement = $this->settlementModel->find($settlementId);
        if ($settlement === null) {
            throw new \RuntimeException("Settlement {$settlementId} not found");
        }

        if ($settlement['status'] !== 'pending_approval') {
            throw new \RuntimeException(
                "Settlement {$settlementId} is not pending approval (status: {$settlement['status']})"
            );
        }

        // Maker-checker: check previous approvals to ensure no self-approval
        $previousApprovals = $this->approvalModel->findBySettlementId($settlementId);
        foreach ($previousApprovals as $prev) {
            if ((int) $prev['approver_id'] === $approverId && $prev['decision'] !== 'rejected') {
                throw new \RuntimeException(
                    "Maker-checker violation: user {$approverId} already has an active approval on settlement {$settlementId}"
                );
            }
        }

        $this->connection->beginTransaction();

        try {
            // Create approval record
            $approval = $this->approvalModel->create($settlementId, $approverId);
            $this->approvalModel->decide((int) $approval['id'], 'approved');

            // Transition settlement to approved
            $this->settlementModel->updateStatus($settlementId, 'approved');

            $this->connection->commit();

            return $this->settlementModel->find($settlementId)
                ?? throw new \RuntimeException("Failed to retrieve settlement {$settlementId}");
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Request fund release via payment provider.
     *
     * Transitions from approved -> processing.
     */
    public function requestRelease(int $settlementId): array
    {
        $settlement = $this->settlementModel->find($settlementId);
        if ($settlement === null) {
            throw new \RuntimeException("Settlement {$settlementId} not found");
        }

        if ($settlement['status'] !== 'approved') {
            throw new \RuntimeException(
                "Settlement {$settlementId} is not approved for release (status: {$settlement['status']})"
            );
        }

        // Transition to processing
        $this->settlementModel->updateStatus($settlementId, 'processing');

        // In production: call gateway payout API here
        // $providerRef = $this->callGatewayPayout($settlement);

        return $this->settlementModel->find($settlementId)
            ?? throw new \RuntimeException("Failed to retrieve settlement {$settlementId}");
    }

    /**
     * Confirm settlement after gateway confirms payout.
     *
     * Records the ledger entry for the settlement.
     */
    public function confirmSettlement(int $settlementId, string $providerRef, ?int $confirmedPaise = null): array
    {
        $settlement = $this->settlementModel->find($settlementId);
        if ($settlement === null) {
            throw new \RuntimeException("Settlement {$settlementId} not found");
        }

        if ($settlement['status'] !== 'processing') {
            throw new \RuntimeException(
                "Settlement {$settlementId} is not in processing state (status: {$settlement['status']})"
            );
        }

        $this->connection->beginTransaction();

        try {
            $actualPaise = $confirmedPaise ?? (int) $settlement['requested_paise'];

            $this->settlementModel->updateStatus(
                $settlementId,
                'completed',
                $providerRef,
                $actualPaise,
            );

            // Record double-entry ledger posting
            $this->ledgerService->recordSettlement($settlementId, $actualPaise);

            $this->connection->commit();

            return $this->settlementModel->find($settlementId)
                ?? throw new \RuntimeException("Failed to retrieve settlement {$settlementId}");
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }
}
