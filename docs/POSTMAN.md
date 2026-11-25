# Trying the API in Postman, with real seeded values

The Postman collection (`postman/Marine-Booking-API.postman_collection.json`, delivered with the packages) is run with an **environment**: the base address, the demo logins, and the ids and tokens the requests use. Most of those are different in every database (the seeders make new uuids each time, and a token only exists in the database that issued it), so the environment is **made from your own database**:

```bash
php artisan migrate:fresh --seed          # the demo data (the demo accounts, the activities, the reviews ...)
php artisan postman:environment           # writes storage/app/postman/Marine-Booking-Seeded.postman_environment.json
```

Import that file into Postman (Environments > Import), select it, and run any folder of the collection, or the whole collection.

## What is in the file

All 178 variables of the collection. Those the command can fill from the database are filled:

| | |
|---|---|
| The address and the logins | `base_url` (`--base-url`, else `APP_URL`), the admin (`admin@marine-booking.test` / `password`), the demo provider owner (`0511111111`, business "Red Sea Divers") and the demo customer (`0522222222`), `webhook_secret`, and `otp_code` (the fixed one-time code, when the app issues it: see below) |
| Valid tokens | `admin_token`, `customer_token`, `provider_token`: new Sanctum tokens, named and with the abilities that signing in gives, so a request that needs a sign-in works without running the sign-in first |
| A bookable activity | `ca_activity_id`, `ca_provider_id`, `ca_date`, `ca_time`, `activity_uuid`, `activity_slug`, `booking_option_uuid`, `reservation_date`: an activity customers can see, **from a verified provider**, that the demo customer has **not yet reviewed**, and its first departure with room, chosen with the app's own catalog and availability |
| One of each way of booking | `rt_persons_id`, `rt_units_id`, `rt_fixed_id`, `rt_time_id` |
| Things to exist | a **confirmed booking** of the demo customer, paid with the mock gateway (`ca_booking_id`, `reservation_uuid`, `payment_uuid`: what the admin refunds and the support message is about), and a booking **waiting for its payment** (`reservation2_uuid`, `payment2_uuid`, `payment2_reference`: what the signed webhook confirms). They are made through the API with the customer's token; their hold is lengthened to a day |

Everything else (a reservation a request creates, a review it writes) is empty until the collection fills it in while it runs.

## Using it

- **Run a whole folder, or the whole collection:** every folder runs on its own, with only this environment (measured: 0 of 578 requests fail), and the whole collection runs in order (578 executions, 2,480 assertions, 0 failures).
- **Run a single request:** it works alone when it does not need something an earlier request in its flow makes (the list, read and search requests, the sign-ins, the bookings and the payments). Requests that update or delete what a previous request created (an activity, a schedule period, a coupon), or that check what happens after a sign-out (a token that "no longer works"), belong to their flow: run their folder.
- **After a sign-out request**, the token it used is revoked in the database. Run the command again (it takes about a second) for new tokens. Running it again never revokes the earlier ones, and it **reuses** the confirmed and waiting bookings while they are still what they were made to be (a cancelled booking, a payment already confirmed or refunded is replaced), so it does not take more places of the activity each time.
- **The one-time code.** The sign-in requests use the fixed code (`OTP_DEFAULT_CODE`), which the app only issues locally or when `OTP_DEFAULT_CODE_ENABLED=true`. When it is not in use the command says so and leaves `otp_code` empty: the real code arrives by SMS and cannot be put in a file.
- **Options:** `--output=PATH`, `--base-url=URL`, `--admin-email`, `--admin-password`, `--provider-phone`, `--customer-phone` (if you seeded other demo accounts), and `--without-fixtures` (make no bookings; the variables that need them stay empty).

## Safety

The command mints API tokens, so **it refuses to run in production** (and makes nothing). The file it writes holds those tokens and is readable by its owner only (`0600`); it is written to `storage/app/postman`, which the web server does not serve. Do not commit it.

## The activities of providers waiting for verification

The seeders create providers still waiting for verification, some with published activities. The customer catalog lists those activities, but a provider that is not verified has no public page (`GET /customer-app/providers/{id}` is `404` for it). The environment therefore never picks such an activity; the rule itself (whether the catalog should list them) is a decision for you.
