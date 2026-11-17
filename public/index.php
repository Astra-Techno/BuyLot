<?php

declare(strict_types=1);

use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Modules\AdminAudit\Middleware\AuditMiddleware;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load configuration
$basePath = dirname(__DIR__);
Config::load($basePath . '/.env', $basePath . '/config');

// ---------------------------------------------------------------------------
// Global middleware: CORS headers
// Applied unconditionally before any routing so OPTIONS pre-flight works.
// ---------------------------------------------------------------------------

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Request-ID');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle pre-flight OPTIONS request immediately — no further processing needed.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit(0);
}

// ---------------------------------------------------------------------------
// Global middleware: simple rate limiting via Redis
// ---------------------------------------------------------------------------

$rateLimitConfig = Config::get('auth.redis', []);
try {
    if (!class_exists('Redis')) {
        throw new RuntimeException('ext-redis not installed');
    }
    $rateRedis = new Redis();
    $rateRedis->connect(
        $rateLimitConfig['host']     ?? 'localhost',
        (int) ($rateLimitConfig['port'] ?? 6379),
    );
    if (!empty($rateLimitConfig['password'])) {
        $rateRedis->auth($rateLimitConfig['password']);
    }

    $clientIp  = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $rateKey   = 'rate:' . $clientIp;
    $rateLimit = (int) Config::env('RATE_LIMIT_PER_MINUTE', 300);

    $current = (int) $rateRedis->get($rateKey);
    if ($current === 0) {
        $rateRedis->setEx($rateKey, 60, 1);
    } elseif ($current >= $rateLimit) {
        header('Content-Type: application/json');
        header('Retry-After: 60');
        http_response_code(429);
        echo json_encode(['error' => 'Too Many Requests. Limit: ' . $rateLimit . ' requests/minute.']);
        exit(1);
    } else {
        $rateRedis->incr($rateKey);
    }
} catch (Throwable $e) {
    // Rate-limit backend unavailable: fail open and log the problem.
    error_log('[RateLimit] Redis unavailable: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Initialize database
// ---------------------------------------------------------------------------

$dbConfig   = Config::get('database.connections.mysql');
$connection = Connection::getInstance($dbConfig);

// ---------------------------------------------------------------------------
// Shared JWT handler
// Built once and passed to modules that require it rather than letting each
// module instantiate its own (which is safe but wastes memory on each request).
// ---------------------------------------------------------------------------

$jwtConfig  = Config::get('auth.jwt', []);
$jwtHandler = new JwtHandler($jwtConfig['secret'] ?? 'change-me');

// ---------------------------------------------------------------------------
// Shared auth middleware (passed to modules that accept it as a parameter)
// ---------------------------------------------------------------------------

$authMiddleware = new AuthMiddleware($jwtHandler);

// ---------------------------------------------------------------------------
// Global audit middleware (applied to all write operations)
// ---------------------------------------------------------------------------

$auditMiddleware = new AuditMiddleware($connection);

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------

$router = new Router();

// ---------------------------------------------------------------------------
// Health check — no auth, no rate limit overhead, responds instantly
// ---------------------------------------------------------------------------

$router->get('/v1/health', static function (Request $req) use ($connection): Response {
    $dbOk = false;
    try {
        $connection->getPdo()->query('SELECT 1');
        $dbOk = true;
    } catch (Throwable) {
        // db down
    }

    $status = $dbOk ? 'ok' : 'degraded';
    $code   = $dbOk ? 200 : 503;

    return Response::json([
        'status'    => $status,
        'timestamp' => date('c'),
        'checks'    => [
            'database' => $dbOk ? 'ok' : 'unreachable',
        ],
    ], $code);
});

// ---------------------------------------------------------------------------
// Root welcome
// ---------------------------------------------------------------------------

$router->get('/', static fn(Request $req) => Response::json(['message' => 'Welcome to Collective Commerce']));

// ---------------------------------------------------------------------------
// Module routes
// ---------------------------------------------------------------------------

// Identity — auth, OTP, profile, admin user management
// register($router, $connection) — builds JwtHandler internally
\App\Modules\Identity\Routes::register($router, $connection);

// Organizations — org CRUD, member management
// register($router, $connection, $jwtHandler)
\App\Modules\Organizations\Routes::register($router, $connection, $jwtHandler);

// AdminAudit — audit log viewer, platform settings, feature flags, risk flags, policies, compliance
// register($router, $connection, $jwtHandler)
\App\Modules\AdminAudit\Routes::register($router, $connection, $jwtHandler);

// SupplierVerification — seller onboarding, KYC, compliance review, bank-account maker-checker
// register($router, $authMiddleware, $db)
\App\Modules\SupplierVerification\Routes::register($router, $authMiddleware, $connection);

// Addresses — buyer address book
// register($router, $authMiddleware, $db)
\App\Modules\Addresses\Routes::register($router, $authMiddleware, $connection);

// Catalog — products, categories, brands (public read + admin write)
// register($router, $connection) — builds JwtHandler internally
\App\Modules\Catalog\Routes::register($router, $connection);

// Demand — buy requests, demand groups, intent reconfirmation
// register($router, $connection) — builds JwtHandler internally
\App\Modules\Demand\Routes::register($router, $connection);

// RFQ — request-for-quote lifecycle, seller invitations
// register($router, $connection, $authMiddleware)
\App\Modules\RFQ\Routes::register($router, $connection, $authMiddleware);

// Bidding — sealed bids, bid revisions, confidential bid comparison
// register($router, $connection, $authMiddleware)
\App\Modules\Bidding\Routes::register($router, $connection, $authMiddleware);

// ProcurementCRM — cases, supplier leads, negotiation events, tasks
// register($router, $connection) — builds JwtHandler internally
\App\Modules\ProcurementCRM\Routes::register($router, $connection);

// Offers — negotiated offers, price tiers, maker-checker approval, stock reservations
// register($router, $connection) — builds JwtHandler internally
\App\Modules\Offers\Routes::register($router, $connection);

// Orders — buyer orders, seller fulfillment orders, commitments, MOQ
// register($router, $connection) — builds JwtHandler internally
\App\Modules\Orders\Routes::register($router, $connection);

// Payments — payment intents, webhooks, refunds, Ledger, Settlements
// register($router, $connection) — builds JwtHandler internally
\App\Modules\Payments\Routes::register($router, $connection);

// Fulfillment — shipments, dispatch, delivery evidence, Quality inspections
// register($router, $connection) — builds JwtHandler internally
\App\Modules\Fulfillment\Routes::register($router, $connection);

// Disputes — dispute lifecycle, evidence, resolutions, appeals
// register($router, $connection) — builds JwtHandler internally
\App\Modules\Disputes\Routes::register($router, $connection);

// Notifications — in-app notifications, preferences
// register($router, $connection, $jwtHandler)
\App\Modules\Notifications\Routes::register($router, $connection, $jwtHandler);

// ---------------------------------------------------------------------------
// Global error handler (catches exceptions thrown before dispatch reaches
// the try/catch below — e.g. during route registration).
// ---------------------------------------------------------------------------

set_exception_handler(function (Throwable $exception) use ($auditMiddleware): void {
    if (Config::env('APP_DEBUG', false)) {
        $response = Response::json([
            'exception' => get_class($exception),
            'message'   => $exception->getMessage(),
            'trace'     => explode("\n", $exception->getTraceAsString()),
        ], 500);
    } else {
        $statusCode = match (true) {
            $exception instanceof HttpException => $exception->getCode(),
            default                             => 500,
        };

        $response = Response::json(
            ['error' => $exception->getMessage()],
            $statusCode,
        );
    }

    $response->send();
    exit(1);
});

// ---------------------------------------------------------------------------
// Dispatch
// ---------------------------------------------------------------------------

try {
    $request = Request::fromGlobals();

    // Apply global AuditMiddleware around the router dispatch so that every
    // successful write operation (POST/PUT/PATCH/DELETE returning 2xx) is
    // recorded regardless of which module handled it.
    $response = $auditMiddleware->handle(
        $request,
        fn(Request $req): Response => $router->dispatch($req),
    );

    $response->send();
} catch (HttpException $e) {
    $response = Response::json(
        [
            'error'   => $e->getMessage(),
            'details' => $e->getDetails(),
        ],
        $e->getCode(),
    );
    $response->send();
    exit(1);
} catch (Throwable $e) {
    $response = Response::json(['error' => $e->getMessage()], 500);
    $response->send();
    exit(1);
}
