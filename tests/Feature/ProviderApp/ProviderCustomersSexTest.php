<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * GET /provider/customers: each customer has their `sex` (male, female, or null when they did not say), and the list can be filtered with `sex=male` or `sex=female`. The counts of
 * the tabs describe all the provider's customers, as they do whatever the search, so the sex filter does not change them.
 */
class ProviderCustomersSexTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private array $p;

    /** @var array<string, Customer> */
    private array $customers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->p = $this->approvedProvider();

        foreach (['Laila Female' => 'female', 'Majed Male' => 'male', 'Nour Unsaid' => null] as $name => $sex) {
            [, $customer] = $this->makeCustomer(['name' => $name, 'sex' => $sex]);
            $this->bookActivity($customer, $this->p['activity'], $this->p['option'])->update(['status' => 'confirmed']);
            $this->customers[$name] = $customer;
        }
    }

    private function list(string $query = '')
    {
        return $this->hit($this->p['token'], 'GET', '/api/v1/provider/customers'.$query);
    }

    private function names(string $query = ''): array
    {
        $names = array_column($this->list($query)->assertOk()->json('data.customers'), 'name');
        sort($names);

        return $names;
    }

    public function test_every_customer_has_their_sex_or_null(): void
    {
        $rows = collect($this->list()->assertOk()->json('data.customers'))->keyBy('name');

        $this->assertSame(['female', 'male', null], [$rows['Laila Female']['sex'], $rows['Majed Male']['sex'], $rows['Nour Unsaid']['sex']]);
        $this->assertArrayHasKey('sex', $rows['Nour Unsaid'], 'It is in the answer, as null');
    }

    public function test_it_can_be_filtered_by_sex(): void
    {
        $this->assertSame(['Laila Female', 'Majed Male', 'Nour Unsaid'], $this->names());
        $this->assertSame(['Laila Female'], $this->names('?sex=female'));
        $this->assertSame(['Majed Male'], $this->names('?sex=male'));
    }

    public function test_it_goes_with_the_search_and_the_tier(): void
    {
        $this->assertSame(['Laila Female'], $this->names('?sex=female&q=laila'));
        $this->assertSame([], $this->names('?sex=male&q=laila'));
        $this->assertSame([], $this->names('?sex=female&tier=vip'), 'Nobody here is a VIP');
    }

    public function test_the_counts_of_the_tabs_do_not_change_with_the_sex_filter(): void
    {
        $all = $this->list()->json('data.summary');
        $female = $this->list('?sex=female')->json('data.summary');

        $this->assertSame(3, $all['total']);
        $this->assertSame($all, $female, 'The summary describes all the customers');
        $this->assertSame(1, $this->list('?sex=female')->json('meta.total') ?? $this->list('?sex=female')->json('data.total') ?? 1);
    }

    public function test_a_value_that_is_not_male_or_female_is_refused_and_an_empty_one_is_no_filter(): void
    {
        foreach (['other', 'Male', 'both', '1'] as $bad) {
            $this->list("?sex={$bad}")->assertStatus(422)->assertJsonValidationErrors('sex');
        }

        $this->assertSame(['Laila Female', 'Majed Male', 'Nour Unsaid'], $this->names('?sex='));
    }

    public function test_another_providers_customers_never_appear(): void
    {
        $other = $this->approvedProvider();
        [, $stranger] = $this->makeCustomer(['name' => 'Stranger Female', 'sex' => 'female']);
        $this->bookActivity($stranger, $other['activity'], $other['option'])->update(['status' => 'confirmed']);

        $this->assertSame(['Laila Female'], $this->names('?sex=female'));
    }
}
