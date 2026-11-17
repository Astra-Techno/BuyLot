# Collective Commerce - Project Structure

## Overview

Collective Commerce is a custom PHP 8.3 backend platform for managing B2B commerce operations. This is a modular, non-Laravel architecture built with clean PHP practices.

## Directory Structure

### `/src`
Core application code using PSR-4 autoloading.

#### `/src/Core`
Framework components and abstractions:

- **Http/**
  - `Request.php` - PSR-7 style HTTP request wrapper
  - `Response.php` - JSON response helper with status codes
  - `Router.php` - Regex-based router supporting GET/POST/PUT/DELETE/PATCH with parameter extraction
  - `Middleware/` - Middleware pipeline and interface

- **Database/**
  - `Connection.php` - PDO singleton with MySQL support, charset=utf8mb4
  - `QueryBuilder.php` - Fluent query builder with WHERE, JOIN, ORDER BY, GROUP BY
  - `Migration.php` - Abstract migration base class
  - `TableBuilder.php` - Fluent table definition builder
  - `MigrationRunner.php` - Runs and tracks migrations in migrations table

- **Config/**
  - `Config.php` - Loads .env variables and config array files

- **Event/**
  - `EventDispatcher.php` - Simple event dispatcher
  - `EventInterface.php` - Event interface

- **Cache/**
  - `RedisCache.php` - Redis wrapper with get/set/delete, TTL support, remember()

- **Queue/**
  - `QueueInterface.php` - Queue contract
  - `RedisQueue.php` - Redis-backed queue implementation

- **Auth/**
  - `JwtHandler.php` - JWT encode/decode with HMAC-SHA256
  - `AuthMiddleware.php` - Validates JWT tokens and sets user context

- **Exception/**
  - `HttpException.php` - HTTP exception with status codes
  - `ValidationException.php` - 422 validation errors

#### `/src/Modules`
Business logic modules (each is a container for controllers, models, services):

- `Identity/` - User authentication, registration, profiles
- `Organizations/` - Company management, hierarchies
- `Catalog/` - Product and service catalog
- `Demand/` - Buyer demand management
- `RFQ/` - Request for Quotation workflow
- `Bidding/` - Supplier bidding system
- `ProcurementCRM/` - Procurement relationship management
- `SupplierVerification/` - Supplier verification and ratings
- `Offers/` - Offer management and negotiation
- `Commitments/` - Purchase commitments and planning
- `Orders/` - Order creation and management
- `Payments/` - Payment processing and invoicing
- `Ledger/` - Financial ledger and accounting
- `Settlements/` - Settlement and reconciliation
- `Fulfillment/` - Order fulfillment and shipping
- `Quality/` - Quality assurance and inspection
- `Disputes/` - Dispute resolution
- `Notifications/` - Email, SMS, push notifications
- `Analytics/` - Reporting and analytics
- `AdminAudit/` - Admin actions and audit logging

### `/config`
Configuration files:

- `app.php` - Application settings (name, env, debug, timezone)
- `database.php` - Database connections and credentials
- `auth.php` - Authentication settings (JWT secret, Redis config)

### `/migrations`
Database migrations using the Migration system:
- Naming: `001_description.php`, `002_another.php`, etc.
- Tracks executed migrations in `migrations` table
- Supports rollback by steps

### `/public`
Web root - only exposed directory:

- `index.php` - Entry point that bootstraps app, creates router, dispatches requests
- `.htaccess` - Apache rewrite rules for clean URLs

### `/storage`
Application generated files:

- `logs/` - Application logs
- `uploads/` - User uploaded files

### `/tests`
Test suite using PHPUnit 11:

- `Unit/` - Unit tests
- `Integration/` - Integration tests

### Root Files

- `composer.json` - PHP dependencies and autoloading
- `.env.example` - Environment variables template
- `.gitignore` - Git ignore rules
- `cli.php` - CLI command runner (migrate, rollback)
- `src/helpers.php` - Global helper functions (env(), config())
- `src/Core/Bootstrap.php` - Bootstrap utilities

## Configuration

### Environment Variables (.env)

Required variables:
```
APP_NAME=Collective Commerce
APP_ENV=development
APP_DEBUG=true

DB_HOST=localhost
DB_PORT=3306
DB_NAME=collective_commerce
DB_USER=root
DB_PASS=

REDIS_HOST=localhost
REDIS_PORT=6379

JWT_SECRET=your-secret-key
```

## Running Migrations

```bash
php cli.php migrate          # Run all pending migrations
php cli.php rollback         # Rollback last migration
php cli.php rollback 2       # Rollback last 2 migrations
```

## Example Usage

### Defining Routes

In `public/index.php` or a routes file:

```php
$router->get('/', fn(Request $req) => Response::json(['message' => 'Welcome']));

$router->get('/users/{id}', function(Request $req) {
    $userId = $req->getParam('id');
    return Response::json(['id' => $userId]);
});

$router->post('/users', [AuthMiddleware::class], function(Request $req) {
    $data = $req->getBody();
    return Response::created(['id' => 1]);
});
```

### Database Queries

```php
use App\Core\Database\Connection;
use App\Core\Database\QueryBuilder;

$connection = Connection::getInstance($config);
$query = new QueryBuilder($connection);

$users = $query
    ->table('users')
    ->where('role', 'admin')
    ->orderBy('created_at', 'DESC')
    ->get();

$user = $query
    ->table('users')
    ->where('id', $userId)
    ->first();

$query->table('users')->insert(['name' => 'John', 'email' => 'john@example.com']);
```

### Writing Migrations

```php
class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->createTable('users', function($table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->dropTable('users');
    }
}
```

### Authentication with JWT

```php
use App\Core\Auth\JwtHandler;

$jwt = new JwtHandler($secret);
$token = $jwt->encode(['user_id' => 1, 'email' => 'user@example.com']);

// Validate in middleware
$payload = $jwt->decode($token);
```

### Caching with Redis

```php
use App\Core\Cache\RedisCache;

$cache = new RedisCache('localhost', 6379);
$cache->set('user:1', $userData, 3600); // 1 hour TTL
$user = $cache->get('user:1');

$data = $cache->remember('expensive_key', 3600, fn() => expensive_operation());
```

## Running Tests

```bash
composer test                    # Run all tests
php vendor/bin/phpunit tests/Unit/RouterTest.php  # Run specific test
```

## Development Server

```bash
composer serve  # Runs on localhost:8000
```

## Key Features

- **Strict Types**: All files declare strict_types=1
- **PHP 8.3 Enums & Named Arguments**: Used throughout
- **Readonly Properties**: Immutable data objects
- **Match Expressions**: For clean conditionals
- **PDO with Exception Mode**: Proper error handling
- **PSR-4 Autoloading**: Organized namespaces
- **Middleware Pipeline**: Chain middleware with fluent interface
- **Fluent Query Builder**: Chain queries intuitively
- **Migration System**: Track and rollback DB changes
- **JWT Authentication**: Stateless token-based auth
- **Redis Integration**: Caching and queues

## Next Steps

1. Copy `.env.example` to `.env` and configure
2. Run `composer install`
3. Run `php cli.php migrate` to create tables
4. Create module controllers in `/src/Modules`
5. Register routes in `public/index.php`
6. Start development with `composer serve`
