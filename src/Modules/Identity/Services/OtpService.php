<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Core\Cache\RedisCache;
use App\Core\Database\Connection;
use App\Core\Exception\HttpException;

class OtpService
{
    private const OTP_TTL        = 300;   // 5 minutes
    private const RATE_LIMIT_MAX = 5;     // max OTPs per hour
    private const OTP_LENGTH     = 6;

    private bool $useDb;

    public function __construct(
        private RedisCache $cache,
        private ?Connection $connection = null,
    ) {
        $this->useDb = !$this->cache->isAvailable() && $this->connection !== null;

        if ($this->useDb) {
            $this->ensureOtpTable();
        }
    }

    public function generate(string $mobile): string
    {
        $this->enforceRateLimit($mobile);

        $otp = $this->makeOtp();

        if ($this->useDb) {
            $this->storeInDb($mobile, $otp);
        } else {
            $key = $this->otpKey($mobile);
            $this->cache->set($key, ['otp' => $otp, 'verified' => false], self::OTP_TTL);
        }

        return $otp;
    }

    public function verify(string $mobile, string $otp): bool
    {
        if ($this->useDb) {
            return $this->verifyFromDb($mobile, $otp);
        }

        $key  = $this->otpKey($mobile);
        $data = $this->cache->get($key);

        if (!is_array($data) || !isset($data['otp'])) {
            return false;
        }

        if (!hash_equals((string) $data['otp'], $otp)) {
            return false;
        }

        $this->cache->delete($key);
        return true;
    }

    // -------------------------------------------------------------------------
    // DB fallback (used when Redis is unavailable)
    // -------------------------------------------------------------------------

    private function ensureOtpTable(): void
    {
        $pdo = $this->connection->getPdo();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS otp_codes (
                mobile VARCHAR(15) NOT NULL,
                otp VARCHAR(6) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (mobile)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    private function storeInDb(string $mobile, string $otp): void
    {
        $pdo = $this->connection->getPdo();
        $expiresAt = gmdate('Y-m-d H:i:s', time() + self::OTP_TTL);

        $stmt = $pdo->prepare("
            REPLACE INTO otp_codes (mobile, otp, expires_at)
            VALUES (:mobile, :otp, :expires_at)
        ");
        $stmt->execute([
            'mobile'     => $mobile,
            'otp'        => $otp,
            'expires_at' => $expiresAt,
        ]);
    }

    private function verifyFromDb(string $mobile, string $otp): bool
    {
        $pdo = $this->connection->getPdo();

        $stmt = $pdo->prepare("
            SELECT otp FROM otp_codes
            WHERE mobile = :mobile AND expires_at > UTC_TIMESTAMP()
            LIMIT 1
        ");
        $stmt->execute(['mobile' => $mobile]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || !hash_equals($row['otp'], $otp)) {
            return false;
        }

        // Consume — single use
        $del = $pdo->prepare("DELETE FROM otp_codes WHERE mobile = :mobile");
        $del->execute(['mobile' => $mobile]);

        return true;
    }

    // -------------------------------------------------------------------------
    // Rate limiting & helpers
    // -------------------------------------------------------------------------

    private function enforceRateLimit(string $mobile): void
    {
        if (!$this->cache->isAvailable()) {
            return; // skip rate limiting when Redis is down
        }

        $key   = "otp_rate:{$mobile}";
        $count = $this->cache->increment($key);

        if ($count === 1) {
            $this->cache->set($key, 1, 3600);
        }

        if ((int) $count > self::RATE_LIMIT_MAX) {
            throw new HttpException('Too many OTP requests. Try again after an hour.', 429);
        }
    }

    private function makeOtp(): string
    {
        return str_pad((string) random_int(0, 999999), self::OTP_LENGTH, '0', STR_PAD_LEFT);
    }

    private function otpKey(string $mobile): string
    {
        return "otp:{$mobile}";
    }
}
