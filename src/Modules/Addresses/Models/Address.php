<?php

declare(strict_types=1);

namespace App\Modules\Addresses\Models;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * Address model.
 *
 * Maps to: addresses
 *   id               BIGINT UNSIGNED PK AUTO_INCREMENT
 *   owner_type       VARCHAR(50) NOT NULL — 'user' | 'organization' | 'seller_profile'
 *   owner_id         BIGINT UNSIGNED NOT NULL
 *   label            VARCHAR(100) NULL    — e.g. "Home", "Warehouse"
 *   region           VARCHAR(100) NOT NULL — city/district used for demand grouping
 *   postal_code      VARCHAR(20) NOT NULL
 *   encrypted_detail TEXT NULL            — AES-256 encrypted full address string (see encrypt/decrypt)
 *   is_default       TINYINT(1) NOT NULL DEFAULT 0
 *   created_at       TIMESTAMP
 *   updated_at       TIMESTAMP
 *
 * Encryption:
 *   - The full street address (line1, line2, landmark, etc.) is AES-256-CBC encrypted
 *     using APP_KEY from environment before storage.
 *   - Only region and postal_code are stored in plaintext for demand-grouping queries.
 *   - The encrypted_detail field is NOT searchable by design.
 */
class Address
{
    private const TABLE = 'addresses';

    public const OWNER_TYPE_USER         = 'user';
    public const OWNER_TYPE_ORGANIZATION = 'organization';
    public const OWNER_TYPE_SELLER       = 'seller_profile';

    public const ALLOWED_OWNER_TYPES = [
        self::OWNER_TYPE_USER,
        self::OWNER_TYPE_ORGANIZATION,
        self::OWNER_TYPE_SELLER,
    ];

    /** AES-256-CBC cipher — matches openssl_encrypt/openssl_decrypt. */
    private const CIPHER = 'aes-256-cbc';

    public function __construct(private readonly Connection $connection) {}

    // -------------------------------------------------------------------------
    // Finders
    // -------------------------------------------------------------------------

    public function find(int $id): ?array
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->first();

        return $row !== null ? $this->decryptRow($row) : null;
    }

    /**
     * List all addresses for an owner, decrypted.
     *
     * @return array[]
     */
    public function listByOwner(string $ownerType, int $ownerId): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->orderBy('is_default', 'DESC')
            ->orderBy('created_at', 'DESC')
            ->get();

        return array_map(fn(array $r) => $this->decryptRow($r), $rows);
    }

    /**
     * Find the default address for an owner, or null if none set.
     */
    public function findDefault(string $ownerType, int $ownerId): ?array
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('is_default', 1)
            ->first();

        return $row !== null ? $this->decryptRow($row) : null;
    }

    // -------------------------------------------------------------------------
    // Mutations
    // -------------------------------------------------------------------------

    /**
     * Create a new address.
     *
     * @param array{
     *   label?:          string,
     *   region:          string,
     *   postal_code:     string,
     *   detail?:         string,   — plaintext full address; will be encrypted
     *   is_default?:     bool,
     * } $data
     */
    public function create(string $ownerType, int $ownerId, array $data): array
    {
        if (!in_array($ownerType, self::ALLOWED_OWNER_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid owner_type: {$ownerType}");
        }

        $isDefault = !empty($data['is_default']);

        $this->connection->beginTransaction();

        try {
            if ($isDefault) {
                $this->clearDefault($ownerType, $ownerId);
            }

            $now = gmdate('Y-m-d H:i:s');

            (new QueryBuilder($this->connection))
                ->table(self::TABLE)
                ->insert([
                    'owner_type'       => $ownerType,
                    'owner_id'         => $ownerId,
                    'label'            => $data['label'] ?? null,
                    'region'           => $data['region'],
                    'postal_code'      => $data['postal_code'],
                    'encrypted_detail' => isset($data['detail'])
                        ? $this->encrypt($data['detail'])
                        : null,
                    'is_default'       => $isDefault ? 1 : 0,
                    'created_at'       => $now,
                    'updated_at'       => $now,
                ]);

            $id = (int) $this->connection->lastInsertId();

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return $this->find($id) ?? ['id' => $id, 'owner_type' => $ownerType, 'owner_id' => $ownerId];
    }

    /**
     * Update an existing address.
     *
     * @param array{
     *   label?:       string,
     *   region?:      string,
     *   postal_code?: string,
     *   detail?:      string,   — plaintext; will be re-encrypted
     *   is_default?:  bool,
     * } $data
     */
    public function update(int $id, array $data): bool
    {
        $row = $this->find($id);

        if ($row === null) {
            return false;
        }

        $allowed  = ['label', 'region', 'postal_code', 'is_default'];
        $filtered = array_intersect_key($data, array_flip($allowed));

        if (isset($data['detail'])) {
            $filtered['encrypted_detail'] = $this->encrypt($data['detail']);
        }

        if (array_key_exists('is_default', $filtered)) {
            $filtered['is_default'] = $filtered['is_default'] ? 1 : 0;
        }

        if (empty($filtered)) {
            return false;
        }

        $filtered['updated_at'] = gmdate('Y-m-d H:i:s');

        // If setting as default, clear others first
        if (!empty($filtered['is_default'])) {
            $this->connection->beginTransaction();
            try {
                $this->clearDefault($row['owner_type'], (int) $row['owner_id']);

                $rows = (new QueryBuilder($this->connection))
                    ->table(self::TABLE)
                    ->where('id', $id)
                    ->update($filtered);

                $this->connection->commit();
                return $rows > 0;
            } catch (\Throwable $e) {
                $this->connection->rollBack();
                throw $e;
            }
        }

        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->update($filtered);

        return $rows > 0;
    }

    /**
     * Delete an address. Returns false if not found.
     */
    public function delete(int $id): bool
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->delete();

        return $rows > 0;
    }

    /**
     * Set an address as the default for its owner, clearing any existing default.
     */
    public function setDefault(int $id): bool
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->first();

        if ($row === null) {
            return false;
        }

        $this->connection->beginTransaction();

        try {
            $this->clearDefault((string) $row['owner_type'], (int) $row['owner_id']);

            (new QueryBuilder($this->connection))
                ->table(self::TABLE)
                ->where('id', $id)
                ->update([
                    'is_default'  => 1,
                    'updated_at'  => gmdate('Y-m-d H:i:s'),
                ]);

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            throw $e;
        }

        return true;
    }

    /**
     * Verify ownership of an address row.
     */
    public function isOwnedBy(int $id, string $ownerType, int $ownerId): bool
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('id', $id)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->first();

        return $row !== null;
    }

    // -------------------------------------------------------------------------
    // Encryption / Decryption
    // -------------------------------------------------------------------------

    /**
     * Encrypt the plaintext detail string using AES-256-CBC with the APP_KEY.
     * Output format: base64(iv . ':' . ciphertext)
     */
    public function encrypt(string $plaintext): string
    {
        $key = $this->derivedKey();
        $iv  = random_bytes(openssl_cipher_iv_length(self::CIPHER));

        $encrypted = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \RuntimeException('Address encryption failed.');
        }

        return base64_encode($iv . '::' . $encrypted);
    }

    /**
     * Decrypt a previously encrypted detail string.
     * Returns null if decryption fails (tampered or wrong key).
     */
    public function decrypt(string $ciphertext): ?string
    {
        $decoded = base64_decode($ciphertext, true);

        if ($decoded === false) {
            return null;
        }

        $parts = explode('::', $decoded, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$iv, $encrypted] = $parts;

        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        if (strlen($iv) !== $ivLength) {
            return null;
        }

        $key    = $this->derivedKey();
        $result = openssl_decrypt($encrypted, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        return $result !== false ? $result : null;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Derive a 32-byte key from APP_KEY (supports both raw and base64-encoded keys).
     */
    private function derivedKey(): string
    {
        $appKey = $_ENV['APP_KEY'] ?? getenv('APP_KEY') ?: '';

        if (empty($appKey)) {
            throw new \RuntimeException('APP_KEY is not set. Cannot encrypt address details.');
        }

        // If the key is prefixed "base64:" (Laravel-style), decode it
        if (str_starts_with($appKey, 'base64:')) {
            $decoded = base64_decode(substr($appKey, 7), true);
            if ($decoded !== false && strlen($decoded) >= 32) {
                return substr($decoded, 0, 32);
            }
        }

        // Derive 32 bytes deterministically from whatever the APP_KEY is
        return hash('sha256', $appKey, true);
    }

    /**
     * Remove encryption wrapper and expose 'detail' plaintext in the returned array.
     */
    private function decryptRow(array $row): array
    {
        if (!empty($row['encrypted_detail'])) {
            $row['detail']           = $this->decrypt($row['encrypted_detail']);
            $row['encrypted_detail'] = '[encrypted]'; // never expose raw ciphertext to callers
        } else {
            $row['detail'] = null;
        }

        return $row;
    }

    /**
     * Clear any existing is_default = 1 for the given owner.
     */
    private function clearDefault(string $ownerType, int $ownerId): void
    {
        (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('owner_type', $ownerType)
            ->where('owner_id', $ownerId)
            ->where('is_default', 1)
            ->update([
                'is_default'  => 0,
                'updated_at'  => gmdate('Y-m-d H:i:s'),
            ]);
    }
}
