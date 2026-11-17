<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Core\Database\Connection;
use App\Modules\Ledger\Models\LedgerAccount;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Models\LedgerPosting;

/**
 * Double-entry bookkeeping service.
 *
 * IMMUTABLE: no updates to postings. Corrections are made via reversing entries only.
 * All monetary values in integer paise.
 *
 * Every call to postEntry() runs inside an atomic transaction and validates
 * that sum(debits) === sum(credits) before commit.
 */
class LedgerService
{
    // Standard account codes
    public const ACCOUNT_BUYER_RECEIVABLE    = 'BUYER_RECEIVABLE';
    public const ACCOUNT_GATEWAY_HOLDING     = 'GATEWAY_HOLDING';
    public const ACCOUNT_SELLER_PAYABLE      = 'SELLER_PAYABLE';
    public const ACCOUNT_PLATFORM_COMMISSION = 'PLATFORM_COMMISSION';
    public const ACCOUNT_TAX_COLLECTED       = 'TAX_COLLECTED';
    public const ACCOUNT_REFUND_EXPENSE      = 'REFUND_EXPENSE';
    public const ACCOUNT_GATEWAY_FEE         = 'GATEWAY_FEE';

    public function __construct(
        private Connection     $connection,
        private LedgerAccount  $accountModel,
        private LedgerEntry    $entryModel,
        private LedgerPosting  $postingModel,
    ) {}

    /**
     * Record a payment captured by the gateway.
     *
     * DR gateway_holding (asset: we hold funds)
     * CR buyer_receivable (asset reduction: buyer has paid)
     */
    public function recordPayment(int $orderId, int $amountPaise): int
    {
        return $this->postEntry(
            entityType:  'payment_intent',
            entityId:    $orderId,
            description: "Payment captured for order #{$orderId}",
            postings:    [
                ['account' => self::ACCOUNT_GATEWAY_HOLDING,  'debit' => $amountPaise, 'credit' => 0],
                ['account' => self::ACCOUNT_BUYER_RECEIVABLE, 'debit' => 0,            'credit' => $amountPaise],
            ],
        );
    }

    /**
     * Record platform commission on an order.
     *
     * DR seller_payable (reduce what we owe seller)
     * CR platform_commission (revenue)
     */
    public function recordCommission(int $orderId, int $commissionPaise): int
    {
        return $this->postEntry(
            entityType:  'order',
            entityId:    $orderId,
            description: "Platform commission for order #{$orderId}",
            postings:    [
                ['account' => self::ACCOUNT_SELLER_PAYABLE,      'debit' => $commissionPaise, 'credit' => 0],
                ['account' => self::ACCOUNT_PLATFORM_COMMISSION, 'debit' => 0,                'credit' => $commissionPaise],
            ],
        );
    }

    /**
     * Record a refund back to the buyer.
     *
     * DR refund_expense (expense)
     * CR gateway_holding (asset reduction: funds leave holding)
     */
    public function recordRefund(int $refundId, int $amountPaise): int
    {
        return $this->postEntry(
            entityType:  'refund',
            entityId:    $refundId,
            description: "Refund #{$refundId} processed",
            postings:    [
                ['account' => self::ACCOUNT_REFUND_EXPENSE,  'debit' => $amountPaise, 'credit' => 0],
                ['account' => self::ACCOUNT_GATEWAY_HOLDING, 'debit' => 0,            'credit' => $amountPaise],
            ],
        );
    }

    /**
     * Record a settlement payout to a seller.
     *
     * DR seller_payable (liability reduction: we pay the seller)
     * CR gateway_holding (asset reduction: funds leave holding)
     */
    public function recordSettlement(int $settlementId, int $amountPaise): int
    {
        return $this->postEntry(
            entityType:  'settlement',
            entityId:    $settlementId,
            description: "Settlement #{$settlementId} payout",
            postings:    [
                ['account' => self::ACCOUNT_SELLER_PAYABLE,  'debit' => $amountPaise, 'credit' => 0],
                ['account' => self::ACCOUNT_GATEWAY_HOLDING, 'debit' => 0,            'credit' => $amountPaise],
            ],
        );
    }

    /**
     * Post a balanced double-entry. Atomic: all-or-nothing.
     *
     * @param string $entityType Reference entity type (e.g., 'payment_intent', 'refund')
     * @param int    $entityId   Reference entity ID
     * @param string $description Human-readable description
     * @param array  $postings   Array of ['account' => code, 'debit' => int, 'credit' => int]
     *
     * @return int The created entry ID
     * @throws \RuntimeException If postings do not balance
     */
    public function postEntry(string $entityType, int $entityId, string $description, array $postings): int
    {
        if (empty($postings)) {
            throw new \InvalidArgumentException('At least one posting is required');
        }

        // Validate balance BEFORE hitting the database
        $totalDebit  = 0;
        $totalCredit = 0;
        foreach ($postings as $posting) {
            $totalDebit  += (int) $posting['debit'];
            $totalCredit += (int) $posting['credit'];
        }

        if ($totalDebit !== $totalCredit) {
            throw new \RuntimeException(
                "Ledger entry does not balance: debit={$totalDebit} credit={$totalCredit} " .
                "for {$entityType}#{$entityId}"
            );
        }

        if ($totalDebit === 0) {
            throw new \RuntimeException('Ledger entry has zero total — nothing to post');
        }

        $this->connection->beginTransaction();

        try {
            // Create the entry header
            $entryId = $this->entryModel->create($entityType, $entityId, $description);

            // Create each posting line
            foreach ($postings as $posting) {
                $account = $this->accountModel->findByCode($posting['account']);
                if ($account === null) {
                    throw new \RuntimeException("Ledger account not found: {$posting['account']}");
                }

                $this->postingModel->create(
                    entryId:     $entryId,
                    accountId:   (int) $account['id'],
                    debitPaise:  (int) $posting['debit'],
                    creditPaise: (int) $posting['credit'],
                );
            }

            // Final paranoia check: verify balance in the database
            if (!$this->postingModel->entryIsBalanced($entryId)) {
                throw new \RuntimeException(
                    "CRITICAL: Ledger entry {$entryId} failed balance verification after insert"
                );
            }

            $this->connection->commit();

            return $entryId;
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Get the balance of a ledger account by code.
     *
     * @return int Balance in paise (positive = debit-normal, negative = credit-normal)
     */
    public function getAccountBalance(string $accountCode): int
    {
        $account = $this->accountModel->findByCode($accountCode);
        if ($account === null) {
            throw new \RuntimeException("Ledger account not found: {$accountCode}");
        }

        return $this->postingModel->getAccountBalance((int) $account['id']);
    }

    /**
     * Get the balance of a ledger account by ID.
     */
    public function getAccountBalanceById(int $accountId): int
    {
        return $this->postingModel->getAccountBalance($accountId);
    }
}
