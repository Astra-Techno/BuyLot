# Collective Commerce -- Architecture Document

**Version:** 1.0
**Date:** 2026-11-17
**Status:** Foundational reference for all development phases
**Source of truth:** `Collective_Commerce_Complete_Business_Blueprint.md` (Sections 14-16, 23, 25)

---

## Table of Contents

1. [System Overview](#1-system-overview)
2. [Module Boundaries](#2-module-boundaries)
3. [Authentication & Authorization](#3-authentication--authorization)
4. [Event Flow & Domain Events](#4-event-flow--domain-events)
5. [Data Architecture](#5-data-architecture)
6. [API Design](#6-api-design)
7. [Infrastructure](#7-infrastructure)
8. [Security Principles](#8-security-principles)
9. [Cross-Cutting Concerns](#9-cross-cutting-concerns)

---

## 1. System Overview

Collective Commerce is a **managed group-purchasing and procurement marketplace** for India. It is not a discount catalog -- it is a demand-to-deal engine where a procurement desk actively closes deals even when no seller bids online.

### Three Entry Points

```
+---------------------+     +------------------------+     +---------------------+
|  BUYER-LED REVERSE  |     |  SELLER-LED BULK DEAL  |     |   B2B PROCUREMENT   |
|    PROCUREMENT      |     |                        |     |       / RFQ         |
+----------+----------+     +-----------+------------+     +----------+----------+
           |                            |                             |
           v                            v                             v
   Buyers aggregate          Seller publishes tiers       Business submits RFQ
   demand by SKU/region      Buyers join until MOQ        Verified sellers bid
   Staff source sellers      Payment unlocks deal         Staff assist negotiation
           |                            |                             |
           +----------------------------+-----------------------------+
                                        |
                                        v
                        +-------------------------------+
                        |     MANAGED PROCUREMENT       |
                        |   Sealed Bids + Staff CRM +   |
                        |   Negotiation + Approval      |
                        +---------------+---------------+
                                        |
                                        v
                        +-------------------------------+
                        |      OFFER & COMMITMENT       |
                        |   Final offer -> Accept ->    |
                        |   Pay -> MOQ check -> Order   |
                        +---------------+---------------+
                                        |
                                        v
                        +-------------------------------+
                        |   FULFILLMENT & SETTLEMENT    |
                        |   Dispatch -> Delivery ->     |
                        |   Inspection -> Settlement    |
                        +-------------------------------+
```

### Tech Stack

| Layer        | Technology                                            |
|-------------|-------------------------------------------------------|
| Frontend     | Vue 3 + TypeScript + Vite + Pinia + Tailwind CSS     |
| Backend      | Custom modular PHP 8.3 (NO Laravel), Composer, PSR   |
| Database     | MySQL 8 InnoDB with strict constraints                |
| Cache/Queue  | Redis (caching, job queues, distributed locks)        |
| Web server   | Nginx + PHP-FPM 8.3                                  |
| OS           | Ubuntu Linux                                          |
| Storage      | Object storage (S3-compatible) for documents/evidence |

### Architectural Style

**Modular monolith** -- 20 bounded modules communicating via explicit interfaces and a transactional outbox for domain events. No microservices until justified by scale or team boundaries.

---

## 2. Module Boundaries

Each module owns its tables, exposes typed interfaces (service classes), and publishes/consumes domain events through the outbox. Direct cross-module SQL joins and writes are prohibited.

### 2.1 Module Reference Table

| # | Module | Responsibility | Owned Tables | Exposed Interfaces | Consumes Events |
|---|--------|---------------|--------------|--------------------|-----------------|
| 1 | **Identity** | User registration, OTP auth, JWT issuance, session management, profile, account deletion | `users`, `user_sessions`, `otp_requests` | `AuthService`, `UserService`, `TokenService` | -- |
| 2 | **Organizations** | Business entities, team membership, org-scoped permissions, approval chains | `organizations`, `organization_members`, `addresses` | `OrgService`, `MembershipService` | `identity.user_created` |
| 3 | **Catalog** | Categories, brands, products, variants, attributes, search indexing | `categories`, `brands`, `products`, `product_variants`, `product_attributes` | `CatalogService`, `VariantLookup` | `admin_audit.category_updated` |
| 4 | **Demand** | Buy requests, demand groups, group membership, intent levels (L0-L4), group compatibility | `buy_requests`, `buyer_price_preferences`, `demand_groups`, `group_memberships`, `group_intent_events`, `group_compatibility_rules` | `DemandService`, `GroupService`, `IntentService` | `identity.user_verified`, `catalog.variant_updated` |
| 5 | **RFQ** | Request-for-quotation creation from demand groups, spec snapshots, bid deadlines, seller invitations | `rfqs`, `rfq_seller_invitations` | `RFQService`, `InvitationService` | `demand.grouped` |
| 6 | **Bidding** | Sealed seller bids, immutable revisions, deadline enforcement, bid access control | `seller_bids`, `seller_bid_revisions` | `BidService`, `BidAccessGuard` | `rfq.published`, `supplier_verification.seller_approved` |
| 7 | **ProcurementCRM** | Case management, agent assignment, supplier lead directory, contact logs, offline quotes, negotiation events, tasks, SLA tracking | `procurement_cases`, `supplier_leads`, `supplier_contacts`, `negotiation_events`, `negotiation_attachments`, `procurement_tasks` | `CaseService`, `NegotiationService`, `SupplierLeadService` | `rfq.published`, `bid.submitted`, `demand.grouped` |
| 8 | **SupplierVerification** | Seller KYC/GST verification, bank account verification, risk scoring, compliance flags | `seller_profiles`, `seller_verification_cases`, `seller_bank_accounts` | `VerificationService`, `SellerProfileService` | `identity.user_created` (seller role) |
| 9 | **Offers** | Negotiated offer composition, price tiers, approval workflows, publishing to buyers, expiry management | `negotiated_offers`, `offer_price_tiers`, `offer_approvals` | `OfferService`, `OfferApprovalService` | `bid.submitted`, `procurement_crm.negotiation_completed` |
| 10 | **Commitments** | Buyer offer acceptance, stock reservations, accepted-terms hash, checkout eligibility | `offer_acceptances`, `stock_reservations` | `CommitmentService`, `ReservationService` | `offer.approved`, `offer.published` |
| 11 | **Orders** | Order creation from commitments, order items, status machine, seller fulfillment orders, terms snapshots | `orders`, `order_items`, `order_status_events`, `seller_fulfillment_orders` | `OrderService`, `OrderStatusService` | `payment.captured`, `moq.reached` |
| 12 | **Payments** | Payment intents, gateway handoff, webhook ingestion, idempotency, refund orchestration | `payment_intents`, `payment_webhook_events`, `refunds` | `PaymentService`, `RefundService`, `WebhookProcessor` | `commitments.accepted`, `order.released`, `dispute.resolved` |
| 13 | **Ledger** | Double-entry financial ledger, balanced postings, immutable entries, reconciliation runs | `ledger_accounts`, `ledger_entries`, `ledger_postings`, `reconciliation_runs`, `reconciliation_exceptions` | `LedgerService`, `ReconciliationService` | `payment.captured`, `refund.completed`, `settlement.confirmed` |
| 14 | **Settlements** | Seller settlement eligibility, approval workflow, provider payout requests, confirmed settlement tracking | `seller_settlements`, `settlement_items`, `settlement_approvals` | `SettlementService`, `SettlementApprovalService` | `delivery.verified`, `dispute.resolved`, `payment.captured` |
| 15 | **Fulfillment** | Shipments, carrier tracking, shipment items, delivery evidence, POD, partial delivery tracking | `shipments`, `shipment_items`, `delivery_evidence` | `FulfillmentService`, `ShipmentTracker` | `order.released`, `order.dispatched` |
| 16 | **Quality** | Risk-based inspection tiers (A/B/C), inspector assignment, checklists, findings, buyer acknowledgements | `inspections`, `inspection_findings`, `buyer_acknowledgements` | `InspectionService`, `AcknowledgementService` | `shipment.dispatched`, `delivery.verified` |
| 17 | **Disputes** | Dispute lifecycle, evidence collection, resolution workflow, financial adjustments, escalation | `disputes`, `dispute_events`, `dispute_evidence`, `dispute_adjustments` | `DisputeService`, `EvidenceService` | `quality.issue_reported`, `delivery.verified`, `buyer_acknowledgement.submitted` |
| 18 | **Notifications** | Multi-channel delivery (in-app, SMS, email, WhatsApp), templates, deduplication, consent, delivery audit | `notifications`, `notification_deliveries`, `consent_records` | `NotificationService`, `ChannelRouter` | All major domain events (fan-out consumer) |
| 19 | **Analytics** | Demand funnel, seller funnel, procurement KPIs, commercial KPIs, finance KPIs, cohort reporting | `analytics_events`, `kpi_snapshots` (read replicas / materialized) | `AnalyticsService`, `DashboardService` | All major domain events (async projection) |
| 20 | **AdminAudit** | Immutable audit logs, risk flags, policy versions, feature flags, staff permission management, RBAC/ABAC definitions | `audit_logs`, `risk_flags`, `policy_versions`, `roles`, `permissions`, `role_permissions`, `user_role_assignments` | `AuditService`, `RBACService`, `FeatureFlagService` | All state-changing events |

### 2.2 Module Dependency Diagram

```
                    Identity
                   /    |    \
          Organizations |   AdminAudit (RBAC)
            |           |        |
          Catalog     Demand ----+---- Notifications
            |        /  |  \              |
           RFQ <----+   |   Commitments   |
          / |  \        |       |         |
    Bidding | ProcurementCRM    |         |
         \  |  /        |      |         |
          Offers -------+      |         |
            |                  |         |
        Commitments -----------+         |
            |                            |
          Orders ---- Payments           |
          / |  \        |     \          |
  Fulfillment | Ledger  |   Settlements  |
      |       |    |    |       |        |
   Quality  Disputes ---+-------+        |
      |       |                          |
      +-------+----- Analytics ----------+
                        |
              SupplierVerification
```

Arrows indicate "depends on / consumes from." All modules emit events consumed by Notifications, Analytics, and AdminAudit.

---

## 3. Authentication & Authorization

### 3.1 Authentication Flow

```
Client                   API Gateway              Identity Module
  |                          |                          |
  |-- POST /v1/auth/otp/request (mobile) ------------->|
  |                          |                          |-- generate OTP
  |                          |                          |-- send via SMS provider
  |<--------------------- 200 OK (otp_request_id) -----|
  |                          |                          |
  |-- POST /v1/auth/otp/verify (mobile + otp) -------->|
  |                          |                          |-- verify OTP
  |                          |                          |-- create/lookup user
  |                          |                          |-- issue JWT (access + refresh)
  |<--------------------- 200 OK (tokens) -------------|
  |                          |                          |
  |-- GET /v1/me (Authorization: Bearer <jwt>) ------->|
  |                          |-- validate JWT ----------|
  |                          |-- extract claims --------|
  |<--------------------- 200 OK (user profile) -------|
```

- **OTP-based authentication** -- no passwords. Mobile number is the primary identity.
- **JWT tokens** -- short-lived access token (15-30 min), longer-lived refresh token (days).
- **Token claims:** `user_id`, `roles[]`, `org_id` (if applicable), `scope`, `iat`, `exp`.
- **Staff accounts** require MFA (OTP + additional factor) for internal portal access.

### 3.2 RBAC + ABAC Model

**12 Roles:**

| # | Role | Scope | Portal |
|---|------|-------|--------|
| 1 | Guest | Public data only | Buyer web |
| 2 | Buyer | Own requests, orders, offers | Buyer web |
| 3 | Business Buyer | Organization-scoped purchasing | Buyer web |
| 4 | Seller | Own bids, offers, orders, settlements | Seller portal |
| 5 | Procurement Agent | Assigned cases and bids | Internal ops |
| 6 | Procurement Lead | Authorized team cases, approval | Internal ops |
| 7 | Quality Inspector | Assigned inspection scope | Internal ops |
| 8 | Operations Agent | Assigned order/fulfillment data | Internal ops |
| 9 | Finance Operator | Financial data (read + limited write) | Internal ops |
| 10 | Finance Approver | Settlement/refund approval authority | Internal ops |
| 11 | Compliance/Admin | Seller verification, risk, audit, policies | Internal ops |
| 12 | Super Admin | System configuration, access grants (MFA, audited) | Internal ops |

**ABAC Dimensions (beyond role):**

| Dimension | Examples |
|-----------|---------|
| Ownership | Buyer sees only own orders; seller sees only own bids |
| Organization | Business buyer limited to their org's requests/orders |
| Assignment | Procurement agent sees only cases assigned to them |
| Category | Seller can bid only in approved categories |
| Amount threshold | Maker-checker required above configurable limits |
| Environment | Staff features gated by IP/VPN or feature flag |

**Enforcement points:**
1. **Middleware** -- JWT validation, role extraction, rate limiting
2. **Service layer** -- ownership/org/assignment checks before data access
3. **Query scoping** -- every DB query includes tenant/ownership WHERE clauses
4. **API serializer** -- field-level filtering (e.g., strip bid amounts from non-owner responses)

### 3.3 Bid Confidentiality Enforcement

Sealed bid access is enforced at every layer:

| Layer | Control |
|-------|---------|
| SQL queries | `WHERE seller_id = :current_seller_id` on all bid reads |
| API endpoints | `BidAccessGuard` rejects cross-seller bid access |
| API serializers | Never include other sellers' bid data in any response |
| Background jobs | Workers process bids without leaking to notifications |
| Exports/reports | Filtered by seller scope; internal comparison requires Procurement Lead+ role |
| Logs | Bid amounts never written to application logs |
| Notifications | Bid status updates never include competitor information |
| Analytics | No public "savings" calculations that reveal losing bid amounts |

**Critical rule:** No user may approve their own high-risk financial adjustment. Segregation of duties is enforced in the approval chain.

---

## 4. Event Flow & Domain Events

### 4.1 Transactional Outbox Pattern

```
+-----------+     +----------------+     +-----------+     +------------+
|  Service  |---->|   MySQL TX     |---->|  outbox   |---->|   Redis    |
|  method   |     | 1. write data  |     |  table    |     |  worker    |
|           |     | 2. insert      |     |           |     |  (poller)  |
|           |     |    outbox row  |     |           |     |            |
+-----------+     +----------------+     +-----------+     +-----+------+
                                                                 |
                                                                 v
                                                          +------+-------+
                                                          |   Event      |
                                                          |   Handlers   |
                                                          | (idempotent) |
                                                          +--------------+
```

**Guarantees:**
- Data mutation and event publication are in the **same MySQL transaction** -- no lost events.
- A Redis-backed poller reads the outbox and dispatches to handlers.
- **At-least-once delivery** -- handlers MUST be idempotent (use `event_id` + `processed_at` dedup).
- Failed handlers retry with exponential backoff; dead-letter after max attempts.

**Outbox table schema:**
```sql
CREATE TABLE outbox_events (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_type      VARCHAR(100) NOT NULL,       -- e.g. 'demand.grouped'
    aggregate_type  VARCHAR(50) NOT NULL,         -- e.g. 'demand_group'
    aggregate_id    BIGINT UNSIGNED NOT NULL,
    payload_json    JSON NOT NULL,
    created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    published_at    DATETIME(6) NULL,
    INDEX idx_unpublished (published_at, created_at)
) ENGINE=InnoDB;
```

### 4.2 Key Domain Events

| Event | Publisher | Key Consumers | Trigger |
|-------|-----------|---------------|---------|
| `demand.grouped` | Demand | RFQ, ProcurementCRM, Notifications | Compatible requests form a group |
| `rfq.published` | RFQ | Bidding, ProcurementCRM, Notifications | RFQ goes live for seller bidding |
| `bid.submitted` | Bidding | ProcurementCRM, Offers, AdminAudit | Seller submits or revises a bid |
| `offer.approved` | Offers | Commitments, Notifications | Internal approval of final offer |
| `offer.published` | Offers | Commitments, Notifications, Demand | Offer visible to buyers |
| `offer.accepted` | Commitments | Orders, Payments, Notifications | Buyer explicitly accepts offer |
| `payment.captured` | Payments | Orders, Ledger, Commitments, Notifications | Gateway confirms payment |
| `moq.reached` | Commitments | Orders, Fulfillment, Notifications | Paid quantity meets MOQ threshold |
| `order.released` | Orders | Fulfillment, Settlements, Notifications | Order sent to supplier for fulfillment |
| `shipment.dispatched` | Fulfillment | Quality, Notifications, Analytics | Seller dispatches shipment |
| `delivery.verified` | Fulfillment | Quality, Settlements, Notifications | POD confirmed |
| `buyer_ack.submitted` | Quality | Settlements, Disputes, Notifications | Buyer accepts or reports issue |
| `settlement.approved` | Settlements | Ledger, Notifications, Analytics | Finance approver authorizes payout |
| `settlement.confirmed` | Settlements | Ledger, Notifications | Provider confirms bank payout |
| `dispute.opened` | Disputes | Settlements, Notifications, AdminAudit | Buyer or seller opens dispute |
| `dispute.resolved` | Disputes | Settlements, Payments, Notifications | Resolution with financial adjustment |
| `refund.completed` | Payments | Ledger, Notifications, Analytics | Gateway confirms refund |

### 4.3 Event Flow: Happy Path (Buyer-Led)

```
 demand.grouped
       |
       v
 rfq.published
       |
       v
 bid.submitted (1..N sellers)
       |
       v
 [Procurement negotiation -- CRM internal]
       |
       v
 offer.approved --> offer.published
                         |
                         v
                   offer.accepted (1..N buyers)
                         |
                         v
                   payment.captured (per buyer)
                         |
                         v
                   moq.reached (aggregate check)
                         |
                         v
                   order.released
                         |
                         v
                   shipment.dispatched
                         |
                         v
                   delivery.verified
                         |
                         v
                   buyer_ack.submitted
                         |
                         v
                   settlement.approved
                         |
                         v
                   settlement.confirmed
```

---

## 5. Data Architecture

### 5.1 MySQL 8 InnoDB

**Core conventions:**

| Convention | Detail |
|-----------|--------|
| Primary keys | `BIGINT UNSIGNED AUTO_INCREMENT` (or ULID `CHAR(26)` for distributed-friendly IDs) |
| Money | `BIGINT` storing **integer paise** (1 INR = 100 paise). Column suffix: `_paise` |
| Currency | `CHAR(3) DEFAULT 'INR'` stored alongside every monetary amount |
| Timestamps | `DATETIME(6)` in **UTC**. Display conversion to IST in the frontend |
| Soft deletes | Only where legally required; prefer append-only status transitions |
| Audit columns | `created_at`, `updated_at`, `created_by`, `updated_by` on all tables |
| Character set | `utf8mb4` with `utf8mb4_unicode_ci` collation (Tamil + English support) |
| Transactions | InnoDB with `SELECT ... FOR UPDATE` for stock/commitment concurrency |
| Constraints | Foreign keys, NOT NULL, CHECK (non-negative quantities/amounts), UNIQUE composites |

**Immutable tables** (append-only, never UPDATE/DELETE):
- `seller_bid_revisions` -- bid history
- `ledger_entries`, `ledger_postings` -- financial ledger
- `audit_logs` -- all state changes
- `payment_webhook_events` -- raw gateway events
- `order_status_events` -- order state transitions
- `group_intent_events` -- buyer intent changes
- `negotiation_events` -- procurement history

### 5.2 Key Indexes

```sql
-- Demand matching
INDEX idx_buy_requests_match ON buy_requests(region_id, variant_id, status);
INDEX idx_demand_groups_match ON demand_groups(match_key, status);

-- Bid lookup (sealed)
UNIQUE INDEX idx_seller_bid_identity ON seller_bids(rfq_id, seller_id);

-- Order queries
INDEX idx_orders_buyer ON orders(buyer_id, status);

-- Fulfillment tracking
INDEX idx_shipments_status ON shipments(status);

-- Settlement pipeline
INDEX idx_settlements_seller ON seller_settlements(seller_id, status);

-- Webhook dedup
UNIQUE INDEX idx_webhook_dedup ON payment_webhook_events(provider, provider_event_id);

-- Idempotency
UNIQUE INDEX idx_payment_idempotency ON payment_intents(idempotency_key);
```

### 5.3 Redis Usage

| Purpose | Pattern | Key Example |
|---------|---------|-------------|
| Session/token cache | Key-value with TTL | `session:{user_id}` |
| Rate limiting | Sliding window counter | `rate:{ip}:{endpoint}` |
| Job queue | Redis LIST (BRPOP) | `queue:payments`, `queue:notifications` |
| Distributed lock | SET NX EX | `lock:stock:{offer_id}` |
| Cache | Key-value with TTL | `cache:catalog:{variant_id}` |
| Outbox polling | Lightweight signal | `signal:outbox:pending` |

### 5.4 Encryption & PII

| Data | Storage |
|------|---------|
| Mobile numbers | Stored; displayed masked in UI except to owner |
| Email addresses | Stored; masked in logs |
| Physical addresses | `encrypted_detail` column (AES-256-GCM, app-level encryption) |
| Buyer max budget | Visible only to buyer + authorized procurement staff |
| Seller bank accounts | Provider token reference only; no raw account numbers stored |
| Gateway credentials | Environment variables / secrets manager; never in DB or logs |
| Bid amounts | Column-level access control; never in logs or non-owner responses |

---

## 6. API Design

### 6.1 Conventions

| Aspect | Standard |
|--------|----------|
| Style | RESTful JSON over HTTPS |
| Contract | OpenAPI 3.1 specification (generated and tested) |
| Versioning | URI prefix: `/v1/` |
| Authentication | `Authorization: Bearer <JWT>` header |
| Content-Type | `application/json` request and response |
| Idempotency | `Idempotency-Key` header on all write operations |
| Pagination | Cursor-based: `?cursor=<opaque>&limit=25` |
| Filtering | Query params: `?status=active&region_id=5` |
| Sorting | `?sort=created_at&order=desc` |

### 6.2 Endpoint Structure

```
/v1/auth/otp/*                      -- Identity (public)
/v1/catalog/*                        -- Catalog (public read)
/v1/deals/*                          -- Seller-led deals (public read)
/v1/buy-requests/*                   -- Demand (buyer auth)
/v1/groups/*                         -- Demand groups (buyer auth)
/v1/offers/*                         -- Offers (buyer auth for read/accept)
/v1/orders/*                         -- Orders (buyer auth)
/v1/me/*                             -- User profile/account (buyer auth)
/v1/seller/*                         -- Seller portal (seller auth)
/v1/internal/*                       -- Ops portal (staff auth + role check)
/v1/webhooks/{provider}              -- Payment webhooks (signature verification)
```

### 6.3 Structured Error Response

```json
{
  "error": {
    "code": "OFFER_EXPIRED",
    "message": "This offer expired on 2026-11-15T18:30:00Z.",
    "details": [
      {
        "field": "offer_id",
        "issue": "Offer deadline has passed."
      }
    ],
    "request_id": "req_abc123def456"
  }
}
```

**Standard HTTP status codes:** 200, 201, 204, 400, 401, 403, 404, 409, 422, 429, 500, 503.

### 6.4 Rate Limiting

| Tier | Limit | Scope |
|------|-------|-------|
| Public (unauthenticated) | 30 req/min | Per IP |
| Authenticated buyer/seller | 120 req/min | Per user |
| Internal staff | 300 req/min | Per user |
| OTP request | 5 req/15 min | Per mobile number |
| Webhook ingestion | 1000 req/min | Per provider IP |

Rate limit headers: `X-RateLimit-Limit`, `X-RateLimit-Remaining`, `X-RateLimit-Reset`.

### 6.5 Idempotency

All POST endpoints that create resources or trigger side effects accept an `Idempotency-Key` header. The server:
1. Checks if the key exists in `idempotency_store` (Redis, 24h TTL).
2. If found, returns the cached response (same status code and body).
3. If not found, processes the request, stores the response, returns it.

Critical for: payment intents, order creation, refund requests, settlement approvals, bid submissions.

---

## 7. Infrastructure

### 7.1 Deployment Architecture

```
                          +-------------------+
                          |   Cloudflare /    |
                          |   CDN + WAF       |
                          +---------+---------+
                                    |
                          +---------+---------+
                          |      Nginx        |
                          | (SSL termination, |
                          |  static files,    |
                          |  reverse proxy)   |
                          +---------+---------+
                           /                 \
              +-----------+---+         +----+-----------+
              |   PHP-FPM 8.3 |         |  Vue 3 SSR /  |
              |   API workers  |         |  Static PWA   |
              |   (N processes)|         |  (Nginx)      |
              +-------+-------+         +----------------+
                      |
         +------------+------------+
         |                         |
  +------+------+          +-------+------+
  |  MySQL 8    |          |    Redis     |
  |  (primary)  |          | (cache/queue)|
  +------+------+          +--------------+
         |
  +------+------+
  |  MySQL 8    |
  |  (replica,  |
  |   read-only)|
  +-------------+

  +------------------+     +------------------+
  |  Object Storage  |     |  SMS / Email /   |
  |  (S3-compatible) |     |  WhatsApp APIs   |
  +------------------+     +------------------+
```

### 7.2 Component Responsibilities

| Component | Role |
|-----------|------|
| **Nginx** | SSL termination, static asset serving, reverse proxy to PHP-FPM, request buffering, gzip |
| **PHP-FPM 8.3** | Stateless API request processing, N worker processes, opcache enabled |
| **Redis workers** | Background job processing: outbox polling, notification dispatch, reconciliation, analytics projection |
| **MySQL 8 primary** | All writes, transactional reads requiring consistency |
| **MySQL 8 replica** | Analytics queries, dashboard reads, search indexing (optional, Phase 5+) |
| **Object storage** | Bid evidence, inspection photos, delivery POD, negotiation attachments, invoices |
| **CDN/WAF** | DDoS protection, static caching, geographic distribution |

### 7.3 CI/CD Pipeline

```
 Git Push --> Lint (PHP CS Fixer + ESLint) --> Static Analysis (PHPStan L8)
     --> Unit Tests (PHPUnit) --> Integration Tests (MySQL + Redis)
     --> OpenAPI Contract Validation --> Build Vue 3 (Vite)
     --> Deploy to Staging --> Smoke Tests --> Manual Approval
     --> Deploy to Production (rolling) --> Health Check
```

### 7.4 Backups & Recovery

| Asset | Strategy | Frequency | Retention |
|-------|----------|-----------|-----------|
| MySQL | Automated logical + physical backup | Daily full, hourly binlog | 30 days |
| Redis | RDB snapshots (non-critical; rebuild from MySQL) | Every 15 min | 7 days |
| Object storage | Provider-level redundancy + cross-region copy | Continuous | Per retention policy |
| Application code | Git repository + tagged releases | Every deploy | Indefinite |

**RTO target:** < 4 hours. **RPO target:** < 1 hour (binlog point-in-time recovery).

---

## 8. Security Principles

### 8.1 Core Rules

| Principle | Implementation |
|-----------|---------------|
| No raw credentials in logs | Middleware strips `Authorization`, bid amounts, PII from log output |
| Sealed bid access control | `BidAccessGuard` at service + query + serializer + export + notification layers |
| MFA for staff | OTP + second factor required for internal portal; session timeout 30 min |
| Webhook signature verification | Raw body HMAC verification before any processing; reject on mismatch |
| OWASP API Top 10 compliance | Input validation, output encoding, parameterized queries, CORS, CSP headers |
| No direct SQL interpolation | All queries use prepared statements with bound parameters |
| Secrets management | Environment variables or vault; never committed to repository or database |
| Upload security | File type validation, virus scanning, private signed URLs with expiry |
| Session security | HttpOnly, Secure, SameSite cookies; JWT stored in memory (not localStorage) |

### 8.2 Bid Confidentiality (Reiterated as Security Control)

This is both a business rule and a security requirement. It is tested in the QA matrix (Section 23 items 1-2):
- Seller A cannot read Seller B's bid via API, ID guessing, export, websocket, notification, or logs.
- Buyer cannot see internal negotiations or private max budgets of other buyers.
- Internal bid comparison requires Procurement Lead+ role with explicit permission.
- Penetration testing must include direct object reference and enumeration attacks against bid endpoints.

### 8.3 Financial Security

| Control | Detail |
|---------|--------|
| Maker-checker | No single actor can both initiate and approve a settlement, refund > threshold, or bank detail change |
| Immutable ledger | Financial entries are append-only; corrections via reversing entries |
| Reconciliation | Daily automated: orders <-> payments <-> refunds <-> provider settlements <-> seller payable |
| Duplicate prevention | Idempotency keys on payment intents; unique webhook event IDs |
| Bank detail changes | Verification + cooling-off period + dual approval before activation |

### 8.4 Data Protection

- PII encryption at rest (address details via AES-256-GCM).
- TLS 1.2+ for all data in transit.
- Retention limits enforced; deletion/export workflows for DPDP compliance.
- Consent records tracked per user per purpose.
- Incident response plan documented and tested.

---

## 9. Cross-Cutting Concerns

### 9.1 Feature Flags

Feature flags gate the rollout of capabilities without code deploys:

| Flag | Purpose |
|------|---------|
| `ff_city_{id}_enabled` | City-level launch control |
| `ff_category_{id}_enabled` | Category-level launch control |
| `ff_deposit_experiment` | Token advance / deposit capability (V1: off) |
| `ff_payment_provider_{name}` | Gateway toggle |
| `ff_inspection_tier_c` | On-site inspection (pilot only) |
| `ff_seller_led_deals` | Seller-initiated deal creation |
| `ff_b2b_org_approvals` | Organization approval chains |
| `ff_whatsapp_notifications` | WhatsApp Business message channel |

Flags stored in `policy_versions` table, cached in Redis, evaluated per-request in middleware. Admin UI for toggling. All flag evaluations logged for audit.

### 9.2 Localization Readiness

| Aspect | Approach |
|--------|----------|
| Languages | English (default), Tamil (launch market). Additive -- Hindi, etc. |
| Architecture | All user-facing strings in locale JSON files (`en.json`, `ta.json`) |
| Backend | Error codes (not messages) in API responses; frontend renders locale-appropriate text |
| Currency | INR only in V1; `currency` column on all monetary tables for future extensibility |
| Number formatting | Indian numbering system (lakhs/crores) in display |
| Dates | UTC storage, IST display with explicit timezone label |
| Right-to-left | Not required for Tamil/English; architecture allows future RTL CSS |

### 9.3 Mobile-First PWA

| Requirement | Implementation |
|-------------|---------------|
| Responsive design | Tailwind CSS breakpoints; mobile-first component design |
| PWA manifest | `manifest.json` with app name, icons, theme color, start URL |
| Service worker | Offline shell caching, API request queue for intermittent connectivity |
| Install prompt | Smart banner after second visit |
| Performance budget | < 3s first meaningful paint on 3G; < 200KB initial JS bundle (gzipped) |
| Touch targets | Minimum 44x44px tap targets per WCAG |
| Low bandwidth | Lazy-loaded images, skeleton loaders, compressed API responses |
| WhatsApp sharing | Deep-linkable public deal/request pages with OG meta tags |

### 9.4 SSR for SEO Pages

Public pages that must be search-indexed receive server-side rendering or pre-rendering:

| Page | SSR Strategy |
|------|-------------|
| Home / category listings | Pre-rendered at build time, revalidated periodically |
| Active public deals | SSR on request (cached 5 min) |
| Product / demand group pages | SSR on request (cached, busted on status change) |
| Seller storefront (if public) | Pre-rendered |

Authenticated pages (dashboard, checkout, internal ops) are client-side rendered SPA -- no SSR needed.

### 9.5 Observability

| Signal | Tool |
|--------|------|
| Structured logging | JSON logs with `request_id`, `user_id`, `module`, `event` |
| Error tracking | Centralized exception capture with stack traces and context |
| Metrics | Request latency, queue depth, payment success rate, error rate |
| Health checks | `/health` endpoint checking MySQL, Redis, and worker liveness |
| Alerting | Thresholds on error rate, queue backlog, reconciliation exceptions, failed payments |

---

## Appendix A: Module-to-Table Ownership Matrix

| Module | Tables Owned |
|--------|-------------|
| Identity | `users`, `user_sessions`, `otp_requests` |
| Organizations | `organizations`, `organization_members`, `addresses` |
| Catalog | `categories`, `brands`, `products`, `product_variants`, `product_attributes` |
| Demand | `buy_requests`, `buyer_price_preferences`, `demand_groups`, `group_memberships`, `group_intent_events`, `group_compatibility_rules` |
| RFQ | `rfqs`, `rfq_seller_invitations` |
| Bidding | `seller_bids`, `seller_bid_revisions` |
| ProcurementCRM | `procurement_cases`, `supplier_leads`, `supplier_contacts`, `negotiation_events`, `negotiation_attachments`, `procurement_tasks` |
| SupplierVerification | `seller_profiles`, `seller_verification_cases`, `seller_bank_accounts` |
| Offers | `negotiated_offers`, `offer_price_tiers`, `offer_approvals` |
| Commitments | `offer_acceptances`, `stock_reservations` |
| Orders | `orders`, `order_items`, `order_status_events`, `seller_fulfillment_orders` |
| Payments | `payment_intents`, `payment_webhook_events`, `refunds` |
| Ledger | `ledger_accounts`, `ledger_entries`, `ledger_postings`, `reconciliation_runs`, `reconciliation_exceptions` |
| Settlements | `seller_settlements`, `settlement_items`, `settlement_approvals` |
| Fulfillment | `shipments`, `shipment_items`, `delivery_evidence` |
| Quality | `inspections`, `inspection_findings`, `buyer_acknowledgements` |
| Disputes | `disputes`, `dispute_events`, `dispute_evidence`, `dispute_adjustments` |
| Notifications | `notifications`, `notification_deliveries`, `consent_records` |
| Analytics | `analytics_events`, `kpi_snapshots` |
| AdminAudit | `audit_logs`, `risk_flags`, `policy_versions`, `roles`, `permissions`, `role_permissions`, `user_role_assignments` |

---

*This document is the foundational architecture reference for Collective Commerce. All module implementations, API designs, database schemas, and security controls must conform to the boundaries and conventions defined here. Deviations require documented approval.*
