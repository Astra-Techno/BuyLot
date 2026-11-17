<?php

declare(strict_types=1);

namespace App\Modules\ProcurementCRM\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * NegotiationEvent model — APPEND-ONLY.
 *
 * Maps to the `negotiation_events` table (schema §3.8).
 * Per the design notes: negotiation_events are immutable; no UPDATE or DELETE.
 *
 * Table columns:
 *   id          BIGINT UNSIGNED AUTO_INCREMENT PK
 *   case_id     BIGINT UNSIGNED NOT NULL  FK -> procurement_cases.id
 *   supplier_id BIGINT UNSIGNED NULL      seller_profiles.id or supplier_leads.id
 *   bid_id      BIGINT UNSIGNED NULL      FK -> seller_bids.id
 *   type        VARCHAR(100) NOT NULL
 *   terms_json  JSON NULL
 *   created_by  BIGINT UNSIGNED NOT NULL  FK -> users.id
 *   created_at  TIMESTAMP NOT NULL
 *
 * Type values (application layer):
 *   INITIAL_QUOTE | COUNTER_OFFER | PRICE_REDUCTION | TERMS_CHANGE |
 *   FINAL_OFFER   | ACCEPTANCE    | REJECTION
 */
class NegotiationEvent
{
    private const TABLE = 'negotiation_events';

    public const TYPES = [
        'INITIAL_QUOTE',
        'COUNTER_OFFER',
        'PRICE_REDUCTION',
        'TERMS_CHANGE',
        'FINAL_OFFER',
        'ACCEPTANCE',
        'REJECTION',
    ];

    private const COLUMNS = [
        'id', 'case_id', 'supplier_id', 'bid_id',
        'type', 'terms_json', 'created_by', 'created_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Find a single negotiation event by ID.
     */
    public function find(int $id): ?array
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();

        return $row !== null ? $this->decodeTerms($row) : null;
    }

    /**
     * Return the full negotiation timeline for a case, oldest first.
     *
     * @return array<int, array>
     */
    public function listForCase(int $caseId): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('case_id', $caseId)
            ->orderBy('created_at', 'ASC')
            ->get();

        return array_map(fn(array $r) => $this->decodeTerms($r), $rows);
    }

    // -------------------------------------------------------------------------
    // Mutations — INSERT ONLY
    // -------------------------------------------------------------------------

    /**
     * Record a new negotiation event.
     *
     * This is the sole write operation permitted on this table.
     *
     * @param array{
     *     case_id: int,
     *     type: string,
     *     created_by: int,
     *     supplier_id?: int,
     *     bid_id?: int,
     *     terms?: array,
     * } $data
     */
    public function create(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'case_id'     => (int) $data['case_id'],
                'supplier_id' => isset($data['supplier_id']) ? (int) $data['supplier_id'] : null,
                'bid_id'      => isset($data['bid_id']) ? (int) $data['bid_id'] : null,
                'type'        => strtoupper($data['type']),
                'terms_json'  => isset($data['terms'])
                    ? json_encode($data['terms'], JSON_UNESCAPED_UNICODE)
                    : null,
                'created_by'  => (int) $data['created_by'],
                'created_at'  => $now,
            ]);

        $id = $this->connection->lastInsertId();

        return $this->find((int) $id) ?? [];
    }

    // No update() or delete() methods — this table is append-only.

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function decodeTerms(array $row): array
    {
        if (isset($row['terms_json']) && is_string($row['terms_json'])) {
            $row['terms'] = json_decode($row['terms_json'], true) ?? [];
        } else {
            $row['terms'] = [];
        }

        return $row;
    }
}
