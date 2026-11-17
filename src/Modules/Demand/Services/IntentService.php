<?php

declare(strict_types=1);

namespace App\Modules\Demand\Services;

use App\Modules\Demand\Models\GroupIntentEvent;
use App\Modules\Demand\Models\GroupMembership;

/**
 * Buyer commitment ladder (spec 4.2).
 *
 * Manages intent level transitions for group memberships:
 *   L0 (browse) -> L1 (interested) -> L2 (purchase-ready)
 *   -> L3 (offer accepted) -> L4 (payment confirmed) -> L5 (order released)
 *
 * Each transition:
 *  - Validates the transition is legal (sequential, no skipping).
 *  - Updates the membership record (optimistic lock).
 *  - Creates an immutable GroupIntentEvent audit record.
 */
class IntentService
{
    public function __construct(
        private GroupMembership  $membershipModel,
        private GroupIntentEvent $intentEventModel,
    ) {}

    // -------------------------------------------------------------------------
    // Public transition methods
    // -------------------------------------------------------------------------

    /**
     * L0 -> L1: Express interest in a group.
     *
     * Note: L1 membership is typically created by DemandMatchingService::matchRequest()
     * or BuyRequestController::joinGroup(). This method is for explicit interest
     * expression when the membership already exists at L0 equivalent (should not
     * normally occur since join() creates at L1). Provided for completeness.
     *
     * In practice the join itself is the L0->L1 transition. This method handles
     * cases where a membership needs to be explicitly bumped.
     *
     * @param int $buyerId  The buyer performing the action.
     * @param int $groupId  The demand group.
     * @param int $qty      Quantity the buyer is interested in.
     * @return array The updated membership.
     */
    public function expressInterest(int $buyerId, int $groupId, int $qty): array
    {
        $membership = $this->resolveMembership($buyerId, $groupId);

        // If already at L1 or above, this is a no-op (idempotent)
        if ((int) $membership['intent_level'] >= GroupMembership::LEVEL_INTERESTED) {
            return $membership;
        }

        return $this->transitionLevel(
            $membership,
            GroupMembership::LEVEL_INTERESTED,
            $buyerId,
            'express_interest',
        );
    }

    /**
     * L1 -> L2: Reconfirm purchase readiness.
     *
     * Buyer explicitly confirms they intend to purchase when a price is available.
     * Still non-binding. Used to qualify demand groups for RFQ.
     *
     * @param int $buyerId The buyer performing the action.
     * @param int $groupId The demand group.
     * @return array The updated membership.
     */
    public function reconfirmReady(int $buyerId, int $groupId): array
    {
        $membership = $this->resolveMembership($buyerId, $groupId);

        $this->assertCurrentLevel($membership, GroupMembership::LEVEL_INTERESTED, 'reconfirmReady');

        return $this->transitionLevel(
            $membership,
            GroupMembership::LEVEL_PURCHASE_READY,
            $buyerId,
            'reconfirm_ready',
        );
    }

    /**
     * L2 -> L3: Accept a specific offer.
     *
     * Buyer agrees to exact price, terms, warranty, delivery details.
     * Checkout may be initiated but payment is not yet captured.
     *
     * @param int    $buyerId The buyer performing the action.
     * @param int    $groupId The demand group.
     * @param string $offerId Reference to the accepted offer (for audit trail).
     * @return array The updated membership.
     */
    public function acceptOffer(int $buyerId, int $groupId, string $offerId): array
    {
        $membership = $this->resolveMembership($buyerId, $groupId);

        $this->assertCurrentLevel($membership, GroupMembership::LEVEL_PURCHASE_READY, 'acceptOffer');

        return $this->transitionLevel(
            $membership,
            GroupMembership::LEVEL_OFFER_ACCEPTED,
            $buyerId,
            'accept_offer:' . $offerId,
        );
    }

    /**
     * L3 -> L4: Confirm payment.
     *
     * Gateway has confirmed payment capture. This is the ONLY level that
     * counts toward MOQ (spec 6.1). Funds are with the payment provider.
     *
     * @param int    $buyerId   The buyer performing the action.
     * @param int    $groupId   The demand group.
     * @param string $paymentId Reference to the confirmed payment (for audit trail).
     * @return array The updated membership.
     */
    public function confirmPayment(int $buyerId, int $groupId, string $paymentId): array
    {
        $membership = $this->resolveMembership($buyerId, $groupId);

        $this->assertCurrentLevel($membership, GroupMembership::LEVEL_OFFER_ACCEPTED, 'confirmPayment');

        return $this->transitionLevel(
            $membership,
            GroupMembership::LEVEL_PAYMENT_CONFIRMED,
            $buyerId,
            'confirm_payment:' . $paymentId,
        );
    }

    /**
     * L4 -> L5: Release order to seller for fulfillment.
     *
     * Irrevocable commitment. Seller receives fulfillment instruction.
     * Cancellation after this point requires the refund workflow.
     *
     * @param int $buyerId The buyer performing the action.
     * @param int $groupId The demand group.
     * @return array The updated membership.
     */
    public function releaseOrder(int $buyerId, int $groupId): array
    {
        $membership = $this->resolveMembership($buyerId, $groupId);

        $this->assertCurrentLevel($membership, GroupMembership::LEVEL_PAYMENT_CONFIRMED, 'releaseOrder');

        return $this->transitionLevel(
            $membership,
            GroupMembership::LEVEL_ORDER_RELEASED,
            $buyerId,
            'release_order',
        );
    }

    // -------------------------------------------------------------------------
    // Query helpers
    // -------------------------------------------------------------------------

    /**
     * Get the current intent level for a buyer in a group.
     *
     * @return int|null The intent level (1-5) or null if not a member.
     */
    public function getCurrentLevel(int $buyerId, int $groupId): ?int
    {
        $membership = $this->membershipModel->findByGroupAndBuyer($groupId, $buyerId);

        if ($membership === null) {
            return null;
        }

        return (int) $membership['intent_level'];
    }

    /**
     * Get the full intent event history for a buyer's membership in a group.
     */
    public function getIntentHistory(int $buyerId, int $groupId): array
    {
        $membership = $this->membershipModel->findByGroupAndBuyer($groupId, $buyerId);

        if ($membership === null) {
            return [];
        }

        return $this->intentEventModel->listByMembership((int) $membership['id']);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Resolve the membership record for a buyer + group, or throw.
     */
    private function resolveMembership(int $buyerId, int $groupId): array
    {
        $membership = $this->membershipModel->findByGroupAndBuyer($groupId, $buyerId);

        if ($membership === null) {
            throw new \InvalidArgumentException(
                "Buyer {$buyerId} is not a member of group {$groupId}"
            );
        }

        return $membership;
    }

    /**
     * Assert the membership is at the expected level before transition.
     */
    private function assertCurrentLevel(array $membership, int $expectedLevel, string $action): void
    {
        $current = (int) $membership['intent_level'];

        if ($current !== $expectedLevel) {
            throw new \InvalidArgumentException(
                "Cannot {$action}: membership {$membership['id']} is at L{$current}, expected L{$expectedLevel}"
            );
        }
    }

    /**
     * Execute the intent level transition and record the event.
     *
     * @return array The refreshed membership record.
     */
    private function transitionLevel(
        array  $membership,
        int    $newLevel,
        int    $actorId,
        string $reason,
    ): array {
        $fromLevel    = (int) $membership['intent_level'];
        $membershipId = (int) $membership['id'];

        // Update the membership (includes optimistic lock validation)
        $updated = $this->membershipModel->updateIntent($membershipId, $newLevel);

        if (!$updated) {
            throw new \RuntimeException(
                "Concurrent modification: membership {$membershipId} was modified by another process"
            );
        }

        // Record immutable audit event
        $this->intentEventModel->record([
            'membership_id' => $membershipId,
            'from_level'    => $fromLevel,
            'to_level'      => $newLevel,
            'reason'        => $reason,
            'actor_id'      => $actorId,
        ]);

        // Return the refreshed membership
        return $this->membershipModel->find($membershipId) ?? $membership;
    }
}
