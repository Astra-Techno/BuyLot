# Collective Commerce - Quick Start Guide

## Setup

### 1. Create .env file
```bash
cp .env.example .env
```

### 2. Install Dependencies
```bash
composer install
```

### 3. Run Migrations
```bash
php cli.php migrate
```

This creates the `migrations` table and runs any pending migrations (like `001_create_users_table.php`).

### 4. Start Development Server
```bash
composer serve
```

Server will be available at `http://localhost:8000`

## First Request

Visit `http://localhost:8000/` - you should get a 404 since no routes are defined.

### Register Your First Route

Edit `public/index.php` and add a route before the router dispatch:

```php
$router->get('/', fn(Request $req) => Response::json([
    'message' => 'Welcome to Collective Commerce',
    'version' => '1.0.0'
]));
```

Now visit `http://localhost:8000/` and you'll see the JSON response.

## Common Tasks

### Create a Migration

```bash
# Create file: migrations/002_create_products_table.php
```

```php
class CreateProductsTable extends Migration
{
    public function up(): void
    {
        $this->createTable('products', function($table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique();
            $table->text('description')->nullable();
            $table->decimal('price');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->dropTable('products');
    }
}
```

### Add a Route with Parameter

```php
$router->get('/products/{id}', function(Request $req) {
    $id = $req->getParam('id');

    $query = new QueryBuilder($connection);
    $product = $query->table('products')
        ->where('id', $id)
        ->first();

    if (!$product) {
        return Response::notFound('Product not found');
    }

    return Response::json($product);
});
```

### Add Authentication Middleware

```php
$router->post('/orders', [AuthMiddleware::class], function(Request $req) {
    $user = $req->getParam('_user'); // Set by AuthMiddleware
    $data = $req->getBody();

    // Create order for authenticated user
    return Response::created(['order_id' => 1]);
});
```

### Database Query Examples

```php
use App\Core\Database\QueryBuilder;

$query = new QueryBuilder($connection);

// SELECT
$users = $query->table('users')->get();

// WHERE
$admins = $query->table('users')->where('role', 'admin')->get();

// MULTIPLE CONDITIONS
$results = $query->table('orders')
    ->where('status', 'pending')
    ->where('user_id', 123)
    ->orderBy('created_at', 'DESC')
    ->get();

// INSERT
$query->table('users')->insert([
    'email' => 'user@example.com',
    'name' => 'John Doe',
    'password' => password_hash('secret', PASSWORD_BCRYPT),
]);

// UPDATE
$query->table('users')
    ->where('id', 1)
    ->update(['name' => 'Jane Doe']);

// DELETE
$query->table('users')
    ->where('id', 1)
    ->delete();
```

### Generate JWT Token

```php
use App\Core\Auth\JwtHandler;

$jwt = new JwtHandler(config('auth.jwt.secret'));

$token = $jwt->encode([
    'user_id' => $user['id'],
    'email' => $user['email'],
    'role' => $user['role'],
]);

// Return token to client
return Response::json(['token' => $token]);
```

### Use Redis Cache

```php
use App\Core\Cache\RedisCache;

$cache = new RedisCache(
    config('auth.redis.host'),
    config('auth.redis.port'),
);

// Set cache for 1 hour
$cache->set('user:' . $userId, $userData, 3600);

// Get from cache
$cached = $cache->get('user:' . $userId);

// Cache expensive operation
$data = $cache->remember('expensive_key', 3600, function() {
    return expensiveQuery();
});
```

## Running Tests

```bash
# Run all tests
composer test

# Run specific test file
php vendor/bin/phpunit tests/Unit/RouterTest.php

# Run with coverage
php vendor/bin/phpunit --coverage-html coverage
```

## Project Layout

```
BuyLot/
├── src/
│   ├── Core/           # Framework components
│   ├── Modules/        # Business modules
│   └── helpers.php     # Global helper functions
├── config/             # Configuration files
├── migrations/         # Database migrations
├── public/             # Web root (index.php entry point)
├── storage/            # Logs and uploads
├── tests/              # PHPUnit tests
├── composer.json       # Dependencies
├── cli.php             # CLI commands
└── .env                # Environment variables
```

## Module Structure (Example)

Each module in `/src/Modules` can have:

```
Modules/Orders/
├── Controllers/
│   └── OrderController.php
├── Models/
│   └── Order.php
├── Services/
│   └── OrderService.php
├── Repositories/
│   └── OrderRepository.php
├── Events/
│   └── OrderCreated.php
└── Migrations/
    └── create_orders_table.php
```

## Key Files Reference

| File | Purpose |
|------|---------|
| `src/Core/Http/Router.php` | Route registration and matching |
| `src/Core/Http/Request.php` | HTTP request wrapper |
| `src/Core/Http/Response.php` | JSON response helper |
| `src/Core/Database/Connection.php` | PDO database singleton |
| `src/Core/Database/QueryBuilder.php` | Fluent query builder |
| `src/Core/Auth/JwtHandler.php` | JWT token encoding/decoding |
| `src/Core/Cache/RedisCache.php` | Redis caching layer |
| `src/Core/Config/Config.php` | Configuration management |
| `public/index.php` | Application entry point |
| `cli.php` | CLI command runner |

## Troubleshooting

### "Route not found"
- Routes need to be registered in `public/index.php` before dispatch
- Check the HTTP method and path match exactly (case-sensitive)

### Database connection fails
- Verify `.env` database credentials
- Ensure MySQL is running
- Check database exists or is auto-created

### Redis connection fails
- Ensure Redis is running
- Check Redis host/port in `.env`
- RedisCache is optional - only needed for caching/queues

### Migrations not running
- Ensure migration class name matches file name pattern
- Namespace must be `App\Migrations\ClassName`
- File must be in `migrations/` directory

## Next Steps

1. Review `STRUCTURE.md` for detailed architecture
2. Create your first module in `/src/Modules`
3. Write migrations for your data models
4. Implement business logic and routes
5. Add tests in `/tests`
6. Deploy!
