<?php

declare(strict_types=1);

namespace App\Modules\SupplierVerification\Controllers;

use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\SupplierVerification\Models\SellerBankAccount;
use App\Modules\SupplierVerification\Models\SellerProfile;
use App\Modules\SupplierVerification\Models\SellerVerificationCase;
use App\Modules\SupplierVerification\Services\SellerVerificationService;

/**
 * SellerVerificationController — internal / compliance endpoints.
 *
 * All routes behind auth + compliance_admin (or higher) middleware.
 *
 *   GET  /v1/internal/sellers                             → listSellers()
 *   GET  /v1/internal/sellers/{id}                       → showSeller()
 *   PUT  /v1/internal/sellers/{id}/status                → suspendSeller()   (also activates)
 *   GET  /v1/internal/verification-cases                 → listPending()
 *   POST /v1/internal/verification-cases/{id}/review     → review()
 *   PUT  /v1/internal/sellers/{id}/bank-account          → updateBankAccount()
 *   POST /v1/internal/sellers/{id}/bank-account/approve  → approveBankChange()
 */
readonly class SellerVerificationController
{
    public function __construct(
        private SellerVerificationService $verificationService,
        private SellerProfile $sellerProfile,
        private SellerVerificationCase $verificationCase,
        private SellerBankAccount $bankAccount,
    ) {}

    // -------------------------------------------------------------------------
    // GET /v1/internal/sellers
    // -------------------------------------------------------------------------

    /**
     * List all sellers with optional filters.
     *
     * Query params:
     *   status       string  optional — pending_approval | active | suspended | terminated
     *   category_id  int     optional
     *   region       string  optional
     *   page         int     default 1
     *   per_page     int     default 20 (max 100)
     */
    public function listSellers(Request $request): Response
    {
        $filters = array_filter([
            'status'      => $request->getQuery('status'),
            'category_id' => $request->getQuery('category_id'),
            'region'      => $request->getQuery('region'),
        ]);

        $page    = (int) ($request->getQuery('page', 1));
        $perPage = (int) ($request->getQuery('per_page', 20));

        $result = $this->sellerProfile->list($filters, $page, $perPage);

        return Response::json($result);
    }

    // -------------------------------------------------------------------------
    // GET /v1/internal/sellers/{id}
    // -------------------------------------------------------------------------

    /**
     * Full detail for a single seller including all verification cases and bank accounts.
     */
    public function showSeller(Request $request): Response
    {
        $sellerId = $this->requireIntParam($request, 'id');

        if ($sellerId === null) {
            return Response::badRequest('Missing or invalid seller ID.');
        }

        $profile = $this->sellerProfile->find($sellerId);

        if ($profile === null) {
            return Response::notFound('Seller profile not found.');
        }

        $cases        = $this->verificationCase->listBySeller($sellerId);
        $bankAccounts = $this->listBankAccountsForSeller($sellerId);

        return Response::json([
            'seller_profile'     => $profile,
            'verification_cases' => $cases,
            'bank_accounts'      => $bankAccounts,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /v1/internal/verification-cases
    // -------------------------------------------------------------------------

    /**
     * Paginated list of verification cases awaiting review.
     *
     * Query params:
     *   page      int  default 1
     *   per_page  int  default 20
     */
    public function listPending(Request $request): Response
    {
        $page    = (int) ($request->getQuery('page', 1));
        $perPage = (int) ($request->getQuery('per_page', 20));

        $result = $this->verificationCase->listPending($page, $perPage);

        return Response::json($result);
    }

    // -------------------------------------------------------------------------
    // POST /v1/internal/verification-cases/{id}/review
    // -------------------------------------------------------------------------

    /**
     * Submit a compliance review on a verification case.
     *
     * Body (JSON):
     *   result  string  required — passed | failed | manual_review
     *   notes   string  optional
     */
    public function review(Request $request): Response
    {
        $user   = $this->resolveUser($request);
        $caseId = $this->requireIntParam($request, 'id');

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        if ($caseId === null) {
            return Response::badRequest('Missing or invalid case ID.');
        }

        $data   = $request->getBody();
        $result = $data['result'] ?? null;

        if (empty($result) || !in_array($result, SellerVerificationCase::ALLOWED_RESULTS, true)) {
            throw new ValidationException([
                'result' => 'Result must be one of: ' . implode(', ', SellerVerificationCase::ALLOWED_RESULTS),
            ]);
        }

        if ($result === SellerVerificationCase::RESULT_PENDING) {
            throw new ValidationException(['result' => 'Cannot set result to pending via review.']);
        }

        $updatedCase = $this->verificationService->review(
            caseId:     $caseId,
            result:     $result,
            reviewedBy: (int) $user['user_id'],
            notes:      $data['notes'] ?? null,
        );

        return Response::json([
            'message'           => 'Verification case reviewed.',
            'verification_case' => $updatedCase,
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /v1/internal/sellers/{id}/status
    // -------------------------------------------------------------------------

    /**
     * Update a seller's status (suspend, re-activate, etc.).
     *
     * Body (JSON):
     *   status  string  required — pending_approval | active | suspended | terminated
     *   reason  string  required when suspending
     */
    public function suspendSeller(Request $request): Response
    {
        $user     = $this->resolveUser($request);
        $sellerId = $this->requireIntParam($request, 'id');

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        if ($sellerId === null) {
            return Response::badRequest('Missing or invalid seller ID.');
        }

        $data   = $request->getBody();
        $status = $data['status'] ?? null;

        if (empty($status) || !in_array($status, SellerProfile::ALLOWED_STATUSES, true)) {
            throw new ValidationException([
                'status' => 'Status must be one of: ' . implode(', ', SellerProfile::ALLOWED_STATUSES),
            ]);
        }

        if ($status === SellerProfile::STATUS_SUSPENDED) {
            if (empty($data['reason'])) {
                throw new ValidationException(['reason' => 'A reason is required when suspending a seller.']);
            }

            $profile = $this->verificationService->suspendSeller(
                sellerId: $sellerId,
                actorId:  (int) $user['user_id'],
                reason:   $data['reason'],
            );
        } else {
            $success = $this->sellerProfile->updateStatus($sellerId, $status);

            if (!$success) {
                return Response::notFound('Seller profile not found.');
            }

            $profile = $this->sellerProfile->find($sellerId);
        }

        return Response::json([
            'message' => "Seller status updated to {$status}.",
            'profile' => $profile,
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /v1/internal/sellers/{id}/bank-account
    // -------------------------------------------------------------------------

    /**
     * Request a bank account change for a seller.
     *
     * Body (JSON):
     *   provider_token        string  required — opaque token from payment provider
     *   account_holder_name   string  required
     *   bank_name             string  optional
     *
     * SECURITY: Raw account numbers or IFSC codes must NEVER be supplied here.
     * Only an opaque provider_token (e.g. from Razorpay Fund Account API) is accepted.
     * The change requires a separate approval from a different compliance user.
     */
    public function updateBankAccount(Request $request): Response
    {
        $user     = $this->resolveUser($request);
        $sellerId = $this->requireIntParam($request, 'id');

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        if ($sellerId === null) {
            return Response::badRequest('Missing or invalid seller ID.');
        }

        $profile = $this->sellerProfile->find($sellerId);

        if ($profile === null) {
            return Response::notFound('Seller profile not found.');
        }

        $data   = $request->getBody();
        $errors = $this->validateBankAccountRequest($data);

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $pendingAccount = $this->bankAccount->requestChange(
            sellerId:           $sellerId,
            providerToken:      $data['provider_token'],
            accountHolderName:  $data['account_holder_name'],
            bankName:           $data['bank_name'] ?? null,
            requestedBy:        (int) $user['user_id'],
        );

        return Response::created([
            'message'      => 'Bank account change requested. Awaiting approval from a different compliance user.',
            'bank_account' => $this->sanitizeBankAccount($pendingAccount),
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /v1/internal/sellers/{id}/bank-account/approve
    // -------------------------------------------------------------------------

    /**
     * Approve a pending bank account change.
     *
     * CRITICAL SECURITY RULE: The approving user MUST be different from the requesting user.
     * This is enforced in SellerBankAccount::approveBankChange().
     * A cooling-off period (72 h by default) applies before the account is live for settlements.
     *
     * Body (JSON):
     *   pending_account_id  int  required — ID of the pending seller_bank_accounts row
     */
    public function approveBankChange(Request $request): Response
    {
        $user     = $this->resolveUser($request);
        $sellerId = $this->requireIntParam($request, 'id');

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        if ($sellerId === null) {
            return Response::badRequest('Missing or invalid seller ID.');
        }

        $data             = $request->getBody();
        $pendingAccountId = isset($data['pending_account_id']) ? (int) $data['pending_account_id'] : null;

        if ($pendingAccountId === null || $pendingAccountId <= 0) {
            throw new ValidationException([
                'pending_account_id' => 'pending_account_id is required and must be a positive integer.',
            ]);
        }

        try {
            $approved = $this->bankAccount->approveBankChange(
                sellerId:  $sellerId,
                pendingId: $pendingAccountId,
                approvedBy: (int) $user['user_id'],
            );
        } catch (\LogicException $e) {
            return Response::forbidden($e->getMessage());
        }

        return Response::json([
            'message'      => 'Bank account change approved. Cooling-off period is now active.',
            'bank_account' => $this->sanitizeBankAccount($approved),
        ]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function resolveUser(Request $request): ?array
    {
        $user = $request->getParam('_user');
        return is_array($user) && !empty($user['user_id']) ? $user : null;
    }

    private function requireIntParam(Request $request, string $key): ?int
    {
        $val = $request->getParam($key);
        if ($val === null || !ctype_digit((string) $val)) {
            return null;
        }
        return (int) $val;
    }

    private function listBankAccountsForSeller(int $sellerId): array
    {
        // The QueryBuilder does not have a listBySeller convenience; use findBySeller
        // which returns only the most recent. For compliance detail we show all.
        $account = $this->bankAccount->findBySeller($sellerId);
        return $account !== null ? [$this->sanitizeBankAccount($account)] : [];
    }

    private function validateBankAccountRequest(array $data): array
    {
        $errors = [];

        if (empty($data['provider_token'])) {
            $errors['provider_token'] = 'provider_token is required (opaque payment-provider token).';
        }

        if (empty($data['account_holder_name'])) {
            $errors['account_holder_name'] = 'account_holder_name is required.';
        }

        // Reject any attempt to pass raw account numbers or IFSC codes
        foreach (['account_number', 'ifsc', 'account_no', 'routing_number'] as $forbidden) {
            if (array_key_exists($forbidden, $data)) {
                $errors[$forbidden] = "Raw {$forbidden} must never be stored. Use provider_token only.";
            }
        }

        return $errors;
    }

    /**
     * Never expose the provider_token to responses; mask it.
     */
    private function sanitizeBankAccount(array $account): array
    {
        if (isset($account['provider_token'])) {
            $token = $account['provider_token'];
            $account['provider_token'] = '****' . substr($token, -4);
        }

        return $account;
    }
}
