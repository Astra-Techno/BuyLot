<?php

declare(strict_types=1);

namespace App\Modules\ProcurementCRM\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * SupplierContact model — APPEND-ONLY.
 *
 * Maps to the `supplier_contacts` table (schema §3.7).
 * Records are immutable once created; no UPDATE or DELETE is ever issued.
 *
 * Table columns:
 *   id               BIGINT UNSIGNED AUTO_INCREMENT PK
 *   supplier_lead_id BIGINT UNSIGNED NOT NULL  FK -> supplier_leads.id
 *   method           VARCHAR(50) NOT NULL       CALL|EMAIL|WHATSAPP|VISIT
 *   outcome          VARCHAR(255) NULL
 *   notes            TEXT NULL
 *   contacted_by     BIGINT UNSIGNED NOT NULL   FK -> users.id
 *   contacted_at     TIMESTAMP NOT NULL
 *   created_at       TIMESTAMP
 *   updated_at       TIMESTAMP
 */
class SupplierContact
{
    private const TABLE = 'supplier_contacts';

    public const METHODS = ['CALL', 'EMAIL', 'WHATSAPP', 'VISIT'];

    private const COLUMNS = [
        'id', 'supplier_lead_id', 'method', 'outcome',
        'notes', 'contacted_by', 'contacted_at', 'created_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * List all contact attempts for a supplier lead, newest first.
     *
     * @return array<int, array>
     */
    public function listForLead(int $supplierLeadId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('supplier_lead_id', $supplierLeadId)
            ->orderBy('contacted_at', 'DESC')
            ->get();
    }

    /**
     * Find a single contact record by ID.
     */
    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();
    }

    // -------------------------------------------------------------------------
    // Mutations — INSERT ONLY
    // -------------------------------------------------------------------------

    /**
     * Record a new contact attempt.
     *
     * This is the sole write operation permitted on this table.
     *
     * @param array{
     *     supplier_lead_id: int,
     *     method: string,
     *     outcome?: string,
     *     notes?: string,
     *     contacted_by: int,
     *     contacted_at?: string,
     * } $data
     */
    public function create(array $data): array
    {
        $now    = gmdate('Y-m-d H:i:s');
        $method = strtoupper($data['method'] ?? 'CALL');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'supplier_lead_id' => (int) $data['supplier_lead_id'],
                'method'           => $method,
                'outcome'          => $data['outcome'] ?? null,
                'notes'            => $data['notes'] ?? null,
                'contacted_by'     => (int) $data['contacted_by'],
                'contacted_at'     => $data['contacted_at'] ?? $now,
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);

        $id = $this->connection->lastInsertId();

        return $this->find((int) $id) ?? [];
    }

    // No update() or delete() methods — this table is append-only.
}
