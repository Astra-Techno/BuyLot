<?php

declare(strict_types=1);

namespace App\Modules\Demand\Services;

use App\Core\Database\Connection;
use App\Modules\Demand\Models\BuyRequest;
use App\Modules\Demand\Models\DemandGroup;
use App\Modules\Demand\Models\GroupMembership;

/**
 * Core demand-matching algorithm.
 *
 * V1: exact SKU match only (spec 6.4).
 * match_key = sha256(variant_id | region_id | purchase_window_bucket).
 *
 * Uses DB transaction + SELECT FOR UPDATE to prevent race conditions
 * when two buyers submit near-simultaneously for the same match_key.
 */
class DemandMatchingService
{
    public function __construct(
        private Connection      $connection,
        private BuyRequest      $buyRequestModel,
        private DemandGroup     $demandGroupModel,
        private GroupMembership $membershipModel,
    ) {}

    /**
     * Match a submitted buy request to an existing or newly created DemandGroup.
     *
     * Flow:
     *  1. Compute the deterministic match_key from the request attributes.
     *  2. Inside a transaction with SELECT FOR UPDATE, look for a forming group.
     *  3. If found, join the buyer to that group.
     *  4. If not found, create a new group and join the buyer.
     *  5. Transition the buy request from submitted -> grouping.
     *
     * @param array $buyRequest A buy request row (must be in STATUS_SUBMITTED).
     * @return array{group: array, membership: array}
     *
     * @throws \InvalidArgumentException If the request is not in a matchable state.
     * @throws \RuntimeException         On transactional failure.
     */
    public function matchRequest(array $buyRequest): array
    {
        if ($buyRequest['status'] !== BuyRequest::STATUS_SUBMITTED) {
            throw new \InvalidArgumentException(
                "Buy request {$buyRequest['id']} must be in 'submitted' status to match (current: {$buyRequest['status']})"
            );
        }

        $variantId = (int) $buyRequest['variant_id'];
        $regionId  = (int) $buyRequest['region_id'];

        if ($variantId === 0) {
            throw new \InvalidArgumentException(
                "Buy request {$buyRequest['id']} has no variant_id; V1 requires exact SKU match (spec 6.4)"
            );
        }

        $purchaseWindowBucket = DemandGroup::purchaseWindowBucket($buyRequest['purchase_by'] ?? null);
        $matchKey = DemandGroup::generateMatchKey($variantId, $regionId, $purchaseWindowBucket);

        $this->connection->beginTransaction();

        try {
            // SELECT FOR UPDATE: lock any existing forming group with this match_key
            $group = $this->demandGroupModel->findByMatchKeyForUpdate($matchKey);

            if ($group === null) {
                // No existing group -- create one
                $group = $this->demandGroupModel->create([
                    'match_key'  => $matchKey,
                    'region_id'  => $regionId,
                    'variant_id' => $variantId,
                    'closes_at'  => $buyRequest['purchase_by'] ?? null,
                ]);
            }

            // Join the buyer to the group at L1 (interested)
            $membership = $this->membershipModel->join([
                'group_id'   => (int) $group['id'],
                'buyer_id'   => (int) $buyRequest['buyer_id'],
                'request_id' => (int) $buyRequest['id'],
                'qty'        => (int) $buyRequest['qty'],
            ]);

            // Transition the buy request: submitted -> grouping
            $this->buyRequestModel->updateStatus((int) $buyRequest['id'], BuyRequest::STATUS_GROUPING);

            $this->connection->commit();

            return [
                'group'      => $group,
                'membership' => $membership,
            ];
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw new \RuntimeException(
                "Failed to match buy request {$buyRequest['id']}: " . $e->getMessage(),
                0,
                $e,
            );
        }
    }

    /**
     * Compute the match_key for a given set of attributes without persisting anything.
     * Useful for preview / "which group would I join?" queries.
     */
    public function computeMatchKey(int $variantId, int $regionId, ?string $purchaseBy): string
    {
        $bucket = DemandGroup::purchaseWindowBucket($purchaseBy);
        return DemandGroup::generateMatchKey($variantId, $regionId, $bucket);
    }

    /**
     * Find the forming group for a given match_key (non-locking read).
     */
    public function findGroupForAttributes(int $variantId, int $regionId, ?string $purchaseBy): ?array
    {
        $matchKey = $this->computeMatchKey($variantId, $regionId, $purchaseBy);
        return $this->demandGroupModel->findByMatchKey($matchKey);
    }
}
