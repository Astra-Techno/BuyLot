<?php

declare(strict_types=1);

namespace App\Modules\Offers\Services;

use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Modules\Offers\Models\StockReservation;

/**
 * StockReservationService — atomic stock reservation with concurrency control.
 *
 * Uses SELECT FOR UPDATE within transactions to prevent overselling.
 * Available qty = offer max_qty - active reserved - converted.
 */
class StockReservationService
{
    /** Default reservation TTL in minutes. */
    private const DEFAULT_RESERVATION_TTL_MINUTES = 30;

    public function __construct(
        private Connection       $connection,
        private StockReservation $reservationModel,
    ) {}

    /**
     * Atomically reserve stock for a buyer on an offer.
     *
     * Uses SELECT FOR UPDATE to prevent race conditions.
     * MUST NOT be called inside an existing transaction — this manages its own.
     *
     * @param int      $offerId    Offer ID
     * @param int      $buyerId    Buyer user ID
     * @param int      $qty        Quantity to reserve
     * @param int|null $maxQty     Maximum available stock for the offer (from seller bid)
     * @param int      $ttlMinutes Reservation TTL in minutes
     * @return array   The created reservation record
     */
    public function reserve(
        int  $offerId,
        int  $buyerId,
        int  $qty,
        ?int $maxQty = null,
        int  $ttlMinutes = self::DEFAULT_RESERVATION_TTL_MINUTES,
    ): array {
        if ($qty < 1) {
            throw new HttpException('Reservation quantity must be at least 1', 422);
        }

        // Check if buyer already has an active reservation
        $existing = $this->reservationModel->findActiveByOfferAndBuyer($offerId, $buyerId);
        if ($existing !== null) {
            throw new HttpException('Buyer already has an active reservation for this offer', 409);
        }

        $expiresAt = gmdate('Y-m-d H:i:s', time() + ($ttlMinutes * 60));

        $this->connection->beginTransaction();

        try {
            // Atomic: lock and read current reserved qty
            $activeReserved  = $this->reservationModel->totalActiveReservedQtyForUpdate($offerId);
            $convertedQty    = $this->reservationModel->totalConvertedQty($offerId);
            $unavailableQty  = $activeReserved + $convertedQty;

            // Check available stock if max_qty is known
            if ($maxQty !== null) {
                $available = $maxQty - $unavailableQty;

                if ($qty > $available) {
                    $this->connection->rollBack();
                    throw new HttpException(
                        'Insufficient stock: requested ' . $qty . ', available ' . max(0, $available),
                        409,
                    );
                }
            }

            $reservationId = $this->reservationModel->create([
                'offer_id'   => $offerId,
                'buyer_id'   => $buyerId,
                'qty'        => $qty,
                'expires_at' => $expiresAt,
            ]);

            $this->connection->commit();
        } catch (HttpException $e) {
            // Already rolled back above for stock check failure
            throw $e;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->reservationModel->find($reservationId);
    }

    /**
     * Release a reservation (mark as released).
     */
    public function release(int $reservationId): bool
    {
        $reservation = $this->reservationModel->find($reservationId);

        if ($reservation === null) {
            throw new HttpException('Reservation not found', 404);
        }

        if ($reservation['status'] !== 'active') {
            throw new HttpException('Only active reservations can be released', 409);
        }

        return $this->reservationModel->updateStatus($reservationId, 'released');
    }

    /**
     * Convert a reservation to a confirmed order allocation.
     */
    public function convert(int $reservationId): bool
    {
        $reservation = $this->reservationModel->find($reservationId);

        if ($reservation === null) {
            throw new HttpException('Reservation not found', 404);
        }

        if ($reservation['status'] !== 'active') {
            throw new HttpException('Only active reservations can be converted', 409);
        }

        return $this->reservationModel->updateStatus($reservationId, 'converted');
    }

    /**
     * Expire stale reservations (background job entry point).
     *
     * @return int Number of reservations expired
     */
    public function expireStale(): int
    {
        return $this->reservationModel->expireStaleReservations();
    }

    /**
     * Get available quantity for an offer.
     *
     * Available = maxQty - (active reserved) - (converted)
     * If maxQty is NULL (unlimited), returns NULL to indicate unlimited.
     */
    public function getAvailableQty(int $offerId, ?int $maxQty = null): ?int
    {
        if ($maxQty === null) {
            return null; // Unlimited
        }

        $activeReserved = $this->reservationModel->totalActiveReservedQty($offerId);
        $convertedQty   = $this->reservationModel->totalConvertedQty($offerId);

        return max(0, $maxQty - $activeReserved - $convertedQty);
    }
}
