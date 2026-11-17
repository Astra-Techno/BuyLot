# Collective Commerce -- State Machine Definitions

**Version:** 1.0
**Date:** 17 November 2026
**Spec reference:** `Collective_Commerce_Complete_Business_Blueprint.md`

This document defines the complete state machines for every first-class entity in the Collective Commerce platform. Each machine specifies states, transitions, guards, side effects (events emitted), error handling and authorized roles.

---

## Table of Contents

1. [Buy Request](#1-buy-request)
2. [Demand Group](#2-demand-group)
3. [Seller Bid](#3-seller-bid)
4. [Negotiated Offer](#4-negotiated-offer)
5. [Order](#5-order)
6. [Payment](#6-payment)
7. [Settlement](#7-settlement)
8. [Fulfillment / Delivery](#8-fulfillment--delivery)
9. [Dispute](#9-dispute)
10. [Buyer Intent / Commitment](#10-buyer-intent--commitment)

---

## 1. Buy Request

### ASCII State Diagram

```
                    +-------+
                    | DRAFT |
                    +---+---+
                        |
                   submit_request
                        |
                    +---v----+
              +---->| ACTIVE |<---------+
              |     +---+----+          |
              |         |          ungroup (if
         reactivate     |         group dissolved)
         (if group      |
          cancelled)  match_to_group
              |         |
              |     +---v----+
              +-----| GROUPED|
                    +---+----+
                        |
                   sourcing_started
                        |
                    +---v-----+
                    | SOURCING|
                    +---+-----+
                        |
                   offer_created
                        |
                 +------v--------+
                 | OFFER_PENDING |
                 +------+--------+
                        |
                   offer_published
                        |
                +-------v----------+
                | OFFER_AVAILABLE  |
                +------------------+
                        |
                   (terminal: buyer
                    accepts/pays or
                    offer expires)

   --- From any non-terminal state ---

        expire_deadline         cancel_by_buyer
              |                       |
        +-----v---+            +------v-----+
        | EXPIRED |            | CANCELLED  |
        +---------+            +------------+
```

### State Descriptions

| State | Description |
|---|---|
| `DRAFT` | Buyer has started filling in a request but has not submitted it. Not visible to other participants. |
| `ACTIVE` | Request is submitted and visible. Eligible for demand-group matching. |
| `GROUPED` | Request has been matched into a demand group with compatible requests. |
| `SOURCING` | Procurement has started: RFQ published and/or procurement case opened. |
| `OFFER_PENDING` | A negotiated offer has been created internally but not yet published to buyers. |
| `OFFER_AVAILABLE` | A final offer is published and available for the buyer to accept. |
| `EXPIRED` | The request or its associated offer passed its deadline without buyer action. |
| `CANCELLED` | Buyer or system cancelled the request. |

### Transition Table

| # | From | To | Trigger | Guard Conditions | Side Effects (Events) | Authorized Role |
|---|---|---|---|---|---|---|
| 1 | `DRAFT` | `ACTIVE` | `submit_request` | All required fields valid; buyer OTP-verified; region supported | `BuyRequestSubmitted`; notify matching engine | Buyer |
| 2 | `ACTIVE` | `GROUPED` | `match_to_group` | Compatible demand group exists (same variant/region/window); group still `FORMING` | `BuyRequestGrouped`; increment group `interested_qty` | System (matching engine) |
| 3 | `ACTIVE` | `CANCELLED` | `cancel_by_buyer` | No binding acceptance or payment exists | `BuyRequestCancelled` | Buyer |
| 4 | `ACTIVE` | `EXPIRED` | `expire_deadline` | `purchase_by` deadline passed; no group match | `BuyRequestExpired` | System (scheduler) |
| 5 | `GROUPED` | `SOURCING` | `sourcing_started` | Group status >= `QUALIFIED`; procurement case created | `SourcingStarted` | Procurement Agent |
| 6 | `GROUPED` | `ACTIVE` | `ungroup` | Group dissolved or request removed from group | `BuyRequestUngrouped`; decrement group counts | System / Procurement Agent |
| 7 | `GROUPED` | `CANCELLED` | `cancel_by_buyer` | No offer accepted; no payment | `BuyRequestCancelled`; decrement group counts | Buyer |
| 8 | `SOURCING` | `OFFER_PENDING` | `offer_created` | Negotiated offer drafted for this group | `OfferDraftedForRequest` | Procurement Lead |
| 9 | `SOURCING` | `CANCELLED` | `cancel_no_supplier` | No viable supplier found; procurement lead approves cancellation | `BuyRequestCancelledNoSupply`; notify buyer | Procurement Lead |
| 10 | `OFFER_PENDING` | `OFFER_AVAILABLE` | `offer_published` | Offer approved and published (see Negotiated Offer machine) | `OfferAvailableForBuyer`; send notification | Procurement Lead |
| 11 | `OFFER_AVAILABLE` | `EXPIRED` | `expire_deadline` | Offer `expires_at` passed; buyer did not accept | `OfferExpiredForRequest` | System (scheduler) |
| 12 | `OFFER_AVAILABLE` | `CANCELLED` | `cancel_by_buyer` | Buyer explicitly declines offer; no payment captured | `BuyRequestDeclined` | Buyer |

### Error / Exception States

- **Validation failure on submit:** remains `DRAFT`; return field-level errors.
- **Group match conflict (concurrent):** retry with optimistic lock; if group filled, remain `ACTIVE`.
- **Offer creation fails approval:** remains `SOURCING`; procurement can retry or cancel.

---

## 2. Demand Group

### ASCII State Diagram

```
              +---------+
              | FORMING |
              +----+----+
                   |
              qualify_group
                   |
             +-----v------+
             | QUALIFIED  |
             +-----+------+
                   |
              open_rfq
                   |
            +------v---+
            | RFQ_OPEN |
            +------+---+
                   |
            start_negotiation
                   |
          +--------v------+
          | NEGOTIATING   |
          +--------+------+
                   |
            offer_finalized
                   |
          +--------v------+
          | OFFER_READY   |
          +--------+------+
                   |
            offer_published
                   |
    +-------------v--------------+
    | COLLECTING_PAYMENTS        |
    +------+---------------+-----+
           |               |
     paid_qty >= MOQ   deadline_passed
           |           AND paid_qty < MOQ
     +-----v----+      |
     | MOQ_MET  |   +--v---------+
     +-----+----+   | MOQ_FAILED |
           |        +--+---------+
     release_orders    |
           |        extend / cancel+refund
     +-----v------+
     | FULFILLED  |
     +-----+------+
           |
      all_orders_completed
           |
     +-----v---+
     |  CLOSED |
     +---------+
```

### State Descriptions

| State | Description |
|---|---|
| `FORMING` | Requests are being matched into this group. Not yet ready for sourcing. |
| `QUALIFIED` | Minimum demand threshold met; buyer intent reconfirmed. Ready for RFQ. |
| `RFQ_OPEN` | Sealed RFQ published to eligible sellers; bids being collected. |
| `NEGOTIATING` | Bid deadline passed or sufficient bids received; procurement is negotiating. |
| `OFFER_READY` | Final negotiated offer composed internally; awaiting approval and publish. |
| `COLLECTING_PAYMENTS` | Offer published to buyers; accepting payments toward MOQ. |
| `MOQ_MET` | `paid_qty` >= MOQ. Ready to release fulfillment orders. |
| `MOQ_FAILED` | Payment deadline passed and `paid_qty` < MOQ. Extend, renegotiate or refund. |
| `FULFILLED` | Seller fulfillment orders created and in progress. |
| `CLOSED` | All orders completed, settled and disputes resolved. Terminal state. |

### Transition Table

| # | From | To | Trigger | Guard Conditions | Side Effects | Authorized Role |
|---|---|---|---|---|---|---|
| 1 | `FORMING` | `QUALIFIED` | `qualify_group` | `interested_qty` >= config threshold; >= N buyers reconfirmed intent (L2+) | `DemandGroupQualified`; create procurement case | Procurement Agent |
| 2 | `QUALIFIED` | `RFQ_OPEN` | `open_rfq` | RFQ spec approved; bid deadline set; at least one eligible seller or procurement case active | `RFQPublished`; invite sellers; notify procurement | Procurement Lead |
| 3 | `RFQ_OPEN` | `NEGOTIATING` | `start_negotiation` | Bid deadline reached OR sufficient bids received | `NegotiationStarted`; lock new bids | System (scheduler) / Procurement Lead |
| 4 | `NEGOTIATING` | `OFFER_READY` | `offer_finalized` | Supplier quote confirmed; landed price calculated; terms documented | `OfferFinalized` | Procurement Lead |
| 5 | `OFFER_READY` | `COLLECTING_PAYMENTS` | `offer_published` | Offer approved (maker-checker); expiry set; stock confirmed | `OfferPublished`; notify all group buyers; start payment deadline timer | Finance Approver + Procurement Lead |
| 6 | `COLLECTING_PAYMENTS` | `MOQ_MET` | `moq_check` | `paid_qty` >= offer MOQ (spec 6.1: only paid, unrefunded qty counts) | `MOQReached`; notify seller; prepare fulfillment | System (payment event handler) |
| 7 | `COLLECTING_PAYMENTS` | `MOQ_FAILED` | `deadline_passed` | Payment deadline expired AND `paid_qty` < MOQ | `MOQFailed`; notify buyers and seller | System (scheduler) |
| 8 | `MOQ_MET` | `FULFILLED` | `release_orders` | Seller confirms stock; fulfillment orders created | `FulfillmentOrdersReleased`; create seller_fulfillment_orders | Procurement Lead |
| 9 | `MOQ_FAILED` | `COLLECTING_PAYMENTS` | `extend_deadline` | Seller agrees to extension; buyers notified; new deadline set | `DeadlineExtended` | Procurement Lead (with seller consent) |
| 10 | `MOQ_FAILED` | `CLOSED` | `cancel_and_refund` | Refunds initiated for all paid buyers | `GroupCancelledMOQFailed`; trigger refund workflows | Finance Approver |
| 11 | `FULFILLED` | `CLOSED` | `all_orders_completed` | Every order in group is `COMPLETED`, `REFUNDED` or `CANCELLED`; all settlements reconciled | `DemandGroupClosed` | System (order event handler) |

### Error / Exception States

- **MOQ_FAILED with partial refund failure:** Group stays `MOQ_FAILED`; finance escalation event emitted; individual payment enters `REFUND_PENDING`.
- **Seller stock failure after MOQ_MET:** Group remains `MOQ_MET`; procurement re-sources or transitions to cancel+refund via `MOQ_FAILED` -> `CLOSED`.
- **Oversubscription during COLLECTING_PAYMENTS:** Atomic stock reservation check; excess checkout attempts rejected with `StockReservationFailed`.

### Business Rules

- **Only `paid_qty` counts toward MOQ** (spec 6.1). `interested_qty`, `ready_qty` and `accepted_qty` are informational.
- **No silent repricing:** once a buyer accepts and pays at a given unit price, that price is immutable for their order. If the tier changes due to volume, only new acceptances get the new tier price.

---

## 3. Seller Bid

### ASCII State Diagram

```
         +-------+
         | DRAFT |
         +---+---+
             |
         submit_bid
             |
        +----v------+
   +--->| SUBMITTED |<------+
   |    +----+------+       |
   |         |          submit_revision
   |    submit_revision     |
   |         |         +----+-----+
   |    +----v------+  |          |
   |    | REVISED   +--+          |
   |    +-----------+             |
   |         |                    |
   |    (bid deadline reached)    |
   |         |                    |
   |  +------v--------+          |
   |  | UNDER_REVIEW  |          |
   |  +--+-----+------+          |
   |     |     |                  |
   |  shortlist  reject           |
   |     |     |                  |
   |  +--v--------+ +--v-------+ |
   |  |SHORTLISTED| | REJECTED | |
   |  +--+--------+ +----------+ |
   |     |                        |
   |   award                      |
   |     |                        |
   |  +--v------+                 |
   |  | AWARDED |                 |
   |  +---------+                 |
   |
   |  --- From SUBMITTED/REVISED ---
   |
   |  expire_deadline    withdraw_by_seller
   |       |                   |
   |  +----v----+       +-----v------+
   |  | EXPIRED |       | WITHDRAWN  |
   |  +---------+       +------------+
   |
   +-- (revision creates new immutable
        seller_bid_revisions row;
        bid status returns to SUBMITTED
        with updated latest_revision_id)
```

### State Descriptions

| State | Description |
|---|---|
| `DRAFT` | Seller has started composing a bid but not submitted. |
| `SUBMITTED` | Bid submitted for the RFQ. Visible to procurement (never to other sellers or buyers). |
| `REVISED` | Seller submitted a new revision. Transitional -- immediately moves to `SUBMITTED` with new `latest_revision_id`. |
| `UNDER_REVIEW` | Bid deadline passed; procurement is evaluating this bid alongside others. |
| `SHORTLISTED` | Bid selected for final negotiation round. |
| `AWARDED` | Bid wins the deal; seller receives fulfillment instruction upon MOQ/payment confirmation. |
| `REJECTED` | Bid not selected. Seller sees only their own rejection, never competitor details. |
| `EXPIRED` | Bid validity period or RFQ deadline passed without seller action. |
| `WITHDRAWN` | Seller voluntarily withdrew the bid before award. |

### Transition Table

| # | From | To | Trigger | Guard Conditions | Side Effects | Authorized Role |
|---|---|---|---|---|---|---|
| 1 | `DRAFT` | `SUBMITTED` | `submit_bid` | All required fields present; seller verified; RFQ still open; seller invited/eligible | `BidSubmitted`; create `seller_bid_revisions` row (rev 1) | Seller |
| 2 | `SUBMITTED` | `SUBMITTED` | `submit_revision` | RFQ bid deadline not passed; new revision appended (immutable) | `BidRevised`; append `seller_bid_revisions` row; update `latest_revision_id` | Seller |
| 3 | `SUBMITTED` | `UNDER_REVIEW` | `deadline_reached` | RFQ bid deadline passed | `BidUnderReview` | System (scheduler) |
| 4 | `SUBMITTED` | `EXPIRED` | `expire_validity` | Bid's `valid_until` passed before review | `BidExpired` | System (scheduler) |
| 5 | `SUBMITTED` | `WITHDRAWN` | `withdraw_by_seller` | No award issued; RFQ not in final negotiation with this seller | `BidWithdrawn`; notify procurement | Seller |
| 6 | `UNDER_REVIEW` | `SHORTLISTED` | `shortlist` | Procurement scores bid favorably; meets quality/price thresholds | `BidShortlisted`; notify seller of negotiation invite | Procurement Agent |
| 7 | `UNDER_REVIEW` | `REJECTED` | `reject` | Bid does not meet requirements or better alternatives exist | `BidRejected`; notify seller (no competitor details) | Procurement Lead |
| 8 | `SHORTLISTED` | `AWARDED` | `award` | Final terms agreed; supplier confirmed quote; maker-checker approval for high-value | `BidAwarded`; create/link negotiated offer; notify seller | Procurement Lead + Finance Approver (if above threshold) |
| 9 | `SHORTLISTED` | `REJECTED` | `reject` | Another bid awarded or deal cancelled | `BidRejected` | Procurement Lead |
| 10 | `SHORTLISTED` | `WITHDRAWN` | `withdraw_by_seller` | Seller withdraws during negotiation | `BidWithdrawn`; flag supplier performance | Seller |

### Business Rules -- Immutable Bid Revisions

- Every revision creates a new `seller_bid_revisions` row with incrementing `revision_no`. Previous rows are **never modified or deleted**.
- `seller_bids.latest_revision_id` points to the most recent revision.
- All revision history is available to procurement but **never to other sellers**.
- Seller **never sees**: other bid values, ranking, number of competing bids, buyer maximum budgets, or procurement negotiation notes with other suppliers.

### Error / Exception States

- **Duplicate submission:** Unique constraint on `(rfq_id, seller_id)` prevents multiple bids; seller must revise instead.
- **Late submission:** Rejected at API level if `bid_deadline` passed.
- **Concurrent revision race:** Optimistic lock on `latest_revision_id`; losing write retries.

---

## 4. Negotiated Offer

### ASCII State Diagram

```
         +-------+
         | DRAFT |
         +---+---+
             |
         submit_for_review
             |
     +-------v---------+
     | REVIEW_PENDING   |
     +--+----------+---+
        |          |
     approve     reject
        |          |
   +----v-----+   |   (back to DRAFT
   | APPROVED |   |    for revision)
   +----+-----+   |
        |     +----v---+
     publish  | DRAFT  |
        |     +--------+
   +----v------+
   | PUBLISHED |
   +----+------+
        |
     buyer_accepts_first
        |
   +----v------+
   | ACCEPTING |
   +----+------+
        |
   +----+----------+
   |                |
 paid_qty>=MOQ   deadline_passed
   |             AND paid_qty<MOQ
   |                |
+--v-----------+ +--v--------------+
|THRESHOLD_MET | |THRESHOLD_FAILED |
+--------------+ +-----------------+

   --- From PUBLISHED / ACCEPTING ---

   expire_deadline       cancel_by_admin
        |                      |
   +----v----+          +------v-----+
   | EXPIRED |          | CANCELLED  |
   +---------+          +------------+
```

### State Descriptions

| State | Description |
|---|---|
| `DRAFT` | Offer being composed by procurement. Not visible to buyers. |
| `REVIEW_PENDING` | Submitted for internal approval (maker-checker). |
| `APPROVED` | Approved by designated approver. Ready to publish. |
| `PUBLISHED` | Visible to group buyers. Acceptance window open. |
| `ACCEPTING` | At least one buyer has accepted; collecting payments toward threshold. |
| `THRESHOLD_MET` | `paid_qty` >= MOQ. Fulfillment can proceed. |
| `THRESHOLD_FAILED` | Payment deadline passed; insufficient paid quantity. |
| `EXPIRED` | Offer validity expired without sufficient acceptance. |
| `CANCELLED` | Offer withdrawn by admin/procurement before or during acceptance. |

### Approval Workflow (Maker-Checker)

The offer approval enforces separation of duties:

1. **Maker:** Procurement Agent or Procurement Lead composes the offer and submits for review.
2. **Checker:** A different Procurement Lead or Finance Approver reviews and approves.
3. **Rule:** The submitter cannot be the approver (`offer_approvals.approver_id != negotiated_offers.created_by`).
4. **Threshold:** Offers above a configurable GMV threshold require Finance Approver sign-off in addition to Procurement Lead.
5. **Rejection:** Approver can reject with reason; offer returns to `DRAFT` for revision. Rejection is logged in `offer_approvals`.

### Transition Table

| # | From | To | Trigger | Guard Conditions | Side Effects | Authorized Role |
|---|---|---|---|---|---|---|
| 1 | `DRAFT` | `REVIEW_PENDING` | `submit_for_review` | All offer fields valid; supplier quote confirmed and not expired; price tiers defined; terms snapshot locked | `OfferSubmittedForReview`; create `offer_approvals` record (pending) | Procurement Agent / Procurement Lead |
| 2 | `REVIEW_PENDING` | `APPROVED` | `approve` | Approver != creator; approver has authority for this GMV level; supplier quote still valid | `OfferApproved`; log approval decision | Procurement Lead / Finance Approver |
| 3 | `REVIEW_PENDING` | `DRAFT` | `reject` | Approver provides rejection reason | `OfferRejected`; log reason; notify maker | Procurement Lead / Finance Approver |
| 4 | `APPROVED` | `PUBLISHED` | `publish` | Expiry datetime set; stock reconfirmed; buyer notification prepared | `OfferPublished`; notify group buyers; start acceptance timer; update buy_request states | Procurement Lead |
| 5 | `PUBLISHED` | `ACCEPTING` | `buyer_accepts_first` | At least one buyer explicitly accepts terms and initiates payment | `FirstAcceptanceReceived` | System (acceptance event handler) |
| 6 | `PUBLISHED` | `EXPIRED` | `expire_deadline` | `expires_at` passed; zero acceptances | `OfferExpired`; notify buyers; update buy_request states | System (scheduler) |
| 7 | `PUBLISHED` | `CANCELLED` | `cancel_by_admin` | Procurement or admin cancels (stock issue, supplier withdrawal, compliance) | `OfferCancelled`; notify buyers; refund any payments | Procurement Lead / Admin |
| 8 | `ACCEPTING` | `THRESHOLD_MET` | `moq_check` | `paid_qty` >= offer MOQ (only paid, unrefunded counts) | `ThresholdMet`; notify seller; trigger demand group `MOQ_MET` | System (payment event handler) |
| 9 | `ACCEPTING` | `THRESHOLD_FAILED` | `deadline_passed` | Payment deadline expired AND `paid_qty` < MOQ | `ThresholdFailed`; initiate refunds; trigger demand group `MOQ_FAILED` | System (scheduler) |
| 10 | `ACCEPTING` | `CANCELLED` | `cancel_by_admin` | Critical issue discovered | `OfferCancelled`; refund all paid buyers | Admin |
| 11 | `THRESHOLD_FAILED` | `ACCEPTING` | `extend_deadline` | Seller agrees; new deadline set; buyers notified | `DeadlineExtended` | Procurement Lead |

### Business Rules

- **No silent repricing:** Once `terms_snapshot_json` is locked at `PUBLISHED`, unit prices in that snapshot are immutable. Any price change requires a new offer version with fresh buyer consent.
- **Optimistic version check:** `negotiated_offers.version` is incremented on every state change; concurrent updates fail and retry.
- **Idempotent acceptance:** Duplicate `accept` calls for the same buyer+offer are deduplicated via `offer_acceptances` unique constraint.

### Error / Exception States

- **Supplier quote expires before publish:** Transition to `PUBLISHED` blocked; must renegotiate and re-approve.
- **Stock exhausted during ACCEPTING:** Reject new checkouts with `StockReservationFailed`; existing paid orders honored.
- **Double approval attempt:** Idempotent; second approval is a no-op if already `APPROVED`.

---

## 5. Order

### ASCII State Diagram

```
  +--------+
  |CREATED |
  +---+----+
      |
  initiate_payment
      |
  +---v-----------+
  |PAYMENT_PENDING|
  +---+-----------+
      |
  payment_confirmed
      |
  +---v--+     payment_failed     +-----------+
  | PAID |----------------------->| CANCELLED |
  +---+--+                        +-----------+
      |
  release_to_seller
      |
  +---v--------------+
  |RELEASED_TO_SELLER|
  +---+--------------+
      |
  seller_confirms
      |
  +---v-----------+
  |IN_FULFILLMENT |
  +---+-----------+
      |
  ship
      |
  +---v-----+
  | SHIPPED |
  +---+-----+
      |
  delivery_confirmed
      |
  +---v-------+
  | DELIVERED |
  +---+-------+
      |
  +---+----------+-----------+
  |              |            |
 accept    partial_accept  report_issue
  |              |            |
+-v------+ +----v-----------+ +--v-------+
|ACCEPTED| |PARTIALLY_      | |DISPUTED  |
+--+-----+ |ACCEPTED        | +--+---+---+
   |       +---+-------------+    |   |
   |           |             resolved |
   |           |               |   appeal/
   |      +-+--v---+     +----v--+ reopen
   |      |DISPUTED|     |      | |
   |      +---+----+  +--v------+-v--+
   |          |        | COMPLETED   |
   |     resolved      +------+------+
   |          |               |
   |     +----v------+   (if refund
   |     |COMPLETED  |    required)
   |     +-----------+        |
   |                    +-----v----+
   +------------------->|REFUNDED  |
                        +----------+

  --- From CREATED / PAYMENT_PENDING ---

  cancel_by_buyer / cancel_by_system
              |
        +-----v------+
        | CANCELLED  |
        +------------+
```

### State Descriptions

| State | Description |
|---|---|
| `CREATED` | Order record created after buyer accepts offer; awaiting payment initiation. |
| `PAYMENT_PENDING` | Payment intent created; buyer directed to gateway. |
| `PAID` | Payment captured/confirmed by gateway webhook. Funds with provider. |
| `RELEASED_TO_SELLER` | MOQ met; fulfillment order sent to seller. L5 commitment. |
| `IN_FULFILLMENT` | Seller confirmed and is preparing the order. |
| `SHIPPED` | Goods dispatched; tracking info available. |
| `DELIVERED` | Delivery confirmed (courier POD or buyer-reported). |
| `ACCEPTED` | Buyer accepted full delivery -- item count, model, condition verified. |
| `PARTIALLY_ACCEPTED` | Buyer accepted some items; raised issues on others. |
| `DISPUTED` | Buyer or system raised a dispute on the order. |
| `COMPLETED` | All items accepted/resolved; settlement eligible. Terminal. |
| `REFUNDED` | Full refund processed. Terminal. |
| `CANCELLED` | Order cancelled before fulfillment. Terminal. |

### Transition Table

| # | From | To | Trigger | Guard Conditions | Side Effects | Authorized Role |
|---|---|---|---|---|---|---|
| 1 | `CREATED` | `PAYMENT_PENDING` | `initiate_payment` | Offer not expired; stock reserved; idempotency key set | `PaymentIntentCreated`; create `payment_intents` row | Buyer (via checkout) |
| 2 | `CREATED` | `CANCELLED` | `cancel_by_buyer` | No payment captured | `OrderCancelled`; release stock reservation | Buyer |
| 3 | `PAYMENT_PENDING` | `PAID` | `payment_confirmed` | Gateway webhook confirms capture; signature valid; idempotent check | `OrderPaid`; increment group `paid_qty`; log ledger entry | System (webhook handler) |
| 4 | `PAYMENT_PENDING` | `CANCELLED` | `payment_failed` | Gateway reports terminal failure or checkout deadline passed | `OrderPaymentFailed`; release stock reservation | System (webhook/scheduler) |
| 5 | `PAID` | `RELEASED_TO_SELLER` | `release_to_seller` | Demand group `MOQ_MET`; fulfillment order approved | `OrderReleasedToSeller`; create `seller_fulfillment_orders`; buyer reaches L5 | Procurement Lead |
| 6 | `PAID` | `CANCELLED` | `cancel_moq_failed` | Demand group `MOQ_FAILED` and no extension | `OrderCancelledMOQFailed`; trigger refund | System (group event handler) |
| 7 | `RELEASED_TO_SELLER` | `IN_FULFILLMENT` | `seller_confirms` | Seller acknowledges fulfillment order | `SellerConfirmedOrder` | Seller |
| 8 | `IN_FULFILLMENT` | `SHIPPED` | `ship` | Tracking ref provided; dispatch evidence uploaded | `OrderShipped`; create `shipments` row; notify buyer | Seller |
| 9 | `SHIPPED` | `DELIVERED` | `delivery_confirmed` | Courier POD or buyer-reported delivery | `OrderDelivered`; start acknowledgement window | System / Operations Agent |
| 10 | `DELIVERED` | `ACCEPTED` | `accept` | Buyer confirms full receipt within acknowledgement window | `OrderAccepted`; mark settlement eligible | Buyer |
| 11 | `DELIVERED` | `PARTIALLY_ACCEPTED` | `partial_accept` | Buyer accepts some items, reports issues on others | `OrderPartiallyAccepted`; split settlement eligibility | Buyer |
| 12 | `DELIVERED` | `DISPUTED` | `report_issue` | Buyer reports damage/wrong/missing within dispute window | `DisputeOpened`; hold settlement for disputed items | Buyer |
| 13 | `ACCEPTED` | `COMPLETED` | `complete` | Settlement processed or eligible; no open disputes | `OrderCompleted` | System |
| 14 | `PARTIALLY_ACCEPTED` | `DISPUTED` | `dispute_remaining` | Disputed items enter dispute workflow | `DisputeOpenedPartial` | Buyer / Support |
| 15 | `PARTIALLY_ACCEPTED` | `COMPLETED` | `resolve_all` | Accepted items settled; disputed items resolved | `OrderCompleted` | System |
| 16 | `DISPUTED` | `COMPLETED` | `dispute_resolved` | Dispute closed with resolution applied | `OrderCompleted`; apply refund/credit if any | Support / System |
| 17 | `DISPUTED` | `REFUNDED` | `full_refund` | Dispute resolution = full refund | `OrderRefunded`; trigger refund workflow | Finance Approver |
| 18 | `ACCEPTED` | `REFUNDED` | `full_refund` | Post-acceptance issue (e.g., warranty, recall) | `OrderRefunded` | Finance Approver |
| 19 | `PAID` | `REFUNDED` | `cancel_and_refund` | Pre-fulfillment cancellation with refund | `OrderRefunded` | Finance Approver / System |

### Error / Exception States

- **Payment webhook replay:** Idempotent handler; duplicate `payment_confirmed` for already-`PAID` order is a no-op.
- **Seller non-response after release:** Escalation timer; if seller does not confirm within SLA, procurement re-sources or cancels.
- **Delivery timeout:** If no POD within SLA, Operations Agent investigates; order stays `SHIPPED`.

---

## 6. Payment

### ASCII State Diagram

```
  +--------+
  |CREATED |
  +---+----+
      |
  checkout_initiated
      |
  +---v--------------+
  |CHECKOUT_PENDING   |
  +---+---+---+------+
      |   |   |
      |   | expire      +--------+
      |   +------------->|EXPIRED |
      |   |              +--------+
      |  fail
      |   |    +---------+
      |   +--->| FAILED  |
      |        +---------+
  authorize (if gateway supports)
      |
  +---v---------+
  |AUTHORIZED   |   (optional; not all
  +---+---------+    gateways expose this)
      |
  capture
      |
  +---v-----+
  |CAPTURED  |
  +---+------+
      |
  allocate_to_order
      |
  +---v-------+
  |ALLOCATED  |
  +---+---+---+
      |   |
      |  initiate_refund
      |   |
      | +-v--------------+       +---------+
      | |REFUND_PENDING   |------>|REFUNDED |
      | +-----------------+       +---------+
      |
  request_settlement
      |
  +---v-----------------+       +-------------------+
  |SETTLEMENT_PENDING   |------>|SETTLED            |
  +---------------------+       +-------------------+

  --- Exception states (from any active state) ---

  +----------+   +-----------+   +------------+
  |DUPLICATE |   |CHARGEBACK |   | FAILED     |
  +----------+   +-----------+   +------------+
```

### State Descriptions

| State | Description |
|---|---|
| `CREATED` | Payment intent record created; amount and idempotency key set. |
| `CHECKOUT_PENDING` | Buyer redirected to payment gateway. |
| `AUTHORIZED` | Gateway pre-authorized funds (optional step; gateway-dependent). |
| `CAPTURED` | Funds captured by gateway. Payment is confirmed. |
| `ALLOCATED` | Payment mapped to specific order/order items in internal ledger. |
| `REFUND_PENDING` | Refund initiated with gateway; awaiting confirmation. |
| `REFUNDED` | Gateway confirms refund processed. Terminal (for refund path). |
| `SETTLEMENT_PENDING` | Funds eligible for seller settlement; awaiting provider transfer. |
| `SETTLED` | Provider confirms funds transferred to seller. Terminal. |
| `FAILED` | Gateway reports payment failure (insufficient funds, declined, error). |
| `EXPIRED` | Checkout session timed out without completion. |
| `DUPLICATE` | Detected as duplicate payment via idempotency key. Auto-reversed. |
| `CHARGEBACK` | Buyer's bank initiated a chargeback. |

### Transition Table

| # | From | To | Trigger | Guard Conditions | Side Effects | Authorized Role |
|---|---|---|---|---|---|---|
| 1 | `CREATED` | `CHECKOUT_PENDING` | `checkout_initiated` | Idempotency key unique; amount > 0; order exists | `PaymentCheckoutStarted` | Buyer (via checkout flow) |
| 2 | `CHECKOUT_PENDING` | `AUTHORIZED` | `authorize` | Gateway webhook: authorization success; signature valid | `PaymentAuthorized` | System (webhook) |
| 3 | `CHECKOUT_PENDING` | `CAPTURED` | `capture` | Gateway webhook: direct capture (no separate auth); signature valid | `PaymentCaptured`; update order to `PAID` | System (webhook) |
| 4 | `CHECKOUT_PENDING` | `FAILED` | `fail` | Gateway webhook: payment declined/error | `PaymentFailed`; notify buyer; allow retry | System (webhook) |
| 5 | `CHECKOUT_PENDING` | `EXPIRED` | `expire` | Checkout session TTL passed | `PaymentExpired`; release stock reservation | System (scheduler) |
| 6 | `AUTHORIZED` | `CAPTURED` | `capture` | Gateway capture confirmed | `PaymentCaptured`; update order to `PAID` | System (webhook) |
| 7 | `CAPTURED` | `ALLOCATED` | `allocate_to_order` | Ledger posting created; order items matched | `PaymentAllocated`; credit buyer ledger account | System (payment processor) |
| 8 | `ALLOCATED` | `REFUND_PENDING` | `initiate_refund` | Refund approved (maker-checker for amount > threshold); refund record created | `RefundInitiated`; call gateway refund API | Finance Operator + Finance Approver |
| 9 | `REFUND_PENDING` | `REFUNDED` | `refund_confirmed` | Gateway webhook confirms refund | `PaymentRefunded`; update order; debit ledger | System (webhook) |
| 10 | `ALLOCATED` | `SETTLEMENT_PENDING` | `request_settlement` | Settlement approved (see Settlement machine); provider release requested | `SettlementRequested` | System (settlement workflow) |
| 11 | `SETTLEMENT_PENDING` | `SETTLED` | `settlement_confirmed` | Provider webhook/report confirms payout | `PaymentSettled`; reconcile ledger | System (webhook/reconciliation) |
| 12 | `CAPTURED` | `REFUND_PENDING` | `initiate_refund` | Pre-allocation refund (e.g., MOQ failed) | `RefundInitiated` | Finance Approver |
| 13 | Any active | `DUPLICATE` | `detect_duplicate` | Idempotency key collision; second payment for same logical operation | `DuplicateDetected`; auto-reverse/refund | System |
| 14 | `SETTLED` | `CHARGEBACK` | `chargeback_received` | Bank/gateway notifies chargeback | `ChargebackReceived`; freeze related settlement; open dispute | System (webhook) |
| 15 | `CAPTURED` | `CHARGEBACK` | `chargeback_received` | Pre-settlement chargeback | `ChargebackReceived`; open dispute | System (webhook) |

### Business Rules

- **Webhook idempotency:** `payment_webhook_events.provider_event_id` has a UNIQUE constraint. Duplicate webhooks are logged but not reprocessed. All handlers are idempotent and replay-safe (spec 10.2).
- **Maker-checker for refunds:** Refunds above a configurable threshold require both a Finance Operator (initiator) and a Finance Approver (authorizer). The same person cannot fill both roles.
- **Integer paise:** All amounts stored as integer paise with currency `INR`. No floating-point money arithmetic.
- **No raw card data:** Platform stores only gateway transaction IDs and references, never PAN/CVV/tokens requiring PCI scope.

### Error / Exception States

- **Webhook signature invalid:** Event logged with `signature_valid = false`; not processed; alert raised.
- **Refund fails at gateway:** Payment stays `REFUND_PENDING`; retry with backoff; escalate to Finance after N failures.
- **Partial refund:** Creates a new `refunds` row for the partial amount; payment may stay `ALLOCATED` with reduced eligible amount.

---

## 7. Settlement

### ASCII State Diagram

```
  +--------------+
  |NOT_ELIGIBLE  |
  +------+-------+
         |
    delivery_verified AND
    acceptance_received
         |
  +------v------------------+
  |AWAITING_VERIFICATION    |
  +------+------------------+
         |
    verification_complete
         |
  +------v-----------------+
  |APPROVAL_PENDING        |
  +------+---------+-------+
         |         |
      approve    hold
         |         |
  +------v---+ +--v------+
  |APPROVED  | |ON_HOLD  |
  +------+---+ +---------+
         |         |
  release_funds  resolve_hold
         |         |
         |    +----v-----------+
         |    |APPROVAL_PENDING|
         |    +----------------+
         |
  +------v-----------------+
  |RELEASE_REQUESTED       |
  +------+--------+--------+
         |        |
   provider_ok  provider_fail
         |        |
  +------v-----+ +v--------------+
  |PROVIDER_   | |RELEASE_FAILED |
  |CONFIRMED_  | +--+------------+
  |SETTLED     |    |
  +------------+  retry / escalate
                    |
               +----v-----------+
               |RELEASE_REQUESTED|
               +----------------+

  --- Exception states ---

  +-----------+   +-------------------+   +-------------------------+
  | DISPUTED  |   |PARTIALLY_ELIGIBLE |   |RECONCILIATION_MISMATCH  |
  +-----------+   +-------------------+   +-------------------------+
```

### State Descriptions

| State | Description |
|---|---|
| `NOT_ELIGIBLE` | Order exists but settlement conditions not met (not delivered, not accepted, dispute open). |
| `AWAITING_VERIFICATION` | Delivery verified and buyer acknowledged; finance verifying settlement eligibility. |
| `APPROVAL_PENDING` | Verification complete; awaiting maker-checker approval for fund release. |
| `APPROVED` | Approved for release by authorized finance approver. |
| `RELEASE_REQUESTED` | Release instruction sent to payment provider. |
| `PROVIDER_CONFIRMED_SETTLED` | Provider confirms funds transferred to seller's bank. Terminal. |
| `DISPUTED` | Active dispute blocks settlement. Held until resolution. |
| `ON_HOLD` | Settlement paused for investigation (quality issue, compliance, chargeback). |
| `PARTIALLY_ELIGIBLE` | Some order items eligible for settlement; others held (partial acceptance). |
| `RELEASE_FAILED` | Provider rejected or failed the release attempt. |
| `RECONCILIATION_MISMATCH` | Discrepancy between platform ledger and provider settlement report. |

### Transition Table

| # | From | To | Trigger | Guard Conditions | Side Effects | Authorized Role |
|---|---|---|---|---|---|---|
| 1 | `NOT_ELIGIBLE` | `AWAITING_VERIFICATION` | `eligibility_met` | Delivery verified; buyer accepted (full or partial for eligible items); no blocking dispute; acknowledgement window closed or accepted | `SettlementEligible`; create `seller_settlements` row | System (order event handler) |
| 2 | `NOT_ELIGIBLE` | `DISPUTED` | `dispute_opened` | Dispute raised on the order | `SettlementBlocked` | System (dispute event) |
| 3 | `AWAITING_VERIFICATION` | `APPROVAL_PENDING` | `verification_complete` | Finance operator verifies: amounts match ledger, no fraud flags, seller bank verified, cooling-off passed | `SettlementVerified` | Finance Operator |
| 4 | `APPROVAL_PENDING` | `APPROVED` | `approve` | Approver != verifier (maker-checker); amount within provider limits; seller KYC current | `SettlementApproved`; log `settlement_approvals` | Finance Approver |
| 5 | `APPROVAL_PENDING` | `ON_HOLD` | `hold` | Suspicious activity, chargeback received, or compliance review | `SettlementOnHold`; log reason, actor, review deadline | Finance Approver / Compliance |
| 6 | `ON_HOLD` | `APPROVAL_PENDING` | `resolve_hold` | Hold reason resolved; evidence reviewed | `HoldResolved`; re-enter approval queue | Finance Approver / Compliance |
| 7 | `ON_HOLD` | `DISPUTED` | `escalate_to_dispute` | Hold investigation reveals dispute-worthy issue | `SettlementDisputed` | Finance Approver |
| 8 | `APPROVED` | `RELEASE_REQUESTED` | `release_funds` | Provider API called with settlement instruction | `ReleaseRequested`; call provider payout API | System (settlement processor) |
| 9 | `RELEASE_REQUESTED` | `PROVIDER_CONFIRMED_SETTLED` | `provider_confirmed` | Provider webhook/report confirms payout to seller bank | `SettlementConfirmed`; reconcile ledger; notify seller | System (webhook/reconciliation) |
| 10 | `RELEASE_REQUESTED` | `RELEASE_FAILED` | `provider_failed` | Provider reports failure (bank details wrong, limit, downtime) | `ReleaseFailed`; alert finance team | System (webhook) |
| 11 | `RELEASE_FAILED` | `RELEASE_REQUESTED` | `retry_release` | Issue resolved (bank details corrected, limit cleared) | `ReleaseRetried` | Finance Operator |
| 12 | `RELEASE_FAILED` | `ON_HOLD` | `escalate` | Multiple failures; needs manual investigation | `SettlementOnHold` | Finance Operator |
| 13 | `DISPUTED` | `AWAITING_VERIFICATION` | `dispute_resolved_in_seller_favor` | Dispute closed; settlement should proceed | `DisputeResolvedForSettlement` | System (dispute event) |
| 14 | `DISPUTED` | `NOT_ELIGIBLE` | `dispute_resolved_refund` | Dispute closed with full buyer refund; settlement cancelled | `SettlementCancelledRefund` | System (dispute event) |
| 15 | `NOT_ELIGIBLE` | `PARTIALLY_ELIGIBLE` | `partial_acceptance` | Some items accepted, others disputed/returned | `PartialSettlementEligible`; calculate eligible amount | System (order event handler) |
| 16 | `PARTIALLY_ELIGIBLE` | `AWAITING_VERIFICATION` | `eligible_items_verified` | Eligible portion verified; disputed items handled separately | `PartialSettlementVerified` | Finance Operator |
| 17 | Any post-release | `RECONCILIATION_MISMATCH` | `reconciliation_exception` | Daily reconciliation finds discrepancy between ledger and provider report | `ReconciliationMismatch`; create `reconciliation_exceptions` row | System (reconciliation job) |
| 18 | `RECONCILIATION_MISMATCH` | `PROVIDER_CONFIRMED_SETTLED` | `mismatch_resolved` | Manual investigation resolves discrepancy; ledger corrected | `MismatchResolved` | Finance Operator + Finance Approver |

### Business Rules

- **Maker-checker:** Every settlement approval requires two distinct actors -- a Finance Operator (verifier/maker) and a Finance Approver (checker). No person may approve their own settlement.
- **Every hold requires:** reason, actor ID, timestamp, review deadline, and legal/provider basis (spec 10.3).
- **UI approval is not bank payout:** The `APPROVED` state means internally approved. Actual money movement only confirmed at `PROVIDER_CONFIRMED_SETTLED`.
- **Provider-dependent holds:** `ON_HOLD` is used only if the payment provider supports hold/release mechanics. Otherwise, the platform delays calling the release API.
- **Daily reconciliation:** `reconciliation_runs` compare orders, payments, refunds, provider settlements, seller payable, fees/taxes and bank reports.

---

## 8. Fulfillment / Delivery

### ASCII State Diagram

```
  +-----------------+
  |READY_TO_DISPATCH|
  +--------+--------+
           |
      dispatch
           |
  +--------v--------+
  |   DISPATCHED    |
  +--------+--------+
           |
      carrier_scan
           |
  +--------v--------+
  |   IN_TRANSIT    |
  +--------+--------+
           |
      delivery_reported
           |
  +--------v-----------+
  |DELIVERED_REPORTED  |
  +--------+-----------+
           |
      verify_delivery
           |
  +--------v-----------+
  |DELIVERY_VERIFIED   |
  +--------+-----------+
           |
  +--------+--------+--------+
  |                 |        |
 accept       partial    report_issue
  |            accept         |
+-v-----------+ |    +--------v--------+
|BUYER_       | |    |ISSUE_REPORTED   |
|ACCEPTED     | |    +-----------------+
+-------------+ |
          +-----v-----------+
          |PARTIALLY_       |
          |ACCEPTED         |
          +-----------------+
```

### State Descriptions

| State | Description |
|---|---|
| `READY_TO_DISPATCH` | Seller has fulfillment order; packing in progress. |
| `DISPATCHED` | Goods handed to carrier; tracking reference available. |
| `IN_TRANSIT` | Carrier scan confirms package in logistics network. |
| `DELIVERED_REPORTED` | Carrier reports delivery (POD: timestamp, recipient, OTP/signature). |
| `DELIVERY_VERIFIED` | Platform verified delivery evidence (courier status + optional inspection). |
| `BUYER_ACCEPTED` | Buyer confirmed full receipt: item count, model, visible condition OK. |
| `PARTIALLY_ACCEPTED` | Buyer accepted some items; reported issues with others. |
| `ISSUE_REPORTED` | Buyer reported damage, wrong item, missing units, counterfeit, or non-delivery. |

### Transition Table

| # | From | To | Trigger | Guard Conditions | Side Effects | Authorized Role |
|---|---|---|---|---|---|---|
| 1 | `READY_TO_DISPATCH` | `DISPATCHED` | `dispatch` | Carrier assigned; tracking ref provided; dispatch evidence uploaded | `ShipmentDispatched`; create `shipments` row; notify buyer | Seller |
| 2 | `DISPATCHED` | `IN_TRANSIT` | `carrier_scan` | Carrier API/webhook reports in-transit scan | `ShipmentInTransit` | System (carrier webhook) |
| 3 | `IN_TRANSIT` | `DELIVERED_REPORTED` | `delivery_reported` | Carrier reports delivery with POD (timestamp, signature/OTP) | `DeliveryReported`; start verification; notify buyer | System (carrier webhook) |
| 4 | `DELIVERED_REPORTED` | `DELIVERY_VERIFIED` | `verify_delivery` | POD evidence reviewed; optionally inspection completed per tier | `DeliveryVerified`; start buyer acknowledgement window timer | Operations Agent / System |
| 5 | `DELIVERY_VERIFIED` | `BUYER_ACCEPTED` | `accept` | Buyer confirms full acceptance within acknowledgement window | `BuyerAccepted`; mark order `ACCEPTED`; mark settlement `AWAITING_VERIFICATION` | Buyer |
| 6 | `DELIVERY_VERIFIED` | `PARTIALLY_ACCEPTED` | `partial_accept` | Buyer accepts some items; specifies accepted/disputed quantities via `buyer_acknowledgements` | `BuyerPartiallyAccepted`; split settlement eligibility; open dispute for rejected items | Buyer |
| 7 | `DELIVERY_VERIFIED` | `ISSUE_REPORTED` | `report_issue` | Buyer reports issue with evidence (photos, description) within dispute window | `IssueReported`; hold settlement; open dispute | Buyer |
| 8 | `DELIVERY_VERIFIED` | `BUYER_ACCEPTED` | `auto_accept` | Acknowledgement window expired; buyer silent; deemed acceptance per policy (must be legally reviewed) | `DeemedAccepted`; mark settlement eligible; preserve buyer statutory rights | System (scheduler) |

### Evidence Requirements by Fulfillment Milestone

| Milestone | Required Evidence |
|---|---|
| `DISPATCHED` | Invoice, packing list, carrier AWB/tracking ref |
| `IN_TRANSIT` | Carrier scan event |
| `DELIVERED_REPORTED` | Carrier POD: timestamp, recipient name, OTP/signature where applicable |
| `DELIVERY_VERIFIED` | POD review; for Tier B/C: pre-dispatch photo, batch/serial verification, inspection report |
| `BUYER_ACCEPTED` | Buyer's explicit acknowledgement in app |

### Exception Handling

| Exception | Handling |
|---|---|
| Non-delivery (carrier shows delivered, buyer denies) | `ISSUE_REPORTED`; investigate with carrier POD; open dispute |
| Late delivery beyond SLA | Flag for compensation review; does not block acceptance |
| Buyer unavailable for delivery | Carrier reattempts per policy; if max attempts exceeded, return-to-origin; order stays `IN_TRANSIT` |
| Counterfeit suspected | Immediate `ISSUE_REPORTED`; escalate to compliance; block settlement; suspend seller listing |
| Split shipment | Each shipment has its own state machine instance; order tracks aggregate across all shipments |

---

## 9. Dispute

### ASCII State Diagram

```
  +--------+
  | OPENED |
  +---+----+
      |
  request_evidence
      |
  +---v-----------------+
  |EVIDENCE_REQUESTED   |
  +---+-----------------+
      |
  evidence_received / deadline_passed
      |
  +---v-----------------+
  |UNDER_INVESTIGATION  |
  +---+-----------------+
      |
  propose_resolution
      |
  +---v-------------------+
  |RESOLUTION_PROPOSED    |
  +---+-------+-----------+
      |       |
   accept   reject
      |       |
  +---v-----+ |
  |RESOLVED | |
  +---+-----+ |
      |       |
      |  +----v---+
      |  |APPEALED|
      |  +----+---+
      |       |
      |  reinvestigate
      |       |
      |  +----v-----------------+
      |  |UNDER_INVESTIGATION   |
      |  +----------------------+
      |
  close_dispute
      |
  +---v----+
  | CLOSED |
  +--------+
```

### State Descriptions

| State | Description |
|---|---|
| `OPENED` | Dispute created by buyer, seller or system. Assigned to support agent. |
| `EVIDENCE_REQUESTED` | Support requested evidence from one or both parties (photos, documents, tracking). |
| `UNDER_INVESTIGATION` | Evidence collected; support investigating root cause. |
| `RESOLUTION_PROPOSED` | Support proposes a resolution (refund, replacement, partial credit, rejection). |
| `RESOLVED` | Both parties accept resolution or resolution enforced per policy. |
| `APPEALED` | Losing party appeals the resolution within appeal window. |
| `CLOSED` | Resolution finalized; financial adjustments applied; no further appeals. Terminal. |

### Transition Table

| # | From | To | Trigger | Guard Conditions | Side Effects | Authorized Role |
|---|---|---|---|---|---|---|
| 1 | -- | `OPENED` | `open_dispute` | Order in eligible state (DELIVERED, ACCEPTED, PARTIALLY_ACCEPTED, or SHIPPED with non-delivery claim); within dispute window | `DisputeOpened`; assign support agent; hold settlement for disputed items; create `disputes` row | Buyer / Seller / System |
| 2 | `OPENED` | `EVIDENCE_REQUESTED` | `request_evidence` | Support agent reviews case; identifies needed evidence | `EvidenceRequested`; notify parties with deadline; create `dispute_events` | Support Agent |
| 3 | `EVIDENCE_REQUESTED` | `UNDER_INVESTIGATION` | `evidence_received` | At least one party submitted evidence OR evidence deadline passed | `InvestigationStarted` | System / Support Agent |
| 4 | `UNDER_INVESTIGATION` | `RESOLUTION_PROPOSED` | `propose_resolution` | Investigation complete; resolution documented with reason and evidence references | `ResolutionProposed`; notify both parties | Support Agent |
| 5 | `RESOLUTION_PROPOSED` | `RESOLVED` | `accept_resolution` | Affected party accepts OR acceptance deadline passes (deemed accepted per policy) | `DisputeResolved`; apply financial adjustments (maker-checker for refund/credit) | Buyer / Seller / System (auto-accept) |
| 6 | `RESOLUTION_PROPOSED` | `APPEALED` | `appeal` | Losing party appeals within appeal window; appeal reason provided | `DisputeAppealed`; escalate to senior support / disputes lead | Buyer / Seller |
| 7 | `APPEALED` | `UNDER_INVESTIGATION` | `reinvestigate` | Appeal accepted for review; new evidence or reassignment | `ReinvestigationStarted`; assign senior agent | Support Lead |
| 8 | `RESOLVED` | `CLOSED` | `close_dispute` | Financial adjustments applied; settlement updated; appeal window expired | `DisputeClosed`; unblock settlement for resolved items; archive | System / Support Agent |
| 9 | `RESOLVED` | `APPEALED` | `appeal` | Appeal within window after resolution | `DisputeAppealed` | Buyer / Seller |

### Business Rules

- **Maker-checker for financial adjustments:** Dispute resolutions involving refunds or credits above a threshold require Finance Operator (maker) + Finance Approver (checker). The support agent proposes; finance executes.
- **Evidence preservation:** All evidence (photos, documents, communication logs) stored immutably in `dispute_evidence`. Cannot be deleted by either party.
- **Settlement interaction:** When a dispute is `OPENED`, the related settlement transitions to `DISPUTED` or `ON_HOLD`. When `CLOSED` with seller-favorable outcome, settlement resumes. When `CLOSED` with buyer-favorable refund, settlement is cancelled for those items.
- **Statutory rights preserved:** Even if platform dispute deadlines expire, consumer statutory rights under Indian consumer protection law are preserved. The platform cannot waive these via policy.

### Error / Exception States

- **Duplicate dispute on same order item:** Rejected; existing dispute must be resolved first.
- **Dispute opened after settlement completed:** Triggers `CHARGEBACK` path or manual financial adjustment; settlement reversal depends on provider capability.
- **Support agent conflict of interest:** Reassign if agent has relationship to seller/buyer.

---

## 10. Buyer Intent / Commitment

### ASCII State Diagram

```
  +-----------+
  | L0_BROWSE |  (no account; no demand counted)
  +-----+-----+
        |
   otp_verified_interest
        |
  +-----v----------+
  | L1_INTERESTED  |  (soft interest; deduplicated)
  +-----+----------+
        |
   reconfirm_purchase_ready
        |
  +-----v--------------+
  | L2_PURCHASE_READY  |  (stronger intent; nonbinding)
  +-----+--------------+
        |
   accept_specific_offer
        |
  +-----v--------------+
  | L3_OFFER_ACCEPTED  |  (buyer agrees to exact terms)
  +-----+--------------+
        |
   payment_confirmed
        |
  +-----v------------------+
  | L4_PAYMENT_CONFIRMED   |  (paid; counts toward MOQ)
  +-----+------------------+
        |
   order_released
        |
  +-----v-----------------+
  | L5_ORDER_RELEASED     |  (fulfillment commitment)
  +-----------------------+
```

### Level Descriptions

| Level | Event | Payment Status | Demand Counting | Meaning |
|---|---|---|---|---|
| `L0_BROWSE` | Page view / search | None | Not counted | Anonymous or logged-out browsing. No demand signal. |
| `L1_INTERESTED` | OTP-verified interest expression | None | `interested_qty` incremented (deduplicated) | Buyer has an account and expressed interest in a product/request. Soft signal only. |
| `L2_PURCHASE_READY` | Explicit reconfirmation of purchase readiness | None | `ready_qty` incremented | Buyer reconfirms they intend to purchase when a price is available. Still nonbinding. Used to qualify demand groups. |
| `L3_OFFER_ACCEPTED` | Buyer explicitly accepts a specific final offer | As required by offer (checkout initiated) | `accepted_qty` incremented | Buyer agrees to exact price, terms, warranty, delivery. May be unpaid if payment not yet completed. |
| `L4_PAYMENT_CONFIRMED` | Gateway confirms payment capture | Captured / Paid | `paid_qty` incremented | **This is the only level that counts toward MOQ** (spec 6.1). Funds are with payment provider. |
| `L5_ORDER_RELEASED` | Order released to seller for fulfillment | Funds under approved settlement contract | Fulfillment commitment | Irrevocable commitment. Seller receives fulfillment instruction. Cancellation requires refund workflow. |

### Transition Table

| # | From | To | Trigger | Guard Conditions | Side Effects | Authorized Role |
|---|---|---|---|---|---|---|
| 1 | `L0_BROWSE` | `L1_INTERESTED` | `otp_verified_interest` | User OTP-verified; not duplicate for this group; request/group exists | `BuyerInterested`; increment `interested_qty`; create `group_memberships` row | Buyer |
| 2 | `L1_INTERESTED` | `L2_PURCHASE_READY` | `reconfirm_purchase_ready` | Buyer explicitly reconfirms; group still active; within purchase window | `BuyerPurchaseReady`; increment `ready_qty`; log `group_intent_events` | Buyer |
| 3 | `L2_PURCHASE_READY` | `L3_OFFER_ACCEPTED` | `accept_specific_offer` | Offer is `PUBLISHED` or `ACCEPTING`; offer not expired; stock available; buyer reviews and accepts exact terms | `OfferAccepted`; increment `accepted_qty`; create `offer_acceptances` row; snapshot `accepted_terms_hash` | Buyer |
| 4 | `L3_OFFER_ACCEPTED` | `L4_PAYMENT_CONFIRMED` | `payment_confirmed` | Gateway webhook confirms capture; idempotent; signature valid | `PaymentConfirmed`; increment `paid_qty`; **this count toward MOQ**; create order | System (webhook handler) |
| 5 | `L4_PAYMENT_CONFIRMED` | `L5_ORDER_RELEASED` | `order_released` | Demand group `MOQ_MET`; fulfillment order approved and created | `OrderReleased`; create `seller_fulfillment_orders`; notify seller | Procurement Lead |

### Downgrade / Withdrawal Transitions

| # | From | To | Trigger | Guard Conditions | Side Effects | Authorized Role |
|---|---|---|---|---|---|---|
| 6 | `L1_INTERESTED` | `L0_BROWSE` | `withdraw_interest` | No binding acceptance | `InterestWithdrawn`; decrement `interested_qty` | Buyer |
| 7 | `L2_PURCHASE_READY` | `L1_INTERESTED` | `downgrade_intent` | No offer accepted | `IntentDowngraded`; decrement `ready_qty` | Buyer |
| 8 | `L3_OFFER_ACCEPTED` | `L2_PURCHASE_READY` | `decline_offer` | Payment not yet captured; within cancellation window | `OfferDeclined`; decrement `accepted_qty`; release stock reservation | Buyer |
| 9 | `L4_PAYMENT_CONFIRMED` | Refund flow | `cancel_after_payment` | Refund approved; MOQ recalculated | `PaymentCancellation`; decrement `paid_qty`; trigger refund; re-check MOQ | Buyer + Finance Approver |

### Business Rules

- **Only L4 counts toward MOQ.** This is the fundamental rule from spec 6.1. No amount of L1/L2/L3 interest constitutes a binding commercial commitment or qualifies a group for fulfillment.
- **No token advance before price known** (spec 4.2 V1). Payments only happen after a specific final offer exists.
- **Deduplication at L1:** A buyer can express interest once per group. OTP verification prevents bot inflation.
- **Reconfirmation at L2:** Procurement may request reconfirmation before sourcing begins, especially for high-value groups.
- **Immutable acceptance snapshot at L3:** `offer_acceptances.accepted_terms_hash` captures the exact offer terms the buyer agreed to. If terms change, a new acceptance is required.
- **L5 is irrevocable:** Once released to seller, cancellation requires a formal refund/return workflow. The seller has a legitimate fulfillment expectation.

---

## Cross-Machine Event Dependencies

The state machines above do not operate in isolation. Key cross-machine event flows:

```
Payment.CAPTURED ──> Order.PAID ──> DemandGroup.paid_qty++ ──> MOQ check
                                                                  │
                                              ┌───────────────────┤
                                              │                   │
                                     MOQ met: │          MOQ failed:
                                              │                   │
                              DemandGroup.MOQ_MET    DemandGroup.MOQ_FAILED
                                     │                        │
                              Order.RELEASED_TO_SELLER   Order.CANCELLED
                                     │                   Payment.REFUND_PENDING
                              Fulfillment.READY_TO_DISPATCH
                                     │
                              ... delivery chain ...
                                     │
                              Order.ACCEPTED
                                     │
                              Settlement.AWAITING_VERIFICATION
                                     │
                              ... approval chain ...
                                     │
                              Settlement.PROVIDER_CONFIRMED_SETTLED
                              Payment.SETTLED
```

### Dispute Interactions

```
Dispute.OPENED ──> Settlement.DISPUTED / ON_HOLD
                   Order.DISPUTED
                   Payment settlement blocked

Dispute.CLOSED (buyer wins) ──> Payment.REFUND_PENDING ──> Settlement cancelled
Dispute.CLOSED (seller wins) ──> Settlement.AWAITING_VERIFICATION resumes
```

---

## Invariants Across All Machines

1. **Idempotency:** Every webhook-triggered transition is idempotent. Duplicate events are detected via unique provider event IDs and are no-ops.
2. **Maker-checker:** All financial approvals (offer publish, settlement release, refund above threshold) require two distinct actors.
3. **Append-only audit:** Every state transition creates an immutable event row (`order_status_events`, `group_intent_events`, `dispute_events`, `ledger_entries`, etc.) with actor, timestamp, from_state, to_state and reason.
4. **Optimistic concurrency:** State transitions use version checks (`SELECT ... FOR UPDATE` or application-level version column) to prevent race conditions.
5. **No silent repricing:** Once a buyer accepts and pays at a specific price, that price is locked. Any change requires a new offer version and fresh buyer consent.
6. **Seller bid confidentiality:** At no point does any state machine transition expose one seller's bid to another seller, to buyers, or to unauthorized staff.
7. **Monetary integrity:** All amounts are integer paise (INR). Double-entry ledger postings balance. Gateway is the source of truth for fund state; platform ledger tracks business eligibility.
8. **Immutable bid revisions:** `seller_bid_revisions` rows are append-only. No UPDATE or DELETE on revision rows.
