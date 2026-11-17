<?php

declare(strict_types=1);

namespace App\Modules\Fulfillment\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * DeliveryEvidence model.
 *
 * Maps the `delivery_evidence` table (schema 6.3).
 *
 * APPEND-ONLY: rows are never updated or deleted.
 * Evidence types mirror the platform's evidence taxonomy:
 *   POD | PHOTO | OTP_VERIFICATION | SIGNATURE
 *
 * The DB column `evidence_type` is VARCHAR(50) so these string constants
 * are stored as-is.
 */
class DeliveryEvidence
{
    private const TABLE = 'delivery_evidence';

    public const TYPE_POD              = 'POD';
    public const TYPE_PHOTO            = 'PHOTO';
    public const TYPE_OTP_VERIFICATION = 'OTP_VERIFICATION';
    public const TYPE_SIGNATURE        = 'SIGNATURE';

    public const VALID_TYPES = [
        self::TYPE_POD,
        self::TYPE_PHOTO,
        self::TYPE_OTP_VERIFICATION,
        self::TYPE_SIGNATURE,
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * All evidence records for a shipment, oldest first.
     *
     * @return array<int, array>
     */
    public function listByShipment(int $shipmentId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('shipment_id', $shipmentId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * Find a specific evidence row.
     */
    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->first();
    }

    /**
     * Whether any verified evidence of a given type exists for a shipment.
     */
    public function hasVerified(int $shipmentId, string $type): bool
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('shipment_id', $shipmentId)
            ->where('evidence_type', $type)
            ->whereNotNull('verified_at')
            ->first();

        return $row !== null;
    }

    // -------------------------------------------------------------------------
    // Mutations  (APPEND-ONLY — no update/delete methods)
    // -------------------------------------------------------------------------

    /**
     * Append a new evidence record.
     *
     * @return int  New row id
     * @throws \InvalidArgumentException on unknown evidence_type
     */
    public function append(
        int     $shipmentId,
        string  $evidenceType,
        string  $objectRef,
        ?int    $verifiedBy = null,
    ): int {
        if (!in_array($evidenceType, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unknown evidence type: {$evidenceType}. Allowed: " . implode(', ', self::VALID_TYPES),
            );
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'shipment_id'   => $shipmentId,
                'evidence_type' => $evidenceType,
                'object_ref'    => $objectRef,
                'verified_at'   => $verifiedBy !== null ? $now : null,
                'verified_by'   => $verifiedBy,
                'created_at'    => $now,
                // No updated_at — append-only table has no update lifecycle
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Mark an evidence row as verified by an ops agent.
     * This is the ONLY mutation allowed after initial insert.
     */
    public function markVerified(int $id, int $verifiedByUserId): bool
    {
        // Only update if not already verified (idempotent)
        $rows = $this->connection->execute(
            'UPDATE delivery_evidence
             SET verified_at = ?, verified_by = ?
             WHERE id = ? AND verified_at IS NULL',
            [gmdate('Y-m-d H:i:s'), $verifiedByUserId, $id],
        );

        return $rows > 0;
    }
}
