# Payments: Tabby and Tamara

How a booking is paid for with **Tabby** or **Tamara**, how the money is checked, taken and given back, what to configure, and how to test it. Card, Apple Pay and mada have no gateway yet (only the `mock`, which is refused in production).

> **What is and is not verified.** Everything on OUR side is tested: 115 automated tests against a stand-in for each gateway's HTTP API (written from Tabby's and Tamara's public documentation), plus real simultaneous requests on MariaDB. **None of it has been run against Tabby's or Tamara's real servers** (the development sandbox cannot reach them). Run the sandbox plan below with your sandbox credentials before going live. The details to check against the sandbox are listed at the end.

## 1. How a payment is routed

A customer chooses a `payment_method` when booking. `tabby` and `tamara` each have a gateway of their own (`config/payments.php`, `methods`), whatever `PAYMENT_GATEWAY` says; every other method goes to the default gateway.

- The provider must accept the method for the activity (`allowed_payment_methods`: `{tabby, tamara}`); otherwise the booking is refused with `422` on `payment_method`. The same check is made when paying again.
- The gateway must take the booking's currency, and the booking must have something to pay.
- A gateway that is not enabled, or enabled but missing a setting, is **refused** (`503 PAYMENT_UNAVAILABLE`, and the booking is let go at once), never half used. Only outside production, with the default gateway still `mock`, a method whose gateway is not enabled works through the mock, as it did before (so local development needs no credentials). In production it never falls back.

## 2. The lifecycle

1. **Start.** `POST /customer-app/bookings` (or `POST /customer/reservations/{uuid}/payments` to pay again) creates a payment and a checkout at the gateway. The answer has `redirect_url`: where the customer pays. One checkout per booking at a time: asking again gives the same one; a checkout that ran out, or is for another method, is closed first; a booking that is paid cannot be paid again. The payment is tied to the booking, holds the amount as it was when payment started, and can be paid until the booking's hold runs out (`expires_at`).
2. **The customer pays at the gateway.** The gateway sends a **notification** to `POST /webhooks/payments/{tabby|tamara}` and sends the customer back to our **return address** (`GET /payments/{payment}/return/{success|cancel|failure}`, signed), which asks the gateway what happened and sends the customer on to the app (`PAYMENT_RETURN_*_URL`) with `?booking=&payment=&status=` (`paid`, `pending`, `failed`, `cancelled`, `expired`).

   **What the customer sees after returning.** The return address answers, in this order: (1) when `PAYMENT_RETURN_SUCCESS_URL` / `_CANCEL_URL` / `_FAILURE_URL` has an address (an app deep link), the customer is sent there; (2) a client that asks for JSON (`Accept: application/json`, or `?format=json`) gets `{success, message, data: {booking, payment, status, reason, amount, currency, booking_reference, paid_at}}`; (3) anyone else, the customer's browser, gets a **page** (`resources/views/payments/return.blade.php`) with the status (paid, pending, failed, cancelled, expired), the **amount** ("Amount paid" once it is paid), the **payment reference** (`PAY-XXXXXXXXXX`) and the **booking reference** (`BH-yymmdd-nnnn`), the activity, the date or time range, how it was paid and, when it is paid, when. It is in English or Arabic (right to left): the language of the app is signed into the address the gateway is given (`lang`), else the browser's. A payment that is still being confirmed refreshes itself every 8 seconds. `PAYMENT_RETURN_APP_URL` adds a "Back to the app" button. The page never depends on a script or an external stylesheet, is not cached, and escapes everything it shows.
3. **Settling.** A notification only says WHICH payment to look at. The payment is **asked of the gateway** and settled by what the gateway says, never by what the notification says. If the customer approved: the booking can still be paid (pending, hold not run out) -> the money is **captured** and the booking is confirmed. Otherwise the approval is **voided**, so nobody is charged.
4. **Safety net.** `payments:reconcile` (every five minutes) checks payments that are still waiting and settles them, so a notification that never came does not leave a paid booking unconfirmed.

### Payment statuses and reasons

`status` is `pending`, `succeeded`, `failed`, `refunded` or `partially_refunded`. Why a payment did not succeed is `failure_code`:

| `failure_code` | Meaning | Booking |
|---|---|---|
| `declined` | The gateway refused the customer or the amount | stays pending: the customer may try again before the hold runs out |
| `cancelled` | The customer cancelled at the gateway, or the booking was cancelled | as it was |
| `expired` | The checkout or the booking's hold ran out | expires |
| `mismatch` | The amount, currency or order at the gateway was not what we asked: nothing is taken | stays pending |
| `late_payment` | The customer paid after the booking was gone: the approval is voided, or the money refunded in full | not confirmed |
| `error` | Anything else | as it was |

## 3. Safety rules (each is tested)

- **Notifications prove themselves or are refused (`401`, nothing looked at, nothing changed).** Tabby: our secret header, compared in constant time; no secret configured means nothing is accepted. Tamara: the signed token (HS256, with `TAMARA_NOTIFICATION_TOKEN`), as a bearer header or `tamaraToken`, not expired, not `alg: none`.
- **A forged "approved" captures nothing:** the gateway is asked.
- **Amount, currency and our reference** at the gateway are compared with the payment; any difference voids the approval.
- **Idempotent and concurrency-safe.** The same notification twice settles once; ten at once capture once and confirm the booking **once** (two guards: the payment and booking are locked while settling, and the database only lets a still-pending payment or booking be settled, and only the request that does it announces it). A customer who taps "Pay" ten times at once gets one checkout. Tested on MariaDB with real simultaneous requests.
- **Late payments are never silently charged:** voided, or refunded in full. Cancelling a booking or letting its hold run out closes its open checkouts at the gateway.
- **Failures are recoverable:** if the gateway cannot be reached while settling, the notification is answered with an error so the gateway sends it again, and the reconciler tries again. A capture that was refused but had already happened is recognised.
- **Logging:** payment events are logged (ids and states only, `PAYMENT_LOG_CHANNEL`). Keys, tokens and customer details are never logged (tested).

## 4. Refunds

Every refund goes through `PaymentService::refund` (customer cancellation by policy, provider cancellation, the admin refund), to the gateway the payment was made with: full or partial, of a captured payment only. A refund the gateway refuses changes nothing and says so.

## 5. Configuration (`.env`; see `.env.example`)

| Setting | |
|---|---|
| `TABBY_ENABLED`, `TABBY_ENV` | switch it on; `sandbox` or `production` |
| `TABBY_PUBLIC_KEY` | creates the checkout (`pk_test_...` sandbox, `pk_...` live) |
| `TABBY_SECRET_KEY` | reads, captures, refunds, closes (`sk_test_...` / `sk_...`) |
| `TABBY_MERCHANT_CODE` | your country's merchant code (for example `SA`) |
| `TABBY_WEBHOOK_HEADER`, `TABBY_WEBHOOK_SECRET` | the header Tabby repeats with every webhook, and its value (choose a long random one) |
| `TAMARA_ENABLED`, `TAMARA_ENV` | switch it on; `sandbox` (`https://api-sandbox.tamara.co`) or `production` (`https://api.tamara.co`); `TAMARA_BASE_URL` overrides |
| `TAMARA_API_TOKEN` | the merchant API token |
| `TAMARA_NOTIFICATION_TOKEN` | signs the notifications Tamara sends us |
| `PAYMENT_RETURN_SUCCESS_URL` / `_CANCEL_URL` / `_FAILURE_URL` | where the customer lands (app deep link or web); empty: JSON |
| `APP_URL` | must be a public **https** address: the gateways call `APP_URL/api/v1/webhooks/payments/...` |

`php artisan payments:check` reads the settings and says what is missing; `php artisan payments:check --live` also makes one read with each gateway's credentials (nothing is created or charged) so a wrong key shows now. `php artisan payments:tabby:register-webhook` (once for the sandbox keys with `--test`, once for live) registers the webhook address and secret header with Tabby. Tamara needs no registration: the notification address is sent with each checkout.

## 6. Sandbox test plan (to run with your sandbox credentials)

For each gateway: (1) `payments:check --live` is green; (2) book with the method and open `redirect_url`; (3) **approve** with the gateway's sandbox test customer: the booking becomes confirmed, and the gateway shows the payment captured; (4) **reject** (use the sandbox customer that is declined): the payment is `failed`/`declined`, the booking is still pending, and paying again works; (5) **abandon** until the hold runs out: payment `expired`; (6) **approve after the hold ran out** (book, wait 15 minutes, then approve): nothing is charged; (7) **cancel a paid booking**: the refund appears at the gateway; (8) re-send the webhook from the gateway dashboard: nothing changes; (9) confirm the sandbox sends the webhook (Tabby: after `register-webhook`) to your address.

## 6b. When something does not work

Run **`php artisan payments:check`** first. It says whether the payment code is installed completely (the `Payment` and `PaymentEvent` models, the new columns and the `payment_events` table: after copying a package, copy EVERY file and run `php artisan migrate`), and what each gateway still needs.

- **`redirect_url: null` although the gateway answered.** Either the booking request was sent again (the same `Idempotency-Key`): from this version it answers with the live checkout, and `POST /customer-app/bookings/{id}/pay` returns it at any time; or `app/Models/Payment.php` is an old version without `checkout_url` among its fillable attributes: Laravel then silently drops the address Tabby sent. Now the payment fails loudly instead ("`checkout_url` was not saved ... out of date") and `payments:check` names the file.
- **`503 PAYMENT_UNAVAILABLE`.** The gateway is not enabled, is missing a setting, or could not be reached. With `APP_DEBUG=true` the answer says which (`errors.reason`, for example "TAMARA is enabled but is missing: TAMARA_NOTIFICATION_TOKEN."), and the log has the same line. Tamara needs **both** `TAMARA_API_TOKEN` and `TAMARA_NOTIFICATION_TOKEN`; Tabby needs the public and secret keys, the merchant code and the webhook secret. `php artisan payments:check --live` tells a wrong credential from a network problem.

## 7. Details to confirm against the real sandboxes

These are written from the public documentation as understood; a sandbox run is the proof:
- **Tabby:** the checkout is created with the **public** key and the other calls use the **secret** key; the checkout answer's `configuration.available_products.installments[0].web_url`; payment statuses (`CREATED`, `AUTHORIZED`, `CLOSED`, `REJECTED`, `EXPIRED`) and `captures`; `POST /api/v2/payments/{id}/captures|close|refunds`; the webhook carries only the header we registered (no signature); `X-Merchant-Code` and the body of the webhook registration.
- **Tamara:** `POST /checkout` and its required fields; order statuses (`new`, `approved`, `authorised`, `fully_captured`, `canceled`, `declined`, `expired`); the lookup by our reference (`/merchants/orders/reference-id/...`); authorise-then-capture and the capture's required `shipping_info`; `POST /orders/{id}/cancel` (only for an authorised order: an approved order we never authorise simply lapses); `POST /payments/simplified-refund/{id}`; the notification token as a bearer header or `tamaraToken`. No `expires_in_minutes` is sent: the checkout lives as long as Tamara's default.
- **Decisions:** the money is captured as soon as the customer approves (the service is booked); each checkout carries one line at the amount paid (after coupon and fee), so the lines always add up; the booking's hold (`RESERVATION_HOLD_MINUTES`) is the time to pay.
