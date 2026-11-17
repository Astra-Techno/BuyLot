# Test Plan — Collective Commerce Platform

**Version:** 1.0
**Date:** 2026-11-17
**Source authority:** Collective Commerce Complete Business Blueprint v1.0, Section 23
**Stack:** Custom PHP 8.3 backend · Vue 3 / TypeScript frontend · MySQL 8 · Redis · PHPUnit · Cypress/Playwright · k6

---

## 1. Core Acceptance Tests

> Each test is derived directly from the 20 acceptance criteria in Section 23 of the blueprint. Priority and Phase assignments follow the development roadmap defined in Section 22.

---

### T01 — Seller A Cannot Read Seller B's Bid

**Priority:** Critical
**Phase:** Phase 2 (Demand and Sealed Procurement)

**Preconditions / Setup**
- Two approved seller accounts exist: `seller_a` and `seller_b`.
- An active RFQ is open and both sellers have submitted bids.
- `seller_a` has submitted `bid_id_A`; `seller_b` has submitted `bid_id_B`.
- Database seed includes `rfq_seller_invitations`, `seller_bids`, and `seller_bid_revisions` rows for both.

**Steps**
1. Authenticate as `seller_a` (obtain JWT/session token).
2. `GET /v1/seller/rfqs/{rfq_id}/bids` — assert the response list contains only `bid_id_A` and no reference to `bid_id_B` or `seller_b` identity.
3. `GET /v1/seller/bids/{bid_id_B}` (direct object access / IDOR) — assert HTTP 403 or 404.
4. `GET /v1/seller/bids?rfq_id={rfq_id}` with any enumeration parameter — assert no cross-seller leakage.
5. `GET /v1/seller/rfqs/{rfq_id}/export` (if export endpoint exists) — assert exported file contains only `seller_a`'s own rows.
6. Connect to the websocket notification channel authenticated as `seller_a`; trigger an internal bid-status event for `bid_id_B` server-side; assert no message is delivered to `seller_a`'s channel.
7. As a user with log/audit access, verify that application logs (`audit_logs`) contain no raw bid prices or seller identities in fields visible to seller roles.
8. Repeat steps 2–4 with an unauthenticated request and with a buyer account; assert 401/403 in all cases.

**Expected Result**
All requests return only `seller_a`'s own data or an appropriate denial code. No bid value, ranking, identity or negotiation note belonging to `seller_b` appears in any response, export, websocket payload or log entry visible to `seller_a`.

---

### T02 — Buyer Cannot See Internal Negotiations or Other Buyers' Budgets

**Priority:** Critical
**Phase:** Phase 2 (Demand and Sealed Procurement)

**Preconditions / Setup**
- Two buyer accounts exist: `buyer_1` and `buyer_2`, both members of `demand_group_X`.
- `buyer_2` has a `buyer_price_preferences` row with `max_paise = 2900000`.
- The group has an active `procurement_case` with `negotiation_events` and internal `negotiation_attachments`.

**Steps**
1. Authenticate as `buyer_1`.
2. `GET /v1/groups/{group_id}` — assert no `max_paise` or any individual budget figure for `buyer_2` is present.
3. `GET /v1/buy-requests/{request_id_of_buyer_2}` — assert HTTP 403 or that `max_paise` is absent/null in the response.
4. Attempt to access any internal procurement endpoint: `GET /v1/internal/procurement/cases/{case_id}` — assert HTTP 403.
5. Attempt `GET /v1/internal/rfqs/{rfq_id}/confidential-bids` — assert HTTP 403.
6. Check all websocket channels subscribed by `buyer_1`; assert that negotiation events never appear.
7. Inspect the buyer-facing group detail screen (Cypress/Playwright): confirm counts (interested/ready) are aggregate-only with no individual name, budget or bid data.

**Expected Result**
`buyer_1` sees only their own budget preference and privacy-safe aggregate counts. No other buyer's `max_paise`, identity or any internal negotiation content is accessible by any buyer-tier API or UI route.

---

### T03 — Offline Quote Requires Supplier Confirmation and Evidence

**Priority:** High
**Phase:** Phase 2 (Demand and Sealed Procurement)

**Preconditions / Setup**
- An authenticated procurement agent account exists.
- A `procurement_case` is in `SOURCING` stage with an assigned agent.
- A `supplier_lead` record exists for the target supplier.

**Steps**
1. As procurement agent, attempt `POST /v1/internal/cases/{case_id}/offline-quotes` with no `evidence_ref` and no `supplier_confirmation` flag — assert HTTP 422 with field-level validation error identifying both missing fields.
2. Retry with `evidence_ref` supplied but `supplier_acknowledgment = false` — assert HTTP 422.
3. Retry with `evidence_ref` supplied, `supplier_acknowledgment = true`, but no `contacted_by` / author identity — assert HTTP 422.
4. Submit a fully valid request: valid `evidence_ref` (pre-uploaded object storage key), `supplier_acknowledgment = true`, correct `source = "TEAM_SOURCED"`, matching `supplier_lead_id`, author from session. Assert HTTP 201.
5. Retrieve the created `seller_bid_revision` via internal API; verify `source = "TEAM_SOURCED"`, `evidence_ref` is present, `submitted_at` is set, and `submitted_by` matches the agent's user ID.
6. Verify an `audit_log` entry records the agent identity, timestamp and quote amount.
7. Verify no `negotiated_offer` can be created from this quote until the case reaches `APPROVED` state (attempt and assert 422 or 409).

**Expected Result**
The API rejects offline quotes that lack evidence or supplier confirmation at the validation layer, before any database write. A valid submission is persisted with full author attribution and evidence reference.

---

### T04 — Bid Deadline and Revisions Behave Correctly Under Concurrent Writes

**Priority:** Critical
**Phase:** Phase 2 (Demand and Sealed Procurement)

**Preconditions / Setup**
- An RFQ has `bid_deadline = now() + 10 seconds` (set dynamically in test setup).
- `seller_a` has an existing bid with `revision_no = 1`.
- Database row-level locking is available (`SELECT ... FOR UPDATE`).

**Steps**
1. At T−2 seconds, send 10 concurrent `POST /v1/seller/bids/{bid_id}/revisions` requests from `seller_a` with slightly varying prices using an HTTP concurrency tool (k6 or parallel curl).
2. Assert that exactly one new `seller_bid_revision` row is created with an incremented `revision_no`; all other concurrent requests return HTTP 409 (conflict) or 422 (deadline imminent), not 500.
3. At T+1 second (after deadline), send a valid revision request — assert HTTP 422 with reason `BID_DEADLINE_PASSED`.
4. Attempt a deadline extension: `POST /v1/internal/rfqs/{rfq_id}/extend-deadline` as a procurement lead — assert the extension is logged in `audit_logs` with actor, old deadline and new deadline.
5. After extension, verify `seller_a` can submit a revision again (assert HTTP 201).
6. Verify `seller_bid_revisions` table shows an append-only sequence; no revision row has been updated or deleted.

**Expected Result**
Under concurrent writes, exactly one revision is committed. Post-deadline submissions are rejected. Deadline extension is logged. All revision records are immutable and append-only.

---

### T05 — Offer Cannot Publish Without Required Approvals, Supplier Confirmation and Valid Terms

**Priority:** Critical
**Phase:** Phase 2 / Phase 3 (Offer conversion)

**Preconditions / Setup**
- A `negotiated_offer` record exists in `DRAFT` status with associated `offer_price_tiers`.
- The approval policy requires at least one `offer_approval` with `decision = APPROVED` before publish.
- A supplier confirmation reference (`supplier_confirmation_ref`) is required on `seller_fulfillment_orders`.

**Steps**
1. Attempt `POST /v1/internal/offers/{offer_id}/publish` with no approvals recorded — assert HTTP 422 with reason `APPROVALS_REQUIRED`.
2. Add one approval (`offer_approvals` row, `decision = APPROVED`) below the required approval threshold — assert publish still returns HTTP 422.
3. Meet the approval count but leave `supplier_confirmation_ref` null on the linked `seller_fulfillment_orders` row — attempt publish — assert HTTP 422 with reason `SUPPLIER_CONFIRMATION_MISSING`.
4. Add `supplier_confirmation_ref` but set `expires_at` to a past timestamp — attempt publish — assert HTTP 422 with reason `OFFER_EXPIRED`.
5. Set `expires_at` to a valid future time, all approvals met, supplier confirmed — `POST publish` — assert HTTP 200 and `negotiated_offer.status = PUBLISHED`.
6. Verify an `order_status_events`-equivalent audit entry records the publish actor and timestamp.
7. Attempt to publish the same offer a second time — assert HTTP 409 (`ALREADY_PUBLISHED`).

**Expected Result**
The offer publish endpoint enforces a strict gate: approvals count, supplier confirmation reference, and non-expired terms are all mandatory preconditions. Each missing condition produces a distinct, named error code.

---

### T06 — 120 Interested / 105 Accepted / 90 Paid with MOQ 100 Does NOT Release Supplier Order

**Priority:** Critical
**Phase:** Phase 3 (Offer Conversion and Order Core)

**Preconditions / Setup**
- A published offer exists with `min_paid_qty = 100`.
- Seed data: 120 `group_memberships` at intent L1, 105 `offer_acceptances`, 90 `payment_intents` in `CAPTURED/PAID` status.
- `paid_qty` counter on the offer = 90.

**Steps**
1. Trigger the MOQ evaluation job (or call the internal MOQ check endpoint).
2. Assert that `seller_fulfillment_orders.status` remains `PENDING` (no supplier order released).
3. Assert that no `negotiation_events` of type `FULFILLMENT_RELEASED` exist for this offer.
4. Assert that `offers.paid_qty = 90` matches the count of unrefunded `CAPTURED` payment records.
5. `POST /v1/internal/offers/{offer_id}/release-supplier-order` manually — assert HTTP 422 with reason `MOQ_NOT_MET` and body showing `paid_qty=90, required=100`.
6. Confirm that the 90 paid buyers' `orders` remain in `PAYMENT_CONFIRMED` / `AWAITING_THRESHOLD` state, not `RELEASED`.

**Expected Result**
The system accurately distinguishes interested, accepted, and paid quantities. With `paid_qty = 90 < MOQ 100`, no supplier order is released through any code path — automated job or manual override endpoint.

---

### T07 — 100 Simultaneous Buyers Cannot Reserve 101 Units of 100 Available

**Priority:** Critical
**Phase:** Phase 3 (Offer Conversion and Order Core)

**Preconditions / Setup**
- A published offer exists with `max_qty = 100` and no prior reservations.
- 101 authenticated buyer accounts are pre-seeded.
- A `stock_reservations` table with a row-level lock guard exists.

**Steps**
1. Using k6 (or equivalent), fire 101 concurrent `POST /v1/offers/{offer_id}/accept` requests simultaneously, each requesting `qty = 1`.
2. After all requests complete, count successful HTTP 201 responses — assert exactly 100 succeeded.
3. Assert exactly 1 request received HTTP 409 (`STOCK_EXHAUSTED`) or HTTP 422 (`QUANTITY_UNAVAILABLE`).
4. Query `stock_reservations` table — assert `SUM(qty) = 100` for this `offer_id`.
5. Assert no `stock_reservations` row has `qty` that would push total above 100.
6. Run the check again after a brief pause to ensure no eventual-consistency window allowed a 101st reservation.
7. Verify no 500 errors occurred; all responses are deterministic 2xx or 4xx.

**Expected Result**
The database transaction and `SELECT ... FOR UPDATE` lock prevents any combination of concurrent requests from creating more than 100 total reservations. Exactly 1 request is rejected with a clear availability error.

---

### T08 — Payment Webhook Delivered 3 Times Creates Exactly One Paid Allocation and One Ledger Posting

**Priority:** Critical
**Phase:** Phase 4 (Provider-Approved Payments and Fulfillment)

**Preconditions / Setup**
- A `payment_intent` record exists in `CHECKOUT_PENDING` status for `order_X`.
- The `payment_webhook_events` table has a `UNIQUE(provider, provider_event_id)` constraint.
- A valid webhook signing secret is configured.

**Steps**
1. Send a correctly signed `payment.captured` webhook payload with `provider_event_id = "evt_001"` to `POST /v1/webhooks/payments/{provider}` — assert HTTP 200.
2. Verify `payment_webhook_events` has exactly 1 row for `provider_event_id = "evt_001"`.
3. Verify `payment_intents.status = CAPTURED` for `order_X`.
4. Verify `ledger_postings` has exactly 2 rows for this event (debit buyer-receivable / credit platform-escrow) — a balanced double-entry pair.
5. Verify `stock_reservations.status = CONFIRMED` for the buyer's reservation.
6. Send the identical webhook payload a second time — assert HTTP 200 (idempotent acknowledgement, not an error) or HTTP 409.
7. Assert `payment_webhook_events` still has exactly 1 row for `provider_event_id = "evt_001"` (unique constraint enforced).
8. Assert `ledger_postings` count for this event has not increased.
9. Send the identical webhook payload a third time — repeat assertions from steps 7–8.

**Expected Result**
Regardless of how many times the same webhook event arrives, the system processes it exactly once: one `payment_intent` state change, one `ledger_postings` pair, one `stock_reservations` confirmation. All duplicate deliveries are silently acknowledged and discarded.

---

### T09 — Paid Buyer Cannot Be Silently Repriced or Substituted

**Priority:** Critical
**Phase:** Phase 3 / Phase 4

**Preconditions / Setup**
- `buyer_1` has a confirmed `order_X` with `terms_hash = "abc123"` locked at `unit_price_paise = 2500000`.
- The corresponding `offer_acceptances` row has `accepted_terms_hash = "abc123"`.
- An internal finance operator account exists.

**Steps**
1. As a finance operator, attempt `PATCH /v1/internal/orders/{order_id}` with `unit_price_paise = 2400000` — assert HTTP 403 (no such endpoint, or permission denied).
2. Attempt a direct `order_items` update via any internal admin endpoint — assert this action is blocked or requires a dispute/adjustment workflow.
3. Attempt to swap the `variant_id` on `order_items` for a different product — assert HTTP 422 or 403.
4. Trigger the offer republish flow with updated price tiers — verify that `order_X`'s `terms_hash` is unchanged and that the buyer is not automatically re-priced.
5. Retrieve `order_X` via buyer API — assert `unit_price_paise` and `terms_hash` match the original accepted snapshot.
6. Query `order_status_events` — assert no event of type `REPRICED` or `SUBSTITUTED` exists on `order_X`.
7. If a price change is legitimately required (e.g., dispute resolution), verify it goes through the `dispute_adjustments` workflow and generates buyer notification + consent record.

**Expected Result**
No code path allows silent modification of a paid order's price or product. Any price or product change requires an explicit, audited workflow (dispute or cancellation) with buyer notification. The immutable `terms_hash` snapshot is the source of truth.

---

### T10 — Seller Withdrawal After Buyer Payment Triggers Re-Source / Consent / Refund Workflow

**Priority:** Critical
**Phase:** Phase 4 (Provider-Approved Payments and Fulfillment)

**Preconditions / Setup**
- An offer has 50 paid buyers, `paid_qty = 50 >= MOQ 50`.
- The supplier order has been released (`seller_fulfillment_orders.status = CONFIRMED`).
- A seller account is linked to this fulfillment order.

**Steps**
1. As the seller (or internal ops), trigger `POST /v1/seller/orders/{fulfillment_order_id}/withdraw` with a documented reason — assert HTTP 200 and status changes to `WITHDRAWN`.
2. Assert that an `order_status_events` row of type `SELLER_WITHDRAWAL` is created with actor and timestamp.
3. Assert that all 50 buyer `orders` move to `SELLER_WITHDRAWN` state — not silently left as `RELEASED`.
4. Assert that a notification is queued for all 50 affected buyers.
5. Assert that a new `procurement_case` is automatically created (or an existing one reopened) with stage `RE_SOURCE` and linked to the original group.
6. Verify that buyers are presented with a consent prompt before any substitute offer is created: attempt to publish a new offer for the group — assert that the system requires explicit buyer re-acceptance (not automatic roll-over).
7. For buyers who do not re-accept within the policy window, trigger the refund flow: assert `refunds` rows are created, `payment_intents` moves to `REFUND_PENDING`, and no `ledger_postings` treat the unaccepted amount as settled.

**Expected Result**
Seller withdrawal after payment is a first-class exception state. It immediately surfaces to all affected buyers, triggers a re-source procurement case, blocks silent substitution, and guarantees refund for any buyer who does not consent to the alternative.

---

### T11 — Partial Delivery of 7/10 Units Leaves 3 Disputed / Unsettled

**Priority:** High
**Phase:** Phase 4 (Fulfillment and Inspection)

**Preconditions / Setup**
- `order_X` has `order_items.qty = 10`, `unit_price_paise = 100000` per unit (total = 1,000,000 paise).
- The shipment is in `DELIVERED_REPORTED` state.
- A `buyer_acknowledgements` record does not yet exist.

**Steps**
1. As `buyer_1`, submit `POST /v1/shipments/{shipment_id}/acknowledgements` with `accepted_qty = 7, disputed_qty = 3, dispute_reason = "Missing units"`.
2. Assert HTTP 201 and that `buyer_acknowledgements.accepted_qty_json` = `{item_id: 7}`, `disputed_qty_json` = `{item_id: 3}`.
3. Assert a `disputes` record is created with `type = SHORT_DELIVERY`, `status = OPEN`, linked to `order_X`.
4. Verify `settlement_items` for the seller: `eligible_qty = 7` only; `disputed_qty = 3` is not in `eligible_paise`.
5. Attempt `POST /v1/internal/settlements/{settlement_id}/approve` — assert the system blocks or flags settlement of the disputed 3 units.
6. Assert `reconciliation_exceptions` does not incorrectly flag the 7 settled units.
7. Close the dispute as resolved (refund for 3 units): assert `refunds` row created for `3 * 100000 = 300000 paise`, `payment_intents` partial refund initiated.
8. After refund, assert `seller_settlements.eligible_paise` covers only the 7 accepted units.

**Expected Result**
The system accurately tracks accepted versus disputed quantities at item level. The seller can be settled only for delivered and accepted units. The 3 disputed units remain unsettled until the dispute is formally resolved, with a matching refund or replacement.

---

### T12 — Buyer Silence Follows Legal Timeline; No Indefinite Hold

**Priority:** High
**Phase:** Phase 4 (Fulfillment and Inspection)

**Preconditions / Setup**
- `order_X` delivery status = `DELIVERED_REPORTED`; no `buyer_acknowledgements` exists.
- System config: `buyer_acknowledgement_window_hours = 72` (policy variable).
- A scheduled job runs the acknowledgement-timeout check.

**Steps**
1. Set `delivery_evidence.verified_at` to `now() - 71 hours` — run the timeout job — assert no auto-resolution triggered yet.
2. Advance time to `now() - 73 hours` (past the 72-hour window) — run the timeout job.
3. Assert that `buyer_acknowledgements` is auto-created with `accepted_qty = ordered_qty` (deemed acceptance) OR a configurable alternative resolution is applied, as defined by policy variable.
4. Assert this auto-resolution is logged in `audit_logs` with reason `BUYER_SILENCE_TIMEOUT` and references the policy version used.
5. Assert the seller settlement eligibility is updated based on the deemed acceptance — not held indefinitely.
6. Assert that the buyer retains the statutory right to raise a post-delivery dispute within the legally defined window (do not close disputes permanently).
7. Verify that if a dispute is raised after deemed acceptance but within the legal window, the `disputes` record is accepted and settlement goes back to `ON_HOLD` or `PARTIALLY_ELIGIBLE`.

**Expected Result**
Buyer silence triggers a timed, policy-driven resolution — not an indefinite payment hold. The resolution is logged, the policy version is referenced, and the buyer's statutory rights to later dispute are preserved.

---

### T13 — Refund After Partial Seller Settlement Is Reconciled Correctly

**Priority:** High
**Phase:** Phase 4 (Provider-Approved Payments and Fulfillment)

**Preconditions / Setup**
- `order_X` total = 1,000,000 paise (10 units at 100,000 each).
- The seller has been partially settled for 7 units: `seller_settlements.confirmed_paise = 700,000`.
- A subsequent dispute on 2 of the 7 settled units is upheld; a refund of 200,000 paise to the buyer is due.

**Steps**
1. Create a `dispute_adjustments` record for 2 units (200,000 paise) with finance approver sign-off.
2. Assert `refunds` row is created: `amount_paise = 200,000`, linked to `order_X`, `payment_intent_id`.
3. Trigger the refund via `POST /v1/internal/refunds/{refund_id}/process` — assert HTTP 200.
4. Assert `ledger_postings` include a reversal entry of 200,000 paise against the original settlement posting.
5. Assert `seller_settlements.confirmed_paise` does not decrease (the settlement already occurred), but a new `seller_settlements` debit row or clawback record is created for 200,000 paise.
6. Run the daily reconciliation job — assert `reconciliation_exceptions` count = 0 for this order after the adjustment.
7. Assert the buyer's total payment net of refund = 800,000 paise (10 units paid − 2 units refunded).
8. Assert the seller's net settlement = 500,000 paise (7 settled − 2 clawback).

**Expected Result**
Post-settlement refunds are recorded as explicit ledger reversals and clawbacks, never as silent edits to existing settled amounts. The reconciliation run confirms the books balance after the adjustment.

---

### T14 — Bank Account Change Cannot Be Self-Approved by the Same Staff Member

**Priority:** Critical
**Phase:** Phase 1 / Phase 4 (Foundation + Payments)

**Preconditions / Setup**
- A seller has an existing verified `seller_bank_accounts` record.
- Two internal staff accounts exist: `agent_1` (initiator) and `approver_1` (approver, different user).
- Maker-checker policy requires `initiator_id != approver_id` for bank account changes.

**Steps**
1. As `agent_1`, submit `POST /v1/internal/seller/{seller_id}/bank-account-change` with new account details — assert HTTP 201 and change request is in `PENDING_APPROVAL` state.
2. As `agent_1`, attempt to approve their own request: `POST /v1/internal/bank-change-requests/{id}/approve` — assert HTTP 403 with reason `SELF_APPROVAL_DENIED`.
3. As `approver_1`, approve the same request — assert HTTP 200 and status moves to `APPROVED`.
4. Verify an `audit_log` entry captures both the initiator (`agent_1`) and approver (`approver_1`) with timestamps and IP/session context.
5. Attempt the entire flow as a single super-admin user (initiating and approving) — assert the same self-approval restriction applies.
6. Verify the old bank account record is preserved in an audit-safe state; the new record is marked `PENDING_VERIFICATION` until provider confirmation.

**Expected Result**
The maker-checker constraint is enforced at the API layer, not just the UI. No single staff member — including admins — can initiate and approve a bank account change in the same session or under the same user ID.

---

### T15 — Account Deletion Honors Retention Requirements

**Priority:** High
**Phase:** Phase 1 (Foundation)

**Preconditions / Setup**
- `buyer_1` has completed orders, an `audit_logs` history, and a `consent_records` entry.
- `seller_a` has settled `seller_settlements` records.
- Retention policy config: financial records retained 7 years, audit logs retained 5 years.

**Steps**
1. As `buyer_1`, request account deletion: `DELETE /v1/me/account` — assert HTTP 202 (accepted, not immediate).
2. Assert `users.status` moves to `DELETION_REQUESTED` with a cooling-off timestamp.
3. After the cooling-off period, trigger the deletion job — assert PII fields (`mobile`, `email`, `addresses.encrypted_detail`) are nulled or pseudonymized.
4. Assert `orders`, `ledger_postings`, `audit_logs`, and `payment_webhook_events` rows linked to the buyer are retained with pseudonymized `buyer_id` reference — not hard-deleted.
5. Assert `consent_records` retention deletion event is logged.
6. Attempt to log in as `buyer_1` after deletion — assert HTTP 401 or 403.
7. Repeat for `seller_a` deletion: assert settled `seller_settlements` and `audit_logs` are retained; PII pseudonymized; seller can no longer authenticate.
8. Verify a compliance operator can retrieve the pseudonymized financial records for the required retention period.

**Expected Result**
Account deletion removes PII (mobile, email, addresses) but preserves all financial, audit, and legal records for the full retention period in pseudonymized form. The deletion is logged as a compliance event.

---

### T16 — Wrong-Signature Webhook Is Rejected and Security Event Recorded

**Priority:** Critical
**Phase:** Phase 4 (Provider-Approved Payments and Fulfillment)

**Preconditions / Setup**
- A valid webhook signing secret is configured for the payment provider.
- A `risk_flags` table and security event logging mechanism exist.

**Steps**
1. Send a `payment.captured` webhook with a valid body but a deliberately incorrect `X-Signature` header — assert HTTP 401 or 400 with no state change.
2. Assert `payment_webhook_events` contains 0 rows for this `provider_event_id`.
3. Assert a `risk_flags` or security-event record is created with `event_type = WEBHOOK_SIGNATURE_INVALID`, IP address, timestamp and raw request metadata (no raw card data).
4. Send a webhook with a valid signature but a replayed `provider_event_id` that already exists in `payment_webhook_events` — assert HTTP 200 (idempotent) but no second processing.
5. Send a webhook with a timestamp outside the replay-tolerance window (e.g., 10 minutes old) — assert rejection and security event.
6. Send a valid webhook immediately after to confirm the endpoint still processes legitimate events correctly — assert HTTP 200 and normal state transition.

**Expected Result**
Invalid-signature webhooks are rejected before any processing, logged as security events, and produce zero side-effects (no state changes, no ledger entries). The endpoint remains functional for valid subsequent requests.

---

### T17 — Failed Queue Job Replay Cannot Duplicate Supplier Order, Refund, or Payout Request

**Priority:** Critical
**Phase:** Phase 4 (Provider-Approved Payments and Fulfillment)

**Preconditions / Setup**
- A transactional outbox / queue worker is configured with at-least-once delivery.
- Idempotency keys exist on `seller_fulfillment_orders`, `refunds`, and `seller_settlements`.
- A test queue worker can be forced to simulate a mid-job failure.

**Steps**
1. Enqueue a `release-supplier-order` job for `offer_X`; simulate a worker crash after the database write but before job acknowledgement.
2. Allow the job to be re-enqueued and re-processed — assert exactly one `seller_fulfillment_orders` row is created (idempotency key enforced).
3. Enqueue a `process-refund` job for `order_Y`; simulate failure after provider API call but before job ack.
4. Re-process the job — assert exactly one `refunds` row and one outbound API call attempt (use provider sandbox call log or a mock with call counting).
5. Enqueue a `request-payout` job for `settlement_Z`; simulate failure mid-way.
6. Re-process — assert exactly one `seller_settlements` row transitions to `RELEASE_REQUESTED` and the provider is called at most once (idempotency key sent to provider).
7. Verify `audit_logs` show replay attempts without duplicate business events.

**Expected Result**
All critical queue jobs are idempotent: retrying a failed job any number of times produces the same single outcome. No duplicate supplier orders, refunds, or payout requests are created.

---

### T18 — Buyer Rejects High Offer; System Reopens Sourcing Without Payment Obligation

**Priority:** High
**Phase:** Phase 3 (Offer Conversion and Order Core)

**Preconditions / Setup**
- A published offer exists at `unit_price_paise = 3200000`.
- `buyer_1` has viewed the offer; no `offer_acceptances` row exists for `buyer_1`.
- The offer has 15 out of 50 required acceptances; MOQ not met.

**Steps**
1. As `buyer_1`, call `POST /v1/offers/{offer_id}/reject` — assert HTTP 200.
2. Assert no `offer_acceptances` row is created for `buyer_1`.
3. Assert no `payment_intents` row is created for `buyer_1`.
4. Assert `buyer_1`'s `group_memberships.intent_level` returns to `L2` (purchase-ready interest, not committed).
5. Assert the offer's acceptance count decreases by 0 (buyer never accepted; this is a rejection of viewing, not cancellation of an accepted offer).
6. As a procurement lead, trigger `POST /v1/internal/offers/{offer_id}/reopen-sourcing` or allow offer to expire — assert a new `procurement_case` stage of `RE_NEGOTIATE` is created.
7. Verify `buyer_1` receives a notification that sourcing has reopened, not a payment request.
8. Confirm that if the offer simply expires (no explicit rejection), `buyer_1` incurs no charge and their group membership persists at `L2`.

**Expected Result**
Offer rejection creates no payment obligation whatsoever. The system cleanly returns the buyer to an interest state and reopens sourcing. No checkout, payment intent, or reservation is ever created for a buyer who declined or did not accept.

---

### T19 — Deal Expires During Checkout; Payment Recovery / Refund Is Safe and Explicit

**Priority:** Critical
**Phase:** Phase 3 / Phase 4

**Preconditions / Setup**
- A published offer has `expires_at = now() + 30 seconds`.
- `buyer_1` has accepted the offer and initiated checkout (`payment_intents.status = CHECKOUT_PENDING`).
- Gateway sandbox is configured to simulate a delayed capture (45 seconds).

**Steps**
1. `buyer_1` initiates checkout at T=0 (25 seconds before expiry).
2. At T=31 seconds, the offer `expires_at` passes — trigger the expiry job.
3. Assert the offer status moves to `EXPIRED`.
4. At T=45 seconds, the payment gateway captures the payment and delivers a webhook.
5. Assert the webhook handler detects that the offer is `EXPIRED` before creating `offer_acceptances` or `stock_reservations`.
6. Assert `payment_intents.status` moves to `CAPTURED` momentarily, then immediately to `REFUND_PENDING`.
7. Assert a `refunds` row is auto-created with `reason = OFFER_EXPIRED_DURING_CHECKOUT`.
8. Assert the buyer receives a notification with explicit reason and refund timeline — not a silent cancellation.
9. Assert `ledger_postings` include a matching debit and credit (capture + reversal), leaving net = 0 for this order.
10. Verify that if the gateway capture fails (e.g., card declined at T=45s), no refund is attempted and `payment_intents.status = FAILED`.

**Expected Result**
Payments captured after offer expiry are caught by the webhook handler, immediately flagged for refund, and communicated explicitly to the buyer. The ledger remains balanced. Failed captures do not trigger spurious refunds.

---

### T20 — Provider Settlement Report Disagrees with Internal Ledger; Exception Blocks False "Paid" Status

**Priority:** Critical
**Phase:** Phase 4 (Provider-Approved Payments and Fulfillment)

**Preconditions / Setup**
- A reconciliation run is configured to compare provider settlement CSV against `ledger_postings`.
- `seller_settlements` has a record `settlement_S` in `APPROVAL_PENDING` state with `eligible_paise = 500,000`.
- The provider settlement report shows `confirmed_paise = 480,000` for the same period (a 20,000 paise discrepancy).

**Steps**
1. Import the provider settlement CSV via `POST /v1/internal/reconciliation/import` — assert HTTP 201.
2. Run the reconciliation job for the period — assert `reconciliation_runs.status = COMPLETED_WITH_EXCEPTIONS`.
3. Assert a `reconciliation_exceptions` row is created for `settlement_S` with `discrepancy_paise = 20000` and `status = OPEN`.
4. Attempt to approve `settlement_S` via `POST /v1/internal/settlements/{id}/approve` — assert HTTP 422 with reason `RECONCILIATION_EXCEPTION_OPEN`.
5. Assert `seller_settlements.status` does NOT move to `PROVIDER_CONFIRMED_SETTLED`.
6. Assert no `ledger_postings` entry marks this settlement as fully paid.
7. As a finance approver, resolve the exception by documenting the discrepancy: `POST /v1/internal/reconciliation/exceptions/{id}/resolve` with reason and evidence — assert HTTP 200.
8. After resolution, attempt settlement approval again — assert HTTP 200 and status progresses correctly.
9. Verify the full exception resolution is captured in `audit_logs` with both the finance operator and approver identities.

**Expected Result**
A discrepancy between internal ledger and provider settlement report creates a blocking `reconciliation_exceptions` record. Settlement approval is impossible until the exception is explicitly resolved by an authorized finance approver. The system never marks a seller as "paid" based solely on internal records when the provider reports a different amount.

---

## 2. Nonfunctional Tests

### 2.1 OWASP API Top 10 Checklist

| ID | Threat | Test approach |
|----|--------|---------------|
| API1 | Broken Object Level Authorization | Replay T01/T02 patterns across every resource type; fuzz `{id}` parameters with ULIDs/UUIDs of other tenants |
| API2 | Broken Authentication | Expired tokens, missing tokens, tokens from deactivated accounts, OTP replay within validity window |
| API3 | Broken Object Property Level Authorization | Attempt to submit extra fields (e.g., `is_admin=true`, `role=FINANCE_APPROVER`) in PATCH/PUT bodies; assert they are ignored |
| API4 | Unrestricted Resource Consumption | Verify rate limits on OTP, search, bid submission and webhook endpoints; assert 429 after threshold |
| API5 | Broken Function Level Authorization | Enumerate internal endpoints as buyer/seller roles; assert 403 on all `/v1/internal/*` routes |
| API6 | Unrestricted Access to Sensitive Business Flows | Automate 1,000 group-join requests from one account; verify abuse throttling |
| API7 | Server Side Request Forgery | Submit URLs in evidence upload fields pointing to internal metadata services; verify blocked |
| API8 | Security Misconfiguration | Scan for exposed debug endpoints, stack traces in error responses, default credentials |
| API9 | Improper Inventory Management | Verify no undocumented v0 endpoints are reachable; OpenAPI contract test coverage ≥ 95% |
| API10 | Unsafe Consumption of APIs | Verify all outbound provider API calls validate TLS certificates and do not follow arbitrary redirects |

---

### 2.2 Authentication and Rate-Limiting Tests

- OTP brute-force: 6 incorrect OTPs exhaust the attempt limit; subsequent valid OTP is blocked until cooldown.
- Token expiry: expired JWT returns 401; refresh token flow tested.
- Concurrent session limits: if policy limits sessions per user, verify excess sessions are invalidated.
- Staff MFA: internal portal endpoints return 403 without MFA challenge completion.
- Rate limit headers (`X-RateLimit-Remaining`, `Retry-After`) present in 429 responses.
- Seller bid submission rate limit: no more than N revisions per hour per seller.

---

### 2.3 PII Access Audit

- Query `audit_logs` after a full buyer journey; confirm buyer `max_paise` never appears in seller-visible or public log entries.
- Confirm `addresses.encrypted_detail` is never returned in list endpoints, only in detail endpoints with explicit ownership.
- Confirm mobile number is masked in notification preview payloads (e.g., `+91 ****7890`).
- Confirm that analytics export endpoints aggregate and anonymize; no individual buyer budget or identity is exportable by non-authorized roles.
- After account deletion (T15 scenario), verify pseudonymized records pass a PII scan (regex for email, mobile, Aadhaar patterns).

---

### 2.4 Backup and Restore Verification

- Schedule: daily full backup + hourly incremental; verify backup job completion logs.
- Restore drill (quarterly): restore to a staging environment from the most recent backup; run database integrity checks (`mysqlcheck`); verify row counts for key tables match pre-backup state.
- Point-in-time recovery: verify binary log retention allows recovery to within 15 minutes of any failure.
- Object storage: verify `delivery_evidence` and `negotiation_attachments` are replicated to a second region; sample restore of 10 random files confirms integrity.
- RTO/RPO targets are `[CONFIRM]` policy variables; test must assert against whatever values are documented.

---

### 2.5 Load and Concurrency Benchmarks

| Scenario | Tool | Target (illustrative — confirm before pilot) |
|----------|------|-----------------------------------------------|
| 500 concurrent group-join requests | k6 | p95 response < 300 ms, 0% error rate |
| 100 simultaneous checkouts (T07 scenario) | k6 | 0 oversell, p99 < 1,000 ms |
| 1,000 req/s on `GET /v1/deals` (cached) | k6 | p95 < 100 ms |
| Webhook burst: 200 events in 5 seconds | k6 | 0 duplicates, 0 missed, queue depth clears within 30 s |
| Daily reconciliation job on 10,000 orders | PHPUnit + DB fixture | Completes within 60 s, 0 exceptions on clean data |
| Redis lock contention: 50 concurrent MOQ evaluations | k6 | Exactly 1 supplier order released per offer |

---

### 2.6 Timezone Handling

- Bid deadline stored as UTC; displayed in buyer's local timezone (Asia/Kolkata default) with explicit label.
- Offer `expires_at` evaluation uses server UTC clock, not client-submitted timestamps.
- Acknowledgement window calculation (`buyer_acknowledgement_window_hours`) uses UTC delivery timestamp, not local midnight.
- Reconciliation period boundaries are UTC; test with a period spanning a DST boundary (not applicable for IST but required for future markets).
- Create an offer with `expires_at = 2027-03-14 02:30:00 UTC`; verify deadline display and enforcement are consistent.

---

### 2.7 Accessibility (WCAG 2.1 AA)

- Automated scan (axe-core via Playwright) on: Home, Group Detail, Final Offer, Checkout, Order Status, Delivery Acknowledgement.
- Assert zero WCAG 2.1 AA critical violations on each scanned page.
- Manual checks: keyboard navigation through the checkout flow; focus order is logical; all form fields have associated `<label>` elements.
- Screen reader test (NVDA + Chrome): offer price breakdown and MOQ progress bar are announced meaningfully.
- Colour contrast: all primary text and interactive elements meet 4.5:1 ratio minimum.
- Tap target size on mobile viewport (375 px): all interactive elements ≥ 44 × 44 px.

---

### 2.8 Mobile Network Resilience

- Throttle browser network to "Slow 3G" (40 kbps) in Playwright; verify checkout flow completes or provides a clear retry path without data corruption.
- Simulate mid-upload failure during `delivery_evidence` photo upload; verify partial upload is detected and re-upload is prompted without duplicate evidence records.
- Offline PWA: navigate to cached offer detail page with network disabled; verify cached data displays with a "last updated" timestamp and no stale checkout button.
- WebSocket reconnect: drop connection mid-session; verify client reconnects and replays any missed notification events without duplicates.

---

### 2.9 Observability Verification

- Structured log format: every API request emits a JSON log line with `request_id`, `user_id` (pseudonymized in public logs), `route`, `status_code`, `duration_ms`.
- Alert rules: test that the following alerts fire in staging — payment webhook error rate > 5% for 2 minutes; reconciliation exception count > 0; queue depth > 1,000 for 5 minutes.
- Distributed trace: a full checkout flow produces a trace with spans for: HTTP handler → auth middleware → service layer → DB query → Redis lock → outbox event → webhook response.
- Health endpoint: `GET /health` returns `{"status":"ok","db":"ok","redis":"ok","queue":"ok"}` and is excluded from auth middleware.
- No sensitive data (card numbers, raw bank details, `max_paise`, bid prices) appears in any log line or trace attribute.

---

## 3. Test Infrastructure

### 3.1 PHPUnit — Backend Unit and Integration Tests

- **Unit tests** cover: state machine transition guards, money arithmetic (integer paise only), idempotency key generation, bid confidentiality scope filters, MOQ evaluation logic, webhook signature verification, ledger posting balance validation.
- **Integration tests** use a dedicated MySQL test database (`collective_commerce_test`) reset before each suite via `DatabaseTransactions` or explicit truncation.
- **Test doubles:** HTTP client for gateway calls is replaced with a recording mock; Redis is a real in-process instance (or `phpredis` with a test database index).
- **Coverage target:** 80% line coverage on `Payments`, `Bidding`, `Offers`, `Orders`, and `Ledger` modules before Phase 4 gate.
- **Contract tests:** PHPUnit asserts all internal API responses against the OpenAPI spec using a schema validator middleware in test mode.

```
tests/
  Unit/
    BiddingConfidentialityTest.php
    MOQEvaluationTest.php
    WebhookSignatureTest.php
    LedgerBalanceTest.php
    IdempotencyKeyTest.php
  Integration/
    OfferPublishGateTest.php
    ConcurrentReservationTest.php
    PaymentWebhookIdempotencyTest.php
    ReconciliationExceptionTest.php
    BankAccountMakerCheckerTest.php
    AccountDeletionRetentionTest.php
```

---

### 3.2 Cypress / Playwright — End-to-End Tests

- **Buyer journey:** Create request → join group → receive offer → accept → checkout → acknowledge delivery → raise dispute.
- **Seller journey:** Submit bid → receive negotiation request → confirm terms → dispatch order → view settlement.
- **Procurement agent journey:** Capture offline quote with evidence → approve offer → monitor MOQ gate.
- **Finance journey:** View reconciliation exceptions → resolve discrepancy → approve settlement.
- **Cross-role isolation:** Each E2E suite starts with freshly seeded accounts; no test shares session state.
- **Visual regression:** Playwright snapshots for Offer Detail and Checkout on mobile (375 px) and desktop (1280 px) viewports.
- Tests run in CI on every pull request to `main`; full E2E suite runs nightly.

```
e2e/
  buyer/
    group-join.spec.ts
    checkout-offer-expired.spec.ts     ← T19
    delivery-acknowledgement.spec.ts   ← T11
  seller/
    bid-confidentiality.spec.ts        ← T01
    seller-withdrawal.spec.ts          ← T10
  internal/
    offline-quote-evidence.spec.ts     ← T03
    offer-publish-gate.spec.ts         ← T05
    bank-account-maker-checker.spec.ts ← T14
    reconciliation-exception.spec.ts   ← T20
  accessibility/
    axe-scan.spec.ts
```

---

### 3.3 k6 / Artillery — Load Tests

- Load test scripts live in `tests/load/` and are run against a dedicated staging environment, never production.
- Each script declares its own virtual user ramp-up, steady state and tear-down.
- Thresholds are defined in the script and cause a non-zero exit code if breached (CI pipeline fails).
- Database state is reset to a known fixture before each load run via a seeding API endpoint (`POST /internal/test/seed`, disabled in production via feature flag).

```
tests/load/
  concurrent-checkout.js        ← T07 scenario
  webhook-burst.js               ← T08 scenario
  group-join-ramp.js
  deal-listing-cache.js
  reconciliation-job.js
```

---

### 3.4 Test Database Seeding Strategy

| Layer | Method | Notes |
|-------|--------|-------|
| PHPUnit unit | In-memory stubs / mocks | No DB required |
| PHPUnit integration | `DatabaseTransactions` trait; rollback after each test | MySQL test DB; migrations run once per suite |
| Playwright E2E | `POST /internal/test/seed` API endpoint | Accepts a JSON fixture name; creates users, offers, orders atomically; protected by `APP_ENV=testing` guard |
| k6 load tests | Seed API called once before ramp-up | Fixture creates N buyers, N sellers, open offer; cleaned up post-run |
| Staging full reset | `php artisan db:seed --env=staging` equivalent | Run before each nightly E2E suite |

**Fixture naming convention:** `fixtures/{phase}/{scenario}.json` (e.g., `fixtures/phase3/concurrent-checkout.json`).

**Sensitive data in fixtures:** All fixtures use synthetic data only — no real mobile numbers, PAN, GST numbers or bank accounts. Fixture generator uses a deterministic fake-data library seeded with a fixed integer for reproducibility.

---

*End of test plan — Version 1.0*
