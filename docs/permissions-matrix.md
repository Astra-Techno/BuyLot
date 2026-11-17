# RBAC + ABAC Permissions Matrix

**Platform:** Collective Commerce
**Version:** 1.0 — 17 November 2026
**Source:** Collective Commerce Complete Business Blueprint, Sections 2, 5.1, 12, 16

---

## 1. Role Definitions

| # | Role | Scope | Portal |
|---|---|---|---|
| 1 | Guest | Public, unauthenticated | Buyer-facing web |
| 2 | Buyer | Own account, own org (B2C individual) | Buyer-facing web/PWA |
| 3 | Business Buyer | Organization-scoped, multi-user approval chain | Buyer-facing web/PWA |
| 4 | Seller | Own seller profile, own bids, own offers, own orders | Seller portal |
| 5 | Procurement Agent | Assigned procurement cases | Internal operations portal |
| 6 | Procurement Lead | All procurement cases; approve offers above agent authority | Internal operations portal |
| 7 | Quality Inspector | Assigned inspection tasks only | Internal operations portal |
| 8 | Operations Agent | Assigned shipment and fulfillment tasks | Internal operations portal |
| 9 | Finance Operator | Financial reconciliation, refund prep, settlement prep | Internal operations portal |
| 10 | Finance Approver | Authorize financial releases; no self-approval | Internal operations portal |
| 11 | Support / Disputes | Assigned dispute cases; need-to-know order data | Internal operations portal |
| 12 | Compliance / Admin | Seller verification, audit, risk, policy | Internal operations portal |
| 13 | Super Admin | System configuration, access grants; MFA-protected | Internal operations portal |

---

## 2. Action Legend

| Symbol | Meaning |
|---|---|
| **Full** | Full CRUD access across the entire resource set |
| **Own** | Access limited to records owned by or belonging to the authenticated user/organization |
| **Assigned** | Access limited to records explicitly assigned to this user |
| **Approve-only** | Can read and approve/reject; cannot create or edit content |
| **None** | No access; requests return 403 |
| **–** | Not applicable for this resource/role combination |

---

## 3. Permissions Matrix

### 3.1 Catalog (products, categories, variants)

| Resource / Action | Guest | Buyer | Biz Buyer | Seller | Proc Agent | Proc Lead | Qual Insp | Ops Agent | Fin Oper | Fin Approver | Support | Compliance | Super Admin |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Products — read public** | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full |
| **Products — create** | None | None | None | Own | None | None | None | None | None | None | None | None | Full |
| **Products — update** | None | None | None | Own | None | Assigned | None | None | None | None | None | Full | Full |
| **Products — delete** | None | None | None | Own | None | None | None | None | None | None | None | Full | Full |
| **Products — export** | None | None | None | Own | Assigned | Full | None | None | None | None | None | Full | Full |
| **Categories — read** | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full |
| **Categories — create/update/delete** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **Variants — read public** | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full |
| **Variants — create/update** | None | None | None | Own | None | None | None | None | None | None | None | None | Full |

**ABAC constraints — Catalog:**
- Sellers may only create/update/delete their own product listings (`seller_id = auth.user.seller_id`).
- Procurement Lead may update catalog entries only for products linked to an active assigned procurement case.
- Compliance/Admin may update or suppress any listing for policy/verification reasons.
- Category and variant creation/deletion is restricted to Compliance/Admin and Super Admin to prevent taxonomy pollution.

---

### 3.2 Buy Requests (own vs. all)

| Resource / Action | Guest | Buyer | Biz Buyer | Seller | Proc Agent | Proc Lead | Qual Insp | Ops Agent | Fin Oper | Fin Approver | Support | Compliance | Super Admin |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Own buy requests — create** | None | Own | Own | None | None | None | None | None | None | None | None | None | Full |
| **Own buy requests — read** | None | Own | Own | None | None | None | None | None | None | None | Own | Own | Full |
| **Own buy requests — update** | None | Own | Own | None | None | None | None | None | None | None | None | None | Full |
| **Own buy requests — delete/cancel** | None | Own | Own | None | None | None | None | None | None | None | None | Full | Full |
| **All buy requests — read (staff)** | None | None | None | None | Full | Full | None | None | None | None | Assigned | Full | Full |
| **All buy requests — export** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **Buy request — approve (org multi-user)** | None | None | Approve-only | None | None | None | None | None | None | None | None | None | Full |

**ABAC constraints — Buy Requests:**
- Buyers read/update only records where `created_by = auth.user.id`.
- Business Buyers are scoped to their `organization_id`; individual members within an org see only their own requests unless granted org-level approval authority.
- Procurement Agents read all requests to source for assigned cases but cannot modify buyer-submitted data.
- Buyer maximum budget field (`max_budget`) is **staff-visible only** (Procurement Agent, Procurement Lead, Compliance, Super Admin); it is never exposed to Sellers, Guests, or Support unless Support has an active dispute against that order.
- Support role may read a specific buyer request only when linked to an assigned dispute case.

---

### 3.3 Demand Groups (own, all, assigned)

| Resource / Action | Guest | Buyer | Biz Buyer | Seller | Proc Agent | Proc Lead | Qual Insp | Ops Agent | Fin Oper | Fin Approver | Support | Compliance | Super Admin |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Public group stats — read** | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full |
| **Join / leave group** | None | Own | Own | None | None | None | None | None | None | None | None | None | Full |
| **Reconfirm purchase readiness** | None | Own | Own | None | None | None | None | None | None | None | None | None | Full |
| **Group member list (count only)** | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full |
| **Group member list (identities)** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **Group aggregate budgets (staff only)** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **All groups — read** | None | None | None | None | Full | Full | None | None | None | None | Assigned | Full | Full |
| **Group — create/modify** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **Group — close/expire** | None | None | None | None | None | Full | None | None | None | None | None | Full | Full |
| **Group — export** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |

**ABAC constraints — Demand Groups:**
- Individual buyer identities within a group are never exposed to Sellers, Guests, or other Buyers. Only privacy-safe aggregate counts (interested count, ready count) are public.
- Aggregate budget distribution data (used for price discovery per Section 4.3) is strictly restricted to Procurement Agent (assigned cases), Procurement Lead, Compliance, and Super Admin.
- Sellers see only the existence of a group and its public stats, not member identities or budget signals.

---

### 3.4 RFQs (own bids, all bids, confidential comparison)

| Resource / Action | Guest | Buyer | Biz Buyer | Seller | Proc Agent | Proc Lead | Qual Insp | Ops Agent | Fin Oper | Fin Approver | Support | Compliance | Super Admin |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **RFQ spec — read (public)** | Full | Full | Full | Full | Full | Full | None | None | None | None | None | Full | Full |
| **Own bid — create** | None | None | None | Own | None | None | None | None | None | None | None | None | Full |
| **Own bid — read** | None | None | None | Own | None | None | None | None | None | None | None | None | Full |
| **Own bid — update/revise** | None | None | None | Own | None | None | None | None | None | None | None | None | Full |
| **Own bid — delete/withdraw** | None | None | None | Own | None | None | None | None | None | None | None | None | Full |
| **All bids (sealed) — read** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **Confidential bid comparison** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **Offline/team-sourced quote — create** | None | None | None | None | Assigned | Full | None | None | None | None | None | None | Full |
| **Offline quote — read** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **Bid normalization / landed-cost view** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **RFQ deadline extension** | None | None | None | None | None | Full | None | None | None | None | None | Full | Full |
| **RFQ bid reopen** | None | None | None | None | None | Full | None | None | None | None | None | Full | Full |
| **RFQ — export bids** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **B2B RFQ — create** | None | None | Own | None | None | None | None | None | None | None | None | None | Full |
| **B2B RFQ — read own** | None | None | Own | None | None | None | None | None | None | None | None | None | Full |

**ABAC constraints — RFQs (Sealed Bid Isolation):**
- A Seller's read scope on bids is enforced at the query level: `WHERE seller_id = auth.user.seller_id`. This is not merely a UI filter; it is enforced server-side on every API serializer, background job, notification, export, and analytics query.
- Sellers never receive: competing bid values, ranking positions, other seller identities, negotiation notes, or private buyer maximum budgets.
- Procurement Agents access confidential bid comparison only for cases where `case.assigned_agent_id = auth.user.id`.
- Deadline extensions and bid reopening events are written to the immutable audit log with actor identity and timestamp.
- Offline/team-sourced quotes (`source = TEAM_SOURCED`) require mandatory evidence attachment and author identity; staff cannot fabricate a supplier bid.
- Buyer maximum budget is accessible to Procurement Agent (assigned case only) and Procurement Lead as an internal planning signal; it is never surfaced in RFQ spec published to sellers.

---

### 3.5 Procurement Cases (own, assigned, all)

| Resource / Action | Guest | Buyer | Biz Buyer | Seller | Proc Agent | Proc Lead | Qual Insp | Ops Agent | Fin Oper | Fin Approver | Support | Compliance | Super Admin |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Case — create** | None | None | None | None | Full | Full | None | None | None | None | None | None | Full |
| **Case — read own/assigned** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **Case — read all** | None | None | None | None | None | Full | None | None | None | None | None | Full | Full |
| **Case — update (notes, status, tasks)** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **Case — assign/reassign** | None | None | None | None | None | Full | None | None | None | None | None | Full | Full |
| **Case — close/archive** | None | None | None | None | None | Full | None | None | None | None | None | Full | Full |
| **Case — delete** | None | None | None | None | None | None | None | None | None | None | None | None | Full |
| **Case — export** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **Supplier lead — create/update** | None | None | None | None | Assigned | Full | None | None | None | None | None | None | Full |
| **Supplier lead — read** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **Contact/call log — create** | None | None | None | None | Assigned | Full | None | None | None | None | None | None | Full |
| **Contact/call log — read** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **Negotiation history — read** | None | None | None | None | Assigned | Full | None | None | None | None | None | Full | Full |
| **Approval workflow — trigger** | None | None | None | None | Assigned | Full | None | None | None | None | None | None | Full |
| **Approval workflow — approve** | None | None | None | None | None | Approve-only | None | None | None | None | None | Full | Full |
| **Case — priority/SLA update** | None | None | None | None | None | Full | None | None | None | None | None | Full | Full |

**ABAC constraints — Procurement Cases:**
- Procurement Agents read/update only cases where `case.assigned_agent_id = auth.user.id`.
- Procurement Agents cannot modify paid orders, seller bank details, or financial settlement records (per Section 5.3).
- Procurement Lead has full case access to enable escalation, mentoring, and coverage.
- Commercial commitments above the configured approval limit (`config.procurement_approval_threshold`) require maker-checker: Procurement Agent submits; Procurement Lead (or Compliance for high-value) approves; they cannot be the same person.

---

### 3.6 Offers (draft, published, approval)

| Resource / Action | Guest | Buyer | Biz Buyer | Seller | Proc Agent | Proc Lead | Qual Insp | Ops Agent | Fin Oper | Fin Approver | Support | Compliance | Super Admin |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Draft offer — create** | None | None | None | Own | Assigned | Full | None | None | None | None | None | None | Full |
| **Draft offer — read** | None | None | None | Own | Assigned | Full | None | None | None | None | None | Full | Full |
| **Draft offer — update** | None | None | None | Own | Assigned | Full | None | None | None | None | None | None | Full |
| **Draft offer — delete** | None | None | None | Own | None | Full | None | None | None | None | None | Full | Full |
| **Published offer — read (buyer)** | Full | Full | Full | Own | Full | Full | None | Full | Full | Full | Full | Full | Full |
| **Offer — submit for approval** | None | None | None | None | Assigned | Full | None | None | None | None | None | None | Full |
| **Offer — approve / reject** | None | None | None | None | None | Approve-only | None | None | None | None | None | Full | Full |
| **Offer — publish** | None | None | None | None | None | Full | None | None | None | None | None | Full | Full |
| **Offer — accept (buyer)** | None | Own | Own | None | None | None | None | None | None | None | None | None | Full |
| **Offer — extend deadline** | None | None | None | None | None | Full | None | None | None | None | None | Full | Full |
| **Offer — export** | None | None | None | Own | Assigned | Full | None | None | None | None | None | Full | Full |

**ABAC constraints — Offers:**
- Seller-led draft offers are scoped to `seller_id = auth.user.seller_id`.
- Internal procurement offers (composed by Procurement Agent) are scoped to `case.assigned_agent_id = auth.user.id`.
- Offer approval is a maker-checker gate: the agent who composed the offer cannot be the approver.
- Final offers shown to buyers include full price breakdown (landed unit price, fees, taxes, MOQ, warranty, return terms) per buyer UX journey (Section 7, step 7). The underlying seller cost or commission margin is not exposed to buyers.

---

### 3.7 Orders (own, assigned, all)

| Resource / Action | Guest | Buyer | Biz Buyer | Seller | Proc Agent | Proc Lead | Qual Insp | Ops Agent | Fin Oper | Fin Approver | Support | Compliance | Super Admin |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Own order — create (checkout)** | None | Own | Own | None | None | None | None | None | None | None | None | None | Full |
| **Own order — read** | None | Own | Own | Own | None | None | None | None | None | None | Assigned | None | Full |
| **All orders — read (staff)** | None | None | None | None | None | Full | Assigned | Assigned | Full | Full | Assigned | Full | Full |
| **Order — update status** | None | None | None | None | None | Full | None | Assigned | None | None | None | Full | Full |
| **Order — cancel** | None | Own | Own | None | None | Full | None | None | None | None | None | Full | Full |
| **Order — delete** | None | None | None | None | None | None | None | None | None | None | None | None | Full |
| **Order — export** | None | Own | Own | Own | None | Full | None | None | Full | Full | Assigned | Full | Full |
| **Seller order view (own confirmed orders)** | None | None | None | Own | None | None | None | None | None | None | None | None | Full |
| **Org order — approve (B2B multi-user)** | None | None | Approve-only | None | None | None | None | None | None | None | None | None | Full |

**ABAC constraints — Orders:**
- Buyers read only orders where `order.buyer_id = auth.user.id`.
- Sellers read only orders where `order.seller_id = auth.user.seller_id` (their own confirmed orders).
- Sellers cannot see other sellers' orders, buyer identities beyond what is contractually necessary for fulfillment, or payment breakdown details.
- Quality Inspector and Operations Agent access only orders linked to their assigned inspection/fulfillment tasks.
- Support reads an order only when it is linked to an assigned dispute case.
- Finance Operator reads all orders for reconciliation but cannot modify order status.
- Business Buyer org-approval chain: order progresses only when authorized approver within the same `organization_id` approves.

---

### 3.8 Payments (own, all, refund, settlement)

| Resource / Action | Guest | Buyer | Biz Buyer | Seller | Proc Agent | Proc Lead | Qual Insp | Ops Agent | Fin Oper | Fin Approver | Support | Compliance | Super Admin |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Own payment — initiate** | None | Own | Own | None | None | None | None | None | None | None | None | None | Full |
| **Own payment — read status** | None | Own | Own | None | None | None | None | None | None | None | Assigned | None | Full |
| **All payments — read** | None | None | None | None | None | None | None | None | Full | Full | None | Full | Full |
| **Payments — export** | None | Own | Own | Own | None | None | None | None | Full | Full | None | Full | Full |
| **Refund — initiate (request)** | None | Own | Own | None | None | None | None | None | Full | None | Assigned | None | Full |
| **Refund — approve / execute** | None | None | None | None | None | None | None | None | None | Approve-only | None | Full | Full |
| **Settlement — prepare** | None | None | None | None | None | None | None | None | Full | None | None | None | Full |
| **Settlement — approve / release** | None | None | None | None | None | None | None | None | None | Approve-only | None | Full | Full |
| **Reconciliation exceptions — read** | None | None | None | None | None | None | None | None | Full | Full | None | Full | Full |
| **Seller settlement statement — read** | None | None | None | Own | None | None | None | None | Full | Full | None | Full | Full |
| **Chargeback / dispute payment flag** | None | None | None | None | None | None | None | None | Full | Approve-only | Assigned | Full | Full |
| **Payment — delete / void** | None | None | None | None | None | None | None | None | None | None | None | None | Full |

**ABAC constraints — Payments:**
- No self-approval for financial adjustments: Finance Operator prepares refunds and settlements; Finance Approver authorizes; they must be different users (`approver_id != preparer_id`). This is enforced at the application layer and audited.
- Finance Approver cannot approve their own expense or adjustment submission.
- Procurement Agents have no access to payment records; they cannot trigger refunds or settlements (per Section 5.3).
- Seller bank account details are managed separately under Seller Profile (Compliance-gated) and are not accessible to Finance Operator or Procurement roles directly.
- Payment gateway webhooks are processed by a dedicated system role; raw webhook payloads are not accessible to any user role except Super Admin audit.
- Buyer payment amount and breakdown is own-scoped; sellers never see buyer payment details.

---

### 3.9 Fulfillment (shipments, inspections, delivery)

| Resource / Action | Guest | Buyer | Biz Buyer | Seller | Proc Agent | Proc Lead | Qual Insp | Ops Agent | Fin Oper | Fin Approver | Support | Compliance | Super Admin |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Shipment — create (dispatch)** | None | None | None | Own | None | None | None | Assigned | None | None | None | None | Full |
| **Shipment — read (buyer tracking)** | None | Own | Own | Own | None | None | None | Assigned | None | None | Assigned | Full | Full |
| **Shipment — read (all)** | None | None | None | None | None | Full | None | Full | None | None | None | Full | Full |
| **Shipment — update status** | None | None | None | Own | None | None | None | Assigned | None | None | None | Full | Full |
| **Delivery acknowledgement — create** | None | Own | Own | None | None | None | None | None | None | None | None | None | Full |
| **Delivery acknowledgement — read** | None | Own | Own | Own | None | Full | Assigned | Full | None | None | Assigned | Full | Full |
| **Inspection task — assign** | None | None | None | None | None | Full | None | None | None | None | None | Full | Full |
| **Inspection task — read (assigned)** | None | None | None | None | None | None | Assigned | None | None | None | None | Full | Full |
| **Inspection — complete / submit evidence** | None | None | None | None | None | None | Assigned | None | None | None | None | Full | Full |
| **Inspection report — read** | None | Own | Own | Own | Assigned | Full | Assigned | Full | None | None | Assigned | Full | Full |
| **Inspection — approve result** | None | None | None | None | None | Approve-only | None | None | None | None | None | Full | Full |
| **Fulfillment — export** | None | None | None | Own | None | Full | None | Full | None | None | None | Full | Full |

**ABAC constraints — Fulfillment:**
- Sellers dispatch their own orders only (`order.seller_id = auth.user.seller_id`); they cannot access other sellers' shipment records.
- Quality Inspector reads and completes only inspection tasks where `inspection.assigned_inspector_id = auth.user.id`; they cannot access financial data, bid details, or buyer budgets.
- Operations Agent manages only shipments/tasks where `shipment.assigned_ops_agent_id = auth.user.id`.
- Buyer sees delivery tracking for own orders; partial-fulfillment acknowledgement is own-scoped.
- Inspection evidence (photos, documents) is stored in private object storage with signed access; Inspector uploads are write-once.

---

### 3.10 Disputes (own, assigned, all, financial adjustments)

| Resource / Action | Guest | Buyer | Biz Buyer | Seller | Proc Agent | Proc Lead | Qual Insp | Ops Agent | Fin Oper | Fin Approver | Support | Compliance | Super Admin |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Dispute — open** | None | Own | Own | Own | None | None | None | None | None | None | None | None | Full |
| **Dispute — read own** | None | Own | Own | Own | None | None | None | None | None | None | Assigned | None | Full |
| **Dispute — read all** | None | None | None | None | None | None | None | None | None | None | Full | Full | Full |
| **Dispute — assign** | None | None | None | None | None | None | None | None | None | None | Full | Full | Full |
| **Dispute — update (notes, evidence)** | None | Own | Own | Own | None | None | None | None | None | None | Assigned | Full | Full |
| **Dispute — resolve (close)** | None | None | None | None | None | None | None | None | None | None | Assigned | Full | Full |
| **Financial adjustment — propose** | None | None | None | None | None | None | None | None | Full | None | Assigned | Full | Full |
| **Financial adjustment — approve** | None | None | None | None | None | None | None | None | None | Approve-only | None | Full | Full |
| **Financial adjustment — no self-approval** | — | — | — | — | — | — | — | — | Blocked | Blocked | Blocked | Blocked | Blocked |
| **Dispute — export** | None | None | None | None | None | None | None | None | None | None | Assigned | Full | Full |
| **Dispute — delete** | None | None | None | None | None | None | None | None | None | None | None | None | Full |

**ABAC constraints — Disputes:**
- Buyers and Sellers read only disputes where they are a party (`dispute.buyer_id = auth.user.id` or `dispute.seller_id = auth.user.seller_id`).
- Support agent reads and updates only disputes where `dispute.assigned_agent_id = auth.user.id`.
- Financial adjustments (refunds, compensations, deductions) follow strict maker-checker: Finance Operator (or Support with limited authority) proposes; Finance Approver authorizes. The same user cannot both propose and approve the same adjustment (`approver_id != proposer_id`), enforced at the database constraint level.
- Compliance/Admin can override or escalate dispute resolution for policy enforcement.
- Dispute resolution involving seller deductions requires both dispute evidence and Finance Approver sign-off.

---

### 3.11 Seller Profiles (own, all, verification)

| Resource / Action | Guest | Buyer | Biz Buyer | Seller | Proc Agent | Proc Lead | Qual Insp | Ops Agent | Fin Oper | Fin Approver | Support | Compliance | Super Admin |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Public seller profile — read** | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full | Full |
| **Own seller profile — create** | None | None | None | Own | None | None | None | None | None | None | None | None | Full |
| **Own seller profile — update** | None | None | None | Own | None | None | None | None | None | None | None | Full | Full |
| **Own seller profile — delete** | None | None | None | Own | None | None | None | None | None | None | None | Full | Full |
| **All seller profiles — read (internal)** | None | None | None | None | Full | Full | None | None | Full | Full | Full | Full | Full |
| **Seller KYC / verification documents — read** | None | None | None | Own | None | None | None | None | None | None | None | Full | Full |
| **Seller KYC — verify / approve** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **Seller KYC — reject / suspend** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **Seller bank details — read** | None | None | None | Own | None | None | None | None | None | None | None | Full | Full |
| **Seller bank details — update** | None | None | None | Own | None | None | None | None | None | None | None | Full | Full |
| **Seller risk flag — create** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **Seller performance data — read** | None | None | None | Own | Assigned | Full | None | None | None | None | None | Full | Full |
| **Seller profiles — export** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |

**ABAC constraints — Seller Profiles:**
- Seller bank account details (`bank_account_number`, `ifsc`, `upi`) are accessible only to the Seller themselves (own) and Compliance/Admin. Finance Operator and Procurement roles do not have direct read access to raw bank credentials.
- Seller KYC documents (GST certificate, PAN, trade license, authorized signatory) are restricted to Compliance/Admin and Super Admin.
- Procurement Agent may read seller profiles and performance data while sourcing for an assigned case but cannot modify or verify seller information.
- Buyer-facing public profile shows only: trading name, verification badge, product categories, and fulfillment region. It does not expose contact details, tax IDs, or bank information.

---

### 3.12 Admin (settings, audit, users, roles)

| Resource / Action | Guest | Buyer | Biz Buyer | Seller | Proc Agent | Proc Lead | Qual Insp | Ops Agent | Fin Oper | Fin Approver | Support | Compliance | Super Admin |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **Platform settings — read** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **Platform settings — update** | None | None | None | None | None | None | None | None | None | None | None | None | Full |
| **Audit log — read (own actions)** | None | Own | Own | Own | Own | Own | Own | Own | Own | Own | Own | Full | Full |
| **Audit log — read (all)** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **Audit log — export** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **Audit log — delete / modify** | None | None | None | None | None | None | None | None | None | None | None | None | None |
| **User accounts — read (own)** | None | Own | Own | Own | Own | Own | Own | Own | Own | Own | Own | Full | Full |
| **User accounts — read (all)** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **User accounts — create** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **User accounts — update** | None | Own | Own | Own | Own | Own | Own | Own | Own | Own | Own | Full | Full |
| **User accounts — suspend/delete** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **Role assignments — read** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **Role assignments — grant / revoke** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **Super Admin role — grant** | None | None | None | None | None | None | None | None | None | None | None | None | Full |
| **Feature flags — read** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **Feature flags — update** | None | None | None | None | None | None | None | None | None | None | None | None | Full |
| **Category/geography policy — update** | None | None | None | None | None | None | None | None | None | None | None | Full | Full |
| **Fee/commission config — update** | None | None | None | None | None | None | None | None | None | None | None | None | Full |
| **MFA enforcement** | — | — | — | — | Required | Required | Required | Required | Required | Required | Required | Required | Required |

**ABAC constraints — Admin:**
- Audit log records are immutable: no role, including Super Admin, may delete or modify audit entries. Writes are append-only.
- Super Admin requires MFA on every session; all Super Admin actions generate audit events with actor ID, timestamp, IP, and action payload.
- Compliance/Admin may grant standard operational roles (Procurement Agent, Support, etc.) but cannot grant Compliance/Admin or Super Admin to themselves or others; only Super Admin can elevate to those tiers.
- Role assignments to financial approval roles (Finance Approver) require Super Admin; this prevents Compliance/Admin from bypassing maker-checker by self-assigning approval authority.
- Platform settings that affect payment flows, fee structures, or commission rates are Super Admin-only to prevent unauthorized commercial changes.

---

## 4. ABAC Constraint Summary

The table below consolidates all ABAC constraint types. Each applies on top of the RBAC role grants above. A request must satisfy both its role grant and all applicable ABAC constraints.

| Constraint Type | Description | Enforced On | Affected Roles |
|---|---|---|---|
| **Ownership scope** | `resource.owner_id = auth.user.id`; user can only act on their own data | Buy requests, orders, payments, offers, bids, profiles | Buyer, Business Buyer, Seller |
| **Organization scope** | `resource.organization_id = auth.user.organization_id`; member can only act on org-owned resources | Org buy requests, B2B RFQs, org orders, org users | Business Buyer, org-scoped Seller teams |
| **Assignment scope** | `resource.assigned_to = auth.user.id`; staff can only act on explicitly assigned work | Procurement cases, shipments, inspections, dispute cases | Procurement Agent, Ops Agent, Quality Inspector, Support |
| **Category scope** | Staff or seller access may be bounded to authorized product categories (`auth.user.category_permissions`) | RFQs, procurement cases, catalog | Seller (category-approved), Procurement Agent (category-scoped), Quality Inspector |
| **Amount threshold (maker-checker)** | Transactions or commitments above configured threshold require a second authorized approver; submitter and approver must be different users | Offer approval, financial adjustments, refunds, settlements, procurement commitments | Procurement Agent → Procurement Lead, Finance Operator → Finance Approver |
| **No self-approval** | `approver_id != preparer_id` enforced at application and DB layer for all financial adjustments, offer approvals, and settlement releases | Financial adjustments, refunds, settlement approvals, offer publication | Finance Operator, Finance Approver, Procurement Agent, Procurement Lead |
| **Sealed bid isolation** | Seller's bid query scope is enforced server-side: `WHERE bid.seller_id = auth.user.seller_id`. Applied in SQL, API serializers, background jobs, notifications, exports, analytics, and admin views | All RFQ bid reads, comparisons, notifications, exports | Seller; blocked for all other non-staff roles |
| **Buyer budget privacy** | `max_budget` and individual budget signals are staff-only fields; excluded from all seller-facing API serializers, public group stats, notifications, and exports | Buy requests, demand groups, bid comparison views | Visible to: Procurement Agent (assigned), Procurement Lead, Compliance, Super Admin. Hidden from: Seller, Guest, Buyer (others), Support (unless active dispute) |
| **Competing bid confidentiality** | Seller never receives another seller's bid value, identity, rank, or negotiation notes; enforced in serializer, job outputs, notification templates, and analytics aggregations | RFQ bids, comparison reports, analytics | All roles except Procurement Agent (assigned), Procurement Lead, Compliance, Super Admin |
| **Conflict-of-interest declaration** | Staff members with declared or detected supplier relationships are removed from the assignment scope for affected cases | Procurement cases, offer approvals | Procurement Agent, Procurement Lead |
| **Environment scope** | Staff access to production PII requires MFA and is logged; staging/dev environments have synthetic data only | All PII fields | All staff roles |
| **Immutable records** | Bid revisions, payment events, audit entries, price acceptance snapshots, and ledger entries are append-only; no role may update or delete them | Bid revisions, payment ledger, audit log, price snapshots | All roles including Super Admin |

---

## 5. Cross-Cutting Enforcement Notes

### 5.1 Server-side authorization requirement

Every API endpoint enforces role and ABAC checks server-side before returning data or committing writes. UI-level hiding of features is a usability convenience only; it is not a security control. Direct object access (e.g., `GET /v1/seller/bids/999` by a different seller) must return 403, not filtered data.

### 5.2 Sealed bid enforcement checkpoints

The bid confidentiality rule must be verified at all of the following checkpoints:

1. SQL query (`WHERE bid.seller_id = ?`)
2. API response serializer (no competitor fields in JSON)
3. Background job outputs (quote normalization job filters by role)
4. Email/SMS notification templates (no competitor values)
5. Analytics aggregations (exposed savings cannot reverse-engineer losing bid)
6. Export files (CSV/XLSX exports honour same scope)
7. Admin views (non-privileged admin roles see masked competitor values)
8. Automated tests (direct object access and enumeration regression tests)

### 5.3 Maker-checker amount thresholds

| Transaction Type | Threshold (configurable) | Maker | Checker |
|---|---|---|---|
| Procurement commercial commitment | Above `config.procurement_approval_limit` | Procurement Agent | Procurement Lead |
| Final offer publication | All offers | Procurement Agent | Procurement Lead |
| Refund to buyer | Above `config.refund_auto_limit` | Finance Operator / Support | Finance Approver |
| Seller settlement release | All settlements | Finance Operator | Finance Approver |
| Financial dispute adjustment | All amounts | Finance Operator / Support | Finance Approver |
| Role assignment (Fin Approver, Compliance, Super Admin) | Always | Compliance / Super Admin | Super Admin |

### 5.4 Audit log events

All of the following must generate an immutable audit event with actor ID, role, IP address, timestamp (UTC), resource type, resource ID, and action payload:

- Any role grant or revocation
- Any financial approval or rejection
- Bid comparison view access
- Offer publication
- Settlement release
- Dispute resolution with financial adjustment
- Super Admin platform settings change
- Seller KYC approve/reject
- Seller bank account update
- Any export of sensitive data (payments, bids, buyer budgets)
- Failed permission checks on sensitive resources (403 events)

### 5.5 Role assignment authority chain

```
Super Admin
  └── grants: Compliance/Admin
  └── grants: Finance Approver
  └── grants: all other roles

Compliance/Admin
  └── grants: Procurement Lead, Procurement Agent, Quality Inspector,
              Operations Agent, Finance Operator, Support/Disputes
  └── cannot grant: Compliance/Admin, Finance Approver, Super Admin

No other role may grant roles.
```

### 5.6 Data not visible to sellers (summary)

| Data Field | Visible to Seller |
|---|---|
| Other sellers' bid amounts | No |
| Other sellers' identities in an RFQ | No |
| Bid ranking or scores | No |
| Buyer maximum budget | No |
| Buyer identities in a demand group | No |
| Buyer count beyond public aggregate | No |
| Internal negotiation notes | No |
| Procurement agent contact history | No |
| Platform commission margin | No |
| Other sellers' settlement amounts | No |

---

## 6. Role Permission Quick Reference

| Role | Primary resource scope | Can approve financial items | Can view sealed bids | Can view buyer budgets |
|---|---|---|---|---|
| Guest | Public catalog, public group stats | No | No | No |
| Buyer | Own requests, own orders, own payments | No | No | Own only |
| Business Buyer | Org-scoped requests, orders, RFQs | Org-internal only | No | Org own only |
| Seller | Own bids, own offers, own orders, own settlement | No | Own bids only | No |
| Procurement Agent | Assigned cases, assigned RFQs | No | Yes (assigned) | Yes (assigned) |
| Procurement Lead | All cases, all RFQs | Yes (offers) | Yes (all) | Yes (all) |
| Quality Inspector | Assigned inspections | No | No | No |
| Operations Agent | Assigned shipments, fulfillment | No | No | No |
| Finance Operator | All payments, refund prep, settlement prep | No (prepares only) | No | No |
| Finance Approver | All financial approvals | Yes (not own submissions) | No | No |
| Support / Disputes | Assigned dispute cases | Limited (propose only) | No | No (unless active dispute) |
| Compliance / Admin | All seller profiles, audit, users, roles | Yes (policy override) | Yes (all) | Yes (all) |
| Super Admin | All resources, system config | Yes (all) | Yes (all) | Yes (all) |
