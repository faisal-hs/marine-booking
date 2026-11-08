<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsProviderScenarios;
use Tests\TestCase;

/**
 * The three migrations behind document review. Documents that existed before had no status or history: those of a provider an admin verified were
 * what the verification was based on (approved), the rest are waiting. The history is written once for what already exists, and running a migration
 * again never changes anything.
 */
class DocumentReviewMigrationsTest extends TestCase
{
    use BuildsProviderScenarios;
    use RefreshDatabase;

    private function migration(string $name): object
    {
        return require database_path("migrations/{$name}.php");
    }

    private function undo(): void
    {
        $this->migration('2026_11_10_100200_create_provider_document_events_table')->down();
        $this->migration('2026_11_10_100100_add_review_to_provider_documents_table')->down();
    }

    private function redo(): void
    {
        $this->migration('2026_11_10_100100_add_review_to_provider_documents_table')->up();
        $this->migration('2026_11_10_100200_create_provider_document_events_table')->up();
    }

    /** A document as it was before review existed. */
    private function legacy(int $providerId, string $type, string $created): int
    {
        return DB::table('provider_documents')->insertGetId(['uuid' => (string) Str::uuid(), 'provider_id' => $providerId, 'type' => $type, 'original_name' => "{$type}.pdf", 'path' => "p/{$type}.pdf",
            'mime_type' => 'application/pdf', 'size_bytes' => 5, 'created_at' => $created, 'updated_at' => $created]);
    }

    // --- The types ---------------------------------------------------------------------------------------------------

    public function test_the_types_migration_creates_the_eight_and_runs_again_without_changing_anything(): void
    {
        $migration = $this->migration('2026_11_10_100000_create_verification_document_types_table');
        $migration->down();
        $this->assertFalse(Schema::hasTable('verification_document_types'));

        $migration->up();
        $this->assertSame(8, DB::table('verification_document_types')->count());
        $this->assertSame(['commercial_registration', 'insurance_certificate', 'safety_certification'], DB::table('verification_document_types')->where('is_mandatory', true)->orderBy('key')->pluck('key')->all());
        $this->assertSame(0, DB::table('verification_document_types')->whereNull('title_ar')->orWhere('title_ar', '')->count(), 'A name in Arabic for each');

        DB::table('verification_document_types')->where('key', 'business_license')->update(['title_en' => 'Trade licence', 'is_mandatory' => true, 'is_active' => false, 'sort_order' => 77]);
        $migration->up();

        $this->assertSame(8, DB::table('verification_document_types')->count());
        $edited = DB::table('verification_document_types')->where('key', 'business_license')->first();
        $this->assertSame(['Trade licence', 1, 0, 77], [$edited->title_en, $edited->is_mandatory, $edited->is_active, $edited->sort_order], 'What an admin changed is not undone');
    }

    // --- Existing documents --------------------------------------------------------------------------------------------

    public function test_existing_documents_are_approved_when_the_provider_was_verified_and_waiting_otherwise(): void
    {
        [, $verified] = $this->makeProvider('verified');
        [, $pending] = $this->makeProvider('pending');
        [, $rejected] = $this->makeProvider('rejected');
        $this->undo();
        $a = $this->legacy($verified->id, 'safety_certification', '2026-10-01 09:00:00');
        $b = $this->legacy($pending->id, 'safety_certification', '2026-10-02 09:00:00');
        $c = $this->legacy($rejected->id, 'safety_certification', '2026-10-03 09:00:00');

        $this->redo();

        $rows = DB::table('provider_documents')->whereIn('id', [$a, $b, $c])->get()->keyBy('id');
        $this->assertSame(['approved', 'pending', 'pending'], [$rows[$a]->status, $rows[$b]->status, $rows[$c]->status]);
        $this->assertSame('2026-10-01 09:00:00', $rows[$a]->reviewed_at, 'Decided when it was uploaded: all that is known');
        $this->assertNull($rows[$b]->reviewed_at);
        $this->assertSame([null, null, null], [$rows[$a]->superseded_at, $rows[$a]->expires_at, $rows[$a]->review_note]);
    }

    public function test_the_history_is_written_once_for_what_exists(): void
    {
        [, $verified] = $this->makeProvider('verified');
        [, $pending] = $this->makeProvider('pending');
        $this->undo();
        $a = $this->legacy($verified->id, 'safety_certification', '2026-10-01 09:00:00');
        $b = $this->legacy($pending->id, 'insurance_certificate', '2026-10-02 09:00:00');

        $this->redo();

        $events = DB::table('provider_document_events')->orderBy('id')->get();
        $this->assertSame([[$a, 'submitted'], [$a, 'approved'], [$b, 'submitted']], $events->map(fn ($e) => [$e->provider_document_id, $e->action])->all());
        $this->assertSame(['2026-10-01 09:00:00', '2026-10-01 09:00:00', '2026-10-02 09:00:00'], $events->pluck('created_at')->all());
        $this->assertSame([$verified->id, $verified->id, $pending->id], $events->pluck('provider_id')->all());
        $this->assertSame([null, null, null], $events->pluck('actor_user_id')->all(), 'Nobody is credited with what nobody recorded');

        $this->migration('2026_11_10_100200_create_provider_document_events_table')->up();
        $this->assertSame(3, DB::table('provider_document_events')->count(), 'Running it again adds nothing');
    }

    public function test_running_the_review_migration_again_changes_no_decision(): void
    {
        [, $verified] = $this->makeProvider('verified');
        $document = DB::table('provider_documents')->insertGetId(['uuid' => (string) Str::uuid(), 'provider_id' => $verified->id, 'type' => 'safety_certification', 'original_name' => 's.pdf', 'path' => 'p/s.pdf',
            'mime_type' => 'application/pdf', 'size_bytes' => 5, 'status' => 'rejected', 'review_note' => 'Blurry', 'created_at' => now(), 'updated_at' => now()]);

        $this->migration('2026_11_10_100100_add_review_to_provider_documents_table')->up();

        $row = DB::table('provider_documents')->find($document);
        $this->assertSame(['rejected', 'Blurry'], [$row->status, $row->review_note], 'A decision already made is never overwritten');
    }

    public function test_the_columns_exist(): void
    {
        foreach (['status', 'review_note', 'reviewed_by_user_id', 'reviewed_at', 'expires_at', 'superseded_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('provider_documents', $column), $column);
        }
        foreach (['provider_id', 'document_type', 'provider_document_id', 'action', 'note', 'actor_user_id', 'created_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('provider_document_events', $column), $column);
        }
        $this->assertFalse(Schema::hasColumn('provider_document_events', 'updated_at'), 'History is never edited');
    }
}
