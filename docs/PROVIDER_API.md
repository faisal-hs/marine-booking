# Provider app API: status against the specification

The source is *Provider platform API specification v1.0* (6 November 2026): 29 original endpoints plus proposed additions, 38 operations in the tables below
(the spec says "about 40"). The routes are in `routes/api_provider.php`; the older provider routes are in `routes/api_v1.php`.

| | Operations |
|---|---|
| **Built** | 10, plus 1 that replaces two (booking cancellation) |
| **Decided: the current system stays** | 3 (availability) |
| **Kept as it is** | 1 (`GET /provider/profile`, which now also shows a change waiting for review) |
| **Decided, not built yet** | 14 (verification documents, earnings, bank account, withdrawals, coupons, analytics) |
| **Held: the decisions conflict** | 4 (activities create / read / update; see section 7) |
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
| `GET /provider/verification` | Decided, not built | Section 7. |
| `POST /provider/verification/{key}` | Decided, not built | Section 7. |
| `GET /provider/profile` | **Kept** | The existing endpoint, unchanged, plus `business_type`, `marina_name`, `contact_phone`, `contact_email`, `category_ids` and `pending_profile_change`. |
| `PATCH /provider/profile` | **Built** | Partial update. Ordinary fields change at once; critical ones wait for an admin (section 3). The older `PUT` stays and follows the same rule. |

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
| `GET, POST /provider/activities`, `GET, PUT /provider/activities/{id}` | **Held** | Your two answers about activity approval conflict (section 7). |

### Phase 4: earnings and withdrawals

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/earnings`, `/earnings/statement`, `/earnings/report` | Decided, not built | The wallet ledger first (section 7). |
| `GET /provider/bank-account` | Decided, not built | Section 7. |
| `POST /provider/withdrawals`, `GET /provider/withdrawals`, `GET /provider/withdrawals/{id}` | Decided, not built | The wallet ledger first (section 7). |

### Phase 5: customers, coupons, reviews

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/customers` | **Built** | Tiers from a configurable score (section 3). |
| `GET, POST /provider/coupons`, `PATCH, DELETE /provider/coupons/{id}` | Decided, not built | Needs the customer booking flow to apply one (section 7). |
| `GET /provider/reviews`, `POST, PATCH, DELETE /provider/reviews/{id}/reply` | Not yet | Need no decision; the next batch. |

### Phase 6: analytics

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/analytics` | Decided, not built | Financial figures must come from the ledger (section 7). |

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
- **Profile changes** (`ProviderProfileService`). Ordinary information (bio, address, city, area, location, marina, business contact, categories, the account's name and email) changes
  at once. **Critical information** (`config/provider_app.php` → `critical_profile_fields`, default `business_name` and `business_type`) does not take effect until an admin approves it:
  the live profile keeps its value, and the response says the change is waiting, with the current and the requested value. There is at most one waiting request: asking again replaces it
  (the older one is kept as `superseded`), and asking for a field's current value again withdraws just that field. The admin sees it on the provider's page with Approve and Reject (a
  reason is required and the provider is told it); admins who may update providers are notified when one is waiting. `PATCH` can clear a field with null; the older `PUT` ignores nulls,
  as it always did. **The older `PUT` also follows the rule**: changing the business name through it no longer takes effect at once.
- **Currency** (`config/platform.php`, `PLATFORM_CURRENCY`, **SAR**). Every price, booking, payment and report label uses it. It is written onto each record when the record is made, so a
  record always says what it was made in, and changing it affects only new records: **nothing already stored is converted or relabelled**. Records made before this change keep
  `EGP` (section 7, question 1).
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

| Topic | Outcome |
|---|---|
| Accept/decline bookings | **Decided.** Automatic confirmation on payment; the provider can cancel a confirmed booking, with a mandatory reason, logged as provider-initiated. **Built.** |
| Provider-wide availability | **Decided.** The current per-activity system stays. |
| Customer tiers | **Decided.** The spec's weights and thresholds, configurable in `config/provider_app.php`. **Built.** |
| Another provider's resource | **Decided.** `404 RESOURCE_NOT_FOUND`. **Built.** |
| Profile | **Decided and built.** `PATCH` added, `PUT` kept; critical changes reviewed by an admin. |
| Currency | **Decided and built.** SAR. |
| Verification documents | **Decided, not built.** Section 7. |
| Wallet and withdrawals | **Decided, not built.** Section 7. |
| Discounts, fees, commission | **Decided, not built.** Needs the ledger. Section 7. |
| Coupons | **Decided, not built.** Section 7. |
| Analytics | **Decided, not built.** Section 7. |
| Activity pricing and capacity | **Decided, not built.** Section 7. |
| Activity publishing | **Conflict.** Your answer of 6 November (admin approval stays, with an automatic-publishing switch) and your document of 7 November (no admin approval of activities) disagree. Section 7. |
| Envelope | Assumed: the spec's, for the provider app only. |

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
- `ProviderResource`: also `pending_profile_change`. `Provider`: `pendingProfileChange()`, `profileChanges()`. New table `provider_profile_changes`.
- `PUT /provider/profile` now goes through `ProviderProfileService`: a changed business name (or any field in `critical_profile_fields`) waits for an admin. The test that asserted an immediate change was updated.
- Currency: `config/platform.php`; the pricing rule model default and the pricing service fallback, the bulk and monthly seeders, the admin report headers, the admin labels (a `:currency` placeholder in English and
  Arabic) and the public activity card read it. A migration changes the database default of `activity_pricing_rules.currency`; no row is touched.

## 7. Held and open: what I need before building the rest

**A conflict to resolve first: activity approval.** On 6 November you answered *"Admin approval remains a prerequisite before activities are published"* and asked for an automatic-publishing setting
(built: `ACTIVITY_AUTO_PUBLISH`). Your document of 7 November says individual activities need **no** admin approval: an approved provider creates, edits and publishes directly, and an admin can
pause or deactivate (with a reason) afterwards. They cannot both stand. If the document is the final word, then: the `pending_review` / approve / reject flow and its admin screens stop being used for
activities; editing a live activity no longer sends it back to review (`LiveActivityContentGuard`); `ACTIVITY_AUTO_PUBLISH` has no purpose; and an admin pause must be remembered separately from a
provider's own pause, so a provider cannot republish what an admin paused. That touches the admin activity screens and a large part of the activity tests, so I have built none of it.

**Gaps in the document** (each needs an answer, or I use the stated default):

1. **Existing EGP data.** Is there real data stored in EGP? Nothing is converted (as you said), so old rows stay EGP, and totals that add old and new rows together would mix currencies. If this is only
   demo data, re-seeding is enough.
2. **When does an earning become withdrawable?** "Once the eligibility conditions are met" is not defined. Default I would use: when the booking is completed, plus a configurable delay (default 0 days).
3. **Gateway fees.** "According to the provider's approved financial agreement": there is no field for it. I would add a percentage and a fixed amount per provider (default 0). And is the commission per
   provider (as now) only, or can an activity have its own rate?
4. **Payouts.** "The approved payout procedure": who pays out and how (bank transfer by hand, a payout provider)? Until chosen, withdrawals can be recorded and reserved but not paid by the system.
5. **Bank account data**: which fields (IBAN, bank name, account holder), and may the provider change it freely? I would require admin review of a change, like the profile.
6. **Platform-funded discounts**: only a provider can create a coupon, so every coupon is provider-funded. Are there platform (admin) coupons or promotions? If so, who creates them?
7. **Verification documents**: which documents are mandatory (today: commercial registration, safety certification, insurance certificate), do any expire (and when is "expired" decided), and what exactly does
   an expired mandatory document suspend?
8. **Activity capacity per departure**: today `max_participants` belongs to the activity and a departure has no capacity of its own. Per-departure capacity means a new column and a change to the
   booking capacity check. Confirm that is wanted, and what an existing activity's departures take as their capacity (I would copy the activity's).
9. **Money arithmetic.** The platform handles money as floats. The ledger must not. I would build it in integer minor units (halalas). That is a design decision, not a question, unless you object.
