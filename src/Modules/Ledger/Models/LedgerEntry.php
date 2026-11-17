<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * LedgerEntry model.
 *
 * Table: ledger_entries
 * APPEND-ONLY: rows are never updated or deleted.
 * Groups related postings under a single reference.
 */
class LedgerEntry
{
    private const TABLE = 'ledger_entries';

    private const COLUMNS = [
        'id', 'entity_type', 'entity_id', 'description', 'created_by', 'created_at',
    ];

    public function __construct(private Connection $connection) {}

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();
    }

    /**
     * Find all entries for a given entity.
     */
    public function findByEntity(string $entityType, int $entityId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * Create a new ledger entry. APPEND-ONLY.
     *
     * @return int The entry ID
     */
    public function create(
        string  $entityType,
        int     $entityId,
        string  $description,
        ?int    $createdBy = null,
    ): int {
        $now = gmdate('Y-m-d H:i:s');

        $data = [
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'description' => $description,
            'created_at'  => $now,
        ];

        if ($createdBy !== null) {
            $data['created_by'] = $createdBy;
        }

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert($data);

        return (int) $this->connection->lastInsertId();
    }
}
