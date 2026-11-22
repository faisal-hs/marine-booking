<?php

namespace Tests\Feature\Admin;

use App\Models\Customer;
use App\Models\Provider;
use App\Models\ProviderRequest;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The SEX of a customer or a provider's owner in the admin panel: chosen in the forms (male, female, or not said), and a FILTER of the customers, providers, users and provider
 * requests lists (`sex=male` or `sex=female`; anything else is no filter).
 */
class UserSexAdminTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function asAdmin(): static
    {
        return $this->actingAs($this->makeAdmin(), 'web');
    }

    private function customerPayload(array $over = []): array
    {
        return array_merge(['name' => 'Layla Hassan', 'phone' => '0533000111', 'email' => 'layla@example.test', 'date_of_birth' => '1992-04-18', 'preferred_language' => 'ar'], $over);
    }

    private function providerPayload(array $over = []): array
    {
        return array_merge(['name' => 'Omar Said', 'phone' => '0555000111', 'email' => 'omar@bluehole.test', 'business_name' => 'Blue Hole Dive Club', 'business_license' => 'LIC-2026-77',
            'bio' => 'Family run since 2009.', 'address' => 'Dahab, South Sinai', 'lat' => 28.5, 'lng' => 34.5, 'commission_rate' => 12.5], $over);
    }

    /** A customer with a name and a sex (null: not said). */
    private function customerNamed(string $name, ?string $sex): Customer
    {
        return $this->makeCustomer(['name' => $name, 'sex' => $sex])[1];
    }

    /** A provider with an owner's name and sex, and a pending application. */
    private function providerNamed(string $owner, ?string $sex, string $business): Provider
    {
        [$user, $provider] = $this->makeProvider('pending');
        $user->update(['name' => $owner, 'sex' => $sex]);
        $provider->update(['business_name' => $business]);
        ProviderRequest::create(['user_id' => $user->id, 'provider_id' => $provider->id, 'source' => ProviderRequest::SOURCE_REGISTRATION, 'status' => 'pending', 'submitted_at' => now()]);

        return $provider;
    }

    // --- The forms ----------------------------------------------------------------------------------------------------

    public function test_the_customer_form_has_the_choice_and_saves_it_changes_it_and_clears_it(): void
    {
        $this->asAdmin()->get(route('admin.customers.create'))->assertOk()->assertSee('name="sex"', false)->assertSee(__('admin.sex.male'))->assertSee(__('admin.sex.female'));

        $this->asAdmin()->post(route('admin.customers.store'), $this->customerPayload(['sex' => 'female']))->assertSessionHasNoErrors();
        $customer = Customer::firstOrFail();
        $this->assertSame('female', $customer->user->sex);

        $this->asAdmin()->put(route('admin.customers.update', $customer), $this->customerPayload(['sex' => 'male']))->assertSessionHasNoErrors();
        $this->assertSame('male', $customer->user->fresh()->sex);

        $this->asAdmin()->put(route('admin.customers.update', $customer), $this->customerPayload(['sex' => '']))->assertSessionHasNoErrors();
        $this->assertNull($customer->user->fresh()->sex, 'The empty choice is "not said"');

        $this->asAdmin()->get(route('admin.customers.edit', $customer))->assertOk()->assertSee('name="sex"', false);
    }

    public function test_the_provider_form_has_the_choice_and_saves_it_changes_it_and_clears_it(): void
    {
        $this->asAdmin()->get(route('admin.providers.create'))->assertOk()->assertSee('name="sex"', false);

        $this->asAdmin()->post(route('admin.providers.store'), $this->providerPayload(['sex' => 'male']))->assertSessionHasNoErrors();
        $provider = Provider::firstOrFail();
        $this->assertSame('male', $provider->user->sex);

        $this->asAdmin()->put(route('admin.providers.update', $provider), $this->providerPayload(['sex' => 'female']))->assertSessionHasNoErrors();
        $this->assertSame('female', $provider->user->fresh()->sex);

        $this->asAdmin()->put(route('admin.providers.update', $provider), $this->providerPayload(['sex' => null]))->assertSessionHasNoErrors();
        $this->assertNull($provider->user->fresh()->sex);
    }

    public function test_a_value_that_is_not_male_or_female_is_refused_in_both_forms(): void
    {
        $this->asAdmin()->post(route('admin.customers.store'), $this->customerPayload(['sex' => 'other']))->assertSessionHasErrors('sex');
        $this->asAdmin()->post(route('admin.providers.store'), $this->providerPayload(['sex' => 'Male']))->assertSessionHasErrors('sex');

        $this->assertSame(0, Customer::count());
        $this->assertSame(0, Provider::count());
    }

    public function test_the_sex_is_optional_in_the_forms_and_so_is_the_email(): void
    {
        $this->asAdmin()->post(route('admin.customers.store'), $this->customerPayload(['email' => null]))->assertSessionHasNoErrors();
        $this->asAdmin()->post(route('admin.providers.store'), $this->providerPayload(['email' => null]))->assertSessionHasNoErrors();

        $this->assertSame([null, null], [Customer::firstOrFail()->user->sex, Provider::firstOrFail()->user->sex]);
        $this->assertSame(2, User::where('user_type', '!=', 'admin')->whereNull('email')->count());
    }

    // --- The filters --------------------------------------------------------------------------------------------------

    public function test_the_customers_list_can_be_filtered_by_sex(): void
    {
        $this->customerNamed('Laila Female', 'female');
        $this->customerNamed('Majed Male', 'male');
        $this->customerNamed('Nour Unsaid', null);
        $get = fn (array $q) => $this->asAdmin()->get(route('admin.customers.index', $q));

        $get([])->assertOk()->assertSee('Laila Female')->assertSee('Majed Male')->assertSee('Nour Unsaid')->assertSee('name="sex"', false);
        $get(['sex' => 'female'])->assertOk()->assertSee('Laila Female')->assertDontSee('Majed Male')->assertDontSee('Nour Unsaid');
        $get(['sex' => 'male'])->assertOk()->assertSee('Majed Male')->assertDontSee('Laila Female')->assertDontSee('Nour Unsaid');
        $get(['sex' => 'other'])->assertOk()->assertSee('Laila Female')->assertSee('Majed Male')->assertSee('Nour Unsaid');   // not a sex: no filter
        $get(['sex' => 'female', 'search' => 'Laila'])->assertSee('Laila Female')->assertDontSee('Majed Male');
        $get(['sex' => 'male', 'search' => 'Laila'])->assertDontSee('Laila Female')->assertDontSee('Majed Male');
    }

    public function test_the_providers_list_can_be_filtered_by_the_sex_of_the_owner(): void
    {
        $this->providerNamed('Salma Owner', 'female', 'Coral Tours');
        $this->providerNamed('Sami Owner', 'male', 'Reef Divers');
        $this->providerNamed('Noor Owner', null, 'Sand Safari');
        $get = fn (array $q) => $this->asAdmin()->get(route('admin.providers.index', $q));

        $get(['sex' => 'female'])->assertOk()->assertSee('Coral Tours')->assertDontSee('Reef Divers')->assertDontSee('Sand Safari');
        $get(['sex' => 'male'])->assertOk()->assertSee('Reef Divers')->assertDontSee('Coral Tours');
        $get(['sex' => 'bogus'])->assertOk()->assertSee('Coral Tours')->assertSee('Reef Divers')->assertSee('Sand Safari');
        $get(['sex' => 'male', 'status' => 'pending'])->assertSee('Reef Divers')->assertDontSee('Coral Tours');
    }

    public function test_the_provider_requests_list_can_be_filtered_by_the_sex_of_the_applicant_and_the_tabs_keep_it(): void
    {
        $this->providerNamed('Salma Owner', 'female', 'Coral Tours');
        $this->providerNamed('Sami Owner', 'male', 'Reef Divers');
        $get = fn (array $q) => $this->asAdmin()->get(route('admin.provider-requests.index', $q));

        $get([])->assertOk()->assertSee('Coral Tours')->assertSee('Reef Divers');
        $get(['sex' => 'female'])->assertOk()->assertSee('Coral Tours')->assertDontSee('Reef Divers')->assertSee('sex=female', false);   // the status tabs carry it on
        $get(['sex' => 'male'])->assertSee('Reef Divers')->assertDontSee('Coral Tours');
        $get(['sex' => 'x'])->assertSee('Coral Tours')->assertSee('Reef Divers');
    }
}
