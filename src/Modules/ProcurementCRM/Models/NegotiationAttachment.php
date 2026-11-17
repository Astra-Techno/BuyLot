<?php

declare(strict_types=1);

namespace App\Modules\ProcurementCRM\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * NegotiationAttachment model.
 *
 * Maps to the `negotiation_attachments` table (schema §3.9).
 *
 * Table columns:
 *   id         BIGINT UNSIGNED AUTO_INCREMENT PK
 *   event_id   BIGINT UNSIGNED NOT NULL  FK -> negotiation_events.id  (CASCADE DELETE)
 *   object_ref VARCHAR(500) NOT NULL     Object-storage path/key
 *   mime       VARCHAR(100) NOT NULL
 *   checksum   VARCHAR(128) NOT NULL     SHA-256 hex of file content
 *   created_at TIMESTAMP NOT NULL
 */
class NegotiationAttachment
{
    private const TABLE = 'negotiation_attachments';

    private const COLUMNS = [
        'id', 'event_id', 'object_ref', 'mime', 'checksum', 'created_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    /**
     * Find a single attachment by ID.
     */
    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();
    }

    /**
     * List all attachments for a negotiation event.
     *
     * @return array<int, array>
     */
    public function listForEvent(int $eventId): array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('event_id', $eventId)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Attach a document to a negotiation event.
     *
     * @param array{
     *     event_id: int,
     *     object_ref: string,
     *     mime: string,
     *     checksum: string,
     * } $data
     */
    public function create(array $data): array
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'event_id'   => (int) $data['event_id'],
                'object_ref' => $data['object_ref'],
                'mime'       => $data['mime'],
                'checksum'   => $data['checksum'],
                'created_at' => $now,
            ]);

        $id = $this->connection->lastInsertId();

        return $this->find((int) $id) ?? [];
    }
}
