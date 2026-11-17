# Route Registration Example

This file shows how to register routes in your application.

## Basic Setup

Edit `public/index.php` and add your route registrations before the dispatch call:

```php
// ... after $router = new Router(); ...

// Example: Register your authentication routes
$authController = new \App\Modules\Identity\Controllers\AuthController(
    $connection,
    new \App\Core\Auth\JwtHandler(config('auth.jwt.secret')),
);

// Public routes
$router->post('/auth/register', fn(Request $req) => $authController->register($req));
$router->post('/auth/login', fn(Request $req) => $authController->login($req));

// Protected routes (with AuthMiddleware)
$router->get(
    '/auth/profile',
    fn(Request $req) => $authController->profile($req),
    [\App\Core\Auth\AuthMiddleware::class],
);

$router->put(
    '/auth/profile',
    fn(Request $req) => $authController->updateProfile($req),
    [\App\Core\Auth\AuthMiddleware::class],
);

// Health check
$router->get('/', fn(Request $req) => Response::json([
    'status' => 'ok',
    'service' => 'Collective Commerce API',
    'version' => '1.0.0',
]));

// ... then dispatch as normal ...
```

## Full Example in public/index.php

Here's what the complete entry point might look like:

```php
<?php

declare(strict_types=1);

use App\Core\Config\Config;
use App\Core\Database\Connection;
use App\Core\Exception\HttpException;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\Router;
use App\Core\Auth\AuthMiddleware;
use App\Core\Auth\JwtHandler;
use App\Modules\Identity\Controllers\AuthController;

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../vendor/autoload.php';

$basePath = dirname(__DIR__);
Config::load($basePath . '/.env', $basePath . '/config');

$dbConfig = Config::get('database.connections.mysql');
$connection = Connection::getInstance($dbConfig);

$router = new Router();

// ============ Identity Module ============
$authController = new AuthController(
    $connection,
    new JwtHandler(config('auth.jwt.secret')),
);

// Public endpoints
$router->post('/auth/register', fn(Request $req) => $authController->register($req));
$router->post('/auth/login', fn(Request $req) => $authController->login($req));

// Protected endpoints
$router->get(
    '/auth/profile',
    fn(Request $req) => $authController->profile($req),
    [AuthMiddleware::class],
);

$router->put(
    '/auth/profile',
    fn(Request $req) => $authController->updateProfile($req),
    [AuthMiddleware::class],
);

// ============ Health Check ============
$router->get('/', fn(Request $req) => Response::json([
    'status' => 'ok',
    'service' => 'Collective Commerce API',
]));

// ============ Error Handling & Dispatch ============
set_exception_handler(function(Throwable $exception): void {
    if (Config::env('APP_DEBUG', false)) {
        $response = Response::json([
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'trace' => explode("\n", $exception->getTraceAsString()),
        ], 500);
    } else {
        $statusCode = match (true) {
            $exception instanceof HttpException => $exception->getCode(),
            default => 500,
        };

        $response = Response::json(
            ['error' => $exception->getMessage()],
            $statusCode,
        );
    }

    $response->send();
    exit(1);
});

try {
    $request = Request::fromGlobals();
    $response = $router->dispatch($request);
    $response->send();
} catch (HttpException $e) {
    $response = Response::json(
        [
            'error' => $e->getMessage(),
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
```

## Testing the Routes

### Register a new user

```bash
curl -X POST http://localhost:8000/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "securepassword123",
    "role": "buyer"
  }'
```

### Login

```bash
curl -X POST http://localhost:8000/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john@example.com",
    "password": "securepassword123"
  }'
```

Response:
```json
{
  "status": 200,
  "message": "OK",
  "data": {
    "token": "eyJhbGc..."
  }
}
```

### Get Profile (Protected)

```bash
curl -X GET http://localhost:8000/auth/profile \
  -H "Authorization: Bearer eyJhbGc..."
```

## Pattern: Module Structure

Create module directories like this:

```
src/Modules/ModuleName/
├── Controllers/
│   ├── EntityController.php
│   └── OtherController.php
├── Models/
│   └── Entity.php
├── Services/
│   └── EntityService.php
├── Repositories/
│   └── EntityRepository.php
├── Events/
│   └── EntityCreated.php
└── Migrations/
    └── create_entities_table.php (optional, or use main migrations/)
```

## Pattern: Dependency Injection

Always inject dependencies in constructors:

```php
readonly class OrderController
{
    public function __construct(
        private Connection $connection,
        private EventDispatcher $events,
        private RedisCache $cache,
    ) {}

    public function create(Request $request): Response
    {
        // Use injected dependencies
        $query = new QueryBuilder($this->connection);
        // ...
    }
}
```

## Middleware Chaining

Pass multiple middleware as an array:

```php
$router->post(
    '/orders',
    fn(Request $req) => $orderController->create($req),
    [
        AuthMiddleware::class,        // Authenticate first
        RateLimitMiddleware::class,   // Rate limiting
        ValidateJsonMiddleware::class, // Validate JSON
    ],
);
```

## Error Responses

The framework automatically handles validation and HTTP errors:

```php
// Validation error (422)
throw new ValidationException([
    'email' => 'Email is invalid',
    'name' => 'Name is too short',
]);

// HTTP errors
return Response::notFound('User not found');
return Response::unauthorized('Invalid token');
return Response::forbidden('Access denied');
return Response::badRequest('Invalid request');

// Server error
return Response::serverError('Something went wrong');
```

## Tips

1. **Keep controllers thin** - Business logic goes in Service classes
2. **Use Repository pattern** - Encapsulate database queries
3. **Inject dependencies** - Don't use static calls
4. **Validate early** - Throw ValidationException for input errors
5. **Use typed responses** - Return Response objects from route handlers
6. **Protect sensitive routes** - Use AuthMiddleware for authenticated endpoints
7. **Log important events** - Use EventDispatcher to trigger logging
8. **Cache frequently accessed data** - Use RedisCache for performance
