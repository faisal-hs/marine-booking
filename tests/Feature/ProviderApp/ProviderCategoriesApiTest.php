<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/** GET /provider/categories: what a provider can choose to work in. */
class ProviderCategoriesApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    public function test_it_lists_the_active_categories_in_their_order_with_id_name_and_icon(): void
    {
        $diving = $this->makeCategory('Diving');
        $yacht = $this->makeCategory('Yacht');
        $this->makeCategory('Hidden')->update(['status' => 'inactive']);
        $this->makeCategory('Removed')->delete();
        DB::table('categories')->where('id', $diving->id)->update(['sort_order' => 2]);
        DB::table('categories')->where('id', $yacht->id)->update(['sort_order' => 1, 'image' => 'categories/yacht.svg']);

        $data = $this->hit($this->unapprovedProvider()['token'], 'GET', '/api/v1/provider/categories')->assertOk()->json('data');

        $this->assertSame(['Yacht', 'Diving'], array_column($data, 'name'), 'Active only, in order');
        $this->assertSame(['id', 'name', 'icon'], array_keys($data[0]));
        $this->assertSame($yacht->id, $data[0]['id']);
        $this->assertStringEndsWith('categories/yacht.svg', $data[0]['icon']);
        $this->assertNull($data[1]['icon'], 'A category with no image has a null icon');
    }

    public function test_an_unapproved_provider_can_see_them_because_they_choose_while_applying(): void
    {
        $this->makeCategory('Diving');

        foreach (['pending', 'rejected'] as $status) {
            $this->hit($this->unapprovedProvider($status)['token'], 'GET', '/api/v1/provider/categories')->assertOk();
        }
    }

    public function test_an_empty_list_is_an_empty_array(): void
    {
        $token = $this->approvedProvider()['token'];
        Category::query()->delete();

        $response = $this->hit($token, 'GET', '/api/v1/provider/categories')->assertOk();

        $this->assertSame([], $response->json('data'));
    }
}
