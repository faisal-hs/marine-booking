# Provider app API: status against the specification

The source is *Provider platform API specification v1.0* (6 November 2026): 38 operations in the tables below (the spec says "about 40"). The routes are in
`routes/api_provider.php`; the older provider routes are in `routes/api_v1.php`. A Postman collection that exercises all of it is `postman/Provider-API.postman_collection.json`.

| | Operations |
|---|---|
| **Built** | 17, plus 1 that replaces two (booking cancellation) |
| **Decided: the current system stays** | 3 (availability) |
| **Answered by an older endpoint** | 5 (`GET /provider/profile` and the four activity operations) |
| **Decided, not built yet** | 11 (verification documents 2, coupons 4, reviews 4, analytics 1) |

## 1. Every operation, and where it stands

**Built** = implemented, tested, in `routes/api_provider.php`. **Replaced / Stays** = settled by a decision (section 4). **Older** = an older endpoint answers the same need with another shape.
**Not built** = decided or needing no decision, simply not done yet.

### Phase 1: onboarding and verification

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/categories` | **Built** | Active categories as `{id, name, icon}`. Open to a provider not yet approved. |
| `POST /provider/apply` | **Built** | Multipart. The three documents keep the names the platform uses. Several categories (many to many). |
| `GET /provider/apply` | **Built** | Latest application, the admin's note, `can_resubmit`. 404 when there is none. |
| `POST /provider/apply/resubmit` | **Built** | Latest rejected application only; creates a NEW application, the rejected one stays as history. |
| `GET /provider/verification`, `POST /provider/verification/{key}` | Not built | Decided (section 4). Until then the older `/provider/documents` endpoints list and upload. |
| `GET /provider/profile` | **Older, kept** | Unchanged, plus the business details and `pending_profile_change`. |
| `PATCH /provider/profile` | **Built** | Ordinary fields change at once; critical ones wait for an admin (section 3). The older `PUT` follows the same rule. |

### Phase 2: bookings, calendar, availability

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/dashboard` | **Built** | Figures come from the wallet ledger. |
| `GET /provider/bookings` | **Built** | `filter`, `status`, `from`, `to`, `page`, `per_page`; tab counts. |
| `POST /provider/bookings/{id}/accept`, `/decline` | **Replaced** | Bookings confirm themselves when paid. |
| `POST /provider/bookings/{id}/cancel` | **Built** (new) | Confirmed bookings only, reason mandatory, logged as the provider's, customer refunded in full. |
| `GET /provider/calendar` | **Built** | Blocked days come from the activities' closures. |
| `GET, PUT, PATCH /provider/availability` | **Stays** | Availability remains per activity (`.../schedule`, `.../overrides`). |

### Phase 3: activities

| Operation | Status | Notes |
|---|---|---|
| `PATCH /provider/activities/{id}/status` | **Built** | Publish, pause, resume. See the limitation on activity approval in section 7. |
| `GET, POST /provider/activities`, `GET, PUT /provider/activities/{id}` | **Older** | Richer model (booking options, pricing rules, schedule), uuids. |

### Phase 4: earnings, wallet, withdrawals (all built)

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/earnings` | **Built** | Balances, gross and net revenue, last payout, breakdown by activity, ledger transactions. |
| `GET /provider/earnings/statement?month=` | **Built** | A **signed link that expires** to a **CSV** of the month. |
| `GET /provider/earnings/report?year=` | **Built** | Monthly gross and net, annual totals, change against the year before. |
| `GET /provider/bank-account` | **Built** | The IBAN is never returned in full (only `iban_last4`). 404 until one is added. |
| `PUT /provider/bank-account` | **Built (addition)** | The spec has no way to set the account, and a withdrawal needs one. |
| `POST /provider/withdrawals` | **Built** | Reserves the amount at once; an idempotency key stops a repeat. |
| `GET /provider/withdrawals`, `GET /provider/withdrawals/{id}` | **Built** | `status` filter, paging; only the provider's own. |

### Phase 5: customers, coupons, reviews

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/customers` | **Built** | Tiers from a configurable score. |
| `GET, POST /provider/coupons`, `PATCH, DELETE /provider/coupons/{id}` | Not built | Provider-funded only (decided); needs the customer booking flow to apply a coupon. |
| `GET /provider/reviews`, `POST, PATCH, DELETE /provider/reviews/{id}/reply` | Not built | Needs no decision. |

### Phase 6: analytics

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/analytics` | Not built | Financial figures must come from the ledger; the platform records no page views yet. |

## 2. Conventions of the provider app API

- **Envelope.** Success `{status: true, message, data, meta?}`, failure `{status: false, code, message, errors}`, also for failures before any controller runs. The rest of the API keeps `{success, ..., pagination}`. Chosen by the `'envelope' => 'provider-app'` attribute on the route group (`ProviderEnvelope`).
- **Paging.** `page` and `per_page` (default 20, maximum 100), `meta: {current_page, last_page, per_page, total}`. An empty parameter means no filter.
- **Errors.** `UNAUTHENTICATED` 401, `FORBIDDEN` 403, `PROVIDER_NOT_APPROVED` 403, `RESOURCE_NOT_FOUND` 404, `INVALID_STATUS_TRANSITION` 409, `DUPLICATE_WITHDRAWAL` 409, `VALIDATION_ERROR` 422, `CANCELLATION_REASON_REQUIRED` 422, `INSUFFICIENT_AVAILABLE_BALANCE` 422, `BANK_ACCOUNT_REQUIRED` 422, `REFUND_FAILED` 502. `errors` is always an object.
- **Another provider's thing is `404 RESOURCE_NOT_FOUND`**, with the same message as one that does not exist (decided; the spec's 403 `RESOURCE_NOT_OWNED` is not used).
- **Ids** are the database's numbers, always looked up inside the caller's own data. **Times** are UTC ISO-8601. **Money** is in the platform currency, SAR.
- Onboarding routes (categories, apply) are open to a provider not yet approved; everything else needs approval. The statement download is the one route with no token: it is reached through the signed link, and only a valid signature opens it.

## 3. Rules that are implemented

- **Money.** Amounts are PHP floats **rounded to two decimals at every step**, and stored in `DECIMAL(12,2)` columns (decided; storage is not changed to FLOAT).
- **Currency** (`config/platform.php`, `PLATFORM_CURRENCY`, **SAR**). Written onto each record when it is made; changing it affects only new records. There is no real data in EGP and none is converted.
- **Commission** is set **per provider** and is the same for all their activities: `commission_type` is `percentage` (a percent of what the provider kept on a booking) or `fixed` (an amount kept once per booking, never more than the booking), and `commission_rate` is the value. An admin sets it on the provider form.
- **The wallet ledger** (`wallet_entries`): every movement of a provider's money is one signed row, never edited; a correction is a new row; each row has a unique `reference`, so nothing is posted twice. When a booking is **completed**, three kinds of row are posted for it: `booking_income` (+total), `refund` (-refunded, posted as it happens, also after the fact) and `platform_fee` (-commission on what the provider kept). The commission **terms are frozen when the first fee is posted**: a later change of terms never rewrites a booking already posted. `LedgerService::plan()` is the one rule, used by the live system and by the bulk seeder.
- **Waiting period** (`WALLET_SETTLEMENT_DELAY_DAYS`, configurable, **default 0**): booking money becomes available that many days after the END of the trip day.
- **Balances** (all sums of the ledger): `available`, `pending` (inside the waiting period), `reserved` (held for withdrawals that are waiting or being processed), `total_earnings` and `total_withdrawn`. A refund after the money was withdrawn can make `available` negative; a provider who owes money cannot withdraw.
- **Withdrawals.** A provider asks (`amount`, `idempotency_key`); the amount is **reserved immediately** (a ledger row of minus the amount) and a repeated key is refused. **An admin pays by hand**, in the admin panel (Withdrawals), and records the result: *start processing*, *paid* (with the transfer reference), *rejected* or *failed* (with a reason). Rejected and failed give the money back, exactly once; a paid withdrawal can never be given back; each request has one outcome, decided under a lock and only from the right status. The system never moves money to a bank. Both sides are notified. Permissions: `withdrawals.view`, `withdrawals.process`.
- **Withdrawal fee** (`WALLET_WITHDRAWAL_FEE_PERCENT`, default **0**) and the **smallest withdrawal** (`WALLET_MIN_WITHDRAWAL`, default 0) are configuration. The spec's `fee` and `net_amount` exist and are 0 until a fee is decided.
- **Bank account.** Holder name, bank, IBAN (stored encrypted), optional SWIFT. A withdrawal keeps a copy of the account it was asked for.
- **Profile changes.** Ordinary information changes at once. **Critical information** (`critical_profile_fields`: the business name and type) waits for an admin's approval; one waiting request at a time, replaced when asked again; the admin approves or rejects (with a reason) on the provider's page.
- **Provider cancels a booking.** Confirmed bookings only, before the trip day has passed, with a mandatory reason; logged as `cancellation_source = provider`; refunded in full, all or nothing; the provider is not told of their own action.
- **Automatic publishing** (`ACTIVITY_AUTO_PUBLISH`): see section 7, this is not yet the final rule.
- **Customer tiers**, from `config/provider_app.php`: completed spend 40, completed bookings 30, recent bookings 20, no recent cancellation 10; vip >= 70, regular >= 30. The weights, caps and thresholds are configuration.
- **Seeders are deterministic.** The bulk seeders draw from **their own random engine** (`BulkSeeder::$random`), never from PHP's global generator. Faker's destructor reseeds the global generator whenever it is destroyed, and a seeder that depended on it gave different data from run to run depending on how much garbage earlier code had left. `SeederDeterminismTest` recreates that on purpose.

## 4. Decisions (final business decisions of 7 November)

| Topic | Decision | State |
|---|---|---|
| Currency | SAR; no real EGP data, nothing is converted | **Done** |
| Money arithmetic | PHP floats rounded to two decimals; `DECIMAL(12,2)` storage | **Done** |
| Commission | Per provider, `percentage` or `fixed`, same for all their activities; no per-activity rates | **Done** |
| Earnings availability | A configurable waiting period | **Done** (default 0, see section 6) |
| Withdrawals | The provider asks; the amount is reserved at once; an admin pays by hand and updates the status | **Done** |
| Coupons | Provider-created and provider-funded; no platform-funded coupons or promotions | Not built |
| Activity publishing | Admin approves the **provider** only; the provider creates, edits and publishes; `ACTIVITY_AUTO_PUBLISH` decides whether a publish request is live at once or kept hidden until the provider activates it; an admin can pause or deactivate, tracked separately from the provider's own pause | Not built (section 7) |
| Verification documents | Admin configures the document types, each mandatory or optional, can request an update, reviews, approves, rejects; the provider sees statuses, uploads, replaces | Not built |
| Booking accept/decline | Replaced by the provider cancelling a confirmed booking | **Done** |
| Availability | Stays per activity | **Done** |
| Customer tiers | The spec's weights, thresholds configurable | **Done** |
| Another provider's resource | `404 RESOURCE_NOT_FOUND` | **Done** |

## 5. Where the spec collides with routes that already exist

| Spec | Existing | Differences |
|---|---|---|
| `GET /provider/profile` | `GET /provider/profile` | the spec's has `category_ids`, `commercial_data_status`, `logo`; the platform says `verified` |
| `GET, POST /provider/activities`, `GET, PUT /provider/activities/{id}` | the same paths | uuids, richer fields, options, pricing rules |
| `/provider/verification` | `/provider/documents` | document status and history |
| `/provider/bookings` | `/provider/reservations` | both exist; the new one is the spec's contract |

## 6. Still to be confirmed (each is configurable or neutral until then)

1. **The waiting period** and its default value: configurable, **0** for now.
2. **Gateway fees**: whether they apply and who bears them. The withdrawal fee exists as configuration and is 0.
3. **Bank account changes**: whether they need admin approval. Today they do not.
4. **Bank account fields**: holder name, bank, IBAN, optional SWIFT are my choice.
5. **Capacity per departure**: today `max_participants` belongs to the activity. Not touched.

## 7. Limitations

- **Activity approval is not yet the final rule.** The code still has the admin approval of activities, with the `ACTIVITY_AUTO_PUBLISH` setting deciding whether approval also publishes. The final rule (admin approves the provider only, the provider publishes, admin pause tracked separately) is decided but not built.
- **The statement is a CSV**, not a PDF (no PDF library is installed).
- **The bank account endpoint `PUT` is an addition** to the spec, and a change needs no admin approval.
- **Balances are written by posting on completion, on a refund, and by a nightly job** (`wallet:sync`, 00:30); reading earnings also posts anything missing for that provider.
- **Concurrency.** Withdrawal requests of one provider are serialised by a row lock and each request has a unique key; this is tested one request after another, because the test database (SQLite) cannot run two requests at the same instant. The lock is a database lock (`FOR UPDATE`) on MySQL.
- **Listeners are registered twice** in this project (explicitly in `AppServiceProvider` and again by Laravel's auto-discovery). It is harmless here (the notifications dedupe and the ledger sync is idempotent) and was left as it is.
- **Admin adjustments** of a wallet (credits or debits by an admin) are in the ledger's vocabulary (`adjustment`) but there is no screen for them.

## 8. What changed in existing code

- `ProviderRequestService`, `Provider`, `Category`, `ProviderResource`, `ReservationService::cancel()`, `ActivityModerationService`, `LiveActivityContentGuard`, `ActivityApprovedNotification`, `EnsureProviderApproved`, `bootstrap/app.php`: as in the earlier rounds.
- `ReservationService::completePastReservations()` posts each completed booking to the wallet; a `SyncWalletOnRefund` listener keeps the ledger right after a refund; `wallet:sync` runs nightly.
- `providers`: `commission_type`, `bank_account` (encrypted), `commission_rate` widened to `decimal(10,2)` so it can hold a fixed amount.
- New tables: `wallet_entries`, `withdrawals`, `provider_profile_changes`, `category_provider`; reservations got `cancellation_source` and `cancelled_by_user_id`.
- `BulkSeeder` and the bulk seeders that used `array_rand`, `mt_rand` or Faker directly now use the seeder's own engine; the helper test starts that engine instead of seeding the global generator.
- The admin provider form has a commission type; the admin has a Withdrawals screen, in English and Arabic.
