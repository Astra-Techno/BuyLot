<?php

declare(strict_types=1);

namespace App\Modules\Offers\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * StockReservation model.
 *
 * Table: stock_reservations
 * Statuses: active, converted, released, expired
 * Requires SELECT FOR UPDATE for atomic reservation (enforced at service layer).
 */
class StockReservation
{
    private const TABLE = 'stock_reservations';

    private const COLUMNS = [
        'id', 'offer_id', 'buyer_id', 'qty',
        'expires_at', 'status', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('id', $id)
            ->first();
    }

    public function findActiveByOfferAndBuyer(int $offerId, int $buyerId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select(...self::COLUMNS)
            ->where('offer_id', $offerId)
            ->where('buyer_id', $buyerId)
            ->where('status', 'active')
            ->first();
    }

    /**
     * Sum of active reserved qty for an offer.
     */
    public function totalActiveReservedQty(int $offerId): int
    {
        $result = $this->connection->query(
            'SELECT COALESCE(SUM(qty), 0) as total_qty FROM ' . self::TABLE
                . ' WHERE offer_id = ? AND status = ?',
            [$offerId, 'active'],
        );

        return (int) ($result[0]['total_qty'] ?? 0);
    }

    /**
     * Sum of converted qty for an offer.
     */
    public function totalConvertedQty(int $offerId): int
    {
        $result = $this->connection->query(
            'SELECT COALESCE(SUM(qty), 0) as total_qty FROM ' . self::TABLE
                . ' WHERE offer_id = ? AND status = ?',
            [$offerId, 'converted'],
        );

        return (int) ($result[0]['total_qty'] ?? 0);
    }

    /**
     * Get active reserved qty with FOR UPDATE lock (for atomic reservation).
     * MUST be called within an active transaction.
     */
    public function totalActiveReservedQtyForUpdate(int $offerId): int
    {
        $result = $this->connection->query(
            'SELECT COALESCE(SUM(qty), 0) as total_qty FROM ' . self::TABLE
                . ' WHERE offer_id = ? AND status = ? FOR UPDATE',
            [$offerId, 'active'],
        );

        return (int) ($result[0]['total_qty'] ?? 0);
    }

    /**
     * Find all expired-but-active reservations (for background cleanup).
     */
    public function findStaleReservations(int $limit = 100): array
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->query(
            'SELECT ' . implode(', ', self::COLUMNS)
                . ' FROM ' . self::TABLE
                . ' WHERE status = ? AND expires_at < ? LIMIT ?',
            ['active', $now, $limit],
        );
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a reservation record.
     */
    public function create(array $data): int
    {
        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'offer_id'   => $data['offer_id'],
                'buyer_id'   => $data['buyer_id'],
                'qty'        => $data['qty'],
                'expires_at' => $data['expires_at'],
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

        return (int) $this->connection->lastInsertId();
    }

    /**
     * Update reservation status.
     */
    public function updateStatus(int $id, string $status): bool
    {
        if (!in_array($status, ['active', 'converted', 'released', 'expired'], true)) {
            return false;
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update([
                'status'     => $status,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);

        return $rows > 0;
    }

    /**
     * Bulk-expire stale reservations. Returns number of rows affected.
     */
    public function expireStaleReservations(): int
    {
        $now = gmdate('Y-m-d H:i:s');

        return $this->connection->execute(
            'UPDATE ' . self::TABLE . ' SET status = ?, updated_at = ? WHERE status = ? AND expires_at < ?',
            ['expired', $now, 'active', $now],
        );
    }
}
