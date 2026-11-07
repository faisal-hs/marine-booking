# Provider app API: status against the specification

The source is *Provider platform API specification v1.0* (6 November 2026): 29 original endpoints plus proposed additions, 38 operations in the tables below
(the spec says "about 40"). The routes are in `routes/api_provider.php`; the older provider routes are in `routes/api_v1.php`.

| | Operations |
|---|---|
| **Built** | 9, plus 1 that replaces two (booking cancellation, below) |
| **Decided: the current system stays** | 3 (availability) |
| **Waiting on a decision** | 14 |
| **Collide with a route that already exists** | 6 |
| **Not yet, need no decision** | 4 (reviews) |

## 1. Every operation, and where it stands

**Built** = implemented, tested, in `routes/api_provider.php`. **Replaced** / **Stays** = settled by a decision (section 4). **Decision** = not built, the
spec and the platform disagree or the spec leaves the rule open. **Collides** = the same method and path exists with another response; replacing it would
change a contract in use (section 5). **Not yet** = needs no decision, simply not done.

### Phase 1: onboarding and verification

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/categories` | **Built** | Active categories as `{id, name, icon}`. Open to a provider not yet approved. |
| `POST /provider/apply` | **Built** | Multipart. The three documents keep the names the platform uses (`commercial_registration`, `safety_certification`, `insurance_certificate`). Several categories (many to many). |
| `GET /provider/apply` | **Built** | Latest application, the admin's note, `can_resubmit`. 404 when there is none. |
| `POST /provider/apply/resubmit` | **Built** | Latest rejected application only; creates a NEW application, the rejected one stays as history. |
| `GET /provider/verification` | Decision D4 | Needs a status per document. |
| `POST /provider/verification/{key}` | Decision D4 | Same. |
| `GET /provider/profile` | Collides, D5 | The path exists with another shape. The existing response now also carries the new application details. |
| `PATCH /provider/profile` | Collides, D5 | The existing one is `PUT`. Commercial changes needing new approval need a revision workflow. |

### Phase 2: bookings, calendar, availability

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/dashboard` | **Built** | Balances are provisional (section 3). |
| `GET /provider/bookings` | **Built** | `filter`, `status`, `from`, `to`, `page`, `per_page`; tab counts. |
| `POST /provider/bookings/{id}/accept` | **Replaced** (D1) | Bookings confirm themselves when paid, so there is nothing to accept. |
| `POST /provider/bookings/{id}/decline` | **Replaced** (D1) | By cancelling a confirmed booking: |
| `POST /provider/bookings/{id}/cancel` | **Built** (new) | Confirmed bookings only, reason mandatory, logged as the provider's, customer refunded in full (section 3). |
| `GET /provider/calendar` | **Built** | Blocked days come from the activities' closures. |
| `GET, PUT, PATCH /provider/availability` | **Stays** (D2) | Availability remains per activity (departures and date overrides, `/provider/activities/{activity}/schedule` and `/overrides`). |

### Phase 3: activities

| Operation | Status | Notes |
|---|---|---|
| `PATCH /provider/activities/{id}/status` | **Built** | Publish an approved activity, pause, resume (section 3). |
| `GET, POST /provider/activities` | Collides, D3 | |
| `GET, PUT /provider/activities/{id}` | Collides, D3 | |

### Phase 4: earnings and withdrawals

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/earnings`, `/earnings/statement`, `/earnings/report` | Decision D6, D7 | Need the wallet ledger. |
| `GET /provider/bank-account` | Decision D6 | |
| `POST /provider/withdrawals`, `GET /provider/withdrawals`, `GET /provider/withdrawals/{id}` | Decision D6, D8 | |

### Phase 5: customers, coupons, reviews

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/customers` | **Built** | Tiers from a configurable score (section 3). |
| `GET, POST /provider/coupons`, `PATCH, DELETE /provider/coupons/{id}` | Decision D9 | No coupon exists in the platform yet. |
| `GET /provider/reviews`, `POST, PATCH, DELETE /provider/reviews/{id}/reply` | Not yet | Need no decision; the next batch. |

### Phase 6: analytics

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/analytics` | Decision D10 | The platform records no page views at all. |

## 2. Conventions of the provider app API

- **Envelope.** Success `{status: true, message, data, meta?}`, failure `{status: false, code, message, errors}`, as the spec shows, including for failures that
  happen before any controller runs (no token, wrong token). The rest of the API keeps `{success, ..., pagination}`. It is chosen by the
  `'envelope' => 'provider-app'` attribute on the route group; the shape is one class, `ProviderEnvelope` (D11).
- **Paging.** `page` and `per_page` (default 20, maximum 100), `meta: {current_page, last_page, per_page, total}`. An empty parameter (`?status=`) means no filter.
- **Errors.** `UNAUTHENTICATED` 401, `FORBIDDEN` 403 (wrong kind of token), `PROVIDER_NOT_APPROVED` 403 (with `data.provider_request_status`), `RESOURCE_NOT_FOUND` 404,
  `INVALID_STATUS_TRANSITION` 409, `VALIDATION_ERROR` 422, `CANCELLATION_REASON_REQUIRED` 422, `REFUND_FAILED` 502. `errors` is always an object.
- **Who is calling** is always the token's own provider. Nothing the client sends (a provider id, a customer id) is trusted.
- **Another provider's thing is `404 RESOURCE_NOT_FOUND`** (decided), exactly like one that does not exist, with the same message. The spec's 403 `RESOURCE_NOT_OWNED` is not used.
- **Ids** are the database's numbers, as in the spec's examples, always looked up inside the caller's own data.
- **Times** are UTC, ISO-8601 (`2026-11-06T13:00:00Z`). A booking's `date_time` is its day plus the departure's start time.
- Onboarding routes (categories, apply) are open to a provider who is not approved yet; everything else needs approval. A customer who wants to become a provider
  uses `POST /account/provider-request`, which still works unchanged.

## 3. Rules that are implemented (each in one place)

- **Booking tabs.** *today*: today's trips that are pending, confirmed or completed. *upcoming*: later days, pending or confirmed. *pending*: pending, any day.
  Cancelled and expired bookings appear only under *all* or an explicit `status`. (`ProviderBookings`)
- **Provider cancels a booking** (`ProviderBookingCancellation`). Bookings confirm themselves when the payment succeeds. A provider can cancel one that is
  **confirmed** and whose trip day has not passed (today is allowed), and **must give a reason** (3 to 500 characters, else 422 `CANCELLATION_REASON_REQUIRED`).
  It is recorded on the booking as `cancellation_source = provider` and `cancelled_by_user_id` (the provider's account); the customer's own cancellation is recorded
  as `customer`, and the admin reservation page shows "Cancelled by". The customer is **refunded in full** (what remains of every payment, through the same
  `PaymentService::refund` an admin uses) and told, in app, push and email, that the provider cancelled and that they are being refunded. The provider is not
  notified of their own action; admins still are. It is **all or nothing**: if the refund fails the booking stays confirmed (502 `REFUND_FAILED`). A pending
  booking (a payment hold), a cancelled, expired or completed one, or a trip already past: 409 `INVALID_STATUS_TRANSITION`.
- **Automatic publishing** (`config/activities.php`, `ACTIVITY_AUTO_PUBLISH`, default `true`). An admin must approve every activity either way. On: approval
  publishes it at once (what happened until now). Off: approval makes it `approved` and hidden, and the **provider** publishes it with
  `PATCH /provider/activities/{id}/status {"status": "active"}`. `paused` (or `inactive`) hides a published activity without touching confirmed bookings, and `active`
  resumes it. Nothing that has not been approved can be activated. The app sees published as `active` and inactive as `paused`. An `approved` activity is guarded like a
  live one: changing what customers see sends it back to review, so it cannot be edited after approval and then published. The setting applies to approvals made
  after it is changed.
- **Guests**: the participants of a per-person booking added up, otherwise the quantity; a whole trip counts as one.
- **Gross** = total of COMPLETED bookings before commission. **Net** = (gross - refunds on them) x (1 - the provider's `commission_rate` percent). Refunds are treated as
  borne by the provider (D8). (`ProviderRevenue`)
- **Provisional balances**: `available_balance` = net of every completed booking; `pending_balance` = net of confirmed bookings not yet completed. Nothing has been
  withdrawn because there are no withdrawals. They are replaced by the ledger when it exists (D6).
- **Blocked day** (calendar): every published activity of the provider is closed that day by an active "unavailable" override. (`ProviderCalendar`)
- **Customer score** out of 100, from `config/provider_app.php` (nothing is hard-coded): completed spend up to 5000 EGP = 40, completed bookings up to 10 = 30, bookings
  made in the last 30 days up to 3 = 20, no cancellation in the last 90 days = 10. **vip >= 70, regular >= 30, otherwise normal.** The weights and the thresholds are the
  spec's initial values and are configurable; **the caps are my assumption** (the spec gives no scale) and are in the same file. A customer is someone with at least one
  confirmed or completed booking; their phone and email are never returned.

## 4. Decisions

| # | Question | Outcome |
|---|---|---|
| D1 | Accept/decline bookings | **Decided.** Keep automatic confirmation on payment; the provider can cancel a confirmed booking, with a mandatory reason, logged as provider-initiated. |
| D2 | Provider-wide availability | **Decided.** The current per-activity system stays; the three availability endpoints are not built. |
| D3 | Activity publishing | **Decided.** A true/false setting for automatic publishing; admin approval stays a prerequisite. *Still open inside D3: the simple `price`, `duration_hours`, `max_capacity` fields of the spec's activity create/update, and one category versus several per activity.* |
| D12 | Customer tiers | **Decided.** The spec's initial weights and thresholds, configurable in a config file. |
| Ownership | Another provider's resource | **Decided.** `404 RESOURCE_NOT_FOUND`. |
| D4 | Verification documents: a status per document, versions, review history; needs per-document review in the admin panel too. | Open. Recommendation: build it, with Approve / Reject per document on the admin request page. |
| D5 | Profile: the spec's `GET`/`PATCH` collide with the existing `GET`/`PUT`. | Open. Recommendation: add the spec's fields to the existing response and accept `PATCH`, rather than replacing. |
| D6 | Wallet: when do completed bookings become withdrawable; who executes payouts. | Open. Recommendation: a ledger first; withdrawals recorded, paid out manually until a payout provider is chosen. |
| D7 | Currency: the spec's examples are SAR; the platform stores EGP. | Open. Recommendation: EGP. |
| D8 | Who bears promotional discounts, taxes, gateway fees (what Net means). | Open. Recommendation: the provider bears commission and refunds; the platform bears gateway fees and its own discounts. |
| D9 | Coupons: unique per provider or platform-wide; empty `activity_ids` means all; applying one is part of the customer booking flow. | Open. Recommendation: unique per provider; empty means all; built together with the customer side. |
| D10 | Analytics: the platform records no page views. | Open. Recommendation: start recording views now; build the endpoint once there is data. |
| D11 | The spec's envelope differs from the rest of the API's. | Assumed: the spec's, for the provider app only. |

## 5. Where the spec collides with routes that already exist

| Spec | Existing | Differences |
|---|---|---|
| `GET /provider/profile` | `GET /provider/profile` | the spec's has `category_ids`, `commercial_data_status`, `logo`; the platform says `verified` |
| `PATCH /provider/profile` | `PUT /provider/profile` | method; commercial changes needing approval |
| `GET, POST /provider/activities`, `GET, PUT /provider/activities/{id}` | the same paths | uuids, richer fields, options, pricing rules, review |
| `/provider/verification` | `/provider/documents` | document status and history |
| `/provider/bookings` | `/provider/reservations` | both exist; the new one is the spec's contract |

## 6. What changed in existing code

- `ProviderRequestService`: stores the new application details and categories **only when they are sent**; `POST /account/provider-request` is unchanged.
- `Provider` and `Category` models: `categories` / `providers` (table `category_provider`); `providers` has four new nullable columns.
- `ReservationService::cancel()` takes who cancelled (default: the customer, as before) and records it; `reservations` has two new nullable columns, `cancellation_source` and `cancelled_by_user_id`.
- `SendReservationCancelledNotifications` and `ReservationCancelledNotification`: the customer is told by whom, and the provider is not told of their own cancellation.
- `ActivityModerationService`: `approve()` follows `activities.auto_publish`; `publishApproved()` is new; `sendBackToReview()` and `LiveActivityContentGuard` also cover `approved`.
- `ActivityApprovedNotification`: says whether the activity is live or waiting for the provider. The admin screen shows the `approved` state and says what an approval did.
- `EnsureProviderApproved`, `bootstrap/app.php`: answer in the spec's shape on provider app routes.
- `ProviderResource`: five more fields (`business_type`, `marina_name`, `contact_phone`, `contact_email`, `category_ids`); nothing removed. Reservation resources: `cancellation_source`.
