<?php

declare(strict_types=1);

namespace App\Modules\AdminAudit\Services;

use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Modules\AdminAudit\Models\AuditLog;
use Psr\Log\LoggerInterface;
use Throwable;

class AuditService
{
    private AuditLog $auditLog;

    public function __construct(
        private readonly Connection $connection,
        private readonly ?LoggerInterface $logger = null,
    ) {
        $this->auditLog = new AuditLog($connection);
    }

    // -------------------------------------------------------------------------
    // Log
    // -------------------------------------------------------------------------

    /**
     * Create an audit log entry.
     *
     * @param int|null    $actorId      User id; null for SYSTEM/WEBHOOK actors.
     * @param string      $actorType    One of AuditLog::ACTOR_TYPE_* constants.
     * @param string      $action       Action token, e.g. "organization.created".
     * @param string|null $resourceType Entity type, e.g. "organization".
     * @param int|null    $resourceId   Entity id.
     * @param mixed       $oldValues    State before the change; null for creates.
     * @param mixed       $newValues    State after the change; null for deletes.
     * @param Request|null $request     HTTP request used to extract IP and UA.
     *
     * Returns the new audit_log id, or null on failure (non-blocking).
     */
    public function log(
        ?int $actorId,
        string $actorType,
        string $action,
        ?string $resourceType,
        ?int $resourceId,
        mixed $oldValues,
        mixed $newValues,
        ?Request $request = null,
    ): ?int {
        try {
            $data = [
                'actor_id'    => $actorId,
                'actor_role'  => $actorType,
                'action'      => $action,
                'entity_type' => $resourceType,
                'entity_id'   => $resourceId,
                'before_json' => $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_UNICODE) : null,
                'after_json'  => $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_UNICODE) : null,
                'ip_address'  => $request !== null ? $this->extractIp($request) : null,
                'user_agent'  => $request !== null ? $this->extractUserAgent($request) : null,
            ];

            return $this->auditLog->insert($data);
        } catch (Throwable $e) {
            // Audit writes must NEVER cause the originating request to fail.
            $this->logError('AuditService::log failed', $e, [
                'action'       => $action,
                'resource_type' => $resourceType,
                'resource_id'   => $resourceId,
            ]);

            return null;
        }
    }

    // -------------------------------------------------------------------------
    // Query
    // -------------------------------------------------------------------------

    /**
     * Search audit logs with pagination and filters.
     *
     * @param array{
     *   actor_id?:    int,
     *   action?:      string,
     *   entity_type?: string,
     *   entity_id?:   int,
     *   date_from?:   string,
     *   date_to?:     string,
     *   limit?:       int,
     *   offset?:      int,
     * } $filters
     *
     * @return array{logs: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function query(array $filters = []): array
    {
        return $this->auditLog->query($filters);
    }

    public function findById(int $id): ?array
    {
        return $this->auditLog->findById($id);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function extractIp(Request $request): ?string
    {
        // Trust X-Forwarded-For only in known proxy environments;
        // fall back to REMOTE_ADDR from server globals.
        $forwarded = $request->getHeader('x-forwarded-for');

        if ($forwarded !== null) {
            $ips = explode(',', $forwarded);
            $ip  = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        $realIp = $request->getHeader('x-real-ip');
        if ($realIp !== null && filter_var($realIp, FILTER_VALIDATE_IP)) {
            return $realIp;
        }

        // Fall back to server global (available during fromGlobals() flows)
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($remoteAddr !== null && filter_var($remoteAddr, FILTER_VALIDATE_IP)) {
            return $remoteAddr;
        }

        return null;
    }

    private function extractUserAgent(Request $request): ?string
    {
        $ua = $request->getHeader('user-agent');
        if ($ua === null) {
            return null;
        }

        // Truncate to match the VARCHAR(500) column length.
        return mb_substr($ua, 0, 500);
    }

    private function logError(string $message, Throwable $e, array $context = []): void
    {
        if ($this->logger !== null) {
            $this->logger->error($message, array_merge($context, [
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ]));

            return;
        }

        // Fallback: write to PHP error log so failures are never silently swallowed.
        error_log(sprintf(
            '[AuditService] %s | %s in %s:%d | context: %s',
            $message,
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            json_encode($context),
        ));
    }
}
