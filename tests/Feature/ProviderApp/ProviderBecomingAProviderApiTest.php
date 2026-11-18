<?php

namespace Tests\Feature\ProviderApp;

use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\ProviderRequest;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsProviderAppScenarios;
use Tests\TestCase;

/**
 * A CUSTOMER can become a provider with the token they already have: GET /provider/categories, GET /provider/verification, POST /provider/apply, GET /provider/apply and
 * POST /provider/apply/resubmit accept a customer's token as well as a provider's. Nothing else in the provider app does.
 */
class ProviderBecomingAProviderApiTest extends TestCase
{
    use BuildsProviderAppScenarios;
    use RefreshDatabase;

    private const REQUIRED = ['commercial_registration', 'safety_certification', 'insurance_certificate'];

    private const FIVE = ['/api/v1/provider/categories', '/api/v1/provider/apply', '/api/v1/provider/verification'];

    private User $user;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-18 12:00:00');
        Storage::fake(config('providers.documents.disk'));
        $this->seed(RolesAndPermissionsSeeder::class);
        [$this->user] = $this->makeCustomer(['name' => 'Layla Hassan']);
        $this->token = $this->tokenFor($this->user, 'customer');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function fields(): array
    {
        return ['business_name' => 'Layla Marine Tours', 'business_type' => 'Tour operator', 'category_ids' => [$this->makeCategory('Boat tours')->id], 'city' => 'Jeddah',
            'marina_name' => 'Obhur Marina', 'area' => 'North Obhur', 'phone' => '+966551112233', 'email' => 'info@laylamarine.sa'];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->create("{$name}.pdf", 40, 'application/pdf');
    }

    /** @param  list<string>  $keys */
    private function files(array $keys): array
    {
        return array_combine($keys, array_map(fn ($key) => $this->pdf($key), $keys));
    }

    private function apply(array $extra, ?string $token = null)
    {
        return $this->hitWithFiles($token ?? $this->token, '/api/v1/provider/apply', array_merge($this->fields(), $extra));
    }

    private function provider(): ?Provider
    {
        return Provider::where('user_id', $this->user->id)->first();
    }

    // --- Looking, before applying ------------------------------------------------------------------------------------------

    public function test_a_customer_can_read_the_categories_an_application_needs(): void
    {
        $category = $this->makeCategory('Fishing');

        $r = $this->hit($this->token, 'GET', '/api/v1/provider/categories')->assertOk();

        $this->assertContains($category->id, array_column($r->json('data'), 'id'));
    }

    public function test_a_customer_with_no_provider_record_sees_every_document_as_missing_with_which_are_required(): void
    {
        $rows = $this->hit($this->token, 'GET', '/api/v1/provider/verification')->assertOk()->json('data');

        $this->assertSame(['commercial_registration', 'safety_certification', 'insurance_certificate', 'business_license', 'identity_document', 'professional_certification', 'insurance', 'other'], array_column($rows, 'key'));
        $this->assertSame(['missing'], array_unique(array_column($rows, 'status')));
        $this->assertSame(self::REQUIRED, array_column(array_filter($rows, fn ($r) => $r['is_mandatory']), 'key'));
        $this->assertSame([null], array_unique(array_column($rows, 'file_url')));
        $this->assertNull($this->provider(), 'Looking made no provider record');
    }

    public function test_a_customer_who_has_not_applied_is_told_so_and_nothing_is_made(): void
    {
        $r = $this->hit($this->token, 'GET', '/api/v1/provider/apply')->assertNotFound();

        $this->assertSame(['RESOURCE_NOT_FOUND', 'You have not submitted an application yet.'], [$r->json('code'), $r->json('message')]);
        $this->assertNull($this->provider());
        $this->assertSame(0, ProviderRequest::count());
    }

    // --- Applying -----------------------------------------------------------------------------------------------------------

    public function test_a_customer_applies_with_their_own_token_and_becomes_an_applicant(): void
    {
        $r = $this->apply($this->files(self::REQUIRED))->assertCreated();

        $this->assertSame(['status', 'message', 'data'], array_keys($r->json()));
        $this->assertSame('pending', $r->json('data.status'));
        $this->assertSame('2026-11-18T12:00:00Z', $r->json('data.submitted_at'));

        $provider = $this->provider();
        $this->assertNotNull($provider, 'A provider record was made for the customer');
        $this->assertSame(['Layla Marine Tours', 'pending'], [$provider->business_name, $provider->verification_status]);
        $application = ProviderRequest::firstOrFail();
        $this->assertSame([$this->user->id, $provider->id, 'customer_upgrade', 'pending'], [$application->user_id, $application->provider_id, $application->source, $application->status]);
        $this->assertSame($application->id, $r->json('data.application_id'));
        $this->assertEqualsCanonicalizing(self::REQUIRED, $provider->documents()->pluck('type')->all());
        $this->assertSame('customer', $this->user->refresh()->user_type, 'They are still a customer until an admin approves');
    }

    public function test_the_customer_can_then_follow_the_application_and_the_documents(): void
    {
        $this->apply($this->files(self::REQUIRED))->assertCreated();

        $apply = $this->hit($this->token, 'GET', '/api/v1/provider/apply')->assertOk()->json('data');
        $this->assertSame(['pending', null, false], [$apply['status'], $apply['note'], $apply['can_resubmit']]);
        $this->assertSame(['provider', 'stats'], array_slice(array_keys($apply), 6));
        $this->assertSame('Layla Marine Tours', $apply['provider']['business_name']);

        $documents = collect($this->hit($this->token, 'GET', '/api/v1/provider/verification')->json('data'))->keyBy('key');
        foreach (self::REQUIRED as $key) {
            $this->assertSame('pending', $documents[$key]['status'], $key);
        }
        $this->assertSame('missing', $documents['business_license']['status']);
    }

    public function test_the_documents_are_dynamic_for_a_customer_too_an_optional_one_is_kept_and_an_unknown_key_is_refused(): void
    {
        $bad = $this->apply(['documents' => $this->files([...self::REQUIRED, 'passport_of_the_cat'])])->assertStatus(422);
        $this->assertArrayHasKey('documents.passport_of_the_cat', $bad->json('errors'));
        $this->assertNull($this->provider(), 'A refused application makes nothing');

        $this->apply($this->files(self::REQUIRED) + ['documents' => $this->files(['business_license'])])->assertCreated();

        $this->assertEqualsCanonicalizing([...self::REQUIRED, 'business_license'], $this->provider()->documents()->pluck('type')->all());
    }

    public function test_a_missing_required_document_is_refused_and_leaves_no_trace(): void
    {
        $r = $this->apply($this->files(['commercial_registration', 'safety_certification']))->assertStatus(422);

        $this->assertArrayHasKey('insurance_certificate', $r->json('errors'));
        $this->assertNull($this->provider());
        $this->assertSame(0, ProviderRequest::count());
        $this->assertSame(0, ProviderDocument::count());
    }

    public function test_applying_again_while_the_application_waits_is_a_conflict(): void
    {
        $this->apply($this->files(self::REQUIRED))->assertCreated();

        $r = $this->apply($this->files(self::REQUIRED))->assertStatus(409);

        $this->assertSame('INVALID_STATUS_TRANSITION', $r->json('code'));
        $this->assertSame(1, ProviderRequest::count());
    }

    public function test_a_customer_who_is_already_an_approved_provider_cannot_apply_again(): void
    {
        $this->apply($this->files(self::REQUIRED))->assertCreated();
        $this->provider()->update(['verification_status' => 'verified']);

        $this->apply($this->files(self::REQUIRED))->assertStatus(409)->assertJsonPath('code', 'INVALID_STATUS_TRANSITION');
    }

    // --- A rejected application ------------------------------------------------------------------------------------------------

    public function test_a_customer_whose_application_was_rejected_can_send_it_again_with_the_same_token(): void
    {
        $this->apply($this->files(self::REQUIRED))->assertCreated();
        $application = ProviderRequest::firstOrFail();
        $application->update(['status' => 'rejected', 'rejection_reason' => 'The registration is not clear', 'reviewed_at' => now()]);

        $apply = $this->hit($this->token, 'GET', '/api/v1/provider/apply')->assertOk()->json('data');
        $this->assertSame(['rejected', 'The registration is not clear', true], [$apply['status'], $apply['note'], $apply['can_resubmit']]);

        $r = $this->hitWithFiles($this->token, '/api/v1/provider/apply/resubmit', array_merge($this->fields(), ['application_id' => $application->id, 'documents' => $this->files(['commercial_registration'])]))->assertCreated();

        $this->assertSame(['pending', $application->id], [$r->json('data.status'), $r->json('data.previous_application_id')]);
        $this->assertSame(2, ProviderRequest::count());
        $this->assertSame('pending', $this->hit($this->token, 'GET', '/api/v1/provider/apply')->json('data.status'));
    }

    // --- The other doors agree ---------------------------------------------------------------------------------------------------

    public function test_the_customer_app_and_the_account_endpoint_see_the_same_application(): void
    {
        $this->apply($this->files(self::REQUIRED))->assertCreated();

        $profile = $this->hit($this->token, 'GET', '/api/v1/customer-app/profile')->assertOk()->json('data');
        $account = $this->hit($this->token, 'GET', '/api/v1/account/provider-request')->assertOk()->json('data');

        $this->assertSame('pending', $profile['provider_status']);
        $this->assertSame('pending', $account['status'] ?? $account['provider_request_status'] ?? null);
    }

    public function test_an_application_made_through_the_account_endpoint_can_be_followed_through_the_provider_one(): void
    {
        $this->asAccount($this->token)->post('/api/v1/account/provider-request', $this->providerRequestPayload(), ['Accept' => 'application/json'])->assertCreated();

        $apply = $this->hit($this->token, 'GET', '/api/v1/provider/apply')->assertOk()->json('data');

        $this->assertSame('pending', $apply['status']);
        $this->assertSame(1, ProviderRequest::count());
    }

    public function test_one_customers_application_is_not_anothers(): void
    {
        $this->apply($this->files(self::REQUIRED))->assertCreated();
        [$other] = $this->makeCustomer();
        $otherToken = $this->tokenFor($other, 'customer');

        $this->hit($otherToken, 'GET', '/api/v1/provider/apply')->assertNotFound();
        $this->assertSame(['missing'], array_unique(array_column($this->hit($otherToken, 'GET', '/api/v1/provider/verification')->json('data'), 'status')));
    }

    // --- What stays closed -----------------------------------------------------------------------------------------------------

    public function test_every_other_provider_route_still_refuses_a_customer_token_even_one_who_has_applied(): void
    {
        $this->apply($this->files(self::REQUIRED))->assertCreated();

        // EVERY other provider GET route that takes no parameter (so any route added later is covered too), apart from the signed download link, which has no token at all.
        $others = collect(app('router')->getRoutes()->getRoutes())->filter(fn ($route) => in_array('GET', $route->methods(), true) && str_starts_with($route->uri(), 'api/v1/provider/') && ! str_contains($route->uri(), '{'))
            ->map(fn ($route) => '/'.$route->uri())->reject(fn ($uri) => in_array($uri, self::FIVE, true) || str_ends_with($uri, '/download'))->values();
        $this->assertGreaterThan(15, $others->count(), 'There are many provider routes to check');

        foreach ($others as $uri) {
            $this->hit($this->token, 'GET', $uri)->assertForbidden();
        }
        $this->hit($this->token, 'POST', '/api/v1/provider/document-types', [])->assertForbidden();
        $this->hit($this->token, 'POST', '/api/v1/provider/verification', [])->assertForbidden();
        $this->hit($this->token, 'POST', '/api/v1/provider/withdrawals', [])->assertForbidden();
    }

    public function test_a_staff_token_and_nobody_may_use_none_of_the_five(): void
    {
        $staff = $this->tokenFor(User::factory()->admin()->create(), 'admin');

        foreach (self::FIVE as $uri) {
            $this->hit($staff, 'GET', $uri)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
            $this->hit(null, 'GET', $uri)->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
        }
        $this->hitWithFiles($staff, '/api/v1/provider/apply', array_merge($this->fields(), $this->files(self::REQUIRED)))->assertForbidden();
        $this->hitWithFiles(null, '/api/v1/provider/apply', array_merge($this->fields(), $this->files(self::REQUIRED)))->assertUnauthorized();
        $this->assertSame(0, ProviderRequest::count());
    }

    public function test_a_provider_token_still_works_on_the_five(): void
    {
        $p = $this->unapprovedProvider('pending');

        foreach (self::FIVE as $uri) {
            $this->assertContains($this->hit($p['token'], 'GET', $uri)->status(), [200, 404], $uri);
        }
        $this->apply($this->files(self::REQUIRED), $p['token'])->assertCreated();
        $this->assertSame('registration', ProviderRequest::where('provider_id', $p['provider']->id)->value('source'), 'A provider who registered as one is not an upgrade');
    }
}
