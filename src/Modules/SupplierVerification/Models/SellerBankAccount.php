<?php

declare(strict_types=1);

namespace App\Modules\SupplierVerification\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * SellerBankAccount model.
 *
 * Maps to: seller_bank_accounts
 *   id                     BIGINT UNSIGNED PK AUTO_INCREMENT
 *   seller_id              BIGINT UNSIGNED NOT NULL (FK -> seller_profiles.id)
 *   provider_token         VARCHAR(500) NOT NULL    — opaque token from payment provider;
 *                                                     raw account/IFSC MUST NOT be stored here
 *   account_holder_name    VARCHAR(255) NOT NULL
 *   bank_name              VARCHAR(255) NULL
 *   verified_at            TIMESTAMP NULL
 *   status                 ENUM('pending_verification','verified','failed','deactivated')
 *   change_requested_by    BIGINT UNSIGNED NULL     — user_id who requested this change
 *   change_approved_by     BIGINT UNSIGNED NULL     — user_id who approved (must != requester)
 *   cooling_off_until      TIMESTAMP NULL           — new account not active before this timestamp
 *   created_at             TIMESTAMP
 *   updated_at             TIMESTAMP
 *
 * Security invariants (enforced at application layer):
 *   1. Raw account numbers or IFSC codes MUST NEVER be stored — only provider_token.
 *   2. Any change to provider_token requires a separate approver
 *      (change_approved_by != change_requested_by).
 *   3. A cooling-off period of COOLING_OFF_HOURS hours must elapse before the new
 *      account becomes eligible for settlement disbursements.
 */
class SellerBankAccount
{
    private const TABLE = 'seller_bank_accounts';

    /** Hours before a newly approved bank account becomes live for settlements. */
    private const COOLING_OFF_HOURS = 72;

    public const STATUS_PENDING     = 'pending_verification';
    public const STATUS_VERIFIED    = 'verified';
    public const STATUS_FAILED      = 'failed';
    public const STATUS_DEACTIVATED = 'deactivated';

    public function __construct(private readonly Connection $connection) {}

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
     * Find the current active (or most recent) bank account for a seller.
     */
    public function findBySeller(int $sellerId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('seller_id', $sellerId)
            ->orderBy('created_at', 'DESC')
            ->first();
    }

    /**
     * Find a pending change-request (status = pending_verification, change_requested_by set).
     */
    public function findPendingChange(int $sellerId): ?array
    {
        return (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('seller_id', $sellerId)
            ->where('status', self::STATUS_PENDING)
            ->orderBy('created_at', 'DESC')
            ->first();
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Request a bank account change.
     *
     * Creates a new row in pending_verification state. The same user CANNOT approve
     * their own request — this is enforced in approveBankChange().
     *
     * @param string $providerToken  Opaque token from the payment provider — NEVER raw account data
     */
    public function requestChange(
        int $sellerId,
        string $providerToken,
        string $accountHolderName,
        ?string $bankName,
        int $requestedBy,
    ): array {
        // Deactivate any existing pending change requests to avoid orphaned rows
        $this->deactivatePendingChanges($sellerId);

        $now = gmdate('Y-m-d H:i:s');

        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->insert([
                'seller_id'             => $sellerId,
                'provider_token'        => $providerToken,
                'account_holder_name'   => $accountHolderName,
                'bank_name'             => $bankName,
                'status'                => self::STATUS_PENDING,
                'change_requested_by'   => $requestedBy,
                'change_approved_by'    => null,
                'cooling_off_until'     => null,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);

        $id = (int) $this->connection->lastInsertId();

        return $this->find($id) ?? ['id' => $id, 'seller_id' => $sellerId, 'status' => self::STATUS_PENDING];
    }

    /**
     * Approve a pending bank account change.
     *
     * Enforces dual-approval: $approvedBy MUST differ from change_requested_by.
     * Sets verified_at, cooling_off_until, and status = 'verified'.
     * Deactivates the previous verified account.
     *
     * @throws \LogicException when the approver is the same as the requester,
     *                         or when no pending change exists.
     */
    public function approveBankChange(int $sellerId, int $pendingId, int $approvedBy): array
    {
        $pending = $this->find($pendingId);

        if ($pending === null || (int) $pending['seller_id'] !== $sellerId) {
            throw new \LogicException('Pending bank account record not found for this seller.');
        }

        if ((string) $pending['status'] !== self::STATUS_PENDING) {
            throw new \LogicException('Bank account record is not in pending_verification state.');
        }

        // CRITICAL: dual-approval — approver must be a different user than requester
        if ((int) $pending['change_requested_by'] === $approvedBy) {
            throw new \LogicException(
                'Dual-approval violation: the approver must be a different user than the requester.',
            );
        }

        $now            = gmdate('Y-m-d H:i:s');
        $coolingOffUntil = gmdate(
            'Y-m-d H:i:s',
            strtotime('+' . self::COOLING_OFF_HOURS . ' hours'),
        );

        $this->connection->beginTransaction();

        try {
            // Deactivate any currently verified account for this seller
            $this->deactivateVerifiedAccounts($sellerId, $pendingId);

            // Approve the pending record
            (new QueryBuilder($this->connection))
                ->table(self::TABLE)
                ->where('id', $pendingId)
                ->update([
                    'status'             => self::STATUS_VERIFIED,
                    'verified_at'        => $now,
                    'change_approved_by' => $approvedBy,
                    'cooling_off_until'  => $coolingOffUntil,
                    'updated_at'         => $now,
                ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->find($pendingId) ?? ['id' => $pendingId, 'status' => self::STATUS_VERIFIED];
    }

    /**
     * Whether the most recent verified bank account has cleared its cooling-off period
     * and is eligible for settlement disbursements.
     */
    public function isEligibleForSettlement(int $sellerId): bool
    {
        $account = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('seller_id', $sellerId)
            ->where('status', self::STATUS_VERIFIED)
            ->orderBy('verified_at', 'DESC')
            ->first();

        if ($account === null) {
            return false;
        }

        if ($account['cooling_off_until'] === null) {
            return true;
        }

        return strtotime($account['cooling_off_until']) <= time();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function deactivatePendingChanges(int $sellerId): void
    {
        // Fetch existing pending rows and mark them failed so history is preserved
        $pending = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('seller_id', $sellerId)
            ->where('status', self::STATUS_PENDING)
            ->get();

        foreach ($pending as $row) {
            (new QueryBuilder($this->connection))
                ->table(self::TABLE)
                ->where('id', (int) $row['id'])
                ->update([
                    'status'     => self::STATUS_FAILED,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
        }
    }

    private function deactivateVerifiedAccounts(int $sellerId, int $exceptId): void
    {
        $verified = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('seller_id', $sellerId)
            ->where('status', self::STATUS_VERIFIED)
            ->get();

        foreach ($verified as $row) {
            if ((int) $row['id'] === $exceptId) {
                continue;
            }

            (new QueryBuilder($this->connection))
                ->table(self::TABLE)
                ->where('id', (int) $row['id'])
                ->update([
                    'status'     => self::STATUS_DEACTIVATED,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
        }
    }
}
