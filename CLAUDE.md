# Collective Commerce — Project Guide for Claude

## Project Overview

Collective Commerce is a B2B group-buying platform built on a custom PHP 8.3 framework (no Laravel/Symfony). Buyers pool demand into groups to meet supplier minimum-order quantities, then sealed-bid RFQs are issued to verified sellers, procurement staff negotiate and compose offers, buyers accept, orders are created, and the platform manages fulfillment, quality inspection, payment settlement, disputes, and notifications — all within a single monorepo organised as domain modules.

## Tech Stack

| Layer | Technology |
|-------|------------|
| Language | PHP 8.3 (strict types throughout) |
| HTTP kernel | Custom `App\Core\Http\{Router,Request,Response}` |
| Auth | Stateless JWT (`App\Core\Auth\JwtHandler`) + OTP via Redis |
| Database | MySQL via PDO (`App\Core\Database\Connection` — singleton) |
| Cache / queues | Redis (`ext-redis`) |
| Testing | PHPUnit 11 |
| Autoload | PSR-4 `App\` → `src/` via Composer |

No ORM — all queries are written by hand in model classes.

## How to Run

```bash
# 1. Install dependencies
composer install

# 2. Configure environment
cp .env.example .env
# Edit .env: set DB_HOST, DB_NAME, DB_USER, DB_PASS, JWT_SECRET, REDIS_* etc.

# 3. Run database migrations
php cli.php migrate

# 4. Start the development server (PHP built-in server on :8000)
composer serve
# equivalent: php -S localhost:8000 -t public
```

The entry point is `public/index.php`. All requests are routed through it.

## Module Structure

```
src/
  Core/
    Auth/          JwtHandler, AuthMiddleware
    Cache/         RedisCache
    Config/        Config (loads .env + config/*.php)
    Database/      Connection (PDO singleton)
    Exception/     HttpException
    Http/          Router, Request, Response, MiddlewareInterface
  Modules/
    Identity/          OTP auth, JWT issue, user profile, admin user management
    Organizations/     Org creation, member roles (owner/admin/member)
    AdminAudit/        Audit log viewer, platform settings, feature flags,
                       risk flags, policy versions, compliance (GDPR)
    SupplierVerification/  Seller onboarding, KYC docs, verification cases,
                           bank-account maker-checker
    Addresses/         Buyer address book (CRUD + set default)
    Catalog/           Products, variants, attributes, categories, brands
    Demand/            Buy requests, demand groups, buyer intent reconfirmation
    RFQ/               Request-for-quote lifecycle, seller invitation lists
    Bidding/           Sealed-bid submission, revisions, withdrawal,
                       confidential bid comparison for procurement staff
    ProcurementCRM/    Procurement cases, supplier leads, negotiation events,
                       offline quotes, attachments, task tracking
    Offers/            Negotiated offers, price tiers, maker-checker approval
                       workflow, stock reservations, offer acceptance
    Orders/            Buyer orders, seller fulfillment orders, commitments, MOQ
    Payments/          Payment intents, payment-gateway webhooks, refunds,
                       double-entry Ledger, seller settlements (Ledger +
                       Settlements sub-modules live inside Payments\Routes)
    Fulfillment/       Shipment dispatch, tracking updates, delivery evidence,
                       buyer acknowledgement, Quality inspection sub-module
    Disputes/          Dispute lifecycle, evidence, resolution proposals,
                       financial adjustments, appeals
    Notifications/     In-app notification inbox, channel preferences
```

Each module exposes a single `Routes::register()` static method wired in `public/index.php`.

## Key Architectural Decisions

### Sealed Bids
Bids submitted via `POST /v1/seller/rfqs/{rfqId}/bids` are stored encrypted/opaque and are not visible to buyers or other sellers. Only authenticated procurement staff with the correct role may access confidential bid data via `GET /v1/internal/rfqs/{id}/confidential-bids`. This is enforced at the middleware and service layers.

### Integer Paise (no floats for money)
All monetary values are stored and transmitted as integer paise (1 INR = 100 paise). There are no `float` or `decimal` fields for money anywhere in the codebase. Arithmetic is integer-only to eliminate floating-point rounding errors.

### Maker-Checker Approval
High-value or sensitive mutations require two distinct users: the initiator (maker) and an approver (checker) must be different accounts. This is enforced in:
- `Offers` — offer approval before publishing (`procurement_lead` or `finance_approver` approver)
- `SupplierVerification` — bank-account change requests require a second compliance admin to approve
- `Disputes` — financial adjustments above `MAKER_CHECKER_THRESHOLD_PAISE` require `approved_by != created_by`
- `Settlements` — settlement release requires a finance approver distinct from the initiator

### Stateless JWT Auth
No server-side sessions. Every request must carry a `Bearer` token. `AuthMiddleware` validates the JWT and injects the decoded user payload into request params as `_user`. Role checks are performed by `RoleMiddleware` (Identity module) or inline closures.

### Global Audit Logging
`AuditMiddleware` (from `AdminAudit`) is applied globally in `public/index.php` around the entire router dispatch. It fires for every write method (POST/PUT/PATCH/DELETE) that returns a 2xx response, writing a record to the `audit_logs` table. It is non-blocking — audit write failures are logged to `error_log` but do not fail the request. Read-only audit-log routes themselves are intentionally excluded to prevent recursive log growth.

### CORS + Rate Limiting
CORS headers are emitted before routing. OPTIONS pre-flight requests are short-circuited with HTTP 204. Per-IP rate limiting is enforced via Redis (`rate:{ip}` keys with 60-second TTL). The limit defaults to 300 requests/minute and can be overridden with `RATE_LIMIT_PER_MINUTE` in `.env`. If Redis is unavailable the limiter fails open and logs the error.

### No Framework ORM
All database access uses raw PDO queries inside model classes. Models receive a `Connection` instance and return plain arrays. There is no active-record pattern or query builder.

### Module Isolation
Each module is self-contained: its own models, services, controllers, middleware, and validators live under `src/Modules/{Name}/`. Cross-module dependencies (e.g. `Orders` using `Commitments\Services`) are explicit namespace imports — there is no service container or dependency-injection framework.

## Testing

```bash
# Run the full test suite
composer test
# equivalent: ./vendor/bin/phpunit

# Run a single test file
./vendor/bin/phpunit tests/Modules/Identity/AuthControllerTest.php

# Run a specific test method
./vendor/bin/phpunit --filter testVerifyOtp tests/Modules/Identity/AuthControllerTest.php
```

Tests live under `tests/` mirroring `src/` structure. Use `Connection::getInstance()` with a test DSN or SQLite in-memory for unit tests that touch the database. PHPUnit configuration is in `phpunit.xml` at the project root.
