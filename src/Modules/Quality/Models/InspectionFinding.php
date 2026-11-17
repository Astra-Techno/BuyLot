<?php

declare(strict_types=1);

namespace App\Modules\Quality\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * InspectionFinding model.
 *
 * Maps the `inspection_findings` table (schema 6.5).
 *
 * Finding types (stored as VARCHAR in DB):
 *   PASS | MINOR_ISSUE | MAJOR_DEFECT | COUNTERFEIT | MISSING
 *
 * Rows are effectively append-only after recording — inspectors add findings
 * during an in_progress inspection; they cannot be modified once the inspection
 * is completed.  Enforcement is at the service layer.
 */
class InspectionFinding
{
    private const TABLE = 'inspection_findings';

    public const TYPE_PASS         = 'PASS';
    public const TYPE_MINOR_ISSUE  = 'MINOR_ISSUE';
    public const TYPE_MAJOR_DEFECT = 'MAJOR_DEFECT';
    public const TYPE_COUNTERFEIT  = 'COUNTERFEIT';
    public const TYPE_MISSING      = 'MISSING';

    public const VALID_TYPES = [
        self::TYPE_PASS,
        self::TYPE_MINOR_ISSUE,
        self::TYPE_MAJOR_DEFECT,
        self::TYPE_COUNTERFEIT,
        self::TYPE_MISSING,
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->first();
    }

    /**
     * All findings for a given inspection.
     *
     * @return array<int, array>
     */
    public function listByInspection(int $inspectionId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('inspection_id', $inspectionId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * Whether any blocking findings (MAJOR_DEFECT or COUNTERFEIT) exist.
     */
    public function hasBlockingFindings(int $inspectionId): bool
    {
        $row = $this->connection->query(
            'SELECT id FROM inspection_findings
             WHERE inspection_id = ?
               AND finding_type IN (?, ?)
             LIMIT 1',
            [$inspectionId, self::TYPE_MAJOR_DEFECT, self::TYPE_COUNTERFEIT],
        );

        return !empty($row);
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Record a finding during an active inspection.
     *
     * @return int  New finding id
     * @throws \InvalidArgumentException on unknown finding_type
     */
    public function create(
        int     $inspectionId,
        int     $orderItemId,
        string  $findingType,
        int     $qtyAffected = 1,
        ?string $evidenceRef = null,
        ?string $notes       = null,
    ): int {
        if (!in_array($findingType, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException(
                "Unknown finding type: {$findingType}. Allowed: " . implode(', ', self::VALID_TYPES),
            );
        }

        if ($qtyAffected < 1) {
            throw new \InvalidArgumentException('qty_affected must be >= 1');
        }

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'inspection_id' => $inspectionId,
                'order_item_id' => $orderItemId,
                'finding_type'  => $findingType,
                'qty_affected'  => $qtyAffected,
                'description'   => $notes,
                'evidence_ref'  => $evidenceRef,
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }
}
