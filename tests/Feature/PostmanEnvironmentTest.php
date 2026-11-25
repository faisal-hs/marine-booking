<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Activity;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Review;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Concerns\BuildsCustomerAppScenarios;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * `php artisan postman:environment`: a Postman environment made from THIS database (the seeded demo accounts, valid tokens for them, a bookable activity of each kind, and the payments the
 * collection's later requests need to find already there), so every request can be tried without running the ones before it. Uuids and tokens are different in every database, which is
 * why it is a command and not a file.
 */
class PostmanEnvironmentTest extends TestCase
{
    use BuildsCustomerAppScenarios;
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private array $a;

    private $customerUser;

    private $customer;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pinTime();
        $this->seed(RolesAndPermissionsSeeder::class);
        config(['otp.default_code' => '1234', 'otp.default_code_enabled' => true, 'payments.default' => 'mock']);
        $this->a = $this->approvedProvider(['business_name' => 'Red Sea Divers']);
        [$this->customerUser, $this->customer] = $this->signedInCustomer(['phone' => '0522222222', 'name' => 'Demo Customer']);
        $this->makeAdmin()->update(['email' => 'admin@marine-booking.test']);
        $this->a['user']->update(['phone' => '0511111111']);
        $this->file = sys_get_temp_dir().'/postman-env-'.uniqid().'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Runs the command and returns the variables of the file as key => value. @return array<string, string> */
    private function generate(array $options = []): array
    {
        $this->artisan('postman:environment', ['--output' => $this->file, '--base-url' => 'http://api.test/'] + $options)->assertSuccessful();

        return collect(json_decode((string) file_get_contents($this->file), true)['values'])->pluck('value', 'key')->all();
    }

    // --- The file ----------------------------------------------------------------------------------------------------------------

    public function test_it_writes_a_postman_environment_with_every_variable_of_the_collection(): void
    {
        $this->generate();
        $env = json_decode((string) file_get_contents($this->file), true);

        $this->assertSame('environment', $env['_postman_variable_scope']);
        $this->assertStringStartsWith('Marine Booking - Seeded', $env['name']);
        $this->assertGreaterThanOrEqual(170, count($env['values']));
        $this->assertSame(count($env['values']), count(array_unique(array_column($env['values'], 'key'))), 'No variable twice');
        foreach ($env['values'] as $v) {
            $this->assertSame(['key', 'value', 'type', 'enabled'], array_keys($v));
            $this->assertIsString($v['value']);
            $this->assertTrue($v['enabled']);
        }
        // What the collection fills in while it runs is there, empty, so Postman knows the variable.
        $values = collect($env['values'])->pluck('value', 'key');
        foreach (['review_uuid', 'document_uuid', 'tb_activity_id', 'bk_customer_token', 'webhook_body'] as $key) {
            $this->assertTrue($values->has($key), $key);
            $this->assertSame('', $values[$key]);
        }
    }

    public function test_tokens_and_secrets_are_marked_secret_and_the_file_is_readable_by_its_owner_only(): void
    {
        $this->generate();
        $types = collect(json_decode((string) file_get_contents($this->file), true)['values'])->pluck('type', 'key');

        foreach (['admin_token', 'customer_token', 'provider_token', 'admin_password', 'webhook_secret', 'bk_customer_token'] as $key) {
            $this->assertSame('secret', $types[$key], $key);
        }
        $this->assertSame('default', $types['customer_phone']);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->file)), -4));
    }

    public function test_the_demo_accounts_and_the_settings_are_filled_in(): void
    {
        $v = $this->generate();

        $this->assertSame(['http://api.test', '1234', 'admin@marine-booking.test', 'password', '0522222222', 'Demo Customer', '0511111111', 'Red Sea Divers'],
            [$v['base_url'], $v['otp_code'], $v['admin_email'], $v['admin_password'], $v['customer_phone'], $v['customer_name'], $v['provider_phone'], $v['provider_business_name']]);
        $this->assertSame([$this->customer->uuid, $this->a['provider']->uuid, config('payments.mock.webhook_secret')], [$v['customer_uuid'], $v['provider_uuid'], $v['webhook_secret']]);
    }

    public function test_the_base_url_defaults_to_the_apps_address(): void
    {
        config(['app.url' => 'https://staging.example.test/']);

        $this->artisan('postman:environment', ['--output' => $this->file])->assertSuccessful();

        $this->assertSame('https://staging.example.test', collect(json_decode((string) file_get_contents($this->file), true)['values'])->pluck('value', 'key')['base_url']);
    }

    public function test_the_otp_code_is_given_only_when_the_app_issues_the_fixed_code(): void
    {
        config(['otp.default_code_enabled' => false]);

        $this->artisan('postman:environment', ['--output' => $this->file])->expectsOutputToContain('fixed one-time code is not in use')->assertSuccessful();

        $this->assertSame('', collect(json_decode((string) file_get_contents($this->file), true)['values'])->pluck('value', 'key')['otp_code']);

        config(['otp.default_code_enabled' => null, 'otp.default_code_environments' => ['testing']]);   // unset: only local and testing use it, as OtpService decides
        $this->assertSame('1234', $this->generate()['otp_code']);
    }

    // --- The tokens work ---------------------------------------------------------------------------------------------------------

    private function asToken(string $token, string $path)
    {
        $this->app['auth']->forgetGuards();

        return $this->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'])->getJson($path);
    }

    public function test_each_token_signs_in_as_its_own_account_and_no_other(): void
    {
        $v = $this->generate();

        $this->asToken($v['customer_token'], '/api/v1/customer-app/profile')->assertOk()->assertJsonPath('data.phone', '0522222222');
        $this->asToken($v['provider_token'], '/api/v1/provider/profile')->assertOk();
        $this->asToken($v['admin_token'], '/api/v1/admin/payments')->assertOk();

        $this->asToken($v['customer_token'], '/api/v1/admin/payments')->assertStatus(403);
        $this->asToken($v['admin_token'], '/api/v1/customer-app/profile')->assertStatus(403);
        $this->asToken($v['provider_token'], '/api/v1/customer-app/profile')->assertStatus(403);
    }

    public function test_every_run_mints_new_tokens(): void
    {
        $first = $this->generate();
        $second = $this->generate();

        $this->assertNotSame($first['customer_token'], $second['customer_token']);
        $this->asToken($first['customer_token'], '/api/v1/customer-app/profile')->assertOk();   // the earlier ones are not revoked
    }

    // --- The activity ------------------------------------------------------------------------------------------------------------

    public function test_it_picks_an_activity_that_can_be_booked_with_its_first_free_departure(): void
    {
        $v = $this->generate();
        $activity = $this->a['activity'];

        $this->assertSame([(string) $activity->id, (string) $this->a['provider']->id, $activity->uuid, $activity->slug, $this->a['option']->uuid], [$v['ca_activity_id'], $v['ca_provider_id'], $v['activity_uuid'], $v['activity_slug'], $v['booking_option_uuid']]);
        $this->assertSame([self::NEXT_SATURDAY, '09:00', self::NEXT_SATURDAY], [$v['ca_date'], $v['ca_time'], $v['reservation_date']]);
        // ...and it really is bookable: the app's own availability lists that departure.
        $days = $this->asToken($v['customer_token'], "/api/v1/customer-app/activities/{$v['ca_activity_id']}/availability?month=2026-11")->assertOk()->json('data.days');
        $this->assertContains('09:00', collect($days)->firstWhere('date', $v['ca_date'])['slots'] ? array_column(collect($days)->firstWhere('date', $v['ca_date'])['slots'], 'time') : []);
    }

    public function test_an_activity_of_a_provider_that_is_not_verified_is_never_picked(): void
    {
        $this->a['provider']->update(['verification_status' => 'pending']);   // the catalog still lists it, but its provider cannot be reached
        $other = $this->approvedProvider(['business_name' => 'Verified Ones']);

        $v = $this->generate();

        $this->assertSame((string) $other['activity']->id, $v['ca_activity_id']);
        $this->assertSame((string) $other['provider']->id, $v['ca_provider_id']);
    }

    public function test_an_activity_the_customer_has_already_reviewed_is_not_picked(): void
    {
        $other = $this->approvedProvider(['business_name' => 'Verified Ones']);
        $this->makeReview($this->a['activity'], $this->customerUser->customer ?? $this->customer, 5, 'Lovely');

        $this->assertSame((string) $other['activity']->id, $this->generate()['ca_activity_id'], 'The review flow needs one that can still be reviewed');
    }

    public function test_it_finds_one_activity_for_each_way_of_booking(): void
    {
        // One price for the booking (not by kind of guest), as an option booked by units, time or at one price has it.
        $make = function (string $title, float $price, string $model, array $attrs = []) {
            $activity = $this->moreActivity($this->a, $title, 100);
            $option = $activity->bookingOptions()->first();
            $option->update(['booking_model' => $model, 'is_default' => true] + $attrs);
            $option->pricingRules()->delete();
            $option->pricingRules()->create(['participant_type' => null, 'price' => $price]);

            return $activity;
        };
        $units = $make('Kayaks', 300, 'per_unit', ['min_quantity' => 1, 'max_quantity' => 4]);
        $time = $make('Jet ski', 300, 'per_hour', ['min_duration_minutes' => 45]);
        $fixed = $make('Private trip', 5000, 'package');

        $v = $this->generate();

        $this->assertSame([(string) $this->a['activity']->id, (string) $units->id, (string) $fixed->id, (string) $time->id], [$v['rt_persons_id'], $v['rt_units_id'], $v['rt_fixed_id'], $v['rt_time_id']]);
    }

    public function test_with_no_activity_to_book_the_command_says_so_and_still_writes_the_accounts(): void
    {
        $this->a['activity']->update(['status' => 'draft']);

        $this->artisan('postman:environment', ['--output' => $this->file])->expectsOutputToContain('No activity with a free departure was found')->assertSuccessful();

        $v = collect(json_decode((string) file_get_contents($this->file), true)['values'])->pluck('value', 'key');
        $this->assertSame('', $v['ca_activity_id']);
        $this->assertNotSame('', $v['customer_token']);
    }

    // --- The fixtures ------------------------------------------------------------------------------------------------------------

    public function test_it_makes_the_customer_a_confirmed_booking_and_a_waiting_one(): void
    {
        $v = $this->generate();

        $paid = Reservation::findOrFail($v['ca_booking_id']);
        $this->assertSame(['confirmed', $this->customer->id, $this->a['activity']->id], [$paid->status->value, $paid->customer_id, $paid->activity_id]);
        $this->assertSame([$paid->uuid, $paid->uuid], [$v['reservation_uuid'], Reservation::findOrFail($v['booking_id'])->uuid]);
        $this->assertEquals((float) $paid->total, (float) $v['ca_booking_total']);
        $payment = Payment::where('uuid', $v['payment_uuid'])->firstOrFail();
        $this->assertSame([PaymentStatus::Succeeded, $paid->id], [$payment->status, $payment->reservation_id], 'What the admin refunds');

        $waiting = Reservation::where('uuid', $v['reservation2_uuid'])->firstOrFail();
        $pending = Payment::where('uuid', $v['payment2_uuid'])->firstOrFail();
        $this->assertSame(['pending', PaymentStatus::Pending, $pending->provider_reference], [$waiting->status->value, $pending->status, $v['payment2_reference']], 'What the signed webhook confirms');
        $this->assertTrue($waiting->hold_expires_at->isAfter(now()->addHours(23)), 'Its hold is a day, so the file stays usable');
    }

    public function test_the_signed_webhook_the_collection_sends_confirms_the_waiting_booking(): void
    {
        $v = $this->generate();
        $body = json_encode(['provider_reference' => $v['payment2_reference'], 'status' => 'succeeded']);

        $this->postJson('/api/v1/webhooks/payments/mock', json_decode($body, true), ['X-Mock-Signature' => hash_hmac('sha256', $body, $v['webhook_secret'])])->assertOk();

        $this->assertSame('confirmed', Reservation::where('uuid', $v['reservation2_uuid'])->firstOrFail()->status->value);
    }

    public function test_running_it_again_uses_the_same_bookings_while_they_are_still_good(): void
    {
        $first = $this->generate();
        $second = $this->generate();

        foreach (['ca_booking_id', 'reservation_uuid', 'payment_uuid', 'reservation2_uuid', 'payment2_uuid', 'payment2_reference'] as $key) {
            $this->assertSame($first[$key], $second[$key], $key);
        }
        $this->assertSame(2, Reservation::count());
    }

    public function test_a_booking_that_was_used_up_is_replaced_on_the_next_run(): void
    {
        $first = $this->generate();
        Reservation::findOrFail($first['ca_booking_id'])->update(['status' => 'cancelled']);                    // cancelled by a request
        Payment::where('uuid', $first['payment2_uuid'])->firstOrFail()->update(['status' => 'succeeded']);   // confirmed by the webhook

        $second = $this->generate();

        $this->assertNotSame($first['ca_booking_id'], $second['ca_booking_id']);
        $this->assertNotSame($first['payment2_uuid'], $second['payment2_uuid']);
        $this->assertSame('confirmed', Reservation::findOrFail($second['ca_booking_id'])->status->value);
        $this->assertSame('pending', Reservation::where('uuid', $second['reservation2_uuid'])->firstOrFail()->status->value);
    }

    public function test_without_fixtures_nothing_is_booked_and_their_variables_stay_empty(): void
    {
        $v = $this->generate(['--without-fixtures' => true]);

        $this->assertSame(0, Reservation::count());
        foreach (['ca_booking_id', 'reservation_uuid', 'payment_uuid', 'reservation2_uuid', 'payment2_reference'] as $key) {
            $this->assertSame('', $v[$key], $key);
        }
        $this->assertNotSame('', $v['customer_token']);
    }

    public function test_the_fixtures_do_not_use_up_the_customers_bookings_a_minute(): void
    {
        $this->generate();
        $key = md5('customer-app-booking'.'customer-app-booking:'.$this->customerUser->id);

        $this->assertSame(0, RateLimiter::attempts($key), 'The first requests of the collection must not start with the limit used');
    }

    // --- Safety ------------------------------------------------------------------------------------------------------------------

    public function test_it_never_runs_in_production_and_writes_nothing(): void
    {
        $tokens = \Laravel\Sanctum\PersonalAccessToken::count();
        $this->app['env'] = 'production';

        $this->artisan('postman:environment', ['--output' => $this->file])->expectsOutputToContain('does not run in production')->assertFailed();

        $this->assertFileDoesNotExist($this->file);
        $this->assertSame($tokens, \Laravel\Sanctum\PersonalAccessToken::count(), 'No token was made');
    }

    public function test_when_an_account_is_missing_it_says_which_and_makes_nothing(): void
    {
        $tokens = \Laravel\Sanctum\PersonalAccessToken::count();

        $this->artisan('postman:environment', ['--output' => $this->file, '--customer-phone' => '0599999999'])->expectsOutputToContain('Cannot find the customer (0599999999)')->assertFailed();

        $this->assertFileDoesNotExist($this->file);
        $this->assertSame($tokens, \Laravel\Sanctum\PersonalAccessToken::count());
    }
}
