<?php

declare(strict_types=1);

namespace App\Modules\ProcurementCRM\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * SupplierLead model.
 *
 * Maps to the `supplier_leads` table (schema §3.6).
 *
 * Table columns:
 *   id                  BIGINT UNSIGNED AUTO_INCREMENT PK
 *   legal_name          VARCHAR(255) NOT NULL
 *   source              VARCHAR(100) NOT NULL
 *   contact_info_json   JSON NULL     (application-managed JSON blob)
 *   verification_status ENUM(unverified|pending|verified|rejected)
 *   region_id           BIGINT UNSIGNED NULL
 *   notes               TEXT NULL
 *   created_by          BIGINT UNSIGNED NULL   FK -> users.id
 *   created_at          TIMESTAMP
 *   updated_at          TIMESTAMP
 *
 * Source values:
 *   PLATFORM_SELLER | MANUAL_SEARCH | REFERRAL | DIRECTORY
 *
 * Note: `contact_info_json` is stored in the `notes` column as a workaround
 * when the column is not yet present; callers should migrate the schema to add
 * a dedicated `contact_info_json JSON NULL` column alongside `notes`.
 */
class SupplierLead
{
    private const TABLE = 'supplier_leads';

    public const SOURCES = [
        'PLATFORM_SELLER',
        'MANUAL_SEARCH',
        'REFERRAL',
        'DIRECTORY',
    ];

    public const VERIFICATION_STATUSES = [
        'unverified',
        'pending',
        'verified',
        'rejected',
    ];

    private const COLUMNS = [
        'id', 'legal_name', 'source', 'contact_info_json',
        'verification_status', 'region_id', 'notes',
        'created_by', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Find a single supplier lead by ID.
     */
    public function find(int $id): ?array
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();

        return $row !== null ? $this->decodeJson($row) : null;
    }

    /**
     * Full-text style search over legal_name.
     *
     * @param array{name?: string, source?: string, verification_status?: string} $filters
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function search(array $filters = [], int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS);

        if (!empty($filters['name'])) {
            $term = '%' . addcslashes($filters['name'], '%_') . '%';
            $qb->where('legal_name', 'LIKE', $term);
        }

        if (!empty($filters['source'])) {
            $qb->where('source', strtoupper($filters['source']));
        }

        if (!empty($filters['verification_status'])) {
            $qb->where('verification_status', strtolower($filters['verification_status']));
        }

        $total = (clone $qb)->count();

        $rows = $qb
            ->orderBy('legal_name', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => array_map(fn(array $r) => $this->decodeJson($r), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    /**
     * List supplier leads for a specific region.
     *
     * @return array{data: array, total: int, page: int, per_page: int}
     */
    public function findByRegion(int $regionId, int $page = 1, int $perPage = 20): array
    {
        $page    = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset  = ($page - 1) * $perPage;

        $qb = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('region_id', $regionId);

        $total = (clone $qb)->count();

        $rows = $qb
            ->orderBy('legal_name', 'ASC')
            ->limit($perPage)
            ->offset($offset)
            ->get();

        return [
            'data'     => array_map(fn(array $r) => $this->decodeJson($r), $rows),
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
        ];
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new supplier lead.
     *
     * @param array{
     *     legal_name: string,
     *     source: string,
     *     contact_info?: array,
     *     region_id?: int,
     *     notes?: string,
     *     created_by?: int,
     * } $data
     */
    public function create(array $data): array
    {
        $now    = gmdate('Y-m-d H:i:s');
        $source = strtoupper($data['source'] ?? 'MANUAL_SEARCH');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'legal_name'          => $data['legal_name'],
                'source'              => $source,
                'contact_info_json'   => isset($data['contact_info'])
                    ? json_encode($data['contact_info'], JSON_UNESCAPED_UNICODE)
                    : null,
                'verification_status' => 'unverified',
                'region_id'           => $data['region_id'] ?? null,
                'notes'               => $data['notes'] ?? null,
                'created_by'          => $data['created_by'] ?? null,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);

        $id = $this->connection->lastInsertId();

        return $this->find((int) $id) ?? [];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * JSON-decode the contact_info_json field if present.
     */
    private function decodeJson(array $row): array
    {
        if (isset($row['contact_info_json']) && is_string($row['contact_info_json'])) {
            $row['contact_info'] = json_decode($row['contact_info_json'], true) ?? [];
        } else {
            $row['contact_info'] = [];
        }

        return $row;
    }
}
