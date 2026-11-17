<?php

declare(strict_types=1);

namespace App\Modules\Disputes\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * DisputeEvidence model — APPEND-ONLY.
 *
 * Table: dispute_evidence (Module 7, §7.3)
 *
 *   id             BIGINT UNSIGNED AUTO_INCREMENT PK
 *   dispute_id     BIGINT UNSIGNED NOT NULL
 *   submitted_by   BIGINT UNSIGNED NOT NULL  — user who uploaded
 *   evidence_type  ENUM('PHOTO','VIDEO','DOCUMENT','SCREENSHOT','INVOICE','CORRESPONDENCE') NOT NULL
 *   object_ref     VARCHAR(500) NOT NULL      — object-storage path/key
 *   description    VARCHAR(1000) NULL
 *   created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
 *
 * INVARIANT: Rows are NEVER updated or deleted (spec §9 business rules:
 * "All evidence stored immutably in dispute_evidence. Cannot be deleted by either party.")
 */
class DisputeEvidence
{
    private const TABLE = 'dispute_evidence';

    // -------------------------------------------------------------------------
    // Evidence type constants
    // -------------------------------------------------------------------------

    public const TYPE_PHOTO          = 'PHOTO';
    public const TYPE_VIDEO          = 'VIDEO';
    public const TYPE_DOCUMENT       = 'DOCUMENT';
    public const TYPE_SCREENSHOT     = 'SCREENSHOT';
    public const TYPE_INVOICE        = 'INVOICE';
    public const TYPE_CORRESPONDENCE = 'CORRESPONDENCE';

    public const ALL_TYPES = [
        self::TYPE_PHOTO,
        self::TYPE_VIDEO,
        self::TYPE_DOCUMENT,
        self::TYPE_SCREENSHOT,
        self::TYPE_INVOICE,
        self::TYPE_CORRESPONDENCE,
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * All evidence for a dispute, chronologically ascending.
     *
     * @return array<array>
     */
    public function listByDispute(int $disputeId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select('id', 'dispute_id', 'submitted_by', 'evidence_type', 'object_ref', 'description', 'created_at')
            ->where('dispute_id', $disputeId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Mutations — append-only
    // -------------------------------------------------------------------------

    /**
     * Store a new piece of evidence. Returns the new row ID.
     *
     * @param array{
     *   dispute_id: int,
     *   submitted_by: int,
     *   evidence_type: string,
     *   object_ref: string,
     *   description?: string|null,
     * } $data
     */
    public function append(array $data): int
    {
        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'dispute_id'    => $data['dispute_id'],
                'submitted_by'  => $data['submitted_by'],
                'evidence_type' => $data['evidence_type'],
                'object_ref'    => $data['object_ref'],
                'description'   => $data['description'] ?? null,
                'created_at'    => gmdate('Y-m-d H:i:s'),
            ]);

        return (int) $this->connection->lastInsertId();
    }
}
