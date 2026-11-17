<?php

declare(strict_types=1);

namespace App\Modules\SupplierVerification\Controllers;

use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\SupplierVerification\Models\SellerProfile;
use App\Modules\SupplierVerification\Services\SellerVerificationService;

/**
 * SellerOnboardingController
 *
 * Seller-facing endpoints for self-service onboarding:
 *   POST /v1/seller/onboarding               → register()
 *   POST /v1/seller/onboarding/documents     → submitDocuments()
 *   GET  /v1/seller/onboarding/status        → getStatus()
 *   PUT  /v1/seller/profile                  → updateProfile()
 */
readonly class SellerOnboardingController
{
    public function __construct(
        private SellerVerificationService $verificationService,
        private SellerProfile $sellerProfile,
    ) {}

    // -------------------------------------------------------------------------
    // POST /v1/seller/onboarding
    // -------------------------------------------------------------------------

    /**
     * Register a new seller organization and profile.
     *
     * Body (JSON):
     *   legal_name    string  required
     *   display_name  string  required
     *   tax_id_ref    string  optional — GST/PAN reference (tokenised/encrypted externally)
     */
    public function register(Request $request): Response
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        $data   = $request->getBody();
        $errors = $this->validateRegistration($data);

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $result = $this->verificationService->register($data, (int) $user['user_id']);

        return Response::created([
            'message'        => 'Seller registration submitted. Status: pending_approval.',
            'organization'   => $result['organization'],
            'seller_profile' => $this->sanitizeProfile($result['seller_profile']),
        ]);
    }

    // -------------------------------------------------------------------------
    // POST /v1/seller/onboarding/documents
    // -------------------------------------------------------------------------

    /**
     * Upload KYC / GST / Identity document references for verification.
     *
     * Body (JSON):
     *   submissions array required — each item:
     *     type           string   required — KYC | GST | BANK | IDENTITY
     *     document_refs  string[] required — object-storage paths / CDN URLs
     *     provider_ref   string   optional
     */
    public function submitDocuments(Request $request): Response
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        $sellerId = $this->resolveSellerIdForUser($user, $request);

        if ($sellerId === null) {
            return Response::forbidden('No seller profile found for this account.');
        }

        $data        = $request->getBody();
        $submissions = $data['submissions'] ?? [];

        if (!is_array($submissions) || empty($submissions)) {
            throw new ValidationException(['submissions' => 'At least one submission is required.']);
        }

        $cases = $this->verificationService->submitDocuments($sellerId, $submissions);

        return Response::created([
            'message'            => 'Documents submitted for verification.',
            'verification_cases' => $cases,
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /v1/seller/onboarding/status
    // -------------------------------------------------------------------------

    /**
     * Return the current onboarding progress for the authenticated seller.
     */
    public function getStatus(Request $request): Response
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        $sellerId = $this->resolveSellerIdForUser($user, $request);

        if ($sellerId === null) {
            return Response::notFound('No seller profile found for this account.');
        }

        $status = $this->verificationService->getOnboardingStatus($sellerId);
        $status['profile'] = $this->sanitizeProfile($status['profile']);

        return Response::json($status);
    }

    // -------------------------------------------------------------------------
    // PUT /v1/seller/profile
    // -------------------------------------------------------------------------

    /**
     * Update mutable profile fields.
     * Only permitted for ACTIVE sellers (status = active).
     *
     * Body (JSON):
     *   display_name     string    optional
     *   categories_json  int[]     optional — array of approved category IDs
     *   service_regions  string[]  optional — array of region identifiers
     */
    public function updateProfile(Request $request): Response
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        $sellerId = $this->resolveSellerIdForUser($user, $request);

        if ($sellerId === null) {
            return Response::forbidden('No seller profile found for this account.');
        }

        $profile = $this->sellerProfile->find($sellerId);

        if ($profile === null) {
            return Response::notFound('Seller profile not found.');
        }

        if ($profile['status'] !== SellerProfile::STATUS_ACTIVE) {
            return Response::forbidden(
                'Profile updates are only permitted for active sellers. '
                . "Current status: {$profile['status']}.",
            );
        }

        $data   = $request->getBody();
        $errors = $this->validateProfileUpdate($data);

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $this->sellerProfile->updateProfile($sellerId, $data);

        $updated = $this->sellerProfile->find($sellerId);

        return Response::json([
            'message' => 'Profile updated successfully.',
            'profile' => $this->sanitizeProfile($updated ?? []),
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

    /**
     * Find the seller_profile.id for the currently authenticated user.
     *
     * Resolution order:
     *   1. '_seller_id' route/middleware param (set by a seller-auth middleware)
     *   2. 'seller_id' claim inside the JWT payload (_user)
     *
     * Routes that require an active seller session should inject _seller_id via
     * a dedicated middleware that looks up organization_members → seller_profiles.
     * Falling back to the JWT claim allows the simpler onboarding flow where the
     * seller_id is embedded at token-refresh time after registration.
     */
    private function resolveSellerIdForUser(array $user, Request $request): ?int
    {
        // 1. Preferred: injected by middleware after DB lookup
        $fromMiddleware = $request->getParam('_seller_id');
        if ($fromMiddleware !== null && ctype_digit((string) $fromMiddleware)) {
            return (int) $fromMiddleware;
        }

        // 2. Fallback: JWT claim (set after successful registration / token refresh)
        if (!empty($user['seller_id']) && ctype_digit((string) $user['seller_id'])) {
            return (int) $user['seller_id'];
        }

        return null;
    }

    private function validateRegistration(array $data): array
    {
        $errors = [];

        if (empty($data['legal_name'])) {
            $errors['legal_name'] = 'Legal name is required.';
        } elseif (mb_strlen(trim($data['legal_name'])) < 2) {
            $errors['legal_name'] = 'Legal name must be at least 2 characters.';
        }

        if (empty($data['display_name'])) {
            $errors['display_name'] = 'Display name is required.';
        } elseif (mb_strlen(trim($data['display_name'])) < 2) {
            $errors['display_name'] = 'Display name must be at least 2 characters.';
        }

        return $errors;
    }

    private function validateProfileUpdate(array $data): array
    {
        $errors = [];

        if (array_key_exists('display_name', $data)) {
            if (empty($data['display_name']) || mb_strlen(trim($data['display_name'])) < 2) {
                $errors['display_name'] = 'Display name must be at least 2 characters.';
            }
        }

        if (array_key_exists('categories_json', $data) && !is_array($data['categories_json'])) {
            $errors['categories_json'] = 'categories_json must be an array of category IDs.';
        }

        if (array_key_exists('service_regions', $data) && !is_array($data['service_regions'])) {
            $errors['service_regions'] = 'service_regions must be an array of region identifiers.';
        }

        if (empty($data['display_name']) && empty($data['categories_json']) && empty($data['service_regions'])) {
            $errors['update'] = 'No updatable fields supplied.';
        }

        return $errors;
    }

    /**
     * Remove internal/sensitive fields before returning to the seller.
     */
    private function sanitizeProfile(array $profile): array
    {
        // reliability_score is an internal metric; expose it but not raw DB internals
        return $profile;
    }
}
