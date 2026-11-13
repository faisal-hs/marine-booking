# Provider app API: status against the specification

The source is *Provider platform API specification v1.0* (6 November 2026): 38 operations in the tables below (the spec says "about 40"). The routes are in
`routes/api_provider.php`; the older provider routes are in `routes/api_v1.php`. A Postman collection that exercises all of it is `postman/Provider-API.postman_collection.json`.

| | Operations |
|---|---|
| **Built** | 28, plus 1 that replaces two (booking cancellation) |
| **Decided: the current system stays** | 3 (availability) |
| **Answered by an older endpoint** | 5 (`GET /provider/profile` and the four activity operations) |

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
| `GET /provider/verification` | **Built** | One row per document an admin asks for: `status` (`missing`, `pending`, `approved`, `rejected`, `expired`, and `update_requested`), `is_done`, a signed expiring `file_url`, the `review_note`, `is_mandatory`. Open to a provider not yet approved. |
| `POST /provider/verification/{key}` | **Built** | Uploads or replaces one document (multipart `file`); it is `pending` until an admin decides. An approved document nobody asked to update answers 409. |
| `GET /provider/profile` | **Older, kept** | Unchanged, plus the business details and `pending_profile_change`. |
| `PATCH /provider/profile` | **Built** | Ordinary fields change at once; critical ones wait for an admin (section 3). The older `PUT` follows the same rule. |

### Phase 2: bookings, calendar, availability

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/dashboard` | **Built** | Revenue from the wallet ledger; trends, weekly revenue, performance and the rating distribution (rules in section 3). |
| `GET /provider/bookings` | **Built** | `filter` (`today`, `this_week`, `upcoming`, `pending`, `all`), `date` (one day), `status`, `from`, `to`, `page`, `per_page`; tab counts. |
| `POST /provider/bookings/{id}/accept`, `/decline` | **Replaced** | Bookings confirm themselves when paid. |
| `POST /provider/bookings/{id}/cancel` | **Built** (new) | Confirmed bookings only, reason mandatory, logged as the provider's, customer refunded in full. |
| `GET /provider/calendar` | **Built** | Blocked days come from the activities' closures. |
| `GET, PUT, PATCH /provider/availability` | **Stays** | Availability remains per activity (`.../schedule`, `.../overrides`). |

### Phase 3: activities

| Operation | Status | Notes |
|---|---|---|
| `PATCH /provider/activities/{id}/status` | **Built** | `active` turns an activity on (always live), `paused` takes it off. Refused with `403 ACTIVITY_SUSPENDED_BY_ADMIN` while an admin's suspension stands. |
| `GET, POST /provider/activities`, `GET, PUT /provider/activities/{id}` | **Older** | Richer model (booking options, pricing rules, schedule), uuids. A provider's edit applies at once. |
| `POST /provider/activities/{uuid}/publish` (old name `submit-for-review`) | **Built** | The provider's publish request: live at once, or *ready* and hidden, as `ACTIVITY_AUTO_PUBLISH` says. |

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
| `GET /provider/coupons` | **Built** | The provider's own coupons, `status` filter, `used_count` of the bookings still alive. |
| `POST /provider/coupons` | **Built** | Percent (more than 0, at most 100) or fixed; the code in capitals, unique among the provider's own; optional limit, cap, smallest booking, expiry, activities. |
| `PATCH /provider/coupons/{id}` | **Built** | Percent and fixed can be swapped; the code never changes; the limit cannot go below the uses taken. |
| `DELETE /provider/coupons/{id}` | **Built** | Deactivates; nothing is deleted. |
| `POST /customer/coupons/validate` | **Built (customer side, not in the spec)** | What a coupon would take off a booking, before booking. A coupon is used by sending `coupon_code` with `POST /customer/reservations`. |
| `GET /provider/reviews` | **Built** | Rating, how the ratings are spread, and the reviews of the provider's own activities with their replies; hidden reviews are not counted. |
| `POST /provider/reviews/{id}/reply` | **Built** | One reply stands per review (a second answers 409). |
| `PATCH /provider/reviews/{id}/reply` | **Built** | Edits the standing reply. |
| `DELETE /provider/reviews/{id}/reply` | **Built** | Deletes it and keeps an audit record; the review can be answered again. |

### Phase 6: analytics

| Operation | Status | Notes |
|---|---|---|
| `GET /provider/analytics` | **Built** | Page views, conversion, rating, response rate, top activities (from the ledger) and booking sources, for a period of at most a year. Every figure is defined in section 3. |

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
- **Verification documents.** Uploading and an admin's approval are separate steps.
  - **Which documents are asked for** is configured by an admin on the dashboard (`verification_document_types`, no longer a list in the config): each type has a key, a name in English and Arabic, an optional description, mandatory or optional, active or switched off, and an order. The migration creates the same eight types as before, with the same three mandatory. **Applying as a provider needs a file for every active mandatory type** (a type an admin adds is asked for by its key), so what is configured is what the application form asks for. A type documents use is switched off, never deleted; its key never changes.
  - **Status.** Each uploaded file is `pending`, `approved` or `rejected` (with a note); an approval may carry a valid-until date, after which it shows as `expired`. What a provider sees per document type is worked out, never stored: `update_requested` (an admin asked for a new version and nothing has been uploaded since) beats everything; then `missing`, `expired`, and the status of the version in force. `is_done` is true only for `approved`.
  - **Uploading** (`POST /provider/verification/{key}`): allowed when nothing is on file, while it waits, after a rejection, once it expired, and whenever an admin asked for an update. A new version **replaces** the one in force, which is **kept** as history (marked replaced), because it may be what an approval was based on. A provider cannot delete a replaced version, nor the mandatory documents of an approved provider.
  - **The admin** reviews from a queue (Document review) and on each provider's page: **approve** (optionally until a date), **reject** (a reason is mandatory), or **ask for an update** of any document type, with a reason, even one nothing was uploaded for. Only something the provider sent in can be approved or rejected; to take an approval back, ask for an update. Every upload and decision is in the **review history** with who made it and why. The provider is told of each decision; the admins who review are told of each upload. Verifying the provider approves the documents still waiting, with a note in the history.
  - The files are **private**: a provider reads their own through a signed link that expires (`WALLET_STATEMENT_LINK_MINUTES`), an admin through the download route. Permissions: `provider_documents.review`, `document_types.manage`.
- **Dashboard.** `month_revenue` and `today_revenue` are the ledger's **gross** (the price of the trips that took place, after the provider's own coupon, never the service fee or VAT); a trip counts on its own day, and revenue is only recognised once the trip is completed. Every comparison is `null` when there was nothing to compare with (never 0 or infinite), and a percentage has one decimal.
  - `month_revenue.change_percent`: this month so far against **the same days of last month** (the 1st to the same day of the month, or the whole of a shorter month): a fair comparison, because revenue only counts trips that have happened.
  - `weekly_revenue`: four numbers, **oldest first, the current week (so far) last**. A week starts on `PLATFORM_WEEK_STARTS_ON` (0, Sunday, the Saudi week; 1 is Monday, 6 Saturday) and the same setting decides what "this week" means for bookings.
  - `month_bookings_change`: **how many more or fewer** bookings (not a percentage) than the whole of last month, both counted by the day of the trip (so this month includes trips still to come). `month_bookings` counts pending, confirmed and completed bookings.
  - `today_revenue_change_percent`: today against yesterday.
  - `performance`, each over a stated period and `null` with nothing to be worked out from: `booking_rate` is, of the bookings **made** in the last 30 days, the percentage that were kept (paid and confirmed, or completed); `cancellation_rate` the percentage that were cancelled (by the customer or the provider); `response_rate` the percentage of the last 30 days' visible reviews the provider replied to (there is no customer messaging for providers yet, so replies to reviews are what is measured); `repeat_customers` the percentage of the customers with a kept booking who have **two or more**, all time.
  - `rating_distribution`: the **share** of the visible reviews for each star, in percent, adding up to exactly 100 (the leftover points go to the largest remainders, the best star on a tie), every star listed, all 0 with no reviews. (`GET /provider/reviews` gives the counts.)
- **Bookings filters.** `filter=this_week` is the bookings whose **trip** is in the current week (Sunday to Saturday unless `PLATFORM_WEEK_STARTS_ON` says otherwise), soonest first, in the time order of the day's departures; `date=YYYY-MM-DD` is the bookings whose trip is on that day (past or future), earliest departure first. Like `today`, both show the **real** bookings (pending, confirmed, completed) unless a `status` is asked for. They combine with each other and with `from`/`to`: every filter given must hold. An empty value means no filter.
- **Provider cancels a booking.** Confirmed bookings only, before the trip day has passed, with a mandatory reason; logged as `cancellation_source = provider`; refunded in full, all or nothing; the provider is not told of their own action.
- **Publishing activities.** The admin approves the **provider account** only; **nobody approves an activity**. An approved provider creates, edits and publishes their own, and a provider's edit applies at once (a live activity stays live).
  - **The publish request** (`POST /provider/activities/{uuid}/publish`, a draft only): with `ACTIVITY_AUTO_PUBLISH` on (the default) the activity is live at once; with it off the activity becomes **ready** (stored as `approved`, shown to the provider as `ready`) and stays hidden until the provider activates it. Activating (`PATCH .../status` with `active`), from a draft, from ready or from the provider's own pause, always makes it live.
  - **Pausing** is the provider's: `paused` takes a live activity off the site and `active` brings it back. Confirmed bookings are untouched.
  - **Admin suspension.** An admin can stop an activity that is live, ready or paused (a draft cannot be suspended), with a mandatory reason. It is recorded **apart from the provider's pause** (the activity is `inactive` with `suspended_at`, `suspension_reason`, `suspended_by_user_id` and `suspended_from_status` set), and **every way of going live refuses it**: a provider can never switch it off, whatever route they use, and an edit never clears it. Only an admin lifting the suspension (the same `activities.suspend` permission) ends it, and the activity goes back to what it was (live again only while its provider is still in good standing). The provider is told why, and when it is lifted; they are not told of their own publishing or pausing. "Pause or deactivate" is one admin act.
  - Before an activity goes live its provider's account must be approved and in good standing (`ProviderVerificationGate::assertCanPublish`).
- **Customer tiers**, from `config/provider_app.php`: completed spend 40, completed bookings 30, recent bookings 20, no recent cancellation 10; vip >= 70, regular >= 30. The weights, caps and thresholds are configuration.
- **Coupons.** A coupon is **funded by the provider** that made it: the customer pays less, and the provider earns on what the customer paid (the platform's commission is on that too). There are no platform-funded coupons. One coupon per booking.
  - **The coupon.** `percent` (more than 0 and at most 100, optionally capped by `max_discount`) or `fixed` (a positive amount); optional `usage_limit`, `min_order_amount` and `expires_at`; limited to some of the provider's activities, or, with none listed, **all** of them. The code is kept in capitals and is **unique among the provider's own** (a booking is for one activity, so its provider is always known). Percent and fixed can be swapped; the code never changes; the limit cannot be set below the uses already taken. A coupon is **deactivated, never deleted**.
  - **Using one.** A customer sends `coupon_code` with the booking (`POST /customer/reservations`), or checks it first with `POST /customer/coupons/validate` (rate limited, because it is a way to guess codes). It works when it is active, not expired, for that activity, the booking is large enough, and it has uses left. The discount is the percentage (never above the cap) or the fixed amount, never more than the booking, and **a booking never costs less than `COUPON_MINIMUM_TOTAL`** (default 1): a bigger discount is cut back. It is checked, and its last use taken, **under a lock**, so two bookings cannot both have it. A refused coupon books nothing; an unknown and a switched-off code are answered alike.
  - **Its used count** is the bookings it is on that are **still alive** (not cancelled, not expired): a use is given back when a booking is not kept, and a pending booking holds its use. The booking stores the discount, the total after it and a `coupon_redemptions` row; the ledger records the discount on the booking's income.
- **Reviews.** A provider sees the reviews of **their own** activities (an admin-hidden one is not counted or shown) with the rating (two decimals), how the ratings are spread (every star 5 to 1) and each review's reply. **One reply stands per review**; it can be edited; deleting it keeps the row (when, and by whom) as an audit record and lets the review be answered again; the database allows only one active reply. Someone else's review, a hidden one and a made-up id are all `404`, and the review is looked up **before** the text is checked. Customers see the reply with the review; admins see it on the review page.
- **Analytics.** Every figure has one definition (`ProviderAnalytics`): **page_views** are visitors per day looking at a live activity (a refresh adds nothing; the provider's own looks and robots do not count; the visitor is a hash that cannot be turned back into a person); **conversion_rate** is the bookings made in the period (confirmed or completed: a cancelled or expired one is not a booking) over the page views in it, times 100, 0 when nobody looked; **page_views_change** and **conversion_change** compare with the period of the same length just before (percent, and percentage points; `null` when that period had no page views); **avg_rating** is the average of all visible reviews (`null` when none); **response_rate** is the share of the period's visible reviews the provider replied to (`null` when none); **top_activities** are the activities whose trips took place in the period, best gross first, at most five, **from the wallet ledger** (so the revenue is the revenue everywhere else: gross is what customers paid, net what the provider keeps); **booking_sources** are the share of the period's bookings from each of `direct`, `category`, `recommendations`, `social`, adding up to exactly 100. The default period is the last 30 days; a period is at most a year. `?source=` (or the `X-Booking-Source` header) on a visit or a booking says how the customer arrived; anything else is `direct`.
- **Seeders are deterministic.** The bulk seeders draw from **their own random engine** (`BulkSeeder::$random`), never from PHP's global generator. Faker's destructor reseeds the global generator whenever it is destroyed, and a seeder that depended on it gave different data from run to run depending on how much garbage earlier code had left. `SeederDeterminismTest` recreates that on purpose.

## 4. Decisions (final business decisions of 7 November)

| Topic | Decision | State |
|---|---|---|
| Currency | SAR; no real EGP data, nothing is converted | **Done** |
| Money arithmetic | PHP floats rounded to two decimals; `DECIMAL(12,2)` storage | **Done** |
| Commission | Per provider, `percentage` or `fixed`, same for all their activities; no per-activity rates | **Done** |
| Earnings availability | A configurable waiting period | **Done** (default 0, see section 6) |
| Withdrawals | The provider asks; the amount is reserved at once; an admin pays by hand and updates the status | **Done** |
| Coupons | Provider-created and provider-funded; no platform-funded coupons or promotions | **Done** |
| Reviews and replies | One reply stands per review; edit and delete (kept for audit) | **Done** |
| Analytics | Page views, conversion, ratings, response rate, top activities, booking sources; revenue from the ledger | **Done** |
| Activity publishing | Admin approves the **provider** only; the provider creates, edits and publishes; `ACTIVITY_AUTO_PUBLISH` decides whether a publish request is live at once or kept hidden until the provider activates it; an admin can pause or deactivate, tracked separately from the provider's own pause | **Done** |
| Verification documents | Admin configures the document types, each mandatory or optional, can request an update, reviews, approves, rejects; the provider sees statuses, uploads, replaces | **Done** |
| Booking accept/decline | Replaced by the provider cancelling a confirmed booking | **Done** |
| Availability | Stays per activity | **Done** |
| Customer tiers | The spec's weights, thresholds configurable | **Done** |
| Another provider's resource | `404 RESOURCE_NOT_FOUND` | **Done** |

## 5. Where the spec collides with routes that already exist

| Spec | Existing | Differences |
|---|---|---|
| `GET /provider/profile` | `GET /provider/profile` | the spec's has `category_ids`, `commercial_data_status`, `logo`; the platform says `verified` |
| `GET, POST /provider/activities`, `GET, PUT /provider/activities/{id}` | the same paths | uuids, richer fields, options, pricing rules |
| `/provider/verification` | `/provider/documents` | both exist: the older one lists and uploads files as they come (several per type, as before) and now shows each document's review status; the new one is the spec's contract, keyed by document type |
| `/provider/bookings` | `/provider/reservations` | both exist; the new one is the spec's contract |

## 6. Still to be confirmed (each is configurable or neutral until then)

1. **The waiting period** and its default value: configurable, **0** for now.
2. **Gateway fees**: whether they apply and who bears them. The withdrawal fee exists as configuration and is 0.
3. **Bank account changes**: whether they need admin approval. Today they do not.
4. **Bank account fields**: holder name, bank, IBAN, optional SWIFT are my choice.
5. **Capacity per departure**: today `max_participants` belongs to the activity. Not touched.

6. **Coupon codes: unique among the provider's own** (my choice; the spec leaves platform-wide or provider-wide to confirm). Two providers can both have `SUMMER20`.
7. **A coupon with no activities listed works for all of the provider's activities** (the spec asks to confirm this).
8. **The definition of conversion** (bookings over page views, a cancelled or expired booking not counted): the spec asks for it to be defined, and this is my definition.

## 7. Limitations

- **Publishing does not check that an activity is complete** (photos, schedule, pricing). The admin review that used to catch that is gone, and nothing replaces it yet.
- **The `approved` status is kept** for an activity that is ready but hidden, because renaming it means altering a database enum. The provider app calls it `ready`; the admin sees "Ready (hidden until the provider activates it)". `pending_review` and `rejected` can no longer be reached; a migration moved what was in them (a waiting activity goes live or becomes ready, as `ACTIVITY_AUTO_PUBLISH` says; a rejected one becomes a draft).
- **There is no admin API for suspending**: it is done in the admin panel, so the Postman collection cannot demonstrate it; the tests cover it.
- **Document titles in the provider API come back in English**: the platform picks a language only from the admin and public sessions. The Arabic names exist and are shown to admins in Arabic.
- **Approving a provider does not require its documents to be approved first.** Verifying the provider approves the ones still waiting; an admin who wants to hold a provider back until each mandatory document is approved has to do it by hand.
- **Which documents are asked for does not depend on the provider's activity type or jurisdiction** (the spec lists that as open): one list for everyone.
- **A document's expiry is optional and set by the admin when approving**; nothing is sent when one is about to expire, it simply shows as `expired` afterwards.
- **`update_requested` is a status of its own** (not in the spec's list): it is what tells a provider with an approved document that an admin wants a new one.
- **Coupons have no admin screen.** An admin cannot see or deactivate a provider's coupons from the panel; the provider manages them through the API.
- **The provider's bookings list does not show the coupon** a booking used (the discount is in the booking's totals and the ledger).
- **A customer is not notified when a provider replies to their review**; they see the reply on the review.
- **The booking source is what the client says it is** (`?source=` or the header); it is not verified, so it is as trustworthy as the apps that send it. Bookings made before this existed have no source and count as `direct`.
- **Page views are kept without limit** (one row per visitor per day per activity); there is no clean-up of old ones.
- **A review's average rating in the analytics is over all time**, not the period; the response rate is for the period.
- **Coupons and the customer web site:** the customer web pages do not offer a coupon field; coupons are used through the customer API.
- **The dashboard's response rate is about reviews, not messages**: providers have no messaging yet. `booking_rate` is about bookings that were made; the app has no count of booking *requests* that were never made.
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
- **Dashboard and bookings.** The dashboard has trends, weekly revenue, performance and the rating distribution; the bookings list has `filter=this_week` and `date`. **A bug fixed on the way:** on SQLite the ledger keeps a day as `2026-11-07 00:00:00`, and every revenue range was compared as text, so a trip on the **last day of a period** (a month, a week, a year, a statement, an analytics period) was left out of it. Ranges are now compared by date. New setting `PLATFORM_WEEK_STARTS_ON`.
- **Coupons, reviews, analytics.** New tables `coupons` (with `activity_coupon`), `coupon_redemptions`, `review_replies`, `activity_views`; `reservations.source`. `ReservationService::create` takes a coupon code and the source; the ledger's booking income records the discount; the public activity pages record a page view; the reservation response shows the coupon. A named rate limiter for each new write endpoint and for the coupon check. Four bulk seeders (coupons, redemptions applied before the payments, review replies, page views) and a demo seeder `ProviderDemoReviewSeeder` (run after `ProviderMonthlyReservationSeeder`). `NotificationFailureIsolationTest` now tells its queue worker not to quit on memory: it quit once a full test run had used 128 MB, which these tests did not depend on by design.
- **Verification documents.** New tables `verification_document_types` and `provider_document_events`; `provider_documents` gained `status`, `review_note`, `reviewed_by_user_id`, `reviewed_at`, `expires_at` and `superseded_at` (existing documents are backfilled: a verified provider's are approved, the rest pending, with their history). `config/providers.php` no longer lists the types; everything reads them through `VerificationDocumentTypes`. Replacing a document now keeps the old version instead of deleting it (so three older tests that expected deletion were updated). The older `/provider/documents` API shows each document's status. Three bulk seeders (document types, reviewed documents, their history).
- **Activities.** `ActivityPublishingService` replaces `ActivityModerationService` and `LiveActivityContentGuard`; the admin approve, reject, publish and deactivate actions (and the `activities.approve`, `activities.reject`, `activities.publish` permissions) are replaced by suspend and lift (`activities.suspend`); the approval notifications and events (approved, rejected, sent back to review, published) are replaced by suspended and suspension-lifted. Migrations: the four suspension columns on `activities`, and the retirement of `pending_review` and `rejected`. The demo and bulk seeders create admin-suspended activities.
