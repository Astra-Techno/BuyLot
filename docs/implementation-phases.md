# Implementation Phases — Collective Commerce

**Source of truth:** `Collective_Commerce_Complete_Business_Blueprint.md` v1.0 (17 Nov 2026)
**Key constraints (Section 25):** Custom PHP 8.3 (no Laravel), Vue 3 + TypeScript + Vite + Pinia + Tailwind, MySQL 8 + InnoDB, Redis, mobile-first PWA, integer paise, OpenAPI contract before frontend integration, immutable commercial snapshots, sealed-bid access strictly enforced, feature flags, no mock gateway behavior represented as production.

---

## Reading guide

Each milestone entry follows this structure:

| Field | Meaning |
|---|---|
| **Deliverables** | Migrations, APIs, UI components and test suites that must exist |
| **Depends on** | Milestone IDs that must be complete before work begins |
| **Rollback strategy** | How to revert without corrupting data or leaving the system in an inconsistent state |
| **Definition of done** | Verifiable, binary acceptance criteria |
| **Complexity** | S = 1–2 days, M = 3–5 days, L = 1–2 weeks, XL = 2–4 weeks |

---

## Phase 1 — Core Foundation

**Phase goal:** A running, secure, tested skeleton that every later module can be bolted onto. No business logic beyond identity and authorization.

**Phase gate:** No cross-tenant data leaks confirmed by automated tests; staff MFA enforced; full permission matrix covered by tests; CI pipeline green.

---

### M1.1 — PHP project scaffolding, Composer, PSR-4 autoloading, directory structure

**Deliverables:**
- Repository initialized; `.gitignore`, `.editorconfig`, `composer.json` with PSR-4 autoload map.
- Directory skeleton: `src/` (modules), `config/`, `database/migrations/`, `database/seeds/`, `public/` (single entry point `index.php`), `tests/` (unit, integration, feature), `storage/`, `bin/` (CLI runner).
- Composer scripts: `test`, `cs-fix`, `static-analysis`.
- PHP 8.3 strict-types enforced project-wide; `declare(strict_types=1)` in all files.
- `README.md` with local setup instructions (PHP version, extensions, env vars).
- `.env.example` with all required keys; no secrets committed.
- Minimal bootstrap: environment loader, service container (hand-rolled or Pimple), config reader.
- Nginx + PHP-FPM local dev config documented.

**Depends on:** nothing

**Rollback strategy:** Delete the repository and restart from scratch. No data exists yet.

**Definition of done:**
- `composer install` completes with zero errors on a clean machine.
- `php bin/console` prints a help stub.
- PHPUnit runs and reports 0 tests, 0 failures.
- Static analysis passes at the configured strictness level.
- A second developer can clone and reach a running 200 response from `GET /health` with no manual steps beyond `cp .env.example .env`.

**Complexity:** S

---

### M1.2 — Custom migration framework and runner

**Deliverables:**
- `bin/migrate` CLI: `up`, `down`, `status`, `fresh` (dev only), `seed` subcommands.
- `migrations` table: `id`, `filename`, `batch`, `executed_at`.
- Each migration is a PHP class with `up()` and `down()` methods; DDL executed in a transaction where MySQL allows it.
- Naming convention: `YYYYMMDD_HHMMSS_description.php`.
- Seed classes behind a `--env=local` guard; seeds never run in production automatically.
- Tests: run a migration up and down; assert `migrations` table state; assert idempotency (running `up` twice is safe).

**Depends on:** M1.1

**Rollback strategy:** Run `bin/migrate down --batch=N` to reverse the last batch. Each migration's `down()` must be implemented and tested. For destructive schema changes in production, a pre-migration backup snapshot is required before running `up`.

**Definition of done:**
- All subcommands covered by integration tests against a real MySQL test database.
- `fresh` is blocked when `APP_ENV=production`.
- Running `up` on an already-migrated state produces no error and no duplicate entry.

**Complexity:** S

---

### M1.3 — HTTP router, middleware pipeline (auth, CORS, rate-limit, audit)

**Deliverables:**
- Lightweight router: attribute-based or config-based route registration; supports route groups, prefix, named routes.
- Request/response objects (PSR-7 compatible or thin wrappers).
- Middleware pipeline: ordered, composable.
- **CORS middleware:** configurable allowed origins, methods, headers; preflight handled.
- **Rate-limit middleware:** per-IP and per-user-token sliding-window counts stored in Redis; configurable per route group; returns `429` with `Retry-After`.
- **Auth middleware:** JWT extraction and validation (see M1.4 for token issuance); attaches `AuthContext` to request; unauthenticated returns `401`; insufficient role returns `403`.
- **Audit middleware:** records every mutating request (method, path, actor, tenant, IP, timestamp, request-id) to `audit_logs` table; fires after response so it does not block the response path.
- Global error handler: maps domain exceptions to structured JSON errors with `error_code`, `message`, `request_id`; never leaks stack traces to clients.
- `GET /health` and `GET /version` endpoints (no auth).
- OpenAPI stub document (`docs/api-openapi.yaml`) with info, servers and security scheme declared.

**Depends on:** M1.1, M1.2

**Rollback strategy:** Routing and middleware are stateless code changes. Revert via git. The `audit_logs` table data is append-only and does not need rollback.

**Definition of done:**
- Feature tests: authenticated request reaches handler; unauthenticated request returns `401`; rate-limit trips at configured threshold and resets after window; CORS preflight returns correct headers; unknown route returns structured `404`.
- Audit log entry exists after every mutating request in tests.
- Stack traces absent from all error responses in test assertions.

**Complexity:** M

---

### M1.4 — Identity module (users, OTP, JWT)

**Deliverables:**
- Migrations: `users`, `otp_requests`, `refresh_tokens`.
  - `users(id ULID, mobile VARCHAR(20) UNIQUE, email VARCHAR(255) UNIQUE NULLABLE, status ENUM, locale VARCHAR(10), verified_at, created_at, updated_at)`.
  - `otp_requests(id, user_id NULLABLE, mobile, purpose ENUM, code_hash, expires_at, used_at, attempt_count, ip)`.
  - `refresh_tokens(id, user_id, token_hash, expires_at, revoked_at, ip, user_agent)`.
- APIs:
  - `POST /v1/auth/otp/request` — rate-limited per mobile+IP; generates 6-digit OTP; sends via SMS provider (stubbed behind interface); stores hash not plaintext.
  - `POST /v1/auth/otp/verify` — validates hash + expiry + attempt count; on success issues short-lived JWT access token (15 min) and long-lived refresh token (30 days); marks OTP used.
  - `POST /v1/auth/token/refresh` — validates refresh token; issues new access token; rotates refresh token.
  - `POST /v1/auth/logout` — revokes refresh token.
  - `GET /v1/me` — returns authenticated user profile.
  - `PATCH /v1/me` — update locale/email (email re-verification deferred to later).
- JWT payload: `sub` (user ULID), `iat`, `exp`, `jti`, `scope` (user role list); signed HS256 with rotating secret or RS256.
- OTP: 6-digit; hashed with bcrypt/argon2; max 5 attempts; 10-minute expiry; SMS provider interface with a no-op local driver.
- Staff MFA: staff roles require TOTP second factor (TOTP library integration; backup codes stored hashed).
- `DELETE /v1/me/account` — initiates controlled deletion workflow (marks status `DELETION_REQUESTED`; actual purge deferred subject to retention policy).
- Tests: OTP flow end-to-end; replay attack blocked (used OTP rejected); expired OTP rejected; brute-force lockout; JWT expiry; refresh rotation; staff TOTP enforced.

**Depends on:** M1.2, M1.3

**Rollback strategy:** Reverse migration drops `users`, `otp_requests`, `refresh_tokens`. All tokens become invalid immediately. No financial data exists yet.

**Definition of done:**
- Full OTP → JWT → refresh → logout cycle passes automated feature tests.
- OTP plaintext never appears in database, logs or API responses.
- Staff endpoint returns `403` without valid TOTP when MFA is configured.
- Penetration: IDOR on `/v1/me` impossible (token binding verified in tests).

**Complexity:** M

---

### M1.5 — Organizations and multi-tenant scoping

**Deliverables:**
- Migrations: `organizations`, `organization_members`, `addresses`.
  - `organizations(id, type ENUM(BUYER_ORG, SELLER_ORG, INTERNAL), legal_name, tax_id_ref, verification_status, created_at, updated_at)`.
  - `organization_members(id, organization_id, user_id, role, status, invited_by, joined_at)`.
  - `addresses(id, owner_type, owner_id, region_id, postal_code, encrypted_detail, is_default, created_at)`.
- APIs:
  - `POST /v1/organizations` — create org (buyer/seller).
  - `GET /v1/organizations/{id}` — own org only (tenant scope enforced).
  - `POST /v1/organizations/{id}/members` — invite member.
  - `GET /v1/organizations/{id}/members` — list members.
  - `DELETE /v1/organizations/{id}/members/{userId}` — remove member.
  - `POST /v1/me/addresses`, `GET /v1/me/addresses`, `DELETE /v1/me/addresses/{id}`.
- **Tenant scoping helper:** `TenantScope` class injected into every repository; all queries automatically filter by `organization_id` or `user_id` depending on context; scope must be explicitly bypassed (and audited) for internal/admin use.
- Cross-tenant test suite: User A from Org 1 cannot read, list or modify any resource belonging to Org 2 through any endpoint.
- PII: `encrypted_detail` column uses application-level AES-256-GCM encryption with key from secrets store.

**Depends on:** M1.4

**Rollback strategy:** Reverse migrations drop organization tables. Users table is preserved. No tenanted business data exists yet.

**Definition of done:**
- Automated cross-tenant isolation tests: 10 scenarios minimum covering read, write, list and delete across two separate organizations.
- Address `encrypted_detail` is ciphertext in the database (verified by direct DB assertion in tests).
- No raw address detail appears in any log line (asserted via log capture in tests).

**Complexity:** M

---

### M1.6 — RBAC + ABAC authorization engine

**Deliverables:**
- Migrations: `roles`, `permissions`, `role_permissions`, `user_role_assignments`.
- Seed: canonical roles from Section 2 (Guest, Buyer, BusinessBuyer, Seller, ProcurementAgent, ProcurementLead, QualityInspector, OperationsAgent, FinanceOperator, FinanceApprover, SupportDisputes, ComplianceAdmin, SuperAdmin).
- Seed: canonical permissions set (resource × action tuples; e.g., `bids.read.own`, `bids.read.confidential`, `settlements.approve`, `seller_bank_accounts.update`).
- `AuthorizationService::can(AuthContext $ctx, string $permission, ?Model $resource): bool`:
  - RBAC layer: checks `user_role_assignments → role_permissions`.
  - ABAC layer: evaluates ownership (`$resource->user_id === $ctx->userId`), organization scope, assignment scope (e.g., procurement agent assigned to case), amount thresholds, environment.
  - **Explicit deny rules:** sellers denied `bids.read.confidential`; buyers denied `procurement.negotiation.read`; no user may `settlements.self_approve`.
- Policy classes per domain (e.g., `BidPolicy`, `SettlementPolicy`) implementing the `can()` contract; used in every controller and background job.
- Middleware integration: auth middleware calls `AuthorizationService` for route-level permission checks; controller methods call policy classes for resource-level checks.
- `GET /v1/internal/roles`, `GET /v1/internal/permissions`, `POST /v1/internal/roles/{id}/permissions` (SuperAdmin only).
- Tests: permission matrix spreadsheet (from `docs/permissions-matrix.md`) encoded as parameterized tests; every role × sensitive permission combination asserted.

**Depends on:** M1.5

**Rollback strategy:** Permission data is seeded and version-controlled. Rolling back a permissions change means re-running the previous seed or applying a corrective migration. Code rollback via git reverts policy classes.

**Definition of done:**
- Every row in the permissions matrix has a corresponding passing test.
- Seller cannot access confidential bid data via any endpoint: verified by integration tests hitting real HTTP routes.
- Self-approval of financial adjustments is blocked at the authorization layer (not just UI): verified by test.
- SuperAdmin actions are logged to `audit_logs` with actor identity.

**Complexity:** L

---

### M1.7 — Audit logging middleware and service

**Deliverables:**
- Migration: `audit_logs(id, event_type, actor_id NULLABLE, actor_role, organization_id NULLABLE, resource_type, resource_id, ip, user_agent, request_id, payload_summary_json, created_at)`. Append-only; no UPDATE or DELETE permitted on this table (MySQL trigger or application-level guard).
- `AuditService::log(AuditEvent $event): void` — writes synchronously for high-sensitivity events (financial approvals, permission grants, seller bank changes); writes via outbox/queue for routine request logging.
- High-sensitivity events requiring synchronous audit: seller bank account change, settlement approval, offer approval, role assignment change, SuperAdmin action, bid confidential read.
- Audit middleware (wired in M1.3) calls `AuditService` after response flush for all mutating requests.
- `GET /v1/internal/audit` — paginated, filterable by actor/resource/event type/date range; ComplianceAdmin + SuperAdmin only.
- Log export: CSV export with date range; behind `audit.export` permission.
- Tests: audit entry created after each sensitive operation; direct-DB assertion that no audit row has been modified; export returns correct filtered results.

**Depends on:** M1.3, M1.6

**Rollback strategy:** Audit logs are intentionally irrevocable. Code changes (e.g., changing what is logged) are deployed forward. Schema rollback drops the table but this is only acceptable before any production data exists.

**Definition of done:**
- Every high-sensitivity event in the list above produces a corresponding `audit_logs` row in automated tests.
- A MySQL trigger test confirms no `UPDATE`/`DELETE` succeeds on `audit_logs`.
- Audit endpoint returns `403` for a Seller role (verified in tests).

**Complexity:** M

---

### M1.8 — Seller profiles and verification

**Deliverables:**
- Migrations: `seller_profiles`, `seller_verification_cases`, `seller_bank_accounts`.
  - `seller_profiles(id, organization_id, categories_json, service_regions_json, score DECIMAL, status ENUM(PENDING, UNDER_REVIEW, APPROVED, SUSPENDED, REJECTED), created_at, updated_at)`.
  - `seller_verification_cases(id, seller_profile_id, submitted_docs_ref, provider_ref NULLABLE, result ENUM, reviewed_by, reviewed_at, notes)`.
  - `seller_bank_accounts(id, seller_profile_id, provider_token, account_name, verified_at, status, changed_by, previous_token_ref)`. Raw bank numbers never stored; only provider tokenized reference.
- APIs (Seller-facing):
  - `POST /v1/seller/onboarding` — submit KYC/GST/bank details; creates org + seller profile + verification case.
  - `GET /v1/seller/profile` — own profile.
  - `PATCH /v1/seller/profile` — update categories/regions (re-triggers review if sensitive fields change).
  - `POST /v1/seller/bank-accounts` — add new bank account (triggers dual-approval workflow from M1.6).
- APIs (Internal):
  - `GET /v1/internal/seller-verifications` — ComplianceAdmin queue.
  - `POST /v1/internal/seller-verifications/{id}/approve` / `reject` — maker-checker: different staff member from submitter.
  - `POST /v1/internal/seller-profiles/{id}/suspend`.
- Bank account change: requires a second approver (not the same user who initiated); cooling-off period configurable via feature flag; full audit trail.
- Document uploads: stored in object storage with private signed URLs; virus scan hook (interface; no-op driver for dev).
- Tests: seller cannot see another seller's profile; bank change blocked without dual approval; suspended seller blocked from bidding.

**Depends on:** M1.5, M1.6, M1.7

**Rollback strategy:** Reverse migrations. Verification case documents in object storage are cleaned up by a separate purge job; the job is idempotent.

**Definition of done:**
- Onboarding flow reaches `UNDER_REVIEW` state via API test.
- Bank account update blocked when second approver is the same user as requester (automated test).
- Suspended seller's JWT returns `403` on seller bid submission endpoint (even though bidding is built later, the authorization check is wired now).

**Complexity:** M

---

### M1.9 — Redis integration (cache, queue, locks)

**Deliverables:**
- Redis connection pool with configurable `read_timeout`, `connection_timeout`, retry.
- `CacheService` wrapping Redis with TTL, namespaced keys, tag-based invalidation.
- `QueueService`: push/pop job envelope (JSON payload + attempt count + max retries + delay); dead-letter queue after max retries; worker CLI `bin/worker --queue=default`.
- `LockService`: `acquireLock(string $key, int $ttlMs, string $token): bool` / `releaseLock(string $key, string $token): bool` using Redis SET NX + Lua release script; used for concurrency-safe stock reservations (M3), MOQ threshold checks (M3) and idempotency (M4).
- Job base class: `handle()`, `failed()`, `shouldRetry(): bool`; jobs must be idempotent (documented contract).
- Worker: graceful shutdown on SIGTERM; heartbeat key in Redis for health monitoring.
- Tests: lock acquired/released correctly; second lock attempt on same key returns false; job pushed and consumed; dead-letter after max retries; cache set/get/invalidate.

**Depends on:** M1.1

**Rollback strategy:** Redis is stateless for queue/cache purposes. Flush Redis keys for the application namespace. No persistent business data lives in Redis only (all durable state is in MySQL).

**Definition of done:**
- Lock acquisition under concurrent goroutine-style test (two PHP processes racing) confirms only one acquires.
- Job handler called exactly once per pushed job in integration test (worker run inline in tests).
- Worker exits cleanly on SIGTERM without dropping in-flight job.

**Complexity:** M

---

### M1.10 — Vue 3 + Vite + Tailwind + Pinia frontend shells

**Deliverables:**
- Three separate Vite projects (or Vite monorepo with workspaces):
  - `frontend/buyer-pwa` — Buyer-facing PWA (mobile-first, installable, offline shell).
  - `frontend/seller-portal` — Seller web portal.
  - `frontend/ops-portal` — Internal operations portal.
- Each project: Vue 3 + TypeScript strict mode, Vite, Tailwind CSS (design tokens in `tailwind.config.ts`), Pinia store scaffold, Vue Router with lazy-loaded route chunks, Axios HTTP client with JWT interceptor + 401 refresh logic.
- Shared package `frontend/shared`: TypeScript API types auto-generated from OpenAPI stub (M1.3), Pinia auth store (login, refresh, logout), base form/input/button components, toast notification service, error boundary component.
- Authentication flow wired: OTP request → verify → store token → redirect to dashboard; logout clears store + cookie.
- PWA manifest and service worker (Vite PWA plugin): offline shell page, background sync stub.
- Tailwind: mobile-first breakpoints, accessible color contrast (WCAG AA minimum), large tap targets (44px min).
- Storybook or equivalent component catalogue for shared components.
- Vitest unit tests for Pinia stores; Playwright stub for E2E (one smoke test per portal: login → dashboard renders).
- CI: `npm run build` succeeds for all three projects; Vitest passes; Playwright smoke passes against a local dev server.

**Depends on:** M1.3, M1.4

**Rollback strategy:** Frontend is independently deployable. Roll back by redeploying the previous build artifact. No database state is owned by the frontend.

**Definition of done:**
- All three portals build with zero TypeScript errors.
- OTP login flow works end-to-end against the real API in a local integration test.
- Lighthouse PWA score >= 90 for buyer PWA (CI artifact).
- 401 refresh loop does not spin infinitely: verified by Playwright test (revoked token causes redirect to login, not an infinite loop).

**Complexity:** L

---

### M1.11 — CI/CD pipeline and automated tests

**Deliverables:**
- CI pipeline (GitHub Actions or equivalent): triggered on push to any branch and on PR to `main`.
  - Jobs: `lint` (PHP CS Fixer, ESLint), `static-analysis` (PHPStan level 8+), `unit-tests` (PHPUnit, isolated, no DB), `integration-tests` (PHPUnit, MySQL + Redis test containers), `frontend-build` (all three Vite builds), `frontend-unit` (Vitest), `e2e-smoke` (Playwright).
  - Test database provisioned via Docker Compose service in CI; migrations run fresh before integration tests.
  - Code coverage report uploaded as artifact; minimum 80% line coverage gate on the `src/` core modules.
- CD pipeline (deploy to staging): triggers on merge to `main`; zero-downtime deploy (PHP-FPM reload, run migrations, symlink release); rollback step (repoint symlink to previous release, reload FPM) documented and tested.
- Secrets managed via CI secrets store; never in repository.
- Branch protection: `main` requires passing CI and one code review.
- `CHANGELOG.md` convention documented.

**Depends on:** M1.1 through M1.10

**Rollback strategy:** CD rollback job repoints the active release symlink to the previous release directory and reloads PHP-FPM. Database rollback (if a migration was applied) requires running `bin/migrate down --batch=N` manually, documented in the runbook.

**Definition of done:**
- A PR that introduces a failing PHPUnit test causes the CI pipeline to block merge.
- A PR that introduces a TypeScript error causes the CI pipeline to block merge.
- Staging deploy triggered by a merge to `main` completes without manual intervention.
- Rollback to the previous staging release completes in under 2 minutes (timed).

**Complexity:** M

---

## Phase 2 — Demand and Sealed Procurement

**Phase goal:** Buyers can create and join buying requests; demand is aggregated into groups; RFQs are published; sellers submit sealed bids; procurement agents source offline; negotiation is logged; offers reach the approval stage.

**Phase gate:** Seller cannot discover a competing bid value, identity or ranking through any channel — API, export, event payload, notification, log line or admin screen accessible to seller role. Verified by automated penetration-style tests.

---

### M2.1 — Catalog: categories, brands, products, variants, attributes

**Deliverables:**
- Migrations: `categories` (tree, closure table), `brands`, `products`, `product_variants`, `product_attributes`, `variant_attribute_values`.
- Seed: initial category tree for the pilot product category.
- APIs (public):
  - `GET /v1/catalog/categories` — tree.
  - `GET /v1/catalog/products` — paginated, filterable by category/brand/region.
  - `GET /v1/catalog/products/{id}` — with variants and attribute matrix.
  - `GET /v1/catalog/search` — full-text search over product name + brand + attributes (MySQL FULLTEXT or Redis-backed index).
- APIs (internal/admin): CRUD for categories, brands, products, variants (ComplianceAdmin).
- OpenAPI: all catalog endpoints documented with request/response schemas.
- UI (Buyer PWA): browse categories → product list → product detail with variant selector.
- UI (Ops Portal): product management screens (create/edit/publish/archive).
- Tests: search returns correct variants; inactive product not visible to buyers; variant attribute combination uniqueness enforced.

**Depends on:** M1.5, M1.6, M1.10, M1.11

**Rollback strategy:** Reverse catalog migrations. Product data is reference data with no financial linkage yet. Object storage images can be retained or purged independently.

**Definition of done:**
- Product search returns results in under 200ms for a 1,000-product seed dataset (measured in integration test).
- Inactive/draft product returns `404` for unauthenticated buyer request (test).
- Variant attribute matrix enforces uniqueness constraint (database constraint test).

**Complexity:** M

---

### M2.2 — Buy requests and demand groups

**Deliverables:**
- Migrations: `buy_requests`, `buyer_price_preferences`, `demand_groups`, `group_memberships`, `group_intent_events`, `group_compatibility_rules`.
- Group matching: background job runs after each new `buy_request`; matches on `(variant_id, region_id, purchase_by window)` with compatible substitute rules; creates or joins an existing `demand_group`; logs compatibility decisions.
- Intent levels: `L0` through `L4` as defined in Section 4.2; transitions logged in `group_intent_events`; only `L4` (paid) counts toward MOQ.
- APIs (Buyer):
  - `POST /v1/buy-requests` — create; triggers matching job.
  - `GET /v1/buy-requests/{id}` — own only.
  - `GET /v1/groups/{id}` — public aggregate counts (interested_qty, ready_qty only; no individual budgets).
  - `POST /v1/groups/{id}/join` — join with intent level.
  - `POST /v1/groups/{id}/reconfirm` — upgrade to `L2` purchase-ready.
  - `DELETE /v1/groups/{id}/leave`.
  - `GET /v1/me/requests` — own requests and group memberships.
- Buyer budget privacy: `buyer_price_preferences.max_paise` has `visibility_scope = INTERNAL_ONLY`; API serializer for buyer-facing routes must never return `max_paise`; assertion in OpenAPI contract and test suite.
- Aggregate demand stats exposed publicly: interested count, ready count, region, variant — no individual data.
- UI (Buyer PWA): create request form → "Price pending" state → group detail with progress bar → reconfirm CTA.
- UI (Ops Portal): demand queue showing groups, aggregate budgets (internal only), readiness counts.
- Tests: buyer A cannot see buyer B's `max_paise`; group matching is idempotent; leaving a group decrements counts; `L4` count correct after simulated payment.

**Depends on:** M2.1, M1.6

**Rollback strategy:** Reverse migrations drop group and request tables. No financial state involved. The matching background job is idempotent; re-running it after a rollback and re-deploy rebuilds group memberships.

**Definition of done:**
- `max_paise` field absent from all buyer-facing API responses (automated serializer test scans all response payloads).
- Two buyers with the same variant + region + window are matched into the same group (integration test).
- Incompatible variants (different model year) are not merged (compatibility rule test).

**Complexity:** L

---

### M2.3 — RFQ publication and seller invitations

**Deliverables:**
- Migrations: `rfqs`, `rfq_seller_invitations`.
- RFQ creation: internal ops agent creates RFQ from a qualifying demand group; spec snapshot taken at creation time (immutable `spec_snapshot_json`); quantity band derived from `ready_qty` ± configured buffer; bid deadline set.
- APIs (Internal):
  - `POST /v1/internal/groups/{id}/rfqs` — create RFQ; ProcurementAgent + Lead.
  - `GET /v1/internal/rfqs/{id}` — full RFQ detail including confidential aggregate budgets.
  - `POST /v1/internal/rfqs/{id}/invitations` — invite sellers by ID.
  - `PATCH /v1/internal/rfqs/{id}` — extend deadline (logged; reason required).
- APIs (Seller):
  - `GET /v1/seller/rfqs` — only RFQs the seller is invited to; shows spec, qty band, deadline, region — **no competing bids, no buyer budgets**.
  - `GET /v1/seller/rfqs/{id}` — same scope restriction enforced at serializer level.
- Notifications: seller invited → notification dispatched (in-app + SMS stub).
- Deadline extension: `rfq_deadline_extensions` log entry required; selective disclosure to competitors forbidden (all invited sellers get the same public update).
- UI (Seller Portal): RFQ feed → RFQ detail (spec, qty band, deadline, submission form shell).
- UI (Ops Portal): RFQ management, invitation list, deadline extension form with reason field.
- Tests: seller API response for RFQ detail contains zero fields from `buyer_price_preferences`; seller cannot enumerate RFQs they were not invited to (IDOR test).

**Depends on:** M2.2, M1.8

**Rollback strategy:** Reverse RFQ migrations. Seller invitations are notification records; already-sent SMS cannot be unsent. Spec snapshot is immutable so no data integrity issue on rollback.

**Definition of done:**
- Seller API for RFQ detail passes automated field-level assertion: response schema contains no `max_paise`, no `target_paise`, no other-seller data.
- IDOR test: seller with valid JWT but not on the invitation list receives `404` (not `403`) to prevent enumeration.
- Deadline extension produces an audit log entry with actor and reason.

**Complexity:** M

---

### M2.4 — Sealed bid submission and revisions

**Deliverables:**
- Migrations: `seller_bids`, `seller_bid_revisions`.
  - `seller_bids(id, rfq_id, seller_id, source ENUM(PLATFORM, TEAM_SOURCED), status, latest_revision_id, created_at)`. Unique `(rfq_id, seller_id)`.
  - `seller_bid_revisions(id, bid_id, revision_no, unit_price_paise, tax_basis ENUM, freight_paise, moq, max_qty, valid_until, lead_time_days, warranty_snapshot_json, attachments_json, submitted_at, submitted_by)`. Append-only; no updates.
- APIs (Seller):
  - `POST /v1/seller/rfqs/{id}/bids` — create first revision; only before deadline.
  - `POST /v1/seller/bids/{id}/revisions` — add revision; only before deadline; previous revisions preserved.
  - `GET /v1/seller/bids/{id}` — own bid + own revisions only.
- Deadline enforcement: server-side check; bids and revisions rejected after `rfqs.bid_deadline`; uses `LockService` from M1.9 to prevent race on deadline boundary.
- Sealed bid access control: `BidPolicy::canReadConfidential()` returns true only for ProcurementAgent, ProcurementLead, FinanceApprover, ComplianceAdmin, SuperAdmin. Seller receives `403` if they attempt to query any bid other than their own. All confidential bid reads logged to `audit_logs`.
- APIs (Internal — confidential):
  - `GET /v1/internal/rfqs/{id}/confidential-bids` — returns all bids and revisions; strict permission `bids.read.confidential`; audit logged.
- Attachment uploads: stored in object storage; private signed URLs with 1-hour TTL.
- Tests: seller A cannot read seller B's bid (direct ID, list, enumeration); bid submitted after deadline rejected; revision increments `revision_no`; `latest_revision_id` updated atomically; confidential read by procurement agent produces audit log entry.

**Depends on:** M2.3, M1.7, M1.9

**Rollback strategy:** Bid revisions are append-only. Rolling back the migration drops all bid data. In a production incident, bids can be voided by status update (not deleted) with audit trail; a new RFQ would be required.

**Definition of done:**
- Automated penetration test: seller role JWT + brute-forced revision IDs for other sellers → 100% `403` responses.
- Post-deadline bid submission returns `422` with `error_code: BID_DEADLINE_PASSED` (integration test).
- Confidential bid list read by procurement agent produces exactly one `audit_logs` row with `event_type = BID_CONFIDENTIAL_READ` (test).

**Complexity:** L

---

### M2.5 — Procurement CRM: cases, supplier directory, contact log, tasks

**Deliverables:**
- Migrations: `procurement_cases`, `supplier_leads`, `supplier_contacts`, `procurement_tasks`.
- Auto-creation: when an RFQ is published (M2.3), a `procurement_case` is created automatically with `stage = OPEN`, `sla_due_at = rfq.bid_deadline + configured_buffer`.
- APIs (Internal):
  - `GET /v1/internal/procurement/cases` — queue with priority, SLA, stage; filterable.
  - `GET /v1/internal/procurement/cases/{id}` — full case with group, aggregate budget (authorized only), bid list shell, tasks, contacts.
  - `POST /v1/internal/cases/{id}/assign` — assign agent.
  - `POST /v1/internal/cases/{id}/supplier-leads` — add supplier lead.
  - `POST /v1/internal/supplier-leads/{id}/contacts` — log contact attempt (method, outcome, notes, timestamp).
  - `POST /v1/internal/cases/{id}/tasks` — create task.
  - `PATCH /v1/internal/tasks/{id}` — update task status/due date.
  - `GET /v1/internal/supplier-directory` — search supplier leads by category/region.
- SLA alerting: background job runs every 15 minutes; cases approaching SLA breach flagged; `risk_flags` entry created; ops dashboard surfaced.
- UI (Ops Portal): procurement queue → case detail → supplier lead + contact log → tasks panel → bid comparison panel (shell only; populated in M2.6).
- Tests: case auto-created on RFQ publish; SLA flag created when overdue; agent cannot access case assigned to another agent unless ProcurementLead or above.

**Depends on:** M2.4, M1.9

**Rollback strategy:** Reverse migrations. SLA flag `risk_flags` entries are advisory; dropping them does not affect financial state. Supplier lead data should be backed up before migration rollback if it has real contact history.

**Definition of done:**
- Case created automatically within one job cycle of RFQ publish (integration test with worker run inline).
- SLA breach flag appears in `risk_flags` table after simulated time advance past `sla_due_at` (integration test).
- ProcurementAgent cannot access a case assigned to a different agent (ABAC ownership test).

**Complexity:** L

---

### M2.6 — Offline quotes and negotiation log

**Deliverables:**
- Migrations: `negotiation_events`, `negotiation_attachments`.
  - `negotiation_events(id, case_id, supplier_id NULLABLE, bid_id NULLABLE, type ENUM(OFFLINE_QUOTE, CALL_NOTE, COUNTER_OFFER, SUPPLIER_CONFIRMATION, REJECTION, ESCALATION), terms_json, created_by, created_at)`. Append-only.
  - `negotiation_attachments(id, event_id, object_ref, mime, checksum, uploaded_by, uploaded_at)`.
- Offline quote validation: `source = TEAM_SOURCED` requires `supplier_confirmation` attachment OR a linked `supplier_contacts` outcome of `CONFIRMED`; without evidence, submission is rejected with `error_code: OFFLINE_QUOTE_EVIDENCE_REQUIRED`. Staff cannot fabricate a bid (Section 5.2 requirement).
- Bid normalization: `GET /v1/internal/rfqs/{id}/normalized-comparison` — returns all bids (platform + offline) normalized to `landed_paise = unit_price_paise + freight_paise + (tax if applicable)` with weighting notes; visible only to ProcurementAgent+.
- APIs (Internal):
  - `POST /v1/internal/cases/{id}/offline-quotes` — requires evidence attachment.
  - `POST /v1/internal/cases/{id}/negotiation-events` — log call/counter/escalation.
  - `GET /v1/internal/cases/{id}/negotiation-history` — full timeline.
  - `GET /v1/internal/rfqs/{id}/normalized-comparison`.
- UI (Ops Portal): negotiation timeline → attach evidence → submit offline quote form → normalized bid comparison table.
- Tests: offline quote without attachment rejected; normalization formula correct for GST-inclusive vs exclusive bids; negotiation history append-only (no edit endpoint exists).

**Depends on:** M2.5, M2.4

**Rollback strategy:** Negotiation events are append-only. Rolling back the migration loses the negotiation history. For production incidents, events are voided by status (never deleted) with audit trail.

**Definition of done:**
- Offline quote `POST` without attachment returns `422` with correct `error_code` (integration test).
- Normalized comparison returns `landed_paise` calculated correctly for three test bids with different tax bases (unit test on normalization service).
- Negotiation history has no `PUT`/`PATCH`/`DELETE` endpoints (OpenAPI contract test asserts absence).

**Complexity:** M

---

### M2.7 — Offer approval workflow (pre-publish)

**Deliverables:**
- Migrations: `negotiated_offers`, `offer_price_tiers`, `offer_approvals`.
- Offer creation by ProcurementLead: selects winning bid revision (or offline quote); snapshots all commercial terms in `terms_snapshot_json` (immutable from this point); sets `expires_at` and `status = PENDING_APPROVAL`.
- Approval flow: at least one `FinanceApprover` or `ProcurementLead` (above a configurable amount threshold, two approvers required; second approver cannot be the same person as the creator). Recorded in `offer_approvals`.
- Approval threshold: configurable per category via feature flag; e.g., above ₹5,00,000 GMV requires dual approval.
- Offer cannot transition to `APPROVED` without required approvals; enforced at application layer and tested.
- Notifications: approval requested → notify approver; approved → notify procurement lead.
- APIs (Internal):
  - `POST /v1/internal/offers` — create offer draft.
  - `GET /v1/internal/offers/{id}` — full detail including terms snapshot.
  - `POST /v1/internal/offers/{id}/approve` / `reject` — with reason.
  - `POST /v1/internal/offers/{id}/publish` — transitions to `PUBLISHED` state; triggers buyer notification (M3.1).
- Offer snapshot immutability: once `status` leaves `DRAFT`, `terms_snapshot_json` has no update endpoint; enforced by policy.
- Tests: single-approver offer approved below threshold; dual-approval required above threshold; creator cannot self-approve; rejected offer cannot be published.

**Depends on:** M2.6, M1.6, M1.7

**Rollback strategy:** Offer in `PENDING_APPROVAL` or `DRAFT` can be archived without financial consequence. Once `PUBLISHED`, rolling back requires expiring the offer and notifying buyers (no payment has occurred yet in this phase).

**Definition of done:**
- Creator self-approval attempt returns `403` with `error_code: SELF_APPROVAL_DENIED` (integration test).
- Offer above threshold blocked from `APPROVED` state with only one approver (state machine test).
- `terms_snapshot_json` unchanged after a `PATCH /v1/internal/offers/{id}` attempt returns `405` (OpenAPI + integration test).

**Complexity:** L

---

## Phase 3 — Offers and Orders

**Phase goal:** Buyers receive, review and accept final offers; payment scaffolding captures checkout intent; stock is reserved atomically; MOQ logic gates order release; seller-led deals are supported.

**Phase gate:** Concurrency tests pass — 100 simultaneous checkout attempts on 100-unit stock produce exactly 100 reservations and 0 oversell. No silent repricing: accepted offer price is immutable. No silent product substitution.

---

### M3.1 — Final offer presentation and buyer acceptance

**Deliverables:**
- Buyer-facing offer detail: landed unit price, fee breakdown, taxes, MOQ threshold, seller/fulfillment summary, warranty and return terms, offer expiry countdown, explicit accept CTA.
- Offer expiry: server-side `expires_at` enforced; expired offers return `410 Gone`; frontend polls status every 60s and shows expiry warning.
- Migrations: `offer_acceptances`.
  - `offer_acceptances(id, offer_id, buyer_id, qty, accepted_terms_hash, accepted_at, ip, user_agent)`.
- Acceptance flow: buyer explicitly accepts; server validates offer not expired, buyer eligible (member of linked group, intent >= L2), terms hash matches current snapshot; records `offer_acceptances`; upgrades buyer group membership to `L3`.
- Price lock: `accepted_terms_hash` is `sha256(terms_snapshot_json)`; any repricing attempt invalidates the hash and is blocked.
- APIs (Buyer):
  - `GET /v1/offers/{id}` — buyer-facing serialization (no internal procurement notes).
  - `POST /v1/offers/{id}/accept` — with `qty` and `terms_hash`.
- UI (Buyer PWA): offer detail page with itemized price breakdown, warranty terms, expiry timer, accept button with confirmation modal showing full terms.
- Notifications: offer published → notify group members; offer expiring (24h and 2h before) → reminder.
- Tests: expired offer acceptance returns `410`; terms hash mismatch returns `422`; buyer not in group returns `403`; accepted_qty increments correctly under sequential accepts.

**Depends on:** M2.7, M2.2

**Rollback strategy:** `offer_acceptances` is append-only. If an offer needs to be retracted, it is expired server-side; accepted-but-unpaid buyers are notified and their `L3` status reverted. No financial rollback needed (payment not yet processed in this milestone).

**Definition of done:**
- Terms hash mismatch test: mutate `terms_snapshot_json` in DB, attempt acceptance → `422` (integration test).
- Expired offer acceptance blocked at API level (not just UI) — verified by direct API call after advancing `expires_at` in test DB.
- Accepted offer price reflected in `order_items.unit_price_paise` matches the snapshot exactly (snapshot integrity test).

**Complexity:** M

---

### M3.2 — Stock reservation with atomic concurrency control

**Deliverables:**
- Migration: `stock_reservations(id, offer_id, seller_fulfillment_order_id NULLABLE, buyer_id, qty, expires_at, status ENUM(HELD, CONFIRMED, RELEASED, EXPIRED))`.
- Atomic reservation: `LockService` acquires per-offer lock; MySQL `SELECT ... FOR UPDATE` on a `stock_allocations` counter; check available = `max_qty - SUM(confirmed_reservations)`; insert reservation or return `STOCK_EXHAUSTED`.
- Reservation expiry: checkout deadline passed without payment → background job sets `EXPIRED`; releases back to available pool.
- MOQ check: background job fires when `paid_qty` changes; if `paid_qty >= offer.min_paid_qty` and `confirmed_stock >= paid_qty`, transitions offer to `MOQ_REACHED`; creates `seller_fulfillment_orders` record; notifies procurement team.
- If `paid_qty` never reaches MOQ before offer expires: transitions to `MOQ_FAILED`; triggers refund workflow stubs (actual refunds in M4).
- APIs (Internal):
  - `GET /v1/internal/offers/{id}/moq-status` — live paid_qty, interested_qty, threshold.
- Tests: 100 concurrent reservation attempts on 100-unit stock → exactly 100 succeed, 0 oversell (concurrent PHP process test or transaction-level test); reservation expiry job releases stock; MOQ_REACHED fires at correct threshold.

**Depends on:** M3.1, M1.9

**Rollback strategy:** `stock_reservations` can be cleared for an offer if no payments have been confirmed. Rolling back the migration drops the table; counter is rebuilt from `offer_acceptances` and `payment_intents` on re-deploy.

**Definition of done:**
- Concurrency test: spawn 110 parallel reservation attempts for a 100-unit offer; assert `SELECT COUNT(*) FROM stock_reservations WHERE status = HELD` = 100 and `= 0` oversell (integration test using multiple DB connections).
- MOQ_FAILED offer triggers notification event (integration test with worker inline).
- MOQ_REACHED offer creates exactly one `seller_fulfillment_orders` row (idempotency test: job run twice → still one row).

**Complexity:** XL

---

### M3.3 — Checkout scaffolding and order creation

**Deliverables:**
- Migrations: `orders`, `order_items`, `order_status_events`, `seller_fulfillment_orders`.
- Order creation: triggered after stock reservation confirmed; snapshots `unit_price_paise`, `qty`, `tax_paise`, `shipping_paise`, `terms_hash` from offer at the moment of acceptance (not from live offer data); creates `order_items`; order status `CHECKOUT_PENDING`.
- Checkout deadline: configurable per offer (e.g., 30 minutes); expired checkout releases reservation.
- APIs (Buyer):
  - `POST /v1/orders/{id}/checkout` — provide delivery address; validates reservation still held; returns payment intent payload (gateway integration stubbed; returns a mock `payment_url` behind feature flag).
  - `GET /v1/orders/{id}` — own orders.
  - `GET /v1/me/orders` — list.
- Idempotency: `POST /v1/orders/{id}/checkout` with same `Idempotency-Key` header returns cached response; second genuine attempt after expiry returns `409`.
- Price immutability: `order_items` snapshot created from acceptance snapshot; if offer `terms_snapshot_json` has been modified (should be impossible due to M3.1 guard), order creation fails with `SNAPSHOT_MISMATCH` error.
- UI (Buyer PWA): order summary → address selection → payment handoff page (placeholder for gateway in M4).
- UI (Seller Portal): confirmed orders list (read-only at this stage).
- Tests: order price matches acceptance snapshot exactly; second checkout attempt same idempotency key returns same response; checkout after reservation expiry returns `409`; `order_status_events` populated at each transition.

**Depends on:** M3.2, M1.5

**Rollback strategy:** Orders in `CHECKOUT_PENDING` with no payment can be cancelled and reservations released. Rolling back the migration loses order records; acceptable only before production data exists.

**Definition of done:**
- Price snapshot assertion: change `offer.terms_snapshot_json` after acceptance, attempt checkout → `SNAPSHOT_MISMATCH` error (integration test).
- Idempotency key test: same key twice → identical response body and HTTP status (integration test).
- `order_status_events` has a row for every status transition (state machine completeness test).

**Complexity:** M

---

### M3.4 — MOQ quantity logic and seller-led tier deals

**Deliverables:**
- Quantity tier engine: `offer_price_tiers` rows; at MOQ check time, `paid_qty` selects the applicable tier; price locked from the tier active at acceptance time (not the tier at MOQ check time for already-accepted buyers).
- Seller-led deal publication:
  - Migration additions: `seller_deals` table referencing `offers` with `deal_type = SELLER_LED`.
  - API: `POST /v1/seller/deals` — seller creates deal with inventory, tier prices, deadline, regions, fulfillment SLA; goes to `PENDING_REVIEW`; internal staff approve.
  - `GET /v1/deals` (public) — active seller-led deals.
  - `GET /v1/deals/{id}` — public deal detail with tier table, progress bar (units paid so far), deadline.
- Tier price change before acceptance: permitted via `POST /v1/internal/offers/{id}/revise-tiers` only if `accepted_qty = 0` and offer in `DRAFT`; otherwise blocked.
- Tier price change after any acceptance: blocked entirely; returns `TIER_LOCKED` error.
- Oversubscription: when `paid_qty > max_qty`, additional checkouts rejected with `STOCK_EXHAUSTED`; waitlist entry created optionally.
- Tests: buyer accepted at tier 2 price; group grows past tier 3 threshold; buyer's locked price remains tier 2 (snapshot); seller cannot revise tiers after first acceptance; seller-led deal visible on public feed after approval.

**Depends on:** M3.3, M2.7, M1.8

**Rollback strategy:** Seller-led deal approval is a status flag; rolling back sets deal to `ARCHIVED`. Tier data rollback: reverse migration. No payments involved yet.

**Definition of done:**
- Tier lock test: one buyer accepts at tier 2; attempt `revise-tiers` → `TIER_LOCKED` (integration test).
- Oversubscription: 101 checkout attempts on max_qty=100 → exactly 100 succeed, 1 returns `STOCK_EXHAUSTED` (concurrent test).
- Seller-led deal not visible on public API before internal approval (integration test).

**Complexity:** L

---

## Phase 4 — Payments and Fulfillment

**Phase goal:** A provider-approved payment gateway processes live (or sandbox) funds; a double-entry ledger records every money movement; shipments and inspections are tracked; buyers acknowledge delivery; settlements reach the approval queue.

**Phase gate:** Written provider approval of the intended fund-flow on file. Sandbox end-to-end passes all 20 QA test cases from Section 23. Finance reconciliation sign-off on the daily reconciliation run.

---

### M4.1 — Payment gateway integration, intents, and webhooks

**Deliverables:**
- Migrations: `payment_intents`, `payment_webhook_events`.
- Gateway client: interface `PaymentGatewayInterface` with concrete implementation for selected provider (Razorpay or Cashfree); `SandboxGatewayDriver` for tests.
- Payment intent lifecycle: `CREATED → CHECKOUT_PENDING → CAPTURED/PAID` or `FAILED/EXPIRED`.
- `POST /v1/orders/{id}/checkout` updated: creates `payment_intents` row with `idempotency_key`; calls gateway `createOrder`; returns `payment_url` / SDK params.
- Webhook endpoint: `POST /v1/webhooks/payments/{provider}` — raw body preserved for signature verification; `HMAC-SHA256` signature checked before any processing; invalid signature → `200 OK` logged as `SIGNATURE_INVALID` security event (do not return error that leaks info); valid → push to queue; return `200` immediately.
- Webhook consumer job: idempotent on `(provider, provider_event_id)`; updates `payment_intents.status`; upgrades buyer group membership to `L4`; triggers MOQ check; posts to ledger (M4.2).
- Duplicate webhook delivery: job checks `payment_webhook_events.processed_at`; if already set, skip processing, log `DUPLICATE_SKIPPED`.
- Refund interface: `POST /v1/internal/refunds` (FinanceApprover only) — creates `refunds` row; calls gateway refund API; webhook confirms.
- Tests (Section 23 items 8, 10, 16, 17): webhook delivered 3 times → exactly 1 `PAID` allocation; wrong-signature webhook rejected + security event; failed job replay cannot duplicate order; seller withdrawal after payment triggers re-source workflow.
- Feature flag: `payments.live_mode` — when false, `SandboxGatewayDriver` used; production deployment requires flag on.

**Depends on:** M3.3, M1.9

**Rollback strategy:** Payment intents and webhook events are immutable records. Rolling back the gateway integration code (not the migration) reverts to sandbox driver via feature flag. Database migration rollback acceptable only before any real payments are processed.

**Definition of done:**
- Section 23 items 8, 16, 17 pass as automated integration tests against sandbox driver.
- Invalid signature webhook produces `payment_webhook_events` row with `signature_valid = 0` and a `risk_flags` security event row (integration test).
- `paid_qty` increments exactly once after three identical webhook deliveries (idempotency integration test).

**Complexity:** XL

---

### M4.2 — Double-entry ledger and financial reconciliation

**Deliverables:**
- Migrations: `ledger_accounts`, `ledger_entries`, `ledger_postings`, `reconciliation_runs`, `reconciliation_exceptions`.
- Ledger accounts seeded: `BUYER_RECEIVABLE`, `PLATFORM_REVENUE`, `GATEWAY_FEES_PAYABLE`, `SELLER_PAYABLE`, `REFUNDS_PAYABLE`, `DISPUTE_RESERVE`.
- Every financial event produces balanced postings (debit = credit in paise); no float; integer paise only.
- Key posting events: payment captured, refund initiated, refund confirmed, settlement approved, commission earned.
- Daily reconciliation job: compare `ledger_entries` for the period against gateway settlement report (CSV/API import); flag `reconciliation_exceptions` for any discrepancy; block false `SETTLED` status if provider report disagrees (Section 23 item 20).
- APIs (Internal — FinanceOperator+):
  - `GET /v1/internal/ledger` — paginated postings filterable by account/date/order.
  - `POST /v1/internal/reconciliation/runs` — trigger manual reconciliation.
  - `GET /v1/internal/reconciliation/exceptions` — list open exceptions.
  - `POST /v1/internal/reconciliation/exceptions/{id}/resolve`.
- Never calculate balance from mutable order totals; always from ledger postings (Section 10.4).
- Tests: payment → check balanced postings sum to zero; partial refund → check ledger balances; reconciliation exception flagged when mock provider report shows ₹100 discrepancy.

**Depends on:** M4.1

**Rollback strategy:** Ledger is append-only. Correcting errors requires compensating entries, not deletes. Schema rollback acceptable only before production use; compensating entry pattern documented in runbook.

**Definition of done:**
- All ledger postings in test suite balance to zero paise (automated assertion on every test that triggers a financial event).
- Reconciliation exception created when provider report total differs from internal ledger by any non-zero amount (integration test).
- Section 23 item 13 (refund after partial settlement reconciled correctly) passes as an automated test.

**Complexity:** XL

---

### M4.3 — Shipping, dispatch and delivery tracking

**Deliverables:**
- Migrations: `shipments`, `shipment_items`, `delivery_evidence`.
- Seller dispatch flow: seller uploads invoice + packing evidence; creates `shipments` row with carrier and tracking ref; `seller_fulfillment_orders` transitions to `DISPATCHED`.
- Delivery evidence: carrier status webhook (stubbed via interface) or manual update by OperationsAgent; evidence stored as signed object URLs.
- Fulfillment state machine: `READY_TO_DISPATCH → DISPATCHED → IN_TRANSIT → DELIVERED_REPORTED → DELIVERY_VERIFIED → BUYER_ACCEPTED / ISSUE_REPORTED / PARTIALLY_ACCEPTED` (Section 9.2).
- APIs (Seller):
  - `POST /v1/seller/orders/{id}/dispatch` — create shipment, upload invoice.
  - `GET /v1/seller/orders` — own fulfillment orders with status.
- APIs (Internal):
  - `PATCH /v1/internal/shipments/{id}/status` — OperationsAgent update.
  - `POST /v1/internal/shipments/{id}/evidence` — attach delivery evidence.
- Notifications: dispatch confirmation → buyer; delivery reported → buyer (acknowledgement prompt).
- UI (Seller Portal): dispatch form → invoice upload → tracking entry.
- UI (Ops Portal): fulfillment tracking queue → shipment detail → evidence gallery.
- Tests: seller cannot dispatch an order not belonging to their organization; state machine rejects invalid transitions (e.g., `DELIVERED_REPORTED → DISPATCHED`); evidence URL is signed and expires.

**Depends on:** M4.1, M1.9

**Rollback strategy:** Shipment records are operational. Rolling back requires retracting dispatch notifications; documented as a manual ops procedure. Database migration rollback acceptable before production data.

**Definition of done:**
- State machine transition test: all 15 valid transitions pass; 5 invalid transitions return `422 INVALID_TRANSITION` (state machine unit test).
- Seller IDOR test: seller A cannot fetch shipment for seller B's order (integration test).
- Evidence signed URL expires after configured TTL (integration test using clock mock).

**Complexity:** M

---

### M4.4 — Quality inspection tiers

**Deliverables:**
- Migrations: `inspections`, `inspection_findings`.
- Inspection tier assignment: at order release time, category feature flag selects tier A/B/C (Section 9.1); tier A is default; tier B/C require explicit assignment by OperationsAgent.
- Inspection flow: `ASSIGNED → IN_PROGRESS → COMPLETE / FAILED`.
- Inspection findings: per order item; `finding_type ENUM(OK, DAMAGED, WRONG_ITEM, MISSING, COUNTERFEIT_SUSPECTED)`; evidence attachment required for non-OK findings.
- APIs (Internal):
  - `POST /v1/internal/inspections` — assign inspection to order/shipment.
  - `POST /v1/internal/inspections/{id}/complete` — submit checklist + findings.
  - `GET /v1/internal/inspections/{id}` — findings + evidence.
- Inspection result gates settlement eligibility: `COUNTERFEIT_SUSPECTED` → automatic `risk_flags` entry and settlement hold.
- Tests: counterfeit finding creates risk_flag and blocks settlement approval (integration test); tier A inspection does not require inspector assignment (auto-complete with document evidence only).

**Depends on:** M4.3, M1.6

**Rollback strategy:** Inspection records are operational. Rolling back clears inspection state; fulfillment_orders revert to `DISPATCHED`. Acceptable before production.

**Definition of done:**
- `COUNTERFEIT_SUSPECTED` finding creates a `risk_flags` row and sets `seller_settlements.status = ON_HOLD` (integration test).
- Inspection checklist snapshot stored in `checklist_snapshot` JSON is immutable after `COMPLETE` (no update endpoint exists; OpenAPI contract test).

**Complexity:** M

---

### M4.5 — Buyer acknowledgement and dispute window

**Deliverables:**
- Migration: `buyer_acknowledgements`.
- Acknowledgement prompt: sent after `DELIVERY_VERIFIED`; reminder at 24h and 48h; legal deadline configurable per category feature flag.
- Acknowledgement outcomes: full accept, partial accept (accepted_qty_json + disputed_qty_json), issue report.
- Buyer silence policy: after legal deadline, system logs `DEEMED_ACCEPTED` with explicit reason and timestamp; does **not** auto-release settlement unless provider and legal counsel have confirmed this is permitted (`[CONFIRM]` gate); platform transitions to `ESCALATION_REQUIRED` pending confirmation.
- APIs (Buyer):
  - `POST /v1/shipments/{id}/acknowledgements` — accept/dispute with evidence upload.
- APIs (Internal):
  - `GET /v1/internal/acknowledgements/pending` — OperationsAgent queue.
  - `POST /v1/internal/acknowledgements/{id}/escalate`.
- Partial delivery: `accepted_qty_json` per order item; disputed items transition to dispute workflow (M5.1); only accepted quantities eligible for settlement.
- Tests: Section 23 items 11, 12 — partial delivery leaves 3 disputed/unsettled; buyer silence does not silently release settlement.

**Depends on:** M4.4, M1.9

**Rollback strategy:** Acknowledgements are immutable records. Reversing an incorrect acknowledgement requires a dispute (M5.1), not a data edit.

**Definition of done:**
- Section 23 item 11: order with 10 units, delivery of 7 → 3 items in `disputed_qty_json`, settlement for 3 units blocked (integration test).
- Section 23 item 12: buyer silence after legal deadline does not set `seller_settlements.status = APPROVED` without explicit internal action (integration test with time-advance).

**Complexity:** M

---

### M4.6 — Seller settlements workflow

**Deliverables:**
- Migrations: `seller_settlements`, `settlement_items`, `settlement_approvals`.
- Settlement eligibility engine: runs after buyer acknowledgement or deemed acceptance; calculates `eligible_paise` from accepted `order_items` minus commission minus gateway fees borne minus deductions; records `settlement_items`.
- Settlement state machine: `NOT_ELIGIBLE → AWAITING_VERIFICATION → APPROVAL_PENDING → APPROVED → RELEASE_REQUESTED → PROVIDER_CONFIRMED_SETTLED` + exception states.
- Maker-checker: FinanceOperator prepares settlement; FinanceApprover approves; same person cannot do both for a given settlement.
- Provider settlement release: `POST /v1/internal/settlements/{id}/release` calls provider payout API (interface); `PROVIDER_CONFIRMED_SETTLED` set only after webhook/confirmation, not after the API call.
- Bank account change cooling-off enforced: if `seller_bank_accounts` changed within configured window (e.g., 48h), settlement release blocked pending secondary review.
- APIs (Internal):
  - `GET /v1/internal/settlements` — FinanceOperator queue.
  - `POST /v1/internal/settlements/{id}/approve` / `reject`.
  - `POST /v1/internal/settlements/{id}/release`.
- UI (Ops Portal): settlement queue → settlement detail with line items → approve/release actions.
- UI (Seller Portal): `GET /v1/seller/settlements` — own settlement history with status and paise amounts.
- Tests: Section 23 items 13, 14 — partial refund reconciled; bank change blocks settlement; self-approval blocked.

**Depends on:** M4.5, M4.2, M1.7

**Rollback strategy:** Settlement state machine states can be reset to `AWAITING_VERIFICATION` by FinanceApprover with audit trail. Database migration rollback acceptable only before production data.

**Definition of done:**
- Section 23 item 14: FinanceOperator who initiated settlement cannot approve it (integration test).
- Bank account changed 1 hour before release → `BANK_CHANGE_COOLING_OFF` error (integration test with clock mock).
- `PROVIDER_CONFIRMED_SETTLED` set only after mock provider webhook fires (not after API call alone).

**Complexity:** XL

---

## Phase 5 — Disputes and Admin

**Phase goal:** Complete dispute lifecycle; configurable notifications; feature flags; admin policy settings; risk management dashboard.

**Phase gate:** Complete dispute lifecycle — open → evidence → decision → appeal → close — tested end-to-end for all scenario types in Section 11.1.

---

### M5.1 — Disputes engine

**Deliverables:**
- Migrations: `disputes`, `dispute_events`, `dispute_evidence`, `dispute_adjustments`.
- Dispute types: `WRONG_ITEM`, `DAMAGED`, `NOT_DELIVERED`, `COUNTERFEIT`, `QUANTITY_SHORT`, `LATE_DELIVERY`, `PAYMENT_ISSUE`.
- Lifecycle: `OPEN → EVIDENCE_REQUESTED → UNDER_REVIEW → DECISION_PENDING → RESOLVED / ESCALATED → CLOSED / APPEALED → APPEAL_REVIEW → FINAL_CLOSED`.
- SLAs: response, investigation, evidence, decision, appeal targets stored as configurable policy variables (not hardcoded).
- Financial adjustment: `dispute_adjustments` row with amount, reason, actor, maker-checker approval; linked to `ledger_entries` (M4.2).
- Evidence preservation: when dispute opens, snapshot relevant `order_items`, `shipment`, `inspection_findings`, `buyer_acknowledgement`; held immutably.
- APIs (Buyer): `POST /v1/orders/{id}/disputes`, `POST /v1/disputes/{id}/evidence`, `GET /v1/disputes/{id}`.
- APIs (Seller): `GET /v1/seller/disputes`, `POST /v1/seller/disputes/{id}/evidence`.
- APIs (Internal):
  - `GET /v1/internal/disputes` — SupportDisputes queue.
  - `POST /v1/internal/disputes/{id}/assign`.
  - `POST /v1/internal/disputes/{id}/decision`.
  - `POST /v1/internal/disputes/{id}/adjustments`.
  - `POST /v1/internal/disputes/{id}/appeal-review`.
- Section 23 items 7, 10, 11, 12, 13 all pass as automated tests.
- Tests: financial adjustment requires maker-checker; seller cannot read buyer's private dispute evidence fields; consumer statutory rights not waived by platform deadline expiry (policy flag test).

**Depends on:** M4.5, M4.2, M1.7

**Rollback strategy:** Dispute records are append-only. Financial adjustments require compensating entries. Schema rollback acceptable before production.

**Definition of done:**
- All 13 scenario rows from Section 11.1 have a corresponding automated test covering buyer outcome and finance outcome.
- Maker-checker on `dispute_adjustments`: same actor for both maker and checker returns `403` (integration test).
- Consumer statutory rights flag: if `dispute_sla_expired = true` and `statutory_rights_preserved = false`, system throws `CONFIG_ERROR` on attempt to close (config validation test).

**Complexity:** XL

---

### M5.2 — Notifications system

**Deliverables:**
- Migrations: `notifications`, `notification_deliveries`, `consent_records`.
- Channels: in-app (immediate), SMS (OTP provider), transactional email (SMTP/SES interface), WhatsApp Business (interface; feature-flagged off by default pending template approval).
- Event-driven: each domain event (offer published, payment confirmed, dispatch, acknowledgement reminder, dispute update, refund, settlement) publishes a notification job.
- Channel preferences: user can configure channel per notification type; defaults sensible for mobile-first India.
- Deduplication: same event + user + channel within a time window → skip.
- Reminders: scheduled jobs for offer expiry (24h, 2h), acknowledgement (24h, 48h), payment deadline.
- PII in previews: notification body must not expose private budget figures, competing bid data or other users' PII; asserted in notification content tests.
- APIs (Buyer): `GET /v1/me/notifications`, `PATCH /v1/me/notification-preferences`.
- Tests: offer expiry reminder fires at correct schedule; duplicate event within window sends only one notification; WhatsApp driver no-ops when feature flag is off.

**Depends on:** M1.9, M4.1

**Rollback strategy:** Notification records are advisory. Clearing the queue and table has no financial consequence. Code rollback via git.

**Definition of done:**
- Duplicate suppression test: same event fired twice within 5 minutes → exactly one `notification_deliveries` row (integration test).
- PII test: notification body for "offer published" event contains no `max_paise` field value from buyer preferences (content assertion test).
- WhatsApp driver no-op test: `notification_deliveries` for WhatsApp channel has `status = SKIPPED` when feature flag is off.

**Complexity:** M

---

### M5.3 — Feature flags, admin settings, policy configuration

**Deliverables:**
- Migration: `feature_flags(id, key, value_json, description, updated_by, updated_at)`.
- Flags required by this point: `payments.live_mode`, `payments.provider`, `inspection.tier_b_categories`, `deposit.enabled`, `whatsapp.enabled`, `cities.enabled_list`, `categories.enabled_list`, `settlement.bank_change_cooloff_hours`, `dispute.sla_response_hours`, `offer.dual_approval_threshold_paise`.
- `FeatureFlagService::get(string $key, mixed $default)` — cached in Redis with TTL; cache-busted on update.
- APIs (SuperAdmin only):
  - `GET /v1/internal/feature-flags`.
  - `PATCH /v1/internal/feature-flags/{key}`.
- Change audit: every flag change logged to `audit_logs` with before/after value.
- Admin policy settings: `policy_versions` table for versioned terms-of-service, refund policy, inspection SLA policy documents; linked to consent records.
- Risk flags dashboard: `GET /v1/internal/risk-flags` — OperationsAgent+; filterable by type, actor, status.
- Tests: flag change produces audit log entry; cache invalidated after update (flag read returns new value within one TTL cycle); risk flag visible only to authorized roles.

**Depends on:** M1.7, M1.9

**Rollback strategy:** Feature flag rollback by updating the flag value via the admin API (not a code or migration rollback). Policy version rollback: publish a new version pointing to the previous document.

**Definition of done:**
- Flag update reflected in `FeatureFlagService::get` within Redis TTL (integration test with clock mock).
- Flag change audit: `audit_logs` row contains `before_value` and `after_value` JSON (integration test).
- SuperAdmin-only endpoint returns `403` for FinanceOperator role (authorization test).

**Complexity:** M

---

### M5.4 — Risk management and fraud controls

**Deliverables:**
- Risk signals: OTP rate limit trips, failed payment attempts, duplicate account detection (same mobile hash), suspicious bid patterns (same IP multiple sellers), bank account change velocity.
- `risk_flags(id, type, entity_type, entity_id, severity, details_json, created_by, resolved_by, resolved_at)`.
- Automated flag creation: hooks in OTP service (M1.4), payment webhook (M4.1), seller bank change (M1.8), inspection findings (M4.4).
- Manual flag creation: ComplianceAdmin.
- Account freeze: `users.status = FROZEN` or `seller_profiles.status = SUSPENDED` blocks all actions; not reversible by the same staff member who set it.
- Supplier scorecard: `supplier_leads.score` updated by background job from `negotiation_events` (quote_response_rate), `shipments` (on_time_rate), `disputes` (dispute_rate); displayed in procurement CRM.
- APIs (Internal):
  - `POST /v1/internal/risk-flags` — manual flag.
  - `POST /v1/internal/risk-flags/{id}/resolve`.
  - `POST /v1/internal/users/{id}/freeze` / `unfreeze` (ComplianceAdmin; unfreeze requires second approver).
- Tests: five OTP failures in window → `risk_flags` entry; account freeze blocks API access for that user; supplier scorecard values update after simulated fulfilled orders.

**Depends on:** M5.3, M1.7

**Rollback strategy:** Risk flag resolution by admin. User freeze reversal requires dual approval. No financial rollback required.

**Definition of done:**
- Frozen user JWT returns `403` on any authenticated endpoint (integration test).
- Supplier scorecard `on_time_rate` recalculated correctly from two on-time and one late shipment (unit test on scoring job).
- Duplicate account detection flag created when two users share the same OTP mobile hash (integration test).

**Complexity:** M

---

## Phase 6 — Analytics and Pilot

**Phase goal:** Operational dashboards, security hardening, full E2E test coverage, load tests, and controlled pilot readiness.

**Phase gate:** Positive contribution margin per test order; reconciliation exceptions below threshold; all 20 Section 23 QA cases pass automated; penetration test completed with no critical or high findings outstanding; load test at 2× expected pilot peak passes without data errors.

---

### M6.1 — Analytics dashboards and reporting

**Deliverables:**
- Demand funnel metrics: views → requests → verified → purchase-ready → offer received → accepted → paid → delivered → acknowledged → repeat (Section 20).
- Seller funnel: invited → bid submitted → qualified → negotiated → awarded → fulfilled → settled.
- Procurement KPIs: time-to-first-quote, time-to-approved-offer, sourcing success rate, quote-to-deal rate, agent workload.
- Commercial KPIs: completed GMV, net platform revenue, contribution margin per order/category/city, refund rate, dispute rate, on-time delivery.
- Finance KPIs: payment success rate, reconciliation exception rate, refund aging, chargeback rate.
- Separation enforced: "interest" and "paid demand" are always separate metrics — no aggregation that inflates paid figures.
- APIs (Internal):
  - `GET /v1/internal/analytics/demand-funnel` — with cohort filters (city, category, date range).
  - `GET /v1/internal/analytics/procurement-kpis`.
  - `GET /v1/internal/analytics/commercial-kpis`.
  - `GET /v1/internal/analytics/finance-kpis`.
- UI (Ops Portal): executive dashboard page wiring all KPI endpoints; charts using a lightweight library (Chart.js or similar).
- Scheduled report: daily email to configured recipients with key KPIs; feature-flagged.
- Tests: demand funnel metric for a simulated end-to-end order matches expected values at each stage.

**Depends on:** M4.6, M5.1, M5.2

**Rollback strategy:** Analytics endpoints are read-only; rollback is a code revert. No schema changes required if analytics reads from existing tables.

**Definition of done:**
- Demand funnel test: seed a simulated order through all stages; assert `paid_demand` count equals `interested_demand` count only after gateway payment confirmed (not before).
- Contribution margin calculation correct to the paise for a test order with known commission, gateway fee, and inspection cost (unit test on margin service).
- Dashboard renders without errors in Playwright E2E test.

**Complexity:** L

---

### M6.2 — Security review, penetration testing preparation, and hardening

**Deliverables:**
- OWASP API Top 10 self-assessment: documented review against each item with evidence of mitigation or accepted risk.
- Automated security scan: integrate `phpstan-security-advisories` and `roave/security-advisories` in Composer; npm `audit` for frontend; run in CI.
- Input validation audit: every `POST`/`PATCH` endpoint reviewed for missing validation; OpenAPI request schema validation middleware enforced globally.
- PII access review: enumerate every endpoint that returns PII; confirm each has minimum necessary access scope and is covered by an RBAC test.
- Secrets audit: confirm no credentials in git history (git-secrets or trufflehog scan in CI).
- Rate limiting audit: confirm every public endpoint has a rate limit; thresholds documented.
- Signed URL expiry audit: all object storage URLs have configured TTL; `delivery_evidence`, `negotiation_attachments`, `inspection_findings` evidence tested.
- TLS/HSTS, security headers (CSP, X-Frame-Options, X-Content-Type-Options) configured in Nginx; verified via automated header test.
- Pre-penetration-test report: document all known risks, accepted risks and mitigations; share with external pen tester before engagement.
- Checklist of GDPR/DPDP obligations: consent records, deletion workflows, data processor agreements — mapped to implemented features.

**Depends on:** All prior milestones

**Rollback strategy:** Security hardening changes are configuration and code; rollback via git. Nginx header changes rollback via config revert.

**Definition of done:**
- Zero critical or high findings in Composer and npm security audit (CI gate).
- Trufflehog scan finds zero credential patterns in git history (CI gate).
- Automated HTTP header test passes for all required security headers on staging.
- OWASP API Top 10 self-assessment document reviewed and signed off by tech lead.

**Complexity:** L

---

### M6.3 — End-to-end test suite and all 20 Section 23 QA cases

**Deliverables:**
- Playwright E2E test suite covering the full buyer journey: register → create request → join group → receive offer → accept → pay (sandbox) → dispatch → acknowledge.
- Automated implementation of all 20 QA cases from Section 23 as named, documented test scenarios in `tests/feature/acceptance/`.
- Concurrency test suite: items 4, 7 from Section 23 (bid deadline concurrent writes; 100 simultaneous buyers cannot reserve 101 units) run as PHP parallel-process integration tests.
- Idempotency test suite: items 8, 17 (webhook 3×; failed queue job replay) run as integration tests with controlled re-delivery.
- Cross-role access test suite: items 1, 2 (seller cannot read competitor bid; buyer cannot read internal negotiations) run as parameterized API tests.
- All 20 tests run in CI on every PR to `main`; failures block merge.

**Depends on:** All prior milestones

**Rollback strategy:** Tests are code; rollback via git. Test failures do not modify production state.

**Definition of done:**
- All 20 Section 23 cases have a named test file, pass in CI, and are tagged `@section23`.
- Zero flaky tests over 5 consecutive CI runs (tracked in CI history).
- Concurrency tests use real MySQL transactions (not mocks) and pass consistently.

**Complexity:** L

---

### M6.4 — Load testing and performance baseline

**Deliverables:**
- Load test tool: k6 or Locust scripts for key scenarios: OTP login, browse catalog, join group, accept offer, concurrent checkout (stock reservation).
- Baseline targets at 2× expected pilot peak (to be set based on pilot volume estimate `[CONFIRM]`):
  - `GET /v1/catalog/search` — p95 < 300ms, 0% error.
  - `POST /v1/groups/{id}/join` — p95 < 500ms, 0% error.
  - `POST /v1/orders/{id}/checkout` (concurrent) — p95 < 1,000ms, 0% oversell.
  - Webhook consumer throughput — 50 webhooks/s sustained, 0 duplicates.
- Bottleneck report: identify top 3 slow queries (MySQL slow query log + EXPLAIN); index recommendations applied and re-tested.
- Redis cache hit rate measured for catalog endpoints; target > 85% hit rate under load.
- Worker queue depth monitored under load; dead-letter queue remains empty.
- Load test results documented as `docs/load-test-report.md` (created only if explicitly requested; otherwise recorded in CI artifact).

**Depends on:** M6.3

**Rollback strategy:** Load tests run against staging, not production. Rollback means fixing performance issues and re-running. No data rollback needed.

**Definition of done:**
- All p95 latency targets met at 2× pilot peak load (CI artifact).
- Zero oversell events in concurrent checkout load test (assertion on `stock_reservations` count after test run).
- Slow query report shows no query above 100ms in the critical path after index optimization.

**Complexity:** L

---

### M6.5 — Pilot readiness checklist and controlled launch

**Deliverables:**
- Operations runbook: deploy procedure, rollback procedure, incident response contacts, on-call escalation path.
- Seller onboarding guide: step-by-step KYC, bid submission, dispatch instructions.
- Buyer FAQ: how groups work, what "price pending" means, payment safety, dispute process.
- Payment provider go-live checklist: written approval on file, live credentials rotated into secrets store, `payments.live_mode` flag enabled, sandbox driver disabled in production config.
- Legal/compliance checklist: counsel-approved contract package on file, GST/TCS review complete, grievance officer appointed and contact published, privacy notice live, refund policy live.
- Monitoring: application error rate alert (> 1% 5xx triggers PagerDuty or equivalent), payment webhook failure alert, reconciliation exception alert, queue depth alert.
- First 10 orders: manually supervised by procurement lead; each order reviewed for correct ledger postings, settlement eligibility, and contribution margin.
- Gate decision: proceed to wider pilot only after gate criteria met (Section 21.2 and Section 22 Phase 5 gate).

**Depends on:** M6.2, M6.4, M5.3

**Rollback strategy:** If gate criteria are not met, `payments.live_mode` is set to false and onboarded buyers are notified. No data rollback needed; pending payment intents expire.

**Definition of done:**
- All items in payment provider go-live checklist have a named sign-off person and date.
- All items in legal/compliance checklist have a named sign-off person and date.
- First 10 orders processed with zero reconciliation exceptions and zero oversell events.
- Contribution margin for first 10 orders positive (gross commission > variable costs for each order).

**Complexity:** M

---

## Cross-cutting concerns (apply throughout all phases)

The following requirements are not confined to a single milestone and must be verified at every phase gate:

| Concern | Requirement | Verified by |
|---|---|---|
| Sealed bid confidentiality | Seller cannot access competing bid data via API, export, event, log or admin screen | Automated penetration tests (M2.4, M6.3) |
| Tenant isolation | No cross-organization data leak | Cross-tenant test suite (M1.5, run at every phase) |
| Integer paise | All monetary values stored and computed as integer paise, never float | Unit tests on all financial calculations |
| Idempotency | Webhook replay and job replay produce no duplicate financial state | M4.1 idempotency tests, M6.3 suite |
| Immutable snapshots | Accepted offer terms, bid revisions, ledger entries are append-only | Schema constraint tests + OpenAPI contract tests |
| Audit completeness | Every high-sensitivity action has an audit log entry | M1.7 tests run continuously |
| Feature flags | All incomplete/provider-pending features gated by flag; no mock behavior misrepresented as live | M5.3 flag tests |
| Staff MFA | All staff roles require TOTP; no bypass path exists | M1.4 tests run at every phase gate |
| No self-approval | Financial approvals, risk flag resolution, account freeze cannot be self-approved | M1.6 and M4.6 tests |
| OpenAPI contract | Frontend integrates only against the OpenAPI contract; contract tests run in CI | M1.3 contract tests in every CI run |
