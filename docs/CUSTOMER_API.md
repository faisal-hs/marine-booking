# Customer app API

Built to the *Customer App API Specification* (34 operations). It is separate from the older `/api/v1/customer/*` and `/api/v1/public/*` routes, which stay as they are.

- **Prefix:** `/api/v1/customer-app/…` (routes in `routes/api_customer_app.php`).
- **Postman:** the whole project is in `postman/Marine-Booking-API.postman_collection.json`; this API is its folder **12 - Customer app API**.
- **Language:** the `Accept-Language` header: `ar` is Arabic, anything else English. It decides the names of cities, the pages, the FAQs, notification titles and coupon and cancellation messages.

## 1. Response shape

Success: `{"status": true, "message": "Success", "data": …}`, and for a page also `"meta": {current_page, last_page, per_page, total}`. Failure: `{"status": false, "code": "…", "message": "…", "errors": {…}}`.

| Status | When | Codes |
|---|---|---|
| 401 | no token, or a token that is no longer valid | `UNAUTHENTICATED` |
| 403 | a token that is not a customer's (for example a provider's) | `FORBIDDEN` |
| 404 | the thing does not exist, is not visible, or is another customer's | `RESOURCE_NOT_FOUND` |
| 409 | a conflict in state | `SLOT_UNAVAILABLE`, `BOOKING_NOT_CANCELLABLE`, `REVIEW_NOT_ALLOWED` (a repeat), `ACCOUNT_HAS_ACTIVE_BOOKINGS`, `ACCOUNT_IS_PROVIDER` |
| 422 | invalid input or a rule | `VALIDATION_ERROR`, `IDEMPOTENCY_KEY_REQUIRED`, `ACTIVITY_NOT_BOOKABLE`, `REVIEW_NOT_ALLOWED` (not yet allowed) |
| 429 | rate limited | `TOO_MANY_REQUESTS` |
| 502 / 503 | a refund failed / payment is unavailable | `REFUND_FAILED`, `PAYMENT_UNAVAILABLE` |

**Ownership.** The customer is always the signed-in one: no endpoint takes a customer id. Another customer's booking, conversation, notification or favourite is `404`, the same answer as one that does not exist, so ids cannot be probed.

## 2. The 34 operations, and the coupon check

Open = no token needed (a customer's token only makes it personal). Customer = a customer's token.

| # | Operation | Access | Notes |
|--:|---|---|---|
| 1 | `GET /home` | open | categories, featured, destinations, popular, unread count |
| 2 | `GET /activities` | open | search: `q, category_id, city_id, min_price, max_price, max_duration, min_rating, sort, page, per_page` |
| 3 | `GET /search/popular` | open | a configured list |
| 4 | `GET /filters` | open | cities and categories that have something to book, the price range, the sorts |
| 5 | `GET /activities/{id}` | open | the full page; counts as a page view for the provider. Has `price_unit`, `reservation_type` and `price_options` |
| 6 | `POST /favorites/{activity_id}` | customer | idempotent |
| 7 | `DELETE /favorites/{activity_id}` | customer | idempotent |
| 8 | `POST /conversations` | customer | starts or returns the conversation (201 or 200) |
| 9 | `GET /activities/{id}/reviews` | open | `rating` filter; includes the provider's reply |
| 10 | `POST /activities/{id}/reviews` | customer | `booking_id, rating, text` |
| 11 | `GET /activities/{id}/availability` | open | **every day of a month** (`month`, else the current one), or a window (`from, to`); each departure with its `id`, times and places left; `booking_option_id`; has `booking_options` (every option with its adult and child price, or its block of time and what the shortest booking costs) |
| 12 | `POST /bookings/summary` | customer | the price of a booking of any reservation type, with the coupon; books and uses nothing; **two kinds**: by persons (adult and child) or by time (`slot_id`, `from`, `to`) |
| 13 | `POST /bookings` | customer | needs an `Idempotency-Key` header; same fields as the summary |
| 14 | `GET /bookings/{id}` | customer | |
| 15 | `GET /bookings` | customer | `status` filter, counts over all trips |
| 16 | `POST /bookings/{id}/cancel` | customer | the refund is the backend's |
| 17 | `GET /favorites` | customer | |
| 18 | `GET /notifications` | customer | |
| 19 | `POST /notifications/{id}/read` | customer | idempotent |
| 20 | `POST /notifications/read-all` | customer | |
| 21 | `GET /conversations` | customer | latest first |
| 22 | `GET /conversations/{id}/messages` | customer | newest first, 30 a page; marks the provider's messages on the page read |
| 23 | `POST /conversations/{id}/messages` | customer | |
| 24 | `GET /profile` | customer | |
| 25 | `POST /auth/logout` | customer | this device only |
| 26 | `POST /profile/update` | customer | partial; JSON or form (avatar is a file); `email`, `sex` (`male`/`female`) and `city` can be set, and taken off with `null` |
| 27 | `DELETE /profile` | customer | `confirm: true` |
| 28 | `GET /pages/cancellation-policy` | open | built from the real policy |
| 29 | `GET /settings` | open | support phone, email, WhatsApp |
| 30 | `POST /support/tickets` | customer | optional attachment (file) |
| 31 | `GET /faqs` | open | `category` filter |
| 32 | `GET /pages/terms` | open | |
| 33 | `GET /pages/privacy` | open | |
| 34 | `GET /about` | open | `platform` (ios, android) and the app's `version` |
| 35 | `POST /activities/{id}/coupons/validate` | customer | a coupon checked for the activity: its rules, and what it takes off when the booking (or an `amount`) is given. Limited to 20 a minute |

Sign-in, one-time codes and the payment webhook are not part of the 34: they are the existing `/auth/customer/*` and `/webhooks/payments/{gateway}` routes.

## 3. Rules

### Which activities a customer sees
An activity is **visible** when it is published, not suspended by an admin, and **bookable**: it has a default booking option with a price. The default option is the active one the provider marked default (else the first), **of any booking model**: the same rule the provider app uses. (The customer app used to show per-person activities only; an activity with only per-unit, per-hour, per-day, per-trip, per-group, fixed or package options is now listed and bookable, and priced as its option says.) A rule of the wrong kind for its option (an adult price on a per-unit option, say) is not a price, so such an activity stays hidden. Home, search, details, availability, favourites, similar activities and conversations answer only for visible activities.

### Price and badges
`price` is the lowest active price of the default option: the adult price for a per-person one, the price of the unit, the hour, the day or the whole booking for the others; `price_unit` (`per_person`, `per_unit`, `per_hour`, `per_day`, `per_trip`, `per_group`, `fixed`, `package`) says which, and `reservation_type` what a booking is made of (below). Cards and the activity page both have them. There is no "original price" in the platform, so `original_price` and `discount_percent` are null. `booked_count` is the confirmed and completed bookings of the activity; `is_bestseller` is `booked_count >= BESTSELLER_MIN_BOOKINGS`. `is_premium` means an admin featured the activity (the Feature button in the admin's Activities list). `popular` means most booked. A customer's name on a review is shown as "Sara A."; a deleted customer's is "Deleted customer".

### Fields the platform has no data for
Returned empty rather than invented: `weather` (null: no weather service), `languages` (empty), `rating_categories` (null), `host.response_rate` (null: nothing measures it), `is_online` in a conversation (null: no presence service), the meeting point's `note` (null).

### Reservation types and price options
What a booking is made of is the **reservation type** of its price option, in four words (`booking_model` stays the exact model):

| `reservation_type` | Booking models | What to send with the booking |
|---|---|---|
| `persons` | `per_person` | `participants` `{adult, child, infant}`, or `guests` (that many adults) |
| `units` | `per_unit` | `quantity` |
| `time` | `per_hour`, `per_day` | by time: `slot_id`, `from` and `to` (a window; `per_hour`, see "Booking by time"), or `duration` (hours or days, as the option counts: `duration_unit`) |
| `fixed` | `per_trip`, `per_group`, `fixed`, `package` | nothing; `guests` / `participants` may be sent for the places the booking takes |

`GET /activities/{id}` has `price_unit`, `reservation_type` (of the default option) and `price_options`: every active option with its `id`, `name`, `reservation_type`, `booking_model`, `is_default`, its limits (`min_participants`, `max_participants`, `min_quantity`, `max_quantity`, `min_duration`, `max_duration`, `duration_unit`) and its `prices` (`{type, label, price, currency}`: adult, child and infant for persons; `unit`, `hour`, `day`, `trip`, `group`, `fixed` or `package` for the rest). `has_departure_prices` is true when the provider priced a particular departure differently: the exact price of a booking is always what `POST /bookings/summary` answers. The provider app's `GET /provider/activities/{uuid}` has the same `reservation_type` and `price_options` (with the switched-off options marked `is_active: false`).

### Availability
`GET /activities/{id}/availability` lists **every day** of a month: `month` (`YYYY-MM`) asks for one, from this month to twelve months ahead, and the **current month** is the answer when nothing is asked; `from` and `to` (at most 62 days) give a window instead (a `from` alone is 14 days). A day with nothing to book (gone, closed by the provider, or full) is listed all the same, with `is_available: false` and no slots, so a calendar can draw the whole month. A departure has its `id` (what a booking names it by), `time`, `end_time`, `spots_left` (null where there is no limit) and `is_available`. The places are those of the default price option, or of `booking_option_id`; for a per-unit option they are units. The answer starts with `timezone`, `from`, `to`, `booking_option` and `max_guests`.

**`booking_options`** (new) lists every active booking option a customer can choose, flat, so one call shows what can be booked and what it costs: `id`, `name`, `reservation_type`, `booking_model`, `price_unit`, `is_default`, `currency`, and
- for a booking **by persons**: `adult_price`, `child_price`, `infant_price` (the others `null`);
- for the others: `price`, the one price (of a unit, of a block of time, of a day, of the whole booking);
- for a booking **by time**: `increment_minutes` (the block it is made of, and what `price` is the price of), `min_minutes` (the shortest booking) with **`min_minutes_price`** (what the shortest costs), and `max_minutes` (the longest, or `null`).

A departure of an option booked by time also has `booked_windows` (the times already taken inside it, `{from, to}`, so an app can show what is free) and `ends_next_day` (true for a period that runs past midnight, 20:00 to 05:00). The departures of the other options are as they were.

### Booking by time (from and to)
A booking option booked by time is configured by the provider (docs/PROVIDER_API.md): the **shortest booking in minutes**, which is also the **block** a booking grows by, and the **price of one block**; optionally the longest. 30 minutes at 200 means 30 minutes costs 200, 60 costs 400, 90 costs 600, 120 costs 800. The price is the number of blocks times the block price: it is **not** prorated by the minute, and a length that is not a whole number of blocks is refused.

`POST /bookings/summary` and `POST /bookings` take it as `slot_id` (the operating period, as the availability lists it), `from` and `to` (`"2026-11-28 20:30"`, in `PLATFORM_TIMEZONE`; ISO `T` and seconds are accepted), and, if there are several, `booking_option_id` (an option booked by time; the default one if it is, else the activity's one that is). `booking_type` may be sent as `time_window` (or `persons`, for a booking by persons); it is worked out from what is sent, and every answer says it. The summary answers `details.from`, `to` and `minutes`, and `lines`: `{type: duration_unit, label: "30 minutes", quantity: 3, unit_minutes: 30, unit_price: 200, total: 600}`; an option in whole hours (blocks of 60 minutes) has `type: hour`, as it always had.

- **Inside the operating period.** A window must be wholly inside the period. A period may **cross midnight**: 20:00 to 05:00 is one period, a window may cross midnight too, and one that starts after midnight (02:00) belongs to the period that started the evening before: its operating day is that evening's date (`slot.date`, and `date` may be sent and must then be that). The period runs on the weekday it starts.
- **Length.** A whole number of blocks, from the shortest to the longest the option allows. A window may start at any minute.
- **Future, and not closed.** It must start after now, on a day the provider has not closed.
- **Free.** Windows of one option never overlap, wherever they are (the same period, another period of the option, or the next day of an overnight one): `409 SLOT_UNAVAILABLE` says which time is taken. Windows that only touch (one ends at 11:00, the next starts at 11:00) are fine.
- **An unpaid booking holds its time** until its hold runs out (`RESERVATION_HOLD_MINUTES`), like a place; a confirmed one holds it for good. **A cancelled, expired or refunded one gives it back at once.**
- **Safe when several customers book at once.** The check is made again inside the booking, under a lock on the option, so two customers asking for overlapping times get one booking and one `409`; a customer who sends the same booking twice (one `Idempotency-Key`) gets the booking that was made, not a conflict.
- **The booking** (`GET /bookings/{id}`) has `booking_type`, `from`, `to`, `duration_minutes` (all `null` for a booking by persons), and its card is priced per block (`price_unit: per_30_minutes`).

### Coupon check
`POST /activities/{id}/coupons/validate` checks a coupon (`coupon_code`, or `code`) for the activity, before it is used. With nothing more it checks the coupon's own rules (it exists, is on, has not expired, is for this activity, has not been used up). Given the booking (`date` with `time` or `slot_id`, and what it is made of: the summary's fields) it prices the booking as the summary does and answers what the coupon takes off; given an `amount` it does the same for that price. The answer is `{code, is_valid, message, type, value, max_discount, min_order_amount, currency, subtotal, discount, service_fee, vat, total}`. **A coupon that cannot be used is a normal `200` with `is_valid: false` and why**, as in the summary; an unknown code, a switched-off one and another provider's are answered alike (`This coupon code is not valid.`), so the answer never tells which codes exist. Checking uses nothing up. Limited to 20 a minute for each customer, so it cannot be used to guess codes.

### Checkout
- A booking is for a `date` and a departure named by its `time` or its `slot_id` (the id the availability gives; the id wins when both are sent), a price option (`booking_option_id`, else the default one) and what it is made of (above). A booking is for a departure that is one of the activity's schedule periods, **in the platform timezone** (`PLATFORM_TIMEZONE`, default Asia/Riyadh); `date_time` in answers is UTC. A departure that does not exist, is on a closed day, or has already left is `409 SLOT_UNAVAILABLE`.
- **Capacity** is the option's maximum, else the activity's, else no limit, less the confirmed bookings and the unexpired holds on that departure: the same count the booking itself uses. More guests than the departure can ever take is `422` on `guests`, a quantity beyond the option's maximum is `422` on `quantity`, and a duration outside its limits `422` on `duration`; fewer places (or units) than asked left is `409`.
- **Price** = the price of what the booking is made of: the guests by kind, the units, the hours or days, or the one price. The summary answers `reservation_type`, `booking_option`, `slot`, `details` and the `lines` the price is made of, besides `unit_price`, `guests`, `subtotal`, `discount`, `service_fee`, `vat`, `total` and `coupon`. **Service fee** = `CUSTOMER_SERVICE_FEE_PERCENT` of that price *before the coupon*. **VAT** = `CUSTOMER_VAT_PERCENT` of (price − coupon + service fee). **Total** = price − coupon + service fee + VAT, each amount rounded to two decimals. Both percentages are **0** until the business confirms them; the specification's example (450 × 2, a 20% coupon, 3% fee, 15% VAT) comes out as 900 − 180 + 27 + 112.05 = 859.05.
- **The service fee is the platform's and the VAT the tax authority's: a provider earns the price after their own coupon** (`Reservation::providerAmount()`), and the wallet ledger, the provider's booking list, customer spend and revenue projection all use it. A refund of part of what the customer paid takes back the same share of the provider's amount.
- **Coupons** follow the provider rules (docs/PROVIDER_API.md): provider-funded, per provider, one per booking, never below `COUPON_MINIMUM_TOTAL`. The summary **reports** a coupon that cannot be used (`coupon.is_valid: false` and why) and prices without it; creating the booking **refuses** it (`422` on `coupon_code`). Neither the summary nor availability uses anything up.
- **Idempotency.** `POST /bookings` needs an `Idempotency-Key` header (up to 80 characters, new for each attempt). The same key again returns the booking the first request made (200, not 201) and books and charges nothing more; keys are per customer. Without one: `422 IDEMPOTENCY_KEY_REQUIRED`.
- **Payment methods.** `payment_method` is `card`, `apple_pay`, `mada`, `tabby` or `tamara`. **Tabby and Tamara are accepted only where the provider turned them on for that activity** (the activity page says so in `allowed_payment_methods: {tabby, tamara}`); otherwise the booking is refused with `422` on `payment_method` and nothing is booked or charged. No Tabby or Tamara gateway is connected yet: the method is recorded on the booking.
- **Payment.** The booking starts a payment with the configured gateway and answers `booking_id, reference, status, total, payment_id, payment_status, redirect_url`. It is **confirmed only when the payment is** (by the gateway, never by the app), so with a gateway that confirms at once `status` is already `upcoming`; otherwise `pending_payment` and the booking holds its places for 15 minutes. If payment cannot even be started (`503`) the booking is let go at once.
- `simulate` (`sync_success`, `sync_failure`, `async`) is read only by the mock gateway, as in the older payment endpoint; the mock gateway is refused in production.

### Booking statuses
The platform's are pending, confirmed, cancelled, expired, completed. The customer sees:

| Customer sees | Is |
|---|---|
| `pending_payment` | pending, hold not run out |
| `upcoming` | confirmed (it stays upcoming until completed) |
| `completed`, `cancelled`, `expired` | as they are; a pending booking whose hold ran out is `expired` before the system marks it |

`reference` is `BH-{yymmdd of the trip}-{id, 4 digits}`. `can_cancel` and `can_review` are decided by the backend. The provider's phone is shown in a booking once it is confirmed, not before.

### Customer cancellation
More than `CANCELLATION_FULL_REFUND_HOURS` (24) before the trip: everything paid comes back; more than `CANCELLATION_PARTIAL_REFUND_HOURS` (12): `CANCELLATION_PARTIAL_REFUND_PERCENT` (50%); closer: nothing; after the trip has started it cannot be cancelled. **The activity page, the cancellation page, the FAQ and the cancel itself all read this one policy**, so they cannot disagree. A provider cancelling always refunds everything (the provider app's rule). The refund is a share of what was actually paid, service fee and VAT included; it happens together with the cancellation or not at all (`502 REFUND_FAILED`, the booking stays), under a lock so two taps refund once. Cancelling gives the places and the coupon use back; a second cancel is `409`. The refund notification and the wallet follow by themselves.

### Reviews
The booking must be the customer's, for the activity in the URL, and **completed** (`422 REVIEW_NOT_ALLOWED` before that). One review per customer and activity (`409` for a repeat). A completed trip can be reviewed even if the activity was taken down since. The provider's reply is in every review.

### Messaging
One conversation per customer, provider and activity; the provider must be verified and the activity theirs and visible. Messages are newest first, 30 a page; opening a page marks the provider's messages on it as read (there is no separate receipt). **Providers cannot reply yet** (the provider app's messaging is not built), so a conversation is one-sided until it is.

### Notifications
The customer account's feed only (a person who is also a provider keeps the two apart); the home screen's unread count is the same number. `id` is a UUID (a notification is not a counter), `type` is `booking`, `payment` or `general`, `title` is by kind and language.

### Profile and account
`is_verified` is the phone (that is how a customer signs in); `phone_verified` and `email_verified` are reported separately. A new email has to be verified again. **Deleting an account** needs `confirm: true`, and is refused (`409`) while there is a pending or confirmed booking, or if the phone also has a provider account. Otherwise the person is removed: the name, phone, email, avatar and the contact details on bookings are gone, favourites, devices and conversations are deleted, every sign-in is revoked, and the bookings, payments and reviews stay without the person (accounting needs them). The number can sign up again.

### Support and content
Support tickets are kept with an optional attachment (jpg, png or pdf up to 5 MB, stored privately under a name we choose). The pages, FAQs and about screen are in `lang/{en,ar}/customer_app.php`; the support contact, app versions and update date are settings. `force_update` is true only when the app says its `version` and it is below the platform's minimum.

## 4. The specification's open decisions (section 14)

| # | Topic | What was done |
|--:|---|---|
| 1 | Authentication | the existing `/auth/customer/*` routes; only logout is in this API |
| 2 | Prefix | `/api/v1/customer-app` |
| 3 | Guest checkout | **not allowed**: summary and booking need a customer |
| 4 | Payment | the existing gateway and webhook; the booking answers with the payment state |
| 5 | Pricing | the formula above, percentages 0 until confirmed (**to confirm**) |
| 6 | Coupon minimum | the floor is on the price after the coupon, before fee and VAT (**to confirm**) |
| 7 | Capacity | per booking option (the platform's own model), else the activity's |
| 8 | Timezone | `PLATFORM_TIMEZONE`, returned by the availability endpoint |
| 9 | Cancellation | the 24 / 12 hour policy above (**to confirm**) |
| 10 | Statuses | the mapping above |
| 11 | Review replies | included in every review |
| 12 | Host contact | the phone only once the booking is confirmed |
| 13 | Messaging | no receipts, attachments or closing; one-sided until providers can reply |
| 14 | Notifications | no device or preference endpoints here (the older API has devices) |
| 15 | Verification | `phone_verified`, `email_verified`, and `is_verified` = the phone |
| 16 | Account deletion | the rules above |
| 17 | Support | ticket creation only; no listing or replies |
| 18 | Search | the sort allow-list; popular terms are a configured list |
| 19 | Weather | omitted (null) |
| 20 | App version | per platform, with a minimum and a store link |

## 5. Rate limits

120 reads a minute; 30 summaries; 20 coupon checks; 10 bookings or cancellations (together); 30 conversations and messages (together); 10 reviews; 5 support tickets; 3 account deletions. Each has its own budget.

## 6. Settings (`.env`, listed in `.env.example`)

`CUSTOMER_SERVICE_FEE_PERCENT`, `CUSTOMER_VAT_PERCENT`, `CANCELLATION_FULL_REFUND_HOURS`, `CANCELLATION_PARTIAL_REFUND_HOURS`, `CANCELLATION_PARTIAL_REFUND_PERCENT`, `BESTSELLER_MIN_BOOKINGS`, `SUPPORT_PHONE`, `SUPPORT_EMAIL`, `SUPPORT_WHATSAPP`, `PLATFORM_FOUNDED_YEAR`, `APP_VERSION_IOS`, `APP_MIN_VERSION_IOS`, `APP_STORE_URL_IOS` (and the same for Android), `CONTENT_UPDATED_AT`, `PLATFORM_TIMEZONE`.

## 7. Admin

**Cities** (menu: Cities; permission `cities.manage`): add, edit (English and Arabic names, a picture, order, on or off) and delete when no activity is in the city. **Featuring** an activity (permission `activities.feature`): the Feature button in the Activities list; only a live, unsuspended activity can be featured.

## 8. Limitations

- No admin screen for support tickets (they are stored; there is no list or reply yet).
- Providers cannot read or answer conversations yet.
- A booking by units, or by a number of hours (not a window), takes no check of the provider's own opening hours beyond the departure it is for: the duration is the customer's to choose within the option's limits. A booking by time (from and to) is checked against the operating period and against every other booking of the option.
- The static pages, FAQs and popular terms are edited in the language files, not in the admin.
- The booking `source` is the app's say-so, as in the provider analytics.
- Booking creation is checked for places before it is made, and again inside the booking; two customers racing for the last place get one booking and one `409`.
- Row locks are real on MySQL and ignored by SQLite, so the tests run on logic, not on simultaneous connections. **Bookings by time** are checked under real simultaneous connections by `tests/Concurrency/check_booking_concurrency.py` (run it on the database you will use). **Bookings by persons are not yet guaranteed under heavy simultaneous load on MySQL**: when many customers take the last places at the very same instant the places can be over-sold and some requests can fail with a deadlock error (see FILES.md).

## 9. Data

The demo seeder makes six cities and places every demo activity (about one in seven featured). The bulk seeders make cities, conversations, messages and support tickets, apply coupons to bookings before the payments, and give activities a city and a featured flag. `ProviderDemoReviewSeeder` (run after `ProviderMonthlyReservationSeeder`) makes the demo reviews.
