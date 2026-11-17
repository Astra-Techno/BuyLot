<?php

declare(strict_types=1);

namespace App\Modules\Addresses\Controllers;

use App\Core\Exception\ValidationException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Addresses\Models\Address;

/**
 * AddressController — user self-service address management.
 *
 *   GET    /v1/me/addresses        → list()
 *   POST   /v1/me/addresses        → create()
 *   PUT    /v1/me/addresses/{id}   → update()
 *   DELETE /v1/me/addresses/{id}   → delete()
 *   POST   /v1/me/addresses/{id}/default  → setDefault()
 */
readonly class AddressController
{
    public function __construct(private Address $address) {}

    // -------------------------------------------------------------------------
    // GET /v1/me/addresses
    // -------------------------------------------------------------------------

    /**
     * Return all addresses owned by the authenticated user.
     */
    public function list(Request $request): Response
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        $addresses = $this->address->listByOwner(
            Address::OWNER_TYPE_USER,
            (int) $user['user_id'],
        );

        return Response::json(['data' => $addresses]);
    }

    // -------------------------------------------------------------------------
    // POST /v1/me/addresses
    // -------------------------------------------------------------------------

    /**
     * Create a new address for the authenticated user.
     *
     * Body (JSON):
     *   label        string   optional — e.g. "Home", "Office"
     *   region       string   required — city/district for demand grouping
     *   postal_code  string   required
     *   detail       string   optional — full street address; stored AES-256 encrypted
     *   is_default   bool     optional — set this as the default address
     */
    public function create(Request $request): Response
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        $data   = $request->getBody();
        $errors = $this->validateAddressData($data);

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $address = $this->address->create(
            Address::OWNER_TYPE_USER,
            (int) $user['user_id'],
            $data,
        );

        return Response::created([
            'message' => 'Address created.',
            'address' => $address,
        ]);
    }

    // -------------------------------------------------------------------------
    // PUT /v1/me/addresses/{id}
    // -------------------------------------------------------------------------

    /**
     * Update an address. Only the owner may update their own address.
     *
     * Body (JSON): (all optional; at least one required)
     *   label        string
     *   region       string
     *   postal_code  string
     *   detail       string   — stored encrypted
     *   is_default   bool
     */
    public function update(Request $request): Response
    {
        $user      = $this->resolveUser($request);
        $addressId = $this->requireIntParam($request, 'id');

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        if ($addressId === null) {
            return Response::badRequest('Missing or invalid address ID.');
        }

        if (!$this->address->isOwnedBy($addressId, Address::OWNER_TYPE_USER, (int) $user['user_id'])) {
            return Response::notFound('Address not found.');
        }

        $data   = $request->getBody();
        $errors = $this->validateAddressUpdateData($data);

        if (!empty($errors)) {
            throw new ValidationException($errors);
        }

        $success = $this->address->update($addressId, $data);

        if (!$success) {
            return Response::notFound('Address not found or no changes applied.');
        }

        $updated = $this->address->find($addressId);

        return Response::json([
            'message' => 'Address updated.',
            'address' => $updated,
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /v1/me/addresses/{id}
    // -------------------------------------------------------------------------

    /**
     * Delete an address. Only the owner may delete their own address.
     */
    public function delete(Request $request): Response
    {
        $user      = $this->resolveUser($request);
        $addressId = $this->requireIntParam($request, 'id');

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        if ($addressId === null) {
            return Response::badRequest('Missing or invalid address ID.');
        }

        if (!$this->address->isOwnedBy($addressId, Address::OWNER_TYPE_USER, (int) $user['user_id'])) {
            return Response::notFound('Address not found.');
        }

        $success = $this->address->delete($addressId);

        if (!$success) {
            return Response::notFound('Address not found.');
        }

        return Response::noContent();
    }

    // -------------------------------------------------------------------------
    // POST /v1/me/addresses/{id}/default
    // -------------------------------------------------------------------------

    /**
     * Set an address as the user's default.
     * Clears any existing default for the same owner atomically.
     */
    public function setDefault(Request $request): Response
    {
        $user      = $this->resolveUser($request);
        $addressId = $this->requireIntParam($request, 'id');

        if ($user === null) {
            return Response::unauthorized('Authentication required.');
        }

        if ($addressId === null) {
            return Response::badRequest('Missing or invalid address ID.');
        }

        if (!$this->address->isOwnedBy($addressId, Address::OWNER_TYPE_USER, (int) $user['user_id'])) {
            return Response::notFound('Address not found.');
        }

        $success = $this->address->setDefault($addressId);

        if (!$success) {
            return Response::notFound('Address not found.');
        }

        $updated = $this->address->find($addressId);

        return Response::json([
            'message' => 'Default address updated.',
            'address' => $updated,
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

    private function validateAddressData(array $data): array
    {
        $errors = [];

        if (empty($data['region'])) {
            $errors['region'] = 'region is required.';
        } elseif (mb_strlen(trim($data['region'])) > 100) {
            $errors['region'] = 'region must not exceed 100 characters.';
        }

        if (empty($data['postal_code'])) {
            $errors['postal_code'] = 'postal_code is required.';
        } elseif (!preg_match('/^\d{6}$/', (string) $data['postal_code'])) {
            $errors['postal_code'] = 'postal_code must be a 6-digit Indian PIN code.';
        }

        if (isset($data['label']) && mb_strlen((string) $data['label']) > 100) {
            $errors['label'] = 'label must not exceed 100 characters.';
        }

        return $errors;
    }

    private function validateAddressUpdateData(array $data): array
    {
        $updatable = ['label', 'region', 'postal_code', 'detail', 'is_default'];
        $supplied  = array_intersect_key($data, array_flip($updatable));

        if (empty($supplied)) {
            return ['update' => 'No updatable fields supplied.'];
        }

        $errors = [];

        if (array_key_exists('region', $data)) {
            if (empty($data['region'])) {
                $errors['region'] = 'region cannot be empty.';
            } elseif (mb_strlen(trim($data['region'])) > 100) {
                $errors['region'] = 'region must not exceed 100 characters.';
            }
        }

        if (array_key_exists('postal_code', $data)) {
            if (!preg_match('/^\d{6}$/', (string) $data['postal_code'])) {
                $errors['postal_code'] = 'postal_code must be a 6-digit Indian PIN code.';
            }
        }

        if (array_key_exists('label', $data) && mb_strlen((string) $data['label']) > 100) {
            $errors['label'] = 'label must not exceed 100 characters.';
        }

        return $errors;
    }
}
