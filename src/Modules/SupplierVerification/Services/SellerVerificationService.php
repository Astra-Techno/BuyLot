<?php

declare(strict_types=1);

namespace App\Modules\SupplierVerification\Services;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;
use App\Core\Exception\ValidationException;
use App\Modules\SupplierVerification\Models\SellerBankAccount;
use App\Modules\SupplierVerification\Models\SellerProfile;
use App\Modules\SupplierVerification\Models\SellerVerificationCase;

/**
 * SellerVerificationService
 *
 * Orchestrates the full seller onboarding and compliance verification workflow:
 *   1. register()         — create org + seller profile; return PENDING status
 *   2. submitDocuments()  — open verification cases for KYC / GST / IDENTITY docs
 *   3. review()           — compliance decision (pass / fail / manual_review)
 *   4. activateSeller()   — transition profile to ACTIVE once all required cases pass
 *   5. suspendSeller()    — compliance-initiated suspension with reason
 *
 * Bank-account changes are handled via SellerBankAccount (dual-approval, cooling-off).
 */
class SellerVerificationService
{
    /**
     * Verification case types that MUST pass before a seller can be activated.
     */
    private const REQUIRED_CASE_TYPES = [
        SellerVerificationCase::TYPE_KYC,
        SellerVerificationCase::TYPE_GST,
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly SellerProfile $sellerProfile,
        private readonly SellerVerificationCase $verificationCase,
        private readonly SellerBankAccount $bankAccount,
    ) {}

    // -------------------------------------------------------------------------
    // Onboarding
    // -------------------------------------------------------------------------

    /**
     * Register a new seller: create an organization of type 'seller', add the
     * requesting user as 'owner', and create a seller_profile in PENDING state.
     *
     * @param array{legal_name: string, display_name: string, tax_id_ref?: string} $data
     * @param int $userId  The authenticated user who is registering
     * @return array{organization: array, seller_profile: array}
     * @throws ValidationException on duplicate organization / missing fields
     */
    public function register(array $data, int $userId): array
    {
        $errors = [];

        if (empty($data['legal_name'])) {
            $errors['legal_name'] = 'Legal name is required.';
        }

        if (empty($data['display_name'])) {
            $errors['display_name'] = 'Display name is required.';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        // Check if user already owns a seller org
        $existing = (new QueryBuilder($this->connection))
            ->table('organization_members')
            ->where('user_id', $userId)
            ->where('role', 'owner')
            ->first();

        if ($existing !== null) {
            $existingProfile = $this->sellerProfile->findByOrganization((int) $existing['organization_id']);
            if ($existingProfile !== null) {
                throw new ValidationException([
                    'seller' => 'A seller profile already exists for this user.',
                ]);
            }
        }

        $this->connection->beginTransaction();

        try {
            $now = gmdate('Y-m-d H:i:s');

            // 1. Create organization
            (new QueryBuilder($this->connection))
                ->table('organizations')
                ->insert([
                    'type'                => 'seller',
                    'legal_name'          => trim($data['legal_name']),
                    'tax_id_ref'          => $data['tax_id_ref'] ?? null,
                    'verification_status' => 'pending_review',
                    'created_at'          => $now,
                    'updated_at'          => $now,
                ]);

            $organizationId = (int) $this->connection->lastInsertId();

            // 2. Add user as owner member
            (new QueryBuilder($this->connection))
                ->table('organization_members')
                ->insert([
                    'organization_id' => $organizationId,
                    'user_id'         => $userId,
                    'role'            => 'owner',
                    'status'          => 'active',
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);

            // 3. Create seller profile
            $profile = $this->sellerProfile->create($organizationId, trim($data['display_name']));

            $this->connection->commit();

            return [
                'organization'   => [
                    'id'                 => $organizationId,
                    'legal_name'         => $data['legal_name'],
                    'verification_status' => 'pending_review',
                ],
                'seller_profile' => $profile,
            ];
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }
    }

    /**
     * Submit verification documents for a seller profile.
     * Creates one SellerVerificationCase per document type supplied.
     *
     * @param array{type: string, document_refs: string[], provider_ref?: string}[] $submissions
     * @return SellerVerificationCase[] as arrays
     */
    public function submitDocuments(int $sellerId, array $submissions): array
    {
        $profile = $this->sellerProfile->find($sellerId);

        if ($profile === null) {
            throw new ValidationException(['seller_id' => 'Seller profile not found.']);
        }

        if ($profile['status'] === SellerProfile::STATUS_TERMINATED) {
            throw new ValidationException(['seller' => 'Terminated sellers cannot submit documents.']);
        }

        $errors = [];
        foreach ($submissions as $i => $sub) {
            if (empty($sub['type']) || !in_array($sub['type'], SellerVerificationCase::ALLOWED_TYPES, true)) {
                $errors["submissions.{$i}.type"] = 'Invalid or missing type.';
            }
            if (empty($sub['document_refs']) || !is_array($sub['document_refs'])) {
                $errors["submissions.{$i}.document_refs"] = 'At least one document reference is required.';
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $cases = [];
        foreach ($submissions as $sub) {
            $cases[] = $this->verificationCase->create(
                sellerId:    $sellerId,
                type:        $sub['type'],
                documentRefs: $sub['document_refs'],
                providerRef: $sub['provider_ref'] ?? null,
            );
        }

        return $cases;
    }

    /**
     * Return the full onboarding progress for a seller.
     *
     * @return array{
     *   profile: array,
     *   verification_cases: array,
     *   required_types: string[],
     *   missing_types: string[],
     *   all_required_passed: bool
     * }
     */
    public function getOnboardingStatus(int $sellerId): array
    {
        $profile = $this->sellerProfile->find($sellerId);

        if ($profile === null) {
            throw new ValidationException(['seller_id' => 'Seller profile not found.']);
        }

        $cases  = $this->verificationCase->listBySeller($sellerId);
        $passed = [];

        foreach ($cases as $case) {
            if ($case['result'] === SellerVerificationCase::RESULT_PASSED) {
                $passed[] = $case['type'];
            }
        }

        $missingTypes       = array_values(array_diff(self::REQUIRED_CASE_TYPES, $passed));
        $allRequiredPassed  = empty($missingTypes);

        return [
            'profile'              => $profile,
            'verification_cases'   => $cases,
            'required_types'       => self::REQUIRED_CASE_TYPES,
            'missing_types'        => $missingTypes,
            'all_required_passed'  => $allRequiredPassed,
        ];
    }

    // -------------------------------------------------------------------------
    // Compliance actions
    // -------------------------------------------------------------------------

    /**
     * Record a compliance review on a verification case.
     * If all required types now pass, automatically promote seller to ACTIVE.
     *
     * @param string $result  passed | failed | manual_review
     */
    public function review(
        int $caseId,
        string $result,
        int $reviewedBy,
        ?string $notes = null,
    ): array {
        $case = $this->verificationCase->find($caseId);

        if ($case === null) {
            throw new ValidationException(['case_id' => 'Verification case not found.']);
        }

        $this->verificationCase->review($caseId, $result, $reviewedBy, $notes);

        $updatedCase = $this->verificationCase->find($caseId);
        $sellerId    = (int) $case['seller_id'];

        // Auto-promote to ACTIVE if all required cases now pass
        if ($result === SellerVerificationCase::RESULT_PASSED) {
            $this->attemptAutoActivation($sellerId);
        }

        // If a case failed, ensure the seller is not left in ACTIVE state
        if ($result === SellerVerificationCase::RESULT_FAILED) {
            $profile = $this->sellerProfile->find($sellerId);
            if ($profile !== null && $profile['status'] === SellerProfile::STATUS_ACTIVE) {
                $this->sellerProfile->updateStatus($sellerId, SellerProfile::STATUS_PENDING);
            }
        }

        return $updatedCase ?? [];
    }

    /**
     * Suspend a seller with a mandatory reason.
     * Logs a note by inserting a new 'manual_review' verification case as an audit trail.
     */
    public function suspendSeller(int $sellerId, int $actorId, string $reason): array
    {
        $profile = $this->sellerProfile->find($sellerId);

        if ($profile === null) {
            throw new ValidationException(['seller_id' => 'Seller profile not found.']);
        }

        if ($profile['status'] === SellerProfile::STATUS_TERMINATED) {
            throw new ValidationException(['seller' => 'Cannot suspend a terminated seller.']);
        }

        $this->sellerProfile->updateStatus($sellerId, SellerProfile::STATUS_SUSPENDED);

        // Create an audit-trail case entry with the suspension reason
        $this->verificationCase->create(
            sellerId:    $sellerId,
            type:        SellerVerificationCase::TYPE_IDENTITY,
            documentRefs: [],
            providerRef: "suspension_by_user_{$actorId}",
        );

        // Immediately mark it manual_review with the reason as notes
        $auditCaseId = (int) $this->connection->lastInsertId();
        if ($auditCaseId > 0) {
            $this->verificationCase->review(
                $auditCaseId,
                SellerVerificationCase::RESULT_MANUAL_REVIEW,
                $actorId,
                "Suspension reason: {$reason}",
            );
        }

        return $this->sellerProfile->find($sellerId) ?? [];
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Check whether all required verification case types have passed.
     * If so, promote seller profile to ACTIVE.
     */
    private function attemptAutoActivation(int $sellerId): void
    {
        $profile = $this->sellerProfile->find($sellerId);

        if ($profile === null || $profile['status'] !== SellerProfile::STATUS_PENDING) {
            return;
        }

        $cases  = $this->verificationCase->listBySeller($sellerId);
        $passed = array_column(
            array_filter($cases, fn(array $c) => $c['result'] === SellerVerificationCase::RESULT_PASSED),
            'type',
        );

        $allPassed = empty(array_diff(self::REQUIRED_CASE_TYPES, $passed));

        if ($allPassed) {
            $this->sellerProfile->updateStatus($sellerId, SellerProfile::STATUS_ACTIVE);

            // Mirror on the organization
            $this->connection->execute(
                "UPDATE organizations SET verification_status = 'verified', updated_at = ? WHERE id = ?",
                [gmdate('Y-m-d H:i:s'), (int) $profile['organization_id']],
            );
        }
    }
}
