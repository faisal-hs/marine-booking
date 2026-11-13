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

## 2. The 34 operations

Open = no token needed (a customer's token only makes it personal). Customer = a customer's token.

| # | Operation | Access | Notes |
|--:|---|---|---|
| 1 | `GET /home` | open | categories, featured, destinations, popular, unread count |
| 2 | `GET /activities` | open | search: `q, category_id, city_id, min_price, max_price, max_duration, min_rating, sort, page, per_page` |
| 3 | `GET /search/popular` | open | a configured list |
| 4 | `GET /filters` | open | cities and categories that have something to book, the price range, the sorts |
| 5 | `GET /activities/{id}` | open | the full page; counts as a page view for the provider |
| 6 | `POST /favorites/{activity_id}` | customer | idempotent |
| 7 | `DELETE /favorites/{activity_id}` | customer | idempotent |
| 8 | `POST /conversations` | customer | starts or returns the conversation (201 or 200) |
| 9 | `GET /activities/{id}/reviews` | open | `rating` filter; includes the provider's reply |
| 10 | `POST /activities/{id}/reviews` | customer | `booking_id, rating, text` |
| 11 | `GET /activities/{id}/availability` | open | `from, to`: days, departures, places left |
| 12 | `POST /bookings/summary` | customer | the price, with the coupon; books and uses nothing |
| 13 | `POST /bookings` | customer | needs an `Idempotency-Key` header |
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
| 26 | `POST /profile/update` | customer | partial; JSON or form (avatar is a file) |
| 27 | `DELETE /profile` | customer | `confirm: true` |
| 28 | `GET /pages/cancellation-policy` | open | built from the real policy |
| 29 | `GET /settings` | open | support phone, email, WhatsApp |
| 30 | `POST /support/tickets` | customer | optional attachment (file) |
| 31 | `GET /faqs` | open | `category` filter |
| 32 | `GET /pages/terms` | open | |
| 33 | `GET /pages/privacy` | open | |
| 34 | `GET /about` | open | `platform` (ios, android) and the app's `version` |

Sign-in, one-time codes and the payment webhook are not part of the 34: they are the existing `/auth/customer/*` and `/webhooks/payments/{gateway}` routes.

## 3. Rules

### Which activities a customer sees
An activity is **visible** when it is published, not suspended by an admin, and **bookable**: it has a default booking option (an active per-person one: the one marked default, else the first) with an adult price. The customer app books per-person activities; one with only other booking models is not shown (it still works in the older API). Home, search, details, availability, favourites, similar activities and conversations all use this one definition.

### Price and badges
`price` is the lowest active adult price of the default option. There is no "original price" in the platform, so `original_price` and `discount_percent` are null. `booked_count` is the confirmed and completed bookings of the activity; `is_bestseller` is `booked_count >= BESTSELLER_MIN_BOOKINGS`. `is_premium` means an admin featured the activity (the Feature button in the admin's Activities list). `popular` means most booked. A customer's name on a review is shown as "Sara A."; a deleted customer's is "Deleted customer".

### Fields the platform has no data for
Returned empty rather than invented: `weather` (null: no weather service), `languages` (empty), `rating_categories` (null), `host.response_rate` (null: nothing measures it), `is_online` in a conversation (null: no presence service), the meeting point's `note` (null).

### Checkout
- A booking is for a `date` and a `time` that is the start of one of the activity's schedule periods, **in the platform timezone** (`PLATFORM_TIMEZONE`, default Asia/Riyadh); `date_time` in answers is UTC. A departure that does not exist, is on a closed day, or has already left is `409 SLOT_UNAVAILABLE`.
- **Capacity** is the option's maximum, else the activity's, else no limit, less the confirmed bookings and the unexpired holds on that departure: the same count the booking itself uses. More guests than the departure can ever take is `422` on `guests`; fewer places than that left is `409`.
- **Price** = the price of the guests. **Service fee** = `CUSTOMER_SERVICE_FEE_PERCENT` of that price *before the coupon*. **VAT** = `CUSTOMER_VAT_PERCENT` of (price − coupon + service fee). **Total** = price − coupon + service fee + VAT, each amount rounded to two decimals. Both percentages are **0** until the business confirms them; the specification's example (450 × 2, a 20% coupon, 3% fee, 15% VAT) comes out as 900 − 180 + 27 + 112.05 = 859.05.
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

120 reads a minute; 30 summaries; 10 bookings or cancellations (together); 30 conversations and messages (together); 10 reviews; 5 support tickets; 3 account deletions. Each has its own budget.

## 6. Settings (`.env`, listed in `.env.example`)

`CUSTOMER_SERVICE_FEE_PERCENT`, `CUSTOMER_VAT_PERCENT`, `CANCELLATION_FULL_REFUND_HOURS`, `CANCELLATION_PARTIAL_REFUND_HOURS`, `CANCELLATION_PARTIAL_REFUND_PERCENT`, `BESTSELLER_MIN_BOOKINGS`, `SUPPORT_PHONE`, `SUPPORT_EMAIL`, `SUPPORT_WHATSAPP`, `PLATFORM_FOUNDED_YEAR`, `APP_VERSION_IOS`, `APP_MIN_VERSION_IOS`, `APP_STORE_URL_IOS` (and the same for Android), `CONTENT_UPDATED_AT`, `PLATFORM_TIMEZONE`.

## 7. Admin

**Cities** (menu: Cities; permission `cities.manage`): add, edit (English and Arabic names, a picture, order, on or off) and delete when no activity is in the city. **Featuring** an activity (permission `activities.feature`): the Feature button in the Activities list; only a live, unsuspended activity can be featured.

## 8. Limitations

- No admin screen for support tickets (they are stored; there is no list or reply yet).
- Providers cannot read or answer conversations yet.
- Only per-person activities are bookable in this API.
- The static pages, FAQs and popular terms are edited in the language files, not in the admin.
- The booking `source` is the app's say-so, as in the provider analytics.
- Booking creation is checked for places before it is made, and again inside the booking; two customers racing for the last place get one booking and one `409`.
- Row locks are real on MySQL and ignored by SQLite, so the concurrent-coupon and last-place guarantees are tested on the logic, not on simultaneous connections.

## 9. Data

The demo seeder makes six cities and places every demo activity (about one in seven featured). The bulk seeders make cities, conversations, messages and support tickets, apply coupons to bookings before the payments, and give activities a city and a featured flag. `ProviderDemoReviewSeeder` (run after `ProviderMonthlyReservationSeeder`) makes the demo reviews.
