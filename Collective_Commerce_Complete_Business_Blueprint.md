# COLLECTIVE COMMERCE
## Complete Business, Operations, Product and Technical Blueprint

**Version:** 1.0 — 17 November 2026  
**Status:** Proposed architecture and operating model; not a legal approval or market validation  
**Working name:** Collective Commerce (brand/domain not selected)  
**Primary market:** India; launch city/category to be selected after validation  
**Core proposition:** Buyers aggregate verified demand; sellers bid privately; an internal procurement team sources, negotiates, validates and closes deals; a compliant payment partner handles buyer collections and seller settlements; fulfillment, inspection and disputes are managed transparently.

> **Critical distinction:** A buyer's interest is not an order, a seller's bid is not a guaranteed supply commitment, a buyer's stated budget is not an accepted offer, and an internal payout approval is not a completed bank settlement.

---

# 1. Executive Summary

Collective Commerce is a managed group-purchasing and procurement marketplace with three entry points:

1. **Buyer-led reverse procurement:** Individuals or businesses request products; compatible demand is aggregated; approved sellers submit sealed quotations; staff can proactively source suppliers and negotiate; buyers receive a time-limited final offer and decide whether to purchase.
2. **Seller-led bulk offers:** Sellers list available inventory with transparent minimum quantities, price tiers, deadlines and fulfillment terms; buyers join until the required paid volume is reached.
3. **B2B procurement/RFQ:** Businesses specify recurring or one-off requirements; verified suppliers submit confidential quotes; staff assist with negotiation, documentation and fulfillment.

**Differentiation:** This is not a discount catalog. It is a managed demand-to-deal engine. A procurement desk actively closes deals even when no seller bids online.

**V1 strategic constraint:** One city/region and one or two manageable product categories; no warehouse, own delivery fleet, blanket physical inspection promises or self-operated escrow. Launch only after payment, tax, consumer protection and supplier contracting are reviewed.

## 1.1 Success definition

The platform succeeds only when all parties gain measurable value:
- Buyer: a competitive **final landed price**, accurate specifications, reliable delivery and fair dispute handling.
- Seller: genuine demand, predictable conversion, low acquisition cost and transparent payment terms.
- Platform: positive **contribution margin per completed order** after procurement, payment, inspection, support, refunds and dispute costs.

## 1.2 Non-goals for V1

- Guaranteed lowest price anywhere.
- Guarantee that a request becomes an order.
- Company bank account holding customer money as an informal escrow.
- Universal product quality verification by employees.
- Automatic award to the lowest bid regardless of quality, warranty, location or supplier reliability.
- Fully automated AI negotiation or dynamic pricing without human approval.
- Pan-India, all-category launch.

---

# 2. Participants, Roles and Access

| Actor | Core responsibilities | Sensitive information access |
|---|---|---|
| Guest | Browse public requests/deals | Public data only |
| Buyer | Create request, express intent, accept/pay, acknowledge delivery, raise disputes | Own budget, orders, offers |
| Business buyer | Organization purchasing, multi-user approvals, RFQs, invoices | Organization-scoped data |
| Seller | Register, verify, bid, offer, fulfill, respond to disputes | Own bids, offers, orders only |
| Procurement agent | Qualify demand, source suppliers, record calls/quotes, negotiate | Assigned cases and bids |
| Procurement lead | Review negotiation, approve final commercial offers | Authorized team cases |
| Quality inspector/partner | Check assigned products, capture evidence | Inspection scope only |
| Operations agent | Track dispatch, delivery and acceptance | Assigned order data |
| Finance operator | Reconcile payments/refunds/settlements | Financial data, not unrestricted editing |
| Finance approver | Authorize eligible releases/adjustments | Segregated approval authority |
| Support/disputes | Triage complaints, evidence and remedies | Need-to-know order data |
| Compliance/admin | Seller verification, risk flags, audit and policies | Controlled elevated access |
| Super admin | System configuration and access grants | Audited, MFA-protected |

**RBAC + ABAC:** Role permissions plus ownership, organization, assignment, category, amount threshold and environment constraints. No user may approve their own high-risk financial adjustment. Access to competing bids is explicitly denied to sellers, buyers and ordinary support users.

---

# 3. Business Models and Revenue

## 3.1 Buyer-led procurement

Buyer creates a request with exact product or acceptable equivalents, variant/specification, desired quantity, delivery region, preferred purchase window, optional target budget, willingness to consider alternatives, and contact preferences. Buyers can join compatible groups. Sellers quote privately; procurement staff may source outside the platform.

**Revenue:** Seller commission, disclosed buyer service fee if chosen, and optional enterprise sourcing fee. Final commercial structure must be clearly disclosed and contractually documented. Do not double-charge silently.

## 3.2 Seller-led group deals

Seller publishes product, variant, inventory, regions, fulfillment SLA, tax-inclusive/exclusive price basis, tiered quantity thresholds, deal deadline and return/warranty terms. Buyers commit, then pay according to the confirmed tier and MOQ policy.

**Revenue:** Transaction commission or agreed seller subscription/lead fee. Avoid complicated mixed monetization initially.

## 3.3 B2B procurement

RFQ with organization details, approved specification, quantity, delivery schedule, invoicing needs and bid deadline. Optional buyer organization approval chain and repeat ordering. Formal purchase orders and GST invoice handling may be needed.

**Revenue:** Procurement fee or supplier commission; choose one clear contract model per transaction.

## 3.4 Unit economics

For each order track:
`net_platform_revenue = commission + disclosed_service_fee - gateway_fees_borne - procurement_labor - inspection_cost - support_cost - promotion_cost - refund_or_dispute_loss - other_variable_costs`

Report margin per order, seller, product category, deal, city and acquisition channel. **Example only:** ₹5,00,000 gross merchandise value × 5% commission = ₹25,000 gross commission before taxes, gateway fees, inspection, staff and losses. This is not profit.

---

# 4. Pricing and Buyer Intent: Solve Before Seller Negotiation

## 4.1 Never invent an opening selling price

A buyer-led request starts with **“Price pending / sourcing in progress.”** It is not a purchasable listing. Reference market price may be displayed only with a verifiable source, timestamp and clear disclaimer; no fabricated crossed-out MRP or unsupported savings claim.

Collect buyer signals:
- Optional **preferred target price** (not binding).
- Optional **absolute maximum budget**, private to buyer and authorized procurement staff.
- Purchase window and quantity.
- Intent: exploring / likely / purchase-ready.
- Whether substitutes or alternative brands are acceptable.
- Whether price includes GST, installation, shipping and accessories.

Do not automatically expose a buyer's maximum budget or individual identity to sellers. Publish only privacy-safe aggregate demand statistics.

## 4.2 Buyer commitment ladder

| Level | Event | Payment | What it means |
|---|---|---|---|
| L0 | Browse | None | No demand counted |
| L1 | OTP-verified interest | None | Soft interest; deduplicated |
| L2 | Purchase-ready reconfirmation | None | Stronger intent, still nonbinding |
| L3 | Specific final offer accepted | As required by offer | Buyer agrees to exact terms |
| L4 | Payment confirmed | Gateway-confirmed | Paid quantity eligible toward MOQ |
| L5 | Order released to supplier | Funds handled under approved settlement contract | Fulfillment commitment |

**V1:** No token advance before a price is known. For unusually costly sourcing, consider a separate documented and legally reviewed paid procurement service only after validating demand and payment-provider support. A refundable deposit cannot be marketed as unconditional if it is not genuinely unconditional.

## 4.3 Price discovery workflow

1. Aggregate compatible requests by exact SKU/variant, acceptable substitute rules, city/region and purchase window.
2. Validate buyer seriousness; reconfirm target price and quantity for high-value groups.
3. Publish a **sealed RFQ** with specs, expected quantity band, logistics and deadline; do not publish competing bids or buyer maximum budgets.
4. Collect indicative quotations from platform sellers and procurement-sourced suppliers.
5. Normalize quotations to **landed, like-for-like price** (product + applicable tax + shipping + installation + warranty conditions + fees).
6. Calculate demand curve: count of verified buyers whose stated maximum is at or above each candidate final price; label this **potential demand**, never guaranteed acceptance.
7. Validate a proposed price with buyers as **nonbinding indication** if necessary.
8. Negotiate a time-limited final supplier quotation for realistic quantities and clear MOQ.
9. Add disclosed platform fees/commission as applicable; obtain internal approval and supplier confirmation.
10. Present final offer to buyers; capture explicit acceptance and payment; release orders only after threshold and risk checks pass.

## 4.4 Example: price rejection

100 interested buyers: 70 budget ≤ ₹28,000; 20 budget ≤ ₹29,000; 10 budget ≤ ₹30,000. A supplier quotes ₹29,000. Only 30 buyers have stated budgets **at least** ₹29,000; this is potential, not confirmed demand. If seller MOQ is 50, do not place a 50-unit order. Options:
- Ask seller for a 30-unit price tier.
- Reopen sealed bids or proactively source another supplier.
- Offer a buyer-approved alternative SKU or longer collection window.
- Allow an explicitly consented lower quantity tier, if quoted and clearly shown.
- Expire/cancel the offer without charging buyers who did not accept.

Never automatically increase accepted buyer prices or substitute products.

## 4.5 Quote ranking

Internal weighted score: normalized landed price, verified stock, authenticity, supplier history, warranty, delivery SLA, returns, inspection requirements, and capacity. Weights are configurable and logged. Cheapest is not automatically best. A procurement agent can recommend a seller but a designated approver must sign off above risk thresholds.

---

# 5. Sealed Seller Bidding and Procurement CRM

## 5.1 Seller bidding policy

- Only verified/approved sellers can submit binding or indicative quotes as allowed by RFQ rules.
- A seller sees request specs, location region, estimated volume, closing time and **their own** quotes/revisions.
- A seller **never** sees other seller identities, bid values, ranking, negotiation notes or private buyer budgets.
- Bid includes price basis, tax treatment, freight, installation, MOQ, max supply, lead time, warranty, quote validity, attachments and authorized signatory.
- Bids may be revised until deadline; preserve immutable revisions and timestamps.
- Deadline extension and bid reopening must be logged; avoid selectively disclosing competitor terms.
- A quotation is not an accepted purchase order until documented award conditions are met.

## 5.2 Parallel sourcing, including zero bids

Every qualified request can create a procurement case **immediately**. A case owner may search manufacturers, distributors, authorized dealers and wholesalers while sealed bidding is open. The case can have online seller bids and staff-entered offline quotations simultaneously.

Offline quote flow: supplier lead → contact attempt → supplier verification → documented quote (source `TEAM_SOURCED`) → supplier acknowledgment/confirmation → normalized comparison → approved final offer. Staff cannot invent a supplier bid; quote evidence and author identity are mandatory.

## 5.3 CRM functionality

Case priority, SLA, assigned agent, contact history, call outcomes, supplier directory, tasks, reminders, quote attachments, negotiation rounds, approvals, procurement cost/time, supplier response rate, conversion outcomes and lost-reason taxonomy. Escalate high-value cases and aging requests. Avoid giving procurement staff permission to change paid orders or seller bank details.

## 5.4 Negotiation guardrails

- No false claims about confirmed buyers or fabricated competing offers.
- Never disclose competitor's confidential quote without permission.
- Supplier can decline an order not meeting agreed MOQ.
- Supplier quote expiry must precede or align with buyer offer expiry and fulfillment schedule.
- Any revised final terms require renewed supplier confirmation and buyer consent where relevant.
- Commercial commitments above approval limits require maker-checker approval.

---

# 6. Deal Formation, MOQ and Inventory Logic

## 6.1 Separate four quantities

- `interested_qty`: deduplicated soft demand.
- `ready_qty`: buyer reconfirmed purchase intent.
- `accepted_qty`: accepted specific final offer, may be unpaid.
- `paid_qty`: payment confirmed, unrefunded and eligible for MOQ.

**Only paid, valid quantities count toward a paid MOQ** unless a seller contract expressly permits another basis. An example group with 120 interested, 105 accepted, 90 paid and MOQ 100 has **not** met paid MOQ.

## 6.2 Quantity and tier rules

Each offer has minimum paid quantity, maximum quantity, price tier(s), validity, stock reservation policy, checkout deadline, refund/failure policy, and explicit price lock. A seller-led tier may change with volume **only before acceptance or through explicit buyer consent**. Once an offer is accepted and paid, the system must honor it or cancel/refund as contractually required; no silent repricing.

## 6.3 Threshold outcomes

- **Threshold reached and stock reconfirmed:** approve fulfillment; create seller order/purchase instruction.
- **Threshold not reached:** extend once if seller and buyers agree, negotiate lower tier, or cancel and refund paid amounts according to provider-approved flows.
- **Oversubscription:** atomic reservation, waitlist, or reject extra checkout.
- **Supplier stock failure:** stop checkout, source alternate with explicit buyer consent, or refund.
- **Partial group fulfillment:** permit only where contract, allocation, buyer consent and settlement/refund rules support it.

## 6.4 Group compatibility

Do not group similar-looking products that differ materially in model year, warranty, capacity, region, delivery schedule, authenticity or installation requirements. Exact SKU preferred in V1; alternatives require opt-in and clear comparison.

---

# 7. Buyer Journey

1. Discover/search public product requests and seller deals.
2. Register/login with OTP; consent to privacy notices.
3. Create exact request or join an existing compatible request.
4. Enter quantity, delivery region, preferred budget and deadline.
5. See “Price pending” and request status; no misleading purchase CTA.
6. Reconfirm purchase readiness when procurement starts.
7. Receive a final, itemized offer showing landed unit price, fees, taxes, MOQ, seller/fulfillment details, warranty, return terms, payment deadline and estimated delivery.
8. Accept explicitly, select address, pay through approved gateway.
9. See group threshold and order status; receive notice if MOQ fails.
10. Track shipment, delivery, inspection status and evidence.
11. Acknowledge full/partial receipt, or report damage/shortage.
12. Obtain invoice(s), support, refunds and dispute outcomes.

**UX states:** loading, empty, expired, quote pending, revised, supplier unavailable, below MOQ, payment pending/failed/duplicate, stock lost, partially fulfilled, dispute open, refund pending/complete.

**Accessibility:** mobile-first, clear Tamil/English localization readiness, large tap targets, screen-reader labels, low-bandwidth resilience, understandable price breakdowns.

---

# 8. Seller Journey

1. Register business, verify authorized representative, submit KYC/GST and bank details per provider and platform requirements.
2. Await risk/verification approval.
3. Browse eligible RFQs without competing bids or private buyer data.
4. Submit sealed quote and evidence; edit through versioned revisions before deadline.
5. Receive negotiation request and respond through portal or staff-assisted workflow.
6. Confirm final terms, quantity/stock, tax and delivery commitments.
7. Receive confirmed fulfillment instruction only after agreed conditions.
8. Pack, dispatch, upload invoice and shipping evidence; respond to inspections/issues.
9. See settlement eligibility, holds, deductions and provider-confirmed payout status.
10. Handle approved returns, warranty claims and disputes.

Seller-led deals add listing management, tier prices, inventory allocation, region restrictions, offer expiration and performance analytics.

---

# 9. Quality Assurance, Delivery and Buyer Acknowledgement

## 9.1 Risk-based inspection tiers

| Tier | Typical scope | Evidence | V1 practicality |
|---|---|---|---|
| A | Verified seller + standard packaged goods | Supplier invoice, serial/IMEI where appropriate, packing and delivery proof | Default |
| B | Higher-value or higher-risk orders | Pre-dispatch photo/video, sample check, batch/serial verification | Selected categories |
| C | Custom or high-value B2B goods | Independent inspection partner, documented checklist, possibly on-site inspection | Pilot only |

Do not advertise “physically inspected” for Tier A. Inspection is not a substitute for legal warranties or consumer rights. Some products need licensed specialists or product-specific compliance; exclude risky regulated categories initially.

## 9.2 Distinct fulfillment milestones

`READY_TO_DISPATCH → DISPATCHED → IN_TRANSIT → DELIVERED_REPORTED → DELIVERY_VERIFIED → BUYER_ACCEPTED / ISSUE_REPORTED / PARTIALLY_ACCEPTED`

- **Proof of delivery (POD):** courier status, timestamp, recipient/OTP/signature where suitable.
- **Quality acceptance:** buyer's separate confirmation of item count, model, visible condition and agreed inspection scope.
- **Exceptions:** damaged, wrong item, missing units, late, counterfeit suspected, non-delivery, buyer unavailable, buyer unresponsive.

## 9.3 Acknowledgement and dispute window

Provide a clear, legally reviewed inspection/complaint window appropriate to category and contract. Send reminders; if buyer is silent, do not indefinitely withhold payment or treat silence as a waiver of statutory rights. Any deemed-acceptance or settlement timing must be permitted by law and gateway contract. A timely dispute triggers an evidence-preserving hold **only if the payment partner supports it**; otherwise handle refunds/adjustments using the approved process.

## 9.4 Partial deliveries

Record ordered, dispatched, delivered, accepted, disputed, refunded and settlement-eligible quantities per order item. Split shipments and partial acceptance must never settle disputed units accidentally. Where provider cannot split settlement, escalate to manual finance workflow under contract.

---

# 10. Payments, Custody, Settlement and Financial Controls

> **Regulatory design gate:** The platform must not collect and retain buyer funds in an ordinary operating account on the assumption that it can act as escrow. Engage an Indian payments lawyer, CA and prospective RBI-authorized payment aggregator before implementing conditional settlement. A gateway product name alone is not proof that the desired hold-until-acknowledgement flow is permitted.

## 10.1 Preferred V1 architecture

- Buyer pays through an approved payment provider / marketplace arrangement.
- Provider, not the platform's ordinary bank account, handles fund custody and permitted settlement routing.
- Platform stores gateway transaction IDs and immutable ledger entries, not raw card details.
- Platform calculates **business eligibility** for settlement based on delivery, inspection, disputes and contractual conditions.
- Actual release timing, split payout and any hold are controlled by the provider's approved capabilities and agreements; do not promise buyer-acknowledgement-gated payout until confirmed in writing.
- Provider webhooks plus reconciliation confirm paid/refunded/settled states. UI approval ≠ bank payout.

**Gateway evaluation checklist:** Razorpay Route, Cashfree Easy Split or another approved provider; confirm legal entity eligibility, seller KYC, settlement timing, authorized hold periods, partial settlements, refunds after payout, reserve/chargebacks, split fees, webhook semantics, sandbox capabilities, reconciliation exports and service support. Do not assume features are available on every account.

## 10.2 Payment state machine

`CREATED → CHECKOUT_PENDING → AUTHORIZED? → CAPTURED/PAID → ALLOCATED → REFUND_PENDING/REFUNDED or SETTLEMENT_PENDING → SETTLED`

Use provider-specific mapping; not all gateways expose authorization separately. Failed, expired, duplicate and chargeback states are first-class. All payment events are idempotent and replay-safe.

## 10.3 Settlement state machine

`NOT_ELIGIBLE → AWAITING_VERIFICATION → APPROVAL_PENDING → APPROVED → RELEASE_REQUESTED → PROVIDER_CONFIRMED_SETTLED`

Exception states: `DISPUTED`, `ON_HOLD_IF_PROVIDER_SUPPORTED`, `PARTIALLY_ELIGIBLE`, `RELEASE_FAILED`, `RECONCILIATION_MISMATCH`. Every hold requires reason, actor, timestamp, review deadline and permitted legal/provider basis.

## 10.4 Money and invoices

- Store money as integer paise, currency `INR`, explicit tax and fee components.
- Snapshot unit price, quantity, shipping, taxes, commission, discounts, service fees and accepted terms at checkout.
- Distinguish seller goods invoice from platform commission/service-fee invoice; actual invoicing depends on seller-of-record structure.
- Seller bank details changed only through verification and cooling-off/dual approval controls.
- Never calculate balances solely from mutable order totals; use double-entry-style financial ledger and immutable gateway event records.
- Daily reconciliation: orders ↔ payments ↔ refunds ↔ provider settlements ↔ seller payable ↔ fees/taxes ↔ bank reports.

## 10.5 Indian tax/legal review items (not conclusions)

Assess GST registration, seller invoices, marketplace GST TCS (Section 52 where applicable), income-tax TDS under Section 194-O where applicable, GST on platform fees, e-commerce operator classification, payment aggregator directions, consumer protection/e-commerce rules, Legal Metrology labeling/pricing, DPDP obligations, grievance handling, product-specific rules and the legal suitability of an LLP versus Private Limited for the exact marketplace role. Have counsel review before collecting live funds. Rules and applicability may change.

---

# 11. Cancellation, Refunds, Chargebacks and Disputes

## 11.1 Scenario matrix

| Scenario | Decision rule | Buyer outcome | Seller/finance outcome |
|---|---|---|---|
| Buyer dislikes indicative price | No binding acceptance | Can leave freely | No order |
| Buyer rejects final offer | No payment | Can decline | Supplier quote may expire |
| Buyer accepts but does not pay | Checkout deadline | Acceptance lapses | Not counted toward MOQ |
| Paid MOQ not reached | Policy deadline | Extend with consent or refund | No unauthorized purchase order |
| Seller withdraws before order | Re-source or cancel | New opt-in offer or refund | Supplier performance flag |
| Seller fails after payment | Escalate and remediate | Refund/replacement per policy and law | Contractual penalties if enforceable |
| Wrong/damaged item | Evidence and inspection | Repair/replace/refund as applicable | Hold/adjust only within approved settlement arrangement |
| Partial delivery | Item-level reconciliation | Accept good units; dispute missing | Partial eligible settlement if supported |
| Buyer unresponsive | Reminder/escalation policy | Rights preserved | Settle per provider/contract timetable |
| Duplicate payment | Idempotency + reconciliation | Reverse/refund duplicate | Never duplicate order |
| Chargeback after payout | Provider dispute workflow | Fair review | Reserve/recovery per contract |
| Counterfeit/safety issue | Immediate escalation | Remediation and reporting as needed | Suspend supplier/listing |
| Fraudulent buyer/seller | Risk controls | Investigate without prejudging | Freeze only as law/provider allows |

## 11.2 Dispute service levels

Define response, investigation, evidence request, decision and appeal targets **as policy variables** `[CONFIRM]`, not invented promises. Dispute decisions need documented reason, owner, attachments and maker-checker for financial adjustment. Preserve legal consumer remedies even if platform deadlines expire.

---

# 12. Fraud, Abuse, Privacy and Trust

**Buyer risks:** fake groups, bot requests, duplicate accounts, coordinated price manipulation, chargebacks, false non-delivery claims, abusive returns.

**Seller risks:** fake bids, bait-and-switch, counterfeit products, invoice manipulation, phantom stock, collusion, non-fulfillment, bank account takeover.

**Staff risks:** quote fabrication, commission kickbacks, hidden supplier relationships, bid leaks, unauthorized refunds, payout diversion.

**Controls:** OTP, rate limits, device/IP risk signals without overcollection, seller KYC, supplier bank verification, quote evidence, conflict-of-interest declarations, approval segregation, immutable audit, anomaly alerts, supplier scorecards, fraud case management, permission reviews, MFA for staff, encrypted secrets and PII, retention limits, export/deletion workflows subject to legal retention, consent records and incident response.

**Bid confidentiality:** enforce in SQL/query scopes, API serializers, background jobs, exports, notifications, logs, analytics and admin role permissions. Test direct object access and enumeration. Do not expose losing bid amounts through public “savings” calculations.

---

# 13. Complete Screen Map

## 13.1 Buyer-facing web/PWA

1. Home/discovery: trending demand, active deals, categories, trust explanation.
2. Search/product details: specs, variants, location availability, requests vs confirmed deals.
3. Create buying request: exact SKU/spec, quantity, region, target budget, purchase timeframe.
4. Request/group detail: interested/ready counts, progress, status, privacy-safe activity, join/leave.
5. Final offer detail: supplier/quality terms, landed price, expiry, MOQ, warranty, explicit accept.
6. Checkout/payment status: address, invoice details, gateway handoff, recovery from failures.
7. My requests/orders: lifecycle, shipping, refund, dispute and invoices.
8. Delivery acknowledgement: accept all, partial accept, issue report, photo upload.
9. Notifications/inbox: offer ready, reconfirmation, payment deadline, shipping, resolution.
10. Profile/organization: addresses, preferences, team approvals, privacy/account deletion.

## 13.2 Seller portal

1. Seller onboarding and verification.
2. Eligible RFQ feed and sealed bid submission.
3. My bids/revisions and negotiation requests.
4. Listings and seller-led deals/tiers.
5. Confirmed orders, inventory and dispatch.
6. Returns/disputes/inspection requests.
7. Settlement reports and invoice history.
8. Seller team members, permissions and profile.

## 13.3 Internal operations portal

1. Executive operations dashboard.
2. Procurement queue and assignment board.
3. Request case detail with aggregate buyer budgets and readiness.
4. Confidential bid comparison and landed-cost normalization.
5. Supplier lead directory, contact log and offline quote capture.
6. Negotiation history and approval workflow.
7. Final-offer composer, preview and publish.
8. Group payment/MOQ monitor and supplier release gate.
9. Fulfillment tracking and inspection queue.
10. Disputes, refunds and risk cases.
11. Finance ledger, gateway reconciliation and settlement approvals.
12. Seller onboarding/compliance and audit log.
13. Category, geography, fees, policy and staff permission settings.

All screens must specify skeleton/loading, empty, validation error, permission denied, retry, stale data, success and conflict states.

---

# 14. Architecture and Technology

## 14.1 Recommended V1 platform

- **Responsive web/PWA first**, sharable via WhatsApp and discoverable through search; Flutter buyer/seller apps only after traction.
- **Frontend:** Vue 3, TypeScript, Vite, Pinia, Tailwind CSS; SSR/prerender public deal/category pages for SEO.
- **Backend:** Custom modular PHP 8.3 REST API (no Laravel), strict types, Composer, PSR conventions, secure middleware, OpenAPI contract.
- **Data:** MySQL 8 with InnoDB transactions, proper constraints/indexes, Redis for cache/queues/locks where appropriate.
- **Infra:** Ubuntu, Nginx, PHP-FPM, Redis workers, object storage for documents/evidence, CI/CD, backups and observability.
- **Integrations:** payment gateway, SMS OTP, transactional email, WhatsApp Business notifications where approved, shipment provider, optional GST/KYC vendors.

## 14.2 Modular monolith boundaries

`Identity` · `Organizations` · `Catalog` · `Demand` · `RFQ` · `Bidding` · `ProcurementCRM` · `SupplierVerification` · `Offers` · `Commitments` · `Orders` · `Payments` · `Ledger` · `Settlements` · `Fulfillment` · `Quality` · `Disputes` · `Notifications` · `Analytics` · `AdminAudit`.

Modules communicate via explicit interfaces and domain events/outbox; avoid tightly coupled cross-module SQL updates. Do not introduce microservices until justified by scale/team boundaries.

## 14.3 Core design principles

- Tenant/organization-scoped queries and server-side authorization on every endpoint.
- MySQL transactions and `SELECT ... FOR UPDATE` for stock/commitment transitions; unique idempotency keys.
- Transactional outbox for events; at-least-once worker processing; handlers idempotent.
- Append-only bid revisions, price acceptance snapshots, ledger events and audit trails.
- UTC timestamps internally, buyer-local display and explicit deadline timezone.
- Upload scanning, private signed object access, document retention policy.
- No sensitive gateway credentials or private bid contents in client logs.
- Feature flags for categories, cities, deposit experiments, payment capabilities and inspection tiers.

---

# 15. Logical Database Blueprint

**All tables:** UUID/ULID or consistent BIGINT IDs, `created_at`, `updated_at`, actor IDs where relevant, indexes, foreign keys, soft delete only where legally appropriate. Immutable records use append-only semantics rather than edits.

## 15.1 Identity and businesses

- `users(id, mobile, email, status, verified_at, locale)`
- `organizations(id, type, legal_name, tax_id_ref, verification_status)`
- `organization_members(id, organization_id, user_id, role, status)`
- `roles`, `permissions`, `role_permissions`, `user_role_assignments`
- `seller_profiles(id, organization_id, categories, service_regions, score, status)`
- `seller_verification_cases(id, seller_id, provider_ref, result, reviewed_by, reviewed_at)`
- `seller_bank_accounts(id, seller_id, provider_token, verified_at, status)` — **never raw bank secrets unnecessarily**
- `addresses(id, owner_type, owner_id, region, postal_code, encrypted_detail)`

## 15.2 Catalog and demand

- `categories`, `brands`, `products`, `product_variants`, `product_attributes`
- `buy_requests(id, buyer_id, variant_id, spec_json, qty, region_id, purchase_by, status)`
- `buyer_price_preferences(id, request_id, target_paise, max_paise, visibility_scope)`
- `demand_groups(id, match_key, region_id, variant_id, status, closes_at)`
- `group_memberships(id, group_id, buyer_id, qty, intent_level, confirmed_at)`
- `group_intent_events(id, membership_id, from_level, to_level, reason, created_at)`
- `group_compatibility_rules(id, group_id, allowed_variants_json, constraints_json)`

## 15.3 RFQ, bids and procurement

- `rfqs(id, group_id, spec_snapshot_json, qty_band_json, bid_deadline, status)`
- `rfq_seller_invitations(id, rfq_id, seller_id, status)`
- `seller_bids(id, rfq_id, seller_id, source, status, latest_revision_id)`
- `seller_bid_revisions(id, bid_id, revision_no, unit_price_paise, tax_basis, freight_paise, moq, max_qty, valid_until, lead_time, warranty_snapshot, evidence_ref, submitted_at)`
- `procurement_cases(id, rfq_id, assigned_agent_id, priority, stage, sla_due_at)`
- `supplier_leads(id, legal_name, source, verification_status, region_id)`
- `supplier_contacts(id, supplier_lead_id, method, outcome, contacted_by, contacted_at)`
- `negotiation_events(id, case_id, supplier_id, bid_id, type, terms_json, created_by, created_at)`
- `negotiation_attachments(id, event_id, object_ref, mime, checksum)`
- `procurement_tasks(id, case_id, assignee_id, due_at, status)`

## 15.4 Offers, commitments, orders

- `negotiated_offers(id, group_id, seller_id, source_bid_revision_id, status, version, expires_at, terms_snapshot_json)`
- `offer_price_tiers(id, offer_id, min_paid_qty, max_paid_qty, unit_price_paise)`
- `offer_approvals(id, offer_id, approver_id, decision, reason, decided_at)`
- `offer_acceptances(id, offer_id, buyer_id, qty, accepted_terms_hash, accepted_at)`
- `stock_reservations(id, offer_id, buyer_id, qty, expires_at, status)`
- `orders(id, buyer_id, group_id, offer_id, status, total_paise, currency, terms_hash)`
- `order_items(id, order_id, variant_id, qty, unit_price_paise, tax_paise, shipping_paise, snapshot_json)`
- `order_status_events(id, order_id, from_state, to_state, actor_id, reason)`
- `seller_fulfillment_orders(id, seller_id, offer_id, status, supplier_confirmation_ref)`

## 15.5 Payments, fulfillment, quality and finance

- `payment_intents(id, order_id, provider, provider_ref, amount_paise, status, idempotency_key)`
- `payment_webhook_events(id, provider, provider_event_id UNIQUE, signature_valid, payload_ref, processed_at)`
- `ledger_accounts`, `ledger_entries`, `ledger_postings` (balanced postings)
- `refunds(id, payment_id, order_item_id, amount_paise, reason, status, provider_ref)`
- `seller_settlements(id, seller_id, provider_ref, eligible_paise, requested_paise, confirmed_paise, status)`
- `settlement_items(id, settlement_id, order_item_id, eligible_qty, amount_paise)`
- `settlement_approvals(id, settlement_id, approver_id, decision, reason)`
- `reconciliation_runs(id, provider, period_start, period_end, status)`
- `reconciliation_exceptions(id, run_id, entity_type, entity_id, discrepancy_paise, status)`
- `shipments(id, seller_fulfillment_order_id, carrier, tracking_ref, status)`
- `shipment_items(id, shipment_id, order_item_id, qty)`
- `delivery_evidence(id, shipment_id, evidence_type, object_ref, verified_at)`
- `inspections(id, order_id, shipment_id, tier, inspector_id, status, result, checklist_snapshot)`
- `inspection_findings(id, inspection_id, order_item_id, finding_type, qty_affected, evidence_ref)`
- `buyer_acknowledgements(id, order_id, shipment_id, buyer_id, accepted_qty_json, disputed_qty_json, submitted_at)`
- `disputes(id, order_id, type, status, opened_by, assigned_to, resolution, closed_at)`
- `dispute_events`, `dispute_evidence`, `dispute_adjustments`
- `notifications`, `notification_deliveries`, `audit_logs`, `risk_flags`, `policy_versions`, `consent_records`

## 15.6 Essential constraints and indexes

- Unique `(rfq_id, seller_id)` for seller bid identity; append revisions.
- Unique `(provider, provider_event_id)` for webhook replay protection.
- Unique payment idempotency key per logical operation.
- Check nonnegative quantity/money and explicit state transition guards.
- Composite indexes on `buy_requests(region_id, variant_id, status)`, `demand_groups(match_key, status)`, `seller_bids(rfq_id, seller_id)`, `orders(buyer_id, status)`, `shipments(status)`, `settlements(seller_id, status)`.
- DB transaction guards for quantity reservation, paid MOQ and stock decrement; verify capacity under concurrent checkout.

---

# 16. API Blueprint (Illustrative)

**Public/buyer:**
- `POST /v1/auth/otp/request`, `POST /v1/auth/otp/verify`
- `GET /v1/catalog/search`, `GET /v1/deals`, `GET /v1/deals/{id}`
- `POST /v1/buy-requests`, `GET /v1/buy-requests/{id}`
- `POST /v1/groups/{id}/join`, `POST /v1/groups/{id}/reconfirm`
- `GET /v1/offers/{id}`, `POST /v1/offers/{id}/accept`
- `POST /v1/orders/{id}/checkout`, `GET /v1/orders/{id}`
- `POST /v1/shipments/{id}/acknowledgements`, `POST /v1/orders/{id}/disputes`
- `GET /v1/me/requests`, `GET /v1/me/orders`, `DELETE /v1/me/account` (controlled deletion flow)

**Seller:**
- `POST /v1/seller/onboarding`, `GET /v1/seller/rfqs`
- `POST /v1/seller/rfqs/{id}/bids`, `POST /v1/seller/bids/{id}/revisions`
- `GET /v1/seller/orders`, `POST /v1/seller/orders/{id}/dispatch`
- `GET /v1/seller/settlements`, `POST /v1/seller/deals`

**Internal:**
- `GET /v1/internal/procurement/cases`, `POST /v1/internal/cases/{id}/assign`
- `POST /v1/internal/cases/{id}/supplier-leads`, `POST /v1/internal/cases/{id}/offline-quotes`
- `GET /v1/internal/rfqs/{id}/confidential-bids` — **strict permission**
- `POST /v1/internal/offers`, `POST /v1/internal/offers/{id}/approve`, `POST /v1/internal/offers/{id}/publish`
- `POST /v1/internal/inspections/{id}/complete`
- `POST /v1/internal/settlements/{id}/approve`, `POST /v1/internal/disputes/{id}/resolve`
- `GET /v1/internal/reconciliation/exceptions`, `GET /v1/internal/audit`

**Provider:** `POST /v1/webhooks/payments/{provider}` with raw-body signature verification, timestamp/replay controls and asynchronous idempotent processing.

All write APIs require authenticated role/ownership checks, request validation, idempotency where appropriate, optimistic version checks for edits, structured errors and audit context. Generate OpenAPI and contract tests before frontend integration.

---

# 17. Notifications and Communication

**Channels:** in-app, SMS OTP, transactional email and opt-in WhatsApp Business messages under applicable template/consent rules.

**Events:** request created; compatible group found; ready-intent reconfirmation; bid window status (without confidential bids); proposed price validation; final offer published; offer expiring; payment success/failure; MOQ reached/failed; seller dispatch; delivery; buyer acknowledgement reminder; dispute update; refund confirmed; seller settlement confirmed.

Deduplicate events, respect channel preferences, limit noisy reminders, preserve consent and provide message delivery audit. Do not reveal sensitive purchase data in notification previews unnecessarily.

---

# 18. Operations SOPs and Staffing

## 18.1 Core SOPs

1. Demand qualification: verify product match, buyer intent, quantity and region.
2. RFQ publication: approve specifications and bid deadline.
3. Seller sourcing: invite verified sellers and search offline leads in parallel.
4. Bid normalization: compare full landed cost and contractual terms.
5. Buyer price validation: estimate realistic conversion without promising demand.
6. Final negotiation: document supplier quote and approval.
7. Offer publication: review terms, legal disclosures and deadline.
8. MOQ/payment control: reconcile paid quantities, reserve stock, prevent oversell.
9. Fulfillment: track supplier confirmation, shipping, POD and exceptions.
10. Quality/acceptance: apply inspection tier and collect buyer evidence.
11. Disputes/refunds: classify, investigate, approve and reconcile.
12. Settlement: verify provider rules, eligibility, approvals and actual payout.

## 18.2 Staffing model for pilot

Founder/CTO: architecture, security, payment integration and product. Business/operations partner: supplier acquisition and commercial oversight. Start with one procurement lead/agent and shared support/finance controls; do not build a large call center until order volume proves need. External CA/legal counsel and inspection partners on demand. Avoid concentration of all approvals in one individual.

## 18.3 Supplier relationships

Document supplier category, authorization, warranty channel, lead time, historical fulfillment, dispute rate, on-time delivery, quote response and settlement issues. Use performance data for prioritization, not opaque discrimination.

---

# 19. Legal, Compliance and Contract Package

**Required legal review before launch:**
- Marketplace legal entity eligibility and exact role: facilitator, agent, reseller or seller of record.
- Indian consumer protection/e-commerce rules, grievance officer/contact, product disclosure and seller identity.
- GST, TCS/TDS and invoicing applicability; interstate trade and tax handling.
- Payment aggregation, permitted settlement timing and provider-managed fund flow.
- DPDP/privacy, retention, consent, security, data processor agreements.
- Product-specific licenses/certifications (e.g., food, medicines, electronics, vehicles) and category exclusions.
- Legal Metrology, advertising/discount claims, product warranties, returns and liability.
- B2B procurement contracts, purchase orders and inspection/acceptance clauses.

**Document set:** Seller Marketplace Agreement; Seller RFQ/Bid Rules; Buyer Terms and Group Purchase Rules; Privacy Notice; Refund/Cancellation/Dispute Policy; Procurement/Inspection Service Scope; Settlement and Fee Schedule; B2B Procurement Addendum; Supplier Offline Quotation Confirmation; internal Anti-Bribery/Conflict of Interest Policy; incident response and retention policy.

**Contractual decision points `[CONFIRM]`:** Seller of record; who invoices buyer; commission payer; who bears payment fees, shipping and inspection; return shipping; warranty responsibility; price validity; MOQ failure; order cancellation; supplier nonperformance; inspection window; provider settlement timetable; dispute escalation; reserves and chargebacks.

---

# 20. Analytics and Decision Dashboard

**Demand funnel:** views → requests → verified interest → purchase-ready → offer received → offer accepted → paid → delivered → accepted → repeat.

**Seller funnel:** invited → bid submitted → qualified → negotiated → awarded → fulfilled → settled.

**Procurement KPIs:** time to first quote, time to approved offer, sourcing success when zero bids, number of contacted suppliers, quote-to-deal rate, cost per case, agent workload, lost reasons.

**Commercial KPIs:** completed GMV, platform net revenue, contribution margin, average realized savings vs **verifiable comparable benchmark**, effective take rate, buyer repeat rate, supplier repeat rate, refunds/disputes per order, on-time delivery, inspection cost.

**Finance KPIs:** payment success, reconciliation exceptions, refund aging, seller payable aging, chargeback rate, gateway settlement exceptions.

**Cohorts:** by city, category, buyer acquisition source, buyer type and supplier type. Separate **interest** from **paid demand** in every chart.

---

# 21. Pilot and Go-to-Market

## 21.1 Launch wedge

Choose one city and a narrow product category with:
- Clear SKU comparability and standardized warranties.
- Several reachable distributors/manufacturers.
- Meaningful potential bulk discount and manageable logistics.
- Low counterfeit, breakage, regulatory and return complexity.
- Buyers with near-term purchase intent and shareable groups.

Candidate categories for evaluation: standard appliances, office equipment, small-business consumables, selected furniture or other locally supplyable standardized products. Do not assume these are profitable; interview suppliers and buyers first.

## 21.2 Four-week validation plan (targets are hypotheses, not forecasts)

**Week 1:** Interview 15–20 buyers and 8–10 suppliers; validate real willingness to purchase, price expectations, discount capacity and procurement friction. Build a manual RFQ spreadsheet and demo.

**Week 2:** Collect 30–50 verified requests in one category/region; deduplicate and obtain purchase-ready reconfirmations. Invite suppliers to quote; measure response and landed-cost savings.

**Week 3:** Manually negotiate and publish a small number of transparent offers. Run buyer acceptance tests; do **not** accept live customer funds until contracts, provider and compliance are ready.

**Week 4:** Review conversion, operational cost, supplier response, price rejection and margin. Proceed to live payment pilot only after gates pass.

**Illustrative decision gates `[CONFIRM]`:** ≥3 reliable suppliers per category; meaningful verified buyer readiness; positive expected contribution margin after variable costs; acceptable complaint risk; provider approval for settlement model; counsel-approved contract package. Adjust thresholds based on evidence, not optimism.

## 21.3 Growth channels

Search-indexed public group pages, buyer referral links, WhatsApp sharing with consent, housing associations, employee groups, apartment communities, college alumni, SME associations and procurement partners. Do not spam or promise unverified savings. Supplier referrals may become an acquisition channel once payout reliability is proven.

---

# 22. Development Roadmap and Release Gates

## Phase 0 — Discovery and compliance design

Deliver: buyer/supplier interviews, category selection, legal entity/payment structure decision, provider capability matrix, approved policies, UX prototype, threat model. **Gate:** payment flow and settlement promises documented and legally/provider validated.

## Phase 1 — Core foundation

Identity/OTP, organizations, seller verification, catalog/variants, RBAC, audit, admin shell, CI/CD, migrations, OpenAPI, automated tests. **Gate:** no cross-tenant data leaks, staff MFA and permissions tested.

## Phase 2 — Demand and sealed procurement

Create/join requests, grouping, intent levels, budgets, RFQs, sealed bids/revisions, sourcing CRM, offline quotes, negotiation, offer approvals. **Gate:** seller cannot discover competing bid through API, export, events or logs.

## Phase 3 — Offer conversion and order core

Final offers, price/terms snapshot, acceptance, paid MOQ logic, inventory reservation, checkout scaffolding, seller-led tier deals. **Gate:** concurrency and expiry tests pass; no oversell, no silent repricing.

## Phase 4 — Provider-approved payments and fulfillment

Gateway onboarding, payment webhooks, refunds, ledger, reconciliation, shipping, inspection, buyer acknowledgement, disputes, seller settlement workflow. **Gate:** written provider approval of intended flow, sandbox end-to-end tests and finance reconciliation sign-off.

## Phase 5 — Controlled pilot

One category/city, manually supervised orders, seller onboarding, support playbooks, dashboard, fraud alerts. **Gate:** acceptable conversion, operational SLAs, positive contribution margin and low reconciliation exceptions.

## Phase 6 — Expansion

B2B organizations/approval workflows, supplier scorecards, additional cities/categories, inspection partners, Flutter apps, AI-assisted matching and negotiation support only after evidence.

---

# 23. QA Test Matrix and Failure Injection

**Core acceptance tests:**
1. Seller A cannot read Seller B's bid via list/detail, guessing IDs, export, websocket, notification or logs.
2. Buyer cannot see internal negotiations or private maximum budgets of other buyers.
3. Procurement agent records offline quote with mandatory supplier confirmation and evidence.
4. Bid deadline and revisions behave correctly under concurrent writes.
5. Offer cannot publish without required approvals, supplier confirmation and valid terms.
6. 120 interested / 105 accepted / 90 paid with MOQ 100 **does not** release supplier order.
7. 100 simultaneous buyers cannot reserve 101 units of 100 available.
8. Payment webhook delivered 3 times creates exactly one paid allocation and ledger posting.
9. Paid buyer cannot be silently repriced or substituted.
10. Seller withdrawal after buyer payment triggers defined re-source/consent/refund workflow.
11. Partial delivery of 7/10 units leaves 3 disputed/unsettled according to provider rules.
12. Buyer silence follows legal/provider-approved timeline; no indefinite hold.
13. Refund after partial seller settlement is reconciled correctly.
14. Bank account changes cannot be self-approved by same staff member.
15. Buyer/seller account deletion requests honor retention requirements.
16. Wrong-signature webhook is rejected and security event recorded.
17. Failed queue job replay cannot duplicate supplier order, refund or payout request.
18. Buyer rejects high offer; system reopens sourcing without creating a payment obligation.
19. Deal expires while buyer checks out; payment recovery/refund is safe and explicit.
20. Provider settlement report disagrees with internal ledger; exception blocks false “paid” status.

**Nonfunctional tests:** OWASP API Top 10, auth/rate limiting, PII access, backup restore, load/concurrency, disaster recovery, timezones, accessibility, mobile networks, observability and penetration testing before production.

---

# 24. Open Decisions Register

| Decision | Recommended default | Owner/status |
|---|---|---|
| Brand/domain | Working name only | `[CONFIRM]` |
| Legal entity | Obtain India e-commerce counsel advice; do not assume LLP fits every model | `[LEGAL REVIEW]` |
| First city/category | Narrow pilot based on supply and demand interviews | `[CONFIRM]` |
| Seller-of-record / invoice issuer | Explicit contract and tax design | `[LEGAL/CA REVIEW]` |
| Payment provider | Evaluate approved split/conditional settlement options | `[PROVIDER CONFIRMATION]` |
| Hold-until-buyer-acceptance | Only if legally and contractually permitted | `[NOT APPROVED]` |
| Initial token advance | No pre-quote deposit in V1 | Proposed |
| Buyer target budget visibility | Internal authorized staff only | Proposed |
| Seller bid confidentiality | Strict sealed bids | Required |
| MOQ basis | Paid eligible units | Required |
| Inspection | Risk-based tiers, not universal physical checks | Proposed |
| Platform fee/commission | Per-category pilot economics | `[CONFIRM]` |
| Seller settlement schedule | Provider-approved, clearly disclosed | `[CONFIRM]` |
| Buyer inspection window | Category- and law-specific | `[LEGAL REVIEW]` |
| Shipping/returns cost allocation | Contract-specific | `[CONFIRM]` |
| Team approval thresholds | Maker-checker matrix | `[CONFIRM]` |
| Public reference pricing | Only with reliable evidence | Proposed |
| Flutter mobile app | After web/PWA validation | Proposed |

---

# 25. Instructions for Coding Agent (Kimi K3 / Claude / Codex)

Use this blueprint as the **product source of truth**, not as permission to invent missing policies. First create:

1. `docs/architecture.md` — module boundaries, auth, event flows, deployment.
2. `docs/state-machines.md` — request, bid, offer, order, payment, delivery, dispute and settlement transitions.
3. `docs/permissions-matrix.md` — role × resource × action × scope, including sealed bids.
4. `docs/database-schema.md` — normalized schema, indexes, constraints and migrations.
5. `docs/api-openapi.yaml` — versioned API contract with errors and idempotency.
6. `docs/payment-provider-gates.md` — explicit unsupported/awaiting approval features.
7. `docs/test-plan.md` — acceptance and concurrency tests from Section 23.
8. `docs/implementation-phases.md` — small reviewable milestones and rollback strategy.

**Development constraints:** custom PHP 8.3 (no Laravel), Vue 3 + TypeScript + Tailwind + Pinia, MySQL 8, Redis, mobile-first PWA, strict RBAC, migrations, automated tests, OpenAPI, audit logs, integer paise, webhook idempotency, immutable commercial snapshots, private bid access, and feature flags. No mock gateway behavior represented as production payments. No direct seller settlement in production until provider approval. Do not claim tests passed unless executed. Request approval before major architectural changes or business-policy assumptions.

**Build sequence:** Foundation → Demand → Bidding/CRM → Offers → Orders/MOQ → Gateway/Ledger → Fulfillment/Inspection → Disputes/Settlement → Admin/Analytics → Pilot. Each phase must include migrations, APIs, UI, tests, security review, demo and a checklist of unfinished items.

---

# 26. Final Recommendation

The most robust version of this business is a **managed procurement marketplace** that combines buyer aggregation, confidential supplier competition, proactive human sourcing, buyer price validation, explicit acceptance, provider-managed payments, evidence-based quality control and auditable settlement operations.

**Do not build the whole platform before proving that verified buyers will accept negotiated prices and suppliers will honor them.** Validate the procurement process manually, then automate the repeatable parts. Protect customer funds, supplier trust and platform economics from day one.

**Next action:** Decide the first city and category; validate the legal/payment settlement model with providers and counsel; then authorize Phase 0/1 development.

---

*End of blueprint — Version 1.0*
