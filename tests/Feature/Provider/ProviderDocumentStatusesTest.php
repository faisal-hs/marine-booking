<?php

namespace Tests\Feature\Provider;

use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\ProviderDocumentEvent;
use App\Models\VerificationDocumentType;
use App\Services\ProviderDocumentStatuses;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * Where a document type stands is WORKED OUT from what is on file and the history, never stored:
 * update_requested (an admin asked and nobody has uploaded since) beats everything; then missing, expired, and the status of the version in force.
 */
class ProviderDocumentStatusesTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private Provider $provider;

    private ProviderDocumentStatuses $statuses;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
        [, $this->provider] = $this->makeProvider('verified');
        $this->statuses = app(ProviderDocumentStatuses::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function doc(string $type, string $status = 'pending', array $attrs = []): ProviderDocument
    {
        $document = ProviderDocument::create($attrs + ['provider_id' => $this->provider->id, 'type' => $type, 'original_name' => "{$type}.pdf", 'path' => "p/{$type}.pdf", 'mime_type' => 'application/pdf', 'size_bytes' => 5, 'status' => $status]);
        $this->event('submitted', $type, $document);

        return $document;
    }

    private function event(string $action, string $type, ?ProviderDocument $document = null, ?string $note = null, ?string $at = null): ProviderDocumentEvent
    {
        return ProviderDocumentEvent::create(['provider_id' => $this->provider->id, 'document_type' => $type, 'provider_document_id' => $document?->id, 'action' => $action, 'note' => $note, 'created_at' => $at ?? now()]);
    }

    private function statusOf(string $type): string
    {
        return $this->statuses->of($this->provider->fresh(), VerificationDocumentType::where('key', $type)->firstOrFail())['status'];
    }

    public function test_nothing_uploaded_is_missing(): void
    {
        $this->assertSame('missing', $this->statusOf('insurance'));
    }

    public function test_a_version_takes_the_status_an_admin_gave_it(): void
    {
        $this->doc('commercial_registration', 'pending');
        $this->doc('safety_certification', 'approved');
        $this->doc('insurance_certificate', 'rejected');

        $this->assertSame(['pending', 'approved', 'rejected'], [$this->statusOf('commercial_registration'), $this->statusOf('safety_certification'), $this->statusOf('insurance_certificate')]);
    }

    public function test_is_done_means_approved_and_nothing_else(): void
    {
        $this->doc('safety_certification', 'approved');
        $this->doc('insurance_certificate', 'rejected');

        $rows = $this->statuses->all($this->provider->fresh())->keyBy(fn ($r) => $r['type']->key);

        $this->assertSame('approved', $rows['safety_certification']['status']);
        $this->assertSame('rejected', $rows['insurance_certificate']['status']);
        $this->assertSame('missing', $rows['business_license']['status']);
    }

    // --- Expiry ------------------------------------------------------------------------------------------------------

    public function test_an_approved_document_expires_the_day_after_its_date_and_not_before(): void
    {
        $document = $this->doc('safety_certification', 'approved', ['expires_at' => '2026-11-15']);
        $this->assertSame('approved', $this->statusOf('safety_certification'), 'Valid until the end of the day it names');

        $document->update(['expires_at' => '2026-11-14']);
        $this->assertSame('expired', $this->statusOf('safety_certification'));

        $document->update(['expires_at' => '2026-11-16']);
        $this->assertSame('approved', $this->statusOf('safety_certification'));

        $document->update(['expires_at' => null]);
        Carbon::setTestNow('2030-01-01');
        $this->assertSame('approved', $this->statusOf('safety_certification'), 'No date, no expiry');
    }

    public function test_only_an_approved_document_can_expire(): void
    {
        $this->doc('safety_certification', 'pending', ['expires_at' => '2026-01-01']);
        $this->doc('insurance_certificate', 'rejected', ['expires_at' => '2026-01-01']);

        $this->assertSame(['pending', 'rejected'], [$this->statusOf('safety_certification'), $this->statusOf('insurance_certificate')]);
    }

    // --- An admin asking for an update ----------------------------------------------------------------------------

    public function test_a_request_beats_every_other_status_until_the_provider_uploads(): void
    {
        $this->doc('commercial_registration', 'approved');
        $this->doc('safety_certification', 'pending');
        $this->doc('insurance_certificate', 'rejected');
        $this->doc('business_license', 'approved', ['expires_at' => '2020-01-01']);
        foreach (['commercial_registration', 'safety_certification', 'insurance_certificate', 'business_license', 'identity_document'] as $type) {
            $this->event('update_requested', $type, null, 'Please update');
        }

        foreach (['commercial_registration', 'safety_certification', 'insurance_certificate', 'business_license', 'identity_document'] as $type) {
            $this->assertSame('update_requested', $this->statusOf($type), $type);
        }
    }

    public function test_the_provider_uploading_closes_the_request_but_a_decision_does_not(): void
    {
        $document = $this->doc('safety_certification', 'approved');
        $this->event('update_requested', 'safety_certification', $document, 'Please update');
        $this->assertSame('update_requested', $this->statusOf('safety_certification'));

        $this->event('approved', 'safety_certification', $document);
        $this->assertSame('update_requested', $this->statusOf('safety_certification'), 'An admin deciding is not the provider answering');

        $new = $this->doc('safety_certification', 'pending');
        $document->update(['superseded_at' => now()]);
        $this->assertSame('pending', $this->statusOf('safety_certification'));
        $this->assertSame($new->id, $this->statuses->of($this->provider->fresh(), VerificationDocumentType::where('key', 'safety_certification')->first())['document']->id);
    }

    public function test_a_later_request_opens_again_after_an_earlier_one_was_answered(): void
    {
        $this->doc('safety_certification', 'approved');
        $this->event('update_requested', 'safety_certification');
        $this->doc('safety_certification', 'pending');
        $this->assertSame('pending', $this->statusOf('safety_certification'));

        $this->event('update_requested', 'safety_certification', null, 'Once more');

        $this->assertSame('update_requested', $this->statusOf('safety_certification'));
    }

    public function test_when_an_upload_and_a_request_share_a_second_the_later_one_counts(): void
    {
        $at = '2026-11-15 12:00:00';
        $this->event('submitted', 'insurance', null, null, $at);
        $this->event('update_requested', 'insurance', null, 'Now', $at);
        $this->assertSame('update_requested', $this->statusOf('insurance'), 'The request came last');

        $this->event('submitted', 'identity_document', null, null, $at);
        $this->event('update_requested', 'identity_document', null, 'x', '2026-11-15 11:59:59');
        $this->event('submitted', 'identity_document', null, null, $at);
        $this->assertSame('missing', $this->statusOf('identity_document'), 'The upload came last: the request is answered');
    }

    public function test_the_request_carries_its_reason_and_when_it_was_made(): void
    {
        $this->event('update_requested', 'business_license', null, 'Send your trade licence', '2026-11-14 09:30:00');

        $row = $this->statuses->of($this->provider->fresh(), VerificationDocumentType::where('key', 'business_license')->first());

        $this->assertSame(['Send your trade licence', '2026-11-14 09:30:00'], [$row['request']->note, $row['request']->created_at->toDateTimeString()]);
    }

    // --- Which version counts ------------------------------------------------------------------------------------------

    public function test_a_replaced_version_never_counts(): void
    {
        $old = $this->doc('safety_certification', 'approved');
        $old->update(['superseded_at' => now()->subDay()]);
        $this->doc('safety_certification', 'rejected');

        $this->assertSame('rejected', $this->statusOf('safety_certification'));
        $this->assertSame(1, $this->provider->fresh()->documents()->where('type', 'safety_certification')->count());
        $this->assertSame(2, $this->provider->fresh()->documentVersions()->where('type', 'safety_certification')->count(), 'The history still has both');
    }

    public function test_when_several_versions_are_in_force_the_newest_counts(): void
    {
        $this->doc('other', 'approved', ['created_at' => '2026-11-01 10:00:00']);
        $this->doc('other', 'pending', ['created_at' => '2026-11-10 10:00:00']);

        $this->assertSame('pending', $this->statusOf('other'));
    }

    // --- Which types are asked for ---------------------------------------------------------------------------------

    public function test_only_active_types_are_listed_in_the_admins_order(): void
    {
        VerificationDocumentType::where('key', 'insurance')->update(['is_active' => false]);
        VerificationDocumentType::where('key', 'other')->update(['sort_order' => 0]);

        $keys = $this->statuses->all($this->provider->fresh())->map(fn ($r) => $r['type']->key)->all();

        $this->assertNotContains('insurance', $keys);
        $this->assertSame('other', $keys[0]);
        $this->assertCount(7, $keys);
    }

    public function test_a_document_of_a_switched_off_type_is_simply_not_listed(): void
    {
        $this->doc('insurance', 'approved');
        VerificationDocumentType::where('key', 'insurance')->update(['is_active' => false]);

        $keys = $this->statuses->all($this->provider->fresh())->map(fn ($r) => $r['type']->key)->all();

        $this->assertNotContains('insurance', $keys);
        $this->assertSame(1, $this->provider->fresh()->documents()->where('type', 'insurance')->count(), 'It is still on file');
    }

    public function test_one_providers_documents_never_show_on_another(): void
    {
        [, $other] = $this->makeProvider('verified');
        $this->doc('safety_certification', 'approved');
        $this->event('update_requested', 'insurance');

        $row = $this->statuses->all($other)->keyBy(fn ($r) => $r['type']->key);

        $this->assertSame(['missing', 'missing'], [$row['safety_certification']['status'], $row['insurance']['status']]);
    }
}
