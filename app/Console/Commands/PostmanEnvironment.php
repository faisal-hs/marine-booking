<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\Category;
use App\Models\Provider;
use App\Models\User;
use App\Services\CustomerApp\ActivityCatalog;
use App\Services\CustomerApp\Checkout;
use Illuminate\Support\Carbon;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Writes a Postman ENVIRONMENT for the API collection, from THIS database: the demo accounts the seeders made (phones, names, the admin login, the OTP code), valid API tokens for them (minted here,
 * so every request that needs a sign-in works on its own), and the real ids, slugs, uuids and dates of an activity that can be booked, one for each way of booking (persons, units, time, fixed).
 *
 * It exists because uuids and tokens are different in every database (the seeders make new ones each time), so a file made on another machine would be wrong here. Run it after seeding:
 *
 *     php artisan migrate:fresh --seed
 *     php artisan postman:environment
 *
 * then import the file it prints into Postman. It mints API tokens, so it REFUSES to run in production, and the file it writes (storage/app/postman by default) is readable by its owner only.
 * Every variable of the collection is in the file; those the collection fills in while it runs (a reservation it creates, a payment it makes) stay empty until then.
 */
class PostmanEnvironment extends Command
{
    protected $signature = 'postman:environment
        {--output= : The file to write (default: storage/app/postman/Marine-Booking-Seeded.postman_environment.json)}
        {--base-url= : The address the API answers on (default: APP_URL)}
        {--admin-email=admin@marine-booking.test : The admin the AdminSeeder made}
        {--admin-password=password : Its password}
        {--provider-phone=0511111111 : The demo provider owner (DemoUsersSeeder)}
        {--customer-phone=0522222222 : The demo customer (DemoUsersSeeder)}
        {--without-fixtures : Do not make the demo customer a confirmed booking and a pending one (the payments and the support message need one to exist)}';

    protected $description = 'Writes a Postman environment with real values and valid tokens from the seeded database (never in production).';

    /** Every variable the collection reads or sets, so none is missing from the file. @var list<string> */
    private const VARIABLES = [
        'ca_no_id',
        'p9_no_id',
        'base_url',
        'otp_code',
        'customer_phone',
        'customer_name',
        'provider_phone',
        'provider_owner_name',
        'provider_business_name',
        'webhook_secret',
        'fcm_token',
        'upgrade_phone',
        'upgrade_name',
        'upgrade_business_name',
        'admin_token',
        'admin_notification_id',
        'customer_token',
        'customer_uuid',
        'provider_token',
        'provider_uuid',
        'provider_verification',
        'category_id',
        'category_slug',
        'activity_slug',
        'activity_uuid',
        'booking_option_uuid',
        'reservation_date',
        'reservation_uuid',
        'payment_uuid',
        'reservation2_uuid',
        'payment2_uuid',
        'payment2_reference',
        'reservation3_uuid',
        'review_uuid',
        'notification_id',
        'provider_notification_id',
        'document_uuid',
        'provider_activity_uuid',
        'provider_activity2_uuid',
        'schedule_period_uuid',
        'override_uuid',
        'provider_booking_option_uuid',
        'pricing_rule_uuid',
        'image_uuid',
        'image2_uuid',
        'provider_customer_token',
        'unified_token',
        'upgrade_token',
        'upgrade_provider_token',
        'upgrade_status',
        'analytics_from',
        'analytics_to',
        'application_id',
        'available_balance',
        'booking_id',
        'category2_id',
        'coupon_activity_uuid',
        'coupon_booking_fields',
        'coupon_code',
        'coupon_code_lower',
        'coupon_id',
        'coupon_option_uuid',
        'current_month',
        'current_year',
        'last_404_message',
        'onboarding_business_name',
        'onboarding_owner_name',
        'onboarding_phone',
        'onboarding_token',
        'original_business_name',
        'other_activity_id',
        'other_booking_id',
        'other_provider_phone',
        'other_provider_token',
        'other_withdrawal_id',
        'override_from',
        'override_to',
        'published_activity_id',
        'published_activity_uuid',
        'review_id',
        'statement_url',
        'verification_file_url',
        'withdrawal_id',
        'withdrawal_key',
        'admin_email',
        'admin_password',
        'ca_activity_id',
        'ca_provider_id',
        'ca_date',
        'ca_time',
        'ca_idem',
        'ca_booking_id',
        'ca_booking_total',
        'ca_conversation_id',
        'ca_notification_id',
        'booking_date',
        'range_end',
        'this_month',
        'city_id',
        'webhook_signature',
        'webhook_body',
        'profile_logo',
        'one_activity_uuid',
        'one_image_first',
        'one_image_next',
        'become_phone',
        'become_token',
        'become_category_id',
        'rt_',
        'rt_persons_id',
        'rt_date',
        'rt_slot_id',
        'rt_duration',
        'rt_quantity',
        'rt_units_id',
        'rt_time_id',
        'rt_w_activity',
        'rt_w_slot',
        'rt_w_from',
        'rt_w_to',
        'rt_w_minutes',
        'rt_w_duration',
        'rt_fixed_id',
        'rt_next_month',
        'rt_prov_activity_id',
        'rt_coupon_code',
        'rt_idem',
        'rt_booking_id',
        'rt_booking_total',
        'tb_phone',
        'tb_friday',
        'tb_saturday',
        'tb_customer_token',
        'tb_title',
        'tb_activity_id',
        'tb_activity_uuid',
        'tb_today',
        'tb_later',
        'tb_slot_id',
        'tb_key1',
        'tb_key2',
        'tb_booking1',
        'tb_booking2',
        'p9_phone',
        'p9_friday',
        'p9_saturday',
        'p9_customer_token',
        'p9_title',
        'p9_activity_id',
        'p9_date',
        'p9_slot_id',
        'p9_key',
        'p9_booking',
        'p9_redirect',
        'p9_reservation_uuid',
        'bk_phone',
        'bk_friday',
        'bk_saturday',
        'bk_customer_token',
        'bk_pt',
        'bk_p_activity_id',
        'bk_today',
        'bk_later',
        'bk_p_date',
        'bk_p_slot_id',
        'bk_p_option_id',
        'bk_tt',
        'bk_activity_id',
        'bk_date',
        'bk_slot_id',
        'bk_option_id',
        'bk_booking_persons',
        'bk_booking_time',
        'bk_booking_time2',
        'bk_provider_id',
        'bk_provider_uuid',
        'bk_provider_slug',
        'bk_reviews_count',
    ];

    /** Variables whose value is a secret (Postman hides them). */
    private const SECRET = [
        'webhook_secret',
        'fcm_token',
        'admin_token',
        'customer_token',
        'provider_token',
        'provider_customer_token',
        'unified_token',
        'upgrade_token',
        'upgrade_provider_token',
        'onboarding_token',
        'other_provider_token',
        'admin_password',
        'webhook_signature',
        'become_token',
        'tb_customer_token',
        'p9_customer_token',
        'bk_customer_token',
    ];

    public function handle(ActivityCatalog $catalog, Checkout $checkout): int
    {
        if (app()->isProduction()) {
            $this->error('This command mints API tokens: it does not run in production.');

            return self::FAILURE;
        }

        $admin = User::where('email', $this->option('admin-email'))->first();
        $providerUser = User::where('phone', $this->option('provider-phone'))->first();
        $customerUser = User::where('phone', $this->option('customer-phone'))->first();

        foreach ([['the admin', $admin, 'admin-email'], ['the provider', $providerUser?->provider, 'provider-phone'], ['the customer', $customerUser?->customer, 'customer-phone']] as [$who, $found, $option]) {
            if (! $found) {
                $this->error("Cannot find {$who} ({$this->option($option)}). Seed the database first: php artisan migrate:fresh --seed (or pass --{$option}).");

                return self::FAILURE;
            }
        }

        $values = array_fill_keys(self::VARIABLES, '');
        $values = array_merge($values, $this->accounts($admin, $providerUser, $customerUser), $discovered = $this->discovered($catalog, $checkout, $customerUser->customer));

        if (! $this->option('without-fixtures') && isset($discovered['ca_activity_id'])) {
            $values = array_merge($values, $this->fixtures($customerUser, $values));

            // Making them counted against the customer's bookings-a-minute limit: the requests of the collection must not start with it used up.
            foreach (['customer-app-booking'] as $limiter) {
                RateLimiter::clear(md5($limiter.$limiter.':'.$customerUser->id));
                RateLimiter::clear($limiter.':'.$limiter.':'.$customerUser->id);
            }
        }

        $path = $this->option('output') ?: storage_path('app/postman/Marine-Booking-Seeded.postman_environment.json');
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($this->environment($values), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        @chmod($path, 0600);

        $empty = collect($values)->filter(fn ($v) => $v === '')->count();
        $this->info('Wrote '.$path);
        $this->line(count($values).' variables: '.(count($values) - $empty).' filled from this database, '.$empty.' left for the collection to fill while it runs.');
        $this->line('Import it into Postman (Environments > Import), select it, and run any request or folder.');

        if (! $this->fixedCodeInUse()) {
            $this->warn('The fixed one-time code is not in use here (OTP_DEFAULT_CODE_ENABLED is off, or OTP_DEFAULT_CODE is empty): sign-in requests need the real code, so otp_code is empty. For local testing set OTP_DEFAULT_CODE and OTP_DEFAULT_CODE_ENABLED=true.');
        }

        return self::SUCCESS;
    }

    /** Does the app issue the fixed one-time code? As OtpService decides: an explicit switch wins; unset, only the local and testing environments use it; and a code must be set. */
    private function fixedCodeInUse(): bool
    {
        $switch = config('otp.default_code_enabled');
        $enabled = ($switch === null || $switch === '') ? app()->environment(config('otp.default_code_environments', [])) : filter_var($switch, FILTER_VALIDATE_BOOLEAN);

        return $enabled && filled(config('otp.default_code'));
    }

    /** The demo accounts, their phones and names, and a valid token for each. @return array<string, string> */
    private function accounts(User $admin, User $providerUser, User $customerUser): array
    {
        return [
            'base_url' => rtrim((string) ($this->option('base-url') ?: config('app.url')), '/'),
            // The fixed code, when the app issues it (the rule of OtpService): without it the real code arrives by SMS and cannot be put in a file.
            'otp_code' => $this->fixedCodeInUse() ? (string) config('otp.default_code') : '',
            'admin_email' => $admin->email,
            'admin_password' => (string) $this->option('admin-password'),
            'customer_phone' => (string) $customerUser->phone,
            'customer_name' => (string) $customerUser->name,
            'provider_phone' => (string) $providerUser->phone,
            'provider_owner_name' => (string) $providerUser->name,
            'provider_business_name' => (string) $providerUser->provider->business_name,
            'webhook_secret' => (string) config('payments.mock.webhook_secret'),
            'fcm_token' => 'postman-demo-device-token',
            // What the "become a provider" and "other provider" flows sign up with: new phones, as the collection has always used.
            'upgrade_phone' => '0533333333',
            'upgrade_name' => 'Layla Hassan',
            'upgrade_business_name' => 'Jeddah Divers',
            'onboarding_owner_name' => 'Khaled Owner',
            'other_provider_phone' => '0510000000',
            // The same tokens signing in makes (their names and abilities), made here.
            'admin_token' => $admin->createToken('admin-api', ['admin'], now()->addHours((int) config('admin_api.token_hours')))->plainTextToken,
            'customer_token' => $customerUser->createToken('customer-auth', ['customer'])->plainTextToken,
            'provider_token' => $providerUser->createToken('provider-auth', ['provider'])->plainTextToken,
            'customer_uuid' => (string) $customerUser->customer->uuid,
            'provider_uuid' => (string) $providerUser->provider->uuid,
        ];
    }

    /**
     * What the collection's first requests would discover: a category, an activity that can be booked, the first departure with room, and one activity of each way of booking. Chosen with the
     * app's own catalog and availability, so what is picked is bookable by definition.
     *
     * @return array<string, string>
     */
    private function discovered(ActivityCatalog $catalog, Checkout $checkout, $customer): array
    {
        $out = [];
        $categories = Category::query()->where('status', 'active')->orderBy('sort_order')->orderBy('id')->limit(2)->get();

        if ($first = $categories->first()) {
            $out['category_id'] = (string) $first->id;
            $out['category_slug'] = (string) $first->slug;
        }

        if ($second = $categories->get(1)) {
            $out['category2_id'] = (string) $second->id;
        }

        $city = \App\Models\City::query()->where('is_active', true)->orderBy('id')->first();
        $out['city_id'] = $city ? (string) $city->id : '';

        // One activity of each way of booking the platform has, as the app lists them (the first by id).
        foreach (['rt_persons_id' => ['per_person'], 'rt_units_id' => ['per_unit'], 'rt_fixed_id' => ['per_trip', 'per_group', 'fixed', 'package'], 'rt_time_id' => ['per_hour']] as $variable => $model) {
            $found = $this->activityBooked($catalog, $model);
            $out[$variable] = $found ? (string) $found->id : '';
        }

        // The activity the customer books in the flows: by persons, with a departure that has room.
        $from = Carbon::now(Checkout::tz())->startOfDay();

        // (One the customer has not reviewed yet: a customer reviews an activity once, and the review flow needs one they can still review.)
        foreach ($catalog->visible()->whereRaw(ActivityCatalog::MODEL_SQL.' = ?', ['per_person'])
            ->whereDoesntHave('reviews', fn ($q) => $q->where('customer_id', $customer->id))
            // ...from a provider that is verified and active (the catalog also lists activities of providers still waiting for verification: they cannot be reached as a provider's page).
            ->whereHas('provider', fn ($q) => $q->where('verification_status', 'verified')->whereHas('user', fn ($u) => $u->where('status', 'active')))
            ->orderBy('activities.id')->limit(25)->get() as $activity) {
            foreach ($checkout->availability($activity, $from, $from->copy()->addDays(30))['days'] as $day) {
                $slot = collect($day['slots'])->first(fn ($s) => $s['is_available'] && ($s['spots_left'] === null || $s['spots_left'] >= 2));

                if ($slot) {
                    $option = $catalog->defaultOption($activity);

                    return $out + [
                        'ca_activity_id' => (string) $activity->id,
                        'ca_provider_id' => (string) $activity->provider_id,
                        'ca_date' => (string) $day['date'],
                        'ca_time' => (string) $slot['time'],
                        'activity_uuid' => (string) $activity->uuid,
                        'activity_slug' => (string) $activity->slug,
                        'booking_option_uuid' => (string) ($option?->uuid ?? ''),
                        'reservation_date' => (string) $day['date'],
                        'booking_date' => (string) $day['date'],
                        'override_from' => Carbon::now()->addDays(40)->toDateString(),
                        'override_to' => Carbon::now()->addDays(41)->toDateString(),
                    ];
                }
            }
        }

        $this->warn('No activity with a free departure was found: the booking flows will not find one either.');

        return $out;
    }

    /**
     * What the payment and support requests need to exist, made the way a customer makes it (the same API calls, with the demo customer's token): a CONFIRMED booking (paid with the mock
     * gateway: it is what the admin refunds and what the support message is about) and a booking WAITING for its payment (what the signed webhook confirms). Their holds are lengthened to a day,
     * so the file stays usable. They take two places of the activity each time this is run.
     *
     * @param  array<string, string>  $v
     * @return array<string, string>
     */
    private function fixtures(User $customerUser, array $v): array
    {
        $customerId = $customerUser->customer->id;

        // A fixture made by an earlier run is used again while it is still what it was made to be (a booking that was cancelled, a payment that was refunded or confirmed is not): so running this
        // again does not take more places, and the file keeps pointing at the same rows.
        $reuse = function (string $kind, callable $stillGood) use ($customerId): ?array {
            $r = Reservation::where('customer_id', $customerId)->where('idempotency_key', 'like', "postman-env-{$kind}-%")->latest('id')->first();
            $payment = $r ? Payment::where('reservation_id', $r->id)->latest('id')->first() : null;

            return $r && $payment && $stillGood($r, $payment) ? ['reservation' => $r, 'payment' => $payment, 'total' => $r->total] : null;
        };

        $book = function (string $kind, string $simulate) use ($customerUser, $v): ?array {
            [$status, $json] = $this->api('POST', '/api/v1/customer-app/bookings', [
                'activity_id' => (int) $v['ca_activity_id'], 'date' => $v['ca_date'], 'time' => $v['ca_time'], 'guests' => 1, 'contact_name' => (string) $customerUser->name,
                'contact_phone' => (string) $customerUser->phone, 'payment_method' => 'card', 'simulate' => $simulate,
            ], $v['customer_token'], ['HTTP_IDEMPOTENCY_KEY' => "postman-env-{$kind}-".Str::uuid()]);

            if ($status !== 201) {
                $this->warn("Could not make the {$kind} booking for the fixtures (HTTP {$status}): ".json_encode($json['errors'] ?? $json['message'] ?? ''));

                return null;
            }

            $reservation = Reservation::find($json['data']['booking_id']);

            if ($reservation->status->value === 'pending') {
                $reservation->update(['hold_expires_at' => now()->addDay()]);
            }

            return ['reservation' => $reservation, 'payment' => Payment::where('reservation_id', $reservation->id)->latest('id')->first(), 'total' => $json['data']['total']];
        };

        $out = [];
        $paid = $reuse('confirmed', fn ($r, $p) => $r->status->value === 'confirmed' && $p->status === PaymentStatus::Succeeded) ?? $book('confirmed', 'sync_success');
        $waiting = $reuse('waiting', fn ($r, $p) => $r->status->value === 'pending' && $r->hold_expires_at?->isFuture() && $p->status === PaymentStatus::Pending) ?? $book('waiting', 'async');

        if ($paid) {
            $out += ['ca_booking_id' => (string) $paid['reservation']->id, 'ca_booking_total' => (string) $paid['total'], 'booking_id' => (string) $paid['reservation']->id, 'reservation_uuid' => (string) $paid['reservation']->uuid, 'payment_uuid' => (string) $paid['payment']?->uuid];
        }

        if ($waiting) {
            $out += ['reservation2_uuid' => (string) $waiting['reservation']->uuid, 'payment2_uuid' => (string) $waiting['payment']?->uuid, 'payment2_reference' => (string) $waiting['payment']?->provider_reference];
        }

        return $out;
    }

    /** One call to the API, made inside this process. @param  array<string, mixed>  $body @param  array<string, string>  $server @return array{0: int, 1: array<string, mixed>} */
    private function api(string $method, string $uri, array $body, string $token, array $server = []): array
    {
        $request = Request::create($uri, $method, [], [], [], $server + ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], json_encode($body));
        $response = app(HttpKernel::class)->handle($request);
        app('auth')->forgetGuards();

        return [$response->getStatusCode(), json_decode((string) $response->getContent(), true) ?? []];
    }

    /** The first activity customers can see whose default price option is booked this way. */
    private function activityBooked(ActivityCatalog $catalog, array $models): ?Activity
    {
        // The default option as the app decides it (flagged, else the first), not only one that is flagged.
        return $catalog->visible()->whereRaw(ActivityCatalog::MODEL_SQL.' IN ('.implode(',', array_fill(0, count($models), '?')).')', $models)->orderBy('activities.id')->first();
    }

    /** @param  array<string, string>  $values */
    private function environment(array $values): array
    {
        return [
            'id' => (string) Str::uuid(),
            'name' => 'Marine Booking - Seeded ('.now()->format('Y-m-d H:i').')',
            'values' => collect($values)->map(fn ($value, $key) => ['key' => $key, 'value' => (string) $value, 'type' => in_array($key, self::SECRET, true) ? 'secret' : 'default', 'enabled' => true])->values()->all(),
            '_postman_variable_scope' => 'environment',
            '_postman_exported_at' => now()->toIso8601String(),
            '_postman_exported_using' => 'Marine Booking artisan postman:environment',
        ];
    }
}
