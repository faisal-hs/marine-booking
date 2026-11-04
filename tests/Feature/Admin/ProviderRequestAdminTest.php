<?php

namespace Tests\Feature\Admin;

use App\Models\Provider;
use App\Models\ProviderRequest;
use App\Notifications\Providers\ProviderRejectedNotification;
use App\Notifications\Providers\ProviderVerifiedNotification;
use App\Services\NotificationLinkResolver;
use App\Services\ProviderRequestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\AssertsTranslated;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The admin side of provider requests. Only admins who hold provider_requests.view / .review (the
 * seeder gives them to the admin and super admin roles and nobody else) can see or decide them.
 */
class ProviderRequestAdminTest extends TestCase
{
    use AssertsTranslated;
    use BuildsProviderScenarios;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    /** A real request, made the way the app makes one, so it carries its provider and three documents. */
    private function makeRequest(array $user = [], array $overrides = []): ProviderRequest
    {
        [$applicant] = $this->makeCustomer($user);
        $payload = $this->providerRequestPayload($overrides);
        $files = array_intersect_key($payload, array_flip(['commercial_registration', 'safety_certification', 'insurance_certificate']));
        $details = array_diff_key($payload, $files);

        return app(ProviderRequestService::class)->submit($applicant, $details, $files);
    }

    private function asAdmin(string $role = 'super_admin'): static
    {
        return $this->actingAs($this->makeAdmin($role), 'web');
    }

    // --- Who may do what -----------------------------------------------------------------

    /** @return array<string, array{0: string, 1: string, 2: bool}> [role, action, allowed] */
    public static function permissionMatrix(): array
    {
        $cases = [];
        foreach (['super_admin' => true, 'admin' => true, 'support_admin' => false, 'content_admin' => false, 'none' => false] as $role => $allowed) {
            foreach (['index', 'show', 'approve', 'reject', 'download'] as $action) {
                $cases["{$role} / {$action}"] = [$role, $action, $allowed];
            }
        }

        return $cases;
    }

    #[DataProvider('permissionMatrix')]
    public function test_only_super_admin_and_admin_can_see_or_decide_requests(string $role, string $action, bool $allowed): void
    {
        $request = $this->makeRequest();
        $document = $request->provider->documents()->firstOrFail();
        $actor = $this->makeAdmin('super_admin');
        $actor->syncRoles($role === 'none' ? [] : [$role]);
        $client = $this->actingAs($actor, 'web');

        $response = match ($action) {
            'index' => $client->get(route('admin.provider-requests.index')),
            'show' => $client->get(route('admin.provider-requests.show', $request)),
            'approve' => $client->post(route('admin.provider-requests.approve', $request)),
            'reject' => $client->post(route('admin.provider-requests.reject', $request), ['rejection_reason' => 'Not clear enough.']),
            'download' => $client->get(route('admin.provider-requests.documents.download', [$request, $document])),
        };

        if ($allowed) {
            $this->assertContains($response->getStatusCode(), [200, 302], "{$role} should be able to {$action}");
        } else {
            $this->assertContains($response->getStatusCode(), [302, 403]);
            if ($role !== 'none') {
                $response->assertForbidden();
            }
        }
    }

    public function test_a_refused_decision_changes_nothing(): void
    {
        $request = $this->makeRequest();
        $support = $this->makeAdmin('support_admin');

        $this->actingAs($support, 'web')->post(route('admin.provider-requests.approve', $request))->assertForbidden();
        $this->actingAs($support, 'web')->post(route('admin.provider-requests.reject', $request), ['rejection_reason' => 'Nope.'])->assertForbidden();

        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame('pending', $request->provider->fresh()->verification_status);
    }

    public function test_someone_who_may_only_view_sees_the_request_but_no_decision_buttons(): void
    {
        $request = $this->makeRequest();
        $viewer = $this->makeAdmin('super_admin');
        $viewer->syncRoles([]);
        $viewer->givePermissionTo('provider_requests.view');

        $page = $this->actingAs($viewer, 'web')->get(route('admin.provider-requests.show', $request))->assertOk();

        $page->assertSee('Jeddah Divers')->assertDontSee(route('admin.provider-requests.approve', $request))->assertDontSee(route('admin.provider-requests.reject', $request));
        $this->actingAs($viewer, 'web')->post(route('admin.provider-requests.approve', $request))->assertForbidden();
    }

    // --- The list ------------------------------------------------------------------------------

    public function test_the_list_opens_on_the_requests_that_are_waiting_and_counts_every_tab(): void
    {
        $waiting = $this->makeRequest(['name' => 'Waiting Person'], ['business_name' => 'Waiting Divers']);
        $approved = $this->makeRequest(['name' => 'Approved Person'], ['business_name' => 'Approved Divers']);
        $rejected = $this->makeRequest(['name' => 'Rejected Person'], ['business_name' => 'Rejected Divers']);
        $service = app(ProviderRequestService::class);
        $reviewer = $this->makeAdmin('admin');
        $service->approve($approved, $reviewer);
        $service->reject($rejected, $reviewer, 'Not clear enough.');
        $admin = $this->makeAdmin();

        $default = $this->actingAs($admin, 'web')->get(route('admin.provider-requests.index'))->assertOk();
        $default->assertSee('Waiting Divers')->assertDontSee('Approved Divers')->assertDontSee('Rejected Divers');
        $default->assertSee('Pending (1)', false)->assertSee('Approved (1)', false)->assertSee('Rejected (1)', false)->assertSee('All (3)', false);

        $this->actingAs($admin, 'web')->get(route('admin.provider-requests.index', ['status' => 'approved']))->assertSee('Approved Divers')->assertDontSee('Waiting Divers');
        $this->actingAs($admin, 'web')->get(route('admin.provider-requests.index', ['status' => 'rejected']))->assertSee('Rejected Divers')->assertDontSee('Waiting Divers');
        $this->actingAs($admin, 'web')->get(route('admin.provider-requests.index', ['status' => 'all']))->assertSee('Waiting Divers')->assertSee('Approved Divers')->assertSee('Rejected Divers');
    }

    public function test_the_list_can_be_filtered_by_source_and_searched(): void
    {
        $upgrade = $this->makeRequest(['name' => 'Karim Nabil', 'phone' => '0501110001', 'email' => 'karim@mail.test'], ['business_name' => 'Coral Divers', 'city' => 'Jeddah', 'area' => 'Obhur']);
        [$registrantUser] = $this->makeProvider('pending');
        $registrantUser->update(['name' => 'Sara Adel', 'phone' => '0502220002', 'email' => 'sara@mail.test']);
        $registration = app(ProviderRequestService::class)->submit($registrantUser, ['business_name' => 'Reef Riders', 'city' => 'Dammam', 'area' => 'Corniche'], array_intersect_key($this->providerRequestPayload(), array_flip(['commercial_registration', 'safety_certification', 'insurance_certificate'])));
        $admin = $this->makeAdmin();
        $get = fn (array $q) => $this->actingAs($admin, 'web')->get(route('admin.provider-requests.index', $q + ['status' => 'all']));

        $get(['source' => 'customer_upgrade'])->assertSee('Coral Divers')->assertDontSee('Reef Riders');
        $get(['source' => 'registration'])->assertSee('Reef Riders')->assertDontSee('Coral Divers');
        foreach (['Coral', 'Karim', '0501110001', 'karim@mail', 'Obhur'] as $term) {
            $get(['search' => $term])->assertSee('Coral Divers')->assertDontSee('Reef Riders');
        }
        foreach (['Reef', 'Sara', 'Dammam', 'Corniche'] as $term) {
            $get(['search' => $term])->assertSee('Reef Riders')->assertDontSee('Coral Divers');
        }
        $this->assertNotNull($upgrade);
        $this->assertNotNull($registration);
    }

    public function test_an_applicant_whose_account_was_deleted_still_shows_by_name(): void
    {
        $request = $this->makeRequest(['name' => 'Gone Person']);
        $request->user->delete();

        $this->asAdmin()->get(route('admin.provider-requests.index'))->assertOk()->assertSee('Gone Person');
        $this->asAdmin()->get(route('admin.provider-requests.show', $request))->assertOk()->assertSee('Gone Person');
    }

    public function test_an_empty_list_says_so(): void
    {
        $this->asAdmin()->get(route('admin.provider-requests.index'))->assertSee(__('admin.provider_requests.none'));
    }

    // --- The details ---------------------------------------------------------------------------

    public function test_the_page_shows_the_applicant_the_business_and_the_three_documents_with_download_links(): void
    {
        $request = $this->makeRequest(['name' => 'Layla Hassan', 'phone' => '0533000111'], ['bio' => 'Family run since 2009.', 'address' => 'Corniche Road']);

        $page = $this->asAdmin()->get(route('admin.provider-requests.show', $request))->assertOk();

        $page->assertSee('Layla Hassan')->assertSee('0533000111')->assertSee('Jeddah Divers')->assertSee('Jeddah')->assertSee('North Obhur')
            ->assertSee('Family run since 2009.')->assertSee('Corniche Road')
            ->assertSee('cr.pdf')->assertSee('safety.pdf')->assertSee('insurance.pdf')
            ->assertSee('Commercial registration')->assertSee('Safety certification')->assertSee('Insurance certificate')
            ->assertSee(route('admin.provider-requests.approve', $request))
            ->assertSee(route('admin.provider-requests.reject', $request));
        foreach ($request->provider->documents as $document) {
            $page->assertSee(route('admin.provider-requests.documents.download', [$request, $document]));
        }
    }

    public function test_a_missing_document_is_flagged(): void
    {
        $request = $this->makeRequest();
        $request->provider->documents()->where('type', 'insurance_certificate')->firstOrFail()->delete();

        $this->asAdmin()->get(route('admin.provider-requests.show', $request))->assertSee('Missing: Insurance certificate');
    }

    public function test_a_decided_request_shows_who_decided_when_and_why_and_no_buttons(): void
    {
        $request = $this->makeRequest();
        $reviewer = $this->makeAdmin('admin');
        $reviewer->update(['name' => 'Reviewer Rana']);
        app(ProviderRequestService::class)->reject($request, $reviewer, 'The registration is unreadable.');

        $page = $this->asAdmin()->get(route('admin.provider-requests.show', $request))->assertOk();

        $page->assertSee('The registration is unreadable.')->assertSee('Reviewer Rana')
            ->assertDontSee(route('admin.provider-requests.approve', $request))->assertDontSee(route('admin.provider-requests.reject', $request));
    }

    // --- Approving -----------------------------------------------------------------------------

    public function test_approving_makes_the_applicant_a_provider_records_the_reviewer_and_tells_them(): void
    {
        $request = $this->makeRequest();
        $reviewer = $this->makeAdmin('admin');

        $this->actingAs($reviewer, 'web')->post(route('admin.provider-requests.approve', $request))
            ->assertRedirect(route('admin.provider-requests.show', $request))->assertSessionHas('status');

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($reviewer->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
        $this->assertSame('verified', $request->provider->fresh()->verification_status);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $request->user_id)->where('type', ProviderVerifiedNotification::class)->count());
    }

    public function test_after_approval_the_person_can_actually_use_the_provider_account(): void
    {
        $request = $this->makeRequest();
        $token = $this->tokenFor($request->user, 'customer');
        $this->asAccount($token)->postJson('/api/v1/account/switch', ['account_type' => 'provider'])->assertStatus(422);

        $this->asAdmin()->post(route('admin.provider-requests.approve', $request));

        $this->asAccount($token)->postJson('/api/v1/account/switch', ['account_type' => 'provider'])->assertOk()->assertJsonPath('data.account.account_type', 'provider');
    }

    public function test_a_request_cannot_be_decided_twice(): void
    {
        $request = $this->makeRequest();
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->post(route('admin.provider-requests.approve', $request));

        $this->actingAs($admin, 'web')->post(route('admin.provider-requests.approve', $request))->assertSessionHas('error');
        $this->actingAs($admin, 'web')->post(route('admin.provider-requests.reject', $request), ['rejection_reason' => 'Changed my mind.'])->assertSessionHas('error');

        $this->assertSame('approved', $request->fresh()->status);
        $this->assertSame('verified', $request->provider->fresh()->verification_status);
    }

    public function test_a_deleted_or_suspended_applicant_cannot_be_approved(): void
    {
        $deleted = $this->makeRequest();
        $deleted->user->delete();
        $suspended = $this->makeRequest();
        $suspended->user->update(['status' => 'suspended']);
        $admin = $this->makeAdmin();

        foreach ([$deleted, $suspended] as $request) {
            $this->actingAs($admin, 'web')->post(route('admin.provider-requests.approve', $request))->assertSessionHas('error');
            $this->assertSame('pending', $request->fresh()->status);
            $this->assertSame('pending', $request->provider->fresh()->verification_status);
        }
    }

    // --- Rejecting -----------------------------------------------------------------------------

    public function test_rejecting_needs_a_reason_and_tells_the_applicant_why(): void
    {
        $request = $this->makeRequest();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.provider-requests.reject', $request), ['rejection_reason' => ''])->assertSessionHasErrors('rejection_reason');
        $this->actingAs($admin, 'web')->post(route('admin.provider-requests.reject', $request), ['rejection_reason' => 'no'])->assertSessionHasErrors('rejection_reason');
        $this->assertSame('pending', $request->fresh()->status);

        $this->actingAs($admin, 'web')->post(route('admin.provider-requests.reject', $request), ['rejection_reason' => 'The commercial registration has expired.'])->assertSessionHas('status');

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame('The commercial registration has expired.', $request->rejection_reason);
        $this->assertSame('rejected', $request->provider->fresh()->verification_status);
        $note = DB::table('notifications')->where('notifiable_id', $request->user_id)->where('type', ProviderRejectedNotification::class)->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('commercial registration has expired', $note->data);
    }

    public function test_the_applicant_sees_the_reason_and_can_send_it_again(): void
    {
        $request = $this->makeRequest();
        $token = $this->tokenFor($request->user, 'customer');
        $this->asAdmin()->post(route('admin.provider-requests.reject', $request), ['rejection_reason' => 'Please upload a clearer registration.']);

        $this->asAccount($token)->getJson('/api/v1/account/provider-request')->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.request.rejection_reason', 'Please upload a clearer registration.');

        $payload = $this->providerRequestPayload();
        $this->asAccount($token)->post('/api/v1/account/provider-request', $payload, ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->assertSame(1, ProviderRequest::where('status', 'pending')->count(), 'It is back in the admins\' queue.');
    }

    public function test_the_reason_is_shown_escaped(): void
    {
        $request = $this->makeRequest();
        $this->asAdmin()->post(route('admin.provider-requests.reject', $request), ['rejection_reason' => '<script>alert(1)</script> unreadable']);

        $this->asAdmin()->get(route('admin.provider-requests.show', $request))
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    // --- The provider's own page and the request never disagree --------------------------------------

    public function test_verifying_from_the_provider_page_settles_the_request_too(): void
    {
        $request = $this->makeRequest();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.providers.verify', $request->provider))->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame($admin->id, $request->reviewed_by);
        $this->assertNotNull($request->reviewed_at);
    }

    public function test_rejecting_from_the_provider_page_settles_the_request_too(): void
    {
        $request = $this->makeRequest();
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'web')->post(route('admin.providers.reject', $request->provider), ['rejection_reason' => 'Licence photo was unreadable'])->assertSessionHasNoErrors();

        $request->refresh();
        $this->assertSame('rejected', $request->status);
        $this->assertSame('Licence photo was unreadable', $request->rejection_reason);
        $this->assertSame($admin->id, $request->reviewed_by);
    }

    public function test_the_provider_page_links_to_the_request(): void
    {
        $request = $this->makeRequest();

        $this->asAdmin()->get(route('admin.providers.show', $request->provider))->assertOk()
            ->assertSee(route('admin.provider-requests.show', $request))->assertSee('North Obhur');
    }

    public function test_an_admin_can_set_city_and_area_when_creating_and_editing_a_provider(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'web')->post(route('admin.providers.store'), [
            'name' => 'Omar Said',
            'phone' => '0555000111',
            'business_name' => 'Blue Hole',
            'city' => 'Dahab',
            'area' => 'Blue Lagoon',
        ])->assertSessionHasNoErrors();
        $provider = Provider::where('business_name', 'Blue Hole')->firstOrFail();
        $this->assertSame(['Dahab', 'Blue Lagoon'], [$provider->city, $provider->area]);

        $this->actingAs($admin, 'web')->put(route('admin.providers.update', $provider), [
            'name' => 'Omar Said',
            'phone' => '0555000111',
            'business_name' => 'Blue Hole',
            'city' => 'Sharm',
            'area' => 'Naama Bay',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['Sharm', 'Naama Bay'], [$provider->fresh()->city, $provider->fresh()->area]);
    }

    // --- Documents ---------------------------------------------------------------------------------

    public function test_a_document_can_be_downloaded_safely(): void
    {
        $request = $this->makeRequest();
        $document = $request->provider->documents()->where('type', 'commercial_registration')->firstOrFail();

        $response = $this->asAdmin()->get(route('admin.provider-requests.documents.download', [$request, $document]))->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('cr.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_a_document_of_another_provider_cannot_be_fetched_through_this_request(): void
    {
        $request = $this->makeRequest();
        $other = $this->makeRequest();
        $foreign = $other->provider->documents()->firstOrFail();

        $this->asAdmin()->get(route('admin.provider-requests.documents.download', [$request, $foreign]))->assertNotFound();
    }

    // --- Where admins notice it --------------------------------------------------------------------

    public function test_the_sidebar_shows_how_many_requests_are_waiting_to_those_who_may_review(): void
    {
        $this->makeRequest();
        $this->makeRequest();
        $decided = $this->makeRequest();
        app(ProviderRequestService::class)->reject($decided, $this->makeAdmin('admin'), 'Not clear enough.');

        $page = $this->asAdmin('admin')->get(route('admin.dashboard'));

        $page->assertSee(route('admin.provider-requests.index'));
        $this->assertSame(1, preg_match('#<span\s+class="ms-auto text-\[10px\] bg-amber-500[^>]*>\s*2\s*</span>#', $page->getContent()), 'The badge should read 2.');
        $this->asAdmin('support_admin')->get(route('admin.dashboard'))->assertDontSee(route('admin.provider-requests.index'));
    }

    public function test_there_is_no_badge_when_nothing_is_waiting(): void
    {
        $page = $this->asAdmin()->get(route('admin.dashboard'));

        $this->assertSame(0, preg_match('#bg-amber-500 text-white rounded-full#', $page->getContent()));
    }

    public function test_the_dashboard_counts_waiting_requests_and_links_to_them(): void
    {
        $this->makeRequest();
        $this->makeRequest();

        $this->asAdmin()->get(route('admin.dashboard'))->assertOk()
            ->assertSee(route('admin.provider-requests.index'));
        $this->asAdmin('support_admin')->get(route('admin.dashboard'))->assertOk()->assertDontSee(route('admin.provider-requests.index'));
    }

    public function test_the_notification_an_admin_gets_opens_the_request(): void
    {
        $admin = $this->makeAdmin('admin');
        $request = $this->makeRequest();

        $url = app(NotificationLinkResolver::class)->urlFor(['provider_request_uuid' => $request->uuid], $admin);

        $this->assertStringEndsWith('/admin/provider-requests/'.$request->uuid, $url);
        $this->assertNull(app(NotificationLinkResolver::class)->urlFor(['provider_request_uuid' => $request->uuid], $this->makeAdmin('support_admin')));
        $this->actingAs($admin, 'web')->get(route('admin.notifications.index'))->assertSee('/admin/provider-requests/'.$request->uuid);
    }

    // --- Language ---------------------------------------------------------------------------------------

    public function test_every_screen_is_fully_translated_in_both_languages(): void
    {
        $pending = $this->makeRequest();
        $approved = $this->makeRequest();
        $rejected = $this->makeRequest();
        $reviewer = $this->makeAdmin('admin');
        app(ProviderRequestService::class)->approve($approved, $reviewer);
        app(ProviderRequestService::class)->reject($rejected, $reviewer, 'Not clear enough.');
        $admin = $this->makeAdmin();

        $this->inEachAdminLanguage(function (string $locale) use ($admin, $pending, $approved, $rejected) {
            foreach (
                [
                    'index' => route('admin.provider-requests.index'),
                    'index (all)' => route('admin.provider-requests.index', ['status' => 'all']),
                    'show pending' => route('admin.provider-requests.show', $pending),
                    'show approved' => route('admin.provider-requests.show', $approved),
                    'show rejected' => route('admin.provider-requests.show', $rejected),
                    'dashboard' => route('admin.dashboard'),
                    'provider page' => route('admin.providers.show', $pending->provider),
                ] as $name => $url
            ) {
                $this->assertNoRawTranslationKeys($this->actingAs($admin, 'web')->get($url)->assertOk(), "({$name}, {$locale})");
            }
        });
    }
}
