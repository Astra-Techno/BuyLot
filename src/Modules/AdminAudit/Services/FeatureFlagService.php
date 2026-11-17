<?php

declare(strict_types=1);

namespace App\Modules\AdminAudit\Services;

use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

/**
 * FeatureFlagService — simple key/value feature flag store backed by the DB.
 *
 * Flags are stored in a `feature_flags` table (expected schema):
 *
 *   CREATE TABLE feature_flags (
 *     flag       VARCHAR(100) NOT NULL,
 *     enabled    TINYINT(1)   NOT NULL DEFAULT 0,
 *     updated_by BIGINT UNSIGNED NULL,
 *     updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 *     PRIMARY KEY (flag)
 *   );
 *
 * Known flags (pre-seeded; additional flags may be added at runtime):
 *   payment_live_mode
 *   inspection_tier_b
 *   inspection_tier_c
 *   whatsapp_notifications
 *   seller_deals
 *   b2b_rfq
 *   city_{name}       — e.g. city_mumbai, city_delhi
 *   category_{name}   — e.g. category_electronics
 */
class FeatureFlagService
{
    private const TABLE = 'feature_flags';

    /** Well-known static flags. */
    public const KNOWN_FLAGS = [
        'payment_live_mode',
        'inspection_tier_b',
        'inspection_tier_c',
        'whatsapp_notifications',
        'seller_deals',
        'b2b_rfq',
    ];

    public function __construct(private readonly Connection $connection) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Check whether a feature flag is enabled.
     * Returns false for unknown / non-existent flags.
     */
    public function isEnabled(string $flag): bool
    {
        $row = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select('enabled')
            ->where('flag', $flag)
            ->first();

        return $row !== null && (bool) $row['enabled'];
    }

    /**
     * Return all feature flags with their current status.
     *
     * @return array<string, bool>  flag => enabled
     */
    public function getFlags(): array
    {
        $rows = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->select('flag', 'enabled', 'updated_at')
            ->orderBy('flag', 'ASC')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[$row['flag']] = [
                'enabled'    => (bool) $row['enabled'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $out;
    }

    /**
     * Enable or disable a feature flag.
     * Creates the row if it does not yet exist (upsert behaviour).
     *
     * @param int|null $updatedBy  User id of the admin making the change; null for system.
     */
    public function setFlag(string $flag, bool $enabled, ?int $updatedBy = null): void
    {
        $this->validateFlagName($flag);

        $now = gmdate('Y-m-d H:i:s');

        $existing = (new QueryBuilder($this->connection))
            ->table(self::TABLE)
            ->where('flag', $flag)
            ->first();

        if ($existing !== null) {
            (new QueryBuilder($this->connection))
                ->table(self::TABLE)
                ->where('flag', $flag)
                ->update([
                    'enabled'    => $enabled ? 1 : 0,
                    'updated_by' => $updatedBy,
                    'updated_at' => $now,
                ]);
        } else {
            (new QueryBuilder($this->connection))
                ->table(self::TABLE)
                ->insert([
                    'flag'       => $flag,
                    'enabled'    => $enabled ? 1 : 0,
                    'updated_by' => $updatedBy,
                    'updated_at' => $now,
                ]);
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Validate that the flag name matches the allowed pattern.
     * Allows: alphanumeric, underscores, hyphens. Max 100 chars.
     * Permits dynamic city_ and category_ prefixes.
     *
     * @throws \InvalidArgumentException on invalid flag name.
     */
    private function validateFlagName(string $flag): void
    {
        if (strlen($flag) === 0 || strlen($flag) > 100) {
            throw new \InvalidArgumentException('Feature flag name must be 1–100 characters.');
        }

        if (!preg_match('/^[a-z0-9_\-]+$/', $flag)) {
            throw new \InvalidArgumentException(
                "Feature flag name \"{$flag}\" is invalid. Use lowercase letters, digits, underscores, or hyphens.",
            );
        }
    }
}
