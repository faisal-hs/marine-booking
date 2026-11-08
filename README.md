# Marine Booking

Backend for booking marine activities: diving, snorkeling, boat trips, fishing and more.
Customers and providers use a mobile app that talks to a JSON API, staff run the platform
from an admin panel, and a bilingual (English / Arabic) public website lets visitors browse.

Built with Laravel 12, Sanctum (API tokens) and Spatie Laravel-Permission (admin roles).

## What is in here

| Surface         | Used by                                                                                                                                    | Where                                           |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------ | ----------------------------------------------- |
| Customer API    | Customers (mobile app): phone + OTP login, reserve, pay, review, favourite                                                                 | `/api/v1/auth/customer/*`, `/api/v1/customer/*` |
| Provider API    | Activity providers (mobile app): phone + OTP login, manage activities, schedules, pricing, upload verification documents, see reservations | `/api/v1/auth/provider/*`, `/api/v1/provider/*` |
| Public API      | Anyone: published activities, categories, availability, reviews                                                                            | `/api/v1/public/*`                              |
| Admin panel     | Staff: staff accounts, providers and their documents, customers, activities (create, edit, moderate), announcements, activity moderation, reservations, payments and refunds, reviews, dashboard, reports          | `/admin` (login at `/admin/login`)              |
| Admin JSON API  | Reservations, payments, reviews and the dashboard as JSON                                                                                  | `/api/v1/admin/*`                               |
| Public website  | Visitors, browse only (booking happens in the mobile app)                                                                                  | `/`                                             |
| Payment webhook | The payment gateway                                                                                                                        | `POST /api/v1/webhooks/payments/{gateway}`      |

`php artisan route:list` shows every route.

## Requirements

- **PHP 8.4 or newer.** `composer.json` says `^8.3`, but the committed `composer.lock` pins
  Symfony 8 packages that need PHP 8.4.1+, so 8.3 cannot install it.
- Composer 2.8 or newer. Older versions cannot run `composer test`: its script uses the
  `@no_additional_args` placeholder, which Composer only understands from 2.8.0. `php artisan test`
  works on any version.
- PHP extensions: ctype, curl, dom, fileinfo, mbstring, openssl, pdo_sqlite, tokenizer, xml and
  **gd** (image uploads and the tests that exercise them)
- SQLite (the default; any database Laravel supports works by editing `.env`)
- Node.js, only for `composer dev` (it uses `concurrently`). The pages style themselves with
  Tailwind from a CDN, so there is no front-end build step.

## Quick start

```bash
git clone https://github.com/faisal-hs/marine-booking.git
cd marine-booking

composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite  # the default SQLite database (or answer yes when migrate offers to create it)
php artisan migrate --seed
php artisan storage:link        # makes uploaded images reachable from the web

npm install                     # once, for composer dev
composer dev                    # web server + queue worker + scheduler
```

Then open <http://127.0.0.1:8000> (public site) or <http://127.0.0.1:8000/admin/login> (admin).

## Demo accounts

`php artisan migrate --seed` creates these. **They are for local development only: see
[Deploying](#deploying).**

| Role          | Email                               | Password   |
| ------------- | ----------------------------------- | ---------- |
| super_admin   | `admin@marine-booking.test`         | `password` |
| admin         | `admin-manager@marine-booking.test` | `password` |
| support_admin | `admin-support@marine-booking.test` | `password` |
| content_admin | `admin-content@marine-booking.test` | `password` |

Customers and providers have no password; they sign in by phone and a one-time code. A fixed one-time
code (`OTP_DEFAULT_CODE`, `1234` in `.env.example`) is controlled by `OTP_DEFAULT_CODE_ENABLED`:
`true` turns it on in **any** environment (for a staging server with no SMS provider yet), `false`
turns it off everywhere (to test real codes locally), and leaving it unset keeps it on in `local` and
`testing` only. Turned on in production it means **anyone who knows a phone number can sign in as that
person**, so every code issued is logged as a warning; switch it off before real users sign in.
It applies to customers and providers alike. The seeded customer is `0522222222` and the seeded provider is `0511111111`:

**Sign-in is rate limited**, because every step either sends a message or guesses a code: asking for a code is limited to 5 per
minute for one phone from one address and 30 per minute for one address in all (so a script cannot walk through many numbers);
resending to 3 per minute; verifying to 5 attempts per minute. Past a limit the answer is 429.

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/customer/otp/request -H 'Accept: application/json' -d phone=0522222222
curl -X POST http://127.0.0.1:8000/api/v1/auth/customer/otp/verify  -H 'Accept: application/json' -d phone=0522222222 -d code=1234
```

The second call returns a bearer token for the other `/api/v1/customer/*` endpoints. Providers
use the same calls under `/api/v1/auth/provider/`.

## Large demo data

`database/seeders/Bulk` fills **every table** with a large, internally consistent data set, for load testing and for
building screens against realistic volumes. It is separate from the small demo data above and can sit beside it.

```bash
php artisan db:seed --class="Database\Seeders\Bulk\BulkDatabaseSeeder"
```

`BULK_SEED_SCALE` sets the size: `1` is the full set (about **1.3 million rows**, 370 MB in SQLite, seeded in about
**75 seconds**), `0.1` is a tenth (about 8 seconds), `0.01` a smoke test. `BULK_SEED_RANDOM` seeds the random
generator: the same scale and seed always produce the same data. **They refuse to run in production.**

There is one seeder per model (26), plus `BulkNotificationSeeder` for the notifications table, and `BulkDatabaseSeeder`
runs them in dependency order after the roles and the twelve demo categories. Each can also be run on its own with
`--class`, and says which seeder to run first if something it builds on is missing. Running one again adds nothing;
raising the scale and running again adds only the difference.

| Seeder | Rows at scale 1 | What it makes |
|---|---:|---|
| BulkCategorySeeder | 40 | extra categories, a few inactive |
| BulkFeatureDefinitionSeeder | 204 | per-category attributes (select, number, boolean, multi-select, text) |
| BulkUserSeeder | 26,540 | 25,000 customers, 1,500 providers, 40 staff; about 2.5% suspended, 1% deleted |
| BulkAdminSeeder | 40 (+40 roles) | staff profiles and roles (always at least one super admin) |
| BulkProviderSeeder | 2,300 | 1,500 registered providers and 800 customers who asked to become one |
| BulkProviderRequestSeeder | 2,462 | approved, rejected and waiting requests, matching each provider's status |
| BulkProviderProfileChangeSeeder | about 370 | approved, rejected and waiting business-name changes; an approved one ends at the provider's live name |
| BulkProviderDocumentSeeder | 6,848 | the three required documents, as REAL small PDFs on the private disk |
| BulkCustomerSeeder | 25,047 | customer accounts, including providers who also use the customer side |
| BulkActivitySeeder | 15,000 | 62% published, plus drafts, waiting, rejected, inactive and 3% deleted |
| BulkActivitySchedulePeriodSeeder | 71,781 | weekly departures, some crossing midnight |
| BulkActivityBookingOptionSeeder | 21,731 | per person, per trip, per hour, package, fixed, per unit, per group |
| BulkActivityPricingRuleSeeder | 36,728 | prices in the platform currency (SAR), by participant type for per-person options |
| BulkActivityImageSeeder | 64,527 | placeholder photo paths (no files), first one the cover |
| BulkActivityFeatureValueSeeder | 58,812 | values of the activity's own category attributes |
| BulkActivityRequirementSeeder | 45,067 | what a guest must bring or meet |
| BulkActivityInclusionSeeder | 67,421 | what is and is not included |
| BulkActivityOptionSeeder | 14,911 | optional extras |
| BulkActivityAvailabilityOverrideSeeder | 16,644 | closed or specially priced date ranges, past and future |
| BulkReservationSeeder | 150,000 | bookings priced from the rules, on real departures, within capacity |
| BulkReservationCapacityLockSeeder | 143,493 | one lock row per option, departure and day that was booked |
| BulkPaymentSeeder | 131,582 | payments that agree with each booking, including failures and refunds |
| BulkReviewSeeder | 25,675 | reviews of completed bookings, and each activity's rating |
| BulkFavoriteSeeder | 120,000 | saved activities, popular ones far more often |
| BulkDeviceSeeder | 17,872 | push devices with made-up tokens |
| BulkOtpSeeder | 60,000 | expired sign-in codes (none can sign anyone in) |
| BulkNotificationSeeder | 208,555 | the in-app feed, built from what happened, using the app's own notification classes |
| BulkBroadcastSeeder | 600 | past announcements (sent, draft, failed) |

Signing in to the seeded data: a staff member's email is `staff` followed by a number from 0 (the exact address is in `BulkUserSeeder`),
and the password is `password`. The first one is a super admin; the next few are admins, then support and content admins. Customers and providers have no password; their phones are `07` + 8 digits
(customers), `06` + 8 digits (providers) and `08` + 8 digits (staff), so they never clash with the demo accounts
(`05...`).

What is real and what is a placeholder: activity photos are paths only, like the demo data, because 65,000 image files
would add nothing a path does not. The provider documents ARE real files (the admin screen offers them for download).
Past announcements record how many people they reached, but their per-user notifications are not generated, which
would add the size of the audience to the notification table for each one.

The seeders are tested for consistency, not just presence (`tests/Feature/Seeders/BulkDataIntegrityTest.php`: a booking's
price adds up and uses the right rule, no day is overbooked, a payment matches its booking, a review follows a
completed one ...), for behaviour (`BulkSeedersBehaviourTest.php`: one seeder per model, repeatable, deterministic,
refused in production), and against the application (`BulkDataInTheAppTest.php`: the public catalogue, customer, provider
and staff sign-in, the admin screens, and a new booking made on top of the seeded ones). They run at 5% scale by default;
`BULK_TEST_SCALE=1 php artisan test tests/Feature/Seeders` runs the same checks over the full set (a few minutes).

## Monthly reservations per provider

```bash
php artisan db:seed --class=ProviderMonthlyReservationSeeder
```

gives **every active provider at least 50 paid reservations in the current month** (`PROVIDER_MONTH_MINIMUM`, default 50), for dashboards,
revenue and the provider app to show real numbers. An active provider is one an admin has approved whose account is active; pending, rejected,
suspended and deleted providers get nothing.

- Each reservation is on a real departure of one of the provider's published activities, on that departure's weekday, priced from the option's own
  pricing rules, and no day goes beyond an activity's capacity.
- Every one is **paid**: a succeeded payment for the full total through the mock gateway. Days before today are `completed`; today and later are
  `confirmed` (a trip is not completed in advance). Customers are active ones, never the provider themselves.
- "At least 50" counts what is already there (confirmed or completed reservations of the month with a succeeded payment, however they were
  created), so running it again adds nothing, and raising the minimum adds only the difference.
- An active provider with nothing bookable first gets one small published activity, "<business> Day Trip" (daily departures, 40 places, adult 300 and
  child 150 SAR). In the demo data that is 5 of the 15 active providers; without it they could never reach the minimum.
- If a minimum cannot fit without overbooking, it stops with a message instead of overbooking.
- It works on the demo data and on the bulk data, ignores `BULK_SEED_SCALE`, and refuses to run in production.

## Running the app

Three processes need to be running. `composer dev` starts all of them; or use three terminals:

```bash
php artisan serve         # web server
php artisan queue:work    # REQUIRED: push, SMS, e-mail and in-app notifications are queued
php artisan schedule:work # REQUIRED: scheduled maintenance, below
```

Scheduled commands (`routes/console.php`):

- `reservations:expire-stale-holds` every five minutes: a pending reservation holds capacity
  for `RESERVATION_HOLD_MINUTES` while waiting for payment, then is marked expired.
- `reservations:complete-past` daily: marks confirmed reservations completed once their date
  has passed. Customers can only review an activity after a reservation is completed.

In production, run the worker under Supervisor or systemd and trigger the scheduler from cron:
`* * * * * cd /path/to/app && php artisan schedule:run`.

## Tests

```bash
composer test        # or: php artisan test
```

The suite is self-contained: in-memory SQLite, log/mock drivers for SMS, push and payments,
no network, and the OTP code is set in `phpunit.xml`, so it does not depend on your `.env`.
GitHub Actions (`.github/workflows/tests.yml`) runs it on every push and pull request, twice:
once with today's date and once with the clock set 400 days ahead.

Six rules keep the suite honest, and each is enforced by a test in the suite:

1. **No hardcoded calendar dates in tests.** The reservation flow rejects past dates, so a test
   that books `2026-09-05` fails once that day has passed. Use `Tests\Concerns\RelativeDates`
   (`futureSaturday()`, `futureSunday()`, `daysFromNow()`).
2. **Test files must be named `<ClassName>Test.php`.** PHPUnit silently skips any other name,
   so the tests in it would never run (`tests/Unit/TestFileNamingTest.php`).
3. **New project environment variables go in `.env.example`**
   (`tests/Unit/EnvExampleCoverageTest.php`).
4. **Every Blade view must compile to valid PHP.** An inline `@php($x = ...)` followed later in the
   same file by a block `@php ... @endphp` compiles without complaint into code that is not valid
   PHP, and only fails when the page is first opened. Use the block form, always
   (`tests/Feature/Admin/BladeViewsCompileTest.php`).
6. **Every notification class is placed** in `NotificationFeed` (customer, provider) or the shared
   list, so each account's feed stays its own (`tests/Feature/Account/DualAccountEffectsTest.php`).
5. **Every admin screen is checked in English and Arabic for untranslated keys**
   (`Tests\Concerns\AssertsTranslated`): a missing key does not fail a page, it prints the key.

## How the code is organised

A request flows `FormRequest` (validation and authorization) → thin controller → **service**
(business rules) → **repository** (interfaces in `app/Repositories/Contracts`, bound in
`RepositoryServiceProvider`) → Eloquent model. Responses go through API resources.

- `app/Http/Controllers/Api/{Auth,Customer,Provider,Public,Admin,Shared,Webhooks}` are the JSON
  API; `app/Http/Controllers/Web/{Admin,Public}` are the Blade pages.
- **Authentication.** Customers and providers use Sanctum bearer tokens with the abilities
  `customer` / `provider`. Staff use the `web` session guard in the browser panel, and a
  short-lived Sanctum token with the ability `admin` on the JSON API (see below). What a staff
  member may do is decided by permissions such as `activities.approve`, `payments.refund` and
  `reports.view`, grouped into the roles `super_admin`, `admin`, `support_admin` and
  `content_admin`.
- **Provider API route files.** `routes/api_v1.php` holds the original provider routes; `routes/api_provider.php` is the file for the provider app's newer ones.
  Both are loaded under `/api/v1`, and a route written in the second at `/foo` answers at `/api/v1/provider/foo` already behind a bearer token, the
  `provider` ability and an approved provider (the header of the file lists the conventions). `ProviderRouteConventionsTest` fails if any route repeats
  another's method and URI or name, or is reachable without those guards.
- **Provider app API status.** `docs/PROVIDER_API.md` lists every operation of the provider app specification, whether it is built, the final business decisions, and what is still
  to be confirmed. The platform currency is SAR (`config/platform.php`). Providers have a **wallet**: a ledger of every movement of their money, a commission set per provider
  (a percentage or a fixed amount), a configurable waiting period before earnings can be withdrawn, and withdrawals that reserve the money at once and are paid by an admin by
  hand (`config/wallet.php`). A provider's change to critical profile information waits for an admin's approval, and providers cancel a confirmed booking with a reason
  (the customer is refunded in full). **The admin approves the provider account only; nobody approves an activity.** An approved provider creates, edits and publishes their own, and `ACTIVITY_AUTO_PUBLISH` decides whether a publish request makes the activity live at once or keeps it hidden until the provider activates it (`config/activities.php`). An admin can suspend any activity (with a reason); that is recorded apart from the provider's own pause, and only an admin can lift it.
- **Refusal comes before lookup.** The `ability:*`, `provider.approved` and staff checks run before route-model binding, so a caller who may not
  use an area gets the same answer whether or not the id exists (it used to be 404 for a made-up id and 403 for a real one, which revealed which
  ids exist, drafts included). `RefusalComesBeforeLookupTest` guards it.
- **Admin JSON API.** `POST /api/v1/auth/admin/login` with `email` and `password` returns a bearer
  token (`token`, `expires_at`, and the staff member's roles and permissions). Send it as
  `Authorization: Bearer <token>` to `/api/v1/admin/*`. The token expires after
  `ADMIN_API_TOKEN_HOURS` hours (8 by default), is revoked by `POST /api/v1/auth/admin/logout`, and
  stops working at once if the account is suspended. A customer or provider token is refused (403)
  however valid it is. Signing in shares the browser panel's rate limit (5 attempts a minute per
  email and address). The API deliberately has no session: a cookie-authenticated JSON API would
  need CSRF protection, and until this token sign-in existed these routes answered 401 over real
  HTTP for everyone, because the `api` middleware group has no session for the `web` guard to read.
- **Availability and pricing are separate services** (`AvailabilityService`,
  `ActivityPricingService`) and are meant to stay that way.
- **Payments** go through `PaymentGatewayInterface` (`app/Contracts/Payments`); a gateway is
  chosen with `PAYMENT_GATEWAY`. See the limitations below before relying on it.

### Provider onboarding and moderation

1. **Becoming a provider takes an admin's approval** (see "Accounts and provider requests"
   below). A provider sends a request with business details and three documents; an admin reads it
   on the provider requests screen (or on the provider's own page) and approves or rejects it. The
   files live on the private disk (`storage/app/private`), are never reachable by URL, and can only
   be downloaded by an admin who may view provider requests. Once approved, the **commercial
   registration, safety certification and insurance certificate cannot be deleted**; a provider can
   still add and remove supporting documents through `/api/v1/provider/documents`.
2. **The gate.** Until approved, a provider account can do nothing on the provider side (every
   `/api/v1/provider/*` route except reading the profile answers 403). Only a verified provider's
   activity can be approved or republished.
3. **Review.** Approving publishes the activity immediately. A rejected activity can be edited and
   submitted again.
4. **Editing a live activity.** Changing the title, description, category, location, photos,
   features, requirements or inclusions of a published activity sends it back to review and takes
   it off the public site until an admin approves it again. The provider is told, and existing
   bookings are not affected. Schedules, blackout dates, prices, extras, participant limits,
   duration and minimum age can be changed freely. Only a real change counts: re-saving an
   unchanged form does nothing, and a deactivated activity is not guarded because an admin has to
   republish it deliberately. Every provider endpoint that changes an activity has to be classified
   in `tests/Feature/Provider/LiveActivityReviewTest.php`, so a new one cannot quietly skip the rule.

### Accounts and provider requests

One phone can hold a **customer account** and a **provider account**. A user's `user_type` is only the
account they *registered* as; `AccountService` decides what they can use now.

| | Customer account | Provider account |
|---|---|---|
| Available | always (created on first use, no approval) | once an admin approves a provider request |
| Token ability | `customer` | `provider` |

- **Signing in.** `/api/v1/auth/customer/otp/*` and `/api/v1/auth/provider/otp/*` open the account they
  are named for, for a person who has it. `/api/v1/auth/otp/*` opens the person's **default account**
  (their own choice, else the one they registered as). A customer phone has no provider sign-in until
  it has a provider account. Every sign-in returns an `account` object: `account_type`,
  `default_account`, `available_accounts`, `provider_request_status`.
- **`GET /api/v1/account`** (either token) returns the account context, the user and the profile;
  **`PUT /api/v1/account/default`** chooses the default; **`POST /api/v1/account/switch`** signs the person
  into the other account: a new token, the old one revoked, and the new account's profile in the
  response. Both profile endpoints also return the `account` object.
- **Asking to become a provider.** A customer, or someone who registered as a provider, sends
  `POST /api/v1/account/provider-request` (multipart): `business_name`, `city`, `area`, optional `bio` and
  `address`, and the files `commercial_registration`, `safety_certification` and
  `insurance_certificate` (PDF or image, up to 5 MB). `GET` on the same path says where they stand
  (`none`, `not_submitted`, `pending`, `approved`, `rejected`, with the reason). A rejected request can
  be corrected and sent again; documents already on file need not be uploaded twice, and a replaced
  document is removed from the database and the disk.
- **The admin side.** Requests appear under **Provider requests** (`/admin/provider-requests`), with a
  badge and a dashboard figure. Only admins holding `provider_requests.view` / `provider_requests.review`,
  which the seeder gives to the `admin` and `super_admin` roles and nobody else, can see or decide them.
  Rejecting needs a reason, which the applicant is shown.
- **Around the rest of the system.** A provider cannot book their own activity. Suspending or
  deleting a person affects both accounts (a delete is refused while either side has a live booking).
  Announcements to "all customers" and "all providers" go by account, and "providers" means approved
  ones. Each account has its **own notification feed**: `NotificationFeed` lists which notification
  classes belong to which account, and a test fails when a new class is not placed.

### Notifications

Services only fire events (`app/Events`). Listeners in `app/Listeners/Notifications` hand the
result to `NotificationDispatchService::dispatchOnce()`, the single place notifications are sent.
It is idempotent (a database unique constraint on `notification_dispatch_log`) and
failure-isolated: a broken push or SMS provider is reported and never breaks a reservation or a
payment.

Customer and provider notifications go out through the database, FCM push, SMS and e-mail
channels. They are queued, one job per channel, with 3 tries and a 30 s then 120 s backoff, so a
failing channel retries on its own without repeating the others. Deliveries that still fail
end up in `failed_jobs` (`php artisan queue:failed`, `php artisan queue:retry all`). Admin
notifications are database-only and are not queued.

Providers are notified when an admin approves, rejects or republishes one of their activities,
when their verification is decided, when an edit sends a live activity back to review, when a
booking is confirmed, and when a confirmed booking is cancelled (in-app and push). An unpaid
pending hold does not alert them. These alerts carry no customer name or contact, because the
provider API does not expose them either.

### Languages

`lang/en` and `lang/ar`, with right-to-left layout for Arabic. The admin panel and the public
site each remember their own language in the session (`admin_locale` and `public_locale`).

## The admin panel

Each area has a list, a detail page and the actions below. A button is shown only to someone who
may use it, and the server checks the same permission again.

| Area | What staff can do | Permission |
|---|---|---|
| **Staff** (`/admin/users`) | List, create, edit, suspend and delete **staff** accounts only | `users.*` |
| **Providers** | List and search, create, edit, suspend, reactivate, delete, restore; read their documents; verify or reject | `providers.view / create / update / delete / verify / reject` |
| **Provider requests** | See and decide requests to become a provider, with their documents; approve or reject (a reason is required) | `provider_requests.view / review`: **admin and super admin only** |
| **Customers** | List and search, create, edit, suspend, reactivate, delete, restore; see their bookings and spending | `customers.view / create / update / delete` |
| **Activities** | List and search, create for a provider, edit (details, features, requirements, inclusions, photos), delete, restore; approve, reject, publish, deactivate | `activities.view / create / update / delete`, and separately `approve / reject / publish` |
| **Notifications** | Your own inbox; a log of every in-app notification sent to anyone; announcements to all customers, all providers or one account | `notifications.view / create / delete` |

How the roles line up: `super_admin` and `admin` can do everything; `support_admin` can look at
providers and customers but not change them; `content_admin` can write activities but cannot
approve or publish them, and sees no accounts or notifications.

Rules that apply across the panel:

- **Delete is always a soft delete** and is **refused while a booking is live**: a confirmed one
  still ahead, or a pending one whose hold is still running. The message says how many. A
  customer's or provider's *history* (bookings, payments, reviews) is never removed. Only the
  user account is soft-deleted, and a deleted account still shows by name, marked "Deleted".
- **Suspending or deleting a provider takes their published activities off the site** (they become
  "inactive"). Reactivating or restoring does not put them back: an admin republishes them, and
  the verified-provider check runs again.
- **Deleting an activity** deactivates it first, so restoring it never republishes it silently.
- A deleted account **cannot sign in**: it gets a clear message, not an error. Restoring it lets
  it in again.
- Staff edits to an activity do **not** send a live activity back to review (only a provider's
  own edits do). The staff are the reviewers.
- **Staff accounts:** only a super admin can create or promote a super admin, only a super admin
  can edit or delete one, and the last active super admin cannot be demoted, suspended or deleted.
- **Announcements** are saved as a draft, then sent once. Sending queues a job (so a queue worker
  must be running) that gives each active recipient an in-app notification and a push. Staff,
  suspended and deleted accounts never receive one. A sent announcement cannot be edited, and
  deleting it does not remove notifications already delivered.
- The notification **log** deliberately lets `notifications.delete` holders remove an entry. That
  also removes it from the recipient's feed in the mobile app, which is why it is a separate,
  stronger permission than viewing.

## Configuration

Everything configurable is in `.env.example`, with a comment on each project-specific
variable: OTP length, expiry and cooldown, reservation hold time, payment gateway, SMS and push
drivers, and branding. A test fails if the code starts reading a variable the example does not
mention.

## Deploying

1. `APP_ENV=production`, `APP_DEBUG=false`, a freshly generated `APP_KEY`, and a real database.
2. `composer install --no-dev --optimize-autoloader`, then `php artisan migrate --force` and
   `php artisan storage:link`, then `php artisan config:cache`, `php artisan route:cache` and
   `php artisan view:cache`. Run the cache commands with `APP_ENV=production` set, because
   `config:cache` freezes the environment it is run in.
3. **Seeding is safe in production.** `php artisan db:seed --force` there seeds only the roles
   and permissions the admin panel needs and skips the demo seeders, and each demo seeder
   (`AdminSeeder` and the others) refuses to run in production if called directly, because they
   create accounts with the password `password`. Add the starter categories and feature
   definitions with `--class=CategorySeeder` and `--class=FeatureDefinitionSeeder` if you want
   them. There is no command to create the first super admin yet: create it by hand (for
   example in `php artisan tinker`), using `database/seeders/AdminSeeder.php` as the template
   for the required fields.
4. Keep a queue worker running under a process manager and the scheduler on cron.
5. Set `SMS_DRIVER=taqnyat` and `FCM_DRIVER=http` with real credentials. A value the
   application does not recognise is rejected with an error; it is never silently sent to the
   real service.
6. **Payments cannot go live yet.** In production the mock gateway is refused and payments
   answer `503` (next section).
7. Back up `storage/app/private` (the verification documents) together with the database, and make
   sure the web server does not serve it.
9. **Providers that registered before the provider request existed** and were never approved will find
   the provider side closed until they submit a request; approved providers are unaffected. Existing
   approved providers have no `city` or `area` until they set them on their profile.
10. Decide on `OTP_DEFAULT_CODE_ENABLED` for every environment. It must be off (or unset) in production
   once real users sign in.
8. After updating an existing installation, run `php artisan migrate` (new tables) and
   `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (new permissions, such as
   `notifications.create`). The seeder is idempotent and safe in production. Without it the new
   buttons never appear for anyone but a super admin.

## Known limitations

These are known and deliberately listed, not hidden:

- **Payments are mock only, so online payments are unavailable in production.** The only
  gateway is `MockPaymentGateway`, which reports payments as successful without charging anyone
  (and the customer payment endpoint accepts a client-sent `simulate` option). In production it
  is refused: paying, refunding and the payment webhook answer `503` and log the reason, so
  nobody can mark a reservation as paid for free. Local, testing and staging environments use
  the mock as before. Reservations can still be created in production but cannot be paid until
  a real gateway is implemented (see `config/payments.php`).
- **There is no command to create the first admin.** Production seeding deliberately creates
  no accounts (see Deploying, step 3), so the first super admin has to be created by hand.
- **The FCM HTTP gateway needs a hand-refreshed OAuth access token** (`FCM_ACCESS_TOKEN`) until
  service-account authentication is implemented (`HttpFcmGateway`).
- **Money is handled as PHP floats and every record carries its currency, and the platform currency (SAR, `config/platform.php`) is used for new ones.**
- **Revenue in the reports is gross.** Payments have no `refunded_at`, so a refund cannot be
  attributed to a period. The all-time figure on the dashboard is net of refunds.
- **Providers cannot see who booked.** Their reservation list and booking alerts show the
  activity, date, party size and total, but no customer name or contact.
- **Verification documents are not virus-scanned.** They are validated by type and size and kept on
  the application server's disk.
- **The `approved` activity status is never used.** Approving publishes straight away; the value
  exists in the database enum only.
- **A person's two accounts share their push devices.** Registering a device on either side registers
  it for the person, so a push meant for one account can reach the device the other account is using.
  The in-app feeds are separate; pushes are not.
- **The notification log filters recipients by the type they registered as**, not by accounts.
- **A provider request needs the three documents even from a provider who was approved before they existed.**
  Such providers are not asked to send them.
- **The provider dropdown on "add activity" lists at most 500 providers.** Beyond that it needs a
  search box.
- **Announcements go to all customers, all providers or one account.** There are no segments,
  scheduling or templates yet, and the "sent to" count is how many were handed to the notification
  system, not how many devices received a push.
- **The notification log shows in-app entries only.** A push, SMS or e-mail that kept failing is
  not listed there; see `php artisan queue:failed`.
- **A deleted customer's reviews still show their name publicly.** The history is kept on purpose;
  whether it should be anonymised is a product decision.
- **No concurrency tests** for capacity locking or webhook races.

## For AI coding assistants

`AGENTS.md` and `CLAUDE.md` hold the Laravel Boost guidelines for AI assistants.
