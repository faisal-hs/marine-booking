# Marine Booking

Backend for booking marine activities: diving, snorkeling, boat trips, fishing and more.
Customers and providers use a mobile app that talks to a JSON API, staff run the platform
from an admin panel, and a bilingual (English / Arabic) public website lets visitors browse.

Built with Laravel 12, Sanctum (API tokens) and Spatie Laravel-Permission (admin roles).

## What is in here

| Surface         | Used by                                                                                                       | Where                                           |
| --------------- | ------------------------------------------------------------------------------------------------------------- | ----------------------------------------------- |
| Customer API    | Customers (mobile app): phone + OTP login, reserve, pay, review, favourite                                    | `/api/v1/auth/customer/*`, `/api/v1/customer/*` |
| Provider API    | Activity providers (mobile app): phone + OTP login, manage activities, schedules, pricing, see reservations   | `/api/v1/auth/provider/*`, `/api/v1/provider/*` |
| Public API      | Anyone: published activities, categories, availability, reviews                                               | `/api/v1/public/*`                              |
| Admin panel     | Staff: users, providers, activity moderation, reservations, payments and refunds, reviews, dashboard, reports | `/admin` (login at `/admin/login`)              |
| Admin JSON API  | Reservations, payments, reviews and the dashboard as JSON                                                     | `/api/v1/admin/*`                               |
| Public website  | Visitors, browse only (booking happens in the mobile app)                                                     | `/`                                             |
| Payment webhook | The payment gateway                                                                                           | `POST /api/v1/webhooks/payments/{gateway}`      |

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

Customers and providers have no password; they sign in by phone and a one-time code. In the
`local` and `testing` environments the code is always `OTP_DEFAULT_CODE` (`1234` in
`.env.example`). The seeded customer is `0522222222` and the seeded provider is `0511111111`:

```bash
curl -X POST http://127.0.0.1:8000/api/v1/auth/customer/otp/request -H 'Accept: application/json' -d phone=0522222222
curl -X POST http://127.0.0.1:8000/api/v1/auth/customer/otp/verify  -H 'Accept: application/json' -d phone=0522222222 -d code=1234
```

The second call returns a bearer token for the other `/api/v1/customer/*` endpoints. Providers
use the same calls under `/api/v1/auth/provider/`.

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

Three rules keep the suite honest, and each is enforced by a test in the suite:

1. **No hardcoded calendar dates in tests.** The reservation flow rejects past dates, so a test
   that books `2026-09-05` fails once that day has passed. Use `Tests\Concerns\RelativeDates`
   (`futureSaturday()`, `futureSunday()`, `daysFromNow()`).
2. **Test files must be named `<ClassName>Test.php`.** PHPUnit silently skips any other name,
   so the tests in it would never run (`tests/Unit/TestFileNamingTest.php`).
3. **New project environment variables go in `.env.example`**
   (`tests/Unit/EnvExampleCoverageTest.php`).

## How the code is organised

A request flows `FormRequest` (validation and authorization) → thin controller → **service**
(business rules) → **repository** (interfaces in `app/Repositories/Contracts`, bound in
`RepositoryServiceProvider`) → Eloquent model. Responses go through API resources.

- `app/Http/Controllers/Api/{Auth,Customer,Provider,Public,Admin,Shared,Webhooks}` are the JSON
  API; `app/Http/Controllers/Web/{Admin,Public}` are the Blade pages.
- **Authentication.** Customers and providers use Sanctum bearer tokens with the abilities
  `customer` / `provider`. Admins use the `web` session guard plus permissions such as
  `activities.approve`, `payments.refund` and `reports.view`, grouped into the roles
  `super_admin`, `admin`, `support_admin` and `content_admin`.
- **Availability and pricing are separate services** (`AvailabilityService`,
  `ActivityPricingService`) and are meant to stay that way.
- **Payments** go through `PaymentGatewayInterface` (`app/Contracts/Payments`); a gateway is
  chosen with `PAYMENT_GATEWAY`. See the limitations below before relying on it.

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

### Languages

`lang/en` and `lang/ar`, with right-to-left layout for Arabic. The admin panel and the public
site each remember their own language in the session (`admin_locale` and `public_locale`).

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
- **Money is handled as PHP floats and the currency is EGP throughout.**
- **Revenue in the reports is gross.** Payments have no `refunded_at`, so a refund cannot be
  attributed to a period. The all-time figure on the dashboard is net of refunds.
- **No concurrency tests** for capacity locking or webhook races.

## For AI coding assistants

`AGENTS.md` and `CLAUDE.md` hold the Laravel Boost guidelines for AI assistants.
