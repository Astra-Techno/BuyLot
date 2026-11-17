<?php

declare(strict_types=1);

namespace App\Modules\Quality\Services;

use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Modules\Quality\Models\Inspection;
use App\Modules\Quality\Models\InspectionFinding;

/**
 * InspectionService — quality inspection lifecycle.
 *
 * Tier auto-determination logic (determineTier):
 *   Tier C (independent inspection):
 *     - Category is in a high-risk set (electronics, luxury, pharmaceuticals)
 *     - AND order value >= HIGH_VALUE_THRESHOLD_PAISE
 *     - OR seller reliability_score < LOW_RELIABILITY_SCORE
 *
 *   Tier B (pre-dispatch photo/video):
 *     - Category is in a medium-risk set
 *     - OR order value >= MID_VALUE_THRESHOLD_PAISE
 *
 *   Tier A (default — invoice + delivery proof):
 *     - Everything else
 *     - Do NOT advertise Tier A as "physically inspected".
 *
 * Settlement trigger:
 *   After completeInspection() with a passing result, this service emits
 *   a settlement-eligibility signal by updating the order status events table.
 *   The actual settlement approval workflow lives in the Finance module.
 */
class InspectionService
{
    // Thresholds (paise; 1 INR = 100 paise)
    private const HIGH_VALUE_THRESHOLD_PAISE = 500_000_00; // 5,00,000 INR
    private const MID_VALUE_THRESHOLD_PAISE  =  50_000_00; //   50,000 INR
    private const LOW_RELIABILITY_SCORE      = 50.0;

    /** Category slugs / IDs that trigger elevated inspection tiers */
    private const HIGH_RISK_CATEGORIES = ['electronics', 'luxury', 'pharmaceuticals'];
    private const MID_RISK_CATEGORIES  = ['appliances', 'fashion', 'furniture'];

    public function __construct(
        private Connection         $connection,
        private Inspection         $inspectionModel,
        private InspectionFinding  $findingModel,
    ) {}

    // -------------------------------------------------------------------------
    // Creation
    // -------------------------------------------------------------------------

    /**
     * Create an inspection for the given order/shipment at the specified tier.
     *
     * Snapshots the current checklist version into checklist_snapshot_json.
     *
     * @return array  Created inspection row
     */
    public function createInspection(int $orderId, ?int $shipmentId, int $tier): array
    {
        if (!in_array($tier, [Inspection::TIER_A, Inspection::TIER_B, Inspection::TIER_C], true)) {
            throw new HttpException("Invalid inspection tier: {$tier}", 422);
        }

        $checklistSnapshot = $this->buildChecklistSnapshot($tier);

        $id = $this->inspectionModel->create($orderId, $shipmentId, $tier, $checklistSnapshot);

        return $this->inspectionModel->find($id);
    }

    /**
     * Auto-determine the appropriate inspection tier for an order.
     *
     * Reads order value, category, and seller history to decide tier.
     * Returns one of Inspection::TIER_A | TIER_B | TIER_C.
     */
    public function determineTier(int $orderId): int
    {
        $order = $this->fetchOrderMeta($orderId);

        if ($order === null) {
            // Cannot determine — default to Tier A
            return Inspection::TIER_A;
        }

        $totalPaise      = (int) ($order['total_paise'] ?? 0);
        $categorySlug    = (string) ($order['category_slug'] ?? '');
        $reliabilityScore = isset($order['reliability_score'])
            ? (float) $order['reliability_score']
            : 100.0;

        // Tier C: high-risk category + (high value OR unreliable seller)
        if (
            in_array($categorySlug, self::HIGH_RISK_CATEGORIES, true)
            && (
                $totalPaise >= self::HIGH_VALUE_THRESHOLD_PAISE
                || $reliabilityScore < self::LOW_RELIABILITY_SCORE
            )
        ) {
            return Inspection::TIER_C;
        }

        // Tier B: medium-risk category OR mid-value order
        if (
            in_array($categorySlug, self::MID_RISK_CATEGORIES, true)
            || $totalPaise >= self::MID_VALUE_THRESHOLD_PAISE
        ) {
            return Inspection::TIER_B;
        }

        // Default: Tier A
        return Inspection::TIER_A;
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    /**
     * Assign an inspector (ops user) to an inspection.
     *
     * @throws HttpException if inspection not found
     */
    public function assignInspector(int $inspectionId, int $inspectorId): array
    {
        $inspection = $this->inspectionModel->find($inspectionId);
        if ($inspection === null) {
            throw new HttpException('Inspection not found', 404);
        }

        if ($inspection['status'] !== Inspection::STATUS_PENDING) {
            throw new HttpException(
                "Cannot assign inspector to inspection in status '{$inspection['status']}'",
                409,
            );
        }

        $this->inspectionModel->assign($inspectionId, $inspectorId);

        return $this->inspectionModel->find($inspectionId);
    }

    /**
     * Start an inspection (transition pending -> in_progress).
     *
     * @throws HttpException if inspection not found or not assignable
     */
    public function startInspection(int $inspectionId): array
    {
        $inspection = $this->inspectionModel->find($inspectionId);
        if ($inspection === null) {
            throw new HttpException('Inspection not found', 404);
        }

        try {
            $this->inspectionModel->start($inspectionId);
        } catch (\LogicException $e) {
            throw new HttpException($e->getMessage(), 409);
        }

        return $this->inspectionModel->find($inspectionId);
    }

    /**
     * Add a finding to an in-progress inspection.
     *
     * @throws HttpException if inspection not in progress
     */
    public function addFinding(
        int     $inspectionId,
        int     $orderItemId,
        string  $findingType,
        int     $qtyAffected,
        ?string $evidenceRef,
        ?string $notes,
    ): array {
        $inspection = $this->inspectionModel->find($inspectionId);
        if ($inspection === null) {
            throw new HttpException('Inspection not found', 404);
        }

        if ($inspection['status'] !== Inspection::STATUS_IN_PROGRESS) {
            throw new HttpException(
                "Findings can only be added to in_progress inspections (current: '{$inspection['status']}')",
                409,
            );
        }

        try {
            $findingId = $this->findingModel->create(
                $inspectionId,
                $orderItemId,
                $findingType,
                $qtyAffected,
                $evidenceRef,
                $notes,
            );
        } catch (\InvalidArgumentException $e) {
            throw new HttpException($e->getMessage(), 422);
        }

        return $this->findingModel->find($findingId);
    }

    /**
     * Complete an inspection with a result.
     *
     * If the result is a pass variant, signals settlement eligibility by
     * inserting an order_status_events row (delivery_verified event).
     *
     * @throws HttpException on invalid state or unknown result
     */
    public function completeInspection(int $inspectionId, string $result): array
    {
        $inspection = $this->inspectionModel->find($inspectionId);
        if ($inspection === null) {
            throw new HttpException('Inspection not found', 404);
        }

        if ($inspection['status'] !== Inspection::STATUS_IN_PROGRESS) {
            throw new HttpException(
                "Cannot complete inspection in status '{$inspection['status']}'",
                409,
            );
        }

        try {
            $this->inspectionModel->complete($inspectionId, $result);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException($e->getMessage(), 422);
        } catch (\LogicException $e) {
            throw new HttpException($e->getMessage(), 409);
        }

        // Trigger settlement eligibility signal for passing inspections
        $passingResults = [Inspection::RESULT_PASS, Inspection::RESULT_PASS_WITH_REMARKS];
        if (in_array($result, $passingResults, true)) {
            $this->signalSettlementEligibility((int) $inspection['order_id']);
        }

        $completed             = $this->inspectionModel->find($inspectionId);
        $completed['findings'] = $this->findingModel->listByInspection($inspectionId);

        return $completed;
    }

    // -------------------------------------------------------------------------
    // Read helpers
    // -------------------------------------------------------------------------

    /**
     * Inspection detail enriched with findings.
     *
     * @throws HttpException if not found
     */
    public function getInspectionDetail(int $inspectionId): array
    {
        $inspection = $this->inspectionModel->find($inspectionId);
        if ($inspection === null) {
            throw new HttpException('Inspection not found', 404);
        }

        $inspection['findings'] = $this->findingModel->listByInspection($inspectionId);

        return $inspection;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch order metadata needed for tier determination.
     * Joins orders -> order_items -> product_variants -> products -> categories
     * and seller_profiles for reliability_score.
     */
    private function fetchOrderMeta(int $orderId): ?array
    {
        $rows = $this->connection->query(
            'SELECT
                o.total_paise,
                c.slug AS category_slug,
                sp.reliability_score
             FROM orders o
             JOIN order_items oi ON oi.order_id = o.id
             JOIN product_variants pv ON pv.id = oi.variant_id
             JOIN products p ON p.id = pv.product_id
             JOIN categories c ON c.id = p.category_id
             JOIN negotiated_offers no2 ON no2.id = o.offer_id
             JOIN seller_profiles sp ON sp.id = no2.seller_id
             WHERE o.id = ?
             LIMIT 1',
            [$orderId],
        );

        return $rows[0] ?? null;
    }

    /**
     * Build a checklist snapshot for the given tier.
     * In production this would load versioned checklist definitions from config/DB.
     *
     * @return array<string, mixed>
     */
    private function buildChecklistSnapshot(int $tier): array
    {
        $baseChecklist = [
            'version'     => '1.0',
            'tier'        => $tier,
            'captured_at' => gmdate('Y-m-d H:i:s'),
        ];

        // Tier A: invoice and delivery proof only.
        // Do NOT label this as a physical inspection.
        if ($tier === Inspection::TIER_A) {
            return array_merge($baseChecklist, [
                'type'  => 'document_review',
                'items' => ['invoice_present', 'delivery_proof_present'],
            ]);
        }

        // Tier B: photo/video pre-dispatch verification
        if ($tier === Inspection::TIER_B) {
            return array_merge($baseChecklist, [
                'type'  => 'photo_video_review',
                'items' => [
                    'pre_dispatch_photo_present',
                    'product_matches_description',
                    'packaging_intact',
                    'quantity_verified',
                ],
            ]);
        }

        // Tier C: independent physical inspection
        return array_merge($baseChecklist, [
            'type'  => 'physical_inspection',
            'items' => [
                'product_matches_description',
                'serial_number_verified',
                'packaging_intact',
                'quantity_verified',
                'no_visible_damage',
                'authenticity_confirmed',
                'accessories_present',
            ],
        ]);
    }

    /**
     * Signal that an order is eligible for settlement by appending an
     * order_status_events row.  This is a lightweight coordination hook;
     * the full settlement workflow is owned by the Finance module.
     */
    private function signalSettlementEligibility(int $orderId): void
    {
        $this->connection->execute(
            'INSERT INTO order_status_events (order_id, from_state, to_state, reason, created_at)
             VALUES (?, ?, ?, ?, ?)',
            [
                $orderId,
                'delivered',
                'delivery_verified',
                'Inspection passed — eligible for settlement',
                gmdate('Y-m-d H:i:s'),
            ],
        );
    }
}
