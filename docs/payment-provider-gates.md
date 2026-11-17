# Payment Provider Gates

**Source:** Collective Commerce Blueprint v1.0, Sections 10 and 24
**Status:** Pre-implementation — no provider has been selected or approved
**Date:** 2026-11-17

> **Hard constraint (spec 10):** The platform must not collect and retain buyer funds in an ordinary operating account as informal escrow. Do not promise any hold-until-acknowledgement flow until a provider confirms it in writing for the specific legal entity and account type.

---

## 1. Provider Evaluation Checklist

The following criteria must be assessed for every candidate provider. The two named candidates from spec 10.1 are **Razorpay Route** and **Cashfree Easy Split**. Neither is confirmed; the comparison below reflects what must be verified, not what has been verified.

| Criterion | What to verify | Razorpay Route | Cashfree Easy Split |
|---|---|---|---|
| Legal entity eligibility | Which Indian entity types are accepted (Private Ltd, LLP, Proprietorship) | REQUIRES_VERIFICATION | REQUIRES_VERIFICATION |
| Seller KYC requirements | Documents required; turnaround time; bulk onboarding support | REQUIRES_VERIFICATION | REQUIRES_VERIFICATION |
| Settlement timing | T+N schedule options; minimum hold periods available | REQUIRES_VERIFICATION | REQUIRES_VERIFICATION |
| Authorized hold periods | Maximum hold duration permitted by RBI/provider policy | REQUIRES_VERIFICATION | REQUIRES_VERIFICATION |
| Partial settlement support | Can a portion of an order be settled while the rest is held | REQUIRES_VERIFICATION | REQUIRES_VERIFICATION |
| Refunds after payout | Can a refund be initiated after funds have already been settled to seller | REQUIRES_VERIFICATION | REQUIRES_VERIFICATION |
| Reserve/chargeback handling | How reserves are funded; who bears chargeback risk; recovery mechanism | REQUIRES_VERIFICATION | REQUIRES_VERIFICATION |
| Split fees | Per-transaction fee structure for marketplace splits; who is billed | REQUIRES_VERIFICATION | REQUIRES_VERIFICATION |
| Webhook semantics | Events emitted; delivery guarantees; idempotency keys; retry behavior | REQUIRES_VERIFICATION | REQUIRES_VERIFICATION |
| Sandbox capabilities | Full sandbox available; supports split/hold flows in test mode | REQUIRES_VERIFICATION | REQUIRES_VERIFICATION |
| Reconciliation exports | Daily/weekly settlement reports; format; API access | REQUIRES_VERIFICATION | REQUIRES_VERIFICATION |
| Service/support | Dedicated account manager; SLA for disputes; API support tier | REQUIRES_VERIFICATION | REQUIRES_VERIFICATION |

---

## 2. Supported vs Unsupported Features

| Feature | Status | Notes |
|---|---|---|
| Basic payment capture (card, UPI, netbanking) | CONFIRMED | Standard gateway capability |
| Webhook delivery of payment events | CONFIRMED | Standard; must implement idempotent handlers |
| Refund initiation before settlement | CONFIRMED | Standard; must be tested end-to-end |
| Split payout to sellers | REQUIRES_VERIFICATION | Core to the marketplace model; must confirm per account type |
| Partial settlement (line-item level) | REQUIRES_VERIFICATION | Required for partial delivery scenario |
| Conditional settlement (release on staff approval) | REQUIRES_PROVIDER_CONFIRMATION | Provider must explicitly permit this in account agreement |
| Hold-until-buyer-acceptance | NOT_APPROVED | Spec 24 explicitly marks this `[NOT APPROVED]`; not to be built or promised |
| Refund after seller payout has been made | REQUIRES_VERIFICATION | Needed for post-settlement dispute resolution |
| Reserve/chargeback recovery from seller | REQUIRES_VERIFICATION | Contract terms must be established before go-live |
| Seller bank account verification at onboarding | REQUIRES_VERIFICATION | Critical fraud control; confirm provider API or manual flow |
| Sandbox split/hold flow testing | REQUIRES_VERIFICATION | Must be possible before any production implementation |

---

## 3. Implementation Gates

The following items MUST be resolved and documented before any live payment flow is activated.

| Gate | Requirement | Responsible |
|---|---|---|
| G-1 | Written provider approval of the intended split/conditional settlement flow for the specific legal entity and account | Platform + Provider |
| G-2 | Legal entity registered and approved by provider (KYC complete) | Founders + CA |
| G-3 | Indian payments lawyer and CA have reviewed the settlement model against PA/PG RBI directions and marketplace GST TCS/TDS obligations | Legal/CA |
| G-4 | Sandbox end-to-end test results documented: payment capture, split, hold, partial settlement, refund, chargeback simulation | Engineering |
| G-5 | Settlement model fully documented: who holds funds, for how long, on what trigger, with what provider authority | Product + Legal |
| G-6 | Chargeback and reserve handling agreed in writing with provider; liability allocation documented | Founders + Provider |
| G-7 | Seller bank account change controls implemented and tested (dual approval, cooling-off period) | Engineering |
| G-8 | Daily reconciliation flow implemented and verified against provider settlement reports | Engineering + Finance |
| G-9 | Feature flag controlling live settlement is off by default; requires explicit operator action to enable per category/region | Engineering |

No gate may be bypassed. If a provider cannot satisfy G-1 or G-5, the architecture must be revised before coding the settlement module.

---

## 4. Fallback Strategies

These are contingency paths when a desired feature is unavailable from the selected provider.

| Desired feature | Fallback if unavailable |
|---|---|
| Conditional/delayed settlement | Use provider's standard settlement schedule (T+N days); document clearly to sellers; remove buyer-acknowledgement gate from settlement trigger |
| Split payout to sellers | Platform collects total; manually disburses to seller from platform account; requires separate banking controls, TDS handling and increased compliance burden — treat as last resort |
| Partial settlement | Settle full order only after all items confirmed; or cancel and refund the undelivered portion and re-initiate a corrected order |
| Refund after payout | Recover from seller's next settlement batch (if provider supports netting) or pursue contractual recovery via seller agreement; document as a known limitation |
| Hold-until-buyer-acceptance | Do not implement this feature at all; instead use a time-based settlement release (e.g., auto-settle after N days if no dispute raised) subject to provider-permitted hold period |
| Sandbox split/hold testing | Do not proceed to production; switch to alternative provider that offers sandbox parity |
| Seller KYC via provider API | Manual KYC via internal ops team with document storage; delay seller onboarding accordingly |

---

## 5. Open Decisions from Section 24 (Payment-Related)

| Decision | Spec status | Recommended default | Blocking for |
|---|---|---|---|
| Payment provider selection | `[PROVIDER CONFIRMATION]` | Evaluate Razorpay Route and Cashfree Easy Split against checklist in Section 1 above; obtain written approval of flow | G-1, G-4, G-5 |
| Hold-until-buyer-acceptance feasibility | `[NOT APPROVED]` | Do not implement; use time-based release with dispute window instead | Architecture must not be designed around this feature |
| Seller settlement schedule | `[CONFIRM]` | Use provider-approved T+N schedule; disclose clearly in seller agreement and onboarding | G-5; affects seller trust and working capital |
| Who bears payment processing fees | `[CONFIRM]` | Define per-category in pilot economics; model both seller-borne and platform-borne scenarios | Affects commission structure and contribution margin |
| Legal entity type (LLP vs Private Ltd) | `[LEGAL REVIEW]` | Obtain counsel advice; entity type affects provider eligibility, GST TCS applicability and liability | G-2, G-3 |
| Buyer inspection window length | `[LEGAL REVIEW]` | Category- and law-specific; determines when settlement can safely be triggered | G-5; must be defined before settlement state machine is finalized |

---

## 6. References Within the Blueprint

- **Section 10.1** — preferred V1 payment architecture and gateway evaluation checklist
- **Section 10.2** — payment state machine (`CREATED → ... → SETTLED`)
- **Section 10.3** — settlement state machine and exception states
- **Section 10.4** — money representation (integer paise), ledger, reconciliation requirements
- **Section 10.5** — Indian tax/legal review items (GST TCS, TDS 194-O, PA/PG directions)
- **Section 11.1** — refund/chargeback scenario matrix
- **Section 24** — open decisions register (payment provider, hold flow, settlement schedule, fee allocation)
- **Section 25** — this document is explicitly listed as a required deliverable (`docs/payment-provider-gates.md`)
